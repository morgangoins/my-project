#!/usr/bin/env python3
"""
Backfill wheelbase/bed_length/truck_body_style/rear_axle_config for trucks
by deterministically parsing existing sticker PDFs.

This avoids relying on the LLM for basic configuration facts that are needed
for exact towing-guide lookup.

Usage:
  python3 /root/www/db/towing/backfill_truck_dimensions.py --limit 200
  python3 /root/www/db/towing/backfill_truck_dimensions.py --models F-150,F-250,F-350,Ranger,Maverick
"""

from __future__ import annotations

import argparse
import sqlite3
from pathlib import Path

from pypdf import PdfReader


DB_PATH = Path("/root/www/db/inventory.sqlite")
STICKERS_DIR = Path("/root/www/scraper/stickers")


def _extract_text(pdf_path: Path) -> str:
    reader = PdfReader(str(pdf_path))
    # The config line (model/cab/wheelbase) is on the first page for Ford stickers,
    # and extracting the full PDF is much slower.
    if not reader.pages:
        return ""
    return reader.pages[0].extract_text() or ""


def _derive_truck_fields(pdf_text: str) -> dict[str, object]:
    # Reuse the same logic as the enrichment pipeline.
    # Imported lazily to avoid path/import issues when running standalone.
    import sys

    sys.path.insert(0, "/root/www/scraper")
    from sticker_2_data import _derive_truck_fields_from_pdf_text  # type: ignore

    return _derive_truck_fields_from_pdf_text(pdf_text)


def _infer_model_table(model: str) -> str:
    if model == "F-150":
        return "f150"
    if model in {"F-250", "F-350", "F-450"}:
        return "super_duty"
    if model == "Ranger":
        return "ranger"
    if model == "Maverick":
        return "maverick"
    return "unknown"


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--limit", type=int, default=999999)
    ap.add_argument("--models", type=str, default="F-150,F-250,F-350,F-450,Ranger,Maverick")
    args = ap.parse_args()
    model_set = {m.strip() for m in args.models.split(",") if m.strip()}

    con = sqlite3.connect(str(DB_PATH))
    cur = con.cursor()

    rows = cur.execute(
        """
        SELECT vin, model
        FROM vehicles
        WHERE model IN ('F-150','F-250','F-350','F-450','Ranger','Maverick')
        ORDER BY vin
        """
    ).fetchall()

    updated = 0
    scanned = 0
    for vin, model in rows:
        if model not in model_set:
            continue
        if scanned >= args.limit:
            break
        scanned += 1
        if scanned % 25 == 0:
            print(f"... scanned {scanned}, updated {updated}")
        pdf = STICKERS_DIR / f"{vin}.pdf"
        if not pdf.exists():
            continue
        try:
            text = _extract_text(pdf)
        except Exception:
            continue

        derived = _derive_truck_fields(text)
        if not derived:
            continue

        table = _infer_model_table(model)
        if table == "unknown":
            continue

        # Ensure row exists in model table
        cur.execute(f"INSERT OR IGNORE INTO {table} (vin) VALUES (?)", (vin,))

        sets = []
        params: dict[str, object] = {"vin": vin}
        for k in ["truck_body_style", "rear_axle_config", "wheelbase", "bed_length"]:
            if k in derived:
                sets.append(f"{k} = :{k}")
                params[k] = derived[k]
        if not sets:
            continue
        cur.execute(f"UPDATE {table} SET {', '.join(sets)} WHERE vin = :vin", params)
        updated += cur.rowcount

        if updated and updated % 50 == 0:
            con.commit()

    con.commit()
    print(f"Scanned {scanned} trucks, updated {updated} rows")


if __name__ == "__main__":
    main()


