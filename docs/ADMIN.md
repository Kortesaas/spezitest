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
| GET | `/admin/test` | Queue of acquired Spezis waiting for a test |
| GET | `/admin/drinks/{id}/test` | Nine-grade test-entry form (draft or completed) |
| POST | `/admin/drinks/{id}/test` | Save a draft test (partial grades allowed) |
| POST | `/admin/drinks/{id}/test/complete` | Validate all nine grades, run the engine, set `tested` |
| GET | `/admin/drinks/{id}/test/result` | Ranking result, place blurred until revealed |

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

Creation requires only a nonblank name of at most 255 bytes and a status; a
new record may be created as `identified` or `acquired` — `tested` requires an
existing completed test and cannot be fabricated by a status-only action.
Imported/existing tested records remain editable. Every other field (picture,
Hersteller, Ort, Region/Land, Notizen, Preis/Menge) is optional and never a
prerequisite for creating the record, but the dedicated `/admin/drinks/new`
page shows all of them inline (product-owner decision) so a newly added Spezi
can be made theoretically test-ready — acquired, priced, with a picture and
notes — in one step. The dashboard's separate "Schnell erfassen" widget still
posts to the same endpoint with only name + status + optional picture, for
fast logging. Every value is validated server-side and escaped when rendered.

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

`/admin/test` lists every `acquired` drink as a picker for "which Spezi do we
test next" — the same query the dashboard's waiting queue uses, without its
6-row cap. Selecting one opens `/admin/drinks/{id}/test`, available for a
drink that is `acquired` (or already `tested`, for corrections). An
`identified` drink must be moved to `acquired` first.

Grading is grouped **by category**, not by tester: one panel each for Optik,
Süffigkeit and Geschmack, each containing all three testers' grade scales side
by side, in that order. Each of the three canonical testers (Manu, Fabi,
Schorsch) still grades every category as an integer 0–10, higher is better,
and a tester's three grades are entered together or not at all, so every saved
row maps cleanly onto the `ratings` table. An optional tasting note is stored
on `drink_tests.notes`. The test price is not entered here — it lives on the
drink itself (see below) and is shown read-only in the summary panel with a
link to the edit form.

"Zwischenspeichern" persists a partial draft and leaves the drink on `acquired`.
"Test abschließen" requires all nine grades: the raw grades are stored, the
verified `RatingCalculator` is asked for an official result, and in one
database transaction the test becomes `completed` and the drink becomes
`tested`. An incomplete rating set returns 422 and changes nothing. Category
averages, Gesamt and rank are always derived on read and never written.

Completing a test redirects to `/admin/drinks/{id}/test/result`, which shows
the Gesamtwertung immediately but blurs the Gesamt rank/place, its price-
performance rank/place, and both drinks' neighbors above/below until a single
"Aufdecken" click reveals them together (a spoiler, so the group can guess
first — see `public/assets/spezitest.css`'s `[data-reveal-scope]`/`.spoiler`
rule and the matching click handler in `spezitest.js`). A drink with no price
set shows "Kein Preis erfasst" for that half, never blurred. The page's
primary action returns to `/admin/test` for the next Spezi.

## Drink price (Preis/Leistung basis)

`price_amount` / `price_volume_ml` are entered on the drink's own edit form,
alongside Hersteller/Ort/Region/Notizen — not on the grading form — because
price is a fact about the acquired product, entered once before tasting. Both
are optional but must be given together (enforced by validation and a
database `CHECK`). The edit form shows a live client-side preview of the
resulting price per 0.5 L (`spezitest.js`); the server always recomputes the
authoritative value via `Spezitest\Domain\Rating\PriceNormalizer`. See
`docs/RATING_SYSTEM.md` for how this feeds Preis/Leistung.

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
