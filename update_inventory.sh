#!/bin/bash
# Update inventory database from Ford dealership website
# Runs scraper then syncs to SQLite database

set -e

SCRIPT_DIR="$(dirname "$(readlink -f "$0")")"
LOG_FILE="$SCRIPT_DIR/update_inventory.log"

log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S %Z')] $1" >> "$LOG_FILE"
}

log "Starting inventory update..."

# Run the scraper
log "Running scraper..."
if python3 "$SCRIPT_DIR/scraper/scrapeNew.py" >> "$LOG_FILE" 2>&1; then
    log "Scraper completed successfully"
else
    log "ERROR: Scraper failed with exit code $?"
    exit 1
fi

# Sync to database
log "Syncing to database..."
if python3 "$SCRIPT_DIR/db/sync_db.py" >> "$LOG_FILE" 2>&1; then
    log "Database sync completed successfully"
else
    log "ERROR: Database sync failed with exit code $?"
    exit 1
fi

# Generate static cache for fast page loads
log "Generating static cache..."
if php "$SCRIPT_DIR/db/generate_cache.php" >> "$LOG_FILE" 2>&1; then
    log "Cache generation completed successfully"
else
    log "WARNING: Cache generation failed (non-fatal)"
fi

log "Inventory update completed successfully"
