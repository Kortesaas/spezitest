# Spezitest

Spezitest is intended to become the production web application for cataloging
Cola-Mix / Spezi drinks, primarily from Germany and surrounding countries. The
eventual public site will be available at
[spezitest.de](https://www.spezitest.de).

## Project status

This repository is in the **initial foundation stage**. It currently documents
the domain invariants, production constraints, security baseline, data
lifecycle, and rating-system verification requirements.

There is deliberately no framework, application implementation, database
schema, authentication system, frontend, Excel migration, or deployment in
this stage.

## Repository organization

- `AGENTS.md`: mandatory guidance for future coding agents.
- `docs/ARCHITECTURE.md`: architectural boundaries and future design
  constraints.
- `docs/PRODUCTION.md`: known hosting environment, deployment constraints, and
  production security requirements.
- `docs/DATA_LIFECYCLE.md`: the canonical lifecycle of a drink and historical
  migration classification.
- `docs/RATING_SYSTEM.md`: immutable rating-methodology requirements and the
  verification gate for future implementation.
- `.env.example`: safe names and placeholder values for environment-based
  configuration.
- `.gitignore`: excludes local configuration, generated artifacts, and common
  development files.

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
Apache, plus MariaDB 10.11. Runtime code must work without Node.js, production
shell access, or PHP shell/process execution functions. See
`docs/PRODUCTION.md` for the complete baseline.

## Configuration

Future runtime configuration must come from the environment. `.env.example`
contains safe local placeholders only. Never commit a populated `.env` file or
real credentials.

## Next phase

No next-phase work should begin without explicit instruction. In particular,
do not select or install a framework, design tables, implement pages or
authentication, migrate Excel data, or deploy based only on this foundation.
