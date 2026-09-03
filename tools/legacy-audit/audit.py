#!/usr/bin/env python3
"""Read-only forensic audit of the two legacy Spezitest OOXML workbooks."""

from __future__ import annotations

import argparse
import hashlib
import json
import posixpath
import re
import unicodedata
from collections import Counter, defaultdict
from difflib import SequenceMatcher
from io import BytesIO
from pathlib import Path
from typing import Any
from xml.etree import ElementTree as ET
from zipfile import ZipFile


MAIN_NS = "http://schemas.openxmlformats.org/spreadsheetml/2006/main"
REL_NS = "http://schemas.openxmlformats.org/officeDocument/2006/relationships"
PACKAGE_REL_NS = "http://schemas.openxmlformats.org/package/2006/relationships"
DRAWING_NS = "http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing"
DRAWING_MAIN_NS = "http://schemas.openxmlformats.org/drawingml/2006/main"
NS = {"m": MAIN_NS, "r": REL_NS, "pr": PACKAGE_REL_NS}

SOURCES = {
    "primaerliste": {
        "path": Path("var/legacy-import/primaerliste.xlsx"),
        "sheet": "Spezi Test",
        "identity_columns": (2, 3, 4),
        "name_column": 4,
        "manufacturer_column": 2,
    },
    "beschaffungsliste": {
        "path": Path("var/legacy-import/beschaffungsliste.xlsx"),
        "sheet": "Tabellenblatt1",
        "identity_columns": (2, 3, 4, 5, 6),
        "name_column": 2,
        "manufacturer_column": 3,
    },
}


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for block in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(block)
    return digest.hexdigest()


def local_name(tag: str) -> str:
    return tag.rsplit("}", 1)[-1]


def column_number(reference: str) -> int:
    letters = re.match(r"[A-Z]+", reference)
    if letters is None:
        raise ValueError(f"Invalid cell reference: {reference}")
    result = 0
    for char in letters.group(0):
        result = result * 26 + ord(char) - 64
    return result


def row_number(reference: str) -> int:
    match = re.search(r"\d+$", reference)
    if match is None:
        raise ValueError(f"Invalid cell reference: {reference}")
    return int(match.group(0))


def shared_strings(archive: ZipFile) -> list[str]:
    if "xl/sharedStrings.xml" not in archive.namelist():
        return []
    root = ET.fromstring(archive.read("xl/sharedStrings.xml"))
    return ["".join(node.text or "" for node in item.iter(f"{{{MAIN_NS}}}t")) for item in root]


def relationship_map(archive: ZipFile, path: str) -> dict[str, str]:
    if path not in archive.namelist():
        return {}
    root = ET.fromstring(archive.read(path))
    return {item.attrib["Id"]: item.attrib["Target"] for item in root}


def package_path(owner: str, target: str) -> str:
    return posixpath.normpath(posixpath.join(posixpath.dirname(owner), target.lstrip("/")))


def workbook_parts(archive: ZipFile) -> tuple[list[dict[str, Any]], list[dict[str, Any]]]:
    workbook = ET.fromstring(archive.read("xl/workbook.xml"))
    rels = relationship_map(archive, "xl/_rels/workbook.xml.rels")
    sheets: list[dict[str, Any]] = []
    chartsheets: list[dict[str, Any]] = []
    for sheet in workbook.findall("m:sheets/m:sheet", NS):
        rid = sheet.attrib[f"{{{REL_NS}}}id"]
        target = package_path("xl/workbook.xml", rels[rid])
        item = {
            "name": sheet.attrib["name"],
            "state": sheet.attrib.get("state", "visible"),
            "part": target,
        }
        if "/chartsheets/" in target:
            chartsheets.append(item)
        else:
            sheets.append(item)
    return sheets, chartsheets


def styles(archive: ZipFile) -> dict[str, Any]:
    root = ET.fromstring(archive.read("xl/styles.xml"))
    builtins = {
        0: "General", 1: "0", 2: "0.00", 3: "#,##0", 4: "#,##0.00",
        9: "0%", 10: "0.00%",
        14: "mm-dd-yy", 20: "h:mm", 21: "h:mm:ss", 22: "m/d/yy h:mm",
    }
    formats = dict(builtins)
    for item in root.findall("m:numFmts/m:numFmt", NS):
        formats[int(item.attrib["numFmtId"])] = item.attrib["formatCode"]
    fills: list[dict[str, Any]] = []
    for fill in root.findall("m:fills/m:fill", NS):
        pattern = fill.find("m:patternFill", NS)
        fg = pattern.find("m:fgColor", NS) if pattern is not None else None
        fills.append({
            "pattern": pattern.attrib.get("patternType") if pattern is not None else None,
            "fg": dict(fg.attrib) if fg is not None else None,
        })
    cell_xfs: list[dict[str, Any]] = []
    for xf in root.findall("m:cellXfs/m:xf", NS):
        num_fmt_id = int(xf.attrib.get("numFmtId", 0))
        cell_xfs.append({
            "fill_id": int(xf.attrib.get("fillId", 0)),
            "font_id": int(xf.attrib.get("fontId", 0)),
            "number_format": formats.get(num_fmt_id, f"numFmtId:{num_fmt_id}"),
        })
    return {"fills": fills, "cell_xfs": cell_xfs}


def cell_value(cell: ET.Element, strings: list[str]) -> Any:
    value = cell.findtext(f"{{{MAIN_NS}}}v")
    data_type = cell.attrib.get("t")
    if data_type == "s" and value is not None:
        return strings[int(value)]
    if data_type == "inlineStr":
        return "".join(node.text or "" for node in cell.iter(f"{{{MAIN_NS}}}t"))
    if data_type == "b" and value is not None:
        return value == "1"
    return value


def inspect_sheet(
    archive: ZipFile,
    part: str,
    strings: list[str],
    style_data: dict[str, Any],
    identity_columns: tuple[int, ...],
) -> tuple[dict[str, Any], dict[int, dict[int, dict[str, Any]]]]:
    root = ET.fromstring(archive.read(part))
    dimension = root.find("m:dimension", NS)
    rows: dict[int, dict[int, dict[str, Any]]] = {}
    formula_bases: list[dict[str, Any]] = []
    formula_counts: Counter[str] = Counter()
    cell_count = 0
    max_identity_row = 1
    max_value_row = 1
    max_value_column = 1
    for row in root.findall("m:sheetData/m:row", NS):
        number = int(row.attrib["r"])
        selected: dict[int, dict[str, Any]] = {}
        for cell in row.findall("m:c", NS):
            cell_count += 1
            reference = cell.attrib["r"]
            col = column_number(reference)
            style_id = int(cell.attrib.get("s", 0))
            formula = cell.find("m:f", NS)
            value = cell_value(cell, strings)
            if formula is not None:
                formula_counts[re.sub(r"(?<=[A-Z])\$?\d+", "#", formula.text or "<shared>")] += 1
                if formula.text:
                    formula_bases.append({
                        "cell": reference,
                        "formula": formula.text,
                        "shared_range": formula.attrib.get("ref"),
                        "shared_index": formula.attrib.get("si"),
                    })
            if value not in (None, ""):
                max_value_row = max(max_value_row, number)
                max_value_column = max(max_value_column, col)
                if col in identity_columns:
                    max_identity_row = max(max_identity_row, number)
            if number <= 500 and col <= 30:
                xf = style_data["cell_xfs"][style_id]
                fill = style_data["fills"][xf["fill_id"]]
                selected[col] = {
                    "reference": reference,
                    "value": value,
                    "cached_raw": cell.findtext(f"{{{MAIN_NS}}}v"),
                    "type": cell.attrib.get("t", "n"),
                    "style_id": style_id,
                    "number_format": xf["number_format"],
                    "fill": fill,
                    "value_metadata": cell.attrib.get("vm"),
                    "formula": formula.text if formula is not None and formula.text else None,
                    "shared_formula_index": formula.attrib.get("si") if formula is not None else None,
                }
        if selected:
            rows[number] = selected
    hidden_rows = [
        int(row.attrib["r"])
        for row in root.findall("m:sheetData/m:row", NS)
        if row.attrib.get("hidden") == "1"
    ]
    columns = []
    for col in root.findall("m:cols/m:col", NS):
        columns.append({
            "min": int(col.attrib["min"]),
            "max": int(col.attrib["max"]),
            "width": col.attrib.get("width"),
            "hidden": col.attrib.get("hidden") == "1",
            "outline_level": int(col.attrib.get("outlineLevel", 0)),
            "collapsed": col.attrib.get("collapsed") == "1",
        })
    merges = [item.attrib["ref"] for item in root.findall("m:mergeCells/m:mergeCell", NS)]
    panes = [dict(item.attrib) for item in root.findall("m:sheetViews/m:sheetView/m:pane", NS)]
    conditional = len(root.findall("m:conditionalFormatting", NS))
    validations = sum(int(item.attrib.get("count", 0)) for item in root.findall("m:dataValidations", NS))
    hyperlinks = len(root.findall("m:hyperlinks/m:hyperlink", NS))
    tables = []
    sheet_rels_path = posixpath.join(
        posixpath.dirname(part), "_rels", posixpath.basename(part) + ".rels"
    )
    rels = relationship_map(archive, sheet_rels_path)
    for table_part in root.findall("m:tableParts/m:tablePart", NS):
        target = package_path(part, rels[table_part.attrib[f"{{{REL_NS}}}id"]])
        table = ET.fromstring(archive.read(target))
        tables.append({"name": table.attrib.get("name"), "display_name": table.attrib.get("displayName"), "range": table.attrib.get("ref")})
    return ({
        "declared_dimension": dimension.attrib.get("ref") if dimension is not None else None,
        "cell_count": cell_count,
        "last_identity_row": max_identity_row,
        "last_nonblank_cached_value_row": max_value_row,
        "last_nonblank_cached_value_column": max_value_column,
        "formula_counts": dict(formula_counts),
        "formula_bases": formula_bases,
        "hidden_row_count": len(hidden_rows),
        "hidden_row_ranges": compact_ranges(hidden_rows),
        "columns": columns,
        "merged_ranges": merges,
        "panes": panes,
        "conditional_formatting_rule_groups": conditional,
        "data_validation_rule_count": validations,
        "hyperlink_count": hyperlinks,
        "tables": tables,
    }, rows)


def compact_ranges(values: list[int]) -> list[str]:
    if not values:
        return []
    ranges: list[str] = []
    start = previous = values[0]
    for value in values[1:]:
        if value != previous + 1:
            ranges.append(str(start) if start == previous else f"{start}-{previous}")
            start = value
        previous = value
    ranges.append(str(start) if start == previous else f"{start}-{previous}")
    return ranges


def image_dimensions(data: bytes) -> tuple[str, int | None, int | None]:
    if data.startswith(b"\x89PNG\r\n\x1a\n") and len(data) >= 24:
        return "png", int.from_bytes(data[16:20], "big"), int.from_bytes(data[20:24], "big")
    if data.startswith(b"\xff\xd8"):
        stream = BytesIO(data)
        stream.read(2)
        while True:
            byte = stream.read(1)
            if not byte:
                break
            if byte != b"\xff":
                continue
            marker = stream.read(1)
            while marker == b"\xff":
                marker = stream.read(1)
            if marker in {bytes([x]) for x in range(0xC0, 0xC4)} | {bytes([x]) for x in range(0xC5, 0xC8)} | {bytes([x]) for x in range(0xC9, 0xCC)} | {bytes([x]) for x in range(0xCD, 0xD0)}:
                stream.read(3)
                height = int.from_bytes(stream.read(2), "big")
                width = int.from_bytes(stream.read(2), "big")
                return "jpeg", width, height
            if marker in (b"\xd8", b"\xd9"):
                continue
            length = int.from_bytes(stream.read(2), "big")
            if length < 2:
                break
            stream.seek(length - 2, 1)
        return "jpeg", None, None
    return "unknown", None, None


def extract_image(
    archive: ZipFile,
    part: str,
    output_dir: Path,
    workbook_key: str,
    row: int,
    name: str,
    representation: str,
    source_id: str,
) -> dict[str, Any]:
    data = archive.read(part)
    image_type, width, height = image_dimensions(data)
    digest = hashlib.sha256(data).hexdigest()
    suffix = ".jpg" if image_type == "jpeg" else f".{image_type}"
    destination = output_dir / workbook_key / f"row-{row:03d}{suffix}"
    destination.parent.mkdir(parents=True, exist_ok=True)
    destination.write_bytes(data)
    return {
        "workbook": workbook_key,
        "row": row,
        "drink_name": name,
        "representation": representation,
        "source_identifier": source_id,
        "package_part": part,
        "detected_format": image_type,
        "width": width,
        "height": height,
        "sha256": digest,
        "extraction_status": "extracted",
        "extracted_path": destination.as_posix(),
    }


def rich_images(
    archive: ZipFile,
    rows: dict[int, dict[int, dict[str, Any]]],
    output_dir: Path,
) -> list[dict[str, Any]]:
    required = {
        "xl/metadata.xml", "xl/richData/rdrichvalue.xml",
        "xl/richData/_rels/richValueRel.xml.rels",
    }
    if not required.issubset(archive.namelist()):
        return []
    metadata = ET.fromstring(archive.read("xl/metadata.xml"))
    value_metadata = []
    for block in metadata.findall(".//m:valueMetadata/m:bk", NS):
        record = block.find("m:rc", NS)
        value_metadata.append(int(record.attrib["v"]))
    rich_root = ET.fromstring(archive.read("xl/richData/rdrichvalue.xml"))
    rich_values = [[child.text for child in list(item)] for item in list(rich_root)]
    rels = relationship_map(archive, "xl/richData/_rels/richValueRel.xml.rels")
    images = []
    for row, cells in sorted(rows.items()):
        image_cell = cells.get(1)
        if not image_cell or not image_cell["value_metadata"]:
            continue
        metadata_index = int(image_cell["value_metadata"]) - 1
        rich_index = value_metadata[metadata_index]
        relation_index = int(rich_values[rich_index][0]) + 1
        rid = f"rId{relation_index}"
        target = package_path("xl/richData/richValueRel.xml", rels[rid])
        images.append(extract_image(
            archive, target, output_dir, "primaerliste", row,
            str(cells.get(4, {}).get("value", "")), "in-cell rich-data", rid,
        ))
    return images


def drawing_images(
    archive: ZipFile,
    sheet_part: str,
    rows: dict[int, dict[int, dict[str, Any]]],
    output_dir: Path,
) -> list[dict[str, Any]]:
    sheet_root = ET.fromstring(archive.read(sheet_part))
    sheet_rels_path = posixpath.join(posixpath.dirname(sheet_part), "_rels", posixpath.basename(sheet_part) + ".rels")
    sheet_rels = relationship_map(archive, sheet_rels_path)
    drawing = sheet_root.find("m:drawing", NS)
    if drawing is None:
        return []
    drawing_part = package_path(sheet_part, sheet_rels[drawing.attrib[f"{{{REL_NS}}}id"]])
    drawing_root = ET.fromstring(archive.read(drawing_part))
    drawing_rels_path = posixpath.join(posixpath.dirname(drawing_part), "_rels", posixpath.basename(drawing_part) + ".rels")
    drawing_rels = relationship_map(archive, drawing_rels_path)
    images = []
    dns = {"x": DRAWING_NS, "a": DRAWING_MAIN_NS}
    for anchor in list(drawing_root):
        origin = anchor.find("x:from", dns)
        blip = anchor.find(".//a:blip", dns)
        if origin is None or blip is None:
            continue
        row = int(origin.findtext("x:row", namespaces=dns)) + 1
        col = int(origin.findtext("x:col", namespaces=dns)) + 1
        rid = blip.attrib[f"{{{REL_NS}}}embed"]
        target = package_path(drawing_part, drawing_rels[rid])
        images.append(extract_image(
            archive, target, output_dir, "beschaffungsliste", row,
            str(rows.get(row, {}).get(2, {}).get("value", "")),
            "drawing anchor", f"{rid} at row {row}, column {col}",
        ))
    return images


def normalized_exact(value: str) -> str:
    return " ".join(value.casefold().split())


def normalized_fuzzy(value: str) -> str:
    value = unicodedata.normalize("NFKD", value.casefold()).encode("ascii", "ignore").decode()
    value = value.replace("&", " und ").replace("+", " plus ")
    value = re.sub(r"\b(kola|cola)[ -]?(mix|orange|orangen|misch(?:e)?)\b", " colamix ", value)
    value = re.sub(r"\b(limonade|getrank|getraenk|spezi)\b", " ", value)
    return " ".join(re.findall(r"[a-z0-9]+", value))


def record_list(rows: dict[int, dict[int, dict[str, Any]]], name_col: int, manufacturer_col: int, last_row: int) -> list[dict[str, Any]]:
    return [
        {
            "row": row,
            "name": str(rows[row][name_col]["value"]),
            "manufacturer": str(rows[row][manufacturer_col]["value"]),
        }
        for row in range(2, last_row + 1)
        if rows.get(row, {}).get(name_col, {}).get("value") not in (None, "")
    ]


def overlap(prima: list[dict[str, Any]], beschaffung: list[dict[str, Any]]) -> dict[str, Any]:
    exact = []
    fuzzy = []
    exact_pairs: set[tuple[int, int]] = set()
    for left in prima:
        for right in beschaffung:
            if normalized_exact(left["name"]) == normalized_exact(right["name"]):
                exact.append({"primaerliste": left, "beschaffungsliste": right})
                exact_pairs.add((left["row"], right["row"]))
                continue
            left_name = normalized_fuzzy(left["name"])
            right_name = normalized_fuzzy(right["name"])
            similarity = SequenceMatcher(None, left_name, right_name).ratio()
            if similarity >= 0.85:
                fuzzy.append({
                    "primaerliste": left,
                    "beschaffungsliste": right,
                    "name_similarity": round(similarity, 6),
                    "manufacturer_similarity": round(SequenceMatcher(
                        None, normalized_fuzzy(left["manufacturer"]), normalized_fuzzy(right["manufacturer"])
                    ).ratio(), 6),
                    "disposition": "manual review; never auto-merge",
                })
    return {
        "exact_candidates": exact,
        "likely_candidates": fuzzy,
        "primaerliste_rows_without_exact_cross_match": len(prima) - len({pair[0] for pair in exact_pairs}),
        "beschaffungsliste_rows_without_exact_cross_match": len(beschaffung) - len({pair[1] for pair in exact_pairs}),
    }


def is_solid_red(cell: dict[str, Any] | None) -> bool:
    if not cell:
        return False
    fill = cell["fill"]
    return fill.get("pattern") == "solid" and (fill.get("fg") or {}).get("rgb", "").upper().endswith("FF0000")


def lifecycle(rows: dict[int, dict[int, dict[str, Any]]], last_row: int) -> dict[str, Any]:
    tested: list[int] = []
    identified: list[int] = []
    acquired: list[int] = []
    unresolved: list[int] = []
    rating_input_columns = (5, 6, 7, 9, 10, 11, 13, 14, 15)
    for row in range(2, last_row + 1):
        cells = rows[row]
        has_all_inputs = all(cells.get(col, {}).get("value") not in (None, "") for col in rating_input_columns)
        has_total = cells.get(17, {}).get("cached_raw") not in (None, "")
        record_red = all(is_solid_red(cells.get(col)) for col in (2, 3, 4))
        if has_all_inputs and has_total:
            tested.append(row)
        elif record_red:
            identified.append(row)
        elif not has_all_inputs and not has_total:
            acquired.append(row)
        else:
            unresolved.append(row)
    return {
        "tested": {"count": len(tested), "rows": compact_ranges(tested)},
        "acquired": {"count": len(acquired), "rows": compact_ranges(acquired)},
        "identified": {"count": len(identified), "rows": compact_ranges(identified)},
        "unresolved": {"count": len(unresolved), "rows": compact_ranges(unresolved)},
        "red_detection": "solid red fill (FFFF0000) on each identity cell B:D",
        "stray_red_cells_excluded": ["I167:K167"],
    }


def column_profiles(rows: dict[int, dict[int, dict[str, Any]]], last_row: int, max_col: int) -> list[dict[str, Any]]:
    headers = {col: rows.get(1, {}).get(col, {}).get("value") for col in range(1, max_col + 1)}
    profiles = []
    for col in range(1, max_col + 1):
        values = [rows.get(row, {}).get(col, {}).get("value") for row in range(2, last_row + 1)]
        types = Counter("blank" if value in (None, "") else "boolean" if isinstance(value, bool) else "number" if re.fullmatch(r"-?\d+(?:\.\d+)?(?:[Ee][+-]?\d+)?", str(value)) else "text/error" for value in values)
        formats = Counter(rows.get(row, {}).get(col, {}).get("number_format", "General") for row in range(2, last_row + 1))
        formulas = sum(1 for row in range(2, last_row + 1) if rows.get(row, {}).get(col, {}).get("formula") is not None or rows.get(row, {}).get(col, {}).get("shared_formula_index") is not None)
        profiles.append({
            "column": col,
            "header": headers[col],
            "value_types": dict(types),
            "number_formats": dict(formats),
            "formula_cells": formulas,
        })
    return profiles


def inspect_workbook(key: str, config: dict[str, Any], output_dir: Path) -> tuple[dict[str, Any], list[dict[str, Any]], dict[int, dict[int, dict[str, Any]]]]:
    path: Path = config["path"]
    with ZipFile(path, "r") as archive:
        strings = shared_strings(archive)
        style_data = styles(archive)
        worksheets, chartsheets = workbook_parts(archive)
        target = next(item for item in worksheets if item["name"] == config["sheet"])
        sheet_audit, rows = inspect_sheet(archive, target["part"], strings, style_data, config["identity_columns"])
        last_row = sheet_audit["last_identity_row"]
        max_col = 24 if key == "primaerliste" else 6
        images = rich_images(archive, rows, output_dir / "images") if key == "primaerliste" else drawing_images(archive, target["part"], rows, output_dir / "images")
        media_parts = [name for name in archive.namelist() if name.startswith("xl/media/") and not name.endswith("/")]
        result = {
            "file": path.as_posix(),
            "worksheets": worksheets,
            "chartsheets": chartsheets,
            "sheet": {**sheet_audit, "name": config["sheet"]},
            "record_count": last_row - 1,
            "meaningful_range": f"A1:{'X' if key == 'primaerliste' else 'F'}{last_row}",
            "columns": column_profiles(rows, last_row, max_col),
            "media_part_count": len(media_parts),
            "mapped_image_count": len(images),
            "unmapped_media_parts": sorted(set(media_parts) - {item["package_part"] for item in images}),
        }
        if key == "primaerliste":
            result["lifecycle"] = lifecycle(rows, last_row)
        return result, images, rows


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output", type=Path, default=Path("var/legacy-audit"))
    args = parser.parse_args()
    missing = [config["path"].as_posix() for config in SOURCES.values() if not config["path"].is_file()]
    if missing:
        parser.error("Missing required source workbook(s): " + ", ".join(missing))
    args.output.mkdir(parents=True, exist_ok=True)

    hashes_before = {key: sha256_file(config["path"]) for key, config in SOURCES.items()}
    workbooks: dict[str, Any] = {}
    rows_by_key: dict[str, dict[int, dict[int, dict[str, Any]]]] = {}
    all_images: list[dict[str, Any]] = []
    for key, config in SOURCES.items():
        audit, images, rows = inspect_workbook(key, config, args.output)
        workbooks[key] = audit
        rows_by_key[key] = rows
        all_images.extend(images)

    prima_records = record_list(rows_by_key["primaerliste"], 4, 2, workbooks["primaerliste"]["sheet"]["last_identity_row"])
    beschaffung_records = record_list(rows_by_key["beschaffungsliste"], 2, 3, workbooks["beschaffungsliste"]["sheet"]["last_identity_row"])
    duplicate_hashes = defaultdict(list)
    for image in all_images:
        duplicate_hashes[image["sha256"]].append({
            "workbook": image["workbook"], "row": image["row"], "drink_name": image["drink_name"]
        })
    repeated_images = [items for items in duplicate_hashes.values() if len(items) > 1]
    image_summary = {
        "total": len(all_images),
        "by_workbook": dict(Counter(item["workbook"] for item in all_images)),
        "by_format": dict(Counter(item["detected_format"] for item in all_images)),
        "unique_sha256_count": len(duplicate_hashes),
        "duplicate_hash_groups": repeated_images,
        "unassociated_record_rows": {
            key: [row for row in range(2, workbooks[key]["sheet"]["last_identity_row"] + 1) if row not in {item["row"] for item in all_images if item["workbook"] == key}]
            for key in SOURCES
        },
    }
    hashes_after = {key: sha256_file(config["path"]) for key, config in SOURCES.items()}
    if hashes_before != hashes_after:
        raise RuntimeError("Source workbook hash changed during read-only audit")

    report = {
        "scope": "read-only local legacy workbook audit; not an importer",
        "source_sha256_before": hashes_before,
        "source_sha256_after": hashes_after,
        "source_integrity_verified": True,
        "workbooks": workbooks,
        "overlap": overlap(prima_records, beschaffung_records),
        "images": image_summary,
    }
    (args.output / "audit.json").write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    (args.output / "image-manifest.json").write_text(json.dumps(all_images, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(json.dumps({
        "audit": (args.output / "audit.json").as_posix(),
        "image_manifest": (args.output / "image-manifest.json").as_posix(),
        "source_integrity_verified": True,
        "record_counts": {key: value["record_count"] for key, value in workbooks.items()},
        "image_count": len(all_images),
        "exact_overlap_count": len(report["overlap"]["exact_candidates"]),
        "likely_overlap_count": len(report["overlap"]["likely_candidates"]),
    }, ensure_ascii=False, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
