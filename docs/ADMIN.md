# Admin Application

## Scope

The admin (Design System admin shell as of Packet 8, deliberately compact)
supports a single environment-configured administrator, lifecycle counts, drink
search/filtering and CRUD, status changes, one optional primary image, and
test/rating entry. It does not provide accounts, roles, self-registration,
password reset, or an image gallery. The public website is separate (see
`README.md`); admin image uploads are also read on the public detail pages
through a controlled route.

## Configuration

The admin has no default credential. Set both of these outside Git:

- `ADMIN_USERNAME`: the initial administrator's login name;
- `ADMIN_PASSWORD_HASH`: output produced by PHP `password_hash()` for the
  administrator's password.

The plaintext password must never be stored in `.env`, logs, source, or
documentation. If either value is absent, login safely remains unavailable.
Malformed or half-configured credentials stop application construction rather
than silently weakening authentication.

Additional settings are:

- `ADMIN_SESSION_NAME`, default `SPEZITEST_ADMIN`;
- `ADMIN_IMAGE_STORAGE_ROOT`, default `var/admin-images`, resolved relative to
  the application root when not absolute; and
- `ADMIN_IMAGE_MAX_BYTES`, default 5,242,880 bytes and hard-capped at the known
  64 MiB server request limit.

The upload root is rejected if it resolves beneath `public/`. It must be a
private writable directory retained across application releases. The optional
`LEGACY_IMAGE_STORAGE_ROOT` lets the authenticated image response serve
existing controlled-import images using their portable `legacy/...` paths.

## Routes

The login form and submission are the only unauthenticated admin routes. Login
is still CSRF-protected.

| Method | Route | Purpose |
| --- | --- | --- |
| GET | `/admin/login` | Login form |
| POST | `/admin/login` | Verify configured identity and start session |
| GET | `/admin` | Lifecycle dashboard |
| POST | `/admin/logout` | Destroy session |
| GET | `/admin/drinks` | List, search, and lifecycle filter |
| GET | `/admin/drinks/new` | Quick-create form |
| POST | `/admin/drinks` | Create from name, status, and optional picture |
| GET | `/admin/drinks/{id}/edit` | Metadata and primary-image edit form |
| POST | `/admin/drinks/{id}` | Update drink and optionally replace/remove image |
| POST | `/admin/drinks/{id}/status` | Change lifecycle on the existing record |
| GET | `/admin/drinks/{id}/delete` | Explicit delete confirmation |
| POST | `/admin/drinks/{id}/delete` | Delete when no restrictive dependencies exist |
| GET | `/admin/drinks/{id}/image` | Authenticated primary-image response |
| GET | `/admin/drinks/{id}/test` | Nine-grade test-entry form (draft or completed) |
| POST | `/admin/drinks/{id}/test` | Save a draft test (partial grades allowed) |
| POST | `/admin/drinks/{id}/test/complete` | Validate all nine grades, run the engine, set `tested` |

All state changes use POST and require a session-bound random CSRF token.
Unauthenticated protected requests redirect to `/admin/login`. Production
responses do not expose exception messages, SQL errors, stack traces, local
paths, or credentials.

## Authentication and sessions

Passwords are verified only with `password_verify()` against the configured
secure hash. Successful login regenerates the PHP session identifier. Logout
destroys session data and expires its cookie. Production sessions use strict
cookie-only mode and a Secure, HttpOnly, SameSite=Strict cookie. Admin
responses are non-cacheable and add a restrictive Content Security Policy,
frame denial, no-referrer policy, and MIME sniffing protection.

There is intentionally no HTTP route that creates credentials, changes the
password, resets access, runs migrations/imports, displays configuration, or
provides debugging/database administration.

## Drink validation and persistence

Quick creation requires only a nonblank name of at most 255 bytes and a status.
Because this packet does not implement test/rating entry, a new record may be
created as `identified` or `acquired`; `tested` requires an existing completed
test and cannot be fabricated by a status-only action. Imported/existing tested
records remain editable. A picture is optional. Manufacturer, location,
region, and notes remain optional and are available only on the edit form.
Every value is validated server-side and escaped when rendered.

Names are intentionally not unique. Creating two records with the same name is
valid and never triggers an automatic merge. Lifecycle changes update the same
drink row. Queries use native PDO prepared statements; there is no ORM.

Deletion has a separate confirmation page. Existing foreign-key restrictions
continue to prevent deletion of a drink with test history. That failure is
shown as a generic domain message rather than a MariaDB error.

## Primary images

The validator ignores the client filename and declared MIME type. It reads the
payload within the configured byte limit and requires agreement between
Fileinfo and PHP's image parser for JPEG, PNG, or WebP, plus positive pixel
dimensions. The file is written with restricted permissions under a random
48-hex-character filename. Only its portable `admin/...` path and detected
metadata enter `drink_images`.

Image creation/replacement is coordinated with the database transaction. A
new file is removed if persistence fails. Replaced or removed files are
deleted only after the database commit, preventing a rollback from leaving a
database row pointing at an already-deleted primary image. Database paths are
resolved only through the configured `admin/` or `legacy/` storage roots and
are never constructed from route or form input.

Uploaded files live outside the web document root and are streamed only after
admin authentication. Generated non-executable extensions, private storage,
controlled response MIME, CSP, and `X-Content-Type-Options: nosniff` prevent an
upload from becoming executable application code.

## Test / rating entry

`/admin/drinks/{id}/test` is available for a drink that is `acquired` (or
already `tested`, for corrections). An `identified` drink must be moved to
`acquired` first.

Each of the three canonical testers (Manu, Fabi, Schorsch) grades Optik,
Süffigkeit and Geschmack as an integer 0–10, higher is better. A tester's three
grades are entered together or not at all, so every saved row maps cleanly onto
the `ratings` table. An optional test price is parsed from German or plain
decimal notation and stored on `drink_tests.price_amount`; an optional note is
stored on `drink_tests.notes`.

"Zwischenspeichern" persists a partial draft and leaves the drink on `acquired`.
"Test abschließen" requires all nine grades: the raw grades are stored, the
verified `RatingCalculator` is asked for an official result, and in one
database transaction the test becomes `completed` and the drink becomes
`tested`. An incomplete rating set returns 422 and changes nothing. Category
averages, Gesamt and rank are always derived on read and never written.

## Current limitations

- GD and Imagick availability on production hosting is not confirmed. Packet 7
  retains the validated original and performs no resize, recompression, or
  conversion; optimization remains pending.
- Production Fileinfo and PHP image-parser/WebP capability still require a
  deployment check. An unsupported format fails closed as an invalid image.
- Only one configured administrator exists. Account management, multiple
  users, roles, password changes, reset/recovery, and audit logging require a
  later reviewed packet.
- The UI is functional but intentionally not a final design.
- Filesystem and database updates cannot share one atomic transaction. The
  service uses staging/cleanup ordering and logs a generic cleanup failure, but
  an operational disk failure after commit can still require private-storage
  reconciliation.
- Rating/test entry and public presentation are outside this packet.
