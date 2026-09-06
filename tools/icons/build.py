#!/usr/bin/env python3
"""Rasterise the Spezitest icon into the favicon, PWA and Apple touch icons.

The source of truth is ``public/assets/spezitest-icon.svg`` (a white bottle on
the brand-red rounded tile). This script renders it once at high resolution with
headless Chrome, then downscales with Pillow and assembles ``favicon.ico``.

    python3 tools/icons/build.py [path-to-chrome]

Outputs (all committed):
    public/favicon.ico                 16/32/48, from the rounded tile
    public/assets/icon-192.png         PWA / Android
    public/assets/icon-512.png         PWA / Android, also the schema.org logo
    public/assets/apple-touch-icon.png 180, full-bleed square (iOS masks it)

Chrome is a build-time dependency only; the deployed app never runs it.
"""
from __future__ import annotations

import pathlib
import subprocess
import sys

from PIL import Image

ROOT = pathlib.Path(__file__).resolve().parents[2]
ASSETS = ROOT / "public" / "assets"
WORK = ROOT / "var" / "icons"

CHROME_CANDIDATES = [
    r"C:\Program Files\Google\Chrome\Application\chrome.exe",
    r"C:\Program Files (x86)\Google\Chrome\Application\chrome.exe",
    r"C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe",
    "/usr/bin/google-chrome",
    "/usr/bin/chromium",
    "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome",
]


def find_chrome() -> str:
    if len(sys.argv) > 1:
        return sys.argv[1]
    for candidate in CHROME_CANDIDATES:
        if pathlib.Path(candidate).exists():
            return candidate
    sys.exit("Chrome/Edge not found - pass the executable path as an argument.")


def wrap(svg: str, background: str) -> str:
    return (
        "<!doctype html><html><head><meta charset=utf-8><style>"
        f"html,body{{margin:0;padding:0;background:{background}}}"
        "svg{display:block;width:100vw;height:100vh}</style></head><body>"
        f"{svg}</body></html>"
    )


def render(chrome: str, html: str, size: int, name: str) -> Image.Image:
    WORK.mkdir(parents=True, exist_ok=True)
    src = WORK / f"{name}.html"
    out = WORK / f"{name}.png"
    src.write_text(html, encoding="utf-8")
    subprocess.run(
        [
            chrome,
            "--headless=new",
            "--disable-gpu",
            "--hide-scrollbars",
            "--force-device-scale-factor=1",
            "--default-background-color=00000000",
            "--virtual-time-budget=4000",
            f"--screenshot={out}",
            f"--window-size={size},{size}",
            src.resolve().as_uri(),
        ],
        check=True,
        capture_output=True,
    )
    return Image.open(out).convert("RGBA")


def resized(image: Image.Image, size: int) -> Image.Image:
    return image.resize((size, size), Image.LANCZOS)


def main() -> None:
    chrome = find_chrome()
    icon_svg = (ASSETS / "spezitest-icon.svg").read_text(encoding="utf-8")
    square_svg = icon_svg.replace('rx="86"', 'rx="0"')

    tile = render(chrome, wrap(icon_svg, "transparent"), 512, "tile")
    square = render(chrome, wrap(square_svg, "#E60005"), 360, "square")

    resized(tile, 512).save(ASSETS / "icon-512.png")
    resized(tile, 192).save(ASSETS / "icon-192.png")
    resized(square, 180).save(ASSETS / "apple-touch-icon.png")
    resized(tile, 256).save(
        ROOT / "public" / "favicon.ico", sizes=[(16, 16), (32, 32), (48, 48)]
    )

    for path in (
        ASSETS / "icon-512.png",
        ASSETS / "icon-192.png",
        ASSETS / "apple-touch-icon.png",
        ROOT / "public" / "favicon.ico",
    ):
        print(f"wrote {path.relative_to(ROOT)} ({path.stat().st_size} bytes)")


if __name__ == "__main__":
    main()
