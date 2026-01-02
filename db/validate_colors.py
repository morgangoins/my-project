#!/usr/bin/env python

"""Validate paint colors in inventory.sqlite against color_mapping.json.

This script checks for color inconsistencies and reports vehicles with
colors that don't match the canonical mapping. It can help identify
new color variations that need to be added to the mapping.
"""

from __future__ import annotations

import json
import pathlib
import sqlite3
from collections import Counter
from typing import Dict, Any, List

BASE_DIR = pathlib.Path(__file__).resolve().parent
DB_PATH = BASE_DIR / "inventory.sqlite"
COLOR_MAPPING_PATH = BASE_DIR / "color_mapping.json"


def load_color_mapping() -> Dict[str, Any]:
    if not COLOR_MAPPING_PATH.exists():
        raise SystemExit(f"Color mapping not found at {COLOR_MAPPING_PATH}")
    with COLOR_MAPPING_PATH.open("r", encoding="utf-8") as f:
        return json.load(f)


def validate_colors(conn: sqlite3.Connection, color_mapping: Dict[str, Any]) -> Dict[str, Any]:
    """Validate paint colors against the color mapping."""

    canonical_colors = color_mapping.get("canonical_colors", {})
    all_variations = set()

    # Build set of all valid variations
    for canonical, data in canonical_colors.items():
        variations = data.get("variations", [])
        all_variations.update(variations)
        all_variations.add(canonical)

    # Query all paint colors from database
    cur = conn.cursor()
    cur.execute("SELECT vin, paint FROM vehicles WHERE paint IS NOT NULL AND paint != ''")
    rows = cur.fetchall()

    valid_colors = Counter()
    invalid_colors = Counter()
    unmapped_vehicles = []

    for vin, paint in rows:
        paint_clean = paint.strip()
        if paint_clean in all_variations:
            valid_colors[paint_clean] += 1
        else:
            invalid_colors[paint_clean] += 1
            unmapped_vehicles.append({
                "vin": vin,
                "paint": paint_clean
            })

    # Group unmapped vehicles by color for easier review
    unmapped_by_color = {}
    for vehicle in unmapped_vehicles:
        color = vehicle["paint"]
        if color not in unmapped_by_color:
            unmapped_by_color[color] = []
        unmapped_by_color[color].append(vehicle["vin"])

    return {
        "total_vehicles": len(rows),
        "valid_colors": dict(valid_colors.most_common()),
        "invalid_colors": dict(invalid_colors.most_common()),
        "unmapped_count": len(unmapped_vehicles),
        "unmapped_by_color": unmapped_by_color,
        "all_valid_variations": sorted(list(all_variations))
    }


def main() -> None:
    if not DB_PATH.exists():
        raise SystemExit(f"Database not found at {DB_PATH}")

    color_mapping = load_color_mapping()

    conn = sqlite3.connect(DB_PATH)
    try:
        results = validate_colors(conn, color_mapping)
    finally:
        conn.close()

    # Print summary
    print("Color Validation Results")
    print("=" * 50)
    print(f"Total vehicles with paint colors: {results['total_vehicles']}")
    print(f"Valid colors: {len(results['valid_colors'])}")
    print(f"Invalid/unmapped colors: {len(results['invalid_colors'])}")
    print(f"Vehicles with unmapped colors: {results['unmapped_count']}")
    print()

    if results['invalid_colors']:
        print("Invalid Colors Found:")
        print("-" * 20)
        for color, count in results['invalid_colors'].items():
            print(f"  {color}: {count} vehicles")
        print()

        print("Unmapped Vehicles by Color:")
        print("-" * 30)
        for color, vins in results['unmapped_by_color'].items():
            print(f"  {color} ({len(vins)} vehicles):")
            # Show first few VINs as examples
            for vin in vins[:3]:
                print(f"    - {vin}")
            if len(vins) > 3:
                print(f"    ... and {len(vins) - 3} more")
            print()
    else:
        print("✓ All paint colors are properly mapped!")

    # Save detailed results to JSON
    output_path = BASE_DIR / "color_validation_results.json"
    with output_path.open("w", encoding="utf-8") as f:
        json.dump(results, f, indent=2, sort_keys=True)

    print(f"Detailed results saved to: {output_path}")


if __name__ == "__main__":
    main()




