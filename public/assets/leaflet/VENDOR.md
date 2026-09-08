# Vendored: Leaflet 1.9.4

`leaflet.js`, `leaflet.css` and `images/` are the unmodified distribution files
of [Leaflet](https://leafletjs.com/) 1.9.4, used by the hunt map on `/karte`.

- Source: `https://registry.npmjs.org/leaflet/-/leaflet-1.9.4.tgz` (`package/dist/`)
- Licence: BSD-2-Clause (see the header comment in `leaflet.js`)

They are committed rather than fetched from a CDN so the site stays first-party
and needs no build step or Node.js. To update: drop in the new `dist/` files and
bump the `?v=` query string in `Layout`/`WebsiteRenderer`.
