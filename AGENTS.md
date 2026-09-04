# AGENTS.md

This file defines non-negotiable constraints for every coding agent working in
this repository. Read it before making changes. More detailed context lives in
`README.md` and `docs/`.

## Current project phase

Packet 8 turns the application into a locally usable Spezitest beta on top of
the Packet 5 domain/rating foundation, the Packet 6 controlled importer and the
Packet 7 admin. It adds admin test/rating entry, a real public website styled
with the approved Spezitest Design System, controlled public image serving, and
statistics derived only from real data. The runtime uses PHP 8.3, Composer,
Slim Framework 4, a PSR-7 implementation, optional local `.env` loading, and a
small lazy PDO connection layer. PHPUnit and PHPStan (level max) are the
development quality tools. The application uses `public/` as its only intended
web document root. Database changes use CLI-only, forward SQL migrations outside
`public/`; Packet 8 added no migrations (test entry uses the existing
`drink_tests` / `ratings` tables). The Python utilities in `tools/legacy-audit/`
and `tools/legacy-import/` are local migration/audit tooling and are not
production runtime code.

The public site and admin share one vendored stylesheet at
`public/assets/spezitest.css`, generated from the "Spezitest Design System"
project. Frontend work must follow that design system and must not invent a new
visual style. The rating input scale is **integers 0–10 inclusive, higher is
better** (re-verified against all 972 historical grade values; see
`docs/RATING_SYSTEM.md`); the Gesamtwertung is the verified weighted sum (≈0–60,
higher is better), ranked descending. Test/rating entry uses the verified engine
only; an incomplete rating set can never complete a test, and a completed test
moves the drink to `tested` in one transaction.

The project is at release-preparation stage. The five fuzzy duplicate
candidates are resolved **DIFFERENT_PRODUCTS**
(`tools/legacy-import/duplicate-decisions.resolved.json`). Deployment is
documented in `docs/DEPLOYMENT.md`; the release artifact is built by
`tools/build-release.sh`. **No production database exists and nothing has been
deployed.** Do not connect to or create the production database, run the legacy
importer against production (its `APP_ENV` guard forbids it anyway), or deploy,
without explicit instruction.

The implemented domain tables are `drinks`, `testers`, `drink_tests`,
`ratings`, and `drink_images`; `schema_migrations` and `legacy_import_runs` are
infrastructure. The rating services live under `src/Domain/Rating/`. See
`docs/DATA_MODEL.md` and `docs/RATING_SYSTEM.md` before changing either design.
Never edit an applied
migration; add a reviewed forward migration.

Unless explicitly performing the reviewed Packet 6 workflow documented in
`docs/LEGACY_IMPORT.md`, do not import or merge workbook data. Read
`docs/ADMIN.md` before modifying authentication, sessions, admin persistence,
or images. Unless the user explicitly starts a later phase, do not:

- add self-registration, password reset, multiple accounts, roles, a gallery,
  or automatic image processing;
- introduce a frontend build pipeline or a Node.js runtime dependency;
- change the rating methodology, scale, weighting, aggregation, or rounding;
- auto-merge fuzzy legacy duplicates, or change a reviewed duplicate decision;
- run the legacy importer against production, or import legacy data by any path
  other than the reviewed SQL import in `docs/DEPLOYMENT.md`;
- connect to or create the production database; or
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

The database will be the single source of truth for Spezitest data once
populated.
Existing Excel workbooks are migration and verification sources only and will
eventually be retired from day-to-day operation.

Adding a newly discovered Spezi must require as little effort as reasonably
possible: minimum required information first, optional enrichment later. Basic
drink creation must never depend on optional metadata. A future phone workflow
must support quickly recording a name, lifecycle status, and optionally one
primary product picture.

The quick-create form stays minimal: name, status, optional picture. Do not add
optional metadata to the create form; it belongs on the edit form after
creation. Duplicate names remain valid and must not be rejected or silently
merged. A drink reaches `tested` only by completing a test through the
test-entry workflow (all nine grades present, run through the verified engine);
a status-only action must never fabricate it. Existing/imported tested records
remain editable without inventing new test data.

There are exactly three permanent testers, with these canonical names:

- Manu
- Fabi
- Schorsch

The existing rating methodology is immutable. Do not invent, simplify, infer,
or modify a formula, weighting, scale, aggregation, or rounding rule. Packet 5
implements the Packet 4 workbook findings and checks all historical golden
cases. Several edge semantics remain explicitly unresolved in
`docs/RATING_SYSTEM.md`; do not promote one to a rule. Rating mathematics must
remain independent of HTTP and persistence. Do not replace exact decimal input
handling or the isolated Excel-compatible rounding policy with an assumed
floating-point equivalent. Any change must continue to pass the golden
fixtures and must be rechecked against the source workbooks and historical
results before production use.

## Architecture and data changes

- Treat the relational database as the sole operational source of truth once
  populated; never create a parallel live workbook datastore.
- Represent lifecycle status on one drink record; do not create status-specific
  drink stores, tables, files, or duplicated records.
- Store the raw rating entered by each canonical tester. Category averages,
  Gesamt, rank, and normalized price/performance are derived and must not become
  ordinary manually editable authoritative columns.
- Tester application logic must use stable codes (`manu`, `fabi`, `schorsch`),
  never assume database IDs 1, 2, and 3, and never key behavior from display
  name spelling.
- Preserve non-unique drink names. Never add `UNIQUE(name)` as a substitute for
  a reviewed duplicate-matching policy.
- Preis/Leistung uses all currently eligible completed/tested Spezis with a
  valid positive price. Preserve the formula but never reproduce fixed Excel
  row ranges as permanent application behavior.
- Never auto-merge fuzzy legacy candidates. Only the four independently
  corroborated exact cross-workbook pairs are preapproved; every fuzzy decision
  must come from the external review file.
- Legacy import must remain CLI-only, verify source hashes, require an otherwise
  empty target, record its run, and store generated image filenames outside
  `public/`. It must not become a normal Python-dependent HTTP path.
- All `/admin` routes except the login form/submission require the existing
  session authentication middleware. Every state-changing route, including
  login and logout, requires the existing CSRF middleware.
- The initial admin username and `password_hash()` output come only from
  environment configuration. Never add a default password, committed hash,
  registration route, reset route, or plaintext credential logging.
- Ordinary primary images are validated originals only: accept detected
  JPEG/PNG/WebP within the configured size limit, use generated filenames,
  store portable relative paths outside `public/`, and serve them through the
  authenticated controller. Do not trust client names or MIME headers.
- Keep image replacement/removal coordinated with database transactions and
  never construct a filesystem path directly from HTTP input.
- Make database changes only through tracked, reviewable migrations.
- Never expose migration execution over HTTP. Migrations are CLI/task or an
  explicitly reviewed manual deployment concern only.
- Treat migrations as forward changes. Do not add automatic destructive down
  migrations; MariaDB DDL may commit implicitly.
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
- Do not rely on PHP `display_errors` for safe production responses. Keep Slim's
  production error details disabled and return generic client-facing failures.
- Never expose installation, migration, `phpinfo`, debug, or database
  administration endpoints publicly in production.
- Never use `eval`, execute a user-provided path, or select an included file
  dynamically from untrusted HTTP input.
- Do not suppress security-relevant failures with PHP's `@` operator.
- Give application database credentials only the privileges required at
  runtime. Use separate, appropriately controlled privileges for migrations.
- Ensure the production database user is restricted to the Spezitest database,
  not every database within the Plesk subscription.
- Never place `DB_PASSWORD` in output or logs.
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
