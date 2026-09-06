<?php

declare(strict_types=1);

/**
 * Builds the link-preview card that messengers show when someone shares a
 * spezitest.de URL (`public/assets/spezitest-share.png`, referenced by
 * `Layout::SHARE_IMAGE`).
 *
 * It is deliberately plain: the full-colour wordmark centred on white, nothing
 * else. A centred logo survives every messenger's crop (square, 1.91:1, small
 * thumbnail) without losing anything.
 *
 *   php tools/share-card/build.php
 *   chrome --headless=new --disable-gpu --hide-scrollbars \
 *     --force-device-scale-factor=1 --virtual-time-budget=4000 \
 *     --window-size=1200,630 \
 *     --screenshot=public/assets/spezitest-share.png var/share-card.html
 *
 * 1200x630 is the size every messenger crops from, and the file has to stay
 * JPEG or PNG: WhatsApp silently skips SVG and WebP, and drops files much over
 * 300 kB (a flat white PNG is a few kB, so that is not a concern here).
 */

$root = dirname(__DIR__, 2);
$out = $root . '/var/share-card.html';

// Inlined as a data URI so the rasteriser needs no file access beyond the page.
$logo = base64_encode((string) file_get_contents($root . '/public/assets/spezitest-logo-color.svg'));

$html = <<<HTML
<!doctype html><html lang="de"><head><meta charset="utf-8"><style>
  *{margin:0;padding:0;box-sizing:border-box}
  html,body{width:1200px;height:630px}
  body{background:#ffffff;display:flex;align-items:center;justify-content:center}
  img{width:900px;height:auto;display:block}
</style></head><body>
<img src="data:image/svg+xml;base64,$logo" alt="Spezitest">
</body></html>
HTML;

if (!is_dir(dirname($out))) {
    mkdir(dirname($out), 0o775, true);
}

file_put_contents($out, $html);

echo 'Wrote ' . $out . ' (', strlen($html), " bytes).\n";
