# Spezitest

Spezitest is intended to become the production web application for cataloging
Cola-Mix / Spezi drinks, primarily from Germany and surrounding countries. The
eventual public site will be available at
[spezitest.de](https://www.spezitest.de).

## Project status

This repository is in the **PHP application-foundation stage**. It contains a
minimal Slim Framework 4 application, a production-safe error-handling
baseline, and automated smoke checks. The single placeholder page is not the
real website design.

There is deliberately no database code or schema, Spezi domain implementation,
rating implementation, authentication, admin panel, frontend framework, Excel
migration, or deployment automation in this stage.

## Repository organization

- `AGENTS.md`: mandatory guidance for future coding agents.
- `docs/ARCHITECTURE.md`: architectural boundaries and runtime structure.
- `docs/PRODUCTION.md`: hosting, deployment, and production security
  constraints.
- `docs/DATA_LIFECYCLE.md`: the canonical lifecycle of a drink and historical
  migration classification.
- `docs/RATING_SYSTEM.md`: immutable rating-methodology requirements and the
  verification gate for future implementation.
- `public/`: the only intended web document root and the minimal front
  controller.
- `src/`: application configuration and construction code.
- `config/`: environment loading and application bootstrap.
- `tests/`: HTTP-level application smoke tests.
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
datasets. The future database will be the single source of truth; existing
Excel workbooks are migration and verification sources only.

The permanent testers are Manu, Fabi, and Schorsch. The existing rating
methodology must remain exactly unchanged. Its formulas and rounding semantics
must be verified from the Excel workbooks and historical results before any
implementation is used in production.

## Production target

The known target is Plesk shared hosting with PHP 8.3 running through FPM and
Apache, plus MariaDB 10.11 in a later phase. Runtime code must work without
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

## Local development

Prerequisites are PHP 8.3 and Composer 2. No database, external service,
production credential, Node.js installation, or frontend toolchain is needed.

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

## Next phase

No next-phase work should begin without explicit instruction. In particular,
do not design tables, implement domain records, ratings, real pages,
authentication or admin functionality, migrate Excel data, or deploy based
only on this foundation.
