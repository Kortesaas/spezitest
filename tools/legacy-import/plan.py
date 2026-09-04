#!/usr/bin/env python3
"""Build a deterministic, read-only Spezitest legacy import plan."""

from __future__ import annotations

import argparse
import hashlib
import importlib.util
import json
import shutil
import tempfile
from collections import Counter, defaultdict
from decimal import Decimal, ROUND_HALF_UP
from pathlib import Path
from typing import Any


ROOT = Path(__file__).resolve().parents[2]
DEFAULT_OUTPUT = ROOT / "var/legacy-import-output/current"
AUDIT_SCRIPT = ROOT / "tools/legacy-audit/audit.py"
SCHEMA_VERSION = 1
IMPORTER_VERSION = "packet-6-v1"

EXACT_PAIRS = ((110, 7), (111, 17), (114, 3), (123, 27))
FUZZY_PAIRS = ((59, 17), (76, 17), (83, 12), (84, 31), (102, 3))
TESTER_COLUMNS = {
    "manu": (5, 9, 13),
    "fabi": (6, 10, 14),
    "schorsch": (7, 11, 15),
}
STATUS_PRIORITY = {"identified": 1, "acquired": 2, "tested": 3}


def load_audit_module() -> Any:
    spec = importlib.util.spec_from_file_location("spezitest_legacy_audit", AUDIT_SCRIPT)
    if spec is None or spec.loader is None:
        raise RuntimeError("Could not load the reviewed legacy audit parser.")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for block in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(block)
    return digest.hexdigest()


def clean_text(value: Any) -> str | None:
    if value in (None, ""):
        return None
    cleaned = " ".join(str(value).strip().split())
    return cleaned or None


def cell_value(rows: dict[int, dict[int, dict[str, Any]]], row: int, column: int) -> Any:
    return rows.get(row, {}).get(column, {}).get("value")


def decimal_value(value: Any) -> str:
    """Normalize Excel's serialized binary-double noise to 15 significant digits."""
    if value in (None, ""):
        raise ValueError("Expected a numeric workbook value.")
    normalized = format(float(str(value)), ".15g")
    if "e" in normalized.lower():
        normalized = format(Decimal(normalized), "f")
    return normalized.rstrip("0").rstrip(".") if "." in normalized else normalized


def excel_time(value: Any) -> str | None:
    if value in (None, ""):
        return None
    seconds = int((Decimal(str(value)) * Decimal(86400)).quantize(Decimal("1"), rounding=ROUND_HALF_UP)) % 86400
    return f"{seconds // 3600:02d}:{(seconds % 3600) // 60:02d}:{seconds % 60:02d}"


def source_id(workbook: str, row: int) -> str:
    return f"{workbook}:{row}"


def display_path(path: Path) -> str:
    return str(path.relative_to(ROOT)) if path.is_relative_to(ROOT) else str(path)


class UnionFind:
    def __init__(self, values: list[str]) -> None:
        self.parent = {value: value for value in values}

    def find(self, value: str) -> str:
        parent = self.parent[value]
        if parent != value:
            self.parent[value] = self.find(parent)
        return self.parent[value]

    def union(self, left: str, right: str) -> None:
        left_root = self.find(left)
        right_root = self.find(right)
        if left_root != right_root:
            self.parent[right_root] = left_root


def image_evidence(image_by_source: dict[str, dict[str, Any]], identifier: str) -> str | None:
    image = image_by_source.get(identifier)
    return None if image is None else str(image["sha256"])


def build_review_template(
    run_id: str,
    base_records: dict[str, dict[str, Any]],
    image_by_source: dict[str, dict[str, Any]],
) -> dict[str, Any]:
    candidates = []
    for prima_row, beschaffung_row in FUZZY_PAIRS:
        left_id = source_id("primaerliste", prima_row)
        right_id = source_id("beschaffungsliste", beschaffung_row)
        left = base_records[left_id]
        right = base_records[right_id]
        candidates.append({
            "id": f"fuzzy-p{prima_row}-b{beschaffung_row}",
            "left": {
                "source": left_id,
                "name": left["name"],
                "manufacturer": left["manufacturer"],
                "location": left["origin_location"],
                "image_sha256": image_evidence(image_by_source, left_id),
            },
            "right": {
                "source": right_id,
                "name": right["name"],
                "manufacturer": right["manufacturer"],
                "location": right["origin_location"],
                "image_sha256": image_evidence(image_by_source, right_id),
            },
            "decision": "UNRESOLVED",
            "canonical_source": None,
            "operator_note": "Set decision to SAME_PRODUCT or DIFFERENT_PRODUCTS. SAME_PRODUCT also requires canonical_source.",
        })
    return {
        "schema_version": SCHEMA_VERSION,
        "run_id": run_id,
        "allowed_decisions": ["UNRESOLVED", "SAME_PRODUCT", "DIFFERENT_PRODUCTS"],
        "candidates": candidates,
    }


def load_or_create_decisions(path: Path, template: dict[str, Any]) -> dict[str, Any]:
    if not path.exists():
        path.write_text(json.dumps(template, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
        return template
    loaded = json.loads(path.read_text(encoding="utf-8"))
    if loaded.get("schema_version") != SCHEMA_VERSION or loaded.get("run_id") != template["run_id"]:
        raise RuntimeError("Duplicate decision file does not belong to this source/importer version.")
    expected_ids = [item["id"] for item in template["candidates"]]
    actual_ids = [item.get("id") for item in loaded.get("candidates", [])]
    if actual_ids != expected_ids:
        raise RuntimeError("Duplicate decision candidates differ from the audited candidate set.")
    for candidate, expected in zip(loaded["candidates"], template["candidates"], strict=True):
        if candidate.get("left") != expected["left"] or candidate.get("right") != expected["right"]:
            raise RuntimeError(f"Duplicate review evidence was modified for {candidate.get('id')}.")
        decision = candidate.get("decision")
        if decision not in template["allowed_decisions"]:
            raise RuntimeError(f"Invalid duplicate decision for {candidate.get('id')}.")
        if decision == "SAME_PRODUCT":
            allowed_sources = {candidate["left"]["source"], candidate["right"]["source"]}
            if candidate.get("canonical_source") not in allowed_sources:
                raise RuntimeError(f"SAME_PRODUCT for {candidate['id']} requires canonical_source to select one side.")
    return loaded


def record_from_prima(
    rows: dict[int, dict[int, dict[str, Any]]],
    row: int,
    audit: Any,
) -> dict[str, Any]:
    if row == 151:
        name = clean_text(cell_value(rows, row, 2))
        manufacturer = clean_text(cell_value(rows, row, 3))
        location = clean_text(cell_value(rows, row, 4))
    else:
        manufacturer = clean_text(cell_value(rows, row, 2))
        location = clean_text(cell_value(rows, row, 3))
        name = clean_text(cell_value(rows, row, 4))
    if name is None:
        raise RuntimeError(f"Primärliste row {row} has no usable name.")
    cells = rows[row]
    if row <= 109:
        status = "tested"
    elif all(audit.is_solid_red(cells.get(column)) for column in (2, 3, 4)):
        status = "identified"
    else:
        status = "acquired"
    tests = []
    if status == "tested":
        ratings = {}
        for tester, columns in TESTER_COLUMNS.items():
            ratings[tester] = {
                "optik": decimal_value(cell_value(rows, row, columns[0])),
                "sueffigkeit": decimal_value(cell_value(rows, row, columns[1])),
                "geschmack": decimal_value(cell_value(rows, row, columns[2])),
            }
        time_cell = cells.get(22, {})
        duration_cell = cells.get(23, {})
        has_artifact_formula = bool(time_cell.get("formula") or duration_cell.get("formula"))
        tests.append({
            "source": source_id("primaerliste", row),
            "status": "completed",
            "price_amount": decimal_value(cell_value(rows, row, 19)),
            "recorded_time": None if has_artifact_formula else excel_time(time_cell.get("value")),
            "duration_value": None if has_artifact_formula or duration_cell.get("value") in (None, "") else int(float(str(duration_cell["value"]))),
            "stream_reference": int(float(str(cell_value(rows, row, 24)))),
            "ratings": ratings,
            "historical": {
                "gesamt": decimal_value(cell_value(rows, row, 17)),
                "rank": int(float(str(cell_value(rows, row, 18)))),
            },
        })
    return {
        "source": source_id("primaerliste", row),
        "workbook": "primaerliste",
        "row": row,
        "name": name,
        "manufacturer": manufacturer,
        "origin_location": location,
        "origin_region": None,
        "notes": None,
        "lifecycle_status": status,
        "tests": tests,
    }


def record_from_beschaffung(rows: dict[int, dict[int, dict[str, Any]]], row: int) -> dict[str, Any]:
    name = clean_text(cell_value(rows, row, 2))
    if name is None:
        raise RuntimeError(f"Beschaffungsliste row {row} has no usable name.")
    return {
        "source": source_id("beschaffungsliste", row),
        "workbook": "beschaffungsliste",
        "row": row,
        "name": name,
        "manufacturer": clean_text(cell_value(rows, row, 3)),
        "origin_location": clean_text(cell_value(rows, row, 4)),
        "origin_region": clean_text(cell_value(rows, row, 6)),
        "notes": clean_text(cell_value(rows, row, 5)),
        "lifecycle_status": "identified",
        "tests": [],
    }


def canonical_record(group: list[dict[str, Any]], requested: list[str]) -> dict[str, Any]:
    unique_requested = sorted(set(requested))
    if len(unique_requested) > 1:
        raise RuntimeError("Conflicting canonical_source choices exist in a merged duplicate group.")
    if unique_requested:
        return next(item for item in group if item["source"] == unique_requested[0])
    return sorted(
        group,
        key=lambda item: (-STATUS_PRIORITY[item["lifecycle_status"]], item["workbook"] != "primaerliste", item["row"]),
    )[0]


def merge_records(
    base_records: dict[str, dict[str, Any]],
    decisions: dict[str, Any],
    images_by_source: dict[str, dict[str, Any]],
) -> list[dict[str, Any]]:
    union = UnionFind(list(base_records))
    for prima_row, beschaffung_row in EXACT_PAIRS:
        union.union(source_id("primaerliste", prima_row), source_id("beschaffungsliste", beschaffung_row))
    for candidate in decisions["candidates"]:
        if candidate["decision"] == "SAME_PRODUCT":
            left = candidate["left"]["source"]
            right = candidate["right"]["source"]
            union.union(left, right)

    groups: dict[str, list[dict[str, Any]]] = defaultdict(list)
    for identifier, record in base_records.items():
        groups[union.find(identifier)].append(record)

    drinks = []
    for group in groups.values():
        all_sources = {item["source"] for item in group}
        requested = []
        for candidate in decisions["candidates"]:
            if candidate["decision"] == "SAME_PRODUCT" and {candidate["left"]["source"], candidate["right"]["source"]}.issubset(all_sources):
                requested.append(candidate["canonical_source"])
        canonical = canonical_record(group, requested)
        ordered = [canonical] + [item for item in sorted(group, key=lambda item: item["source"]) if item is not canonical]

        image_groups: dict[str, dict[str, Any]] = {}
        for item in ordered:
            image = images_by_source.get(item["source"])
            if image is None:
                continue
            digest = image["sha256"]
            if digest not in image_groups:
                image_groups[digest] = {
                    "sha256": digest,
                    "mime_type": image["mime_type"],
                    "width": image["width"],
                    "height": image["height"],
                    "staged_path": image["staged_path"],
                    "sources": [],
                }
            image_groups[digest]["sources"].append(item["source"])

        def first_optional(field: str) -> str | None:
            return next((item[field] for item in ordered if item[field] is not None), None)

        drinks.append({
            "plan_key": canonical["source"],
            "name": canonical["name"],
            "lifecycle_status": max((item["lifecycle_status"] for item in group), key=STATUS_PRIORITY.__getitem__),
            "manufacturer": first_optional("manufacturer"),
            "origin_location": first_optional("origin_location"),
            "origin_region": first_optional("origin_region"),
            "notes": first_optional("notes"),
            "sources": [{key: item[key] for key in ("source", "workbook", "row", "name", "manufacturer", "origin_location", "origin_region", "notes", "lifecycle_status")} for item in ordered],
            "tests": sorted([test for item in group for test in item["tests"]], key=lambda test: test["source"]),
            "images": list(image_groups.values()),
        })
    return sorted(drinks, key=lambda drink: drink["plan_key"])


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output", type=Path, default=DEFAULT_OUTPUT)
    parser.add_argument("--decisions", type=Path)
    args = parser.parse_args()
    output = args.output.resolve()
    output.mkdir(parents=True, exist_ok=True)

    audit = load_audit_module()
    source_paths = {key: (ROOT / config["path"]).resolve() for key, config in audit.SOURCES.items()}
    missing = [str(path) for path in source_paths.values() if not path.is_file()]
    if missing:
        parser.error("Missing required source workbook(s): " + ", ".join(missing))

    hashes_before = {key: sha256_file(path) for key, path in source_paths.items()}
    run_material = IMPORTER_VERSION + "\n" + "\n".join(f"{key}:{hashes_before[key]}" for key in sorted(hashes_before))
    run_id = hashlib.sha256(run_material.encode("utf-8")).hexdigest()

    with tempfile.TemporaryDirectory(prefix="spezitest-legacy-plan-") as temporary:
        workbooks: dict[str, Any] = {}
        rows_by_key: dict[str, Any] = {}
        all_images: list[dict[str, Any]] = []
        for key, config in audit.SOURCES.items():
            absolute_config = {**config, "path": source_paths[key]}
            workbook, images, rows = audit.inspect_workbook(key, absolute_config, Path(temporary))
            workbooks[key] = workbook
            rows_by_key[key] = rows
            all_images.extend(images)

        image_output = output / "images"
        image_output.mkdir(parents=True, exist_ok=True)
        images_by_source: dict[str, dict[str, Any]] = {}
        for image in all_images:
            if image["detected_format"] not in {"png", "jpeg"} or image["width"] is None or image["height"] is None:
                raise RuntimeError(f"Unsupported or unreadable legacy image at {image['workbook']} row {image['row']}.")
            extension = "jpg" if image["detected_format"] == "jpeg" else "png"
            filename = f"{image['sha256']}.{extension}"
            destination = image_output / filename
            source = Path(image["extracted_path"])
            if destination.exists() and sha256_file(destination) != image["sha256"]:
                raise RuntimeError(f"Existing staged image does not match its content hash: {filename}")
            if not destination.exists():
                shutil.copyfile(source, destination)
            images_by_source[source_id(image["workbook"], image["row"])] = {
                "sha256": image["sha256"],
                "mime_type": "image/jpeg" if extension == "jpg" else "image/png",
                "width": image["width"],
                "height": image["height"],
                "staged_path": f"images/{filename}",
            }

    base_records: dict[str, dict[str, Any]] = {}
    for row in range(2, 168):
        record = record_from_prima(rows_by_key["primaerliste"], row, audit)
        base_records[record["source"]] = record
    for row in range(2, 36):
        record = record_from_beschaffung(rows_by_key["beschaffungsliste"], row)
        base_records[record["source"]] = record

    review_template = build_review_template(run_id, base_records, images_by_source)
    decisions_path = (args.decisions or (output / "duplicate-decisions.json")).resolve()
    decisions_path.parent.mkdir(parents=True, exist_ok=True)
    decisions = load_or_create_decisions(decisions_path, review_template)
    unresolved = [item["id"] for item in decisions["candidates"] if item["decision"] == "UNRESOLVED"]

    for prima_row, beschaffung_row in EXACT_PAIRS:
        left = images_by_source[source_id("primaerliste", prima_row)]
        right = images_by_source[source_id("beschaffungsliste", beschaffung_row)]
        if left["sha256"] != right["sha256"]:
            raise RuntimeError(f"Audited exact duplicate image mismatch for rows {prima_row}/{beschaffung_row}.")

    drinks = merge_records(base_records, decisions, images_by_source)
    lifecycle_counts = dict(Counter(drink["lifecycle_status"] for drink in drinks))
    test_count = sum(len(drink["tests"]) for drink in drinks)
    rating_count = sum(len(test["ratings"]) for drink in drinks for test in drink["tests"])
    attached_images = sum(len(drink["images"]) for drink in drinks)
    untested_prices = [
        {
            "source": source_id("primaerliste", row),
            "name": base_records[source_id("primaerliste", row)]["name"],
            "price": decimal_value(cell_value(rows_by_key["primaerliste"], row, 19)),
            "reason": "Untested source price has no verified permanent placement; no test row was invented.",
        }
        for row in range(110, 168)
    ]
    exact_merges = [{
        "primaerliste": source_id("primaerliste", prima_row),
        "beschaffungsliste": source_id("beschaffungsliste", beschaffung_row),
        "image_sha256": images_by_source[source_id("primaerliste", prima_row)]["sha256"],
        "basis": "Audited same name, manufacturer, location, and byte-identical image.",
    } for prima_row, beschaffung_row in EXACT_PAIRS]
    corrections = [
        {"id": "primaerliste-row-151-identity-shift", "detail": "Mapped B151 to name, C151 to manufacturer, and D151 to origin_location."},
        {"id": "primaerliste-stray-red-i167-k167", "detail": "Ignored stray red rating-cell fills; lifecycle uses red identity cells B:D only."},
        {"id": "inflated-ranges", "detail": "Ignored rows beyond Primärliste 167 and Beschaffungsliste 35 plus oversized table/chart/formula ranges."},
        {"id": "stray-vw-formulas", "detail": "Did not import V/W formula artifacts at Primärliste rows 58, 59, 83, and 122 as time/duration facts."},
        {"id": "untested-derived-values", "detail": "Did not import formula-derived Gesamt/rank/price-performance values for untested rows."},
        {"id": "excel-double-price-normalization", "detail": "Removed serialized binary-double noise at Excel's 15-significant-digit precision without display rounding."},
    ]
    hashes_after = {key: sha256_file(path) for key, path in source_paths.items()}
    if hashes_before != hashes_after:
        raise RuntimeError("Source workbook hash changed during read-only plan construction.")

    plan = {
        "schema_version": SCHEMA_VERSION,
        "importer_version": IMPORTER_VERSION,
        "run_id": run_id,
        "source_integrity_verified": True,
        "sources": {
            key: {
                "path": str(path.relative_to(ROOT)),
                "sha256_before": hashes_before[key],
                "sha256_after": hashes_after[key],
                "rows": workbooks[key]["record_count"],
                "images": workbooks[key]["mapped_image_count"],
            }
            for key, path in source_paths.items()
        },
        "apply_ready": not unresolved,
        "unresolved_review_ids": unresolved,
        "counts": {
            "source_rows": {
                "primaerliste": workbooks["primaerliste"]["record_count"],
                "beschaffungsliste": workbooks["beschaffungsliste"]["record_count"],
                "total": sum(workbook["record_count"] for workbook in workbooks.values()),
            },
            "drinks": len(drinks),
            "lifecycle": {
                "identified": lifecycle_counts.get("identified", 0),
                "acquired": lifecycle_counts.get("acquired", 0),
                "tested": lifecycle_counts.get("tested", 0),
            },
            "tests": test_count,
            "ratings": rating_count,
            "images_extracted": len(all_images),
            "unique_image_files": len({image["sha256"] for image in all_images}),
            "images_attached": attached_images,
            "images_missing": len(drinks) - sum(1 for drink in drinks if drink["images"]),
            "image_deduplications": len(all_images) - len({image["sha256"] for image in all_images}),
            "prices_imported": test_count,
            "untested_prices_deferred": len(untested_prices),
            "exact_duplicate_merges": len(exact_merges),
            "fuzzy_candidates": len(decisions["candidates"]),
            "fuzzy_unresolved": len(unresolved),
        },
        "exact_duplicate_merges": exact_merges,
        "fuzzy_candidates": decisions["candidates"],
        "corrections": corrections,
        "deferred_values": untested_prices,
        "missing_images": [{"source": "primaerliste:85", "name": "Endlich Cola-Mix"}],
        "drinks": drinks,
    }
    plan_path = output / "import-plan.json"
    plan_path.write_text(json.dumps(plan, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(json.dumps({
        "plan": display_path(plan_path),
        "decisions": display_path(decisions_path),
        "run_id": run_id,
        "apply_ready": plan["apply_ready"],
        "counts": plan["counts"],
    }, ensure_ascii=False, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
