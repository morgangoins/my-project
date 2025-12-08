#!/usr/bin/env python3
"""
Unified inventory update script.

This script orchestrates the full inventory update workflow:
  1. Scrape new inventory from Ford Fairfield's API
  2. Sync the SQLite database with the scraped CSV
  3. Download window sticker PDFs for all VINs
  4. Enrich the database using AI extraction from stickers

Usage:
  python update.py                    # Run all steps
  python update.py --skip-scrape      # Skip scraping (use existing CSV)
  python update.py --skip-stickers    # Skip sticker downloads
  python update.py --skip-enrich      # Skip AI enrichment
  python update.py --verbose          # More detailed output
"""

import argparse
import os
import sys
import time
from pathlib import Path

# Add script directories to path for imports
ROOT_DIR = Path(__file__).parent.resolve()
sys.path.insert(0, str(ROOT_DIR / "scraper"))
sys.path.insert(0, str(ROOT_DIR / "db"))
sys.path.insert(0, str(ROOT_DIR / "vin_2_data"))


def check_environment() -> list[str]:
    """Check for required environment variables. Returns list of warnings."""
    warnings = []
    if not os.getenv("OPENROUTER_API_KEY"):
        warnings.append(
            "OPENROUTER_API_KEY is not set. Step 4 (enrichment) will be skipped."
        )
    return warnings


def step_scrape(verbose: bool = False) -> dict:
    """Step 1: Scrape inventory from Ford Fairfield API."""
    result = {"success": False, "vehicles": 0, "error": None}
    
    try:
        # Import and run the scraper
        # scrapeNew.py runs on import (no main function), so we need to
        # capture its behavior differently
        import importlib.util
        
        scraper_path = ROOT_DIR / "scraper" / "scrapeNew.py"
        spec = importlib.util.spec_from_file_location("scrapeNew", scraper_path)
        scraper = importlib.util.module_from_spec(spec)
        
        # Capture stdout to parse vehicle count
        from io import StringIO
        import contextlib
        
        stdout_capture = StringIO()
        with contextlib.redirect_stdout(stdout_capture):
            spec.loader.exec_module(scraper)
        
        output = stdout_capture.getvalue()
        if verbose:
            print(output)
        
        # Parse "Saved X unique vehicles to ..."
        for line in output.split("\n"):
            if "unique vehicles" in line.lower():
                parts = line.split()
                for i, part in enumerate(parts):
                    if part == "Saved" and i + 1 < len(parts):
                        try:
                            result["vehicles"] = int(parts[i + 1])
                        except ValueError:
                            pass
                        break
        
        result["success"] = True
        
    except Exception as e:
        result["error"] = str(e)
    
    return result


def step_sync_db(verbose: bool = False) -> dict:
    """Step 2: Sync database from most recent CSV."""
    result = {"success": False, "total": 0, "error": None}
    
    try:
        import sync_db
        
        from io import StringIO
        import contextlib
        
        stdout_capture = StringIO()
        with contextlib.redirect_stdout(stdout_capture):
            sync_db.main()
        
        output = stdout_capture.getvalue()
        if verbose:
            print(output)
        
        # Parse "Synced ...; it now has X vehicles."
        for line in output.split("\n"):
            if "vehicles" in line.lower():
                parts = line.split()
                for i, part in enumerate(parts):
                    if part == "has" and i + 1 < len(parts):
                        try:
                            result["total"] = int(parts[i + 1])
                        except ValueError:
                            pass
                        break
        
        result["success"] = True
        
    except Exception as e:
        result["error"] = str(e)
    
    return result


def step_download_stickers(verbose: bool = False) -> dict:
    """Step 3: Download window sticker PDFs."""
    result = {"success": False, "downloaded": 0, "skipped": 0, "error": None}
    
    try:
        import vin_to_sticker
        
        from io import StringIO
        import contextlib
        import logging
        
        # Configure logging to capture output
        log_capture = StringIO()
        handler = logging.StreamHandler(log_capture)
        handler.setLevel(logging.INFO)
        formatter = logging.Formatter("%(message)s")
        handler.setFormatter(formatter)
        
        # Get the root logger used by vin_to_sticker
        logger = logging.getLogger()
        original_handlers = logger.handlers[:]
        original_level = logger.level
        
        logger.handlers = [handler]
        logger.setLevel(logging.INFO)
        
        try:
            vin_to_sticker.main([])
        finally:
            logger.handlers = original_handlers
            logger.level = original_level
        
        output = log_capture.getvalue()
        if verbose:
            print(output)
        
        # Count results from log output
        for line in output.split("\n"):
            if "Saved window sticker" in line:
                result["downloaded"] += 1
            elif "already exists" in line.lower():
                result["skipped"] += 1
        
        result["success"] = True
        
    except Exception as e:
        result["error"] = str(e)
    
    return result


def step_enrich_data(verbose: bool = False) -> dict:
    """Step 4: Enrich database using AI extraction from stickers."""
    result = {"success": False, "processed": 0, "errors": 0, "error": None}
    
    if not os.getenv("OPENROUTER_API_KEY"):
        result["error"] = "OPENROUTER_API_KEY not set"
        return result
    
    try:
        import sticker_2_data
        
        from io import StringIO
        import contextlib
        
        stdout_capture = StringIO()
        stderr_capture = StringIO()
        
        with contextlib.redirect_stdout(stdout_capture), contextlib.redirect_stderr(stderr_capture):
            sticker_2_data.main([])
        
        output = stdout_capture.getvalue()
        errors = stderr_capture.getvalue()
        
        if verbose:
            print(output)
            if errors:
                print(errors, file=sys.stderr)
        
        # Count processed stickers
        for line in output.split("\n"):
            if line.startswith("Processing"):
                result["processed"] += 1
        
        # Count errors
        for line in errors.split("\n"):
            if "error" in line.lower() or "skipping" in line.lower():
                result["errors"] += 1
        
        result["success"] = True
        
    except Exception as e:
        result["error"] = str(e)
    
    return result


def print_summary(results: dict, elapsed: float) -> None:
    """Print a summary of all steps."""
    print("\n" + "=" * 50)
    print("UPDATE COMPLETE")
    print("=" * 50)
    
    if "scrape" in results:
        r = results["scrape"]
        if r["success"]:
            print(f"✓ Scrape:      {r['vehicles']} vehicles fetched")
        else:
            print(f"✗ Scrape:      FAILED - {r['error']}")
    else:
        print("○ Scrape:      skipped")
    
    if "sync" in results:
        r = results["sync"]
        if r["success"]:
            print(f"✓ DB Sync:     {r['total']} vehicles in database")
        else:
            print(f"✗ DB Sync:     FAILED - {r['error']}")
    else:
        print("○ DB Sync:     skipped")
    
    if "stickers" in results:
        r = results["stickers"]
        if r["success"]:
            print(f"✓ Stickers:    {r['downloaded']} downloaded, {r['skipped']} already cached")
        else:
            print(f"✗ Stickers:    FAILED - {r['error']}")
    else:
        print("○ Stickers:    skipped")
    
    if "enrich" in results:
        r = results["enrich"]
        if r["success"]:
            err_str = f", {r['errors']} errors" if r["errors"] else ""
            print(f"✓ Enrichment:  {r['processed']} processed{err_str}")
        else:
            print(f"✗ Enrichment:  FAILED - {r['error']}")
    else:
        print("○ Enrichment:  skipped")
    
    print("-" * 50)
    print(f"Total time: {elapsed:.1f}s")
    print("=" * 50)


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Update website inventory (scrape → sync → stickers → enrich)"
    )
    parser.add_argument(
        "--skip-scrape",
        action="store_true",
        help="Skip step 1 (use existing CSV)",
    )
    parser.add_argument(
        "--skip-stickers",
        action="store_true",
        help="Skip step 3 (don't download new stickers)",
    )
    parser.add_argument(
        "--skip-enrich",
        action="store_true",
        help="Skip step 4 (don't run AI enrichment)",
    )
    parser.add_argument(
        "--verbose", "-v",
        action="store_true",
        help="Show detailed output from each step",
    )
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    results = {}
    start_time = time.time()
    
    # Check environment
    warnings = check_environment()
    for warning in warnings:
        print(f"⚠ Warning: {warning}")
    
    # Step 1: Scrape
    if not args.skip_scrape:
        print("\n[1/4] Scraping inventory from Ford Fairfield API...")
        results["scrape"] = step_scrape(args.verbose)
        if not results["scrape"]["success"]:
            print(f"  ✗ Scrape failed: {results['scrape']['error']}")
            print("  Stopping due to scrape failure.")
            print_summary(results, time.time() - start_time)
            return 1
        print(f"  ✓ Scraped {results['scrape']['vehicles']} vehicles")
    else:
        print("\n[1/4] Scraping... SKIPPED")
    
    # Step 2: Sync DB
    print("\n[2/4] Syncing database from CSV...")
    results["sync"] = step_sync_db(args.verbose)
    if not results["sync"]["success"]:
        print(f"  ✗ Sync failed: {results['sync']['error']}")
        print("  Stopping due to sync failure.")
        print_summary(results, time.time() - start_time)
        return 1
    print(f"  ✓ Database now has {results['sync']['total']} vehicles")
    
    # Step 3: Download stickers
    if not args.skip_stickers:
        print("\n[3/4] Downloading window sticker PDFs...")
        results["stickers"] = step_download_stickers(args.verbose)
        if not results["stickers"]["success"]:
            print(f"  ✗ Sticker download failed: {results['stickers']['error']}")
            # Continue anyway - enrichment can still work on existing stickers
        else:
            print(f"  ✓ Downloaded {results['stickers']['downloaded']}, skipped {results['stickers']['skipped']} cached")
    else:
        print("\n[3/4] Downloading stickers... SKIPPED")
    
    # Step 4: Enrich data
    if not args.skip_enrich:
        if os.getenv("OPENROUTER_API_KEY"):
            print("\n[4/4] Enriching database from stickers (AI extraction)...")
            results["enrich"] = step_enrich_data(args.verbose)
            if not results["enrich"]["success"]:
                print(f"  ✗ Enrichment failed: {results['enrich']['error']}")
            else:
                print(f"  ✓ Processed {results['enrich']['processed']} stickers")
        else:
            print("\n[4/4] Enrichment... SKIPPED (no API key)")
            results["enrich"] = {"success": False, "error": "No API key"}
    else:
        print("\n[4/4] Enrichment... SKIPPED")
    
    # Print summary
    print_summary(results, time.time() - start_time)
    
    return 0


if __name__ == "__main__":
    sys.exit(main())
