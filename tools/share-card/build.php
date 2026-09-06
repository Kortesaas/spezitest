<?php

declare(strict_types=1);

/**
 * Builds the link-preview card that messengers show when someone shares a
 * spezitest.de URL (`public/assets/spezitest-share.png`, referenced by
 * `Layout::SHARE_IMAGE`).
 *
 * The card is laid out as HTML so it can reuse the brand colours and the
 * existing logo files, then rasterised with headless Chrome. Run:
 *
 *   php tools/share-card/build.php
 *   chrome --headless=new --disable-gpu --hide-scrollbars \
 *     --force-device-scale-factor=1 --window-size=1200,630 \
 *     --screenshot=public/assets/spezitest-share.png var/share-card.html
 *
 * 1200x630 is the size every messenger crops from, and the file has to stay
 * JPEG or PNG: WhatsApp silently skips SVG and WebP, and drops files much over
 * 300 kB.
 */

$root = dirname(__DIR__, 2);
$out = $root . '/var/share-card.html';

// Inlined as data URIs so the rasteriser needs no file access beyond the page.
$logo = base64_encode((string) file_get_contents($root . '/public/assets/spezitest-logo-white.svg'));
// The icon ships as a white bottle on a red tile; dropping the tile fill leaves
// just the bottle, which sits on the card's own red band.
$icon = base64_encode(str_replace(
    'fill="#E60005"',
    'fill="none"',
    (string) file_get_contents($root . '/public/assets/spezitest-icon.svg'),
));

$html = <<<HTML
<!doctype html><html lang="de"><head><meta charset="utf-8"><style>
  *{margin:0;padding:0;box-sizing:border-box}
  html,body{width:1200px;height:630px}
  body{font-family:Arial,Helvetica,sans-serif;background:#002D55;color:#fff;overflow:hidden;position:relative}
  .accent{position:absolute;top:-80px;bottom:-80px;right:-150px;width:470px;background:#E60005;
    transform:skewX(-10deg)}
  .glow{position:absolute;left:-260px;top:-260px;width:700px;height:700px;border-radius:50%;
    background:radial-gradient(circle,rgba(255,255,255,.12),rgba(255,255,255,0) 70%)}
  .mark{position:absolute;right:62px;top:50%;transform:translateY(-50%);width:248px;display:block}
  .inner{position:relative;height:100%;padding:72px 80px;display:flex;flex-direction:column;
    justify-content:space-between;max-width:700px}
  .logo{width:360px;display:block}
  h1{font-size:70px;line-height:1.06;letter-spacing:-.02em;font-weight:700}
  p{margin-top:20px;font-size:28px;line-height:1.4;color:rgba(255,255,255,.85);max-width:19ch}
  .foot{display:flex;align-items:center;gap:16px;font-size:23px;letter-spacing:.09em;
    text-transform:uppercase;color:rgba(255,255,255,.7);white-space:nowrap}
  .foot i{width:9px;height:9px;background:#E60005;display:block;flex:none}
  .bar{position:absolute;left:0;right:0;bottom:0;height:12px;background:#E60005}
</style></head><body>
<div class="accent"></div><div class="glow"></div>
<img class="mark" src="data:image/svg+xml;base64,$icon" alt="">
<div class="inner">
  <img class="logo" src="data:image/svg+xml;base64,$logo" alt="">
  <div>
    <h1>Cola-Mix.<br>Ein Urteil.</h1>
    <p>Jede Spezi selbst gekauft, blind verkostet, gleich bewertet.</p>
  </div>
  <div class="foot"><i></i>Katalog &middot; Ranking &middot; Statistik</div>
</div>
<div class="bar"></div>
</body></html>
HTML;

if (!is_dir(dirname($out))) {
    mkdir(dirname($out), 0o775, true);
}

file_put_contents($out, $html);

echo 'Wrote ' . $out . ' (', strlen($html), " bytes).\n";
