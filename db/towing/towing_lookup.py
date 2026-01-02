#!/usr/bin/env python3
"""
CLI helper: compute tow ratings by VIN or stock.

Usage:
  python3 /root/www/db/towing/towing_lookup.py 1FTEX1KP5SKE25349
  python3 /root/www/db/towing/towing_lookup.py B12345
"""

from __future__ import annotations

import json
import sys

from towing_calc import compute_tow_caps_from_db


def main() -> None:
    if len(sys.argv) != 2:
        raise SystemExit("Usage: towing_lookup.py VIN_OR_STOCK")
    key = sys.argv[1].strip()
    result = compute_tow_caps_from_db(vin_or_stock=key)
    print(
        json.dumps(
            {
                "model": result.model,
                "year": result.year,
                "inputs": result.inputs,
                "missing_inputs": result.missing_inputs,
                "results": result.results,
            },
            indent=2,
            sort_keys=True,
        )
    )


if __name__ == "__main__":
    main()






