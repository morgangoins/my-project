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
import re

from functools import lru_cache
from difflib import SequenceMatcher

try:
    from pypdf import PdfReader
except ImportError:  # pragma: no cover - optional dependency
    PdfReader = None  # type: ignore[assignment]


def _load_openrouter_env() -> None:
    """
    Load OpenRouter credentials from ~/.openrouter_env if present.
    This mirrors the cron setup so local runs pick up the same key.
    """
    env_path = pathlib.Path("/root/.openrouter_env")
    if not env_path.exists():
        return
    try:
        with env_path.open() as f:
            for line in f:
                line = line.strip()
                if not line or line.startswith("#") or "=" not in line:
                    continue
                key, val = line.split("=", 1)
                key = key.strip()
                val = val.strip()
                # Only set if not already present in the environment
                if key and val and key not in os.environ:
                    os.environ[key] = val
    except Exception:
        # Fail silently; caller will still rely on existing env vars
        pass


_load_openrouter_env()

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

# By default, use the db/ford_guides/context directory for context files
DEFAULT_CONTEXT_DIR = pathlib.Path("/root/www/db/ford_guides/context")
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
STICKERS_DIR = ROOT_DIR / "scraper" / "stickers"
TEMPLATE_PATH = ROOT_DIR / "db" / "ford_guides" / "context" / "template.json"

# Fields that come from the CSV/scraper and should not be used to decide
# whether sticker-derived data is missing.
NON_STICKER_COLUMNS = {"vin", "stock", "photo_urls", "vehicle_link", "pricing", "msrp"}

# Base table columns (from model-based schema)
BASE_TABLE_COLUMNS = {
    "vin", "stock", "photo_urls", "vehicle_link", "model_table",
    "year", "make", "model", "trim", "paint", "interior_color",
    "interior_material", "body_style", "fuel", "msrp", "pricing",
    "optional", "standard", "final_assembly_plant", "method_of_transport",
    "special_order", "equipment_group", "equipment_group_discount", "address",
    "mpg", "engine", "engine_displacement", "cylinder_count", "drivetrain",
    "transmission_type", "transmission_speeds"
}


@lru_cache(maxsize=1)
def _load_template() -> dict:
    """Load template.json and return the parsed dict."""
    try:
        return json.loads(TEMPLATE_PATH.read_text(encoding="utf-8"))
    except Exception:
        return {}


def _get_model_table_map() -> dict[str, str]:
    """
    Get the model name -> table name mapping from template.json.
    Falls back to a built-in mapping if template.json doesn't have one.
    """
    tmpl = _load_template()
    mapping = tmpl.get("model_table_map")
    if isinstance(mapping, dict):
        return mapping
    
    # Fallback built-in mapping
    return {
        "F-150": "f150",
        "F-150 Lightning": "f150_lightning",
        "F-250": "super_duty",
        "F-350": "super_duty",
        "F-450": "super_duty",
        "F-550": "super_duty",
        "F-600": "super_duty",
        "F-650": "super_duty",
        "F-750": "super_duty",
        "Super Duty": "super_duty",
        "Ranger": "ranger",
        "Maverick": "maverick",
        "Explorer": "explorer",
        "Expedition": "expedition",
        "Bronco": "bronco",
        "Bronco Sport": "bronco_sport",
        "Escape": "escape",
        "Mustang Mach-E": "mach_e",
        "Mach-E": "mach_e",
        "Mustang": "mustang",
        "GT": "mustang",
        "Transit": "transit",
        "E-Transit": "transit",
        "Transit Connect": "transit",
    }


def _get_model_table_columns() -> dict[str, set[str]]:
    """
    Get model table columns from template.json.
    Returns a dict mapping table name -> set of column names.
    """
    tmpl = _load_template()
    tables = tmpl.get("tables", {})
    
    result: dict[str, set[str]] = {}
    for table_name, fields in tables.items():
        if table_name == "vehicles":
            continue  # Skip base table
        if isinstance(fields, dict):
            # Apply alias mapping to column names
            result[table_name] = {_apply_alias(k) for k in fields.keys()}
    
    return result


def _apply_alias(key: str) -> str:
    """Map template key to DB column name where they intentionally differ."""
    aliases = {
        "optional_equipment": "optional",
        "standard_equipment": "standard",
        "rear_axle_configuration": "rear_axle_config",
        "preferred_equipment_pkg": "equipment_group",
        "preferred_equipment_pkg_cost": "equipment_group_discount",
    }
    return aliases.get(key, key)


def infer_model_table(model: str) -> str:
    """Determine model table name from model name."""
    if not model:
        return "unknown"
    
    model_normalized = model.strip()
    model_table_map = _get_model_table_map()
    
    # Check exact matches first
    if model_normalized in model_table_map:
        return model_table_map[model_normalized]
    
    # Check prefix matches (longer prefixes first)
    for key in sorted(model_table_map.keys(), key=len, reverse=True):
        if model_normalized.startswith(key):
            return model_table_map[key]
    
    # Check if model contains keywords
    model_lower = model_normalized.lower()
    
    # Lightning check first (before F-150 check)
    if "lightning" in model_lower:
        return "f150_lightning"
    
    # Mach-E check (before Mustang check)
    if "mach-e" in model_lower or "mach e" in model_lower:
        return "mach_e"
    
    # Truck patterns
    if any(t in model_lower for t in ["f-250", "f-350", "f-450", "f-550", "f-650", "f-750"]):
        return "super_duty"
    if "f-150" in model_lower:
        return "f150"
    if "ranger" in model_lower:
        return "ranger"
    if "maverick" in model_lower:
        return "maverick"
    
    # SUV patterns
    if "bronco sport" in model_lower:
        return "bronco_sport"
    if "bronco" in model_lower:
        return "bronco"
    if "explorer" in model_lower:
        return "explorer"
    if "expedition" in model_lower:
        return "expedition"
    if "escape" in model_lower:
        return "escapes"
    
    # Van patterns
    if "transit" in model_lower:
        return "transit"
    
    # Coupe/sports car patterns
    if "mustang" in model_lower or model_lower == "gt":
        return "mustang"
    
    return "unknown"


# Keep old function for backwards compatibility
def infer_vehicle_type(model: str) -> str:
    """Determine vehicle type from model name (deprecated, use infer_model_table)."""
    table = infer_model_table(model)
    # Map model tables back to vehicle types for legacy compatibility
    type_map = {
        "f150": "truck", "super_duty": "truck", "ranger": "truck",
        "maverick": "truck", "f150_lightning": "truck",
        "explorer": "suv", "expedition": "suv", "bronco": "suv",
        "bronco_sport": "suv", "escapes": "suv", "mach_e": "suv",
        "mustang": "coupe", "transit": "van",
    }
    return type_map.get(table, "unknown")


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

    # Also load examples from the examples directory
    examples_dir = dir_path.parent / "examples"
    if examples_dir.exists():
        for txt_path in sorted(examples_dir.glob("*.txt")):
            json_path = txt_path.with_suffix(".json")
            if json_path.exists():
                try:
                    txt_text = txt_path.read_text(encoding="utf-8")
                except UnicodeDecodeError:
                    continue

                try:
                    json_text = json_path.read_text(encoding="utf-8")
                except UnicodeDecodeError:
                    continue

                rel_txt = txt_path.relative_to(dir_path.parent)
                rel_json = json_path.relative_to(dir_path.parent)

                pairs.append(
                    "===== WINDOW STICKER EXAMPLE =====\n"
                    f"TEXT FILE: {rel_txt}\n"
                    f"JSON FILE: {rel_json}\n\n"
                    "PDF_TEXT_START\n"
                    f"{txt_text}\n"
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
        "CRITICAL - Engine Extraction Rules:",
        "- For F-150 PowerBoost hybrids: Look for 'POWERBOOST', 'FULL HYBRID', 'HYBRID ELEC' in the text - extract as '3.5L V6 PowerBoost Hybrid'",
        "- For F-150 3.5L EcoBoost (non-hybrid): Look for '3.5L' with 'V6' or 'V-6' but NO PowerBoost/hybrid indicators - extract as '3.5L V6 EcoBoost'",
        "- For F-150 3.5L High Output: Look for '3.5L' with 'HIGH OUTPUT' or 'RAPTOR' - extract as '3.5L EcoBoost High Output'",
        "- For F-150 2.7L: Extract as '2.7L V6 EcoBoost'",
        "- For F-150 5.0L: Extract as '5.0L V8'",
        "- For F-150 5.2L Supercharged: Extract as '5.2L Supercharged V8'",
        "- For F-150 Lightning: Look for 'DUAL EMOTOR' - extract as 'Dual eMotor'",
        "- For Super Duty High Output: Look for '6.7L' and 'HIGH OUTPUT' in the same engine description line - extract as '6.7L High Output Power Stroke V8'",
        "- For Super Duty 6.7L Power Stroke: Look for '6.7L POWER STROKE' - extract as '6.7L Power Stroke V8'",
        "- For Super Duty 6.8L: Extract as '6.8L V8'",
        "- For Super Duty 7.3L: Extract as '7.3L V8'",
        "- Use the canonical names from options.json exactly - do NOT add extra words like 'Engine' or 'Technology'",
        "",
        "CRITICAL - Consistent Capitalization Rules:",
        "- Use Title Case for most string fields (e.g., 'Cloth' not 'CLOTH', 'Oxford White' not 'OXFORD WHITE')",
        "- For interior_material: Use exactly 'Cloth', 'Vinyl', 'Leather', 'ActiveX', 'Leather-Trim', 'Leather-Trimmed', 'Premium-Trimmed', or 'Marine Grade Vinyl'",
        "- For fuel: Use exactly 'Gas', 'Diesel', 'Hybrid', 'Electric', or 'Plug-In Hybrid' (not 'Gasoline')",
        "- For drivetrain: Use exactly '4x2', '4x4', 'RWD', 'AWD', '4WD', or 'FWD'",
        "- For body_style: Use exactly 'Truck', 'SUV', 'Van', 'Coupe', or 'Convertible'",
        "- For rear_axle_config: Use exactly 'SRW' or 'DRW' (not spelled out)",
        "- For transmission_type: Use exactly 'Automatic', 'Manual', 'CVT', or 'eCVT'",
        "- NEVER use ALL CAPS for any field values, even if the sticker shows them that way",
        "- Refer to options.json for canonical values for trim, paint, interior_color, etc.",
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
    # Equipment package aliases
    "preferred_equipment_pkg": "equipment_group",
    "preferred_equipment_pkg_cost": "equipment_group_discount",
}

# Fields that should be normalized against options.json canonical values
_NORMALIZABLE_FIELDS = {
    "trim", "paint", "interior_color", "interior_material", "drivetrain",
    "body_style", "truck_body_style", "rear_axle_config", "engine",
    "engine_displacement", "fuel", "axle_ratio_type", "transmission_type",
    "bed_length",
}


def _derive_truck_fields_from_pdf_text(pdf_text: str) -> dict[str, object]:
    """
    Deterministically extract truck configuration fields from sticker text.

    Why this exists:
    - The towing guide lookup requires wheelbase/bed length/cab style.
    - These fields are present in many sticker PDFs as plain text (e.g. '145\" WHEELBASE',
      'PLATINUM 160\" WB STYLESIDE').
    - Relying on the LLM for these is unnecessary and sometimes inconsistent.
    """
    text = pdf_text or ""
    upper = text.upper()

    # Model hints (used only for wheelbase canonicalization / bed inference)
    model: str | None = None
    if "F-150" in upper or re.search(r"\bF150\b", upper):
        model = "F-150"
    elif re.search(r"\bF[- ]?250\b", upper):
        model = "F-250"
    elif re.search(r"\bF[- ]?350\b", upper):
        model = "F-350"
    elif re.search(r"\bF[- ]?450\b", upper):
        model = "F-450"
    elif "RANGER" in upper:
        model = "Ranger"
    elif "MAVERICK" in upper:
        model = "Maverick"

    # Cab style: normalize to our canonical values
    cab: str | None = None
    if "REGULAR CAB" in upper:
        cab = "Regular Cab"
    elif "SUPERCAB" in upper or "SUPER CAB" in upper:
        cab = "Super Cab"
    elif "SUPERCREW" in upper or "SUPER CREW" in upper:
        cab = "SuperCrew"
    elif "CREW CAB" in upper:
        cab = "Crew Cab"

    # Rear axle config (Super Duty): SRW/DRW often appears in the description line
    rear_axle_config: str | None = None
    if re.search(r"\bDRW\b", upper):
        rear_axle_config = "DRW"
    elif re.search(r"\bSRW\b", upper):
        rear_axle_config = "SRW"

    # Wheelbase: examples include '145\" WHEELBASE' or '160\" WB'
    wheelbase_in: float | None = None
    m = re.search(r"\b(\d{3}(?:\.\d)?)\s*\"?\s*(WHEELBASE|WB)\b", upper)
    if m:
        try:
            wheelbase_in = float(m.group(1))
        except ValueError:
            wheelbase_in = None

    # Canonicalize wheelbase for towing-guide matching (uses decimals like 145.4, 141.5, etc.)
    def _canonicalize_wb(model_name: str | None, wb: float | None) -> float | None:
        if wb is None:
            return None
        # Known selector wheelbases from 2025 guide (rounded on stickers)
        if model_name == "F-150":
            candidates = [122.8, 141.5, 145.4, 157.2]
        elif model_name in {"F-250", "F-350", "F-450"}:
            candidates = [141.6, 159.8, 164.2, 176.0]
        else:
            return wb
        best = min(candidates, key=lambda c: abs(c - wb))
        return best if abs(best - wb) <= 1.2 else wb

    wheelbase = _canonicalize_wb(model, wheelbase_in)

    # Bed length inference (feet) for selector lookup.
    bed_length: float | None = None
    if model == "F-150" and cab and wheelbase:
        if cab == "Regular Cab":
            bed_length = 6.5 if abs(wheelbase - 122.8) < 1.0 else 8.0 if abs(wheelbase - 141.5) < 1.0 else None
        elif cab == "Super Cab":
            bed_length = 6.5 if abs(wheelbase - 145.4) < 1.0 else None
        elif cab == "SuperCrew":
            bed_length = 5.5 if abs(wheelbase - 145.4) < 1.0 else 6.5 if abs(wheelbase - 157.2) < 1.0 else None
    elif model in {"F-250", "F-350", "F-450"} and cab and wheelbase:
        # Based on 2025 towing guide headers for Super Duty pickups:
        # - Regular Cab: 141.6 WB = 8' box
        # - SuperCab: 164.2 WB = 8' box
        # - Crew Cab: 159.8 WB = 6-3/4' box; 176.0 WB = 8' box
        if cab == "Regular Cab" and abs(wheelbase - 141.6) < 1.0:
            bed_length = 8.0
        elif cab == "Super Cab" and abs(wheelbase - 164.2) < 1.0:
            bed_length = 8.0
        elif cab == "Crew Cab":
            if abs(wheelbase - 159.8) < 1.0:
                bed_length = 6.75
            elif abs(wheelbase - 176.0) < 1.0:
                bed_length = 8.0

    out: dict[str, object] = {}
    if cab:
        out["truck_body_style"] = cab
    if rear_axle_config:
        out["rear_axle_config"] = rear_axle_config
    if wheelbase is not None:
        out["wheelbase"] = wheelbase
    if bed_length is not None:
        out["bed_length"] = bed_length
    return out


_SUPER_DUTY_MODEL_KEYWORDS = (
    "F-250",
    "F-350",
    "F-450",
    "F-550",
    "F-600",
    "F-650",
    "F-750",
    "SUPER DUTY",
)

_F150_MODEL_KEYWORDS = (
    "F-150",
    "F150",
)

_HIGH_OUTPUT_PATTERN = re.compile(r"6\.7L?\s+HI(?:GH)?\s*OUTPUT\s+POWER\s+STROKE", re.IGNORECASE)

# Pattern for F-150 PowerBoost hybrid detection
_POWERBOOST_PATTERN = re.compile(r"\bPOWERBOOST\b|\bFULL\s+HYBRID\b|\bHYBRID\s+ELEC\b|\b3\.5L.*HYBRID\b", re.IGNORECASE)


def _is_super_duty_model(model: str | None) -> bool:
    if not model:
        return False
    upper = model.strip().upper()
    return any(keyword in upper for keyword in _SUPER_DUTY_MODEL_KEYWORDS)


def _is_f150_model(model: str | None) -> bool:
    if not model:
        return False
    upper = model.strip().upper()
    return any(keyword in upper for keyword in _F150_MODEL_KEYWORDS)


def _detect_high_output_engine(pdf_text: str, current_engine: str | None, model: str | None, trim: str | None = None) -> str | None:
    if not _is_super_duty_model(model):
        return None
    if current_engine and "HIGH OUTPUT" in current_engine.upper():
        return None

    # Only mark as high output if the window sticker explicitly contains high output text
    if _HIGH_OUTPUT_PATTERN.search(pdf_text):
        return "6.7L High Output Power Stroke V8"

    return None


def _detect_powerboost_engine(pdf_text: str, current_engine: str | None, model: str | None, trim: str | None = None) -> str | None:
    if not _is_f150_model(model):
        return None
    if current_engine and "POWERBOOST" in current_engine.upper():
        return None

    # Only mark as PowerBoost if the window sticker explicitly contains PowerBoost indicators
    if _POWERBOOST_PATTERN.search(pdf_text):
        return "3.5L V6 PowerBoost Hybrid"

    return None


@lru_cache(maxsize=1)
def _load_options_json() -> dict:
    """Load the canonical options from options.json."""
    options_path = CONTEXT_DIR / "options.json"
    if not options_path.exists():
        return {}
    try:
        return json.loads(options_path.read_text(encoding="utf-8"))
    except (json.JSONDecodeError, UnicodeDecodeError):
        return {}


def _get_canonical_values(field: str, model: str | None = None) -> list[str]:
    """
    Get the list of canonical values for a field.
    If model is provided, use model-specific values; otherwise merge all models.
    """
    options = _load_options_json()
    if not options:
        return []

    # Map DB column names to options.json field names
    field_map = {
        "rear_axle_config": "rear_axle_config",
        "equipment_group": "preferred_equipment_pkg",
    }
    options_field = field_map.get(field, field)

    values: set[str] = set()

    # Handle year -> model -> field structure
    def extract_values_from_year_data(year_data: dict) -> None:
        if model and model in year_data:
            model_opts = year_data[model].get(options_field, [])
            for v in model_opts:
                if v is not None and isinstance(v, str):
                    values.add(v)
        else:
            # Merge values from all models in this year
            for model_data in year_data.values():
                if isinstance(model_data, dict):
                    for v in model_data.get(options_field, []):
                        if v is not None and isinstance(v, str):
                            values.add(v)

    # Check each year in the options
    for year_key, year_data in options.items():
        if isinstance(year_data, dict):
            extract_values_from_year_data(year_data)

    return list(values)


def _fuzzy_match(value: str, candidates: list[str], threshold: float = 0.7) -> str | None:
    """
    Find the best fuzzy match for a value among candidates.
    Returns the canonical value if match ratio >= threshold, else None.
    """
    if not value or not candidates:
        return None

    value_lower = value.lower().strip()

    # Remove noise words that might confuse matching
    noise_words = ["engine", "liter", "l"]
    clean_value = value_lower
    for word in noise_words:
        clean_value = clean_value.replace(f" {word}", "").strip()

    # First try exact case-insensitive match (original value)
    for candidate in candidates:
        if candidate.lower() == value_lower:
            return candidate

    # Try exact match with cleaned value
    for candidate in candidates:
        if candidate.lower() == clean_value:
            return candidate

    # Try matching after stripping common prefixes/suffixes
    # e.g., "STX CLOTH" should match "Cloth", "Ltr-Trimmed" should match "Leather"
    value_words = value_lower.split()
    for candidate in candidates:
        cand_lower = candidate.lower()
        # Check if candidate is in value as a word
        if cand_lower in value_words:
            return candidate
        # Check if value contains the candidate
        if cand_lower in value_lower:
            return candidate

    # Handle common abbreviations and variations
    abbreviation_map = {
        "ltr": "leather",
        "vnyl": "vinyl",
        "actx": "activex",
        "clth": "cloth",
        "hybrid engine": "hybrid",
    }
    for abbr, full in abbreviation_map.items():
        if abbr in value_lower:
            for candidate in candidates:
                if candidate.lower() == full:
                    return candidate

    # Try fuzzy matching
    best_match = None
    best_ratio = 0.0

    for candidate in candidates:
        # Check both original and cleaned value
        ratio_orig = SequenceMatcher(None, value_lower, candidate.lower()).ratio()
        ratio_clean = SequenceMatcher(None, clean_value, candidate.lower()).ratio()
        
        ratio = max(ratio_orig, ratio_clean)

        if ratio > best_ratio and ratio >= threshold:
            best_ratio = ratio
            best_match = candidate

    return best_match


def _normalize_value(field: str, value: object, model: str | None = None) -> object:
    """
    Normalize a field value to its canonical form using options.json.
    Returns the original value if no match is found.
    """
    if value is None or not isinstance(value, str):
        return value

    value_str = value.strip()
    if not value_str:
        return value

    candidates = _get_canonical_values(field, model)
    if not candidates:
        return value

    matched = _fuzzy_match(value_str, candidates)
    return matched if matched else value


def _normalize_extracted_data(data: dict, model: str | None = None) -> dict:
    """
    Normalize all applicable fields in extracted data to canonical forms.
    This ensures consistent capitalization and naming across extractions.
    """
    if not isinstance(data, dict):
        return data

    # Extract model from data if not provided
    if not model:
        model = data.get("model")
        if not model:
            labels = data.get("labels", {})
            if isinstance(labels, dict):
                model = labels.get("model")

    normalized = {}
    for key, value in data.items():
        if key == "labels" and isinstance(value, dict):
            # Recursively normalize labels
            normalized[key] = _normalize_extracted_data(value, model)
        elif key in _NORMALIZABLE_FIELDS:
            normalized[key] = _normalize_value(key, value, model)
        else:
            normalized[key] = value

    return normalized


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
                # Flatten nested dicts: e.g., {"mpg": {"city": 20}} -> mpg_city
                for sub_key, sub_val in value.items():
                    flat[f"{key}_{sub_key}"] = sub_val
            else:
                flat[key] = value

    # Now include label fields.
    for key, value in labels.items():
        flat[key] = value

    # Extract paint color from optional equipment if not already present
    # Window stickers often list the actual paint color in optional equipment
    if "optional_equipment" in flat and not flat.get("paint"):
        optional_equip = flat["optional_equipment"]
        if isinstance(optional_equip, list):
            for item in optional_equip:
                if isinstance(item, dict) and "description" in item:
                    desc = item["description"].upper()
                    # Look for common paint color patterns
                    if ("METALLIC" in desc or "TRI-COAT" in desc or "PEARL" in desc or
                        desc.endswith("WHITE") or desc.endswith("BLACK") or
                        desc.endswith("BLUE") or desc.endswith("RED") or
                        desc.endswith("GRAY") or desc.endswith("SILVER")):
                        # This looks like a paint color, use it
                        flat["paint"] = item["description"]
                        break

    # Build column mapping using aliases and SQLite type information.
    columns: dict[str, object | None] = {}
    for raw_key, value in flat.items():
        col_name = _LABEL_KEY_ALIASES.get(raw_key, raw_key)
        if col_name not in column_types:
            continue

        coerced = _coerce_for_sqlite(value, column_types[col_name])
        columns[col_name] = coerced

    # Group pricing fields into a nested JSON object
    pricing_fields = ["base_price", "total_options", "total_vehicle", "destination_delivery", "discount"]
    pricing_data = {}
    for field in pricing_fields:
        if field in columns and columns[field] is not None:
            pricing_data[field] = columns[field]
            del columns[field]  # Remove from top level

    if pricing_data:
        columns["pricing"] = json.dumps(pricing_data, ensure_ascii=False)

    return columns


def _get_table_column_types(conn: sqlite3.Connection, table: str) -> dict[str, str]:
    """Return a mapping of table column name -> declared SQLite type."""
    cur = conn.cursor()
    try:
        cur.execute(f"PRAGMA table_info({table})")
        return {row[1]: (row[2] or "").upper() for row in cur.fetchall()}
    except sqlite3.OperationalError:
        return {}


def _upsert_vehicle_base(conn: sqlite3.Connection, vin: str, columns: dict) -> None:
    """Update base vehicles table with extracted data."""
    cur = conn.cursor()
    protected_cols = NON_STICKER_COLUMNS
    # Only update columns that belong to base table
    update_columns = {
        k: v for k, v in columns.items()
        if k not in protected_cols and k in BASE_TABLE_COLUMNS
    }
    if update_columns:
        set_clause = ", ".join(f"{col} = :{col}" for col in update_columns)
        params = {**update_columns, "vin": vin}
        cur.execute(f"UPDATE vehicles SET {set_clause} WHERE vin = :vin", params)
    else:
        cur.execute("UPDATE vehicles SET vin = vin WHERE vin = :vin", {"vin": vin})
    if cur.rowcount == 0:
        insert_cols = ["vin", "stock", *update_columns.keys()]
        placeholders = [":" + c for c in insert_cols]
        cur.execute(
            f"INSERT INTO vehicles ({', '.join(insert_cols)}) VALUES ({', '.join(placeholders)})",
            {"vin": vin, "stock": "", **update_columns}
        )
    conn.commit()


def _upsert_model_details(conn: sqlite3.Connection, vin: str, model_table: str, columns: dict) -> None:
    """Insert or update the appropriate model table based on model_table name."""
    model_table_columns = _get_model_table_columns()
    
    if model_table not in model_table_columns:
        return
    
    valid_columns = model_table_columns[model_table]
    child_types = _get_table_column_types(conn, model_table)
    
    if not child_types:
        # Table doesn't exist yet
        return
    
    # Filter and coerce columns for model table
    update_columns = {}
    for k, v in columns.items():
        if k in valid_columns and k in child_types:
            update_columns[k] = _coerce_for_sqlite(v, child_types[k])
    
    if not update_columns:
        return
    
    cur = conn.cursor()
    cur.execute(f"SELECT vin FROM {model_table} WHERE vin = ?", (vin,))
    exists = cur.fetchone() is not None
    
    if exists:
        set_clause = ", ".join(f"{col} = :{col}" for col in update_columns)
        params = {**update_columns, "vin": vin}
        cur.execute(f"UPDATE {model_table} SET {set_clause} WHERE vin = :vin", params)
    else:
        insert_cols = ["vin", *update_columns.keys()]
        placeholders = [":" + c for c in insert_cols]
        cur.execute(
            f"INSERT INTO {model_table} ({', '.join(insert_cols)}) VALUES ({', '.join(placeholders)})",
            {"vin": vin, **update_columns}
        )
    conn.commit()


def _upsert_vehicle_from_labels(
    conn: sqlite3.Connection,
    vin: str,
    columns: dict,
) -> None:
    """
    Update an existing vehicle row with extracted data, or insert a new row.
    Also updates the appropriate model table based on model_table (per-model schema).
    """
    # Determine model_table from model if not already set
    model_table = columns.get("model_table")
    model = columns.get("model")
    if not model_table and model:
        model_table = infer_model_table(str(model))
        columns["model_table"] = model_table

    # Update base table
    _upsert_vehicle_base(conn, vin, columns)

    # Update model table if we know the model
    if model_table and model_table != "unknown":
        _upsert_model_details(conn, vin, model_table, columns)


def process_all_stickers(
    conn: sqlite3.Connection,
    *,
    only_missing: bool = False,
    missing_fields: list[str] | None = None,
    missing_all_sticker_fields: bool = False,
) -> None:
    """
    Walk all PDF window stickers under scraper/stickers and enrich the DB.
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

            # Normalize extracted data to canonical forms (consistent capitalization)
            data = _normalize_extracted_data(data)

            # Some outputs may wrap everything under a 'labels' key like your examples.
            labels = data.get("labels") if isinstance(data, dict) else None
            if not isinstance(labels, dict):
                labels = data if isinstance(data, dict) else {}

            # Prefer VIN from labels if present, otherwise use filename.
            vin_from_labels = labels.get("vin")
            if isinstance(vin_from_labels, str) and vin_from_labels.strip():
                vin = vin_from_labels.strip().upper()

            columns = _labels_to_vehicle_columns(labels, data, column_types)
            model_hint = columns.get("model") or (data.get("model") if isinstance(data, dict) else None)
            high_output_engine = _detect_high_output_engine(
                pdf_text,
                columns.get("engine"),
                model_hint,
                columns.get("trim"),
            )
            if high_output_engine:
                columns["engine"] = high_output_engine
                print(f"  Detected High Output engine for VIN {vin} via sticker text.")

            # Detect F-150 PowerBoost hybrid engines
            powerboost_engine = _detect_powerboost_engine(
                pdf_text,
                columns.get("engine"),
                model_hint,
                columns.get("trim"),
            )
            if powerboost_engine:
                columns["engine"] = powerboost_engine
                print(f"  Detected PowerBoost hybrid engine for VIN {vin} via sticker text.")
            # Deterministic enrichment for truck config fields needed for towing lookup.
            # Fill only if missing in the extracted/normalized output.
            derived = _derive_truck_fields_from_pdf_text(pdf_text)
            for k, v in derived.items():
                if k not in columns or columns.get(k) in (None, "", 0):
                    columns[k] = v
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
            "already been downloaded. By default, processes only vehicles missing "
            "a year (i.e., new VINs just synced)."
        )
    )
    parser.add_argument(
        "--process-all",
        action="store_true",
        help=(
            "Process all sticker PDFs, not just those missing data. "
            "Overrides the default behavior of only processing VINs with "
            "empty sticker-derived fields."
        ),
    )
    parser.add_argument(
        "--only-missing",
        action="store_true",
        help=(
            "Process only vehicles whose sticker-derived fields are missing "
            "(defaults to checking msrp). Use --missing-field to specify which fields."
        ),
    )
    parser.add_argument(
        "--missing-field",
        action="append",
        dest="missing_fields",
        default=None,
        help=(
            "Database column to use when determining missing sticker data. "
            "Repeat for multiple fields. Defaults to msrp. Only used with --only-missing."
        ),
    )
    return parser.parse_args(argv)


def main(argv: list[str] | None = None) -> None:
    """
    Enrich the inventory SQLite database using all window sticker PDFs.

    This script:
      - Reads all `*.pdf` files under `/root/www/scraper/stickers`
      - Uses a configurable chat model (via OpenRouter) plus your example
        context to extract data
      - Updates or inserts rows in `/root/www/db/inventory.sqlite`

    Usage:
      python sticker_2_data.py [--process-all] [--only-missing] [--missing-field FIELD ...]

    Default: only process rows missing a year (newly synced VINs). Use
    --process-all to process every sticker PDF. Use --only-missing/--missing-field
    to target other missing columns.
    """
    if argv is None:
        argv = sys.argv[1:]

    args = parse_args(argv)

    # By default, treat vehicles with missing year as "not yet enriched".
    missing_fields = args.missing_fields or ["year"]

    if not OPENROUTER_API_KEY:
        print("OPENROUTER_API_KEY is not set.", file=sys.stderr)
        sys.exit(1)

    # Default behavior: only process VINs missing the chosen fields (year by default).
    # --process-all overrides to process everything. If both flags are supplied,
    # process-all wins.
    if args.process_all:
        only_missing = False
    else:
        only_missing = True
    if args.only_missing and not args.process_all:
        only_missing = True
    missing_all_sticker_fields = False

    try:
        with sqlite3.connect(DB_PATH) as conn:
            process_all_stickers(
                conn,
                only_missing=only_missing,
                missing_fields=missing_fields,
                missing_all_sticker_fields=missing_all_sticker_fields,
            )
    except Exception as exc:  # noqa: BLE001
        print(f"Error updating database: {exc}", file=sys.stderr)
        sys.exit(1)


def fix_powerboost_entries():
    """
    Fix existing F-150 Powerboost entries by scanning PDFs for Powerboost patterns
    without using expensive LLM calls.
    """
    import sqlite3

    print("Fixing existing F-150 Powerboost entries...")

    with sqlite3.connect(DB_PATH) as conn:
        cur = conn.cursor()

        # Find F-150 vehicles with 3.5L engines that might be Powerboost candidates
        cur.execute("""
            SELECT vin, model, engine
            FROM vehicles
            WHERE model LIKE '%F-150%'
            AND (engine LIKE '%3.5L%' OR engine IS NULL OR engine = '')
            AND engine NOT LIKE '%PowerBoost%'
        """)

        candidates = cur.fetchall()
        print(f"Found {len(candidates)} F-150 candidates for Powerboost checking")

        updated_count = 0
        for vin, model, engine in candidates:
            pdf_path = STICKERS_DIR / f"{vin}.pdf"
            if not pdf_path.exists():
                continue

            try:
                pdf_text = extract_text_from_pdf(pdf_path)
            except Exception as e:
                print(f"  Error extracting text from {pdf_path}: {e}")
                continue

            # Check for Powerboost patterns
            if _POWERBOOST_PATTERN.search(pdf_text):
                print(f"  Found Powerboost pattern in {vin} - updating engine")
                cur.execute("""
                    UPDATE vehicles
                    SET engine = '3.5L V6 PowerBoost Hybrid'
                    WHERE vin = ?
                """, (vin,))

                updated_count += 1

        conn.commit()
        print(f"Updated {updated_count} vehicles with Powerboost engines")


if __name__ == "__main__":
    import sys
    if len(sys.argv) > 1 and sys.argv[1] == "--fix-powerboost":
        fix_powerboost_entries()
    else:
        main()
