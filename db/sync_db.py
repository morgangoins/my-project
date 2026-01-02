#!/usr/bin/env python3
"""
Sync the inventory SQLite database from the most recent scrape CSV in
/root/www/scraper.

This script treats the CSV as the current snapshot of the website inventory and
updates the existing `vehicles` table by:
  - adding new vehicles,
  - updating stock/photo URL/vehicle link for existing vehicles, and
  - deleting vehicles that no longer appear in the CSV.

Uses per-model table inheritance pattern:
  - Base `vehicles` table with common fields
  - Model-specific tables: f150, super_duty, ranger, maverick, f150_lightning,
    explorer, expedition, bronco, bronco_sport, escape, mach_e, mustang, transit
  - Model mapping from template.json's model_table_map
"""

import argparse
import csv
import json
import sqlite3
from datetime import datetime, timezone
from pathlib import Path


ROOT_DIR = Path("/root/www")
SCRAPER_DIR = ROOT_DIR / "scraper"
DB_DIR = ROOT_DIR / "db"
DB_PATH = DB_DIR / "inventory.sqlite"
TEMPLATE_PATH = ROOT_DIR / "db" / "ford_guides" / "context" / "template.json"

# Old type-based tables to drop during migration
OLD_TYPE_TABLES = {"trucks", "suvs", "vans", "coupes", "chassis"}

# Base table columns that are always present regardless of template contents.
BASE_ALWAYS_COLUMNS: dict[str, str] = {
    "stock": "TEXT",
    "photo_urls": "TEXT",
    "vehicle_link": "TEXT",
    # First-seen timestamp for new VINs
    "created_at": "TEXT",
}


def _load_template() -> dict:
    """Load template.json and return the parsed dict."""
    try:
        with TEMPLATE_PATH.open(encoding="utf-8") as f:
            return json.load(f)
    except Exception:
        return {}


def _get_model_table_map() -> dict[str, str]:
    """
    Get the model name -> table name mapping from template.json.
    Falls back to a built-in mapping if template.json doesn't have one.
    """
    tmpl = _load_template()
    mapping = tmpl.get("model_table_map")
    if isinstance(mapping, dict):
        return mapping
    
    # Fallback built-in mapping
    return {
        "F-150": "f150",
        "F-150 Lightning": "f150_lightning",
        "F-250": "super_duty",
        "F-350": "super_duty",
        "F-450": "super_duty",
        "F-550": "super_duty",
        "F-600": "super_duty",
        "F-650": "super_duty",
        "F-750": "super_duty",
        "Super Duty": "super_duty",
        "Ranger": "ranger",
        "Maverick": "maverick",
        "Explorer": "explorer",
        "Expedition": "expedition",
        "Bronco": "bronco",
        "Bronco Sport": "bronco_sport",
        "Escape": "escape",
        "Mustang Mach-E": "mach_e",
        "Mach-E": "mach_e",
        "Mustang": "mustang",
        "GT": "mustang",
        "Transit": "transit",
        "E-Transit": "transit",
        "Transit Connect": "transit",
    }


def infer_model_table(model: str) -> str:
    """Determine model table name from model name."""
    if not model:
        return "unknown"
    
    model_normalized = model.strip()
    model_table_map = _get_model_table_map()
    
    # Check exact matches first (order matters - check "F-150 Lightning" before "F-150")
    if model_normalized in model_table_map:
        return model_table_map[model_normalized]
    
    # Check prefix matches (e.g., "F-150" matches "F-150 XLT")
    # Check longer prefixes first to match "F-150 Lightning" before "F-150"
    for key in sorted(model_table_map.keys(), key=len, reverse=True):
        if model_normalized.startswith(key):
            return model_table_map[key]
    
    # Check if model contains keywords
    model_lower = model_normalized.lower()
    
    # Lightning check first (before F-150 check)
    if "lightning" in model_lower:
        return "f150_lightning"
    
    # Mach-E check (before Mustang check)
    if "mach-e" in model_lower or "mach e" in model_lower:
        return "mach_e"
    
    # Truck patterns
    if any(t in model_lower for t in ["f-250", "f-350", "f-450", "f-550", "f-650", "f-750"]):
        return "super_duty"
    if "f-150" in model_lower:
        return "f150"
    if "ranger" in model_lower:
        return "ranger"
    if "maverick" in model_lower:
        return "maverick"
    
    # SUV patterns
    if "bronco sport" in model_lower:
        return "bronco_sport"
    if "bronco" in model_lower:
        return "bronco"
    if "explorer" in model_lower:
        return "explorer"
    if "expedition" in model_lower:
        return "expedition"
    if "escape" in model_lower:
        return "escapes"
    
    # Van patterns
    if "transit" in model_lower:
        return "transit"
    
    # Coupe/sports car patterns
    if "mustang" in model_lower or model_lower == "gt":
        return "mustang"
    
    return "unknown"


def _map_type_from_description(desc: object) -> str:
    """Map a template description to an SQLite column type."""
    if isinstance(desc, (dict, list)):
        return "TEXT"
    if isinstance(desc, str):
        lower = desc.lower()
        if "integer" in lower:
            return "INTEGER"
        if "number" in lower:
            return "REAL"
        if "boolean" in lower:
            return "INTEGER"
        return "TEXT"
    return "TEXT"


def _apply_alias(key: str) -> str:
    """Map template key to DB column name where they intentionally differ."""
    if key == "optional_equipment":
        return "optional"
    if key == "standard_equipment":
        return "standard"
    if key == "rear_axle_configuration":
        return "rear_axle_config"
    if key == "preferred_equipment_pkg":
        return "equipment_group"
    if key == "preferred_equipment_pkg_cost":
        return "equipment_group_discount"
    return key


def _normalize_col_type(col_type: str | None) -> str:
    """Fallback to TEXT for any unknown/empty type strings."""
    if not col_type:
        return "TEXT"
    col_type = col_type.strip().upper()
    if not col_type:
        return "TEXT"
    return col_type


def _infer_tables_from_template(template_path: Path) -> tuple[dict[str, str], dict[str, dict[str, str]]]:
    """
    Infer table -> columns mapping directly from template.json.

    Expects the template to contain a top-level "tables" object with keys
    "vehicles" plus model-specific tables (f150, super_duty, etc.).
    Returns (base_cols, model_cols) where model_cols maps table_name -> {col: type}.
    """
    try:
        with template_path.open(encoding="utf-8") as f:
            tmpl = json.load(f)
    except Exception:
        tmpl = {}

    tables = tmpl.get("tables")
    if not isinstance(tables, dict):
        # Return minimal schema
        return dict(BASE_ALWAYS_COLUMNS), {}

    base_cols: dict[str, str] = dict(BASE_ALWAYS_COLUMNS)
    model_cols: dict[str, dict[str, str]] = {}

    for table_name, fields in tables.items():
        if not isinstance(fields, dict):
            continue

        if table_name == "vehicles":
            # Base table columns
            for key, desc in fields.items():
                col_name = _apply_alias(key)
                col_type = _map_type_from_description(desc)
                # Skip vin (primary key handled separately)
                if col_name == "vin":
                    continue
                base_cols[col_name] = col_type
        else:
            # Model-specific table
            model_cols[table_name] = {}
            for key, desc in fields.items():
                col_name = _apply_alias(key)
                col_type = _map_type_from_description(desc)
                model_cols[table_name][col_name] = col_type

    # Ensure timestamp column exists on all tables for first-seen tracking.
    base_cols.setdefault("created_at", "TEXT")
    for table_name, cols in model_cols.items():
        cols.setdefault("created_at", "TEXT")

    return base_cols, model_cols


def _drop_view(conn: sqlite3.Connection, view_name: str) -> None:
    cur = conn.cursor()
    cur.execute(f"DROP VIEW IF EXISTS {view_name}")


def _drop_old_type_tables(conn: sqlite3.Connection) -> None:
    """Drop the old vehicle type tables (trucks, suvs, vans, coupes, chassis)."""
    cur = conn.cursor()
    for table in OLD_TYPE_TABLES:
        cur.execute(f"DROP TABLE IF EXISTS {table}")
        print(f"  Dropped old table: {table}")


def _ensure_table(
    conn: sqlite3.Connection,
    table: str,
    columns: dict[str, str],
    *,
    primary_key: str = "vin",
    prune: bool = False,
) -> None:
    """
    Create table if missing and add columns based on template-inferred schema.
    Optionally prune columns not present in the template by rebuilding the table.
    """
    cur = conn.cursor()

    # Build CREATE TABLE statement.
    col_defs = [f"{primary_key} TEXT PRIMARY KEY"]
    for col_name, col_type in columns.items():
        if col_name == primary_key:
            continue
        col_defs.append(f"{col_name} {col_type}")
    create_sql = f"CREATE TABLE IF NOT EXISTS {table} (\n    " + ",\n    ".join(col_defs) + "\n)"
    cur.execute(create_sql)

    # Add any missing columns.
    cur.execute(f"PRAGMA table_info({table})")
    existing_cols = {row[1] for row in cur.fetchall()}
    for col_name, col_type in columns.items():
        if col_name not in existing_cols:
            cur.execute(f"ALTER TABLE {table} ADD COLUMN {col_name} {col_type}")

    # Optionally prune extra columns.
    if prune:
        _prune_table(conn, table, columns, primary_key=primary_key)


def _prune_table(
    conn: sqlite3.Connection,
    table: str,
    desired_columns: dict[str, str],
    *,
    primary_key: str = "vin",
) -> None:
    """
    Drop columns not present in desired_columns by rebuilding the table.

    WARNING: This will drop data in columns that are removed. Back up the DB
    before using this.
    """
    cur = conn.cursor()
    cur.execute(f"PRAGMA table_info({table})")
    existing_info = cur.fetchall()
    existing_cols = {row[1] for row in existing_info}

    desired_col_names = set(desired_columns.keys()) | {primary_key}
    extras = existing_cols - desired_col_names
    if not extras:
        return

    tmp_table = f"{table}__tmp"

    # Build desired schema on the temp table.
    col_defs = [f"{primary_key} TEXT PRIMARY KEY"]
    for col_name, col_type in desired_columns.items():
        if col_name == primary_key:
            continue
        col_defs.append(f"{col_name} {col_type}")
    create_sql = f"CREATE TABLE {tmp_table} (\n    " + ",\n    ".join(col_defs) + "\n)"
    cur.execute(create_sql)

    # Copy overlapping columns.
    common_cols = [primary_key] + [c for c in desired_columns if c in existing_cols and c != primary_key]
    if common_cols:
        cols_csv = ", ".join(common_cols)
        cur.execute(
            f"INSERT INTO {tmp_table} ({cols_csv}) SELECT {cols_csv} FROM {table}"
        )

    cur.execute(f"DROP TABLE {table}")
    cur.execute(f"ALTER TABLE {tmp_table} RENAME TO {table}")


def _get_actual_table_columns(conn: sqlite3.Connection, table: str) -> set[str]:
    """Get the actual column names from a table in the database."""
    cur = conn.cursor()
    try:
        cur.execute(f"PRAGMA table_info({table})")
        return {row[1] for row in cur.fetchall()}
    except Exception:
        return set()


def _build_view(
    conn: sqlite3.Connection,
    base_columns: dict[str, str],
    model_columns: dict[str, dict[str, str]],
) -> None:
    """
    Recreate the vehicles_all view from the current schema.
    Joins base vehicles table with all model-specific tables.
    
    Uses ACTUAL database columns (not just template) to handle migration cases
    where old data exists in model tables but schema moved columns to base table.
    """
    _drop_view(conn, "vehicles_all")

    # Get actual columns from database tables (not just template)
    actual_base_cols = _get_actual_table_columns(conn, "vehicles")
    actual_model_cols: dict[str, set[str]] = {}
    for table_name in model_columns:
        actual_model_cols[table_name] = _get_actual_table_columns(conn, table_name)

    # Generate table aliases for each model table
    alias_map = {name: f"m_{i}" for i, name in enumerate(sorted(model_columns.keys()))}

    select_parts: list[str] = []

    # Build a mapping of column -> list of model tables that ACTUALLY contain it.
    # This uses the real database schema, not the template, to handle migration.
    model_column_tables: dict[str, list[str]] = {}
    for table_name, cols in actual_model_cols.items():
        for col in cols:
            if col != "vin":  # vin is the join key, handled separately
                model_column_tables.setdefault(col, []).append(table_name)

    # Base columns - COALESCE with model tables if the column also exists there.
    # This handles migration: old data in model tables, new data in base table.
    base_cols_for_view = ["vin"] + sorted(col for col in actual_base_cols if col != "vin")
    for col in base_cols_for_view:
        if col in model_column_tables:
            # Column exists in both base and model tables - COALESCE them
            # Prefer base table value, fall back to model table values
            model_aliases = [alias_map[t] for t in model_column_tables[col]]
            model_sources = [f"{alias}.{col}" for alias in model_aliases]
            all_sources = [f"v.{col}"] + model_sources
            select_parts.append(f"    COALESCE({', '.join(all_sources)}) AS {col}")
        else:
            select_parts.append(f"    v.{col}")

    # Model-only columns (not in base table) with coalescing across model tables.
    for col in sorted(model_column_tables):
        if col in actual_base_cols:
            continue  # Already handled above
        table_aliases = [alias_map[t] for t in model_column_tables[col]]
        sources = [f"{alias}.{col}" for alias in table_aliases]
        if len(sources) == 1:
            select_parts.append(f"    {sources[0]} AS {col}")
        else:
            select_parts.append(f"    COALESCE({', '.join(sources)}) AS {col}")

    select_sql = ",\n".join(select_parts)

    # Build JOIN clauses for each model table
    join_clauses: list[str] = []
    for table_name in sorted(model_columns.keys()):
        alias = alias_map[table_name]
        join_clauses.append(
            f"        LEFT JOIN {table_name} {alias} ON v.vin = {alias}.vin AND v.model_table = '{table_name}'"
        )

    joins_sql = "\n".join(join_clauses)

    view_sql = f"""
        CREATE VIEW vehicles_all AS
        SELECT
{select_sql}
        FROM vehicles v
{joins_sql}
    """

    cur = conn.cursor()
    cur.execute(view_sql)


def init_db(conn: sqlite3.Connection, *, prune_columns: bool = False, migrate: bool = False) -> None:
    """
    Ensure per-model schema based on template.json.

    - Creates missing tables/columns inferred from the template.
    - Optionally prunes columns not present in the template (data loss risk).
    - If migrate=True, drops old type tables (trucks, suvs, etc.).
    - Rebuilds vehicles_all view.
    """
    base_cols, model_cols = _infer_tables_from_template(TEMPLATE_PATH)

    # Ensure model_table exists for joins even if omitted in template.
    base_cols.setdefault("model_table", "TEXT")

    # Drop the view before structural changes to avoid dependency errors.
    _drop_view(conn, "vehicles_all")

    # Drop old type tables if migrating
    if migrate:
        _drop_old_type_tables(conn)

    _ensure_table(conn, "vehicles", base_cols, primary_key="vin", prune=prune_columns)
    
    # Create all model tables from template
    for table_name, columns in model_cols.items():
        _ensure_table(conn, table_name, columns, primary_key="vin", prune=prune_columns)

    _build_view(conn, base_cols, model_cols)
    _create_indexes(conn)
    conn.commit()


def _create_indexes(conn: sqlite3.Connection) -> None:
    """
    Create indexes on commonly-queried columns for faster filtering.
    
    These indexes significantly speed up the inventory.php filtering queries.
    """
    cur = conn.cursor()
    indexes = [
        ("idx_vehicles_model", "vehicles", "model"),
        ("idx_vehicles_trim", "vehicles", "trim"),
        ("idx_vehicles_paint", "vehicles", "paint"),
        ("idx_vehicles_equipment_group", "vehicles", "equipment_group"),
        ("idx_vehicles_drivetrain", "vehicles", "drivetrain"),
        ("idx_vehicles_year", "vehicles", "year"),
        ("idx_vehicles_model_table", "vehicles", "model_table"),
    ]
    for idx_name, table, column in indexes:
        cur.execute(f"CREATE INDEX IF NOT EXISTS {idx_name} ON {table}({column})")


def find_most_recent_csv(scraper_dir: Path) -> Path:
    """Return the most recently modified CSV file in the scraper directory."""
    csv_files = sorted(
        scraper_dir.glob("*.csv"),
        key=lambda p: p.stat().st_mtime,
        reverse=True,
    )
    if not csv_files:
        raise FileNotFoundError(f"No CSV files found in {scraper_dir}")
    return csv_files[0]


def load_inventory_from_csv(csv_path: Path) -> list[tuple]:
    """
    Load inventory data from the most recent CSV file.

    Pulls VIN, stock number, and other basic fields that change frequently,
    including pricing information.
    """
    rows = []
    with csv_path.open(newline="", encoding="utf-8") as f:
        reader = csv.DictReader(f)
        if "VIN" not in reader.fieldnames:
            raise KeyError(f"'VIN' column not found in {csv_path}")
        if "Stock Number" not in reader.fieldnames:
            raise KeyError(f"'Stock Number' column not found in {csv_path}")
        for row in reader:
            vin = (row.get("VIN") or "").strip()
            if vin:
                stock = (row.get("Stock Number") or "").strip()
                photo_urls = (row.get("Photo URLs") or "").strip()
                vehicle_link = (row.get("Vehicle Link") or "").strip()
                year = (row.get("Year") or "").strip()
                make = (row.get("Make") or "").strip()
                model = (row.get("Model") or "").strip()
                trim = (row.get("Trim") or "").strip()
                paint = (row.get("Exterior Color") or "").strip()
                interior_color = (row.get("Interior Color") or "").strip()
                body_style = (row.get("Body Style") or "").strip()
                fuel = (row.get("Fuel Type") or "").strip()
                engine = (row.get("Engine") or "").strip()
                transmission_type = (row.get("Transmission") or "").strip()
                packages = (row.get("Packages") or "").strip()
                msrp = (row.get("MSRP") or "").strip()
                dealer_discount = (row.get("Dealer Discount") or "").strip()
                sale_price = (row.get("Sale Price") or "").strip()
                factory_rebates = (row.get("Factory Rebates") or "").strip()
                retail_price = (row.get("Retail Price") or "").strip()

                # Build pricing JSON
                pricing = {}
                if msrp or dealer_discount or sale_price or factory_rebates or retail_price:
                    pricing = {
                        "msrp": msrp,
                        "dealer_discount": dealer_discount,
                        "sale_price": sale_price,
                        "factory_rebates": factory_rebates,
                        "retail_price": retail_price
                    }
                    # Remove empty values
                    pricing = {k: v for k, v in pricing.items() if v}

                rows.append((vin, stock, photo_urls, vehicle_link, year, make, model, trim,
                           paint, interior_color, body_style, fuel, engine, transmission_type,
                           packages, msrp, json.dumps(pricing) if pricing else None))
    return rows


def populate_db_from_csv(
    conn: sqlite3.Connection,
    csv_path: Path,
    *,
    prune_columns: bool = False,
    migrate: bool = False,
) -> int:
    """
    Sync the contents of the `vehicles` table with data from the CSV.

    - Delete vehicles that are no longer present in the latest CSV.
    - Insert new vehicles that appear in the CSV but not in the database.
    - Update `stock`, `photo_urls`, and `vehicle_link` for vehicles that already
      exist, preserving any other enriched columns.

    Returns the number of rows in the table after sync.
    """
    # Ensure table exists and has required columns.
    init_db(conn, prune_columns=prune_columns, migrate=migrate)

    # Load latest inventory snapshot from CSV.
    inventory_rows = load_inventory_from_csv(csv_path)

    cur = conn.cursor()

    # Current VINs in database.
    cur.execute("SELECT vin FROM vehicles")
    existing_vins = {row[0] for row in cur.fetchall()}

    # VINs from latest CSV.
    csv_vins = {vin for (vin, *_) in inventory_rows}

    # 1) Delete vehicles that disappeared from the website.
    #    Also delete from model-specific tables.
    vins_to_delete = existing_vins - csv_vins
    if vins_to_delete:
        placeholders = ",".join("?" for _ in vins_to_delete)
        vins_tuple = tuple(vins_to_delete)
        cur.execute(f"DELETE FROM vehicles WHERE vin IN ({placeholders})", vins_tuple)

        # Delete from all model tables
        _, model_cols = _infer_tables_from_template(TEMPLATE_PATH)
        for table_name in model_cols:
            cur.execute(f"DELETE FROM {table_name} WHERE vin IN ({placeholders})", vins_tuple)

    # 2) Upsert vehicles from CSV:
    #    - update subset of columns for existing VINs
    #    - insert new VINs
    rows_inserted = 0
    for (vin, stock, photo_urls, vehicle_link, year, make, model, trim,
         paint, interior_color, body_style, fuel, engine, transmission_type,
         packages, msrp, pricing_json) in inventory_rows:
        if vin in existing_vins:
            cur.execute(
                """
                UPDATE vehicles
                SET stock = ?, photo_urls = ?, vehicle_link = ?, year = ?, make = ?, model = ?,
                    trim = ?, paint = ?, interior_color = ?, body_style = ?, fuel = ?,
                    engine = ?, transmission_type = ?, equipment_group = ?, msrp = ?, pricing = ?
                WHERE vin = ?
                """,
                (stock, photo_urls, vehicle_link, year, make, model, trim, paint, interior_color,
                 body_style, fuel, engine, transmission_type, packages, msrp, pricing_json, vin),
            )
        else:
            first_seen = datetime.now(timezone.utc).isoformat()
            cur.execute(
                """
                INSERT INTO vehicles (vin, stock, photo_urls, vehicle_link, year, make, model, trim,
                                    paint, interior_color, body_style, fuel, engine, transmission_type,
                                    equipment_group, msrp, pricing, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                """,
                (vin, stock, photo_urls, vehicle_link, year, make, model, trim, paint, interior_color,
                 body_style, fuel, engine, transmission_type, packages, msrp, pricing_json, first_seen),
            )
            rows_inserted += 1

    conn.commit()

    # Return the final row count for visibility.
    cur.execute("SELECT COUNT(*) FROM vehicles")
    (final_count,) = cur.fetchone()
    return final_count


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Sync inventory.sqlite from the most recent scraper CSV using template-driven schema.",
    )
    parser.add_argument(
        "--csv",
        type=Path,
        help="Optional explicit CSV path. Defaults to newest *.csv in /root/www/scraper.",
    )
    parser.add_argument(
        "--prune-columns",
        action="store_true",
        help="Drop DB columns not present in template.json by rebuilding tables (data-loss risk; backup first).",
    )
    parser.add_argument(
        "--migrate",
        action="store_true",
        help="Drop old vehicle type tables (trucks, suvs, vans, coupes, chassis) and create new model tables.",
    )
    return parser.parse_args()


def main() -> None:
    args = parse_args()

    DB_DIR.mkdir(parents=True, exist_ok=True)
    csv_path = args.csv or find_most_recent_csv(SCRAPER_DIR)

    print(f"Using CSV: {csv_path}")
    if args.prune_columns:
        print("Pruning columns not present in template.json (ensure you have a DB backup).")
    if args.migrate:
        print("Migration mode: dropping old type tables (trucks, suvs, vans, coupes, chassis).")

    with sqlite3.connect(DB_PATH) as conn:
        count = populate_db_from_csv(conn, csv_path, prune_columns=args.prune_columns, migrate=args.migrate)
        print(f"Synced {DB_PATH}; it now has {count} vehicles.")


if __name__ == "__main__":
    main()
