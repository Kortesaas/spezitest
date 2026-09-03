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

The PHP and MariaDB compatibility targets are PHP 8.3 and MariaDB 10.11.
Resource-intensive operations must respect the stated PHP limits. Application
upload limits may be lower than the server's 64M request limits when domain and
security requirements are later defined.

## Configuration and secrets

- Supply environment-specific settings through environment configuration.
- Never commit secrets, credentials, populated `.env` files, or private keys.
- Do not hard-code production paths, domains, database names, or passwords.
- Treat `.env.example` values as local examples, never production defaults.
- Use a least-privilege database account for the running application.
- Keep migration privileges separate from routine application privileges where
  the hosting environment permits it.

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
- Do not expose installation, migration, `phpinfo`, debug, or database
  administration endpoints publicly.
- Log operational failures safely without recording passwords, tokens, or
  unnecessary personal/sensitive data.

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
