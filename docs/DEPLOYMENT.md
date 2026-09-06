# Deployment — first production release to Plesk

This is the reviewed procedure for the first deployment of Spezitest to
`https://www.spezitest.de` on Plesk shared hosting (PHP 8.3 · Apache + PHP-FPM ·
MariaDB 10.11). It assumes **no SSH**: everything is done through the Plesk
control panel, its File Manager / SFTP, phpMyAdmin, and Plesk Scheduled Tasks.
The CLI migration script runs only as a one-off Plesk Scheduled Task; the legacy
importer never runs on production.

Nothing here connects to production automatically. Follow the steps in order.

---

## 0. What you deploy

Build both artifacts locally with `composer build:full-release`. You upload:

| Artifact | Contents | Where it goes |
| --- | --- | --- |
| `dist/spezitest-<version>.tar.gz` | Application + production Composer deps. No tests, no dev tooling, no secrets, no Excel sources. | Application root on the server |
| `dist/spezitest-initial-data-<version>.tar.gz` | Data-only `spezitest-data.sql`, reviewed refresh plan, manifests, manuals, and all 195 private product images arranged beneath `var/`. | SQL → phpMyAdmin after migrations · `var/` → private application storage |

The reviewed catalogue is 196 drinks (54 identified, 17 acquired, 125 tested),
125 completed tests, 375 raw ratings, 195 images. Of those images, 186 are
optimized 640×1024 WebPs and nine are retained older fallbacks; ten products
are marked as needing a new photograph. All five fuzzy duplicate
candidates were resolved **DIFFERENT_PRODUCTS**.

---

## 1. Check these in Plesk first

- **Subscription / domain** for `spezitest.de` exists, with `www` and the
  apex both pointing at it.
- **PHP** → set the domain to **PHP 8.3**, handler **FPM served by Apache**.
- **PHP extensions**: `pdo_mysql` and `fileinfo` must be enabled (Plesk → PHP
  Settings, or a `phpinfo()` you delete straight after). GD / Imagick are **not
  required** — ordinary admin uploads retain validated originals, while the
  reviewed catalogue WebPs were already generated offline.
- **PHP settings** for the domain: `display_errors = Off`. `memory_limit`
  256M and `upload_max_filesize` / `post_max_size` 64M are already the host
  defaults and are fine.
- **SSL/TLS certificate** issued and valid for `www.spezitest.de` (Let's
  Encrypt via Plesk is fine). The admin session cookie is `Secure` in
  production, so admin login only works over HTTPS.
- **Disk quota** headroom: allow at least 100 MB for the application, current
  images, upload growth, extraction overhead, and logs.
- Confirm whether the domain is served **Apache + `.htaccess`** (normal) or
  **nginx-only**. If nginx-only, you will paste the nginx directives from
  section 5 instead of relying on `public/.htaccess`.

---

## 2. Production environment checklist

Confirm each before going live:

- [ ] Document root points at the deployed **`public/`** directory, not the app root.
- [ ] `.env` exists in the **app root** (one level above `public/`), permissions `600`, and is **not** reachable over HTTP (`https://www.spezitest.de/.env` → 404).
- [ ] `APP_ENV=production`, `APP_DEBUG=false`.
- [ ] `DB_*` point at the dedicated Spezitest database with a least-privilege user restricted to that one database.
- [ ] `ADMIN_USERNAME` set and `ADMIN_PASSWORD_HASH` is a real `password_hash()` string in **single quotes**.
- [ ] `ADMIN_IMAGE_STORAGE_ROOT` and `LEGACY_IMAGE_STORAGE_ROOT` resolve **outside** `public/` and are writable by PHP-FPM.
- [ ] HTTPS works and HTTP redirects to HTTPS (Plesk → Hosting Settings → "Permanent SEO-safe 301 redirect from HTTP to HTTPS").
- [ ] `https://www.spezitest.de/` returns the homepage; `https://www.spezitest.de/nonsense` returns the branded 404; an error never shows a stack trace or SQL.
- [ ] `https://www.spezitest.de/admin` redirects to `/admin/login`; login works over HTTPS.
- [ ] `bin/legacy-import.php` and the `tools/` directory are **not** present in the artifact (they are excluded by the build script).
- [ ] A verified database backup exists before the data import (section 7).

---

## 3. Upload and directory layout

Assume the Plesk domain's home is `.../httpdocs/`.

1. **File Manager / SFTP**: upload `spezitest-<version>.tar.gz` into `httpdocs/`
   and extract it. You get `httpdocs/spezitest-<version>/…`.
2. Move the *contents* of `httpdocs/spezitest-<version>/` up into `httpdocs/`
   (or keep the versioned folder and set the document root into it — see step 4).
   Target layout:

   ```
   httpdocs/
     public/            <- becomes the document root
       index.php  .htaccess  robots.txt  assets/
     src/  config/  database/  vendor/  docs/  bin/
     composer.json  composer.lock
     .env               <- you create this (section 4), NEVER inside public/
     var/
       admin-images/     <- writable, admin uploads land here
       legacy-images/    <- writable, historical images land here
   ```

3. Set permissions: `var/`, `var/admin-images/`, `var/legacy-images/` writable
   by the PHP-FPM user (Plesk File Manager → Change Permissions; typically the
   subscription's system user already owns them — `755` dirs is enough).
4. Delete the now-empty `httpdocs/spezitest-<version>/` wrapper and the
   uploaded `.tar.gz`.

> **Future releases:** upload the new artifact, extract, and replace only
> `public/ src/ config/ database/ vendor/ bin/ composer.*`. **Never overwrite
> `.env` or `var/`.** Then run the migration task (section 9).

---

## 4. Configure `.env`

Copy `.env.production.example` to `httpdocs/.env` and fill it in. Generate the
admin hash on any PHP 8.3 machine:

```bash
php -r "echo password_hash('YOUR-ADMIN-PASSWORD', PASSWORD_DEFAULT), PHP_EOL;"
```

Paste it **in single quotes** — a bcrypt hash contains `$` and would otherwise
be corrupted by `.env` interpolation:

```dotenv
ADMIN_PASSWORD_HASH='$2y$12$....'
```

Set `.env` permissions to `600`. Confirm `https://www.spezitest.de/.env`
returns 404 (it will, because it is above the document root and
`public/.htaccess` also denies dotfiles).

---

## 5. Document root and routing

**Plesk → Hosting Settings → Document root:** set to `public` (relative to the
domain home), i.e. `httpdocs/public`.

**Apache (normal):** `public/.htaccess` (shipped) already does front-controller
routing, denies dotfiles, disables directory listing, and sets a modest cache
policy. Nothing else to configure.

**nginx-only hosting:** in Plesk → Apache & nginx Settings → "Additional nginx
directives", add:

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
location ~ /\.(?!well-known) { deny all; }
location ~* \.(css|js|svg|png|jpe?g|webp|woff2?)$ {
    expires 1d;
    add_header Cache-Control "public";
    add_header X-Content-Type-Options "nosniff";
    try_files $uri =404;
}
```

**Assets/paths:** the app references assets as absolute paths
(`/assets/spezitest.css`, `/assets/spezitest.js`, `/assets/spezitest-*.svg`) and
all routes are absolute (`/spezis`, `/spezi/{id}`, `/ranking`, …). Hosting at
the domain root is the intended and only supported configuration — no base-path
rewriting is needed. Verify after go-live:
`https://www.spezitest.de/assets/spezitest.css` → `200 text/css`.

---

## 6. TLS, sessions and error handling

- HTTPS certificate active (section 1); enable the Plesk HTTP→HTTPS 301 redirect.
- In production the app forces: `Secure`, `HttpOnly`, `SameSite=Strict`,
  strict cookie-only sessions; `APP_DEBUG` is ignored and detailed Slim errors
  are off; every handled failure returns a generic response and logs
  server-side without exposing `DB_PASSWORD`, paths, or SQL. No configuration
  needed beyond `APP_ENV=production`.
- Do not add any `phpinfo`, adminer, installer, or debug script to `public/`.
  phpMyAdmin is Plesk's own, separately authenticated surface.

---

## 7. Backup before the data import

The production database will be empty at this point, but take the backup anyway
so the procedure is exercised and there is a known-good restore point.

1. **Plesk → Backup Manager → Back Up** — select at least *Databases* (and
   *Mail*/*Configuration* if you want a full point-in-time backup). Wait for it
   to finish and note the backup name/date.
2. **Also** export the (empty) database from **phpMyAdmin → Export → Quick →
   SQL** and keep the file. This is your fast, targeted rollback for the import
   step specifically.
3. Record: backup name, timestamp, database name, and where the phpMyAdmin
   export is stored.

Rollback if the import goes wrong: phpMyAdmin → drop all tables in the Spezitest
database → import the pre-import export (or restore via Backup Manager).

---

## 8. Build both deployment packages locally

Use a clean, reviewed checkout with PHP 8.3 and Composer 2. The tracked seed and
images mean the legacy workbooks, the developer's current database, Python,
Node.js, and MariaDB are not required merely to build the archives.

```sh
composer install
composer check
composer build:full-release
```

The build verifies the data SQL hash and all 195 image hashes, MIME types, and
dimensions before creating:

- `dist/spezitest-<version>.tar.gz`; and
- `dist/spezitest-initial-data-<version>.tar.gz`.

The initial-data archive includes `INSTALL.md`, `PLESK-DEPLOYMENT.md`,
`REVIEWED-REFRESH-PLAN.json`, `DATA-MANIFEST.json`, and a SHA-256
`MANIFEST.txt`. The build prints a SHA-256 for each archive; verify both
uploaded archives against those values before extracting them. After
extraction, `shasum -a 256 -c MANIFEST.txt` verifies every file inside the
initial-data package when shell access is available.

`composer initial-data:export` is not part of normal deployment. It exists only
to deliberately regenerate the tracked seed from the verified non-production
database after a reviewed data change, and it refuses production.

---

## 9. Order of operations on production

Do these strictly in order. **A → E.**

### A. Create the database and user (Plesk → Databases → Add Database)

- Database name: your choice, e.g. `spezitest` (Plesk usually prefixes it).
- New database user, **dedicated to this database only** (do not reuse a user
  that can see other databases in the subscription).
- Strong generated password (Plesk's generator). Record it for `.env`.
- Character set / collation: `utf8mb4` / `utf8mb4_unicode_ci` if Plesk asks;
  the imported dump sets these per-table regardless.

### B. Put the application in place and configure `.env`

Sections 3–5 above. At the end, `https://www.spezitest.de/` should load —
it will show empty-state pages until the data is imported, which is expected.

### C. Back up (section 7)

### D. Create the schema, import the data, and install images

1. **Schema:** create a one-off **Plesk → Scheduled Tasks** job using
   `/opt/plesk/php/8.3/bin/php <app-root>/bin/migrate.php` (adjust the PHP and
   app paths to the subscription). Run it once and confirm all seven currently
   tracked migrations are applied. Disable the task after successful verification.
2. **Rows:** phpMyAdmin → select the Spezitest database → **Import** → choose
   `spezitest-data.sql` from the initial-data archive → Go. The data-only seed
   requires the migrated tables to be empty, never drops tables, and loads 196
   drinks, 125 tests, 375 ratings, 195 image rows, and one provenance row. The
   three canonical testers already come from the migrations.
   - If the file is over phpMyAdmin's upload limit, gzip it
     (`spezitest-data.sql.gz`) — phpMyAdmin imports `.gz` directly — or
     use Plesk → Databases → *Import Dump*.
3. **Images:** merge the initial-data archive's `var/` directory into the app
   root's `var/`. The final files must include:
   - `var/admin-images/admin/640x1024/` — 186 WebPs; and
   - `var/legacy-images/legacy/<run-id>/` — nine retained JPEG/PNG fallbacks.

   These locations match `ADMIN_IMAGE_STORAGE_ROOT=var/admin-images` and
   `LEGACY_IMAGE_STORAGE_ROOT=var/legacy-images`. Keep them outside `public/`
   and writable by PHP-FPM. Do not rename any image.

### E. Verify (section 10)

> **Starting empty instead:** if you do not want the historical catalogue yet,
> skip D. Run migrations instead as a one-off **Plesk → Scheduled Tasks** job:
> command `/opt/plesk/php/8.3/bin/php /var/www/vhosts/<domain>/httpdocs/bin/migrate.php`
> (adjust paths to your subscription). Run it once, confirm the output
> "Applied 7 migration(s).", then delete or disable the task. Add drinks and
> tests through `/admin`.

---

## 10. Post-deployment verification

| Check | Expected |
| --- | --- |
| `GET /` | 200, homepage, "125 Spezis getestet." |
| `GET /spezis` | 200, "196 Ergebnisse", card grid |
| `GET /ranking` | 200, podium, Flötzinger Cola-Mix at rank 1 with Gesamtwertung **55,33** |
| `GET /statistik` | 200, "125 Spezis getestet" |
| `GET /spezi/109` | 301 → `/spezi/109-flotzinger-cola-mix` |
| `GET /spezi/109/bild` | 200, `image/webp`, `X-Content-Type-Options: nosniff` |
| `GET /assets/spezitest.css` | 200, `text/css` |
| `GET /nonsense` | 404, branded page, no stack trace |
| `GET /.env` | 404 |
| `GET /admin` | 302 → `/admin/login` |
| Admin login (HTTPS) | succeeds; dashboard shows 54 / 17 / 125 |
| Admin: open a tested drink → Test bearbeiten | grades 0–10 shown, Gesamtwertung recomputed identically |
| Admin: upload a JPEG/PNG on a drink | stored under `var/admin-images/`, visible on the public detail page |
| Provoke an error (e.g. wrong `DB_PASSWORD` briefly) | generic 500, no SQL/paths in the response; details only in the server log |

Rating spot-check: Flötzinger Cola-Mix must remain rank 1 with Gesamtwertung
**55,33**; then open its admin test form and confirm the recomputed result is
identical.

---

## 11. Robots / indexing recommendation

`public/robots.txt` (shipped) disallows `/admin`, allows the rest, and points
crawlers at `https://www.spezitest.de/sitemap.xml`. The catalogue is real data
from day one, so indexing the public site at launch is fine.

If you want a quiet soft-launch, edit `public/robots.txt` to `Disallow: /`
(keeping the `/admin` line), and additionally enable **Plesk → Search Engine
Indexing → block** for a few days. Revert both once you are satisfied with the
public pages. The 404 page sends `noindex`; the admin sends `noindex, nofollow`;
every ordinary public page is indexable.

### SEO / discovery endpoints (all generated from live data, no config)

| URL | What it is |
| --- | --- |
| `/sitemap.xml` | Every public page plus one entry per Spezi and Testabend, with `lastmod`. |
| `/feed.xml` | Atom feed of the most recently tested Spezis. Linked from every page's `<head>`. |
| `/site.webmanifest`, `/favicon.ico`, `/assets/icon-*.png`, `/assets/apple-touch-icon.png` | Icons + PWA manifest. Regenerate from the source SVG with `python3 tools/icons/build.py` (needs Chrome + Pillow locally; never on the server). |
| JSON-LD in each page `<head>` | `Organization`/`WebSite` everywhere; `Product` + `Review` on Spezi pages; `BreadcrumbList`; `VideoObject` on Testabend pages that have a recording URL. |

After launch: submit `https://www.spezitest.de/sitemap.xml` in **Google Search
Console** and **Bing Webmaster Tools**. If the canonical host is ever not
`https://www.spezitest.de`, set `APP_URL` in `.env` — it drives the canonical
tag, link previews, the sitemap and the feed. `public/robots.txt` still names
the domain literally (the `Sitemap:` directive requires an absolute URL); edit
it there too.

---

## 12. Future database migrations

New migrations are forward-only SQL files in `database/migrations/`. On
production:

1. Take a database backup (Plesk Backup Manager + a phpMyAdmin export).
2. Upload the new release (section 3, preserving `.env` and `var/`).
3. **Plesk → Scheduled Tasks →** run once:
   `/opt/plesk/php/8.3/bin/php <app-root>/bin/migrate.php`
4. Confirm the task output lists the applied version(s), then disable the task.
5. Run the section 10 checks.

The runner applies only unrecorded files, records a SHA-256 per file, and
refuses if an already-applied file was edited. MariaDB DDL can commit
implicitly, so a failed migration may need manual cleanup in phpMyAdmin before
retrying — hence the mandatory pre-migration backup.

---

## 13. Ongoing operations notes

- **Admin password change:** regenerate the hash locally and update
  `ADMIN_PASSWORD_HASH` in `.env`. There is deliberately no password-reset route.
- **Image storage** must persist across releases. Never point
  `ADMIN_IMAGE_STORAGE_ROOT` / `LEGACY_IMAGE_STORAGE_ROOT` inside `public/`.
- **WebP uploads:** accepted only if the deployed PHP image parser recognises
  WebP. Test one WebP upload after go-live before telling editors it is
  supported; JPEG/PNG always work.
- **Backups:** keep Plesk's scheduled backups on. The database is now the sole
  source of truth; the Excel workbooks are retired.
- **Do not** run `composer` on the production host, and do not run the legacy
  importer against production — its `APP_ENV` guard will refuse, and the data
  path is the SQL import above.
