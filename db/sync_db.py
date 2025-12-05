#!/usr/bin/env python3
"""
Sync the inventory SQLite database from the most recent scrape CSV in
/root/www/scraper.

This script treats the CSV as the current snapshot of the website inventory and
updates the existing `vehicles` table by:
  - adding new vehicles,
  - updating stock/photo URL/vehicle link for existing vehicles, and
  - deleting vehicles that no longer appear in the CSV.
"""

import csv
import json
import sqlite3
from pathlib import Path


ROOT_DIR = Path("/root/www")
SCRAPER_DIR = ROOT_DIR / "scraper"
DB_DIR = ROOT_DIR / "db"
DB_PATH = DB_DIR / "inventory.sqlite"
TEMPLATE_PATH = ROOT_DIR / "vin_2_data" / "context" / "template.json"


def _infer_template_columns(template_path: Path) -> dict[str, str]:
    """
    Infer SQLite column names and types from vin_2_data/context/template.json.

    Rules:
      - Scalar label hints like "integer", "number", "boolean", "string" in the
        description are mapped to INTEGER/REAL/INTEGER/TEXT respectively.
      - Dicts/lists are stored as TEXT blobs containing JSON.
      - Some label keys are mapped to existing DB naming conventions
        (e.g. rear_axle_configuration -> rear_axle_config, optional_equipment
        -> optional, standard_equipment -> standard).
    """
    columns: dict[str, str] = {}

    try:
        with template_path.open(encoding="utf-8") as f:
            tmpl = json.load(f)
    except Exception:
        # If the template is missing or invalid, fall back to static columns only.
        return columns

    labels = tmpl.get("labels")
    if isinstance(labels, dict):
        for key, desc in labels.items():
            col_name = key
            col_type = "TEXT"

            # Map template keys to DB column names where they intentionally differ.
            if key == "optional_equipment":
                col_name = "optional"
            elif key == "standard_equipment":
                col_name = "standard"
            elif key == "rear_axle_configuration":
                col_name = "rear_axle_config"

            # Complex/nested structures are always stored as JSON blobs.
            if isinstance(desc, (dict, list)):
                col_type = "TEXT"
            elif isinstance(desc, str):
                lower = desc.lower()
                if "integer" in lower:
                    col_type = "INTEGER"
                elif "number" in lower:
                    col_type = "REAL"
                elif "boolean" in lower:
                    # Store booleans as INTEGER 0/1.
                    col_type = "INTEGER"
                else:
                    col_type = "TEXT"
            else:
                col_type = "TEXT"

            # Skip columns that are owned by the CSV/importer.
            if col_name in {"vin", "stock", "photo_urls", "vehicle_link"}:
                continue

            columns[col_name] = col_type

    # Root-level metadata fields that are flattened into columns.
    if "source_pdf" in tmpl:
        columns.setdefault("source_pdf", "TEXT")
    annotation = tmpl.get("annotation")
    if isinstance(annotation, dict):
        if "ts" in annotation:
            columns.setdefault("annotation_ts", "TEXT")
        if "model" in annotation:
            columns.setdefault("annotation_model", "TEXT")

    return columns


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


def init_db(conn: sqlite3.Connection) -> None:
    """
    Ensure the `vehicles` table exists with at least the columns this script uses.

    We no longer drop and recreate the table; instead we create it if needed and
    add any missing columns. This lets us preserve existing rows and any
    enrichment fields that are populated elsewhere.
    """
    cur = conn.cursor()
    # Create the table if it does not already exist.
    #
    # NOTE: The schema below is intentionally aligned with the fields described in
    # `vin_2_data/context/template.json`. The first four columns (vin, stock,
    # photo_urls, vehicle_link) are populated from the CSV by this script; the
    # remaining columns are intended to be populated by the window-sticker
    # enrichment pipeline (`sticker_2_data.py`) based on that template.
    cur.execute(
        """
        CREATE TABLE IF NOT EXISTS vehicles (
            vin TEXT PRIMARY KEY,
            stock TEXT,
            photo_urls TEXT,
            vehicle_link TEXT,

            -- Core vehicle identity/spec fields (template-driven)
            year INTEGER,
            make TEXT,
            model TEXT,
            trim TEXT,
            paint TEXT,
            interior_color TEXT,
            interior_material TEXT,
            drivetrain TEXT,
            body_style TEXT,
            truck_body_style TEXT,
            rear_axle_config TEXT,
            wheelbase TEXT,
            engine TEXT,
            engine_displacement REAL,
            cylinder_count INTEGER,
            transmission_type TEXT,
            transmission_speeds INTEGER,
            fuel TEXT,
            mpg TEXT,

            -- Pricing / totals (template-driven)
            msrp TEXT,
            base_price REAL,
            total_options REAL,
            total_vehicle REAL,
            destination_delivery REAL,
            discount REAL,

            -- Equipment summaries (template-driven; stored as JSON/text blobs)
            optional TEXT,
            standard TEXT,

            -- Build / logistics details (template-driven)
            final_assembly_plant TEXT,
            method_of_transport TEXT,
            special_order INTEGER,
            preferred_equipment_pkg TEXT,
            preferred_equipment_pkg_cost REAL,
            axle_ratio REAL,
            axle_ratio_type TEXT,

            -- Dealer / source metadata
            address TEXT
        )
        """
    )

    # Make sure any newer columns exist even on older databases.
    cur.execute("PRAGMA table_info(vehicles)")
    existing_cols = {row[1] for row in cur.fetchall()}

    # Columns that must exist regardless of the template (driven by CSV/importer).
    static_columns: dict[str, str] = {
        "photo_urls": "TEXT",
        "vehicle_link": "TEXT",
    }

    # Columns inferred dynamically from the JSON template.
    template_columns = _infer_template_columns(TEMPLATE_PATH)

    columns_to_ensure: dict[str, str] = {**static_columns, **template_columns}

    for col_name, col_type in columns_to_ensure.items():
        if col_name not in existing_cols:
            cur.execute(f"ALTER TABLE vehicles ADD COLUMN {col_name} {col_type}")

    conn.commit()


def load_inventory_from_csv(csv_path: Path) -> list[tuple[str, str, str, str]]:
    """
    Read inventory data from the given CSV file.

    For now we only pull VIN and stock number (from 'VIN' and 'Stock Number'
    headers), plus 'Photo URLs' and 'Vehicle Link', and ignore the rest of the
    columns.
    """
    rows: list[tuple[str, str, str, str]] = []
    with csv_path.open(newline="", encoding="utf-8") as f:
        reader = csv.DictReader(f)
        if "VIN" not in reader.fieldnames:
            raise KeyError(f"'VIN' column not found in {csv_path}")
        if "Stock Number" not in reader.fieldnames:
            raise KeyError(f"'Stock Number' column not found in {csv_path}")
        for row in reader:
            vin = (row.get("VIN") or "").strip()
            stock = (row.get("Stock Number") or "").strip()
            if vin:
                photo_urls = (row.get("Photo URLs") or "").strip()
                vehicle_link = (row.get("Vehicle Link") or "").strip()
                rows.append((vin, stock, photo_urls, vehicle_link))
    return rows


def populate_db_from_csv(conn: sqlite3.Connection, csv_path: Path) -> int:
    """
    Sync the contents of the `vehicles` table with data from the CSV.

    - Delete vehicles that are no longer present in the latest CSV.
    - Insert new vehicles that appear in the CSV but not in the database.
    - Update `stock`, `photo_urls`, and `vehicle_link` for vehicles that already
      exist, preserving any other enriched columns.

    Returns the number of rows in the table after sync.
    """
    # Ensure table exists and has required columns.
    init_db(conn)

    # Load latest inventory snapshot from CSV.
    inventory_rows = load_inventory_from_csv(csv_path)

    cur = conn.cursor()

    # Current VINs in database.
    cur.execute("SELECT vin FROM vehicles")
    existing_vins = {row[0] for row in cur.fetchall()}

    # VINs from latest CSV.
    csv_vins = {vin for (vin, _stock, _photos, _link) in inventory_rows}

    # 1) Delete vehicles that disappeared from the website.
    vins_to_delete = existing_vins - csv_vins
    if vins_to_delete:
        placeholders = ",".join("?" for _ in vins_to_delete)
        cur.execute(f"DELETE FROM vehicles WHERE vin IN ({placeholders})", tuple(vins_to_delete))

    # 2) Upsert vehicles from CSV:
    #    - update subset of columns for existing VINs
    #    - insert new VINs
    rows_inserted = 0
    for vin, stock, photo_urls, vehicle_link in inventory_rows:
        if vin in existing_vins:
            cur.execute(
                """
                UPDATE vehicles
                SET stock = ?, photo_urls = ?, vehicle_link = ?
                WHERE vin = ?
                """,
                (stock, photo_urls, vehicle_link, vin),
            )
        else:
            cur.execute(
                """
                INSERT INTO vehicles (vin, stock, photo_urls, vehicle_link)
                VALUES (?, ?, ?, ?)
                """,
                (vin, stock, photo_urls, vehicle_link),
            )
            rows_inserted += 1

    conn.commit()

    # Return the final row count for visibility.
    cur.execute("SELECT COUNT(*) FROM vehicles")
    (final_count,) = cur.fetchone()
    return final_count


def main() -> None:
    DB_DIR.mkdir(parents=True, exist_ok=True)
    csv_path = find_most_recent_csv(SCRAPER_DIR)

    print(f"Using CSV: {csv_path}")
    with sqlite3.connect(DB_PATH) as conn:
        count = populate_db_from_csv(conn, csv_path)
        print(f"Synced {DB_PATH}; it now has {count} vehicles.")


if __name__ == "__main__":
    main()


