#!/usr/bin/env python3
"""
Sync the inventory SQLite database from the most recent scrape CSV in
/root/www/scraper.

This script treats the CSV as the current snapshot of the website inventory and
updates the existing `vehicles` table by:
  - adding new vehicles,
  - updating stock/photo URL/vehicle link for existing vehicles, and
  - deleting vehicles that no longer appear in the CSV.

Uses Class Table Inheritance (CTI) pattern:
  - Base `vehicles` table with common fields
  - Type-specific tables: trucks, suvs, vans, coupes, chassis
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

# Vehicle type classification based on model name
VEHICLE_TYPE_MAP = {
    # Trucks
    "F-150": "truck",
    "F-250": "truck",
    "F-350": "truck",
    "F-450": "truck",
    "F-550": "truck",
    "F-600": "truck",
    "F-650": "truck",
    "F-750": "truck",
    "Ranger": "truck",
    "Maverick": "truck",
    # SUVs (including crossovers like Mustang Mach-E)
    "Bronco": "suv",
    "Bronco Sport": "suv",
    "Explorer": "suv",
    "Expedition": "suv",
    "Edge": "suv",
    "Escape": "suv",
    "EcoSport": "suv",
    "Mustang Mach-E": "suv",
    # Vans
    "Transit": "van",
    "E-Transit": "van",
    "Transit Connect": "van",
    # Coupes (sports cars)
    "Mustang": "coupe",
    "GT": "coupe",
    # Chassis
    "F-Series": "chassis",
}


def infer_vehicle_type(model: str) -> str:
    """Determine vehicle type from model name."""
    if not model:
        return "unknown"
    
    model_normalized = model.strip()
    
    # Check exact matches first (order matters - check Mustang Mach-E before Mustang)
    if model_normalized in VEHICLE_TYPE_MAP:
        return VEHICLE_TYPE_MAP[model_normalized]
    
    # Check prefix matches (e.g., "F-150" matches "F-150 Lightning")
    # Check longer prefixes first to match "Mustang Mach-E" before "Mustang"
    for key in sorted(VEHICLE_TYPE_MAP.keys(), key=len, reverse=True):
        if model_normalized.startswith(key):
            return VEHICLE_TYPE_MAP[key]
    
    # Check if model contains keywords
    model_lower = model_normalized.lower()
    if any(t in model_lower for t in ["f-150", "f-250", "f-350", "f-450", "f-550", "f-650", "ranger", "maverick"]):
        return "truck"
    if "mustang mach-e" in model_lower or "mach-e" in model_lower:
        return "suv"
    if any(s in model_lower for s in ["bronco", "explorer", "expedition", "edge", "escape"]):
        return "suv"
    if "transit" in model_lower:
        return "van"
    if "mustang" in model_lower or model_lower == "gt":
        return "coupe"
    
    return "unknown"


def _infer_template_columns(template_path: Path) -> dict[str, str]:
    """
    Infer SQLite column names and types from template.json.

    Returns a dict mapping column_name -> col_type.

    Rules:
      - Scalar label hints like "integer", "number", "boolean", "string" in the
        description are mapped to INTEGER/REAL/INTEGER/TEXT respectively.
      - Dicts/lists are stored as TEXT blobs containing JSON.
      - Some label keys are mapped to existing DB naming conventions.
    """
    columns: dict[str, str] = {}

    try:
        with template_path.open(encoding="utf-8") as f:
            tmpl = json.load(f)
    except Exception:
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
            elif key == "preferred_equipment_pkg":
                col_name = "equipment_group"
            elif key == "preferred_equipment_pkg_cost":
                col_name = "equipment_group_discount"

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
                    col_type = "INTEGER"
                else:
                    col_type = "TEXT"
            else:
                col_type = "TEXT"

            # Skip columns that are owned by the CSV/importer.
            if col_name in {"vin", "stock", "photo_urls", "vehicle_link"}:
                continue

            columns[col_name] = col_type

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
    Ensure the database uses Class Table Inheritance (CTI) pattern.

    This creates:
      - `vehicles` base table with common fields for all vehicle types
      - `trucks` table for truck-specific attributes
      - `suvs` table for SUV-specific attributes
      - `vans` table for van-specific attributes
      - `coupes` table for coupe-specific attributes
      - `chassis` table for chassis-specific attributes
      - `vehicles_all` view that joins the base with type-specific tables

    We no longer drop and recreate tables; instead we create them if needed and
    add any missing columns. This preserves existing rows and enrichment data.
    """
    cur = conn.cursor()

    # =========================================================================
    # VEHICLES TABLE (Base table with common fields)
    # =========================================================================
    cur.execute(
        """
        CREATE TABLE IF NOT EXISTS vehicles (
            vin TEXT PRIMARY KEY,
            stock TEXT,
            photo_urls TEXT,
            vehicle_link TEXT,

            -- Vehicle type discriminator: 'truck', 'suv', 'van', 'coupe', 'chassis'
            vehicle_type TEXT,

            -- Core vehicle identity (common to all types)
            year INTEGER,
            make TEXT,
            model TEXT,
            trim TEXT,
            paint TEXT,
            interior_color TEXT,
            interior_material TEXT,
            body_style TEXT,
            fuel TEXT,

            -- Pricing / totals (common to all types)
            msrp TEXT,
            base_price REAL,
            total_options REAL,
            total_vehicle REAL,
            destination_delivery REAL,
            discount REAL,

            -- Equipment summaries (stored as JSON/text blobs)
            optional TEXT,
            standard TEXT,

            -- Build / logistics details
            final_assembly_plant TEXT,
            method_of_transport TEXT,
            special_order INTEGER,
            equipment_group TEXT,
            equipment_group_discount REAL,

            -- Dealer / source metadata
            address TEXT
        )
        """
    )

    # =========================================================================
    # TRUCKS TABLE (Truck-specific attributes)
    # =========================================================================
    cur.execute(
        """
        CREATE TABLE IF NOT EXISTS trucks (
            vin TEXT PRIMARY KEY,
            
            -- Powertrain
            drivetrain TEXT,
            engine TEXT,
            engine_displacement REAL,
            cylinder_count INTEGER,
            transmission_type TEXT,
            transmission_speeds INTEGER,
            mpg TEXT,
            
            -- Truck-specific
            truck_body_style TEXT,
            rear_axle_config TEXT,
            wheelbase TEXT,
            axle_ratio REAL,
            axle_ratio_type TEXT,
            bed_length TEXT,
            towing_capacity INTEGER,
            payload_capacity INTEGER
        )
        """
    )

    # =========================================================================
    # SUVS TABLE (SUV-specific attributes)
    # =========================================================================
    cur.execute(
        """
        CREATE TABLE IF NOT EXISTS suvs (
            vin TEXT PRIMARY KEY,
            
            -- Powertrain
            drivetrain TEXT,
            engine TEXT,
            engine_displacement REAL,
            cylinder_count INTEGER,
            transmission_type TEXT,
            transmission_speeds INTEGER,
            mpg TEXT,
            
            -- SUV-specific
            cargo_volume REAL,
            third_row_seating INTEGER,
            ground_clearance REAL
        )
        """
    )

    # =========================================================================
    # VANS TABLE (Van-specific attributes)
    # =========================================================================
    cur.execute(
        """
        CREATE TABLE IF NOT EXISTS vans (
            vin TEXT PRIMARY KEY,
            
            -- Powertrain
            drivetrain TEXT,
            engine TEXT,
            engine_displacement REAL,
            cylinder_count INTEGER,
            transmission_type TEXT,
            transmission_speeds INTEGER,
            mpg TEXT,
            
            -- Van-specific
            cargo_length TEXT,
            roof_height TEXT
        )
        """
    )

    # =========================================================================
    # COUPES TABLE (Coupe-specific attributes)
    # =========================================================================
    cur.execute(
        """
        CREATE TABLE IF NOT EXISTS coupes (
            vin TEXT PRIMARY KEY,
            
            -- Powertrain
            drivetrain TEXT,
            engine TEXT,
            engine_displacement REAL,
            cylinder_count INTEGER,
            transmission_type TEXT,
            transmission_speeds INTEGER,
            mpg TEXT,
            
            -- Coupe-specific
            horsepower INTEGER,
            torque INTEGER
        )
        """
    )

    # =========================================================================
    # CHASSIS TABLE (Chassis-specific attributes)
    # =========================================================================
    cur.execute(
        """
        CREATE TABLE IF NOT EXISTS chassis (
            vin TEXT PRIMARY KEY,
            
            -- Powertrain
            drivetrain TEXT,
            engine TEXT,
            engine_displacement REAL,
            cylinder_count INTEGER,
            transmission_type TEXT,
            transmission_speeds INTEGER,
            mpg TEXT,
            
            -- Chassis-specific (similar to truck)
            truck_body_style TEXT,
            rear_axle_config TEXT,
            wheelbase TEXT,
            axle_ratio REAL,
            axle_ratio_type TEXT,
            towing_capacity INTEGER,
            payload_capacity INTEGER
        )
        """
    )

    # =========================================================================
    # Add any missing columns to base vehicles table
    # =========================================================================
    cur.execute("PRAGMA table_info(vehicles)")
    existing_cols = {row[1] for row in cur.fetchall()}

    base_required_columns: dict[str, str] = {
        "photo_urls": "TEXT",
        "vehicle_link": "TEXT",
        "vehicle_type": "TEXT",
        "equipment_group": "TEXT",
        "equipment_group_discount": "REAL",
    }

    for col_name, col_type in base_required_columns.items():
        if col_name not in existing_cols:
            cur.execute(f"ALTER TABLE vehicles ADD COLUMN {col_name} {col_type}")

    # =========================================================================
    # Add missing columns to trucks table
    # =========================================================================
    cur.execute("PRAGMA table_info(trucks)")
    truck_cols = {row[1] for row in cur.fetchall()}

    truck_required_columns: dict[str, str] = {
        "drivetrain": "TEXT",
        "engine": "TEXT",
        "engine_displacement": "REAL",
        "cylinder_count": "INTEGER",
        "transmission_type": "TEXT",
        "transmission_speeds": "INTEGER",
        "mpg": "TEXT",
        "truck_body_style": "TEXT",
        "rear_axle_config": "TEXT",
        "wheelbase": "TEXT",
        "axle_ratio": "REAL",
        "axle_ratio_type": "TEXT",
        "bed_length": "TEXT",
        "towing_capacity": "INTEGER",
        "payload_capacity": "INTEGER",
    }

    for col_name, col_type in truck_required_columns.items():
        if col_name not in truck_cols:
            cur.execute(f"ALTER TABLE trucks ADD COLUMN {col_name} {col_type}")

    # =========================================================================
    # Add missing columns to suvs table
    # =========================================================================
    cur.execute("PRAGMA table_info(suvs)")
    suv_cols = {row[1] for row in cur.fetchall()}

    suv_required_columns: dict[str, str] = {
        "drivetrain": "TEXT",
        "engine": "TEXT",
        "engine_displacement": "REAL",
        "cylinder_count": "INTEGER",
        "transmission_type": "TEXT",
        "transmission_speeds": "INTEGER",
        "mpg": "TEXT",
        "cargo_volume": "REAL",
        "third_row_seating": "INTEGER",
        "ground_clearance": "REAL",
    }

    for col_name, col_type in suv_required_columns.items():
        if col_name not in suv_cols:
            cur.execute(f"ALTER TABLE suvs ADD COLUMN {col_name} {col_type}")

    # =========================================================================
    # Add missing columns to vans table
    # =========================================================================
    cur.execute("PRAGMA table_info(vans)")
    van_cols = {row[1] for row in cur.fetchall()}

    van_required_columns: dict[str, str] = {
        "drivetrain": "TEXT",
        "engine": "TEXT",
        "engine_displacement": "REAL",
        "cylinder_count": "INTEGER",
        "transmission_type": "TEXT",
        "transmission_speeds": "INTEGER",
        "mpg": "TEXT",
        "cargo_length": "TEXT",
        "roof_height": "TEXT",
    }

    for col_name, col_type in van_required_columns.items():
        if col_name not in van_cols:
            cur.execute(f"ALTER TABLE vans ADD COLUMN {col_name} {col_type}")

    # =========================================================================
    # Add missing columns to coupes table
    # =========================================================================
    cur.execute("PRAGMA table_info(coupes)")
    coupe_cols = {row[1] for row in cur.fetchall()}

    coupe_required_columns: dict[str, str] = {
        "drivetrain": "TEXT",
        "engine": "TEXT",
        "engine_displacement": "REAL",
        "cylinder_count": "INTEGER",
        "transmission_type": "TEXT",
        "transmission_speeds": "INTEGER",
        "mpg": "TEXT",
        "horsepower": "INTEGER",
        "torque": "INTEGER",
    }

    for col_name, col_type in coupe_required_columns.items():
        if col_name not in coupe_cols:
            cur.execute(f"ALTER TABLE coupes ADD COLUMN {col_name} {col_type}")

    # =========================================================================
    # Add missing columns to chassis table
    # =========================================================================
    cur.execute("PRAGMA table_info(chassis)")
    chassis_cols = {row[1] for row in cur.fetchall()}

    chassis_required_columns: dict[str, str] = {
        "drivetrain": "TEXT",
        "engine": "TEXT",
        "engine_displacement": "REAL",
        "cylinder_count": "INTEGER",
        "transmission_type": "TEXT",
        "transmission_speeds": "INTEGER",
        "mpg": "TEXT",
        "truck_body_style": "TEXT",
        "rear_axle_config": "TEXT",
        "wheelbase": "TEXT",
        "axle_ratio": "REAL",
        "axle_ratio_type": "TEXT",
        "towing_capacity": "INTEGER",
        "payload_capacity": "INTEGER",
    }

    for col_name, col_type in chassis_required_columns.items():
        if col_name not in chassis_cols:
            cur.execute(f"ALTER TABLE chassis ADD COLUMN {col_name} {col_type}")

    # =========================================================================
    # Create/update the vehicles_all view that joins all tables
    # =========================================================================
    cur.execute("DROP VIEW IF EXISTS vehicles_all")
    cur.execute(
        """
        CREATE VIEW vehicles_all AS
        SELECT
            -- Base vehicle fields
            v.vin,
            v.stock,
            v.photo_urls,
            v.vehicle_link,
            v.vehicle_type,
            v.year,
            v.make,
            v.model,
            v.trim,
            v.paint,
            v.interior_color,
            v.interior_material,
            v.body_style,
            v.fuel,
            v.msrp,
            v.base_price,
            v.total_options,
            v.total_vehicle,
            v.destination_delivery,
            v.discount,
            v.optional,
            v.standard,
            v.final_assembly_plant,
            v.method_of_transport,
            v.special_order,
            v.equipment_group,
            v.equipment_group_discount,
            v.address,
            
            -- Powertrain (coalesced from type-specific tables)
            COALESCE(t.drivetrain, s.drivetrain, va.drivetrain, c.drivetrain, ch.drivetrain) AS drivetrain,
            COALESCE(t.engine, s.engine, va.engine, c.engine, ch.engine) AS engine,
            COALESCE(t.engine_displacement, s.engine_displacement, va.engine_displacement, c.engine_displacement, ch.engine_displacement) AS engine_displacement,
            COALESCE(t.cylinder_count, s.cylinder_count, va.cylinder_count, c.cylinder_count, ch.cylinder_count) AS cylinder_count,
            COALESCE(t.transmission_type, s.transmission_type, va.transmission_type, c.transmission_type, ch.transmission_type) AS transmission_type,
            COALESCE(t.transmission_speeds, s.transmission_speeds, va.transmission_speeds, c.transmission_speeds, ch.transmission_speeds) AS transmission_speeds,
            COALESCE(t.mpg, s.mpg, va.mpg, c.mpg, ch.mpg) AS mpg,
            
            -- Truck/chassis fields
            COALESCE(t.truck_body_style, ch.truck_body_style) AS truck_body_style,
            COALESCE(t.rear_axle_config, ch.rear_axle_config) AS rear_axle_config,
            COALESCE(t.wheelbase, ch.wheelbase) AS wheelbase,
            COALESCE(t.axle_ratio, ch.axle_ratio) AS axle_ratio,
            COALESCE(t.axle_ratio_type, ch.axle_ratio_type) AS axle_ratio_type,
            t.bed_length,
            t.towing_capacity,
            t.payload_capacity,
            
            -- SUV-specific fields
            s.cargo_volume,
            s.third_row_seating,
            s.ground_clearance,
            
            -- Van-specific fields
            va.cargo_length,
            va.roof_height,
            
            -- Coupe-specific fields
            c.horsepower,
            c.torque
            
        FROM vehicles v
        LEFT JOIN trucks t ON v.vin = t.vin AND v.vehicle_type = 'truck'
        LEFT JOIN suvs s ON v.vin = s.vin AND v.vehicle_type = 'suv'
        LEFT JOIN vans va ON v.vin = va.vin AND v.vehicle_type = 'van'
        LEFT JOIN coupes c ON v.vin = c.vin AND v.vehicle_type = 'coupe'
        LEFT JOIN chassis ch ON v.vin = ch.vin AND v.vehicle_type = 'chassis'
        """
    )


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
    #    Also delete from type-specific tables.
    vins_to_delete = existing_vins - csv_vins
    if vins_to_delete:
        placeholders = ",".join("?" for _ in vins_to_delete)
        vins_tuple = tuple(vins_to_delete)
        cur.execute(f"DELETE FROM vehicles WHERE vin IN ({placeholders})", vins_tuple)
        cur.execute(f"DELETE FROM trucks WHERE vin IN ({placeholders})", vins_tuple)
        cur.execute(f"DELETE FROM suvs WHERE vin IN ({placeholders})", vins_tuple)
        cur.execute(f"DELETE FROM vans WHERE vin IN ({placeholders})", vins_tuple)
        cur.execute(f"DELETE FROM coupes WHERE vin IN ({placeholders})", vins_tuple)
        cur.execute(f"DELETE FROM chassis WHERE vin IN ({placeholders})", vins_tuple)

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
