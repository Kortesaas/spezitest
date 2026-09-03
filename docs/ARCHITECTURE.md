# Architecture

## Purpose and current scope

Spezitest will be an internet-facing production application that catalogs
Cola-Mix / Spezi drinks, primarily from Germany and surrounding countries. This
document records architectural constraints, not an implemented design. No
framework, schema, or concrete component layout is selected in the foundation
stage.

## Source of truth

The future relational database will be the single operational source of truth
for Spezitest data. Existing Excel workbooks are migration inputs and rating
verification references only. They will eventually be retired from day-to-day
operation and must not become a parallel live datastore.

Database changes must be expressed as tracked, reviewable migrations. A
production migration plan must address backup, rollback or forward-recovery,
and data safety before execution.

## Drink identity and lifecycle

One logical drink must correspond to one drink record. It has exactly one
current lifecycle status:

`identified -> acquired -> tested`

Status-specific pages, filters, or lists must be views over the same records.
They must not be implemented as independent datasets between which records are
copied or moved. See `DATA_LIFECYCLE.md` for definitions and the historical
migration rule.

## Rating boundary

Rating calculation is a compatibility requirement, not a greenfield design
exercise. The three permanent testers are Manu, Fabi, and Schorsch. The
existing methodology must remain exactly unchanged.

No calculation code may be derived from memory or assumptions. Before a future
implementation can enter production, its formulas, inputs, weighting,
aggregation, and exact rounding behavior must be established from the existing
Excel workbooks and verified against historical results. See
`RATING_SYSTEM.md`.

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

Only the intended public entry point and static public assets should live under
the web document root. Configuration, source files, logs, migrations, private
uploads, and credentials should not be directly web-accessible. Uploaded files
should normally be stored outside the document root and served only through a
controlled mechanism when access is required.

## Environment portability

Do not hard-code production paths, domains, passwords, or database names.
Environment-specific values must be supplied through configuration. Deployed
runtime code must be compatible with PHP 8.3 and MariaDB 10.11, work without
Node.js running on the server, and not rely on command-line access or disabled
PHP process/shell functions.

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
