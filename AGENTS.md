# AGENTS.md — Project Context for AI Assistants

## Project Purpose

Ford dealership inventory system for **Fairfield Ford** that:
1. Scrapes vehicle inventory from the dealer website
2. Enriches data using LLM-powered window sticker PDF extraction
3. Stores everything in SQLite with a normalized schema
4. Serves a web UI for browsing/filtering inventory

---

## Directory Structure

```
/root/www/
├── scraper/          # Inventory scraper + window sticker processing
│   ├── stickers/     # Downloaded window sticker PDFs (named by VIN)
│   └── invoices/     # Invoice PDFs
├── db/               # SQLite database + sync scripts
│   └── ford_guides/  # Ford product guides + LLM context
│       ├── context/  # LLM context files (template.json, options.json)
│       ├── json/     # Extracted guide data by year
│       └── pdf/      # Ford product guide PDFs
├── html/             # Web frontend (PHP + vanilla JS)
├── var/              # Logs and backups (not in git)
│   ├── logs/         # update.log
│   └── backups/      # Database backups
└── update.py         # Main orchestration script
```

---

## Key Files

| File | Purpose |
|------|---------|
| `scraper/scrapeNew.py` | Fetches inventory JSON from fordfairfield.com API, outputs CSV |
| `scraper/sticker_2_data.py` | LLM extracts structured data from window sticker PDFs |
| `scraper/vin_to_sticker.py` | Downloads window sticker PDFs from Ford's site |
| `db/sync_db.py` | Syncs CSV → SQLite, builds per-model schema from `template.json`, creates indexes |
| `db/generate_cache.php` | Generates pre-built JSON cache with normalized data, facets, and pricing |
| `db/ford_guides/context/template.json` | JSON schema + DB schema — **single source of truth** (tables block drives base/model tables) |
| `db/ford_guides/context/options.json` | Canonical values by model (trims, colors, engines, etc.) |
| `html/index.php` | Main inventory UI (SPA with client-side filtering, infinite scroll) |
| `html/inventory.php` | JSON API endpoint for vehicle data with filtering support |
| `html/vehicle.php` | Legacy redirect to SPA for old vehicle links |
| `update.py` | Orchestrates full pipeline (scrape → sync → stickers → enrich → cache) |

---

## Database Schema (Per-Model Tables)

Uses **per-model table inheritance** — base table + model-specific tables, driven by `template.json` `tables` block:

- **`vehicles`** — Common fields: VIN, stock, year, make, model, trim, paint, pricing, equipment blobs, drivetrain, engine, transmission
- **`f150`** — F-150 specific: truck_body_style, wheelbase, bed_length, towing_capacity, payload_capacity, axle_ratio
- **`super_duty`** — F-250/F-350/F-450/F-550/etc: same as f150 (heavy duty trucks)
- **`ranger`** — Ranger specific: truck fields
- **`maverick`** — Maverick specific: truck fields (compact)
- **`f150_lightning`** — F-150 Lightning: truck fields + battery_capacity, range_estimate
- **`explorer`** — Explorer specific: cargo_volume, third_row_seating, ground_clearance
- **`expedition`** — Expedition specific: SUV fields + towing_capacity
- **`bronco`** — Bronco specific: SUV fields + towing_capacity
- **`bronco_sport`** — Bronco Sport specific: SUV fields
- **`escape`** — Escape specific: SUV fields
- **`mach_e`** — Mustang Mach-E specific: SUV fields + battery_capacity, range_estimate
- **`mustang`** — Mustang specific: horsepower, torque
- **`transit`** — Transit/E-Transit specific: cargo_length, cargo_volume, roof_height, payload_capacity
- **`vehicles_all`** — View that joins base + all model tables

Model table is determined from `model` field using `model_table_map` in template.json (e.g., "F-150" → "f150", "F-250" → "super_duty").

### Database Indexes

The following indexes are automatically created by `sync_db.py` for faster query performance:

- `idx_vehicles_model` — On `model` column
- `idx_vehicles_trim` — On `trim` column
- `idx_vehicles_paint` — On `paint` column (exterior color)
- `idx_vehicles_equipment_group` — On `equipment_group` column
- `idx_vehicles_drivetrain` — On `drivetrain` column
- `idx_vehicles_year` — On `year` column
- `idx_vehicles_model_table` — On `model_table` column (for joins)

---

## Model Table Mapping

| Model Name | Table |
|------------|-------|
| F-150 | `f150` |
| F-150 Lightning | `f150_lightning` |
| F-250, F-350, F-450, F-550, F-600, F-650, F-750 | `super_duty` |
| Ranger | `ranger` |
| Maverick | `maverick` |
| Explorer | `explorer` |
| Expedition | `expedition` |
| Bronco | `bronco` |
| Bronco Sport | `bronco_sport` |
| Escape | `escapes` |
| Mustang Mach-E, Mach-E | `mach_e` |
| Mustang, GT | `mustang` |
| Transit, E-Transit, Transit Connect | `transit` |

---

## Data Pipeline

```
1. scrapeNew.py      → inventoryNew-TIMESTAMP.csv (VIN, stock, photos, links)
2. sync_db.py        → inventory.sqlite (upserts new, deletes gone, creates indexes)
3. vin_to_sticker.py → downloads PDFs to stickers/ for VINs missing data
4. sticker_2_data.py → LLM extracts JSON from PDFs → updates SQLite
5. generate_cache.php → static JSON cache (normalized data, pre-computed facets)
```

Run manually: `python update.py` (use `--help` for options like `--skip-enrich`)

---

## Caching Architecture

The `generate_cache.php` script generates two cache files:

### `inventory_cache.json` (Full Cache)
Contains all vehicle data with full equipment lists, photos, and pricing breakdown.

### `inventory_cache_lite.json` (Lite Cache)
Contains only fields needed for list view cards (~30-50% smaller payload):
- Basic info: vin, stock, year, make, model, trim
- Display: exterior color, engine, drivetrain, body_style
- Pricing: msrp, retail_price
- Images: photo, photo_interior
- Meta: equipment_pkg, method_of_transport

### Cache Features

1. **Pre-normalized data**: Colors, engines, drivetrains, and body styles are normalized during cache generation (not at runtime)
2. **Pre-computed facets**: Filter counts are computed once during cache generation, eliminating expensive GROUP BY queries
3. **Empty value stripping**: Empty strings and null values are stripped to reduce payload size
4. **Centralized pricing extraction**: All pricing logic uses a single `extractPricing()` function

### Cache Fields

Each vehicle in the cache includes:
- `normalized_model`: Pre-computed model category (e.g., "f150", "super-duty", "bronco-sport")
- `exterior`: Normalized color name (e.g., "Oxford White" not "OXFORD WHITE")
- `engine`: Normalized engine name (e.g., "3.5L EcoBoost V6")
- `drive_line`: Normalized drivetrain (e.g., "4x4", "AWD")
- `body_style`: Normalized body style (e.g., "SuperCrew", "Super Cab")
- `pricing_breakdown`: Full pricing data object

---

## LLM Extraction (sticker_2_data.py)

- Uses **OpenRouter API** (default model: `google/gemini-2.5-flash`)
- Env var: `OPENROUTER_API_KEY` (required)
- Context loaded from `db/ford_guides/context/`:
  - `template.json` — Expected output schema with field descriptions
  - `options.json` — Canonical values for normalization (fuzzy-matched)
  - Example PDF+JSON pairs teach the model the mapping

**Important:** Output values are normalized to canonical forms in `options.json`. If the LLM returns "OXFORD WHITE", it gets normalized to "Oxford White".

---

## Conventions

- **VINs** are always uppercase, used as primary keys
- **Pricing** stored as REAL (floats), formatted as currency in frontend
- **Equipment lists** stored as JSON text blobs in `optional` and `standard` columns
- **Model names** should match `options.json` keys exactly: `F-150`, `F-250`, `Bronco Sport`, `Mustang Mach-E`, etc.
- **Body style normalization**: `SuperCrew` not `Supercrew`, `Super Cab` not `SuperCab`
- **Table names** use snake_case: `f150`, `super_duty`, `bronco_sport`, `mach_e`, `f150_lightning`

---

## Common Tasks

### Add a new vehicle model to options.json
Add a new key to `db/ford_guides/context/options.json` with arrays for each attribute. The LLM uses this for extraction guidance and value normalization.

### Add a new database column
1. Add/remove the field in `db/ford_guides/context/template.json` under the correct table in the `tables` block (`vehicles`, `f150`, `super_duty`, etc.). This controls DB ownership.
2. (If the field should be filled from stickers) ensure `sticker_2_data.py` has the alias mapping if names differ.
3. Run `python db/sync_db.py` to add columns. To drop columns removed from the template, run `python db/sync_db.py --prune-columns` (backup DB first; prune rebuilds tables).

### Add a new model table
1. Add the table definition to `template.json` under `tables`
2. Add the model → table mapping to `model_table_map` in `template.json`
3. Run `python db/sync_db.py` to create the new table

### Re-process a specific VIN's sticker
```bash
cd /root/www/scraper
python sticker_2_data.py --process-all  # or delete the VIN's enriched data first
```

### Regenerate cache after database changes
```bash
php /root/www/db/generate_cache.php
```

### Add a new normalization rule
Normalization functions are centralized in `db/generate_cache.php`:
- `normalizePaintColor()` — Color names
- `normalizeEngine()` — Engine descriptions
- `normalizeDrivetrain()` — Drivetrain types
- `normalizeBodyStyle()` — Cab/body styles

After adding a rule, regenerate the cache to apply it to all vehicles.

---

## Migration Runbook (Type Tables → Model Tables)

**This migration replaces the old vehicle-type tables (trucks, suvs, vans, coupes, chassis) with per-model tables (f150, super_duty, explorer, etc.).**

### Pre-migration Checklist

1. **Backup the database:**
   ```bash
   cp /root/www/db/inventory.sqlite /root/www/var/backups/inventory-$(date +%Y%m%d-%H%M%S).sqlite
   ```

2. **Verify template.json has the new schema:**
   - Check `tables` block has model-specific tables (f150, super_duty, etc.)
   - Check `model_table_map` maps model names to table names

### Migration Steps

1. **Run sync_db.py with --migrate flag:**
   ```bash
   cd /root/www/db
   python sync_db.py --migrate
   ```
   This will:
   - Drop old type tables (trucks, suvs, vans, coupes, chassis)
   - Create new model tables (f150, super_duty, etc.)
   - Create database indexes
   - Rebuild the vehicles_all view

2. **Verify schema:**
   ```bash
   sqlite3 /root/www/db/inventory.sqlite ".schema"
   ```
   Confirm new model tables exist and old type tables are gone.

3. **Re-enrich vehicles (optional but recommended):**
   ```bash
   cd /root/www/scraper
   python sticker_2_data.py --process-all
   ```
   This will re-extract and insert data into the correct model tables.

4. **Regenerate cache:**
   ```bash
   php /root/www/db/generate_cache.php
   ```

5. **Verify vehicles_all view:**
   ```bash
   sqlite3 /root/www/db/inventory.sqlite "SELECT COUNT(*) FROM vehicles_all"
   ```

### Rollback

If something goes wrong:
```bash
cp /root/www/var/backups/inventory-TIMESTAMP.sqlite /root/www/db/inventory.sqlite
```
Then revert template.json and sync_db.py to previous versions.

---

## Gotchas

- **Don't** manually edit `inventory.sqlite` — `sync_db.py` may overwrite changes to CSV-derived fields
- **VINs in sticker PDFs** must match filenames (e.g., `1FTFW3L80RFA12345.pdf`)
- **F-150 Lightning** goes to `f150_lightning` table, not `f150`
- **Mustang Mach-E** goes to `mach_e` table (classified as SUV, not coupe)
- The frontend filters are **F-150 specific** — other models have basic filtering only
- `equipment_group` in DB = `preferred_equipment_pkg` in sticker data (aliased)
- The `model_table` column in vehicles table stores which model table contains extended data
- **Cache regeneration**: Always run `generate_cache.php` after database schema changes or adding normalization rules
- **vehicle.php**: This file only redirects to the SPA; all HTML/JS is dead code and has been removed

## Landing the Plane (Session Completion)

**When ending a work session**, you MUST complete ALL steps below. Work is NOT complete until `git push` succeeds.

**MANDATORY WORKFLOW:**

1. **File issues for remaining work** - Create issues for anything that needs follow-up
2. **Run quality gates** (if code changed) - Tests, linters, builds
3. **Update issue status** - Close finished work, update in-progress items
4. **PUSH TO REMOTE** - This is MANDATORY:
   ```bash
   git pull --rebase
   bd sync
   git push
   git status  # MUST show "up to date with origin"
   ```
5. **Clean up** - Clear stashes, prune remote branches
6. **Verify** - All changes committed AND pushed
7. **Hand off** - Provide context for next session

**CRITICAL RULES:**
- Work is NOT complete until `git push` succeeds
- NEVER stop before pushing - that leaves work stranded locally
- NEVER say "ready to push when you are" - YOU must push
- If push fails, resolve and retry until it succeeds
