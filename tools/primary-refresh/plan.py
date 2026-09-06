#!/usr/bin/env python3
"""Build a deterministic plan for refreshing the imported Primärliste data."""

from __future__ import annotations

import argparse
import hashlib
import importlib.util
import json
import re
import unicodedata
from datetime import timedelta
from decimal import Decimal, ROUND_HALF_UP
from pathlib import Path
from typing import Any
from zipfile import ZipFile


ROOT = Path(__file__).resolve().parents[2]
AUDIT_SCRIPT = ROOT / "tools/legacy-audit/audit.py"
DEFAULT_BASE_PLAN = ROOT / "var/legacy-import-output/current/import-plan.json"
DEFAULT_IMAGES = ROOT / "resources/primary-images/640x1024"
DEFAULT_OUTPUT = ROOT / "var/primary-refresh/current/refresh-plan.json"
IMAGE_WIDTH = 640
IMAGE_HEIGHT = 1024
TESTER_COLUMNS = {
    "manu": (5, 9, 13),
    "fabi": (6, 10, 14),
    "schorsch": (7, 11, 15),
}
PYRASER_NAME = "Pyraser Waldquelle Cola-Mix"
OLD_HALLER_NAME = "Haller Wildbachquelle Cola Mix Limonade"
NEW_HALLER_NAME = "Haller Wildbadquelle Cola Mix Limonade"


def load_audit() -> Any:
    spec = importlib.util.spec_from_file_location("spezitest_legacy_audit", AUDIT_SCRIPT)
    if spec is None or spec.loader is None:
        raise RuntimeError("Could not load the reviewed workbook parser.")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for block in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(block)
    return digest.hexdigest()


def clean(value: Any) -> str | None:
    if value in (None, ""):
        return None
    result = " ".join(str(value).strip().split())
    return result or None


def identity(name: str | None, manufacturer: str | None, location: str | None) -> tuple[str, str, str]:
    return tuple(
        unicodedata.normalize("NFKC", value or "").casefold()
        for value in (name, manufacturer, location)
    )


def decimal_value(value: Any) -> str:
    if value in (None, ""):
        raise RuntimeError("A tested row has no price or total.")
    normalized = format(float(str(value)), ".15g")
    if "e" in normalized.lower():
        normalized = format(Decimal(normalized), "f")
    return normalized.rstrip("0").rstrip(".") if "." in normalized else normalized


def excel_time(value: Any) -> str | None:
    if value in (None, ""):
        return None
    seconds = int((Decimal(str(value)) * Decimal(86400)).quantize(Decimal("1"), rounding=ROUND_HALF_UP)) % 86400
    return str(timedelta(seconds=seconds)).rjust(8, "0")


def cell(rows: dict[int, dict[int, dict[str, Any]]], row: int, column: int) -> dict[str, Any]:
    return rows.get(row, {}).get(column, {})


def value(rows: dict[int, dict[int, dict[str, Any]]], row: int, column: int) -> Any:
    return cell(rows, row, column).get("value")


def relative_or_absolute(path: Path) -> str:
    path = path.resolve()
    return str(path.relative_to(ROOT)) if path.is_relative_to(ROOT) else str(path)


def webp_dimensions(data: bytes) -> tuple[str, int | None, int | None]:
    """Return dimensions from the standard VP8X, VP8L, or VP8 WebP header."""
    if len(data) < 20 or data[:4] != b"RIFF" or data[8:12] != b"WEBP":
        return "unknown", None, None

    offset = 12
    while offset + 8 <= len(data):
        chunk_type = data[offset:offset + 4]
        chunk_size = int.from_bytes(data[offset + 4:offset + 8], "little")
        payload = data[offset + 8:offset + 8 + chunk_size]
        if len(payload) != chunk_size:
            break
        if chunk_type == b"VP8X" and len(payload) >= 10:
            width = 1 + int.from_bytes(payload[4:7], "little")
            height = 1 + int.from_bytes(payload[7:10], "little")
            return "webp", width, height
        if chunk_type == b"VP8L" and len(payload) >= 5 and payload[0] == 0x2F:
            width = 1 + (((payload[2] & 0x3F) << 8) | payload[1])
            height = 1 + (((payload[4] & 0x0F) << 10) | (payload[3] << 2) | ((payload[2] & 0xC0) >> 6))
            return "webp", width, height
        if chunk_type == b"VP8 " and len(payload) >= 10 and payload[3:6] == b"\x9d\x01\x2a":
            width = int.from_bytes(payload[6:8], "little") & 0x3FFF
            height = int.from_bytes(payload[8:10], "little") & 0x3FFF
            return "webp", width, height
        offset += 8 + chunk_size + (chunk_size % 2)

    return "webp", None, None


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("workbook", type=Path)
    parser.add_argument("--base-plan", type=Path, default=DEFAULT_BASE_PLAN)
    parser.add_argument("--images", type=Path, default=DEFAULT_IMAGES)
    parser.add_argument("--output", type=Path, default=DEFAULT_OUTPUT)
    args = parser.parse_args()

    workbook = args.workbook.resolve()
    base_plan_path = args.base_plan.resolve()
    image_directory = args.images.resolve()
    output = args.output.resolve()
    for required in (workbook, base_plan_path):
        if not required.is_file():
            parser.error(f"Missing required file: {required}")
    if not image_directory.is_dir():
        parser.error(f"Missing image directory: {image_directory}")

    audit = load_audit()
    workbook_hash_before = sha256_file(workbook)
    with ZipFile(workbook) as archive:
        strings = audit.shared_strings(archive)
        style_data = audit.styles(archive)
        worksheets, _ = audit.workbook_parts(archive)
        sheet = next((item for item in worksheets if item["name"] == "Spezi Test"), None)
        if sheet is None:
            raise RuntimeError("The workbook has no 'Spezi Test' sheet.")
        sheet_info, rows = audit.inspect_sheet(archive, sheet["part"], strings, style_data, (2, 3, 4))

    if sheet_info["last_identity_row"] != 167:
        raise RuntimeError("Expected exactly 166 Primärliste records in rows 2–167.")

    base_plan = json.loads(base_plan_path.read_text(encoding="utf-8"))
    base_drinks = base_plan.get("drinks")
    if not isinstance(base_drinks, list) or len(base_drinks) != 196:
        raise RuntimeError("The reviewed base plan must contain exactly 196 drinks.")

    base_by_primary_identity: dict[tuple[str, str, str], dict[str, Any]] = {}
    primary_sources: set[str] = set()
    for drink in base_drinks:
        for source in drink.get("sources", []):
            if source.get("workbook") != "primaerliste":
                continue
            key = identity(source.get("name"), source.get("manufacturer"), source.get("origin_location"))
            if key in base_by_primary_identity:
                raise RuntimeError("The base plan contains an ambiguous Primärliste identity.")
            base_by_primary_identity[key] = drink
            primary_sources.add(str(source["source"]))

    records = []
    mapped_sources: set[str] = set()
    for row in range(2, 168):
        b, c, d = (clean(value(rows, row, column)) for column in (2, 3, 4))
        if b == PYRASER_NAME:
            name, manufacturer, location = b, c, d
        else:
            manufacturer, location, name = b, c, d
        if name is None:
            raise RuntimeError(f"Row {row} has no usable drink name.")

        lookup_name = OLD_HALLER_NAME if name == NEW_HALLER_NAME else name
        base = base_by_primary_identity.get(identity(lookup_name, manufacturer, location))
        if base is None:
            raise RuntimeError(f"Row {row} does not match the reviewed imported dataset: {name}")
        source = next(item for item in base["sources"] if item.get("workbook") == "primaerliste")
        source_id = str(source["source"])
        if source_id in mapped_sources:
            raise RuntimeError(f"More than one workbook row maps to {source_id}.")
        mapped_sources.add(source_id)

        grade_values = [clean(value(rows, row, column)) for column in (5, 6, 7, 9, 10, 11, 13, 14, 15)]
        has_any = any(item is not None for item in grade_values)
        has_all = all(item is not None for item in grade_values)
        has_total = value(rows, row, 17) not in (None, "")
        if has_any != has_all or has_all != has_total:
            raise RuntimeError(f"Row {row} has an incomplete or inconsistent rating set.")

        red = all(audit.is_solid_red(cell(rows, row, column)) for column in (2, 3, 4))
        status = "tested" if has_all else ("identified" if red else "acquired")
        test = None
        if has_all:
            parsed_grades = []
            for grade in grade_values:
                if grade is None or re.fullmatch(r"(?:0|[1-9]|10)", grade) is None:
                    raise RuntimeError(f"Row {row} contains a non-integer grade outside 0–10.")
                parsed_grades.append(int(grade))
            rating_map = {}
            positions = {column: parsed_grades[index] for index, column in enumerate((5, 6, 7, 9, 10, 11, 13, 14, 15))}
            for tester, columns in TESTER_COLUMNS.items():
                rating_map[tester] = {
                    "optik": positions[columns[0]],
                    "sueffigkeit": positions[columns[1]],
                    "geschmack": positions[columns[2]],
                }
            artifact = bool(cell(rows, row, 22).get("formula") or cell(rows, row, 23).get("formula"))
            duration = None if artifact or value(rows, row, 23) in (None, "") else int(float(str(value(rows, row, 23))))
            test = {
                "price_amount": decimal_value(value(rows, row, 19)),
                "recorded_time": None if artifact else excel_time(value(rows, row, 22)),
                "duration_value": duration,
                "stream_reference": int(float(str(value(rows, row, 24)))),
                "ratings": rating_map,
                "historical_gesamt": decimal_value(value(rows, row, 17)),
                "historical_rank": int(float(str(value(rows, row, 18)))),
            }

        records.append({
            "row": row,
            "source": source_id,
            "plan_key": base["plan_key"],
            "name": name,
            "manufacturer": manufacturer,
            "origin_location": location,
            "lifecycle_status": status,
            "base_lifecycle_status": base["lifecycle_status"],
            "test": test,
        })

    if mapped_sources != primary_sources or len(mapped_sources) != 166:
        raise RuntimeError("The refreshed workbook did not map one-to-one to all 166 Primärliste sources.")

    image_files: dict[str, Path] = {}
    for path in sorted(image_directory.glob("*.webp")):
        if re.fullmatch(r"[a-f0-9]{64}\.webp", path.name) is None:
            raise RuntimeError(f"Unexpected image filename: {path.name}")
        image_files[path.stem] = path
    if len(image_files) != 186:
        raise RuntimeError("Expected exactly 186 normalized replacement images.")

    image_updates = []
    photo_needed_plan_keys = []
    for drink in base_drinks:
        images = drink.get("images", [])
        match = next((image_files.get(str(image.get("sha256"))) for image in images if image_files.get(str(image.get("sha256"))) is not None), None)
        if match is None:
            photo_needed_plan_keys.append(drink["plan_key"])
            continue
        data = match.read_bytes()
        detected, width, height = webp_dimensions(data)
        if detected != "webp" or width != IMAGE_WIDTH or height != IMAGE_HEIGHT:
            raise RuntimeError(f"Replacement image is not a valid {IMAGE_WIDTH}×{IMAGE_HEIGHT} WebP: {match.name}")
        image_updates.append({
            "plan_key": drink["plan_key"],
            "source_path": relative_or_absolute(match),
            "source_sha256": hashlib.sha256(data).hexdigest(),
            "storage_path": f"admin/{IMAGE_WIDTH}x{IMAGE_HEIGHT}/{match.name}",
            "mime_type": "image/webp",
            "width": IMAGE_WIDTH,
            "height": IMAGE_HEIGHT,
        })

    if len(image_updates) != 186 or len(photo_needed_plan_keys) != 10:
        raise RuntimeError("Replacement image coverage must be exactly 186 updated and 10 flagged drinks.")

    database_records = [{
        "plan_key": drink["plan_key"],
        "name": drink["name"],
        "manufacturer": drink.get("manufacturer"),
        "origin_location": drink.get("origin_location"),
        "lifecycle_status": drink["lifecycle_status"],
        "expected_test_count": len(drink.get("tests", [])),
    } for drink in base_drinks]

    workbook_hash_after = sha256_file(workbook)
    if workbook_hash_before != workbook_hash_after:
        raise RuntimeError("The source workbook changed during read-only planning.")

    counts = {
        "database_drinks": len(database_records),
        "primary_records": len(records),
        "lifecycle": {status: sum(1 for record in records if record["lifecycle_status"] == status) for status in ("identified", "acquired", "tested")},
        "completed_tests": sum(record["test"] is not None for record in records),
        "newly_completed_tests": sum(record["test"] is not None and record["base_lifecycle_status"] != "tested" for record in records),
        "replacement_images": len(image_updates),
        "photo_needed": len(photo_needed_plan_keys),
    }
    plan = {
        "schema_version": 1,
        "workbook": {"path": relative_or_absolute(workbook), "sha256": workbook_hash_before, "sheet": "Spezi Test", "range": "A1:X167"},
        "base_plan": {"path": relative_or_absolute(base_plan_path), "sha256": sha256_file(base_plan_path), "run_id": base_plan["run_id"]},
        "image_directory": relative_or_absolute(image_directory),
        "counts": counts,
        "database_records": database_records,
        "primary_records": records,
        "image_updates": image_updates,
        "photo_needed_plan_keys": sorted(photo_needed_plan_keys),
        "corrections": [
            "Preserved the reviewed Pyraser B/C/D identity-column correction.",
            f"Mapped the corrected name '{NEW_HALLER_NAME}' to the existing '{OLD_HALLER_NAME}' record.",
            "Ignored formula artifacts in the historical time/duration columns.",
            "Kept procurement-only records unchanged.",
        ],
    }
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(json.dumps(plan, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(json.dumps({"plan": relative_or_absolute(output), "counts": counts}, ensure_ascii=False, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
