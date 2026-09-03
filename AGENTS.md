# AGENTS.md

This file defines non-negotiable constraints for every coding agent working in
this repository. Read it before making changes. More detailed context lives in
`README.md` and `docs/`.

## Current project phase

The repository is in its initial foundation stage. Do not infer that a
framework, database design, deployment process, authentication system, or user
interface has already been selected.

Unless the user explicitly starts a later phase, do not:

- install a framework or dependencies;
- introduce a database or create database tables;
- implement authentication or frontend pages;
- migrate the existing Excel data; or
- deploy anything.

## Immutable domain rules

Spezitest catalogs Cola-Mix / Spezi drinks, primarily from Germany and
surrounding countries.

Each drink is one record with exactly one current lifecycle status:

`identified -> acquired -> tested`

- `identified`: the drink is known to exist, but Spezitest does not currently
  possess it.
- `acquired`: Spezitest physically possesses at least one unit, and it is
  awaiting testing.
- `tested`: the drink has been tested and its test results have been recorded.

These statuses are states/views of the same drink record. Never duplicate a
drink or physically move it between separate datasets merely to represent a
status change.

Historical migration exception: drinks marked red in the old Primärliste are
not currently possessed. They must migrate as `identified`, even though they
appear in the Primärliste.

The future database will be the single source of truth for Spezitest data.
Existing Excel workbooks are migration and verification sources only and will
eventually be retired from day-to-day operation.

There are exactly three permanent testers, with these canonical names:

- Manu
- Fabi
- Schorsch

The existing rating methodology is immutable. Do not invent, simplify, infer,
or modify a formula, weighting, scale, aggregation, or rounding rule. The exact
Excel formulas and rounding semantics are not yet formally verified. Any later
calculation implementation must be verified against the existing Excel
workbooks and historical results before production use. See
`docs/RATING_SYSTEM.md`.

## Architecture and data changes

- Treat the future relational database as the sole operational source of truth.
- Represent lifecycle status on one drink record; do not create status-specific
  drink stores, tables, files, or duplicated records.
- Make database changes only through tracked, reviewable migrations.
- Plan production migrations around backups, rollback or forward-recovery, and
  data safety.
- Do not hard-code production paths, domains, passwords, database names, or
  other environment-specific values.
- Keep configuration in environment variables. Commit only safe examples.

## Production constraints

The application will be publicly accessible at `https://www.spezitest.de` and
must be production-grade rather than a prototype. The known hosting environment
is:

- Plesk shared hosting;
- PHP 8.3 via FPM with Apache;
- MariaDB 10.11 at `localhost:3306` in production;
- PHP `memory_limit` of 256M;
- PHP `upload_max_filesize` and `post_max_size` of 64M;
- OPcache enabled; and
- some PHP process/shell execution functions disabled.

Runtime code must not depend on `shell_exec()`, `exec()`, `system()`,
`passthru()`, `popen()`, or similar functions. Do not assume production
command-line or SSH access exists. Build/development tooling may not require
Node.js to be running on the production server; the deployed application must
run without it. All runtime code must remain compatible with PHP 8.3 and
MariaDB 10.11.

## Security baseline

Every implementation must preserve these requirements:

- Never commit secrets or credentials. Supply them through environment
  configuration.
- Treat every HTTP input as untrusted, including headers, query parameters,
  form bodies, JSON, cookies, and uploaded filenames.
- Use parameterized/prepared statements for every database query involving
  data.
- Require CSRF protection for every state-changing HTTP request.
- Require authentication for all admin functionality.
- Hash passwords only with PHP's secure password APIs (`password_hash()` and
  `password_verify()`); never store or log plaintext passwords.
- Escape user-controlled output for its specific output context.
- Validate uploads by allowed size and actual MIME/content type, store them
  under generated filenames, and prevent executable uploads. Prefer storage
  outside the public document root.
- In production, never display stack traces, SQL errors, local paths, secrets,
  or other sensitive diagnostics.
- Never expose installation, migration, `phpinfo`, debug, or database
  administration endpoints publicly in production.
- Give application database credentials only the privileges required at
  runtime. Use separate, appropriately controlled privileges for migrations.
- Keep dependencies and application code patched, and review changes as
  internet-facing production software.

## Change discipline

Before finishing a task:

1. Check the request against the domain rules and production constraints above.
2. Preserve unrelated user changes in the working tree.
3. Run checks appropriate to the files changed.
4. Review documentation and configuration for contradictions.
5. Report assumptions and anything not verified.

If a request conflicts with an immutable domain rule or the security baseline,
stop and surface the conflict rather than silently implementing it.
