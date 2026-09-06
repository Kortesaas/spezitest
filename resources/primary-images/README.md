# Reviewed primary-image assets

`640x1024/` contains the 186 approved WebP delivery assets produced from the
reviewed normalized product PNGs by
`tools/primary-refresh/optimize-images.py`. They use a centered 640×1024 canvas,
quality 85, lossless alpha, and no embedded metadata.

These are controlled refresh source assets, not publicly addressable files.
The CLI-only Primärliste refresh verifies them, copies them into private runtime
storage, and stores portable `admin/640x1024/...` paths in `drink_images`.
Public and admin pages serve the files only through the application image
controllers.

The 64-character filename stem is the reviewed legacy image mapping key. It is
intentionally retained across normalization and conversion so each asset maps
back to the corresponding reviewed drink; it is not the WebP file's content
hash.
