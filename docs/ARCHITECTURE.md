# Architecture

## Purpose and current scope

Spezitest will be an internet-facing production application that catalogs
Cola-Mix / Spezi drinks, primarily from Germany and surrounding countries. This
document records architectural constraints and the PHP/runtime foundation.
Slim Framework 4 with a PSR-7 implementation is selected for HTTP delivery,
with Composer PSR-4 autoloading under the `Spezitest` namespace. Packet 5 adds
the first MariaDB domain schema and an HTTP- and persistence-independent rating
engine. There is still no domain repository, template system, authentication,
admin UI, upload pipeline, or real frontend design.

## Runtime structure

- `public/index.php` is the minimal front controller.
- `config/bootstrap.php` optionally loads local environment configuration and
  constructs the application.
- `src/` contains project classes, including configuration, the application
  factory, database infrastructure, and isolated rating domain logic.
- `tests/` constructs the same application directly without starting a web
  server.

Only `public/` is intended to be the Apache/Plesk document root. Its rewrite
configuration sends requests for non-existing files and directories to the
front controller. Application source, configuration, `.env`, Composer files,
tests, and `vendor/` remain outside the public document root.

The application factory owns route and middleware construction. This keeps the
front controller small and permits HTTP-level tests without a production server
or network access.

## Source of truth

The relational database is the intended single operational source of truth for
Spezitest data. Existing Excel workbooks are migration inputs and rating
verification references only. They will eventually be retired from day-to-day
operation and must not become a parallel live datastore.

Database changes must be expressed as tracked, reviewable migrations. A
production migration plan must address backup, rollback or forward-recovery,
and data safety before execution.

## Database infrastructure

Database settings are read from environment configuration only when database
access is requested. Constructing the Slim application does not connect to the
database. `DatabaseConfiguration` validates required `DB_*` values, and an
explicit `ConnectionFactory` creates independent PDO connections without a
global connection or hidden singleton.

Connections use the MySQL PDO driver, `utf8mb4`, exception error mode,
associative fetches, and native prepared statements. The current layer contains
no ORM, query builder, or domain repository.

`bin/migrate.php` is the only migration entry point and is CLI-only. It uses
the same environment loader and database configuration as future application
database access. SQL files under `database/migrations/` use sortable
`YYYYMMDDHHMMSS_description.sql` names. The runner discovers them in bytewise
filename order, applies only unrecorded versions, records successful versions
and SHA-256 checksums, and rejects changes to already-applied migrations.

The runner creates `schema_migrations` itself because tracking must exist before
the first migration can be recorded. Tracked Packet 5 migrations create
`drinks`, `testers`, `drink_tests`, `ratings`, and `drink_images`, then seed the
three canonical testers. The schema uses InnoDB, utf8mb4, foreign keys, database
checks for controlled states, and restrictive deletes. See `DATA_MODEL.md` for
the authoritative field and relationship description.

There are no automatic destructive down migrations. MariaDB DDL may commit
implicitly, so failed migrations can require manual recovery even when the
version was not recorded.

## Drink identity and lifecycle

One logical drink must correspond to one drink record. It has exactly one
current lifecycle status:

`identified -> acquired -> tested`

Status-specific pages, filters, or lists must be views over the same records.
They must not be implemented as independent datasets between which records are
copied or moved. See `DATA_LIFECYCLE.md` for definitions and the historical
migration rule.

Names are not globally unique and do not have a `UNIQUE(name)` rule. Optional
metadata remains nullable so a drink can be created from name and status alone.
Images are file-backed: only relative references and detected metadata are
modeled, with no BLOB or upload implementation.

## Rating boundary

Rating calculation is a compatibility requirement, not a greenfield design
exercise. The three permanent testers are Manu, Fabi, and Schorsch, identified
in code by stable codes rather than database IDs or display names.

`src/Domain/Rating/` contains small services/value objects for category and
Gesamt calculation, exact decimal input handling, isolated Excel-compatible
rounding, competition ranking, and price/performance normalization. These
classes do not query MariaDB and do not depend on Slim. Persistence stores the
raw tester inputs; category averages, Gesamt, rank, and normalized
price/performance are derived.

Ranking and price/performance services operate only on explicit comparison
populations. A future application query/service boundary is responsible for
selecting completed tests with valid official results; the mathematics does
not hard-code workbook ranges. Formula details, historical quirks, and the
remaining Excel boundary verification gate are in `RATING_SYSTEM.md`.

## Future application boundaries

Any later implementation should separate at least these responsibilities even
if the chosen structure uses different names:

- HTTP delivery and request handling;
- input validation and authorization;
- domain behavior for drinks, lifecycle, and ratings;
- database persistence;
- presentation and context-appropriate output escaping; and
- environment-specific configuration and operational logging.

This separation is a constraint on responsibilities, not a requirement to
adopt a particular framework or architectural pattern.

Only the intended public entry point and static public assets may live under
the web document root. Configuration, source files, logs, migrations, private
uploads, credentials, Composer metadata, dependencies, and tests must not be
directly web-accessible. Uploaded files should normally be stored outside the
document root and served only through a controlled mechanism when access is
required.

## Environment portability

Do not hard-code production paths, domains, passwords, or database names.
Environment-specific values must be supplied through configuration. Deployed
runtime code must be compatible with PHP 8.3 and MariaDB 10.11, work without
Node.js running on the server, and not rely on command-line access or disabled
PHP process/shell functions.

Composer installs the dependency artifact before deployment. The HTTP runtime
loads that artifact but neither executes Composer nor requires Composer to be
installed on the production host.

## Security architecture baseline

The design must assume that every HTTP input is hostile until validated.
Database access must use prepared/parameterized statements. State-changing
requests require CSRF protection, and all admin functionality requires
authentication and authorization. Password handling must use PHP's secure
password hashing APIs.

Output must be escaped for its destination context. Upload handling must check
both permitted size and actual MIME/content, generate storage filenames, and
make executable uploads impossible. Production error responses and logs must
not disclose stack traces, SQL errors, local paths, credentials, or secrets to
users.

No publicly accessible installer, migration runner, `phpinfo`, debug console,
or database administration endpoint may exist in production. Runtime database
credentials must follow least privilege.

Slim error middleware is configured explicitly. Detailed error responses are
available only outside production when `APP_DEBUG` is explicitly enabled.
Production forces error detail off and logs application exceptions server-side
through the configured PSR-3 strategy without returning internal exception
messages to visitors.
