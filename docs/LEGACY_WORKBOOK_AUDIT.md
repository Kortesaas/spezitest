# Legacy Workbook Audit

## Scope and evidence

This is the Packet 4 read-only forensic audit of the local migration sources:

| Workbook | SHA-256 | Bytes |
| --- | --- | ---: |
| `primaerliste.xlsx` | `e426d6ae93ca1f754455772664ffb5ed0175c65d6a6138397dec50532e9b262d` | 22,132,910 |
| `beschaffungsliste.xlsx` | `60811823c90651fe44ba77bb7bcc978672c3344fb3ef433f824993b38f58957c` | 6,709,764 |

The audit read cached values, formulas, styles, relationships, drawings,
rich-data image metadata, and media directly from the OOXML packages. It also
rendered every populated row range for a visual formatting check. Neither
workbook was opened for writing or resaved. The same hashes were obtained
after inspection.

The committed utility in `tools/legacy-audit/` reproduces the structural,
lifecycle, overlap, and image checks. It writes only ignored local output
beneath `var/legacy-audit/`; it is not an importer.

## Workbook summary

| Workbook | Visible tabs | Meaningful range | Data rows | Physical/declared artifacts |
| --- | --- | --- | ---: | --- |
| Primärliste | chart sheet `Diagramm1`; worksheet `Spezi Test` | `Spezi Test!A1:X167` | 166 | worksheet dimension `A1:Z381079`; rank formulas and hidden rows through 381,077 |
| Beschaffungsliste | worksheet `Tabellenblatt1` | `A1:F35` | 34 | table `Tabelle1` extends through blank row 115; styled cells extend through column Y |

All listed tabs are visible. Neither data sheet contains merged cells,
conditional-formatting rules, data-validation rules, or hyperlinks.

### Primärliste structure

`Spezi Test` has one header row and records in rows 2–167. Row 1 is frozen;
the saved viewport starts at A137. The three raw tester columns for each
category are grouped and hidden (E:G, I:K, M:O), while the derived category
columns H, L, and P are visible. T, V, and W are also hidden. Columns Y onward
and rows 168–381,077 are hidden.

The very large declared dimension is not the data range. In particular:

- record identity fields stop at row 167;
- category and Gesamt formulas stop at row 167;
- T formulas stop at row 109;
- R formulas continue through row 381,077 while still referencing Q2:Q167;
- a hidden filter-defined name covers A1:X381077; and
- the chart series also use ranges extending through row 381,077.

`Diagramm1` contains a bar chart with series sourced from S:X and multi-level
categories sourced from A:R. It is an analysis/presentation artifact, not a
second dataset. The package also has a calculation chain and a saved internal
Power Query connection named `Dauer_aller_Getr_nke-Segmente`; no VBA project
or external-link part was found. Stale package metadata includes an absolute
Windows path and must not be migrated or exposed.

#### Primärliste columns

| Column | Header | Meaning and populated type | Blank/format notes |
| --- | --- | --- | --- |
| A | Bild | In-cell product image represented through rich-data metadata | 165 images; row 85 has none. Raw OOXML image cells cache `#VALUE!`; this is not a domain error. |
| B | Hersteller | Manufacturer text | 166/166 populated. |
| C | Ort | Free text combining locality and often postal/country prefix | 166/166 populated; not reliably normalized. |
| D | Name | Drink/product name text | 166/166 populated; not unique. |
| E:G | Optik Manu/Fabi/Schorsch | Raw numeric tester inputs | 108 populated per column, 58 blank; hidden. |
| H | Optik | Formula-derived category average | 108 numeric cached results, 58 formula-empty; display `0.00`. |
| I:K | Süffigkeit Manu/Fabi/Schorsch | Raw numeric tester inputs | 108 populated per column, 58 blank; hidden. |
| L | Süffigkeit | Formula-derived category average | 108 numeric cached results, 58 formula-empty; display `0.00`. |
| M:O | Geschmack Manu/Fabi/Schorsch | Raw numeric tester inputs | 108 populated per column, 58 blank; hidden. |
| P | Geschmack | Formula-derived category average | 108 numeric cached results, 58 formula-empty; display `0.00`. |
| Q | Gesamt | Rounded weighted result formula | 108 numeric cached results, 58 formula-empty; display `0.00`. |
| R | Rang | Descending rank formula | 108 numeric cached results, 58 formula-empty in meaningful rows. |
| S | Preis | Numeric historical price | 166/166 populated; stored precision can exceed displayed `#,##0.00`. Unit/basis is unstated. |
| T | Preis/Leistung Zwischenergebnis | `Gesamt / Preis` | Formula and value only on tested rows 2–109; hidden. |
| U | Preis/Leistung | Min-max normalized T formula | Formula on all rows; meaningful numeric result on tested rows, misleading negative cached result on untested rows; display `0.00`. |
| V | Timestamp | Excel time-of-day value | 47 valid-looking values, 115 blank, and 4 stray-formula cells; hidden. No date component. |
| W | Dauer | Integer metadata of unstated unit | 47 valid-looking values, 115 blank, and 4 stray-formula cells; hidden. |
| X | Stream | Integer reference 1–4 | 108 populated, exactly the tested rows; counts are 47/22/21/18 for values 1/2/3/4. |

Rows 58, 59, 83, and 122 contain formulas in V/W instead of values matching
the column meanings. They are migration anomalies, documented in
`RATING_SYSTEM.md`.

Row 151 is visibly column-shifted in the identity data: B contains `Pyraser
Waldquelle Cola-Mix`, C contains `Pyraser Landbrauerei`, and D contains `91177
Thalmässing`. It must be manually corrected during a future reviewed import,
not silently normalized by this audit.

### Beschaffungsliste structure

`Tabellenblatt1` has one header row and 34 populated rows (2–35). Row 1 is
frozen. `Tabelle1` is an Excel table whose declared range is A1:F115, leaving
80 blank table rows 36–115. Those rows and formatting through column Y are not
additional drinks. There are no formulas or hidden rows/columns relevant to
the populated data.

#### Beschaffungsliste columns

| Column | Header | Meaning and populated type | Blank/format notes |
| --- | --- | --- | --- |
| A | Bild | Product image as an ordinary drawing anchored to the row | Cell values are blank; all 34 data rows have one mapped PNG drawing. |
| B | Name der Spez | Drink/product name text | 34/34 populated. Header spelling is preserved as source evidence. |
| C | Hersteller | Manufacturer text | 34/34 populated. |
| D | Wohnort | Free text combining postal code/prefix and locality | 34/34 populated. |
| E | ungefähre Beschreibung | Informal relative-location/acquisition note | 34/34 populated; wording is colloquial and not normalized geography. |
| F | Bundesland | Region/state text | 34/34 populated; mixes full names, `NRW`, and `Österreich`. |

## Lifecycle interpretation

The migration model remains one drink record with one current state:

`identified -> acquired -> tested`

No record is to be copied or moved between datasets to represent that state.

### Primärliste

| Status | Rows | Count | Evidence |
| --- | --- | ---: | --- |
| `tested` | 2–109 | 108 | Every row has all nine raw tester inputs, category results, Gesamt, rank, and stream reference. |
| `identified` | 110–127, 129, 131–132, 134–136, 138–139 | 26 | Untested and record-level solid red fill; the project owner's permanent rule says red Primärliste records are not possessed. |
| `acquired` | 128, 130, 133, 137, 140–167 | 32 | Untested, not record-level red, and retained in the Primärliste awaiting testing. |
| unresolved | — | 0 | The supplied red rule and the complete-vs-blank rating pattern classify every meaningful row. |

Programmatic red detection is reliable when defined as solid `FFFF0000` fill
on each identity cell B:D. Checking whether *any* cell in a row is red is not
safe: I167:K167 have stray red fills, while the identity cells and rest of row
167 are not red. The 26 status rows are also visually red across most of the
record.

### Beschaffungsliste

The sheet is a list of drinks to procure, contains no ratings or possession
fields, and supplies approximate acquisition geography. Its 34 rows are
therefore `identified` migration candidates. Four are the same candidates as
red Primärliste rows and must become one drink record, not two. Because this is
historical material, current possession should still be confirmed before the
eventual migration; the workbook has no as-of date or status history.

## Duplicate and overlap review

Comparison used case-folded, whitespace-normalized names for exact matches.
Exact matching is only candidate generation; the future importer must use a
reviewed identity decision.

### Exact cross-workbook candidates: 4

| Primärliste row | Beschaffung row | Name | Corroboration |
| ---: | ---: | --- | --- |
| 110 | 7 | Braumeisters Cola-Mix | Same manufacturer, location, and byte-identical image. |
| 111 | 17 | Cubana Cola-Mix | Same manufacturer, location, and byte-identical image. |
| 114 | 3 | Berg Quellen ColaMix | Same manufacturer, location, and byte-identical image. |
| 123 | 27 | ERL Bräu Cola-Mix | Same manufacturer, location, and byte-identical image. |

All four Primärliste occurrences are red and therefore `identified`. There
are 162 Primärliste rows and 30 Beschaffungsliste rows without an exact
cross-workbook match.

### Name-similarity candidates requiring review: 5

These pairs passed the audit's deliberately broad normalized-name threshold.
Their different manufacturers are evidence that most or all are distinct;
they are listed to make false-positive handling explicit, not as merge
instructions.

| Primärliste | Beschaffungsliste | Why review is required |
| --- | --- | --- |
| row 59, Cubanita Cola Mix | row 17, Cubana Cola-Mix | Very similar names; different manufacturers. |
| row 76, UB Cubana Cola-Mix | row 17, Cubana Cola-Mix | One name contains the other; different manufacturers. |
| row 83, Vita Cola Mix | row 12, Viva Cola Mix | One-letter difference; different manufacturers. |
| row 84, Giesinger Kracherl Cola Mix | row 31, Golser Kracherl Cola Mix | Similar product wording; different manufacturers and places. |
| row 102, SilberQuelle Cola-Mix | row 3, Berg Quellen ColaMix | Normalization makes generic wording similar; different manufacturers. |

No uncertain pair was merged.

### Within-workbook identity warnings

- Primärliste rows 6 and 27 are both named `Spezi` but have different
  manufacturers and locations. Names are demonstrably not unique.
- Primärliste rows 37/49 (`Adelholzener Primella Cola Mix` / `Adelholzener
  Cola-Mix`) and 128/165 (`Schwarzwald Sprudel Cola-Mix` / `Schwarzwald
  Cola-Mix`) share manufacturer and location and have similar names. They need
  product-level review; differing prices/images do not prove either identity
  or distinctness.
- Several manufacturers have multiple clearly differently branded products.
  Manufacturer/location equality alone is not a merge rule.

## Rating-system findings

The exact formulas and known edge cases are recorded in
`docs/RATING_SYSTEM.md`. In summary:

- **VERIFIED:** each category is the unrounded arithmetic average of Manu,
  Fabi, and Schorsch when `COUNTA` sees all three inputs;
- **VERIFIED:** Gesamt is
  `ROUND(Optik*1 + Süffigkeit*2 + Geschmack*3, 2)`;
- **VERIFIED:** ranking is descending Excel `RANK` over Q2:Q167, with equal
  scores sharing a rank and gaps after ties;
- **VERIFIED:** T is rounded Gesamt divided by stored price for rows 2–109;
- **VERIFIED:** U min-max normalizes T over fixed range T2:T109;
- **HISTORICAL QUIRK:** untested rows still calculate negative U values from a
  blank T, fixed ranges exclude future rows, and R formulas extend through
  381,077; and
- **UNRESOLVED:** input validation/granularity, blank-or-zero price business
  behavior, exact half-boundary port behavior, a zero-width normalization
  population, and whether derived rankings are snapshots or dynamic.

Eight golden historical cases cover high/low results, repeating decimals,
whole-number averages, a rank tie, price variation, both normalization
endpoints, and the row with no image. The JSON contains raw inputs and exact
cached expected outputs; no missing-price case exists in tested history.

## Image audit

| Workbook | Representation | Embedded/mapped | Formats | Missing row images | Unmatched embedded images |
| --- | --- | ---: | --- | ---: | ---: |
| Primärliste | Excel in-cell rich data | 165/165 | 117 PNG, 48 JPEG | 1 (row 85, Endlich Cola-Mix) | 0 |
| Beschaffungsliste | Drawing objects anchored in column A | 34/34 | 34 PNG | 0 | 0 |
| Total | — | 199/199 | 151 PNG, 48 JPEG | 1 | 0 |

All Primärliste images are 500×500 pixels. Beschaffungsliste image dimensions
vary. No row has multiple mapped images.

The Primärliste mapping follows this complete relationship chain:

`A-cell vm -> valueMetadata -> rich value -> richValueRel relationship -> xl/media file`

It maps 165 metadata-bearing cells to rows 2–167, with A85 as the sole
non-image cell. The Beschaffungsliste mapping follows each drawing's zero-based
row/column anchor and relationship ID to its media file; there is exactly one
column-A drawing for each row 2–35. These package-level associations are
deterministic and do not depend on visual proximity guesses.

There are 195 unique image SHA-256 hashes among 199 files. No workbook reuses
an image internally. The four duplicate hash groups are cross-workbook pairs
for the four exact overlap candidates above. This provides useful duplicate
evidence but must not become the only product identity rule.

The ignored `var/legacy-audit/image-manifest.json` records workbook, row,
drink name, representation, source relationship, package part, detected
format/dimensions, SHA-256, and extraction result for every image. Production
resizing, conversion, storage, and upload handling remain out of scope.

## Other historical data worth preserving for review

- Manufacturer and product name are present in both sources.
- Primärliste `Ort` and Beschaffungsliste `Wohnort` often contain postal code
  plus locality, including country prefixes such as `A-` and `L-`; some values
  omit a postal code or spacing. They must first be preserved as raw text.
- Beschaffungsliste adds `Bundesland`/country-like region and an informal
  relative-location note that may assist procurement but is not normalized
  geography.
- Primärliste price is present for every record at greater underlying
  precision than its display format in some rows.
- Stream number is complete for tested history. Time-of-day and duration are
  present for only 47 tested rows; duration unit and both fields' exact meaning
  are unstated.
- Neither workbook has a dedicated country, acquisition source, notes,
  possession timestamp, test date, or stable product identifier.

Presence in a source does not by itself justify a permanent database column.
Raw migration evidence should be retained until the domain design decides
which facts are authoritative, derived, display-only, or historical metadata.

## Migration risks and anomalies

1. Inflated worksheet/table/chart ranges can create hundreds of thousands of
   false blank records or formulas if physical dimensions are trusted.
2. Red status is direct cell styling, not conditional formatting, and must be
   detected at record level to avoid the stray red cells in row 167.
3. In-cell rich-data images are exposed as cached `#VALUE!` by libraries that
   do not understand modern Excel metadata; raw OOXML relationships are
   required.
4. Names, manufacturers, and locations are not normalized identifiers.
   Automatic merging would conflate legitimate products.
5. Row 151's shifted identity fields require explicit human correction.
6. Stored price precision must be retained; displayed text loses information.
7. Untested U values and the four V/W formula anomalies look numeric but are
   not valid domain facts.
8. Chart, query, calculation-chain, authoring-path, and workbook cache data
   must not be mistaken for domain records or published.
9. The two lists are historical and can be stale with respect to current
   possession.
10. The one missing product image needs a placeholder or later enrichment;
    its absence must not block drink migration.

## Firm audit inputs to the data model

- One product record must carry lifecycle state; workbook/list membership is
  migration provenance, not a permanent partition.
- Product names cannot be unique keys. Matching needs multiple facts and a
  manual-review path.
- Raw nine-tester inputs must be preservable independently of derived category
  averages, Gesamt, rank, and price/performance.
- Historical price needs explicit precision and unresolved unit/basis
  semantics; display rounding is not source precision.
- Images require row/product association plus detected format/hash metadata,
  and must remain optional.
- Location and acquisition-description strings need lossless raw staging
  before any optional structured geography.
- Stream/time/duration may describe a test event, but their meaning and
  cardinality are not yet sufficient to finalize tables.
- Import provenance and anomaly/review decisions should be traceable without
  making the Excel files operational sources of truth.

These remain audit constraints and import inputs. Packet 5 implemented the
core product/test/rating/image relationships; `DATA_MODEL.md` is authoritative
for the resulting schema and records which import questions remain deferred.

## Unresolved questions for a later packet

- Confirm current possession for historical Beschaffungsliste and nonred,
  untested Primärliste entries immediately before migration.
- Resolve the five cross-workbook fuzzy candidates and the two strong internal
  product-review pairs with domain knowledge.
- Confirm row 151's intended manufacturer, location, and name.
- Define the business unit/basis of price and the meaning/unit of timestamp,
  duration, and stream.
- Decide whether chart/Power Query history has archival value beyond the raw
  cells; it is not required to reproduce the verified score formulas.
- Decide retest/history semantics and whether rank and price/performance are
  dynamic or snapshot values.
- Establish Excel-compatible error and rounding behavior for cases absent
  from populated history before allowing the implemented rating engine into
  production.
