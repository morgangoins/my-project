#!/usr/bin/env python3
"""
Unified inventory update script.

This script orchestrates the full inventory update workflow:
  1. Scrape new inventory from Ford Fairfield's API
  2. Sync the SQLite database with the scraped CSV
  3. Download window sticker PDFs for all VINs
  4. Enrich the database using AI extraction from stickers
  5. Generate static JSON cache for fast page loads

Usage:
  python update.py                    # Run all steps
  python update.py --skip-scrape      # Skip scraping (use existing CSV)
  python update.py --skip-stickers    # Skip sticker downloads
  python update.py --skip-enrich      # Skip AI enrichment
  python update.py --verbose          # More detailed output
"""

import argparse
import logging
import os
import subprocess
import sys
import time
from datetime import datetime
from pathlib import Path

# Add script directories to path for imports
ROOT_DIR = Path(__file__).parent.resolve()
LOG_DIR = ROOT_DIR / "var" / "logs"
LOG_FILE = LOG_DIR / "update.log"

sys.path.insert(0, str(ROOT_DIR / "scraper"))
sys.path.insert(0, str(ROOT_DIR / "db"))


def setup_logging() -> logging.Logger:
    """Configure file and console logging."""
    LOG_DIR.mkdir(parents=True, exist_ok=True)
    
    logger = logging.getLogger("update")
    logger.setLevel(logging.INFO)
    
    # File handler - always logs
    file_handler = logging.FileHandler(LOG_FILE)
    file_handler.setLevel(logging.INFO)
    file_handler.setFormatter(logging.Formatter(
        "[%(asctime)s] %(message)s", datefmt="%Y-%m-%d %H:%M:%S %Z"
    ))
    logger.addHandler(file_handler)
    
    return logger


# Global logger instance
_logger: logging.Logger | None = None


def log(message: str) -> None:
    """Log a message to the log file."""
    global _logger
    if _logger is None:
        _logger = setup_logging()
    _logger.info(message)


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


def step_generate_cache(verbose: bool = False) -> dict:
    """Step 5: Generate static JSON cache for fast page loads."""
    result = {"success": False, "error": None}
    
    try:
        cache_script = ROOT_DIR / "db" / "generate_cache.php"
        if not cache_script.exists():
            result["error"] = f"Cache script not found: {cache_script}"
            return result
        
        proc = subprocess.run(
            ["php", str(cache_script)],
            capture_output=True,
            text=True,
            cwd=str(ROOT_DIR / "db"),
        )
        
        if verbose and proc.stdout:
            print(proc.stdout)
        if verbose and proc.stderr:
            print(proc.stderr, file=sys.stderr)
        
        if proc.returncode != 0:
            result["error"] = proc.stderr or f"Exit code {proc.returncode}"
            return result
        
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
    
    if "cache" in results:
        r = results["cache"]
        if r["success"]:
            print("✓ Cache:       generated")
        else:
            print(f"✗ Cache:       FAILED - {r['error']}")
    else:
        print("○ Cache:       skipped")
    
    print("-" * 50)
    print(f"Total time: {elapsed:.1f}s")
    print("=" * 50)


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Update website inventory (scrape → sync → stickers → enrich → cache)"
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
    
    log("Starting inventory update...")
    
    # Check environment
    warnings = check_environment()
    for warning in warnings:
        print(f"⚠ Warning: {warning}")
        log(f"Warning: {warning}")
    
    # Step 1: Scrape
    if not args.skip_scrape:
        print("\n[1/5] Scraping inventory from Ford Fairfield API...")
        log("Running scraper...")
        results["scrape"] = step_scrape(args.verbose)
        if not results["scrape"]["success"]:
            print(f"  ✗ Scrape failed: {results['scrape']['error']}")
            log(f"ERROR: Scraper failed - {results['scrape']['error']}")
            print("  Stopping due to scrape failure.")
            print_summary(results, time.time() - start_time)
            return 1
        print(f"  ✓ Scraped {results['scrape']['vehicles']} vehicles")
        log(f"Scraper completed: {results['scrape']['vehicles']} vehicles")
    else:
        print("\n[1/5] Scraping... SKIPPED")
        log("Scraper skipped")
    
    # Step 2: Sync DB
    print("\n[2/5] Syncing database from CSV...")
    log("Syncing database...")
    results["sync"] = step_sync_db(args.verbose)
    if not results["sync"]["success"]:
        print(f"  ✗ Sync failed: {results['sync']['error']}")
        log(f"ERROR: Database sync failed - {results['sync']['error']}")
        print("  Stopping due to sync failure.")
        print_summary(results, time.time() - start_time)
        return 1
    print(f"  ✓ Database now has {results['sync']['total']} vehicles")
    log(f"Database sync completed: {results['sync']['total']} vehicles")
    
    # Step 3: Download stickers
    if not args.skip_stickers:
        print("\n[3/5] Downloading window sticker PDFs...")
        log("Downloading stickers...")
        results["stickers"] = step_download_stickers(args.verbose)
        if not results["stickers"]["success"]:
            print(f"  ✗ Sticker download failed: {results['stickers']['error']}")
            log(f"WARNING: Sticker download failed - {results['stickers']['error']}")
            # Continue anyway - enrichment can still work on existing stickers
        else:
            print(f"  ✓ Downloaded {results['stickers']['downloaded']}, skipped {results['stickers']['skipped']} cached")
            log(f"Stickers completed: {results['stickers']['downloaded']} downloaded, {results['stickers']['skipped']} cached")
    else:
        print("\n[3/5] Downloading stickers... SKIPPED")
        log("Sticker download skipped")
    
    # Step 4: Enrich data
    if not args.skip_enrich:
        if os.getenv("OPENROUTER_API_KEY"):
            print("\n[4/5] Enriching database from stickers (AI extraction)...")
            log("Running AI enrichment...")
            results["enrich"] = step_enrich_data(args.verbose)
            if not results["enrich"]["success"]:
                print(f"  ✗ Enrichment failed: {results['enrich']['error']}")
                log(f"WARNING: Enrichment failed - {results['enrich']['error']}")
            else:
                print(f"  ✓ Processed {results['enrich']['processed']} stickers")
                log(f"Enrichment completed: {results['enrich']['processed']} processed")
        else:
            print("\n[4/5] Enrichment... SKIPPED (no API key)")
            log("Enrichment skipped (no API key)")
            results["enrich"] = {"success": False, "error": "No API key"}
    else:
        print("\n[4/5] Enrichment... SKIPPED")
        log("Enrichment skipped")
    
    # Step 5: Generate cache
    print("\n[5/5] Generating static cache...")
    log("Generating cache...")
    results["cache"] = step_generate_cache(args.verbose)
    if not results["cache"]["success"]:
        print(f"  ✗ Cache generation failed: {results['cache']['error']}")
        log(f"WARNING: Cache generation failed - {results['cache']['error']}")
    else:
        print("  ✓ Cache generated")
        log("Cache generation completed")
    
    # Print summary
    elapsed = time.time() - start_time
    print_summary(results, elapsed)
    log(f"Inventory update completed in {elapsed:.1f}s")
    
    return 0


if __name__ == "__main__":
    sys.exit(main())
