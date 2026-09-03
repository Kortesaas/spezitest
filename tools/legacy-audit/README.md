# Legacy workbook audit utility

`audit.py` is local, read-only migration/audit tooling. It reads the two
ignored workbooks in `var/legacy-import/` as ZIP/OOXML packages and writes its
reports beneath the ignored `var/legacy-audit/` directory.

It does not connect to a database, use production credentials, or modify/save
the workbooks. The utility records each source SHA-256 before inspection,
recomputes it afterward, and fails if either value changes.

Run it from the repository root with Python 3.11 or newer:

```sh
python3 tools/legacy-audit/audit.py
```

Generated local outputs:

- `var/legacy-audit/audit.json` — workbook, lifecycle, overlap, and formula facts
- `var/legacy-audit/image-manifest.json` — row-to-image associations and hashes
- `var/legacy-audit/images/` — extracted source images for local inspection

The generated output is evidence for the audit, not an importer and not a
production image pipeline.
