# Spezitest

Spezitest is intended to become the production web application for cataloging
Cola-Mix / Spezi drinks, primarily from Germany and surrounding countries. The
eventual public site will be available at
[spezitest.de](https://www.spezitest.de).

## Project status

This repository is at the **release-preparation stage** for the first
production deployment. On top of the Packet 5 domain/rating foundation, the
Packet 6 controlled importer and the Packet 7 admin, Packet 8 added:

- **Test / rating entry** in the admin: a nine-grade form (Optik, Süffigkeit,
  Geschmack for Manu, Fabi, Schorsch, each 0–10, higher is better), an optional
  test price, draft saving, a live calculated Gesamtwertung, and a guarded
  completion action that runs the **verified rating engine only** and moves the
  drink to `tested` in one transaction.
- **A real public website** styled with the Spezitest Design System:
  `/` (home), `/spezis` (search / filter / sort browser), `/spezi/{id-or-slug}`
  (detail with tester and category scores), `/ranking`, `/statistik` and
  `/ueber`, plus a branded 404. Product images are served through a controlled
  read-only route (`/spezi/{id}/bild`); missing images get a branded
  placeholder.
- **Statistics** derived only from real database rows (counts, average scores,
  best category scores, tester averages, Gesamt distribution, region and
  manufacturer breakdowns). Nothing is invented; an empty catalog shows honest
  empty states.

Release preparation added: the five fuzzy duplicate candidates resolved
**DIFFERENT_PRODUCTS** (`tools/legacy-import/duplicate-decisions.resolved.json`);
a re-verification of the rating input scale against all 972 historical grade
values; `tools/build-release.sh` to produce a production artifact; a production
`.htaccess`, `robots.txt` and `.env.production.example`; and the full Plesk
procedure in **`docs/DEPLOYMENT.md`**.

Image optimisation is still out of scope (validated originals only; no
GD/Imagick dependency). Everything has been validated only against
disposable/local MariaDB. **No production database has been created or
contacted.**

### Rating scale

Each category (Optik, Süffigkeit, Geschmack) is graded as an **integer 0–10
inclusive, higher is better** — re-verified against all 972 historical grade
values (`docs/RATING_SYSTEM.md`). The published **Gesamtwertung** is the verified
engine's weighted sum (Optik ×1 + Süffigkeit ×2 + Geschmack ×3), roughly 0–60,
higher is better; ranking is descending. Grades are shown with the German
decimal comma.

## Repository organization

- `AGENTS.md`: mandatory guidance for future coding agents.
- `docs/ARCHITECTURE.md`: architectural boundaries and runtime structure.
- `docs/PRODUCTION.md`: hosting and production security constraints.
- `docs/DEPLOYMENT.md`: the step-by-step Plesk deployment procedure, environment
  checklist, database creation, legacy-data import, and verification.
- `docs/DATA_LIFECYCLE.md`: the canonical lifecycle of a drink and historical
  migration classification.
- `docs/DATA_MODEL.md`: implemented tables/relationships and clearly separated
  future or unresolved decisions.
- `docs/RATING_SYSTEM.md`: workbook-verified rating formulas, historical
  quirks, unresolved edge semantics, and the production verification gate.
- `docs/LEGACY_WORKBOOK_AUDIT.md`: workbook structure, lifecycle, overlap,
  image, metadata, anomaly, and migration-risk findings.
- `docs/LEGACY_IMPORT.md`: dry-run, duplicate review, local apply, image
  recovery, corrections, and import safety procedure.
- `docs/ADMIN.md`: authentication, admin routes, image-storage controls, and
  current operational limitations.
- `public/`: the only intended web document root and the minimal front
  controller.
- `src/`: application/configuration code, database infrastructure, and isolated
  rating domain logic.
- `config/`: environment loading and application bootstrap.
- `tests/`: application tests and machine-readable historical golden fixtures.
- `tools/legacy-audit/`: local read-only OOXML audit tooling; never production
  runtime code.
- `tools/legacy-import/`: local plan construction, reports, review support, and
  import-only PHP services; never an HTTP feature.
- `bin/migrate.php`: CLI-only migration command; never an HTTP endpoint.
- `bin/legacy-import.php`: CLI-only legacy plan verifier/apply command.
- `database/migrations/`: reviewed, forward-only SQL migration files.
- `composer.json` and `composer.lock`: PHP dependencies, autoloading, and
  quality commands.
- `phpunit.xml.dist` and `phpstan.neon.dist`: test and static-analysis
  configuration.
- `.env.example`: safe local environment configuration examples.

## Core domain model

A drink has exactly one current lifecycle status:

`identified -> acquired -> tested`

The statuses are views/states of one drink record. A status change must never
be represented by duplicating a drink or moving it between independent
datasets. The MariaDB database will be the single source of truth; existing
Excel workbooks are migration and verification sources only.

The permanent testers are Manu, Fabi, and Schorsch. The implemented schema uses
stable codes rather than relying on row IDs or display names. The existing
rating methodology remains exactly unchanged: the Packet 5 engine reproduces
all eight historical golden cases and ranking evidence. Remaining Excel
boundary semantics are still a production gate; see `docs/RATING_SYSTEM.md`.

Basic drink creation remains quick: name and status are required, and one
primary picture is optional. Other metadata is edited later. Image binaries
are files outside the public document root, referenced by database metadata,
not MariaDB BLOBs.

Current Preis/Leistung preserves the historical calculation but dynamically
normalizes over all eligible completed/tested results with valid positive
prices. The fixed Excel T2:T109 range is documented as an intentionally
corrected spreadsheet artifact.

## Production target

The known target is Plesk shared hosting with PHP 8.3 running through FPM and
Apache, plus MariaDB 10.11. Runtime code must work without
Node.js, production shell access, or PHP shell/process execution functions. See
`docs/PRODUCTION.md` for the complete baseline.

## Configuration

Runtime configuration comes from actual environment variables. For local use,
`vlucas/phpdotenv` optionally loads a repository-root `.env` file when one is
present; the application also works when no `.env` file exists. The secure
defaults are `APP_ENV=production` and `APP_DEBUG=false`, and production always
suppresses detailed HTTP error output.

`.env.example` contains safe local values only. Never commit a populated `.env`
file or real credentials.

Database access additionally requires `DB_HOST`, `DB_PORT`, `DB_NAME`,
`DB_USER`, `DB_PASSWORD`, and `DB_CHARSET=utf8mb4`. Those settings are read and
validated only when database access is explicitly requested; normal application
bootstrap does not open a connection.

The initial admin identity is configured with `ADMIN_USERNAME` and a
`ADMIN_PASSWORD_HASH` produced by PHP's `password_hash()`. Never store the
plaintext password in environment configuration. `ADMIN_IMAGE_STORAGE_ROOT`
must resolve outside `public/`; the default application limit is 5 MiB and can
be lowered with `ADMIN_IMAGE_MAX_BYTES`.

## Admin area

The admin (styled with the Spezitest Design System admin shell, deliberately
more compact than the public site) begins at `/admin/login`. It provides
lifecycle counts, drink listing / search / status filtering, quick creation
(name + status + optional picture), full metadata editing, explicit delete
confirmation, status changes, a single optional primary picture, and
**test / rating entry** (`/admin/drinks/{id}/test`). All admin pages except
login require session authentication. Every POST is CSRF-protected.

The quick-add workflow stays a single short form so a Spezi can be recorded
quickly from a phone. `tested` is reachable only by completing a test; a
status-only action can never fabricate it.

Uploaded JPEG, PNG, and WebP files are checked by actual bytes, dimensions,
and detected MIME type; client filenames and MIME headers are ignored. Files
receive generated names under `ADMIN_IMAGE_STORAGE_ROOT` outside `public/` and
are served only through an authenticated route. See `docs/ADMIN.md`.

## Local development

Prerequisites are PHP 8.3 with PDO MySQL, Fileinfo, and Composer 2. Ordinary
unit tests and the public placeholder page need no running database, external
service, production credential, Node.js installation, or frontend toolchain.

```bash
composer install
cp .env.example .env
composer check
composer serve
```

The application is then available at `http://127.0.0.1:8080`. Admin routes
also require a migrated local database and configured admin credentials.
`composer serve` uses PHP's built-in server and is for local development only.
It is not a production start command.

Individual quality commands are:

```bash
composer test
composer analyse
```

`composer check` deliberately runs unit tests and static analysis only. It does
not connect to or mutate a database.

Legacy planning additionally requires Python 3 using only its standard
library. The source-dependent importer tests are intentionally separate:

```bash
composer test:legacy-import
```

## Local database and integration tests

Database integration tests require a disposable MariaDB 10.11 database. Docker
is one optional local/testing method; it is not part of the production
architecture. For example, start a local disposable server in one terminal:

```bash
docker run --rm --name spezitest-mariadb-10-11 \
  -p 127.0.0.1:3307:3306 \
  -e MARIADB_DATABASE=spezitest_test \
  -e MARIADB_USER=spezitest_test \
  -e MARIADB_PASSWORD=local_test_password \
  -e MARIADB_ROOT_PASSWORD=local_root_password \
  mariadb:10.11
```

Then configure the matching disposable credentials in `.env`:

```dotenv
DB_HOST=127.0.0.1
DB_PORT=3307
DB_NAME=spezitest_test
DB_USER=spezitest_test
DB_PASSWORD=local_test_password
DB_CHARSET=utf8mb4
```

Generate a local admin password hash and add the resulting values only to the
ignored `.env` file:

```dotenv
ADMIN_USERNAME=your_local_admin_name
ADMIN_PASSWORD_HASH=the_password_hash_output
ADMIN_IMAGE_STORAGE_ROOT=var/admin-images
ADMIN_IMAGE_MAX_BYTES=5242880
```

In production the admin session cookie is automatically Secure, HttpOnly, and
SameSite=Strict, and PHP strict cookie-only sessions are enabled.

### `.env.testing` for the integration suite

`composer test:integration` reads a git-ignored `.env.testing` file
automatically (loaded by `tests/bootstrap.php`), so DB_* variables never have
to be typed by hand. Copy the example and fill in the disposable-database
values:

```bash
cp .env.testing.example .env.testing
```

The integration suite is destructive. It refuses to run unless `APP_ENV=testing`
**and** `DB_NAME` ends with `_test`, so it can never touch the development
(`spezitest`) or production database. Use three separate databases:

| Purpose | Database | Configured in |
| --- | --- | --- |
| Development (`composer serve`) | `spezitest` | `.env` |
| Integration tests | `spezitest_test` | `.env.testing` |
| Production | separate Plesk database | deployment env |

Run the integration suite in another terminal:

```bash
composer test:integration
```

The integration suite creates, constrains, inspects, and removes the domain
schema and Packet 6 migration/import-infrastructure test objects in that
disposable database. It is destructive within the configured database. Never
point it at production or a database containing data you care about.

## Database migrations

Forward SQL migrations live in `database/migrations/` and use sortable
`YYYYMMDDHHMMSS_description.sql` filenames. Run pending migrations explicitly:

```bash
composer migrate
```

This command requires configured database credentials and mutates the selected
database. It creates `schema_migrations` itself, applies each unrecorded file in
filename order, records its SHA-256 checksum only after successful execution,
and rejects later edits to applied files. A second run with no new files is a
no-op.

MariaDB DDL can commit implicitly, so migrations are forward-only and do not
promise automatic rollback. Production use requires one serialized runner,
careful review, a verified backup, and a recovery plan.

## Production artifact boundary

The production website document root must point to the deployed `public/`
directory, never the repository root. Application source, configuration,
Composer metadata, `.env`, tests, and `vendor/` must not be directly accessible
through the web server.

Composer dependencies must be resolved and installed before deployment, and
the resulting production artifact must include the required runtime packages.
Serving HTTP requests does not run Composer commands and does not require
Composer or command-line access on the server. Production also does not require
Node.js.

The real production database and user do not exist yet and will be created
later through Plesk. Creating the empty database and applying/importing its
schema are separate operations. Database names, users, passwords, and endpoint
settings are deployment configuration and never belong in migrations.

No final production migration mechanism has been selected. It may later use a
secure CLI/task facility if Plesk supports one, or an explicitly reviewed
manual deployment procedure. It will never use an HTTP migration endpoint.

## Legacy audit tooling

The two source workbooks are local, ignored migration material beneath
`var/legacy-import/`. Run the read-only reproducibility utility from the
repository root with:

```bash
python3 tools/legacy-audit/audit.py
```

It writes only ignored reports and image extracts beneath
`var/legacy-audit/`, verifies source hashes before and after inspection, and
does not connect to a database. See `docs/LEGACY_WORKBOOK_AUDIT.md` for the
reviewed findings. The source workbooks and extracted image library must not be
committed.

## Controlled legacy import

With both ignored source workbooks present, create a database-free import plan
and verification reports with:

```bash
composer legacy-import:plan
```

The command recovers and deduplicates embedded originals under ignored local
output, verifies all 108 historical ratings/ranks through the PHP engine, and
uses the duplicate-decision file. The five fuzzy duplicate candidates are
resolved **DIFFERENT_PRODUCTS**; the reviewed decisions are tracked at
`tools/legacy-import/duplicate-decisions.resolved.json` (copy it into
`var/legacy-import-output/current/duplicate-decisions.json` before re-running
the planner). The apply command refuses unless `APP_ENV` is
`local`/`development`/`testing` and the target has the migrated schema, canonical
testers, and otherwise empty domain tables — so the historical catalogue is
imported into a disposable local database and the resulting SQL + images are
uploaded to production (`docs/DEPLOYMENT.md`).

See `docs/LEGACY_IMPORT.md` before reviewing decisions or running the local
apply command. The importer has no production-force shortcut and is not
available over HTTP.

## Domain schema and rating engine

The migrations create `drinks`, `testers`, `drink_tests`, `ratings`, and
`drink_images`, plus the migrator-owned `schema_migrations` and import-safety
`legacy_import_runs` tables. Derived
rating results are not authoritative columns. The calculation classes under
`src/Domain/Rating/` implement three-tester category averages, weighted and
Excel-compatible rounded Gesamt, competition ranking, and explicit-set
price/performance normalization without database access.

See `docs/DATA_MODEL.md` for exact fields and relationships and
`docs/RATING_SYSTEM.md` for compatibility rules and remaining unknowns.

## Next phase

The deployment procedure is documented (`docs/DEPLOYMENT.md`) and the release
artifacts are reproducible (`tools/build-release.sh` + section 8 of that doc),
but **no production database has been created and nothing has been deployed or
connected to production.** The actual deployment is performed by the project
owner following that document. Do not connect to or create the production/Plesk
database, and do not deploy, without explicit instruction.
