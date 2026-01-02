from __future__ import annotations

import json
import re
import sqlite3
from dataclasses import dataclass
from pathlib import Path
from typing import Any


TOWING_DATA_2025 = Path("/root/www/db/ford_guides/json/2025/towing_trucks.json")
DB_PATH_DEFAULT = Path("/root/www/db/inventory.sqlite")


def _normalize_spaces(s: str) -> str:
    return re.sub(r"\s+", " ", (s or "").strip())


def _extract_axle_ratio_from_equipment(optional_json: str | None, standard_json: str | None) -> float | None:
    """
    Many stickers encode axle ratio inside optional equipment lines, e.g. "3.55 RATIO REGULAR AXLE".
    """
    blobs: list[str] = []
    for raw in (optional_json, standard_json):
        if not raw:
            continue
        try:
            parsed = json.loads(raw)
        except Exception:
            continue
        if isinstance(parsed, list):
            for item in parsed:
                if isinstance(item, str):
                    blobs.append(item)
                elif isinstance(item, dict):
                    blobs.append((item.get("description", "") + " " + (item.get("included") or "")))

    text = " ".join(blobs)
    m = re.search(r"\b(\d\.\d{2})\s*RATIO\b", text, re.I)
    if not m:
        return None
    try:
        return float(m.group(1))
    except ValueError:
        return None


def _infer_cab_from_body_style(body_style: str | None, truck_body_style: str | None) -> str | None:
    if truck_body_style:
        return _normalize_spaces(truck_body_style)
    bs = (body_style or "").lower()
    if "regular" in bs:
        return "Regular Cab"
    if "supercab" in bs or "super cab" in bs:
        return "Super Cab"
    if "supercrew" in bs or "super crew" in bs or "crew cab" in bs:
        # Maverick/Ranger use SuperCrew; Super Duty uses Crew Cab in body_style
        return "SuperCrew" if "supercrew" in bs else "Crew Cab"
    return None


def _normalize_engine_for_matching(engine: str | None) -> str | None:
    """
    Keep it intentionally lightweight: we match by substring against the towing JSON engine labels.
    """
    if not engine:
        return None
    e = engine.lower()
    # Normalize common sticker formats:
    e = e.replace("ecoboost", "ecoboost")
    e = e.replace("power stroke", "power stroke")
    e = e.replace("powerboost", "powerboost")
    # Keep displacement + key words
    m = re.search(r"(\d\.\d)\s*l", e)
    disp = m.group(1) if m else None
    return (disp + "l " + e) if disp else e


def _pick_best_engine_key(engine_norm: str | None, available: list[str]) -> str | None:
    if not engine_norm or not available:
        return None
    en = engine_norm.lower()
    # Prefer matching by displacement first, then by keywords.
    disp = None
    m = re.search(r"\b(\d\.\d)l\b", en)
    if m:
        disp = m.group(1)
    candidates = available
    if disp:
        disp_hits = [k for k in candidates if disp in k.lower()]
        if disp_hits:
            candidates = disp_hits
    # Score by overlap of key tokens
    tokens = [t for t in re.split(r"[^a-z0-9]+", en) if t and t not in {"l", "v", "i4", "v6", "v8"}]
    best = None
    best_score = -1
    for k in candidates:
        kl = k.lower()
        score = sum(1 for t in tokens if t in kl)
        if score > best_score:
            best_score = score
            best = k
    return best


def _equipment_text(optional_json: str | None, standard_json: str | None) -> str:
    blobs: list[str] = []
    for raw in (optional_json, standard_json):
        if not raw:
            continue
        try:
            parsed = json.loads(raw)
        except Exception:
            continue
        if isinstance(parsed, list):
            for item in parsed:
                if isinstance(item, str):
                    blobs.append(item)
                elif isinstance(item, dict):
                    blobs.append((item.get("description", "") + " " + (item.get("included") or "")))
    return " ".join(blobs).lower()


def _requirements_satisfied(requirements: list[str] | None, equipment_blob_lower: str) -> bool:
    if not requirements:
        return True
    # Simple keyword checks for now; keep it conservative.
    for req in requirements:
        r = (req or "").lower()
        if "4k tow" in r:
            if "4k tow" not in equipment_blob_lower and "4k towing" not in equipment_blob_lower:
                return False
        else:
            if r and r not in equipment_blob_lower:
                return False
    return True


@dataclass
class TowResult:
    model: str
    year: int
    inputs: dict[str, Any]
    missing_inputs: list[str]
    results: dict[str, Any]


def _load_towing_data_2025() -> dict[str, Any]:
    return json.loads(TOWING_DATA_2025.read_text(encoding="utf-8"))


def _match_axle_ratio(row_ratio: str, axle_ratio: float | None) -> bool:
    """
    Table ratios can be single (e.g. '3.55') or a slash form (e.g. '3.15/3.55').
    """
    if axle_ratio is None:
        return False
    try:
        ar = float(axle_ratio)
    except Exception:
        return False
    parts = re.split(r"[^\d.]+", row_ratio)
    vals = []
    for p in parts:
        if not p:
            continue
        try:
            vals.append(float(p))
        except ValueError:
            continue
    return any(abs(v - ar) < 0.02 for v in vals)


def _pick_cell_value(cell: Any, *, trim: str | None) -> int | None:
    """
    Convert a table cell to an int tow rating.
    Handles list values like [10300, 9900] where the second value is typically for Tremor.
    """
    if cell is None:
        return None
    if isinstance(cell, int):
        return cell
    if isinstance(cell, list) and cell and all(isinstance(x, int) for x in cell):
        if trim and "tremor" in trim.lower() and len(cell) >= 2:
            return cell[-1]
        return cell[0]
    return None


def compute_tow_caps_from_db(*, vin_or_stock: str, db_path: Path = DB_PATH_DEFAULT) -> TowResult:
    """
    Compute towing capacities for a VIN/stock using inventory.sqlite + towing guide JSON.

    Returns a TowResult with:
      - conventional_tow_lbs: {min, max} when wheelbase/bed are unknown (conservative range)
      - fifth_wheel_tow_lbs: {min, max} (same)
      - gooseneck_tow_lbs: {min, max} (same)
      - max_payload_lbs: exact (from performance table) when engine matches
    """
    con = sqlite3.connect(str(db_path))
    cur = con.cursor()
    row = cur.execute(
        """
        SELECT
          v.vin, v.year, v.model, v.trim, v.drivetrain, v.engine, v.body_style,
          v.optional, v.standard,
          f.truck_body_style as f_truck_body_style,
          f.wheelbase as f_wheelbase,
          f.bed_length as f_bed_length,
          sd.truck_body_style as sd_truck_body_style,
          sd.wheelbase as sd_wheelbase,
          sd.bed_length as sd_bed_length,
          sd.rear_axle_config as sd_rear_axle_config,
          r.truck_body_style as r_truck_body_style,
          m.truck_body_style as m_truck_body_style
        FROM vehicles v
        LEFT JOIN f150 f ON f.vin=v.vin
        LEFT JOIN super_duty sd ON sd.vin=v.vin
        LEFT JOIN ranger r ON r.vin=v.vin
        LEFT JOIN maverick m ON m.vin=v.vin
        WHERE v.vin = ? OR v.stock = ?
        LIMIT 1
        """,
        (vin_or_stock, vin_or_stock),
    ).fetchone()
    if not row:
        raise ValueError(f"Vehicle not found for '{vin_or_stock}'")

    (
        vin,
        year,
        model,
        trim,
        drivetrain,
        engine,
        body_style,
        optional_json,
        standard_json,
        f_truck_body_style,
        f_wheelbase,
        f_bed_length,
        sd_truck_body_style,
        sd_wheelbase,
        sd_bed_length,
        sd_rear_axle_config,
        r_truck_body_style,
        m_truck_body_style,
    ) = row

    truck_body_style = f_truck_body_style or sd_truck_body_style or r_truck_body_style or m_truck_body_style

    cab = _infer_cab_from_body_style(body_style, truck_body_style)
    axle_ratio = _extract_axle_ratio_from_equipment(optional_json, standard_json)
    engine_norm = _normalize_engine_for_matching(engine)
    equip_blob = _equipment_text(optional_json, standard_json)
    wheelbase = f_wheelbase if model == "F-150" else sd_wheelbase if model in {"F-250", "F-350", "F-450"} else None
    bed_length = f_bed_length if model == "F-150" else sd_bed_length if model in {"F-250", "F-350", "F-450"} else None

    towing_data = _load_towing_data_2025()
    models = towing_data.get("models", {})

    # Map DB model names to towing data keys.
    towing_model_key = model
    if model in {"F-250", "F-350", "F-450"}:
        towing_model_key = "Super Duty"

    missing: list[str] = []
    if not engine:
        missing.append("engine")
    if not drivetrain:
        missing.append("drivetrain")
    if model in {"F-150", "F-250", "F-350", "F-450"} and not cab:
        missing.append("cab/body_style")
    if model in {"F-150", "F-250", "F-350", "F-450"} and axle_ratio is None:
        missing.append("axle_ratio")

    # Build results.
    res: dict[str, Any] = {}
    model_obj = models.get(towing_model_key)
    if not model_obj:
        return TowResult(
            model=model,
            year=int(year or 2025),
            inputs={
                "vin": vin,
                "model": model,
                "trim": trim,
                "drivetrain": drivetrain,
                "engine": engine,
                "cab": cab,
                "axle_ratio": axle_ratio,
            },
            missing_inputs=missing,
            results={"error": f"No towing guide data for model '{model}'"},
        )

    # Payload (max, by engine) – exact when matched.
    perf = model_obj.get("performance_by_engine", {}) if isinstance(model_obj, dict) else {}
    if isinstance(perf, dict) and perf:
        best_key = _pick_best_engine_key(engine_norm, list(perf.keys()))
        if best_key and isinstance(perf.get(best_key), dict):
            res["max_payload_lbs"] = perf[best_key].get("max_payload_lbs")
            res["max_conventional_tow_lbs_from_performance"] = perf[best_key].get("max_towing_lbs")
            res["matched_engine_key"] = best_key

    # Selector-based towing ranges
    selectors = model_obj.get("selectors", {}) if isinstance(model_obj, dict) else {}
    selectors_exact = model_obj.get("selectors_exact", {}) if isinstance(model_obj, dict) else {}

    # Exact F-150 selector lookup when we have the full config.
    if model == "F-150" and isinstance(selectors_exact, dict):
        cols = selectors_exact.get("conventional", {}).get("columns")
        rows = selectors_exact.get("conventional", {}).get("rows")
        cols_fw = selectors_exact.get("fifth_wheel_gooseneck", {}).get("columns")
        rows_fw = selectors_exact.get("fifth_wheel_gooseneck", {}).get("rows")

        # Determine column index for this vehicle
        col_idx = None
        if isinstance(cols, list) and wheelbase and cab and drivetrain:
            dt = str(drivetrain).replace(" ", "").upper()
            dt = "4x4" if dt in {"4X4", "4WD", "AWD"} else "4x2" if dt in {"4X2", "RWD"} else dt
            for i, c in enumerate(cols):
                if not isinstance(c, dict):
                    continue
                if c.get("drivetrain") != dt:
                    continue
                if c.get("cab") != cab:
                    continue
                if abs(float(c.get("wheelbase", 0.0)) - float(wheelbase)) > 0.3:
                    continue
                # For 145.4 we need bed_length to disambiguate SuperCrew vs Super Cab (cab already disambiguates)
                if bed_length is not None and c.get("bed_length") is not None:
                    if abs(float(c["bed_length"]) - float(bed_length)) > 0.3:
                        continue
                col_idx = i
                break

        # Choose best row by engine + axle ratio
        if col_idx is not None and isinstance(rows, list):
            engine_keys = [r.get("engine") for r in rows if isinstance(r, dict) and isinstance(r.get("engine"), str)]
            best_engine_key = _pick_best_engine_key(engine_norm, [k for k in engine_keys if k])
            # Filter candidate rows for engine, then ratio match
            candidates = [
                r
                for r in rows
                if isinstance(r, dict)
                and r.get("engine") == best_engine_key
                and isinstance(r.get("axle_ratio"), str)
                and _match_axle_ratio(r["axle_ratio"], axle_ratio)
                and isinstance(r.get("values"), list)
            ]
            if candidates:
                # Prefer the row that yields a value for this column
                chosen = next((r for r in candidates if col_idx < len(r["values"]) and r["values"][col_idx] is not None), candidates[0])
                cell = chosen["values"][col_idx] if col_idx < len(chosen["values"]) else None
                val = _pick_cell_value(cell, trim=trim)
                if val is not None:
                    res["conventional_tow_lbs"] = val
                    res["gcwr_lbs"] = chosen.get("gcwr_lbs")
                    res["matched_engine_key"] = best_engine_key
                    res["matched_axle_ratio_key"] = chosen.get("axle_ratio")

        if col_idx is not None and isinstance(rows_fw, list) and isinstance(cols_fw, list):
            # Fifth-wheel/gooseneck table shares column structure; reuse col_idx by matching cols_fw.
            col_idx_fw = None
            if wheelbase and cab and drivetrain:
                dt = str(drivetrain).replace(" ", "").upper()
                dt = "4x4" if dt in {"4X4", "4WD", "AWD"} else "4x2" if dt in {"4X2", "RWD"} else dt
                for i, c in enumerate(cols_fw):
                    if not isinstance(c, dict):
                        continue
                    if c.get("drivetrain") != dt or c.get("cab") != cab:
                        continue
                    if abs(float(c.get("wheelbase", 0.0)) - float(wheelbase)) > 0.3:
                        continue
                    col_idx_fw = i
                    break
            if col_idx_fw is not None:
                engine_keys = [r.get("engine") for r in rows_fw if isinstance(r, dict) and isinstance(r.get("engine"), str)]
                best_engine_key = _pick_best_engine_key(engine_norm, [k for k in engine_keys if k])
                candidates = [
                    r
                    for r in rows_fw
                    if isinstance(r, dict)
                    and r.get("engine") == best_engine_key
                    and isinstance(r.get("axle_ratio"), str)
                    and _match_axle_ratio(r["axle_ratio"], axle_ratio)
                    and isinstance(r.get("values"), list)
                ]
                if candidates:
                    chosen = next((r for r in candidates if col_idx_fw < len(r["values"]) and r["values"][col_idx_fw] is not None), candidates[0])
                    cell = chosen["values"][col_idx_fw] if col_idx_fw < len(chosen["values"]) else None
                    val = _pick_cell_value(cell, trim=trim)
                    if val is not None:
                        res["fifth_wheel_tow_lbs"] = val
                        res["gooseneck_tow_lbs"] = val

    def _range_from_selector(sel: dict[str, Any]) -> dict[str, int] | None:
        if not isinstance(sel, dict):
            return None
        by_engine = sel.get("by_engine") or {}
        if not isinstance(by_engine, dict) or not by_engine:
            return None
        best_key = _pick_best_engine_key(engine_norm, list(by_engine.keys()))
        if not best_key:
            return None
        rec = by_engine.get(best_key) or {}
        vals = rec.get("tow_values_lbs") if isinstance(rec, dict) else None
        if not isinstance(vals, list) or not vals:
            return None
        ints = [int(x) for x in vals if isinstance(x, int) or (isinstance(x, float) and x.is_integer())]
        if not ints:
            return None
        return {"min": min(ints), "max": max(ints)}

    if model == "F-150":
        # Only fall back to range-based selector if exact did not produce a value.
        if "conventional_tow_lbs" not in res:
            conv = selectors.get("conventional")
            if conv:
                res["conventional_tow_lbs"] = _range_from_selector(conv)
        if "fifth_wheel_tow_lbs" not in res:
            fw = selectors.get("fifth_wheel_gooseneck")
            if fw:
                rng = _range_from_selector(fw)
                res["fifth_wheel_tow_lbs"] = rng
                res["gooseneck_tow_lbs"] = rng

    if model in {"F-250", "F-350", "F-450"}:
        # Prefer the closest matching selector bucket; without wheelbase/box we still return range.
        key_map = {
            "F-250": ("f250_conventional", "f250_fifth_wheel_gooseneck"),
            "F-350": ("f350_srw_conventional", "f350_srw_fifth_wheel_gooseneck"),
            "F-450": ("f350_f450_drw_conventional", "f350_f450_drw_fifth_wheel_gooseneck"),
        }
        k_conv, k_fw = key_map.get(model, (None, None))
        if k_conv and selectors.get(k_conv):
            res["conventional_tow_lbs"] = _range_from_selector(selectors[k_conv])
        if k_fw and selectors.get(k_fw):
            rng = _range_from_selector(selectors[k_fw])
            res["fifth_wheel_tow_lbs"] = rng
            res["gooseneck_tow_lbs"] = rng

    if model in {"Ranger", "Maverick"}:
        # These selectors are exact and already keyed by drivetrain variants.
        try:
            block = selectors.get("max_loaded_trailer_weight", {})
            rows = block.get("max_loaded_trailer_weight", [])
            best_engine_key = None
            best_row = None
            if isinstance(rows, list) and rows:
                engine_keys = [r.get("engine") for r in rows if isinstance(r, dict) and isinstance(r.get("engine"), str)]
                best_engine_key = _pick_best_engine_key(engine_norm, [k for k in engine_keys if k])
                for r in rows:
                    if isinstance(r, dict) and r.get("engine") == best_engine_key:
                        best_row = r
                        break
            if best_row and isinstance(best_row.get("variants"), list):
                # pick variant matching drivetrain if possible; respect package requirements (e.g. Maverick 4K Tow)
                dt = (drivetrain or "").upper().replace(" ", "")
                best_var = None
                dt_candidates = []
                for v in best_row["variants"]:
                    if not isinstance(v, dict):
                        continue
                    if not _requirements_satisfied(v.get("requires"), equip_blob):
                        continue
                    if (v.get("drivetrain") or "").upper().replace(" ", "") == dt:
                        dt_candidates.append(v)
                if dt_candidates:
                    best_var = max(
                        (v for v in dt_candidates if isinstance(v.get("max_trailer_lbs"), int)),
                        key=lambda x: x["max_trailer_lbs"],
                        default=None,
                    )
                if not best_var:
                    best_var = max(
                        (
                            v
                            for v in best_row["variants"]
                            if isinstance(v, dict)
                            and isinstance(v.get("max_trailer_lbs"), int)
                            and _requirements_satisfied(v.get("requires"), equip_blob)
                        ),
                        key=lambda x: x["max_trailer_lbs"],
                        default=None,
                    )
                if best_var:
                    res["conventional_tow_lbs"] = best_var.get("max_trailer_lbs")
                    res["gcwr_lbs"] = best_var.get("gcwr_lbs")
                    if best_var.get("requires"):
                        res["requires"] = best_var.get("requires")
                    res["matched_engine_key"] = best_engine_key
        except Exception:
            pass

    return TowResult(
        model=model,
        year=int(year or 2025),
        inputs={
            "vin": vin,
            "model": model,
            "trim": trim,
            "drivetrain": drivetrain,
            "engine": engine,
            "cab": cab,
            "axle_ratio": axle_ratio,
        },
        missing_inputs=missing,
        results=res,
    )


