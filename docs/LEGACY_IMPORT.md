# Controlled Legacy Import

## Scope and safety boundary

Packet 6 provides a one-time, local/controlled importer for the two ignored
historical workbooks. It is not an HTTP feature and is not part of normal
production request handling. It must never be pointed at production without a
separate reviewed deployment packet.

Required read-only sources:

- `var/legacy-import/primaerliste.xlsx`
- `var/legacy-import/beschaffungsliste.xlsx`

The planner stops if either source is absent. It hashes both files before and
after OOXML inspection and the PHP verifier hashes them again. It reads package
XML and embedded media directly; it never opens either workbook for writing or
resaves it.

Generated plans, reports, review decisions, and staged images live below
`var/legacy-import-output/` and are ignored by Git. Applied image files use the
configured `LEGACY_IMAGE_STORAGE_ROOT`, which must be outside `public/`.

## Dry run

From the repository root:

```bash
composer legacy-import:plan
```

This runs the read-only Python OOXML planner and then the PHP verifier. Python
is used only by isolated migration tooling; the web application has no Python
or Excel-library runtime dependency.

The default output is `var/legacy-import-output/current/`:

- `import-plan.json`: complete normalized, deterministic import plan;
- `duplicate-decisions.json`: editable owner-review input;
- `dry-run-report.json`: machine-readable verification report;
- `dry-run-report.md`: human-readable verification report; and
- `images/<sha256>.<png|jpg>`: deduplicated original image bytes staged under
  generated content-addressed filenames.

Dry run never connects to or mutates MariaDB. Custom output paths are supported
by running the two commands directly:

```bash
python3 tools/legacy-import/plan.py --output var/legacy-import-output/example
php bin/legacy-import.php verify \
  --plan=var/legacy-import-output/example/import-plan.json \
  --output=var/legacy-import-output/example
```

## Duplicate review

Four exact cross-workbook pairs are merged automatically only because the
Packet 4 audit independently corroborated name, manufacturer, location, and
byte-identical images.

Five fuzzy pairs are never resolved by similarity. For each entry in
`duplicate-decisions.json`, the project owner must replace `UNRESOLVED` with:

- `SAME_PRODUCT`, together with `canonical_source` set to the selected left or
  right source identifier; or
- `DIFFERENT_PRODUCTS`, with `canonical_source` left `null`.

Do not change candidate IDs or evidence fields. Re-run the planner after
editing the decisions. It preserves and validates the decision file, applies
explicit same-product unions deterministically, and regenerates the plan and
reports. For a same-product merge, the selected canonical source also provides
the normal primary image (display order zero). Apply refuses while any decision
remains `UNRESOLVED`.

## Local apply

Apply requires:

1. a reviewed plan with no unresolved fuzzy decisions;
2. a disposable/non-production MariaDB 10.11 database;
3. the tracked migrations already applied;
4. the three canonical tester seeds and otherwise empty domain/import tables;
5. `APP_ENV` explicitly set to `local`, `development`, or `testing`;
6. configured `DB_*` values; and
7. `LEGACY_IMAGE_STORAGE_ROOT` pointing outside `public/`.

Then run:

```bash
composer migrate
composer legacy-import:apply
```

There is no force-production or nonempty-database shortcut. Apply validates
the complete plan, source hashes, ratings, rankings, and staged image hashes
before writing. It stages image copies under the configured storage root,
writes all database rows in one transaction, publishes the image directory,
re-reads the stored raw ratings/prices to verify all Gesamt, rank, and dynamic
Preis/Leistung results before commit, and records the run in
`legacy_import_runs`.

The database stores portable image paths in the form
`legacy/<run-id>/<sha256>.<extension>`. A repeated apply is rejected by both
the empty-domain check and the recorded source/plan run. The import-specific
tracking is isolated rather than adding legacy columns to domain tables.

Apply produces `apply-report.json` and `apply-report.md` beside the plan (or in
the explicitly supplied `--output` directory).

## Intentional historical corrections

Every correction is included in both report formats:

- Primärliste row 151 is structurally shifted: B becomes name, C manufacturer,
  and D origin/location.
- Red lifecycle detection uses solid red identity cells B:D. The unrelated red
  fills in I167:K167 do not make row 167 identified.
- Inflated blank/formula/table/chart ranges beyond meaningful rows 167 and 35
  are ignored.
- Stray formulas in V/W at Primärliste rows 58, 59, 83, and 122 are not
  imported as time/duration facts.
- Formula-derived averages, Gesamt, rank, and Preis/Leistung are verification
  evidence, never imported as authoritative fields.
- Excel's serialized binary-double noise is normalized at its 15-significant-
  digit precision. This preserves meaningful price precision up to five
  decimal places without using the two-decimal display text.

## Image recovery

The planner follows the audited OOXML relationships for Primärliste in-cell
rich-data images and Beschaffungsliste drawing anchors. It verifies PNG/JPEG
signatures and dimensions, hashes the actual bytes, and stages each unique
hash once. Workbook media filenames are evidence only and never become trusted
filesystem paths.

The expected source result is 199 mapped images, 195 unique files, and four
deduplications corresponding to the exact merged products. Primärliste row 85
(`Endlich Cola-Mix`) intentionally has no image. No conversion, resizing,
WebP generation, public upload handling, or invented replacement is performed.

## Rating, ranking, and Preis/Leistung verification

The PHP verifier recalculates every completed historical test from its nine raw
inputs using the application rating engine. Any Gesamt or historical rank
mismatch aborts verification and apply.

Preis/Leistung retains the established mathematics but intentionally corrects
the spreadsheet's fixed T2:T109 artifact. The application comparison
population is all currently eligible completed/tested Spezis with a valid
positive price. On this historical import that set contains all 108 completed
tests. Derived results are not stored.

## Remaining unresolved information

- The five fuzzy pairs require explicit project-owner decisions.
- The 58 prices belonging to untested Primärliste records remain in the JSON
  report for later enrichment; no fake test records are created.
- Price currency/unit/basis remains unstated.
- Recorded time, duration unit, and stream semantics remain historical raw
  metadata with limited known meaning.
- Current real-world possession should be reviewed separately from these
  historical workbook states before any production migration.
- Retest/public-result selection behavior remains outside this import packet.
