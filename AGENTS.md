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
├── scraper/          # Inventory scraper (from dealer API)
├── db/               # SQLite database + sync scripts
├── vin_2_data/       # Window sticker processing
│   ├── stickers/     # Downloaded window sticker PDFs (named by VIN)
│   └── invoices/     # Invoice PDFs
├── html/             # Web frontend (PHP + vanilla JS)
├── ford_guides/      # Ford product guides + LLM context
│   ├── context/      # LLM context files (template.json, options.json)
│   ├── json/         # Extracted guide data by year
│   └── pdf/          # Ford product guide PDFs
└── update_inventory.sh  # Main orchestration script
```

---

## Key Files

| File | Purpose |
|------|---------|
| `scraper/scrapeNew.py` | Fetches inventory JSON from fordfairfield.com API, outputs CSV |
| `db/sync_db.py` | Syncs CSV → SQLite, creates CTI schema, handles deletes/updates |
| `vin_2_data/sticker_2_data.py` | LLM extracts structured data from window sticker PDFs |
| `vin_2_data/vin_to_sticker.py` | Downloads window sticker PDFs from Ford's site |
| `ford_guides/context/template.json` | JSON schema for LLM output — **source of truth for fields** |
| `ford_guides/context/options.json` | Canonical values by model (trims, colors, engines, etc.) |
| `html/index.php` | Main inventory UI (client-side filtering, infinite scroll) |
| `update_inventory.sh` | Runs scraper → sync_db → generate_cache |

---

## Database Schema (CTI Pattern)

Uses **Class Table Inheritance** — base table + vehicle-type-specific tables:

- **`vehicles`** — Common fields: VIN, stock, year, make, model, trim, paint, pricing, equipment blobs
- **`trucks`** — Truck-specific: drivetrain, wheelbase, bed_length, towing_capacity, axle_ratio
- **`suvs`** — SUV-specific: cargo_volume, third_row_seating, ground_clearance
- **`vans`** — Van-specific: cargo_length, roof_height
- **`coupes`** — Coupe-specific: horsepower, torque
- **`chassis`** — Chassis-specific: similar to trucks
- **`vehicles_all`** — View that joins base + all child tables

Vehicle type is inferred from model name (see `infer_vehicle_type()` in both `sync_db.py` and `sticker_2_data.py`).

---

## Data Pipeline

```
1. scrapeNew.py      → inventoryNew-TIMESTAMP.csv (VIN, stock, photos, links)
2. sync_db.py        → inventory.sqlite (upserts new, deletes gone, preserves enriched data)
3. vin_to_sticker.py → downloads PDFs to stickers/ for VINs missing data
4. sticker_2_data.py → LLM extracts JSON from PDFs → updates SQLite
5. generate_cache.php → static JSON cache for fast page loads
```

Run manually: `./update_inventory.sh`

---

## LLM Extraction (sticker_2_data.py)

- Uses **OpenRouter API** (default model: `google/gemini-2.5-flash`)
- Env var: `OPENROUTER_API_KEY` (required)
- Context loaded from `ford_guides/context/`:
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

---

## Common Tasks

### Add a new vehicle model to options.json
Add a new key to `ford_guides/context/options.json` with arrays for each attribute. The LLM uses this for extraction guidance and value normalization.

### Add a new database column
1. Add column definition to the appropriate table in `db/sync_db.py` (`init_db()`)
2. Add to `BASE_TABLE_COLUMNS` or `CHILD_TABLE_COLUMNS` in `sticker_2_data.py`
3. Update `template.json` if it should be extracted from stickers

### Re-process a specific VIN's sticker
```bash
cd /root/www/vin_2_data
python sticker_2_data.py --process-all  # or delete the VIN's enriched data first
```

---

## Gotchas

- **Don't** manually edit `inventory.sqlite` — `sync_db.py` may overwrite changes to CSV-derived fields
- **VINs in sticker PDFs** must match filenames (e.g., `1FTFW3L80RFA12345.pdf`)
- **F-150 Lightning** and **Mustang Mach-E** are classified as `truck` and `suv` respectively, not EVs
- The frontend filters are **F-150 specific** — other models have basic filtering only
- `equipment_group` in DB = `preferred_equipment_pkg` in sticker data (aliased)
