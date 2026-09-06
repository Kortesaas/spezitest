# Primärliste refresh

This CLI-only workflow updates a non-production database that was populated by
the reviewed Packet 6 legacy import. It is not an HTTP endpoint and refuses to
run unless `APP_ENV` is `local`, `development`, or `testing`.

The planner treats a new Primärliste as a migration/verification source. It
requires a one-to-one match to all 166 reviewed Primärliste products, preserves
the 30 procurement-only products, validates lifecycle colors and all nine
integer grades, and records the workbook SHA-256. The apply step verifies every
Gesamt and competition rank with the immutable PHP rating engine before opening
a database transaction.

Prepare the reviewed images before generating the plan. This local-only step
requires Python Pillow and `cwebp`; neither is a production runtime dependency.
It center-crops the normalized 1024×1024 PNG canvas to 640×1024, preserving the
full height and transparent background, then creates quality-85 WebPs with
lossless alpha and no embedded metadata. The 640 px width was selected from
the measured visible bounds of all 186 products: it removes 37.5% of the empty
horizontal canvas while retaining every opaque product pixel. Source PNGs are
not modified.

```sh
composer primary-refresh:optimize-images
```

The local outputs are retained under `var/admin-images/640x1024/` (lossless
PNG) and `var/admin-images/640x1024-webp/` (delivery candidate). After visual
and integrity review, the approved WebPs live in the tracked, non-public
`resources/primary-images/640x1024/` source set used by the planner. Generate
and verify a plan:

```sh
python3 tools/primary-refresh/plan.py /absolute/path/to/new-primaerliste.xlsx
composer migrate
composer primary-refresh:verify
```

Apply it only after reviewing `var/primary-refresh/current/refresh-plan.json`:

```sh
composer primary-refresh:apply
```

After the apply and database verification are accepted, regenerate the plan
with `--output resources/initial-data/refresh-plan.json` to promote a portable
reviewed copy. External workbook paths are reduced to the filename; repository
inputs remain relative, and hashes retain source identity. The initial-data
export and integrity gate use this tracked copy, never the ignored working file
under `var/`.

Before a write, apply stores a private JSON snapshot of every application table
under `var/primary-refresh/backups/` with mode `0600`. Database changes are made
in one transaction. The verified WebP sources are copied into the configured
private admin image root and referenced through portable
`admin/640x1024/...` paths. Both public and admin image controllers stream the
stored `image/webp` response; the source PNGs and original legacy files are not
deleted. Products without a replacement are left on their existing primary
image and receive `drinks.needs_new_photo = 1`.

The workflow does not connect to production, deploy, infer fuzzy duplicates,
or turn the workbook into a live datastore. A future production refresh needs
an explicitly reviewed deployment plan, database backup, and recovery steps.
