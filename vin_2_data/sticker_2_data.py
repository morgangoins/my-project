#!/usr/bin/env python3
"""
Simple CLI client to call a chat model via the OpenRouter API,
using context loaded from the local `context` directory.

Environment variables:
  - OPENROUTER_API_KEY   (required) your OpenRouter API key
  - OPENROUTER_MODEL     (optional) model id, defaults to "grok-4"
  - OPENROUTER_BASE_URL  (optional) override API URL
  - OPENROUTER_CONTEXT_DIR (optional) override context directory
  - OPENROUTER_REFERRER  (optional) HTTP referer header
  - OPENROUTER_APP_NAME  (optional) X-Title header
"""

import argparse
import json
import os
import pathlib
import sqlite3
import sys
import time
import urllib.request
import urllib.error

from functools import lru_cache

try:
    from pypdf import PdfReader
except ImportError:  # pragma: no cover - optional dependency
    PdfReader = None  # type: ignore[assignment]

OPENROUTER_API_KEY = os.getenv("OPENROUTER_API_KEY")
BASE_URL = os.getenv(
    "OPENROUTER_BASE_URL",
    "https://openrouter.ai/api/v1/chat/completions",
)
# Default model; override via OPENROUTER_MODEL.
# You can point this to any OpenRouter-compatible chat model, for example:
#   - "google/gemini-2.5-flash"
#   - "x-ai/grok-4.1"
DEFAULT_MODEL = os.getenv("OPENROUTER_MODEL", "google/gemini-2.5-flash")

# By default, use a `context` directory next to this script
DEFAULT_CONTEXT_DIR = pathlib.Path(__file__).with_name("context")
CONTEXT_DIR = pathlib.Path(
    os.getenv("OPENROUTER_CONTEXT_DIR", str(DEFAULT_CONTEXT_DIR))
)

# Limit how many heavy PDF+JSON example pairs we load into the prompt.
# This keeps prompts smaller and improves latency, especially when you
# have many training examples in the context directory.
try:
    MAX_EXAMPLE_PAIRS = int(os.getenv("OPENROUTER_MAX_EXAMPLE_PAIRS", "3"))
except ValueError:
    MAX_EXAMPLE_PAIRS = 3

# Project locations (adjust if your layout changes)
ROOT_DIR = pathlib.Path("/root/www")
DB_PATH = ROOT_DIR / "db" / "inventory.sqlite"
STICKERS_DIR = ROOT_DIR / "vin_2_data" / "stickers"

# Fields that come from the CSV/scraper and should not be used to decide
# whether sticker-derived data is missing.
NON_STICKER_COLUMNS = {"vin", "stock", "photo_urls", "vehicle_link"}


@lru_cache(maxsize=4)
def load_context(dir_path: pathlib.Path) -> str:
    """
    Recursively load all text files in the given directory and
    concatenate them into a single context string.
    """
    if not dir_path.exists():
        return ""

    parts: list[str] = []

    for path in sorted(dir_path.rglob("*")):
        if not path.is_file():
            continue

        try:
            text = path.read_text(encoding="utf-8")
        except UnicodeDecodeError:
            # Skip non-text/binary files
            continue

        rel = path.relative_to(dir_path)
        parts.append(f"===== {rel} =====\n{text}")

    return "\n\n".join(parts)


def _call_openrouter_chat(
    messages: list[dict[str, str]],
    model: str | None = None,
) -> str:
    """
    Low-level helper to call the configured chat model via OpenRouter.
    """
    if not OPENROUTER_API_KEY:
        raise RuntimeError(
            "OPENROUTER_API_KEY is not set. Please export your OpenRouter API key."
        )

    payload = {
        "model": model or DEFAULT_MODEL,
        "messages": messages,
    }

    data = json.dumps(payload).encode("utf-8")

    headers = {
        "Content-Type": "application/json",
        "Authorization": f"Bearer {OPENROUTER_API_KEY}",
    }

    # Optional but recommended headers for OpenRouter
    referrer = os.getenv("OPENROUTER_REFERRER")
    if referrer:
        headers["HTTP-Referer"] = referrer

    app_name = os.getenv("OPENROUTER_APP_NAME")
    if app_name:
        headers["X-Title"] = app_name

    req = urllib.request.Request(BASE_URL, data=data, headers=headers, method="POST")

    with urllib.request.urlopen(req) as resp:
        body = resp.read().decode("utf-8")

    try:
        obj = json.loads(body)
    except json.JSONDecodeError:
        # If we can't parse JSON, just return the raw body for debugging
        return body

    try:
        return obj["choices"][0]["message"]["content"]
    except (KeyError, IndexError, TypeError):
        # If the response schema is unexpected, return the whole JSON
        return json.dumps(obj, indent=2)


def call_chat_with_context(prompt: str, model: str | None = None) -> str:
    """
    Simple helper to send a single user prompt plus any text context files.

    This is model-agnostic: whichever model ID you configure will be used.
    """
    context = load_context(CONTEXT_DIR)

    messages: list[dict[str, str]] = []
    if context:
        messages.append(
            {
                "role": "system",
                "content": (
                    "You are an AI assistant. Your job is to extract data from a vehicle window sticker, "
                    "using the context files provided. Avoid using all caps. "
                    "Use the context files when they are relevant:\n\n"
                    f"{context}"
                ),
            }
        )

    messages.append({"role": "user", "content": prompt})

    return _call_openrouter_chat(messages, model=model)


# Backwards-compatible alias; external callers can keep using call_grok(...).
def call_grok(prompt: str) -> str:
    return call_chat_with_context(prompt)


def extract_text_from_pdf(pdf_path: pathlib.Path) -> str:
    """
    Extract text from a PDF file using pypdf.

    You can install pypdf with:
      pip install pypdf
    """
    if PdfReader is None:
        raise RuntimeError(
            "The 'pypdf' package is required for PDF extraction. "
            "Install it with: pip install pypdf"
        )

    reader = PdfReader(str(pdf_path))
    parts: list[str] = []
    for page in reader.pages:
        text = page.extract_text() or ""
        parts.append(text)
    return "\n\n".join(parts).strip()


@lru_cache(maxsize=4)
def load_pdf_json_examples(dir_path: pathlib.Path) -> str:
    """
    Load paired PDF + JSON examples from the context directory.

    For each `*.pdf` file, if there is a sibling `*.json` file with the same
    base name, we extract the PDF text and include both the PDF text and the
    JSON content in a clearly-marked block.
    """
    if not dir_path.exists() or PdfReader is None:
        return ""

    pairs: list[str] = []

    for pdf_path in sorted(dir_path.rglob("*.pdf")):
        if not pdf_path.is_file():
            continue

        json_path = pdf_path.with_suffix(".json")
        if not json_path.exists() or not json_path.is_file():
            continue

        try:
            pdf_text = extract_text_from_pdf(pdf_path)
        except Exception:  # noqa: BLE001
            continue

        try:
            json_text = json_path.read_text(encoding="utf-8")
        except UnicodeDecodeError:
            continue

        rel_pdf = pdf_path.relative_to(dir_path)
        rel_json = json_path.relative_to(dir_path)

        pairs.append(
            "===== WINDOW STICKER EXAMPLE =====\n"
            f"PDF FILE: {rel_pdf}\n"
            f"JSON FILE: {rel_json}\n\n"
            "PDF_TEXT_START\n"
            f"{pdf_text}\n"
            "PDF_TEXT_END\n\n"
            "JSON_START\n"
            f"{json_text}\n"
            "JSON_END\n"
        )

        # Avoid loading too many heavy examples into the prompt; this reduces
        # token count and speeds up API calls.
        if len(pairs) >= MAX_EXAMPLE_PAIRS:
            break

    return "\n\n".join(pairs)


def call_model_for_window_sticker(pdf_text: str, model: str | None = None) -> str:
    """
    Ask the configured chat model to convert extracted window sticker text into
    JSON, using the JSON examples in the context directory as a formatting
    reference.
    """

    # General text/JSON context (including your example JSON files)
    context = load_context(CONTEXT_DIR)
    # Paired PDF+JSON examples so the model can see how a sticker is converted
    example_pairs = load_pdf_json_examples(CONTEXT_DIR)

    system_content = [
        "You are a data extraction assistant for vehicle window stickers.",
        "Your job is to convert window sticker content into a JSON object "
        "that can be used to update a database.",
        "",
        "The user has provided a strict JSON schema and example JSON files "
        "in the context directory (see `context/template.json`). "
        "You MUST match the field names, nesting, and types from that "
        "template exactly, including fields such as cylinder_count, "
        "final_assembly_plant, method_of_transport, axle_ratio, and "
        "others. If a value is unknown or not present on the sticker, "
        "set it to null instead of omitting the field.",
        "",
        "Important output requirements:",
        "- Output a single valid JSON object.",
        "- Do NOT wrap it in Markdown.",
        "- Do NOT include any explanatory text before or after the JSON.",
    ]

    if context:
        system_content.append(
            "\nAdditional reference files from the context directory:\n\n" + context
        )

    if example_pairs:
        system_content.append(
            "\nPaired window sticker PDF + JSON examples (learn the mapping from these):\n\n"
            + example_pairs
        )

    messages: list[dict[str, str]] = [
        {
            "role": "system",
            "content": "\n".join(system_content),
        },
        {
            "role": "user",
            "content": (
                "Here is the text extracted from a vehicle window sticker PDF.\n\n"
                "Please analyze it and return ONLY a JSON object that follows the "
                "structure and naming conventions of the example JSONs provided "
                "in the context.\n\n"
                "Extracted window sticker text:\n\n"
                f"{pdf_text}"
            ),
        },
    ]

    return _call_openrouter_chat(messages, model=model)


# Backwards-compatible alias name used in earlier versions.
def call_grok_for_window_sticker(pdf_text: str) -> str:
    return call_model_for_window_sticker(pdf_text)


def _extract_json_from_response(text: str) -> dict | None:
    """
    Try to extract a single JSON object from the model response.

    This is useful if the model accidentally adds extra text around the JSON.
    """
    # Fast path: try to parse whole string
    try:
        return json.loads(text)
    except json.JSONDecodeError:
        pass

    start = text.find("{")
    end = text.rfind("}")
    if start == -1 or end == -1 or end <= start:
        return None

    candidate = text[start : end + 1]
    try:
        return json.loads(candidate)
    except json.JSONDecodeError:
        return None


def _get_vehicle_column_types(conn: sqlite3.Connection) -> dict[str, str]:
    """
    Return a mapping of vehicles table column name -> declared SQLite type.
    """
    cur = conn.cursor()
    cur.execute("PRAGMA table_info(vehicles)")
    return {row[1]: (row[2] or "").upper() for row in cur.fetchall()}


def _sticker_field_names(column_types: dict[str, str]) -> list[str]:
    """
    Return all vehicle columns that should be filled from sticker data,
    excluding core identifiers that come from the CSV.
    """
    return [name for name in column_types if name not in NON_STICKER_COLUMNS]


def _fetch_vins_missing_data(
    conn: sqlite3.Connection,
    missing_fields: list[str],
    column_types: dict[str, str],
) -> set[str]:
    """
    Return VINs whose sticker-derived fields are still missing.

    A VIN is considered missing if *all* specified fields are NULL/empty.
    """
    if not missing_fields:
        return set()

    valid_fields: list[str] = []
    for field in missing_fields:
        if field not in column_types:
            print(
                f"Ignoring unknown field '{field}' in missing-field filter.",
                file=sys.stderr,
            )
            continue
        valid_fields.append(field)

    if not valid_fields:
        return set()

    conditions: list[str] = []
    for field in valid_fields:
        col_type = column_types[field]
        if col_type.startswith("INT") or col_type in {"REAL", "FLOAT", "DOUBLE"}:
            conditions.append(f"{field} IS NULL")
        else:
            conditions.append(f"({field} IS NULL OR TRIM({field}) = '')")

    where_clause = " AND ".join(conditions)
    sql = f"SELECT vin FROM vehicles WHERE {where_clause}"
    cur = conn.cursor()
    cur.execute(sql)
    return {
        row[0].strip().upper()
        for row in cur.fetchall()
        if row[0] and isinstance(row[0], str)
    }


# Known aliases between JSON keys (from template/LLM) and DB column names.
_LABEL_KEY_ALIASES: dict[str, str] = {
    # Equipment blobs
    "optional_equipment": "optional",
    "standard_equipment": "standard",
    # Axle config naming differences
    "rear_axle_configuration": "rear_axle_config",
    # Common synonyms
    "fuel_type": "fuel",
    "cylinders": "cylinder_count",
    "cylinder": "cylinder_count",
    "transmission_kind": "transmission_type",
    "gear_count": "transmission_speeds",
    "gears": "transmission_speeds",
    "assembly_plant": "final_assembly_plant",
    "plant": "final_assembly_plant",
    "transport_method": "method_of_transport",
    "delivery_method": "method_of_transport",
    "dealer_address": "address",
}


def _coerce_for_sqlite(value: object, col_type: str) -> object | None:
    """
    Best-effort coercion of a Python value into something SQLite will accept
    for the given column type.
    """
    if value is None:
        return None

    col_type = col_type.upper()

    # Store booleans as INTEGER 0/1 where appropriate.
    if col_type.startswith("INT"):
        if isinstance(value, bool):
            return 1 if value else 0
        if isinstance(value, (int, float)) and not isinstance(value, bool):
            return int(value)
        if isinstance(value, str):
            try:
                return int(value)
            except ValueError:
                return None
        return None

    if col_type in {"REAL", "FLOAT", "DOUBLE"}:
        # Store numeric-ish values as floats, but be forgiving about formatting
        # coming from the LLM (e.g. "2.3L", "$62,300", "3.73 ratio").
        if isinstance(value, bool):
            return 1.0 if value else 0.0
        if isinstance(value, (int, float)) and not isinstance(value, bool):
            return float(value)
        if isinstance(value, str):
            # Fast path: clean up common decoration then parse.
            cleaned = value.strip()
            # Drop leading currency symbols and trailing unit markers.
            for ch in "$€£":
                if cleaned.startswith(ch):
                    cleaned = cleaned[1:].strip()
            # Remove thousands separators.
            cleaned = cleaned.replace(",", "")
            try:
                return float(cleaned)
            except ValueError:
                # Fallback: extract the first numeric token inside the string,
                # e.g. "2.3L" -> 2.3, "3.73 ratio" -> 3.73.
                import re

                match = re.search(r"[-+]?\d*\.?\d+", cleaned)
                if match:
                    try:
                        return float(match.group(0))
                    except ValueError:
                        return None
                return None
        return None

    # TEXT or anything else: store scalars directly; encode complex values as JSON.
    if isinstance(value, (dict, list)):
        return json.dumps(value, ensure_ascii=False)
    return value


def _labels_to_vehicle_columns(
    labels: dict,
    root: dict | None,
    column_types: dict[str, str],
) -> dict:
    """
    Map the model's JSON (root + labels) into columns of the vehicles table.

    This is template-driven and robust to changes:
      - Any label whose (possibly aliased) name matches a DB column will be
        written.
      - New fields added to `template.json` will be picked up automatically as
        long as you also add matching columns to the `vehicles` table.
    """
    # Flatten root-level metadata (excluding labels) – see template.json.
    flat: dict[str, object] = {}

    if isinstance(root, dict):
        for key, value in root.items():
            if key == "labels":
                continue
            if isinstance(value, dict):
                # e.g., annotation.ts -> annotation_ts, annotation.model -> annotation_model
                for sub_key, sub_val in value.items():
                    flat[f"{key}_{sub_key}"] = sub_val
            else:
                flat[key] = value

    # Now include label fields.
    for key, value in labels.items():
        flat[key] = value

    # Build column mapping using aliases and SQLite type information.
    columns: dict[str, object | None] = {}
    for raw_key, value in flat.items():
        col_name = _LABEL_KEY_ALIASES.get(raw_key, raw_key)
        if col_name not in column_types:
            continue

        coerced = _coerce_for_sqlite(value, column_types[col_name])
        columns[col_name] = coerced

    return columns


def _upsert_vehicle_from_labels(
    conn: sqlite3.Connection,
    vin: str,
    columns: dict,
) -> None:
    """
    Update an existing vehicle row with extracted data, or insert a new row.
    """
    cur = conn.cursor()

    # Do not let the enrichment pipeline overwrite these; they are owned by the
    # scraper/importer.
    protected_cols = NON_STICKER_COLUMNS

    update_columns = {k: v for k, v in columns.items() if k not in protected_cols}

    if update_columns:
        set_clause = ", ".join(f"{col} = :{col}" for col in update_columns)
        params = {**update_columns, "vin": vin}
        cur.execute(f"UPDATE vehicles SET {set_clause} WHERE vin = :vin", params)
    else:
        # Nothing to update, but still check if the row exists.
        cur.execute("UPDATE vehicles SET vin = vin WHERE vin = :vin", {"vin": vin})

    if cur.rowcount == 0:
        # If the VIN isn't in the table yet, insert a new row with empty stock.
        insert_cols = ["vin", "stock", *update_columns.keys()]
        placeholders = [":" + c for c in insert_cols]
        insert_sql = (
            f"INSERT INTO vehicles ({', '.join(insert_cols)}) "
            f"VALUES ({', '.join(placeholders)})"
        )
        insert_params = {"vin": vin, "stock": "", **update_columns}
        cur.execute(insert_sql, insert_params)

    conn.commit()


def process_all_stickers(
    conn: sqlite3.Connection,
    *,
    only_missing: bool = False,
    missing_fields: list[str] | None = None,
    missing_all_sticker_fields: bool = False,
) -> None:
    """
    Walk all PDF window stickers under vin_2_data/stickers and enrich the DB.
    """
    if missing_fields is None:
        missing_fields = ["msrp"]

    if not STICKERS_DIR.exists():
        print(f"No stickers directory found at {STICKERS_DIR}", file=sys.stderr)
        return

    pdf_files = sorted(STICKERS_DIR.rglob("*.pdf"))
    if not pdf_files:
        print(f"No PDF stickers found in {STICKERS_DIR}", file=sys.stderr)
        return

    # Cache column types once per connection so we can adapt to field changes.
    column_types = _get_vehicle_column_types(conn)

    target_vins: set[str] | None = None
    if only_missing:
        if missing_all_sticker_fields:
            missing_fields = _sticker_field_names(column_types)
        # Fall back to default if the computed list is empty for any reason.
        if not missing_fields:
            missing_fields = ["msrp"]

        target_vins = _fetch_vins_missing_data(conn, missing_fields, column_types)
        if not target_vins:
            print(
                "All vehicles already have values for the chosen missing-field check; "
                "nothing to do.",
                file=sys.stderr,
            )
            return

        pdf_files = [
            path for path in pdf_files if path.stem.strip().upper() in target_vins
        ]
        if not pdf_files:
            print(
                "No sticker PDFs found for VINs missing data. "
                "Ensure PDFs exist in the stickers directory.",
                file=sys.stderr,
            )
            return

    for pdf_path in pdf_files:
        overall_start = time.perf_counter()
        vin = pdf_path.stem.upper()
        print(f"Processing {pdf_path} (VIN {vin})...")

        try:
            extract_start = time.perf_counter()
            pdf_text = extract_text_from_pdf(pdf_path)
            extract_end = time.perf_counter()
            if not pdf_text:
                print(f"  Skipping {pdf_path}: no text extracted.", file=sys.stderr)
                continue

            api_start = time.perf_counter()
            raw_response = call_model_for_window_sticker(pdf_text)
            api_end = time.perf_counter()
            data = _extract_json_from_response(raw_response)
            if data is None:
                print(f"  Skipping {pdf_path}: could not parse JSON.", file=sys.stderr)
                continue

            # Some outputs may wrap everything under a 'labels' key like your examples.
            labels = data.get("labels") if isinstance(data, dict) else None
            if not isinstance(labels, dict):
                labels = data if isinstance(data, dict) else {}

            # Prefer VIN from labels if present, otherwise use filename.
            vin_from_labels = labels.get("vin")
            if isinstance(vin_from_labels, str) and vin_from_labels.strip():
                vin = vin_from_labels.strip().upper()

            columns = _labels_to_vehicle_columns(labels, data, column_types)
            _upsert_vehicle_from_labels(conn, vin, columns)

            overall_end = time.perf_counter()
            print(
                "  Timing: "
                f"extract={extract_end - extract_start:.2f}s, "
                f"api={api_end - api_start:.2f}s, "
                f"total={overall_end - overall_start:.2f}s"
            )
        except urllib.error.HTTPError as exc:
            # Show full error response body from OpenRouter for easier debugging
            body = exc.read().decode("utf-8", "replace")
            print(
                f"  HTTP error processing {pdf_path}: {exc}\n"
                f"  Response body: {body}",
                file=sys.stderr,
            )
        except Exception as exc:  # noqa: BLE001
            print(f"  Error processing {pdf_path}: {exc}", file=sys.stderr)


def parse_args(argv: list[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description=(
            "Update the inventory database using window sticker PDFs that have "
            "already been downloaded."
        )
    )
    parser.add_argument(
        "--only-missing",
        action="store_true",
        help=(
            "Process only vehicles whose sticker-derived fields are missing "
            "(defaults to checking msrp)."
        ),
    )
    parser.add_argument(
        "--missing-field",
        action="append",
        dest="missing_fields",
        default=None,
        help=(
            "Database column to use when determining missing sticker data. "
            "Repeat for multiple fields. Defaults to msrp."
        ),
    )
    parser.add_argument(
        "--missing-all-sticker-fields",
        action="store_true",
        help=(
            "Process VINs where all sticker-derived fields are empty, ignoring the "
            "core CSV columns (vin, stock, photo_urls, vehicle_link)."
        ),
    )
    return parser.parse_args(argv)


def main(argv: list[str] | None = None) -> None:
    """
    Enrich the inventory SQLite database using all window sticker PDFs.

    This script:
      - Reads all `*.pdf` files under `/root/www/vin_2_data/stickers`
      - Uses a configurable chat model (via OpenRouter) plus your example
        context to extract data
      - Updates or inserts rows in `/root/www/db/inventory.sqlite`

    Usage:
      python sticker_2_data.py [--only-missing] [--missing-field FIELD ...]
    """
    if argv is None:
        argv = sys.argv[1:]

    args = parse_args(argv)

    missing_fields = args.missing_fields or ["msrp"]

    if not OPENROUTER_API_KEY:
        print("OPENROUTER_API_KEY is not set.", file=sys.stderr)
        sys.exit(1)

    try:
        with sqlite3.connect(DB_PATH) as conn:
            process_all_stickers(
                conn,
                # If the user asked for "all sticker fields missing", implicitly
                # enable the only-missing path.
                only_missing=args.only_missing or args.missing_all_sticker_fields,
                missing_fields=missing_fields,
                missing_all_sticker_fields=args.missing_all_sticker_fields,
            )
    except Exception as exc:  # noqa: BLE001
        print(f"Error updating database: {exc}", file=sys.stderr)
        sys.exit(1)


if __name__ == "__main__":
    main()


