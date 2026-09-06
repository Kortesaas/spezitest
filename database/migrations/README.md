# SQL migrations

This directory contains forward-only, version-controlled SQL migrations.
Migration files must use this sortable naming convention:

`YYYYMMDDHHMMSS_lowercase_description.sql`

For example: `20270115103000_add_example_column.sql`.

The CLI runner discovers `.sql` files in bytewise filename order, applies only
unrecorded versions, and records each successful file with its SHA-256 checksum
in `schema_migrations`. Never edit a migration after it has been applied; add a
new forward migration instead. The runner rejects a changed checksum.

Packet 5 defines two domain migrations: the first creates `drinks`, `testers`,
`drink_tests`, `ratings`, and `drink_images`; the second seeds the canonical
tester codes `manu`, `fabi`, and `schorsch`. The runner itself creates
`schema_migrations`, because that table is infrastructure required before any
migration can be tracked. Domain seed migrations contain no sample drinks.

Packet 6 adds a forward migration that expands test-price precision to
`DECIMAL(12,5)` and creates the isolated `legacy_import_runs` safety table. It
does not contain workbook data or sample drinks.

Packet 8 (beta) adds a forward migration that adds `price_amount` and
`price_volume_ml` to `drinks`: the drink's own raw entered price and container
volume, from which the application derives a €-per-0.5 L Preis/Leistung basis.
`drink_tests.price_amount` remains for legacy-imported test records only.

The September 2026 photo-follow-up migration adds the boolean
`drinks.needs_new_photo` marker. It deliberately stores no image path and does
not change lifecycle semantics.

`20260906120000_backfill_primaerliste_prices.sql` fills
`drinks.price_amount` / `drinks.price_volume_ml` for the 166 reviewed
Primärliste products (seed ids 31–196) from the workbook `Preis` column, which
the product owner confirmed is a per-0.5 L figure, so every row is stored with
`price_volume_ml = 500`. The workbook SHA-256 matches the one already recorded
in `resources/initial-data/refresh-plan.json`; the id→product mapping is that
plan's fixed record order, cross-checked against the identical values already
present in `drink_tests.price_amount` for all 125 tested rows. It is a single
idempotent `UPDATE` and leaves the 30 procurement-only drinks (ids 1–30) NULL.

`20260906140000_create_test_runs.sql` adds `test_runs`, one row per
Testabend (livestream episode): number, title, recording date, stream
address and an `open`/`completed` status. It is pure DDL and inserts
nothing. The table is keyed by the same number `drink_tests.stream_reference`
already stores, and deliberately carries **no foreign key** on that column:
migrations run before the reviewed data-only seed during a fresh install, so
a constraint would reject the seed's `drink_tests` rows. Episode details are
therefore optional per stream number.

MariaDB DDL can commit implicitly. A failed multi-statement migration may
therefore leave partial schema changes even though its version is not recorded.
Production migrations require review, a verified backup, a recovery plan, and
serialized execution by one operator/process. Automatic destructive down
migrations are not used.
