# Reviewed initial data

This directory contains the data-only SQL seed and integrity manifest for a
fresh Spezitest installation. It contains no schema, credentials, environment
configuration, or source workbook. Apply all tracked migrations first, then
import `spezitest-data.sql` only into an otherwise-empty application dataset.

Run `composer initial-data:verify` to verify the SQL hash and all referenced
tracked images. Do not edit the generated files by hand. Regeneration with
`composer initial-data:export` is a reviewed non-production maintenance action,
not an ordinary deployment step.

See `docs/INSTALLATION.md` for local installation and `docs/DEPLOYMENT.md` for
the production Plesk procedure.
