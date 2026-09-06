#!/usr/bin/env python3
"""Crop normalized product PNGs and create web-optimized WebP copies."""

from __future__ import annotations

import argparse
import hashlib
import shutil
import subprocess
from pathlib import Path

from PIL import Image


ROOT = Path(__file__).resolve().parents[2]
DEFAULT_SOURCE = ROOT / "var/admin-images/1024x1024"
DEFAULT_PNG_OUTPUT = ROOT / "var/admin-images/640x1024"
DEFAULT_WEBP_OUTPUT = ROOT / "var/admin-images/640x1024-webp"
SOURCE_WIDTH = 1024
SOURCE_HEIGHT = 1024
TARGET_WIDTH = 640
TARGET_HEIGHT = 1024
WEBP_QUALITY = 85
EXPECTED_IMAGES = 186


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for block in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(block)
    return digest.hexdigest()


def require_empty_target(path: Path) -> None:
    if path.exists() and any(path.iterdir()):
        raise RuntimeError(f"Output directory is not empty: {path}")
    path.mkdir(parents=True, exist_ok=True)


def verify_image(path: Path, expected_format: str) -> None:
    with Image.open(path) as image:
        image.load()
        if image.format != expected_format or image.size != (TARGET_WIDTH, TARGET_HEIGHT):
            raise RuntimeError(f"Generated image failed verification: {path.name}")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source", type=Path, default=DEFAULT_SOURCE)
    parser.add_argument("--png-output", type=Path, default=DEFAULT_PNG_OUTPUT)
    parser.add_argument("--webp-output", type=Path, default=DEFAULT_WEBP_OUTPUT)
    parser.add_argument("--cwebp", default="cwebp", help="Path to the cwebp executable.")
    args = parser.parse_args()

    source = args.source.resolve()
    png_output = args.png_output.resolve()
    webp_output = args.webp_output.resolve()
    cwebp = shutil.which(args.cwebp)
    if not source.is_dir():
        parser.error(f"Missing source directory: {source}")
    if cwebp is None:
        parser.error("cwebp is required for deterministic WebP conversion.")

    source_files = sorted(source.glob("*.png"))
    if len(source_files) != EXPECTED_IMAGES:
        parser.error(f"Expected exactly {EXPECTED_IMAGES} source PNGs.")
    if any(len(path.stem) != 64 or any(c not in "0123456789abcdef" for c in path.stem) for path in source_files):
        parser.error("Every source image must use its reviewed 64-character lowercase hash filename.")

    require_empty_target(png_output)
    require_empty_target(webp_output)
    left = (SOURCE_WIDTH - TARGET_WIDTH) // 2
    right = left + TARGET_WIDTH
    png_bytes = 0
    webp_bytes = 0

    try:
        for source_path in source_files:
            png_path = png_output / source_path.name
            webp_path = webp_output / f"{source_path.stem}.webp"
            with Image.open(source_path) as image:
                image.load()
                if image.format != "PNG" or image.size != (SOURCE_WIDTH, SOURCE_HEIGHT):
                    raise RuntimeError(f"Source is not a 1024×1024 PNG: {source_path.name}")
                rgba = image.convert("RGBA")
                cropped = rgba.crop((left, 0, right, TARGET_HEIGHT))
                cropped.save(png_path, format="PNG", optimize=True, compress_level=9)

            result = subprocess.run(
                [
                    cwebp,
                    "-quiet",
                    "-q", str(WEBP_QUALITY),
                    "-m", "6",
                    "-alpha_q", "100",
                    "-metadata", "none",
                    str(png_path),
                    "-o", str(webp_path),
                ],
                check=False,
                capture_output=True,
                text=True,
            )
            if result.returncode != 0:
                raise RuntimeError(f"cwebp failed for {source_path.name}: {result.stderr.strip()}")
            verify_image(png_path, "PNG")
            verify_image(webp_path, "WEBP")
            png_bytes += png_path.stat().st_size
            webp_bytes += webp_path.stat().st_size
    except Exception:
        for directory in (png_output, webp_output):
            if directory.exists():
                shutil.rmtree(directory)
        raise

    webp_hashes = {path.name: sha256_file(path) for path in sorted(webp_output.glob("*.webp"))}
    if len(webp_hashes) != EXPECTED_IMAGES or len(set(webp_hashes.values())) != EXPECTED_IMAGES:
        raise RuntimeError("Generated WebP output is incomplete or unexpectedly duplicated.")

    saved = png_bytes - webp_bytes
    percent = (saved / png_bytes * 100) if png_bytes else 0
    print(f"Generated {EXPECTED_IMAGES} PNGs at {TARGET_WIDTH}×{TARGET_HEIGHT}: {png_output}")
    print(f"Generated {EXPECTED_IMAGES} WebPs at quality {WEBP_QUALITY}: {webp_output}")
    print(f"PNG bytes: {png_bytes}; WebP bytes: {webp_bytes}; reduction: {percent:.1f}%")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
