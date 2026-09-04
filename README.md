# Spezitest

Spezitest is intended to become the production web application for cataloging
Cola-Mix / Spezi drinks, primarily from Germany and surrounding countries. The
eventual public site will be available at
[spezitest.de](https://www.spezitest.de).

## Project status

This repository is at the **Packet 5 domain-foundation stage**. It contains a
minimal Slim Framework 4 application, a lazy production-safe PDO connection
layer, CLI-only forward migrations, the first real Spezitest domain schema, an
independently testable rating engine, automated checks, and the Packet 4
forensic workbook findings. The single placeholder page is not the real
website design.

There is deliberately no authentication, admin panel, public drink browser,
Excel import, image upload/processing, or deployment automation yet. No
production database has been created or contacted.

## Repository organization

- `AGENTS.md`: mandatory guidance for future coding agents.
- `docs/ARCHITECTURE.md`: architectural boundaries and runtime structure.
- `docs/PRODUCTION.md`: hosting, deployment, and production security
  constraints.
- `docs/DATA_LIFECYCLE.md`: the canonical lifecycle of a drink and historical
  migration classification.
- `docs/DATA_MODEL.md`: implemented tables/relationships and clearly separated
  future or unresolved decisions.
- `docs/RATING_SYSTEM.md`: workbook-verified rating formulas, historical
  quirks, unresolved edge semantics, and the production verification gate.
- `docs/LEGACY_WORKBOOK_AUDIT.md`: workbook structure, lifecycle, overlap,
  image, metadata, anomaly, and migration-risk findings.
- `public/`: the only intended web document root and the minimal front
  controller.
- `src/`: application/configuration code, database infrastructure, and isolated
  rating domain logic.
- `config/`: environment loading and application bootstrap.
- `tests/`: application tests and machine-readable historical golden fixtures.
- `tools/legacy-audit/`: local read-only OOXML audit tooling; never production
  runtime code.
- `bin/migrate.php`: CLI-only migration command; never an HTTP endpoint.
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

Basic drink creation must remain quick: minimum required information first,
optional enrichment later. A future image is optional and will normally be a
file on webspace referenced by database metadata, not a MariaDB BLOB.

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

## Local development

Prerequisites are PHP 8.3 with PDO MySQL and Composer 2. Ordinary unit tests and
the placeholder application need no running database, external service,
production credential, Node.js installation, or frontend toolchain.

```bash
composer install
cp .env.example .env
composer check
composer serve
```

The placeholder application is then available at `http://127.0.0.1:8080`.
`composer serve` uses PHP's built-in server and is for local development only.
It is not a production start command.

Individual quality commands are:

```bash
composer test
composer analyse
```

`composer check` deliberately runs unit tests and static analysis only. It does
not connect to or mutate a database.

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

Run the integration suite in another terminal:

```bash
composer test:integration
```

The integration suite creates, constrains, inspects, and removes the Packet 5
domain schema and migration-infrastructure test objects in that disposable
database. It is destructive within the configured database. Never point it at
production or a database containing data you care about.

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

## Domain schema and rating engine

The migrations create `drinks`, `testers`, `drink_tests`, `ratings`, and
`drink_images`, plus the migrator-owned `schema_migrations` table. Derived
rating results are not authoritative columns. The calculation classes under
`src/Domain/Rating/` implement three-tester category averages, weighted and
Excel-compatible rounded Gesamt, competition ranking, and explicit-set
price/performance normalization without database access.

See `docs/DATA_MODEL.md` for exact fields and relationships and
`docs/RATING_SYSTEM.md` for compatibility rules and remaining unknowns.

## Next phase

Packet 6 is the controlled legacy import/migration tooling, but it must not
begin without explicit instruction. Do not import or merge workbook data,
extract/import production images, add upload handling, build real pages, add
authentication/admin functionality, create/connect to production, or deploy
based only on Packet 5.
