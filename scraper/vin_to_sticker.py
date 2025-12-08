#!/usr/bin/env python3
"""
Download Ford window sticker PDFs for all VINs in the inventory database.

This script:
  - Reads VIN and stock from /root/www/db/inventory.sqlite (vehicles table)
  - Hits https://www.windowsticker.forddirect.com/windowsticker.pdf?vin={vin}
    for each VIN
  - Saves the resulting PDF as /root/www/scraper/stickers/{vin}.pdf

We do a small amount of validation to avoid saving the "Please check back later."
placeholder page when a sticker has not yet been released
([reference](https://www.windowsticker.forddirect.com/windowsticker.pdf?vin={insert_vin_here})).
"""

from __future__ import annotations

import argparse
import logging
import sqlite3
import sys
import time
from dataclasses import dataclass
from pathlib import Path
from typing import Iterable
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen


ROOT_DIR = Path("/root/www")
DB_PATH = ROOT_DIR / "db" / "inventory.sqlite"
STICKERS_DIR = ROOT_DIR / "scraper" / "stickers"
WINDOW_STICKER_URL = (
    "https://www.windowsticker.forddirect.com/windowsticker.pdf?vin={vin}"
)


@dataclass
class Vehicle:
    vin: str
    stock: str | None


def fetch_inventory(conn: sqlite3.Connection) -> list[Vehicle]:
    """Return all (vin, stock) rows from the vehicles table."""
    cur = conn.cursor()
    cur.execute("SELECT vin, stock FROM vehicles")
    rows = [Vehicle(vin=row[0], stock=row[1]) for row in cur.fetchall()]
    return rows


def ensure_output_dir(path: Path) -> None:
    path.mkdir(parents=True, exist_ok=True)


def is_pdf_response(content_type: str, data: bytes) -> bool:
    """
    Heuristically determine if the response is a real PDF.

    We check both the Content-Type header and the leading bytes for the %PDF
    magic header. This helps us avoid saving the "Please check back later."
    text/HTML placeholder as a .pdf file.
    """
    ct = (content_type or "").lower()
    if "pdf" in ct:
        return True
    # Fallback: detect raw PDF by magic bytes
    if data.startswith(b"%PDF-"):
        return True
    return False


def download_sticker(vin: str, dest: Path, overwrite: bool = False) -> bool:
    """
    Download a single window sticker PDF for the given VIN.

    Returns True if a valid PDF was downloaded/saved, False otherwise.
    """
    if dest.exists() and not overwrite:
        logging.info("Sticker already exists for VIN %s at %s; skipping", vin, dest)
        return True

    url = WINDOW_STICKER_URL.format(vin=vin)
    logging.debug("Requesting window sticker for VIN %s from %s", vin, url)

    req = Request(
        url,
        headers={
            # Use a simple but non-default User-Agent to avoid trivial blocking.
            "User-Agent": "vin_2_data-sticker-fetcher/1.0",
        },
    )
    try:
        with urlopen(req, timeout=30) as resp:
            content_type = resp.headers.get("Content-Type", "")
            data = resp.read()
    except HTTPError as e:
        logging.warning(
            "HTTP error fetching sticker for VIN %s: %s (code=%s)",
            vin,
            e.reason,
            getattr(e, "code", "n/a"),
        )
        return False
    except URLError as e:
        logging.warning("Network error fetching sticker for VIN %s: %s", vin, e.reason)
        return False
    except Exception:
        logging.exception("Unexpected error fetching sticker for VIN %s", vin)
        return False

    if not is_pdf_response(content_type, data):
        logging.info(
            "No PDF sticker yet for VIN %s (Content-Type=%r, %d bytes); "
            "likely the 'Please check back later.' placeholder.",
            vin,
            content_type,
            len(data),
        )
        return False

    dest.write_bytes(data)
    logging.info("Saved window sticker for VIN %s to %s", vin, dest)
    return True


def iter_vehicles(
    vehicles: Iterable[Vehicle], limit: int | None = None
) -> Iterable[Vehicle]:
    """Optionally limit the number of vehicles processed."""
    count = 0
    for v in vehicles:
        if limit is not None and count >= limit:
            break
        yield v
        count += 1


def prune_orphan_stickers(stickers_dir: Path, valid_vins: set[str]) -> int:
    """
    Delete any sticker PDFs whose VIN (filename stem) is not present in valid_vins.

    This keeps stickers/ in sync with the most recent scraped CSV: when VINs drop
    out of inventory and are no longer written to the DB, their old PDFs are
    removed on the next run.
    """
    if not stickers_dir.exists():
        return 0

    deleted = 0
    for pdf_path in stickers_dir.glob("*.pdf"):
        vin_stem = pdf_path.stem.strip().upper()
        if vin_stem and vin_stem not in valid_vins:
            try:
                pdf_path.unlink()
                deleted += 1
                logging.info(
                    "Deleted orphan sticker PDF %s (VIN %s no longer in inventory)",
                    pdf_path,
                    vin_stem,
                )
            except OSError:
                logging.exception("Failed to delete orphan sticker PDF %s", pdf_path)
    if deleted:
        logging.info("Pruned %d orphan sticker PDFs.", deleted)
    else:
        logging.info("No orphan sticker PDFs to prune.")
    return deleted


def run(
    db_path: Path,
    stickers_dir: Path,
    *,
    limit: int | None = None,
    overwrite: bool = False,
    delay_seconds: float = 0.5,
) -> int:
    """
    Main worker: fetch inventory and download stickers.

    Returns the number of VINs for which we successfully saved a PDF.
    """
    ensure_output_dir(stickers_dir)

    if not db_path.exists():
        logging.error("Database not found at %s", db_path)
        return 0

    with sqlite3.connect(db_path) as conn:
        vehicles = fetch_inventory(conn)

    if not vehicles:
        logging.warning("No vehicles found in %s. Nothing to do.", db_path)
        # Even if there are no vehicles, we still want stickers/ to be cleaned up
        prune_orphan_stickers(stickers_dir, set())
        return 0

    logging.info("Found %d vehicles in %s", len(vehicles), db_path)
    # Normalize VINs to uppercase for consistent comparison with filenames.
    valid_vins = {v.vin.strip().upper() for v in vehicles if v.vin and v.vin.strip()}

    success_count = 0

    for idx, vehicle in enumerate(iter_vehicles(vehicles, limit=limit), start=1):
        vin = (vehicle.vin or "").strip()
        stock = (vehicle.stock or "").strip() if vehicle.stock is not None else ""
        if not vin:
            logging.debug("Row %d has empty VIN; skipping (stock=%r)", idx, stock)
            continue

        dest = stickers_dir / f"{vin}.pdf"
        logging.info(
            "[%d/%d] Processing VIN=%s stock=%s",
            idx,
            len(vehicles) if limit is None else min(len(vehicles), limit),
            vin,
            stock or "(none)",
        )

        # Fast-path: if the sticker already exists and we are not overwriting,
        # skip the network request *and* avoid sleeping. This makes re-running
        # the script over an already-downloaded set of VINs very fast.
        if dest.exists() and not overwrite:
            logging.info(
                "Sticker already exists for VIN %s at %s; skipping download",
                vin,
                dest,
            )
            continue

        if download_sticker(vin, dest, overwrite=overwrite):
            success_count += 1

        # Only throttle when we actually hit the remote server.
        if delay_seconds > 0:
            time.sleep(delay_seconds)

    # After downloads, remove any PDFs whose VIN is no longer in the DB.
    prune_orphan_stickers(stickers_dir, valid_vins)

    logging.info("Successfully saved %d window sticker PDFs.", success_count)
    return success_count


def parse_args(argv: list[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description=(
            "Download Ford window sticker PDFs for all VINs in the "
            "inventory database."
        )
    )
    parser.add_argument(
        "--db-path",
        type=Path,
        default=DB_PATH,
        help=f"Path to inventory SQLite DB (default: {DB_PATH})",
    )
    parser.add_argument(
        "--output-dir",
        type=Path,
        default=STICKERS_DIR,
        help=f"Directory to store sticker PDFs (default: {STICKERS_DIR})",
    )
    parser.add_argument(
        "--limit",
        type=int,
        default=None,
        help="Process at most this many vehicles (for testing).",
    )
    parser.add_argument(
        "--overwrite",
        action="store_true",
        help="Re-download and overwrite existing VIN PDFs.",
    )
    parser.add_argument(
        "--no-delay",
        action="store_true",
        help="Do not sleep between requests (use with caution).",
    )
    parser.add_argument(
        "--log-level",
        default="INFO",
        choices=["DEBUG", "INFO", "WARNING", "ERROR"],
        help="Logging verbosity (default: INFO).",
    )
    return parser.parse_args(argv)


def main(argv: list[str] | None = None) -> int:
    if argv is None:
        argv = sys.argv[1:]

    args = parse_args(argv)

    logging.basicConfig(
        level=getattr(logging, args.log_level.upper(), logging.INFO),
        format="%(asctime)s [%(levelname)s] %(message)s",
    )

    delay = 0.0 if args.no_delay else 0.5
    try:
        run(
            db_path=args.db_path,
            stickers_dir=args.output_dir,
            limit=args.limit,
            overwrite=args.overwrite,
            delay_seconds=delay,
        )
    except KeyboardInterrupt:
        logging.warning("Interrupted by user.")
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())


