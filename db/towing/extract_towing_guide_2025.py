#!/usr/bin/env python3
"""
Extract *truck* towing/payload capability data from Ford's 2025 RV & Trailer Towing Guide.

Source PDF:
  /root/www/db/ford_guides/pdf/2025/towing.pdf

Output JSON:
  /root/www/db/ford_guides/json/2025/towing_trucks.json

Notes on fidelity
-----------------
The towing guide contains large, multi-column selector tables (cab/wheelbase/box-length).
PDF text extraction is lossy, so we store:
  - Exact tables where extraction is clean (Ranger, Maverick, performance payload/tow).
  - For large selector tables (F-150, Super Duty), we store per-engine tokenized rows and
    allow downstream lookup to compute conservative min/max ranges when wheelbase/box isn't known.
"""

from __future__ import annotations

import json
import re
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

from pypdf import PdfReader

PDF_PATH = Path("/root/www/db/ford_guides/pdf/2025/towing.pdf")
OUT_PATH = Path("/root/www/db/ford_guides/json/2025/towing_trucks.json")


def _clean_text(s: str) -> str:
    # Normalize weird PDF ligatures/controls into spaces.
    s = s.replace("\u00a0", " ")
    # Replace other common non-ascii noise with spaces.
    s = re.sub(r"[^\x09\x0a\x0d\x20-\x7e]", " ", s)
    # Keep line breaks (important for parsing), but strip trailing whitespace.
    return "\n".join(line.rstrip() for line in s.splitlines())


def _int_from_token(tok: str) -> int | None:
    """
    Parse integers that may include commas and trailing footnote digits, e.g.
    '13,5004' -> 13500
    '2,2255' -> 2225
    """
    tok = tok.strip()
    if not tok:
        return None
    original = tok
    tok = tok.replace(",", "")
    # Drop a trailing footnote digit ONLY when the original token clearly had an extra digit
    # appended after a comma-formatted number (e.g. '13,5004' -> '135004' -> '13500').
    if re.fullmatch(r"\d{1,3}(?:,\d{3})+\d", original):
        tok = tok[:-1]
    tok = re.sub(r"[^\d]", "", tok)
    if not tok:
        return None
    try:
        return int(tok)
    except ValueError:
        return None


def _numbers_in_line(line: str) -> list[int]:
    # Find tokens like 12,300 or 2400 or 10,300/9,9002 etc.
    # We'll split the / form later; here we keep each comma-number token.
    toks = re.findall(r"\d{1,3}(?:,\d{3})+(?:\d+)?|\d{3,6}", line)
    out: list[int] = []
    for t in toks:
        # If token contains '/', split into separate numeric chunks.
        parts = t.split("/")
        for p in parts:
            n = _int_from_token(p)
            if n is not None:
                out.append(n)
    return out


def _page_tokens(reader: PdfReader, page_index: int) -> list[tuple[float, float, str]]:
    """
    Return (y, x, text) tokens from a PDF page using pypdf's coordinate visitor.
    """
    page = reader.pages[page_index]
    items: list[tuple[float, float, str]] = []

    def visitor(text: str, _cm, tm, _font_dict, _font_size) -> None:  # noqa: ANN001
        s = (text or "").strip()
        if not s:
            return
        x = float(tm[4])
        y = float(tm[5])
        items.append((y, x, s))

    page.extract_text(visitor_text=visitor)
    return items


def _merge_fragments(tokens: list[tuple[float, str]]) -> list[tuple[float, str]]:
    """
    Merge common pypdf token fragments (e.g. '7,' + '400' -> '7,400', '3.' + '15/3.55' -> '3.15/3.55').
    Input is (x, text) already sorted by x.
    """
    out: list[tuple[float, str]] = []
    i = 0
    while i < len(tokens):
        x, s = tokens[i]
        if i + 1 < len(tokens):
            x2, s2 = tokens[i + 1]
            # Join leading digit + comma-number fragments like '1' + '2,300' -> '12,300'
            # (pypdf often splits the leading digit for 5-digit comma numbers).
            if re.fullmatch(r"\d", s) and re.fullmatch(r"\d{1,3},\d{3}\d?", s2):
                out.append((x, s + s2))
                i += 2
                continue
            # Join comma-number fragments like '12,' + '700'
            if s.endswith(",") and s2 and s2[0].isdigit():
                out.append((x, s + s2))
                i += 2
                continue
            # Join decimal fragments like '3.' + '15/3.55'
            if s.endswith(".") and s2 and s2[0].isdigit():
                out.append((x, s + s2))
                i += 2
                continue
        out.append((x, s))
        i += 1
    return out


def _group_lines(items: list[tuple[float, float, str]]) -> dict[float, list[tuple[float, str]]]:
    """
    Group (y,x,text) into line buckets keyed by rounded y (1 decimal).
    Returns {y_bucket: [(x,text), ...]} with tokens sorted by x and fragments merged.
    """
    from collections import defaultdict

    buckets: dict[float, list[tuple[float, str]]] = defaultdict(list)
    for y, x, s in items:
        buckets[round(y, 1)].append((x, s))
    out: dict[float, list[tuple[float, str]]] = {}
    for y in buckets:
        toks = sorted(buckets[y], key=lambda t: t[0])
        out[y] = _merge_fragments(toks)
    return out


def _parse_wb_token(s: str) -> float | None:
    m = re.search(r"(\d{3}\.\d)\s*\"?\s*WB", s, re.I)
    if not m:
        return None
    try:
        return float(m.group(1))
    except ValueError:
        return None


def _extract_exact_f150_table(reader: PdfReader, page_indexes: list[int]) -> dict[str, Any]:
    """
    Extract an exact F-150 selector table from one or more pages.

    Output:
      {
        "columns": [{drivetrain, wheelbase, cab, bed_length}, ...],
        "rows": [{engine, axle_ratio, gcwr_lbs, values: [.. same len as columns ..]}, ...]
      }
    """
    # First, build a unified column list from each page header line containing 'WB'.
    columns: list[dict[str, Any]] = []
    col_key_to_index: dict[tuple[str, float, str], int] = {}

    for pi in page_indexes:
        lines = _group_lines(_page_tokens(reader, pi))
        # Find the header line that contains wheelbase columns.
        header_y = None
        for y, toks in lines.items():
            line_text = " ".join(t for _, t in toks)
            # Header line always includes the WB columns; depending on page extraction,
            # the 'Engine/Ratio' labels may be on a separate line, so keep this loose.
            if "WB" in line_text and ("Engine" in line_text or "GCWR" in line_text or "Ratio" in line_text):
                header_y = y
                break
        if header_y is None:
            continue

        header = lines[header_y]
        wb_cells: list[tuple[float, str, float]] = []
        for x, t in header:
            if "WB" in t:
                wb = _parse_wb_token(t)
                if wb is not None:
                    # Determine drivetrain from token prefix.
                    dt = "4x4" if "4x4" in t else "4x2" if "4x2" in t else None
                    if not dt:
                        continue
                    wb_cells.append((x, dt, wb))
        wb_cells.sort(key=lambda z: z[0])

        # Assign cab based on ordering: Regular (141.5 / 122.8), then SuperCab (first 145.4 pair),
        # then SuperCrew (remaining, incl 157.2).
        # Note: page 15 doesn't include 122.8 columns, so Regular is the first two 141.5 columns.
        # We'll detect by wheelbase distribution.
        wbs = [wb for _, _, wb in wb_cells]
        first_145_idx = next((i for i, wb in enumerate(wbs) if abs(wb - 145.4) < 0.2), None)
        for i, (x, dt, wb) in enumerate(wb_cells):
            if abs(wb - 122.8) < 0.2 or abs(wb - 141.5) < 0.2:
                cab = "Regular Cab"
            elif abs(wb - 157.2) < 0.2:
                cab = "SuperCrew"
            else:
                # 145.4
                if first_145_idx is None:
                    cab = "SuperCrew"
                else:
                    # first two 145.4 columns after regular are SuperCab, rest SuperCrew
                    cab = "Super Cab" if i in {first_145_idx, first_145_idx + 1} else "SuperCrew"

            # Infer bed length from cab + wheelbase (2025 F-150 mapping).
            bed = None
            if cab == "Regular Cab":
                bed = 6.5 if abs(wb - 122.8) < 0.2 else 8.0 if abs(wb - 141.5) < 0.2 else None
            elif cab == "Super Cab":
                bed = 6.5 if abs(wb - 145.4) < 0.2 else None
            elif cab == "SuperCrew":
                bed = 5.5 if abs(wb - 145.4) < 0.2 else 6.5 if abs(wb - 157.2) < 0.2 else None

            k = (dt, wb, cab)
            if k not in col_key_to_index:
                col_key_to_index[k] = len(columns)
                columns.append({"drivetrain": dt, "wheelbase": wb, "cab": cab, "bed_length": bed, "x": x})

    # Sort columns by x and drop x from stored representation.
    columns_sorted = sorted(columns, key=lambda c: c["x"])
    # Build remap
    remap = {id(c): i for i, c in enumerate(columns_sorted)}
    # rebuild key->index according to sorted order
    col_key_to_index = {}
    for i, c in enumerate(columns_sorted):
        col_key_to_index[(c["drivetrain"], float(c["wheelbase"]), c["cab"])] = i

    for c in columns_sorted:
        c.pop("x", None)

    # Now parse rows on each page and project values into the unified columns list.
    rows: list[dict[str, Any]] = []
    current_engine: str | None = None
    current_ratio: str | None = None

    def _engine_from_tokens(toks: list[str]) -> str | None:
        s = " ".join(toks)
        m = re.search(r"\b(\d\.\dL|Electric)\b", s)
        if not m:
            return None
        # take tokens up to before axle ratio-ish token
        parts = []
        for tok in toks:
            if re.fullmatch(r"\d\.\d{2}(?:/\d\.\d{2})?", tok):
                break
            parts.append(tok)
        eng = " ".join(parts).strip()
        return re.sub(r"\s+", " ", eng) if eng else None

    for pi in page_indexes:
        lines = _group_lines(_page_tokens(reader, pi))
        for y in sorted(lines.keys(), reverse=True):
            toks = lines[y]
            line = " ".join(t for _, t in toks)
            if line.startswith("Notes:") or "Calculated with SAE" in line:
                continue
            # data lines generally contain a GCWR comma-number
            if not re.search(r"\b\d{1,3},\d{3}\b", line):
                continue
            # exclude header line
            if "Engine" in line and "Ratio" in line and "WB" in line:
                continue

            # Convert to (x, token) list
            xtoks = toks
            tokens_only = [t for _, t in xtoks]

            eng = _engine_from_tokens(tokens_only)
            if eng:
                current_engine = eng

            # Find axle ratio token
            ratio = next((t for t in tokens_only if re.fullmatch(r"\d\.\d{2}(?:/\d\.\d{2})?", t)), None)
            if ratio:
                current_ratio = ratio

            if not current_engine or not current_ratio:
                continue

            # Extract numeric tokens with their x positions (skip GCWR handled separately)
            num_tokens: list[tuple[float, str]] = [(x, t) for x, t in xtoks if re.fullmatch(r"\d{1,3},\d{3}(?:/\d{1,3},\d{3}\d?)?", t)]
            if not num_tokens:
                continue
            # First numeric token is GCWR (left-most after ratio region)
            num_tokens.sort(key=lambda z: z[0])
            gcwr_tok = num_tokens[0][1]
            gcwr = _int_from_token(gcwr_tok)
            if gcwr is None:
                continue
            cells = num_tokens[1:]
            if not cells:
                continue

            # Map each cell to nearest column by x using the per-page column x anchors.
            # We reuse the unified columns order; for mapping we need column x positions.
            # Since we dropped x, approximate by ordering and distribute using header detection above:
            # We'll assign by nearest column index using relative ordering of the numeric cells.
            # This works because each numeric cell appears in its column position.
            values: list[int | list[int] | None] = [None] * len(columns_sorted)

            # Determine which columns exist on this page by re-detecting header with x anchors.
            header_cols = []
            header_y = next(
                (
                    yy
                    for yy, tt in lines.items()
                    if "WB" in " ".join(t for _, t in tt)
                    and ("Engine" in " ".join(t for _, t in tt) or "GCWR" in " ".join(t for _, t in tt) or "Ratio" in " ".join(t for _, t in tt))
                ),
                None,
            )
            if header_y is not None:
                ht = lines[header_y]
                for x, t in ht:
                    if "WB" in t:
                        wb = _parse_wb_token(t)
                        if wb is None:
                            continue
                        dt = "4x4" if "4x4" in t else "4x2" if "4x2" in t else None
                        if not dt:
                            continue
                        header_cols.append((x, dt, wb))
                header_cols.sort(key=lambda z: z[0])

            # Build page-local cab assignment as done above
            wbs = [wb for _, _, wb in header_cols]
            first_145_idx = next((i for i, wb in enumerate(wbs) if abs(wb - 145.4) < 0.2), None)
            page_cols: list[tuple[float, int]] = []  # (x, unified_index)
            for i, (x, dt, wb) in enumerate(header_cols):
                if abs(wb - 122.8) < 0.2 or abs(wb - 141.5) < 0.2:
                    cab = "Regular Cab"
                elif abs(wb - 157.2) < 0.2:
                    cab = "SuperCrew"
                else:
                    cab = "Super Cab" if first_145_idx is not None and i in {first_145_idx, first_145_idx + 1} else "SuperCrew"
                k = (dt, float(wb), cab)
                if k in col_key_to_index:
                    page_cols.append((x, col_key_to_index[k]))
            page_cols.sort(key=lambda z: z[0])

            # Assign each cell by nearest x among page_cols
            for x, t in cells:
                if not page_cols:
                    continue
                idx = min(page_cols, key=lambda pc: abs(pc[0] - x))[1]
                # Handle slash forms like '10,300/9,9002' (kept as one token by merge_fragments)
                if "/" in t:
                    parts = t.split("/")
                    parsed = [_int_from_token(p) for p in parts]
                    values[idx] = [p for p in parsed if p is not None]
                else:
                    values[idx] = _int_from_token(t)

            rows.append(
                {
                    "engine": current_engine,
                    "axle_ratio": current_ratio,
                    "gcwr_lbs": gcwr,
                    "values": values,
                }
            )

    # Deduplicate rows (same engine/ratio/gcwr can appear multiple times)
    uniq = {}
    for r in rows:
        key = (r["engine"], r["axle_ratio"], r["gcwr_lbs"], tuple(str(v) for v in r["values"]))
        uniq[key] = r
    return {"columns": columns_sorted, "rows": list(uniq.values())}


def _extract_performance_table(page_text: str) -> dict[str, dict[str, int]]:
    """
    Parse the performance tables that include Max Towing and Max Payload by engine.
    Returns {engine: {"max_towing_lbs": X, "max_payload_lbs": Y}}.
    """
    lines = [l.strip() for l in page_text.splitlines() if l.strip()]

    # Heuristic: performance rows contain an engine name followed by many numeric tokens; last two
    # are max towing and max payload (as seen on F-150 and Super Duty pages).
    engines: dict[str, dict[str, int]] = {}
    for line in lines:
        # Must contain at least two comma-numbers near the end.
        nums = _numbers_in_line(line)
        if len(nums) < 2:
            continue

        # Try to identify engine string by looking for 'L' and 'EcoBoost'/'Diesel'/'Gas'/etc.
        # We'll take the left side of the line up to the first horsepower-ish '@' or a big number.
        if "L" not in line:
            continue
        # Skip obvious non-row lines
        if "Max Payload" in line or "Max Towing" in line or "Available" in line:
            continue

        # Extract a candidate engine label.
        # Examples:
        #  - "2.7L EcoBoost V6 ..."
        #  - "6.7L Power Stroke Diesel ..."
        m = re.match(r"^(?P<eng>.+?)\s+\d{2,3}\s+@\s+", line)
        if not m:
            # Super Duty sometimes doesn't have '@' in extracted row; fallback:
            m = re.match(r"^(?P<eng>\d\.\dL.+?)\s+\d{2,3}\s+", line)
        if not m:
            continue

        engine = re.sub(r"\s+", " ", m.group("eng")).strip()
        # Last two numbers: max towing, max payload
        max_tow = nums[-2]
        max_payload = nums[-1]
        # Sanity filter: payload should be smaller than towing and usually < 10000.
        if max_payload > 20000 or max_tow < 1500:
            continue
        engines[engine] = {"max_towing_lbs": max_tow, "max_payload_lbs": max_payload}

    return engines


@dataclass
class SelectorSection:
    name: str
    page_indexes: list[int]
    by_engine: dict[str, dict[str, Any]]


_ENGINE_LINE_START = re.compile(r"^\s*(Electric|\d\.\dL)\b")


def _extract_selector_section(
    pages_text: dict[int, str],
    *,
    section_name: str,
    page_indexes: list[int],
) -> SelectorSection:
    """
    Extract tokenized selector rows from one or more pages. This is lossy for wide tables,
    so we store token lists per engine and let lookup compute conservative ranges.
    """
    by_engine: dict[str, dict[str, Any]] = {}
    current_engine: str | None = None

    def commit_row(engine: str, gcwr: int | None, tow_values: list[int], raw_line: str) -> None:
        if engine not in by_engine:
            by_engine[engine] = {
                "gcwr_values_lbs": [],
                "tow_values_lbs": [],
                "raw_lines": [],
            }
        if gcwr is not None:
            by_engine[engine]["gcwr_values_lbs"].append(gcwr)
        if tow_values:
            by_engine[engine]["tow_values_lbs"].extend(tow_values)
        by_engine[engine]["raw_lines"].append(raw_line)

    for pi in page_indexes:
        text = pages_text.get(pi, "")
        lines = [l.strip() for l in text.splitlines() if l.strip()]
        for line in lines:
            if line.lower().startswith("notes:"):
                current_engine = None
                continue
            if "TABLE OF" in line and "CONTENT" in line:
                continue

            # Detect engine line start or continuation rows (start with a GCWR number).
            is_engine_line = _ENGINE_LINE_START.match(line) is not None
            is_continuation = bool(re.match(r"^\s*\d{1,3},\d{3}", line))

            if not (is_engine_line or is_continuation):
                continue

            # If engine line, capture a normalized engine label prefix.
            if is_engine_line:
                # Take text up to the first axle ratio token or first big number.
                # Example: "2.7L GTDI V 6 3.15/3.55 12,300 7,400"
                m = re.match(r"^(?P<eng>Electric|\d\.\dL.+?)\s+(?:(?:\d\.\d{2}(?:/\d\.\d{2})?)\s+)?\d{1,3},\d{3}", line)
                if m:
                    current_engine = re.sub(r"\s+", " ", m.group("eng")).strip()
                else:
                    # Fallback: first ~6 tokens
                    current_engine = " ".join(line.split()[:6])

            if not current_engine:
                continue

            # Extract numbers; assume the first large-ish number after ratio is GCWR for this row.
            nums = _numbers_in_line(line)
            if not nums:
                continue

            # Heuristic: first number is GCWR if >= 6000 (all trucks).
            gcwr = nums[0] if nums and nums[0] >= 6000 else None
            tow_values = nums[1:] if gcwr is not None else nums

            # Filter out obvious horsepower/torque rows leaking in (shouldn't happen here).
            tow_values = [n for n in tow_values if n >= 1500]
            commit_row(current_engine, gcwr, tow_values, raw_line=line)

    # Deduplicate numeric lists for stability.
    for eng, rec in by_engine.items():
        rec["gcwr_values_lbs"] = sorted(set(int(x) for x in rec["gcwr_values_lbs"] if isinstance(x, int)))
        rec["tow_values_lbs"] = sorted(set(int(x) for x in rec["tow_values_lbs"] if isinstance(x, int)))

    return SelectorSection(name=section_name, page_indexes=page_indexes, by_engine=by_engine)


def _extract_maverick(page_text: str) -> dict[str, Any]:
    """
    Maverick selector is relatively clean. Parse into exact per-engine values by drivetrain + 4K package.
    """
    t = " ".join(page_text.split())
    rows: list[dict[str, Any]] = []

    # Example token sequence (PDF extraction may insert spaces inside words):
    # 2.5L I4 Hybri d 3.37 6,090 2,000 6,315 2,000 8,315 4,000
    rx = re.compile(
        r"(?P<engine>2\.5L\s+I4\s+Hybri\s*d|2\.0L\s+EcoBoos\s*t\s*®?\s*I4)\s+"
        r"(?P<axle>\d\.\d{2})\s+"
        r"(?P<gcwr_fwd>\d{1,3}(?:,\d{3})+)\s+(?P<tow_fwd>\d{1,3}(?:,\d{3})+)\s+"
        r"(?P<gcwr_awd>\d{1,3}(?:,\d{3})+)\s+(?P<tow_awd>\d{1,3}(?:,\d{3})+)\s+"
        r"(?P<gcwr_4k>\d{1,3}(?:,\d{3})+)\s+(?P<tow_4k>\d{1,3}(?:,\d{3})+)",
        re.I,
    )
    for m in rx.finditer(t):
        engine_label = re.sub(r"\s+", " ", m.group("engine")).replace("®", "").strip()
        engine_label = engine_label.replace("EcoBoos t", "EcoBoost").replace("Hybri d", "Hybrid")
        rows.append(
            {
                "engine": engine_label,
                "axle_ratio": float(m.group("axle")),
                "variants": [
                    {
                        "drivetrain": "FWD",
                        "gcwr_lbs": _int_from_token(m.group("gcwr_fwd")),
                        "max_trailer_lbs": _int_from_token(m.group("tow_fwd")),
                        "requires": [],
                    },
                    {
                        "drivetrain": "AWD",
                        "gcwr_lbs": _int_from_token(m.group("gcwr_awd")),
                        "max_trailer_lbs": _int_from_token(m.group("tow_awd")),
                        "requires": [],
                    },
                    {
                        "drivetrain": "AWD",
                        "gcwr_lbs": _int_from_token(m.group("gcwr_4k")),
                        "max_trailer_lbs": _int_from_token(m.group("tow_4k")),
                        "requires": ["4K Tow Package"],
                    },
                ],
            }
        )
    return {"max_loaded_trailer_weight": rows}


def _extract_ranger(page_text: str) -> dict[str, Any]:
    """
    Ranger selector is mostly clean. Parse per engine, with 4x2 and/or 4x4 variants where present.
    """
    t = " ".join(page_text.split())
    rows: list[dict[str, Any]] = []

    # 2.3L EcoBoos t I4 3.73 12,370 7,500 12,590 7,500
    rx_23 = re.compile(
        r"(?P<engine>2\.3L\s+EcoBoos\s*t\s*®?\s*I4)\s+"
        r"(?P<axle>\d\.\d{2})\s+"
        r"(?P<gcwr_4x2>\d{1,3}(?:,\d{3})+)\s+(?P<tow_4x2>\d{1,3}(?:,\d{3})+)\s+"
        r"(?P<gcwr_4x4>\d{1,3}(?:,\d{3})+)\s+(?P<tow_4x4>\d{1,3}(?:,\d{3})+)",
        re.I,
    )
    m = rx_23.search(t)
    if m:
        rows.append(
            {
                "engine": re.sub(r"\s+", " ", m.group("engine")).replace("®", "").replace("EcoBoos t", "EcoBoost").strip(),
                "axle_ratio": float(m.group("axle")),
                "variants": [
                    {
                        "drivetrain": "4x2",
                        "gcwr_lbs": _int_from_token(m.group("gcwr_4x2")),
                        "max_trailer_lbs": _int_from_token(m.group("tow_4x2")),
                    },
                    {
                        "drivetrain": "4x4",
                        "gcwr_lbs": _int_from_token(m.group("gcwr_4x4")),
                        "max_trailer_lbs": _int_from_token(m.group("tow_4x4")),
                    },
                ],
            }
        )

    # 2.7L EcoBoos t V6 3.73 12,745 7,500  (only one pair usually; 4x4)
    rx_27 = re.compile(
        r"(?P<engine>2\.7L\s+EcoBoos\s*t\s*V6)\s+"
        r"(?P<axle>\d\.\d{2})\s+"
        r"(?P<gcwr>\d{1,3}(?:,\d{3})+)\s+(?P<tow>\d{1,3}(?:,\d{3})+)",
        re.I,
    )
    m = rx_27.search(t)
    if m:
        rows.append(
            {
                "engine": re.sub(r"\s+", " ", m.group("engine")).replace("®", "").replace("EcoBoos t", "EcoBoost").strip(),
                "axle_ratio": float(m.group("axle")),
                "variants": [
                    {
                        "drivetrain": "4x4",
                        "gcwr_lbs": _int_from_token(m.group("gcwr")),
                        "max_trailer_lbs": _int_from_token(m.group("tow")),
                    }
                ],
            }
        )

    # 3.0L EcoBoos t V6 4.27 11,465 5,510
    rx_30 = re.compile(
        r"(?P<engine>3\.0L\s+EcoBoos\s*t\s*V6)\s+"
        r"(?P<axle>\d\.\d{2})\s+"
        r"(?P<gcwr>\d{1,3}(?:,\d{3})+)\s+(?P<tow>\d{1,3}(?:,\d{3})+)",
        re.I,
    )
    m = rx_30.search(t)
    if m:
        rows.append(
            {
                "engine": re.sub(r"\s+", " ", m.group("engine")).replace("®", "").replace("EcoBoos t", "EcoBoost").strip(),
                "axle_ratio": float(m.group("axle")),
                "variants": [
                    {
                        "drivetrain": "4x4",
                        "gcwr_lbs": _int_from_token(m.group("gcwr")),
                        "max_trailer_lbs": _int_from_token(m.group("tow")),
                    }
                ],
            }
        )

    return {"max_loaded_trailer_weight": rows}


def main() -> None:
    if not PDF_PATH.exists():
        raise SystemExit(f"Missing PDF: {PDF_PATH}")

    reader = PdfReader(str(PDF_PATH))
    pages_text: dict[int, str] = {}
    for i, p in enumerate(reader.pages):
        pages_text[i] = _clean_text(p.extract_text() or "")

    # Page indexes are 0-based (see exploration output in this repo).
    data: dict[str, Any] = {
        "year": 2025,
        "source_pdf": str(PDF_PATH),
        "extracted_at": datetime.now(timezone.utc).isoformat(),
        "models": {},
    }

    # Performance tables (max towing + max payload)
    data["models"]["F-150"] = {
        "performance_by_engine": _extract_performance_table(pages_text[11]),
        "selectors": {},
    }
    data["models"]["Super Duty"] = {
        "performance_by_engine": _extract_performance_table(pages_text[12]),
        "selectors": {},
    }

    # Ranger & Maverick (exact)
    data["models"]["Ranger"] = {
        "selectors": {
            "max_loaded_trailer_weight": _extract_ranger(pages_text[26]),
        }
    }
    data["models"]["Maverick"] = {
        "selectors": {
            "max_loaded_trailer_weight": _extract_maverick(pages_text[27]),
        }
    }

    # F-150 selector tables (lossy but useful for conservative ranges)
    data["models"]["F-150"]["selectors"]["conventional"] = _extract_selector_section(
        pages_text, section_name="conventional", page_indexes=[14, 15]
    ).__dict__
    data["models"]["F-150"]["selectors"]["fifth_wheel_gooseneck"] = _extract_selector_section(
        pages_text, section_name="fifth_wheel_gooseneck", page_indexes=[16, 17, 18]
    ).__dict__

    # F-150 exact selector tables (column-accurate)
    data["models"]["F-150"]["selectors_exact"] = {
        "conventional": _extract_exact_f150_table(reader, [14, 15]),
        # 5th/gooseneck spans pages; extraction approach is the same header/row shape.
        "fifth_wheel_gooseneck": _extract_exact_f150_table(reader, [16, 17, 18]),
    }

    # Super Duty selector tables (F-250/F-350/F-450 pickup tables live across these pages)
    data["models"]["Super Duty"]["selectors"]["f250_conventional"] = _extract_selector_section(
        pages_text, section_name="f250_conventional", page_indexes=[19]
    ).__dict__
    data["models"]["Super Duty"]["selectors"]["f250_fifth_wheel_gooseneck"] = _extract_selector_section(
        pages_text, section_name="f250_fifth_wheel_gooseneck", page_indexes=[20]
    ).__dict__
    data["models"]["Super Duty"]["selectors"]["f350_srw_conventional"] = _extract_selector_section(
        pages_text, section_name="f350_srw_conventional", page_indexes=[21, 22]
    ).__dict__
    data["models"]["Super Duty"]["selectors"]["f350_srw_fifth_wheel_gooseneck"] = _extract_selector_section(
        pages_text, section_name="f350_srw_fifth_wheel_gooseneck", page_indexes=[22]
    ).__dict__
    data["models"]["Super Duty"]["selectors"]["f350_f450_drw_conventional"] = _extract_selector_section(
        pages_text, section_name="f350_f450_drw_conventional", page_indexes=[23]
    ).__dict__
    data["models"]["Super Duty"]["selectors"]["f350_f450_drw_fifth_wheel_gooseneck"] = _extract_selector_section(
        pages_text, section_name="f350_f450_drw_fifth_wheel_gooseneck", page_indexes=[24, 25]
    ).__dict__

    OUT_PATH.parent.mkdir(parents=True, exist_ok=True)
    OUT_PATH.write_text(json.dumps(data, indent=2, sort_keys=True), encoding="utf-8")
    print(f"Wrote {OUT_PATH} ({OUT_PATH.stat().st_size} bytes)")


if __name__ == "__main__":
    main()


