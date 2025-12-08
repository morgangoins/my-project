#!/usr/bin/env python3
"""
Download Ford invoice PDFs for all VINs in the inventory database.

This script:
  - Reads VIN and stock from /root/www/db/inventory.sqlite (vehicles table)
  - Logs into https://fordvisions.dealerconnection.com via browser automation
  - Hits https://fordvisions.dealerconnection.com/vinv/GetInvoice.aspx?v={vin}
    for each VIN
  - Saves the resulting PDF as /root/www/vin_2_data/invoices/{vin}.pdf

Requires Playwright for browser automation due to login requirements.
Install with: pip install playwright && playwright install chromium

Proxy support:
  Use --proxy to specify a proxy server. Supported formats:
    - http://host:port
    - http://user:pass@host:port
    - socks5://host:port
    - socks5://user:pass@host:port
  Or use --proxy-server, --proxy-username, --proxy-password separately.
"""

from __future__ import annotations

import argparse
import logging
import os
import sqlite3
import sys
import time
from dataclasses import dataclass
from pathlib import Path
from typing import Iterable
from urllib.parse import urlparse

from playwright.sync_api import sync_playwright, Browser, Page, TimeoutError as PlaywrightTimeout


ROOT_DIR = Path("/root/www")
DB_PATH = ROOT_DIR / "db" / "inventory.sqlite"
INVOICES_DIR = ROOT_DIR / "vin_2_data" / "invoices"
INVOICE_URL = "https://fordvisions.dealerconnection.com/vinv/GetInvoice.aspx?v={vin}"
LOGIN_URL = "https://fordvisions.dealerconnection.com"

# Login credentials
USERNAME = "m-goins4@b2bford.com"
PASSWORD = "4Marshall$$$$$"


@dataclass
class ProxyConfig:
    """Proxy configuration for browser connections."""
    server: str  # e.g., "http://proxy.example.com:8080" or "socks5://proxy:1080"
    username: str | None = None
    password: str | None = None

    def to_playwright_proxy(self) -> dict:
        """Convert to Playwright proxy format."""
        proxy = {"server": self.server}
        if self.username:
            proxy["username"] = self.username
        if self.password:
            proxy["password"] = self.password
        return proxy

    @classmethod
    def from_url(cls, url: str) -> "ProxyConfig":
        """
        Parse a proxy URL into ProxyConfig.
        
        Supports formats like:
          - http://host:port
          - http://user:pass@host:port
          - socks5://host:port
        """
        parsed = urlparse(url)
        
        # Reconstruct server URL without credentials
        if parsed.port:
            server = f"{parsed.scheme}://{parsed.hostname}:{parsed.port}"
        else:
            server = f"{parsed.scheme}://{parsed.hostname}"
        
        return cls(
            server=server,
            username=parsed.username,
            password=parsed.password,
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


def login_to_fordvisions(page: Page, debug_dir: Path | None = None) -> bool:
    """
    Log into Ford Visions dealer portal via Ford SAML SSO.
    
    Returns True if login was successful, False otherwise.
    """
    try:
        logging.info("Navigating to Ford Visions login page...")
        page.goto(LOGIN_URL, wait_until="networkidle", timeout=30000)
        
        # Click "Dealer, Supplier, Other Login" button
        logging.info("Clicking 'Dealer, Supplier, Other Login' button...")
        dealer_login_btn = page.get_by_text("Dealer, Supplier, Other Login")
        dealer_login_btn.click(timeout=10000)
        
        # Wait for Ford SSO page to load (redirects to faust.idp.ford.com)
        logging.info("Waiting for Ford SSO login page...")
        page.wait_for_load_state("networkidle", timeout=30000)
        
        # Debug: save screenshot if debug_dir provided
        if debug_dir:
            debug_dir.mkdir(parents=True, exist_ok=True)
            page.screenshot(path=str(debug_dir / "01_sso_page.png"))
            logging.debug("Saved screenshot to %s", debug_dir / "01_sso_page.png")
        
        # Wait for the login form - Ford SSO uses standard input fields
        logging.info("Filling login credentials on Ford SSO page...")
        
        # Wait for username field - try multiple selectors for Ford SSO
        username_selector = "input#userId, input[name='userId'], input#username, input[name='username'], input[type='text']:visible, input[type='email']:visible"
        page.wait_for_selector(username_selector, timeout=20000)
        
        # Fill username
        username_field = page.locator(username_selector).first
        username_field.fill(USERNAME)
        logging.info("Entered username")
        
        # Fill password
        password_selector = "input#password, input[name='password'], input[type='password']:visible"
        password_field = page.locator(password_selector).first
        password_field.fill(PASSWORD)
        logging.info("Entered password")
        
        if debug_dir:
            page.screenshot(path=str(debug_dir / "02_credentials_filled.png"))
        
        # Submit the form - Ford SSO typically has a "Sign In" or "Log In" button
        submit_selector = "input[type='submit'], button[type='submit'], button:has-text('Sign In'), button:has-text('Log In'), button:has-text('Submit'), #submitButton"
        submit_btn = page.locator(submit_selector).first
        submit_btn.click(timeout=10000)
        logging.info("Clicked submit button")
        
        # Wait for login to complete and redirect back
        page.wait_for_load_state("networkidle", timeout=60000)
        
        if debug_dir:
            page.screenshot(path=str(debug_dir / "03_after_login.png"))
        
        # Check if we're logged in by looking at the URL or page content
        current_url = page.url
        logging.info("After login, current URL: %s", current_url)
        
        # If we're still on the SSO page, login might have failed
        if "faust.idp.ford.com" in current_url or "error" in current_url.lower():
            logging.error("Login appears to have failed - still on SSO page or error page")
            if debug_dir:
                page.screenshot(path=str(debug_dir / "04_login_failed.png"))
            return False
        
        logging.info("Login completed successfully.")
        return True
        
    except PlaywrightTimeout as e:
        logging.error("Timeout during login: %s", e)
        if debug_dir:
            try:
                page.screenshot(path=str(debug_dir / "error_timeout.png"))
            except Exception:
                pass
        return False
    except Exception as e:
        logging.exception("Error during login: %s", e)
        if debug_dir:
            try:
                page.screenshot(path=str(debug_dir / "error_exception.png"))
            except Exception:
                pass
        return False


def download_invoice(page: Page, vin: str, dest: Path, overwrite: bool = False) -> bool:
    """
    Download a single invoice PDF for the given VIN.

    Returns True if a valid PDF was downloaded/saved, False otherwise.
    """
    if dest.exists() and not overwrite:
        logging.info("Invoice already exists for VIN %s at %s; skipping", vin, dest)
        return True

    url = INVOICE_URL.format(vin=vin)
    logging.debug("Requesting invoice for VIN %s from %s", vin, url)

    try:
        # Set up download handling
        with page.expect_download(timeout=60000) as download_info:
            page.goto(url, wait_until="networkidle", timeout=30000)
        
        download = download_info.value
        download.save_as(dest)
        logging.info("Saved invoice for VIN %s to %s", vin, dest)
        return True
        
    except PlaywrightTimeout:
        # No download triggered - might be inline PDF or error page
        # Try to capture the page as PDF if it's displaying invoice content
        try:
            # Check if page has PDF content or invoice data
            content = page.content()
            
            # If the page contains error indicators, log and skip
            if "error" in content.lower() or "not found" in content.lower() or "invalid" in content.lower():
                logging.info("No invoice available for VIN %s (error page detected)", vin)
                return False
            
            # Try to print the page as PDF
            page.pdf(path=str(dest))
            logging.info("Saved invoice (as page PDF) for VIN %s to %s", vin, dest)
            return True
            
        except Exception as e:
            logging.warning("Could not save invoice for VIN %s: %s", vin, e)
            return False
            
    except Exception as e:
        logging.warning("Error downloading invoice for VIN %s: %s", vin, e)
        return False


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


def prune_orphan_invoices(invoices_dir: Path, valid_vins: set[str]) -> int:
    """
    Delete any invoice PDFs whose VIN (filename stem) is not present in valid_vins.

    This keeps invoices/ in sync with the most recent scraped CSV: when VINs drop
    out of inventory and are no longer written to the DB, their old PDFs are
    removed on the next run.
    """
    if not invoices_dir.exists():
        return 0

    deleted = 0
    for pdf_path in invoices_dir.glob("*.pdf"):
        vin_stem = pdf_path.stem.strip().upper()
        if vin_stem and vin_stem not in valid_vins:
            try:
                pdf_path.unlink()
                deleted += 1
                logging.info(
                    "Deleted orphan invoice PDF %s (VIN %s no longer in inventory)",
                    pdf_path,
                    vin_stem,
                )
            except OSError:
                logging.exception("Failed to delete orphan invoice PDF %s", pdf_path)
    if deleted:
        logging.info("Pruned %d orphan invoice PDFs.", deleted)
    else:
        logging.info("No orphan invoice PDFs to prune.")
    return deleted


def run(
    db_path: Path,
    invoices_dir: Path,
    *,
    limit: int | None = None,
    overwrite: bool = False,
    delay_seconds: float = 1.0,
    headless: bool = True,
    debug: bool = False,
    proxy: ProxyConfig | None = None,
) -> int:
    """
    Main worker: fetch inventory and download invoices.

    Returns the number of VINs for which we successfully saved a PDF.
    """
    ensure_output_dir(invoices_dir)
    debug_dir = invoices_dir / "debug" if debug else None

    if not db_path.exists():
        logging.error("Database not found at %s", db_path)
        return 0

    with sqlite3.connect(db_path) as conn:
        vehicles = fetch_inventory(conn)

    if not vehicles:
        logging.warning("No vehicles found in %s. Nothing to do.", db_path)
        prune_orphan_invoices(invoices_dir, set())
        return 0

    logging.info("Found %d vehicles in %s", len(vehicles), db_path)
    valid_vins = {v.vin.strip().upper() for v in vehicles if v.vin and v.vin.strip()}

    success_count = 0

    with sync_playwright() as p:
        # Configure browser launch options
        launch_options = {"headless": headless}
        
        # Configure context options
        context_options = {
            "accept_downloads": True,
            "viewport": {"width": 1280, "height": 720},
        }
        
        # Add proxy if configured
        if proxy:
            context_options["proxy"] = proxy.to_playwright_proxy()
            logging.info("Using proxy: %s", proxy.server)
        
        browser = p.chromium.launch(**launch_options)
        context = browser.new_context(**context_options)
        page = context.new_page()

        # Perform login first
        if not login_to_fordvisions(page, debug_dir=debug_dir):
            logging.error("Failed to log in to Ford Visions. Aborting.")
            browser.close()
            return 0

        for idx, vehicle in enumerate(iter_vehicles(vehicles, limit=limit), start=1):
            vin = (vehicle.vin or "").strip()
            stock = (vehicle.stock or "").strip() if vehicle.stock is not None else ""
            if not vin:
                logging.debug("Row %d has empty VIN; skipping (stock=%r)", idx, stock)
                continue

            dest = invoices_dir / f"{vin}.pdf"
            logging.info(
                "[%d/%d] Processing VIN=%s stock=%s",
                idx,
                len(vehicles) if limit is None else min(len(vehicles), limit),
                vin,
                stock or "(none)",
            )

            # Fast-path: if the invoice already exists and we are not overwriting,
            # skip the network request *and* avoid sleeping.
            if dest.exists() and not overwrite:
                logging.info(
                    "Invoice already exists for VIN %s at %s; skipping download",
                    vin,
                    dest,
                )
                continue

            if download_invoice(page, vin, dest, overwrite=overwrite):
                success_count += 1

            if delay_seconds > 0:
                time.sleep(delay_seconds)

        browser.close()

    # After downloads, remove any PDFs whose VIN is no longer in the DB.
    prune_orphan_invoices(invoices_dir, valid_vins)

    logging.info("Successfully saved %d invoice PDFs.", success_count)
    return success_count


def parse_args(argv: list[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description=(
            "Download Ford invoice PDFs for all VINs in the "
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
        default=INVOICES_DIR,
        help=f"Directory to store invoice PDFs (default: {INVOICES_DIR})",
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
        "--no-headless",
        action="store_true",
        help="Show the browser window (useful for debugging).",
    )
    parser.add_argument(
        "--debug",
        action="store_true",
        help="Save debug screenshots during login process.",
    )
    parser.add_argument(
        "--log-level",
        default="INFO",
        choices=["DEBUG", "INFO", "WARNING", "ERROR"],
        help="Logging verbosity (default: INFO).",
    )
    
    # Proxy configuration
    proxy_group = parser.add_argument_group("proxy", "Proxy configuration for bypassing IP blocks")
    proxy_group.add_argument(
        "--proxy",
        type=str,
        default=None,
        help=(
            "Proxy URL (e.g., http://host:port, http://user:pass@host:port, "
            "socks5://host:port). Can also use PROXY_URL environment variable."
        ),
    )
    proxy_group.add_argument(
        "--proxy-server",
        type=str,
        default=None,
        help="Proxy server (e.g., http://proxy.example.com:8080). Alternative to --proxy.",
    )
    proxy_group.add_argument(
        "--proxy-username",
        type=str,
        default=None,
        help="Proxy username (if not included in --proxy URL).",
    )
    proxy_group.add_argument(
        "--proxy-password",
        type=str,
        default=None,
        help="Proxy password (if not included in --proxy URL).",
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

    delay = 0.0 if args.no_delay else 1.0
    headless = not args.no_headless
    
    # Build proxy configuration
    proxy = None
    proxy_url = args.proxy or os.environ.get("PROXY_URL")
    
    if proxy_url:
        # Parse proxy URL (may include credentials)
        proxy = ProxyConfig.from_url(proxy_url)
        # Override with explicit username/password if provided
        if args.proxy_username:
            proxy.username = args.proxy_username
        if args.proxy_password:
            proxy.password = args.proxy_password
    elif args.proxy_server:
        # Use separate server/username/password arguments
        proxy = ProxyConfig(
            server=args.proxy_server,
            username=args.proxy_username,
            password=args.proxy_password,
        )
    
    try:
        run(
            db_path=args.db_path,
            invoices_dir=args.output_dir,
            limit=args.limit,
            overwrite=args.overwrite,
            delay_seconds=delay,
            headless=headless,
            debug=args.debug,
            proxy=proxy,
        )
    except KeyboardInterrupt:
        logging.warning("Interrupted by user.")
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
