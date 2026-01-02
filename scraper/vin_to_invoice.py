#!/usr/bin/env python3
"""
Download Ford invoice PDFs for all VINs in the inventory database.

This script reads VINs from /root/www/db/inventory.sqlite and downloads
invoice PDFs to /root/www/scraper/invoices/{vin}.pdf using the Oxylabs Unblock proxy.
"""

import logging
import sqlite3
import sys
import time
from dataclasses import dataclass
from pathlib import Path

from playwright.sync_api import sync_playwright, Browser, Page, TimeoutError as PlaywrightTimeout


ROOT_DIR = Path("/root/www")
DB_PATH = ROOT_DIR / "db" / "inventory.sqlite"
INVOICES_DIR = ROOT_DIR / "scraper" / "invoices"
INVOICE_URL = "https://fordvisions.dealerconnection.com/vinv/GetInvoice.aspx?v={vin}"

# Login credentials
USERNAME = "m-goins4@b2bford.com"
PASSWORD = "4Marshall$$$$$"

# Oxylabs Unblock proxy (required for Ford access) - DISABLED to avoid further account flags
PROXY_CONFIG = None


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


def check_session_valid(page: Page) -> bool:
    """Quick check if we have a valid Ford session by looking at cookies."""
    try:
        cookies = page.context.cookies()
        ford_cookies = [c for c in cookies if 'ford.com' in c.get('domain', '')]
        return len(ford_cookies) > 2  # Reasonable number of Ford cookies indicates valid session
    except Exception:
        return False


def is_login_page(page: Page) -> bool:
    """Check if we're on a login page that requires authentication."""
    try:
        url = page.url.lower()
        title = page.title().lower()

        # Check URL for login indicators
        url_indicators = ["login", "signin", "sso", "auth", "idp.ford.com", "b2b.ford.com"]
        if any(ind in url for ind in url_indicators):
            return True

        # Check title for login indicators
        title_indicators = ["home realm discovery", "sign in", "login", "authentication"]
        if any(ind in title for ind in title_indicators):
            return True

        # Check for password field
        has_password_field = page.locator("input[type='password']").count() > 0
        return has_password_field
    except Exception:
        return False


def handle_login(page: Page, vin: str) -> bool:
    """Handle Ford SSO login exactly as user described: click dealer login, enter credentials."""
    try:
        logging.info("Starting login process - looking for dealer login button")

        # Debug: check what login options are available on current page
        try:
            current_url = page.url
            current_title = page.title()
            logging.info(f"Current page - URL: {current_url}, Title: {current_title}")

            # Screenshot of initial login page
            page.screenshot(path=f"/root/www/scraper/invoices/debug_initial_login_{vin}.png")

            # Check all buttons and links
            all_elements = page.evaluate("""
                const elements = [];
                // Get all buttons, links, and inputs
                document.querySelectorAll('button, a, input[type="button"], input[type="submit"]').forEach(el => {
                    elements.push({
                        tag: el.tagName,
                        type: el.type || '',
                        text: el.textContent.trim(),
                        href: el.href || '',
                        id: el.id,
                        class: el.className,
                        visible: el.offsetWidth > 0 && el.offsetHeight > 0
                    });
                });
                return elements.filter(el => el.visible).slice(0, 15); // First 15 visible elements
            """)
            logging.info(f"Available clickable elements on login page: {all_elements}")

        except Exception as debug_e:
            logging.warning(f"Could not debug initial login page: {debug_e}")

        # Look for "Dealer, Supplier, Other Login" button and click it
        dealer_button_selectors = [
            "text=Dealer, Supplier, Other Login",
            "button:has-text('Dealer')",
            "a:has-text('Dealer')",
            "[data-testid*='dealer']",
            ".dealer-login",
            "#dealer-login"
        ]

        dealer_clicked = False
        for selector in dealer_button_selectors:
            try:
                element = page.locator(selector)
                if element.count() > 0 and element.first.is_visible():
                    element.first.click()
                    logging.info(f"Clicked dealer login button with selector: {selector}")
                    dealer_clicked = True
                    time.sleep(2)  # Wait for form to appear

                    # Debug: check what happened after clicking dealer button
                    try:
                        current_url_after_click = page.url
                        current_title_after_click = page.title()
                        logging.info(f"After dealer click - URL: {current_url_after_click}, Title: {current_title_after_click}")

                        # Check if we have any input fields now
                        input_count = page.locator("input").count()
                        logging.info(f"Input fields found after dealer click: {input_count}")

                        # Screenshot after dealer click
                        page.screenshot(path=f"/root/www/scraper/invoices/debug_after_dealer_click_{vin}.png")

                    except Exception as debug_e:
                        logging.warning(f"Could not debug after dealer click: {debug_e}")

                    break
            except Exception:
                continue

        if not dealer_clicked:
            logging.warning("Could not find dealer login button")
            # Debug: show available buttons/links
            try:
                available_elements = page.evaluate("""
                    const buttons = Array.from(document.querySelectorAll('button, a, input[type="button"], input[type="submit"]')).map(el => ({
                        tag: el.tagName,
                        text: el.textContent.trim(),
                        id: el.id,
                        class: el.className
                    })).filter(el => el.text.length > 0);
                    return buttons.slice(0, 10);  // First 10 elements
                """)
                logging.info(f"Available clickable elements: {available_elements}")
            except Exception:
                pass
            return False

        # Wait for and fill username field
        try:
            username_selectors = ["#userName", "input[name='username']", "input[type='email']", "input[type='text']"]
            username_field = None
            for selector in username_selectors:
                try:
                    field = page.locator(selector)
                    if field.count() > 0 and field.first.is_visible():
                        username_field = field.first
                        logging.info(f"Found username field: {selector}")
                        break
                except Exception:
                    continue

            if not username_field:
                logging.error("No username field found after clicking dealer login")
                return False

            username_field.fill("m-goins4")
            time.sleep(0.5)

            # Tab to password field and fill it
            page.keyboard.press("Tab")
            time.sleep(0.2)
            page.keyboard.type(PASSWORD, delay=50)
            time.sleep(0.5)

            # Hit Enter to submit (as user described)
            logging.info("Submitting login with Enter key")
            page.keyboard.press("Enter")

        except Exception as e:
            logging.error(f"Form filling failed: {e}")
            return False

        # Wait for login to complete - user says they see PDF after hitting enter
        logging.info("Waiting for login completion...")
        page.wait_for_load_state("networkidle", timeout=30000)

        # Check if we're back on the invoice page with PDF content
        current_url = page.url
        if "GetInvoice" in current_url and "fordvisions.dealerconnection.com" in current_url:
            logging.info("Login successful - back on invoice page")
            return True
        else:
            logging.warning(f"Login may have failed - current URL: {current_url}")
            # Check if we're still on login page
            if is_login_page(page):
                logging.error("Still on login page after submission")
                return False
            # Otherwise, assume success (might be redirecting)
            logging.info("Assuming login successful (not on login page)")
            return True

    except Exception as e:
        logging.error(f"Login error: {e}")
        return False


def download_invoice(page: Page, vin: str, dest: Path, logged_in: bool) -> tuple[bool, bool]:
    """Download a single invoice PDF for the given VIN."""
    if dest.exists():
        return True, logged_in

    url = INVOICE_URL.format(vin=vin)
    logging.info(f"Requesting invoice for VIN {vin}")

    try:
        # Navigate to invoice URL with retry logic
        max_retries = 3
        for attempt in range(max_retries):
            try:
                logging.info(f"Navigating to {url}... (attempt {attempt+1}/{max_retries})")
                page.goto(url, wait_until="domcontentloaded", timeout=30000)
                break  # Success, exit retry loop
            except Exception as nav_e:
                if attempt == max_retries - 1:
                    raise nav_e  # Re-raise on last attempt
                logging.warning(f"Navigation attempt {attempt+1} failed: {nav_e}, retrying...")
                time.sleep(2)

        # Handle login if needed
        if is_login_page(page) or not logged_in or not check_session_valid(page):
            logging.info(f"Login required for VIN {vin}")
            if not handle_login(page, vin):
                logging.error(f"Login failed for VIN {vin}")
                return False, False
            logged_in = True
            # After login, navigate back to invoice URL
            page.goto(url, wait_until="networkidle", timeout=8000)

        # Check if we have invoice content
        content = page.content()
        title = page.title()
        current_url = page.url

        logging.debug(f"Page title: {title}")
        logging.debug(f"Current URL: {current_url}")
        logging.debug(f"Content length: {len(content)}")

        # If we're on a login page, something went wrong
        if is_login_page(page):
            logging.error(f"Still on login page after processing VIN {vin}")
            return False, False

        # Check for error indicators
        content_lower = content.lower()
        error_indicators = ["error", "not found", "invalid", "no invoice", "unavailable", "access denied", "forbidden", "home realm discovery"]
        if any(ind in content_lower for ind in error_indicators):
            logging.info(f"No invoice available for VIN {vin} (error page)")
            return False, logged_in

        # More lenient validation - Ford invoices may load differently
        # Basic checks: not on login page, has some content, includes VIN
        has_vin = vin.lower() in content_lower
        has_some_content = len(content) > 5000  # Reasonable minimum
        not_login_page = not is_login_page(page)
        no_error_indicators = not any(ind in content_lower for ind in [
            "access denied", "forbidden", "authentication failed", "login required"
        ])

        logging.debug(f"VIN found: {has_vin}, Content size: {len(content)}, Not login: {not_login_page}, No errors: {no_error_indicators}")

        if has_vin and has_some_content and not_login_page and no_error_indicators:
            # Generate PDF in screen media to capture dynamic content
            page.emulate_media(media="screen")
            page.pdf(path=str(dest), format="Letter", print_background=True)
            if dest.exists() and dest.stat().st_size > 5000:  # Reasonable size threshold
                logging.info(f"Successfully saved invoice for VIN {vin} ({dest.stat().st_size} bytes)")
                return True, logged_in
            else:
                if dest.exists():
                    dest.unlink()
                logging.warning(f"PDF created but too small for VIN {vin}")
                return False, logged_in
        else:
            logging.info(f"No valid invoice content for VIN {vin} (has_vin={has_vin}, size={len(content)}, not_login={not_login_page})")
            return False, logged_in

    except Exception as e:
        logging.error(f"Error downloading invoice for VIN {vin}: {e}")
        return False, logged_in


def main() -> int:
    """Main function - download all invoice PDFs."""
    logging.basicConfig(
        level=logging.DEBUG,
        format="%(asctime)s [%(levelname)s] %(message)s",
    )

    ensure_output_dir(INVOICES_DIR)

    if not DB_PATH.exists():
        logging.error("Database not found at %s", DB_PATH)
        return 1

    # Fetch all vehicles
    with sqlite3.connect(DB_PATH) as conn:
        vehicles = fetch_inventory(conn)

    if not vehicles:
        logging.warning("No vehicles found in database.")
        return 0

    logging.info("Found %d vehicles in database", len(vehicles))
    success_count = 0
    logged_in = False  # Track login state

    # Set up browser with proxy - use single context for session persistence
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        context = browser.new_context(
            proxy=PROXY_CONFIG,
            ignore_https_errors=True,
            user_agent="Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36",
            viewport={"width": 1920, "height": 1080},
        )
        page = context.new_page()

        for idx, vehicle in enumerate(vehicles[:1], start=1):  # Process only first vehicle for testing
            vin = (vehicle.vin or "").strip()
            if not vin:
                continue

            dest = INVOICES_DIR / f"{vin}.pdf"
            logging.info("[%d/%d] Processing VIN %s", idx, len(vehicles), vin)

            try:
                success, logged_in = download_invoice(page, vin, dest, logged_in)
                if success:
                    success_count += 1
                    logging.info("Downloaded invoice for VIN %s", vin)
                else:
                    logging.warning("Failed to download invoice for VIN %s", vin)
            except Exception as e:
                logging.error(f"Unexpected error processing VIN {vin}: {e}")

            # Minimal rate limiting
            time.sleep(0.5)

        browser.close()

    logging.info("Successfully downloaded %d invoice PDFs", success_count)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
