# Production

## Known environment

Spezitest is intended for the real, publicly accessible production site at
`https://www.spezitest.de`. The currently known hosting environment is:

- shared hosting administered through Plesk;
- PHP 8.3;
- PHP-FPM behind Apache;
- MariaDB 10.11;
- production database endpoint `localhost:3306`;
- PHP `memory_limit = 256M`;
- PHP `upload_max_filesize = 64M`;
- PHP `post_max_size = 64M`; and
- OPcache enabled.

These are compatibility constraints, not permission to hard-code a production
domain, path, database name, username, or credential in application code.

## Runtime and deployment constraints

Some PHP system and shell execution functions are disabled. Application
runtime and web-based maintenance paths must not call or depend on
`shell_exec()`, `exec()`, `system()`, `passthru()`, `popen()`, or equivalent
process execution facilities.

Do not assume SSH or command-line access is available on the production server.
Any eventual deployment and operations design must accommodate the capabilities
Plesk/shared hosting actually provides. Production application code must run
without Node.js. If build tooling is introduced in a later phase, it must
produce deployable artifacts before or during an available deployment step;
Node.js must not be a production runtime dependency.

The Plesk website document root must be configured to the deployed `public/`
directory. The repository or release root must not be exposed. This keeps
source, configuration, `.env`, Composer metadata, dependencies, tests, and
other internals outside direct HTTP access.

Composer dependencies must be resolved and installed before deployment so the
production artifact already contains the required runtime packages. Processing
HTTP requests does not invoke Composer and does not require Composer, PHP CLI,
or shell access on the production server.

The PHP and MariaDB compatibility targets are PHP 8.3 and MariaDB 10.11.
Resource-intensive operations must respect the stated PHP limits. Application
upload limits may be lower than the server's 64M request limits when domain and
security requirements are later defined.

## Database provisioning and migrations

The production database and database user do not exist yet. They will later be
created manually through Plesk. Creating that empty database and applying or
importing its schema are separate operations.

Database host, port, database name, username, password, and charset are
deployment configuration. They must not be embedded in application code or SQL
migrations. The currently known production endpoint is `localhost:3306`, but
those values still come from `DB_HOST` and `DB_PORT`.

Migrations are forward-only SQL files tracked in `schema_migrations` and run by
the CLI-only `bin/migrate.php` command. The runner itself creates the tracking
table. Packet 5 migrations define the domain tables and canonical tester seed,
but they have been exercised only against disposable local MariaDB 10.11; they
have not been applied to production. There is no HTTP migration endpoint.

The final production execution mechanism is intentionally undecided. Depending
on verified Plesk capabilities, migrations may later run through a secure CLI
or task mechanism, or through an explicitly reviewed manual deployment
process. No convenient production shell access is assumed.

MariaDB DDL may implicitly commit, so automatic transactional rollback cannot
be promised. Every production migration needs serialized execution, careful
review, a verified restorable backup, and a specific recovery plan. Failed DDL
may require operator intervention even when the migration version was not
recorded. Automatic destructive down migrations are not used.

## Configuration and secrets

- Supply environment-specific settings through environment configuration.
- Never commit secrets, credentials, populated `.env` files, or private keys.
- Do not hard-code production paths, domains, database names, or passwords.
- Treat `.env.example` values as local examples, never production defaults.
- When no `.env` file exists, supply configuration through actual environment
  variables; `.env` loading is optional.
- Use a least-privilege database account for the running application.
- Restrict the production account to the Spezitest database, not every database
  in the Plesk subscription.
- Keep migration privileges separate from routine application privileges where
  the hosting environment permits it.
- Never print or log `DB_PASSWORD`.

## Internet-facing security baseline

This is production software, not a prototype. Before public release, every
applicable control below is mandatory:

- Treat all HTTP input as untrusted and validate it against explicit rules.
- Use parameterized/prepared statements for database access.
- Protect every state-changing HTTP request against CSRF.
- Require authentication and authorization for admin functionality.
- Use PHP's `password_hash()`, `password_verify()`, and supported secure
  password algorithms for passwords.
- Escape user-controlled output for HTML, attributes, URLs, JavaScript, JSON,
  headers, or other relevant output contexts.
- Validate uploads for an application-defined allowed size and actual
  MIME/content type rather than trusting filenames or client headers.
- Store uploads under generated filenames, outside the public document root
  where practical, and configure storage/serving so uploaded content can never
  execute as code.
- Disable public display of stack traces, SQL errors, local filesystem paths,
  secrets, and sensitive configuration.
- Keep `APP_DEBUG` disabled in production. Application behavior must not rely
  on PHP `display_errors`; handled failures return generic responses while
  details are logged server-side.
- Do not expose installation, migration, `phpinfo`, debug, or database
  administration endpoints publicly.
- Log operational failures safely without recording passwords, tokens, or
  unnecessary personal/sensitive data.

## Future image storage

Product images will normally live as files on production webspace, with the
database storing relative references and detected metadata rather than image
BLOBs. Image upload remains optional for basic drink creation.

Future upload handling must distrust extensions and all user-controlled paths,
verify actual image content/type, enforce size limits, generate internal
filenames, prevent executable content, and fail safely during validation or
processing. No image upload or processing technology is implemented yet, and
GD/Imagick availability is not assumed.

## Database change safety

All database changes must use tracked migrations. Before running a production
migration, the future release procedure must define:

1. a verified, restorable backup appropriate to the change;
2. the migration's compatibility with the deployed application version;
3. rollback or forward-recovery steps, including handling for irreversible data
   transformations;
4. validation checks after migration; and
5. a plan that stays within shared-hosting resource and access constraints.

No web-accessible general-purpose migration or installation endpoint may be
left in production.

## Production release gate

The eventual release process must confirm at minimum:

- configuration and secrets are supplied securely;
- debug output is disabled;
- admin access, CSRF protection, validation, output escaping, and upload
  controls have been tested;
- database credentials are least-privilege;
- migrations have backup and recovery plans;
- rating calculations have been verified against the source Excel formulas,
  their exact rounding semantics, and historical results; and
- the deployed artifact has no Node.js or PHP shell-execution runtime
  dependency.
