#!/usr/bin/env python3
"""
Update filters.json with actual values from the database.

This script scans the inventory database and updates filters.json with:
  - Actual values present in the database for each filter
  - Counts for each filter option
  - Any new values not already defined in the static config

Usage:
    python3 update_filters.py

NOTE: This script uses the vehicles_all view which joins base table with
appropriate child tables based on vehicle_type (CTI schema).
"""

import json
import sqlite3
from pathlib import Path
from datetime import datetime

ROOT_DIR = Path("/root/www")
DB_PATH = ROOT_DIR / "db" / "inventory.sqlite"
FILTERS_PATH = ROOT_DIR / "html" / "filters.json"
F150_OPTIONS_PATH = ROOT_DIR / "vin_2_data" / "context" / "f150_options.json"


def get_distinct_values(conn: sqlite3.Connection, column: str, table: str = "vehicles") -> dict[str, int]:
    """Get distinct values and counts for a column from specified table/view."""
    cur = conn.cursor()
    cur.execute(f"""
        SELECT {column}, COUNT(*) as count 
        FROM {table}
        WHERE {column} IS NOT NULL AND {column} != ''
        GROUP BY {column}
        ORDER BY count DESC
    """)
    return {row[0]: row[1] for row in cur.fetchall()}


def get_distinct_values_from_view(conn: sqlite3.Connection, column: str) -> dict[str, int]:
    """Get distinct values and counts for a column from vehicles_all view."""
    return get_distinct_values(conn, column, "vehicles_all")


def extract_equipment_keywords(conn: sqlite3.Connection) -> dict[str, int]:
    """Extract unique equipment items from the optional JSON column."""
    cur = conn.cursor()
    cur.execute("SELECT optional FROM vehicles WHERE optional IS NOT NULL AND optional != ''")
    
    equipment_counts: dict[str, int] = {}
    
    for (optional_json,) in cur.fetchall():
        try:
            items = json.loads(optional_json)
            if isinstance(items, list):
                for item in items:
                    if isinstance(item, dict):
                        desc = item.get("description", "")
                        if desc:
                            # Normalize the description
                            desc_lower = desc.lower()
                            equipment_counts[desc] = equipment_counts.get(desc, 0) + 1
        except json.JSONDecodeError:
            continue
    
    return equipment_counts


def update_filters():
    """Update filters.json with actual database values."""
    if not DB_PATH.exists():
        print(f"Database not found: {DB_PATH}")
        return
    
    # Load existing filters config
    if FILTERS_PATH.exists():
        with FILTERS_PATH.open() as f:
            filters_config = json.load(f)
    else:
        filters_config = {"metadata": {}, "filters": {}}
    
    conn = sqlite3.connect(DB_PATH)
    
    # Get values from base vehicles table
    db_values = {
        "trim": get_distinct_values(conn, "trim"),
        "package": get_distinct_values(conn, "equipment_group"),
        "color": get_distinct_values(conn, "paint"),
        "vehicle_type": get_distinct_values(conn, "vehicle_type"),
    }
    
    # Get values from vehicles_all view (includes joined child table data)
    try:
        db_values["engine"] = get_distinct_values_from_view(conn, "engine")
        db_values["drivetrain"] = get_distinct_values_from_view(conn, "drivetrain")
        db_values["body_style"] = get_distinct_values_from_view(conn, "truck_body_style")
    except sqlite3.OperationalError:
        # Fall back to base table if view doesn't exist
        print("Warning: vehicles_all view not found, using base table only")
        db_values["engine"] = {}
        db_values["drivetrain"] = {}
        db_values["body_style"] = {}
    
    # Get equipment keywords
    equipment_items = extract_equipment_keywords(conn)
    
    # Get price range
    cur = conn.cursor()
    cur.execute("SELECT MIN(total_vehicle), MAX(total_vehicle) FROM vehicles WHERE total_vehicle IS NOT NULL")
    price_range = cur.fetchone()
    
    conn.close()
    
    # Update metadata
    filters_config.setdefault("metadata", {})
    filters_config["metadata"]["last_updated"] = datetime.now().isoformat()
    
    # Add database_values section showing what's actually in the DB
    filters_config["database_values"] = {
        "vehicle_type": db_values["vehicle_type"],
        "trim": db_values["trim"],
        "package": db_values["package"],
        "engine": db_values["engine"],
        "drivetrain": db_values["drivetrain"],
        "body_style": db_values["body_style"],
        "color": db_values["color"],
        "price_range": {
            "min": price_range[0] if price_range[0] else 0,
            "max": price_range[1] if price_range[1] else 0,
        },
        "equipment_found": dict(sorted(equipment_items.items(), key=lambda x: -x[1])[:50]),
    }
    
    # Write updated config
    with FILTERS_PATH.open("w") as f:
        json.dump(filters_config, f, indent=2)
    
    print(f"Updated {FILTERS_PATH}")
    print(f"\nDatabase summary:")
    print(f"  Vehicle types: {db_values['vehicle_type']}")
    print(f"  Trims: {list(db_values['trim'].keys())}")
    print(f"  Packages: {list(db_values['package'].keys())}")
    print(f"  Engines: {list(db_values['engine'].keys())}")
    print(f"  Drivetrains: {list(db_values['drivetrain'].keys())}")
    print(f"  Body styles: {list(db_values['body_style'].keys())}")
    print(f"  Colors: {len(db_values['color'])} unique colors")
    print(f"  Price range: ${price_range[0]:,.0f} - ${price_range[1]:,.0f}" if price_range[0] else "  Price range: N/A")
    print(f"  Equipment items: {len(equipment_items)} unique items found")


if __name__ == "__main__":
    update_filters()
