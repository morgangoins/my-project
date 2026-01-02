#!/usr/bin/env python

"""Normalize vehicle data in inventory.sqlite using ford_guides/context/options.json.

This script focuses on making text fields more uniform (capitalization and
naming) while respecting the allowed options defined in options.json.

By default it runs in DRY-RUN mode and only prints a summary + writes a
report JSON; pass --apply to actually update the database.
"""

from __future__ import annotations

import argparse
import json
import pathlib
import re
import sqlite3
from collections import Counter, defaultdict
from typing import Any, Dict, Iterable, List, Optional

BASE_DIR = pathlib.Path(__file__).resolve().parent
DB_PATH = BASE_DIR / "inventory.sqlite"
OPTIONS_PATH = BASE_DIR / "ford_guides" / "context" / "options.json"
COLOR_MAPPING_PATH = BASE_DIR / "color_mapping.json"
REPORT_PATH = BASE_DIR / "inventory_normalization_report.json"

# Map DB columns in vehicles -> keys in options.json per model/year
FIELD_MAPPING: Dict[str, str] = {
    "trim": "trim",
    "paint": "paint",
    "interior_color": "interior_color",
    "interior_material": "interior_material",
    "drivetrain": "drivetrain",
    "body_style": "body_style",
    "fuel": "fuel",
    "engine": "engine",
    "engine_displacement": "engine_displacement",
    # cylinder_count / wheelbase etc. are numeric in DB; capitalization
    # inconsistencies are less of an issue so we skip them for now.
    "transmission_type": "transmission_type",
    "transmission_speeds": "transmission_speeds",
    # equipment_group in DB typically corresponds to preferred_equipment_pkg
    "equipment_group": "preferred_equipment_pkg",
}

# Words we want to ignore in paint-style names when matching to canonical
DESCRIPTOR_PATTERN = re.compile(
    r"\b(metallic|met|tri-coat|tri coat|tinted cc|tinted clearcoat|cc|coat)\b",
    flags=re.IGNORECASE,
)


def load_options() -> Dict[str, Any]:
    with OPTIONS_PATH.open("r", encoding="utf-8") as f:
        return json.load(f)


def load_color_mapping() -> Dict[str, Any]:
    if not COLOR_MAPPING_PATH.exists():
        return {}
    with COLOR_MAPPING_PATH.open("r", encoding="utf-8") as f:
        return json.load(f)


def normalize_model_name(name: str) -> str:
    """Normalize a model string for comparison.

    Example:
    - "F-150" -> "F150"
    - "F-150 Lightning" -> "F150LIGHTNING"
    - "MUSTANG MACH-E" -> "MUSTANGMACHE"
    """

    return re.sub(r"[^A-Z0-9]", "", name.upper())


def get_model_spec(options: Dict[str, Any], year: int, model: str) -> Optional[Dict[str, Any]]:
    """Return the options.json spec dict for a given (year, model) row.

    We do a forgiving match on the model name so that e.g. "F-150 LIGHTNING"
    still matches the "F-150 Lightning" key, and "MUSTANG MACH-E" matches
    "Mach-E".
    """

    year_spec = options.get(str(year))
    if not isinstance(year_spec, dict):
        return None

    norm_model = normalize_model_name(model)
    for model_key, spec in year_spec.items():
        norm_key = normalize_model_name(model_key)
        if not norm_key:
            continue
        if norm_model == norm_key or norm_model.endswith(norm_key) or norm_key.endswith(norm_model):
            if isinstance(spec, dict):
                return spec
    return None


def simplify_value_for_match(value: str) -> str:
    """Canonicalize a text value for fuzzy comparison to allowed options.

    - Trim whitespace
    - Collapse internal whitespace
    - Drop common descriptor words like "Metallic", "MET", "Tri-Coat", etc.
    - Lowercase for case-insensitive comparison
    """

    v = value.strip()
    v = re.sub(r"\s+", " ", v)
    v = DESCRIPTOR_PATTERN.sub("", v)
    v = re.sub(r"\s+", " ", v)
    return v.strip().lower()


def choose_canonical_color(value: str, color_mapping: Dict[str, Any]) -> Optional[str]:
    """Find the canonical color name for a given color value using the color mapping.

    Returns the canonical color name if found, None otherwise.
    """
    if not value or not color_mapping:
        return None

    value_clean = value.strip()
    canonical_colors = color_mapping.get("canonical_colors", {})

    # First, check for exact match with any variation
    for canonical, data in canonical_colors.items():
        variations = data.get("variations", [])
        if value_clean in variations:
            return canonical

    # Then check for normalized match (strip descriptors)
    value_norm = simplify_value_for_match(value_clean)
    for canonical, data in canonical_colors.items():
        variations = data.get("variations", [])
        for variation in variations:
            if simplify_value_for_match(variation) == value_norm:
                return canonical

    return None


def choose_canonical(value: str, allowed: Iterable[Any]) -> Optional[str]:
    """Pick the canonical allowed value that best matches the given value.

    Strategy:
    - First, match after running simplify_value_for_match on both sides.
    - Then fall back to simple case-insensitive string equality.
    Returns the canonical allowed value as a string, or None if no good match.
    """

    allowed_list: List[Any] = list(allowed)
    if not allowed_list:
        return None

    v_norm = simplify_value_for_match(value)

    # 1) descriptor-stripped, case-insensitive match
    for a in allowed_list:
        if a is None:
            continue
        a_str = str(a)
        if simplify_value_for_match(a_str) == v_norm:
            return a_str

    # 2) plain case-insensitive match
    value_ci = value.strip().lower()
    for a in allowed_list:
        if a is None:
            continue
        a_str = str(a)
        if a_str.strip().lower() == value_ci:
            return a_str

    # 3) numeric displacement-style match (e.g. "2.3" -> "2.3L")
    num_val = re.fullmatch(r"\s*([0-9]+(?:\.[0-9]+)?)\s*[lL]?\s*", value)
    if num_val:
        num = num_val.group(1)
        candidates = []
        for a in allowed_list:
            if a is None:
                continue
            a_str = str(a)
            m = re.fullmatch(r"\s*([0-9]+(?:\.[0-9]+)?)\s*[lL]?\s*", a_str)
            if m and m.group(1) == num:
                candidates.append(a_str)
        if len(candidates) == 1:
            return candidates[0]

    # 4) integer-in-string match (e.g. "8-Speed" -> 8)
    int_match = re.search(r"\b([0-9]+)\b", value)
    if int_match:
        digits = int_match.group(1)
        for a in allowed_list:
            if a is None:
                continue
            a_str = str(a).strip()
            if a_str == digits:
                return a_str

    return None


def custom_trim_fix(model: str, trim: str) -> Optional[str]:
    """Handle a few known trim quirks before generic matching.

    Examples:
    - Mach-E rows with "PREMIUM EAWD" / "Premium RWD" -> "Premium"
    - Escape rows with "Plug-In Hybrid" as trim -> "PHEV"
    """

    norm_model = normalize_model_name(model)
    t = trim.strip().upper()

    # Mach-E appears in DB as e.g. "MUSTANG MACH-E" or "MACH-E"
    if "MACHE" in norm_model:
        if "PREMIUM" in t:
            return "Premium"
        if t.startswith("GT"):
            return "GT"
        if "SELECT" in t:
            return "Select"

    # Escape plug-in hybrid trim should be PHEV
    if norm_model == normalize_model_name("Escape"):
        if t == "PLUG-IN HYBRID" or t == "PLUG IN HYBRID":
            return "PHEV"

    # Transit cargo/passenger style trims -> canonical trims
    if norm_model == normalize_model_name("Transit"):
        if "CARGO" in t:
            return "Cargo Van"
        if t == "XL":
            return "Passenger Van XL"
        if t == "XLT":
            return "Passenger Van XLT"

    return None


def apply_custom_fix(col: str, model: str, value: str, allowed: Iterable[Any]) -> Optional[str]:
    """Apply field/model specific fixes that we know map cleanly into options.json.

    This is only used when options.json provides an allowed list for the field.
    """

    allowed_list = list(allowed)
    if not allowed_list:
        return None

    norm_model = normalize_model_name(model)
    v = value.strip()
    v_upper = v.upper()

    # body_style tweaks
    if col == "body_style":
        if norm_model == normalize_model_name("Bronco") and "SUV" in map(str, allowed_list):
            if v_upper == "TRUCK":
                return "SUV"
        if norm_model == normalize_model_name("Transit") and "Van" in map(str, allowed_list):
            if v_upper in {"CARGO VAN", "TRUCK"}:
                return "Van"

    # drivetrain tweaks
    if col == "drivetrain":
        if norm_model == normalize_model_name("Bronco Sport"):
            # All Bronco Sport are effectively AWD; make "4x4" uniform
            if v_upper == "4X4" and "AWD" in map(str, allowed_list):
                return "AWD"
        if norm_model == normalize_model_name("Ranger"):
            if v_upper == "4X2" and "RWD" in map(str, allowed_list):
                return "RWD"

    # engine tweaks
    if col == "engine":
        if norm_model == normalize_model_name("Bronco Sport"):
            if "2.0L" in v_upper and "ECOBOOST" in v_upper:
                if "2.0L EcoBoost I4" in map(str, allowed_list):
                    return "2.0L EcoBoost I4"
        if norm_model == normalize_model_name("Escape"):
            if "2.5L" in v_upper and "HYB" in v_upper:
                if "2.5L Hybrid I4" in map(str, allowed_list):
                    return "2.5L Hybrid I4"
        if norm_model in {normalize_model_name("F-250"), normalize_model_name("F-350")}:
            if "6.8L" in v_upper and "V8" in v_upper:
                if "6.8L V8" in map(str, allowed_list):
                    return "6.8L V8"
            if "7.3L" in v_upper and "V8" in v_upper:
                if "7.3L V8" in map(str, allowed_list):
                    return "7.3L V8"
        if norm_model == normalize_model_name("Transit"):
            if "3.5L" in v_upper and "PFDI" in v_upper:
                if "3.5L PFDi V6" in map(str, allowed_list):
                    return "3.5L PFDi V6"

    # interior_color tweaks
    if col == "interior_color":
        if norm_model == normalize_model_name("Bronco"):
            if v_upper in {"BLACK", "ONYX"} and "Black Onyx" in map(str, allowed_list):
                return "Black Onyx"
        if "MACHE" in norm_model:
            if ("BLK" in v_upper or v_upper == "BLACK") and "Black Onyx" in map(str, allowed_list):
                return "Black Onyx"
        if norm_model == normalize_model_name("Mustang"):
            if v_upper in {"BLACK", "BLACK/BLUE"} and "Black Onyx" in map(str, allowed_list):
                return "Black Onyx"
        if norm_model == normalize_model_name("F-150"):
            if v_upper == "DARK SLATE" and "Medium Dark Slate" in map(str, allowed_list):
                return "Medium Dark Slate"
        if norm_model == normalize_model_name("F-150 Lightning") or norm_model == normalize_model_name("F-150 LIGHTNING"):
            if v_upper == "BLACK" and "Black Onyx" in map(str, allowed_list):
                return "Black Onyx"

    # interior_material tweaks (generic)
    if col == "interior_material":
        # Leather-like -> Leather-Trimmed
        if "LEATHER" in v_upper:
            for a in allowed_list:
                if str(a) == "Leather-Trimmed":
                    return "Leather-Trimmed"
        # Premium-like -> Premium-Trimmed
        if "PREMIUM" in v_upper:
            for a in allowed_list:
                if str(a) == "Premium-Trimmed":
                    return "Premium-Trimmed"

    # transmission_type tweaks
    if col == "transmission_type":
        if v_upper.endswith("CVT"):
            for a in allowed_list:
                if str(a) == "eCVT":
                    return "eCVT"
        if "SPEED" in v_upper:
            # Anything like "10-SPEED" should just be "Automatic" where that's the only auto option.
            autos = [str(a) for a in allowed_list if "Automatic" in str(a)]
            if len(autos) == 1:
                return autos[0]

    # transmission_speeds tweaks
    if col == "transmission_speeds":
        # e.g. "8-Speed" -> 8 if 8 is an allowed speed
        m = re.search(r"\b([0-9]+)\b", v_upper)
        if m:
            digits = m.group(1)
            for a in allowed_list:
                if str(a) == digits:
                    return digits

    return None


def normalize_vehicles_table(conn: sqlite3.Connection, options: Dict[str, Any], apply: bool, normalize_colors: bool = False) -> Dict[str, Any]:
    conn.row_factory = sqlite3.Row
    cur = conn.cursor()

    cur.execute(
        """
        SELECT
            vin,
            year,
            model,
            trim,
            paint,
            interior_color,
            interior_material,
            drivetrain,
            body_style,
            fuel,
            engine,
            engine_displacement,
            transmission_type,
            transmission_speeds,
            equipment_group
        FROM vehicles
        """
    )

    rows = cur.fetchall()

    changes: List[Dict[str, Any]] = []
    unknown_values: Dict[str, Counter] = defaultdict(Counter)
    rows_without_spec = 0

    for row in rows:
        vin = row["vin"]
        year = row["year"]
        model = row["model"] or ""

        spec = get_model_spec(options, year, model)
        if spec is None:
            rows_without_spec += 1
            continue

        updates: Dict[str, Any] = {}

        for col, opt_key in FIELD_MAPPING.items():
            current = row[col]
            if current is None:
                continue
            # Treat literal "null" strings as missing data
            if isinstance(current, str) and current.strip().lower() in {"", "null"}:
                continue

            # Skip if options.json does not constrain this field for this model
            allowed = spec.get(opt_key)
            if not isinstance(allowed, list) or all(a is None for a in allowed):
                continue

            # Special-case trim fixes before generic matching
            candidate = str(current)
            if col == "trim":
                fixed = custom_trim_fix(model, candidate)
                if fixed is not None:
                    candidate = fixed

            # Special-case color normalization using color mapping
            if col == "paint" and normalize_colors:
                color_mapping = load_color_mapping()
                color_canonical = choose_canonical_color(candidate, color_mapping)
                if color_canonical is not None:
                    canonical = color_canonical
                else:
                    # Fall back to regular matching if no color mapping found
                    canonical = choose_canonical(candidate, allowed)
            else:
                # Apply additional field/model specific tweaks
                custom = apply_custom_fix(col, model, candidate, allowed)
                if custom is not None:
                    canonical = custom
                else:
                    canonical = choose_canonical(candidate, allowed)
            if canonical is None:
                key = f"{opt_key}::{year}::{model}"
                unknown_values[key][str(current)] += 1
                continue

            if canonical != current:
                updates[col] = canonical

        if updates:
            change_record = {
                "vin": vin,
                "year": year,
                "model": model,
                "before": {col: row[col] for col in updates.keys()},
                "after": updates,
            }
            changes.append(change_record)

            if apply:
                set_clause = ", ".join(f"{col} = ?" for col in updates.keys())
                params = list(updates.values()) + [vin]
                cur.execute(f"UPDATE vehicles SET {set_clause} WHERE vin = ?", params)

    if apply:
        conn.commit()

    summary = {
        "total_rows": len(rows),
        "rows_without_spec_in_options": rows_without_spec,
        "rows_with_changes": len(changes),
        "changes": changes,
        "unknown_values": {
            key: dict(counter) for key, counter in unknown_values.items()
        },
    }
    return summary


def main() -> None:
    parser = argparse.ArgumentParser(description="Normalize inventory.sqlite using options.json")
    parser.add_argument(
        "--apply",
        action="store_true",
        help="Actually write normalized values back to the database (default is dry-run)",
    )
    parser.add_argument(
        "--normalize-colors",
        action="store_true",
        help="Enable color normalization using color_mapping.json",
    )
    args = parser.parse_args()

    if not DB_PATH.exists():
        raise SystemExit(f"Database not found at {DB_PATH}")
    if not OPTIONS_PATH.exists():
        raise SystemExit(f"options.json not found at {OPTIONS_PATH}")

    options = load_options()

    conn = sqlite3.connect(DB_PATH)
    try:
        summary = normalize_vehicles_table(conn, options, apply=args.apply, normalize_colors=args.normalize_colors)
    finally:
        conn.close()

    # Write a JSON report for inspection
    with REPORT_PATH.open("w", encoding="utf-8") as f:
        json.dump(summary, f, indent=2, sort_keys=True)

    # Console summary
    print("Database:", DB_PATH)
    print("Options:", OPTIONS_PATH)
    print("Mode:", "APPLY" if args.apply else "DRY-RUN (no changes written)")
    print("Total vehicle rows:", summary["total_rows"])
    print("Rows with matching options.json spec:", summary["total_rows"] - summary["rows_without_spec_in_options"])
    print("Rows without matching options.json spec:", summary["rows_without_spec_in_options"])
    print("Rows that would be changed:", summary["rows_with_changes"])

    unknown_total = sum(sum(c.values()) for c in summary["unknown_values"].values())
    print("Unmapped values (could not match to options.json):", unknown_total)
    if unknown_total:
        print("See", REPORT_PATH, "for details on which values did not match.")


if __name__ == "__main__":  # pragma: no cover
    main()
