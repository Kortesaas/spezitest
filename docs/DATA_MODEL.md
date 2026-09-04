# Data Model

## Implemented in Packet 5

The MariaDB domain schema is defined by the tracked migrations in
`database/migrations/`. It is the first operational model for Spezitest, not a
literal copy of either workbook. MariaDB will be the single operational source
of truth; the Excel workbooks remain migration and verification inputs only.

The schema stores authoritative facts and relationships. It deliberately does
not store category averages, Gesamtwertung, rank, or normalized
Preis/Leistung as editable data. Those values are reproducibly calculated from
raw ratings and the explicitly selected comparison population.

All tables use InnoDB, `utf8mb4`, and `utf8mb4_unicode_ci`. Internal identifiers
are unsigned numeric primary keys. Foreign-key deletion is restrictive so a
drink, test, or tester cannot silently take historical facts with it.

### `drinks`

One row is one persistent Cola-Mix product. The minimum creation facts are
`name` and `lifecycle_status`; all enrichment fields are nullable.

| Column | Type | Purpose |
| --- | --- | --- |
| `id` | `BIGINT UNSIGNED` | Stable internal identity |
| `name` | `VARCHAR(255)` | Required, nonblank product name |
| `lifecycle_status` | `VARCHAR(16)` | Exactly `identified`, `acquired`, or `tested` |
| `manufacturer` | `VARCHAR(255) NULL` | Free-text manufacturer/brand when known |
| `origin_location` | `VARCHAR(255) NULL` | Raw locality/origin text when known |
| `origin_region` | `VARCHAR(128) NULL` | Raw region text when known |
| `notes` | `TEXT NULL` | Optional operational notes |
| `created_at`, `updated_at` | `DATETIME(6)` | Record timestamps |

MariaDB enforces both the nonblank name and allowed lifecycle values. There is
intentionally no `UNIQUE(name)`: audited history contains same-name products
that are not necessarily the same drink. Lifecycle lists are views over this
single table; a status transition updates the same row.

### `testers`

The table contains the three permanent tester identities. A tracked migration
seeds `manu` / Manu, `fabi` / Fabi, and `schorsch` / Schorsch. `code` and
`display_order` are unique. Application logic uses the stable code and must not
assume the generated row IDs or derive identity from display-name spelling.

The allowed-code check makes the current permanent membership explicit. A
future change to that immutable domain rule requires a deliberate migration
and a rating-compatibility review.

### `drink_tests`

A test is separate from its drink: `drinks 1 -> many drink_tests`. This makes
more than one test structurally possible without defining a retesting product
workflow now.

Each test has a database-constrained `draft` or `completed` status. Only a
completed test with a valid official result may be placed in public rating,
ranking, or price/performance comparison sets. The schema does not use a
trigger to synchronize test status with drink lifecycle; the future
application service must update these consistently in one controlled action.

Nullable fields preserve useful audited test facts without claiming more than
the workbooks establish:

- `price_amount DECIMAL(12,5)` stores the price associated with the test when
  its meaning is known;
- `recorded_time TIME`, `duration_value INT UNSIGNED`, and
  `stream_reference SMALLINT UNSIGNED` retain the workbook-shaped values
  without assigning an unverified unit or business meaning;
- `completed_at` and `notes` are optional; and
- creation and update timestamps are recorded.

The five-decimal price scale preserves audited source values such as `0.6995`
and `0.81583` instead of scraping a two-decimal display. Packet 6 added the
forward precision migration; price unit and basis remain unresolved.
Prices found on legacy untested records are not forced into fake test rows;
Packet 6 reports all 58 as deferred enrichment because their permanent
placement remains unresolved.

### `ratings`

One row stores one canonical tester's three raw inputs for one test:

`drink_tests 1 -> many ratings <- 1 testers`

The authoritative categories are `optik`, `sueffigkeit`, and `geschmack`.
Each is `DECIMAL(8,4)`, which safely preserves the historical integer inputs
and permits decimal granularity without asserting an unverified integer-only
rule. A unique constraint on `(test_id, tester_id)` prevents two rating rows
for the same tester and test.

No database range check such as 1–10 is present. The observed historical range
does not formally establish the accepted future range or granularity. Future
HTTP validation must resolve that question before accepting input; it must not
quietly invent a rule at the persistence layer.

### `drink_images`

Image binaries are files on webspace, never database BLOBs. The table stores a
drink relationship, relative `storage_path`, detected `mime_type`, positive
pixel `width` and `height`, `display_order`, and creation time.

`drinks 1 -> many drink_images`

The storage path is globally unique and constrained to a nonblank relative
reference rather than an absolute path or URL. `(drink_id, display_order)` is
unique. The image with the lowest order is the normal primary display image,
so multiple images are possible while a future ordinary UI can still present
one simple optional picture control. Uploading, detecting metadata, generating
paths, processing files, and deletion are not implemented.

## Relationship summary

```text
drinks 1 ─── * drink_tests 1 ─── * ratings * ─── 1 testers
   │
   └──────── * drink_images
```

There are no source, manufacturer, city, region, inventory, or acquisition
lookup tables. The audited evidence does not justify that normalization, and
simple entry remains more important than speculative structure.

## Derived-result policy

The database stores the nine individual rating inputs and optional test price.
The PHP domain layer calculates:

- three unrounded category averages;
- rounded Gesamtwertung;
- descending competition rank over a caller-supplied completed-result set; and
- price/performance over a caller-supplied comparison set.

The permanent current application comparison set for price/performance is all
eligible completed/tested results with valid positive prices. This corrects
the workbook's fixed T2:T109 range artifact without changing its mathematics.

Not storing those outputs prevents stale values when raw ratings or the chosen
comparison population changes. If profiling later proves caching necessary,
it must be added as explicitly invalidated cache data, not as independently
editable truth.

## Future / unresolved

- Retesting is structurally possible, but which test is public and how
  historical results are presented remain product decisions.
- The valid rating input range and granularity remain unverified beyond the
  observed historical values.
- Price unit and basis are unknown. Missing, zero, or otherwise unavailable
  prices do not produce Preis/Leistung; the 58 untested legacy prices remain
  deferred enrichment facts rather than invented tests.
- Historical acquisition/inventory events are not modeled. Lifecycle remains
  the current state on the drink.
- Duplicate matching and merge rules remain part of the controlled import;
  names alone are insufficient.
- The exact meaning/unit of duration and the semantics of recorded time and
  stream reference remain unresolved; the raw columns must not be embellished
  during import.
- Additional-image UI, upload validation, conversion/resizing, safe serving,
  and deletion behavior remain future work.
- Image-processing technology remains unselected until production PHP
  capabilities are verified.

## Legacy-import infrastructure

### `legacy_import_runs`

This isolated table records the deterministic one-time legacy run ID, plan and
source hashes, a JSON summary, and application timestamp. It protects against
silent duplicate imports without adding legacy-source columns to every domain
table. It is migration infrastructure rather than product data.

## Historical status rule

During historical migration, red-marked drinks in the old Primärliste must be
classified as `identified`, even though they appear in that list.
