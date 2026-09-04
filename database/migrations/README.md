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

MariaDB DDL can commit implicitly. A failed multi-statement migration may
therefore leave partial schema changes even though its version is not recorded.
Production migrations require review, a verified backup, a recovery plan, and
serialized execution by one operator/process. Automatic destructive down
migrations are not used.
