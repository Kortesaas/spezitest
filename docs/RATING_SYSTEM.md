# Rating System

## Compatibility rule and evidence

The established Spezitest rating methodology must remain **exactly
unchanged**. This document records Packet 4 findings from the formulas and
cached results in `primaerliste.xlsx`, worksheet `Spezi Test`. The workbook
SHA-256 at audit time was
`e426d6ae93ca1f754455772664ffb5ed0175c65d6a6138397dec50532e9b262d`.

The permanent testers, in workbook column order, are:

- Manu
- Fabi
- Schorsch

The labels below are deliberate:

- **VERIFIED** means the formula or behavior is directly present in the
  workbook and, where applicable, agrees with populated cached results.
- **HISTORICAL QUIRK** means the workbook demonstrably behaves this way even
  though a new design would probably not choose it.
- **UNRESOLVED** means the populated workbook does not prove the behavior. It
  must not be guessed during implementation.

Packet 5 implements the verified formulas as independent PHP domain logic under
`src/Domain/Rating/`. The golden fixture in
`tests/Fixtures/legacy-rating-golden.json` remains the compatibility authority:
all eight representative cases, their ranking results, and their verified
price/performance values are exercised by unit tests.

The workbook formula and populated historical results are verified. The source
data still does not formally verify every possible Excel coercion or exact
half-boundary rounding case. The implementation must therefore never be
rewritten from memory or assumptions, and its isolated rounding policy must be
rechecked against Excel boundary cases before production.

## Implemented calculation boundary

The calculation layer has no HTTP, PDO, or MariaDB dependency:

- `TesterRating` holds the three raw category values for one stable
  `TesterCode`.
- `RatingCalculator` requires exactly one rating from each of Manu, Fabi, and
  Schorsch, retains unrounded category averages, applies weights 1/2/3, and
  rounds Gesamt once to two decimals.
- `ExactNumber` parses bounded decimal inputs as rational values. Database
  `DECIMAL` values should be passed as strings so calculation does not begin
  with a binary floating-point conversion.
- `ExcelRounder` is the single replaceable compatibility boundary. It applies
  half-away-from-zero rounding to exact rational inputs rather than delegating
  the decision to PHP's binary `round()` behavior.
- `CompetitionRanking` derives descending Excel-style competition ranks from
  exactly the score population supplied by its caller.
- `PricePerformanceCalculator` derives the intermediate and normalized values
  from an explicitly supplied comparison population.

The result objects expose floats at their outside boundary for convenient
consumption, while rating aggregation and Gesamt rounding use exact rational
arithmetic. The intentionally bounded implementation matches the schema's
`DECIMAL` rating/price sizes and the historical fixtures; it is not a
general-purpose arbitrary-precision mathematics library.

## Source columns

| Category | Manu | Fabi | Schorsch | Derived average |
| --- | --- | --- | --- | --- |
| Optik | E | F | G | H |
| Süffigkeit | I | J | K | L |
| Geschmack | M | N | O | P |

`Gesamt` is Q, `Rang` is R, `Preis` is S, the price/performance intermediate
is T, and the normalized `Preis/Leistung` value is U.

All 108 tested rows (2–109) contain all nine raw tester inputs. Observed input
values are integers: Manu has 0–10, Fabi has 0–10, and Schorsch has 0–10
across the three categories (Schorsch's observed Optik maximum is 10 and
Geschmack maximum is 9). There are no worksheet data-validation rules.

## Category averages — VERIFIED

For row `n`, the three formulas are equivalent to:

```text
Hn = IF(COUNTA(En:Gn)=3, AVERAGE(En:Gn), "")
Ln = IF(COUNTA(In:Kn)=3, AVERAGE(In:Kn), "")
Pn = IF(COUNTA(Mn:On)=3, AVERAGE(Mn:On), "")
```

The formulas exist for all meaningful data rows 2–167. A category result is
therefore the arithmetic mean only when all three input cells are nonblank
according to `COUNTA`; otherwise it is the empty string.

The averages are **not rounded by formula**. Cells H, L, and P merely use the
display format `0.00`; their cached numeric values retain Excel's underlying
precision. For example, row 2 caches `8.666666666666666` for Optik while the
sheet displays `8.67`.

## Gesamt — VERIFIED

For row `n`, Q contains:

```text
IF(AND(Hn<>"", Ln<>"", Pn<>""), ROUND(Hn*1 + Ln*2 + Pn*3, 2), "")
```

The category weights are therefore:

- Optik: 1
- Süffigkeit: 2
- Geschmack: 3

The unrounded category averages are multiplied and added first. `ROUND(...,
2)` is applied once to that weighted sum. Q also has the `0.00` display
format. Row 2, for example, produces and caches `55.33`.

If any category-average cell is the empty string, Gesamt is the empty string.
This is what the 58 untested rows cache.

## Ranking — VERIFIED

For meaningful row `n`, R is equivalent to:

```text
IF(Qn<>"", RANK(Qn, Q$2:Q$167), "")
```

The omitted third `RANK` argument makes this a descending rank: the greatest
Gesamt is rank 1. The comparison range is fixed to rows 2–167, including the
currently blank totals of untested records.

Ties use Excel competition ranking: equal totals receive the same rank and
the following rank has a gap. This occurs repeatedly in cached history. For
example, rows 9 and 10 both have Gesamt `43.67`, both rank 8, and the next
record ranks 10. Four records with Gesamt `37.67` all rank 23 and the next
record ranks 27.

The PHP ranker does not know workbook row numbers or query the database. Its
caller must supply the intended population of valid Gesamt values. Production
application code must include only completed tests with official results. This
keeps the formula compatible while preventing the workbook's fixed/oversized
ranges from becoming a domain rule.

### Ranking range — HISTORICAL QUIRK

The R formula was accidentally propagated through row 381,077, even though
meaningful records end at row 167 and the worksheet hides rows 168–381,077.
Every propagated formula still ranks against `Q$2:Q$167`; new rows beyond 167
would not enter the ranking population without changing that formula range.

## Price and Preis/Leistung — VERIFIED

All 166 Primärliste records have a numeric price. The cell display format is
`#,##0.00`, but the stored source values can have more precision, such as
`0.6995`; migration must not scrape the displayed two-decimal text.

For the 108 tested rows only, T is:

```text
Tn = Qn / Sn
```

There is no guard against a blank or zero price. T uses the already rounded
Gesamt value from Q. Its populated cached values range from
`0.7488789237668161` (row 109) to `96.83333333333334` (row 14).

For all meaningful rows 2–167, U is:

```text
(Tn - MIN($T$2:$T$109)) /
    (MAX($T$2:$T$109) - MIN($T$2:$T$109))
```

This is min-max normalization of T over the fixed tested range T2:T109. It
maps the historical minimum to 0 and maximum to 1. U is displayed with
`0.00`, but cached values retain more precision.

### Price/performance range and blanks — HISTORICAL QUIRK

- T formulas stop at row 109, while U formulas continue through row 167.
- The normalization population is fixed at T2:T109. A later test outside
  that range would not affect the minimum or maximum.
- On rows 110–167, T is blank but U still calculates. Excel coerces the blank
  reference to zero, producing cached value `-0.007793965510535853` (displayed
  as `-0.01`) for most untested rows. This must not be mistaken for an
  authoritative rating.
- T and U are not involved in the Gesamt or ranking formulas.

### PHP price/performance policy

The PHP service accepts rounded Gesamt, an optional price, and the intermediate
values for an explicit comparison set. It computes:

```text
intermediate = rounded Gesamt / price
normalized = (intermediate - minimum) / (maximum - minimum)
```

Historical compatibility can therefore pass the exact T2:T109 population;
future behavior may pass a different set only after the product owner chooses
it explicitly. Excel row references are not embedded in the service.

A missing or non-positive price, an empty population, or a zero-width
population returns unavailable rather than inventing a value. Preis/Leistung
is optional even for a completed test. Negative prices are treated as
unavailable because division can be performed safely only for a positive
price; this is a safety policy, not a claim about workbook behavior.

## Other formula anomalies — HISTORICAL QUIRK

Columns V (`Timestamp`) and W (`Dauer`) usually contain time-of-day and integer
metadata, respectively. Four rows instead contain stray formulas resembling
additional normalization passes:

| Row | V formula starts from | W formula starts from | T population |
| ---: | --- | --- | --- |
| 58 | U58 | V58 | T2:T109 |
| 59 | U59 | V59 | T2:T94 |
| 83 | U83 | V83 | T2:T167 |
| 122 | U122 | V122 | T2:T94 |

Their cached negative numbers are not timestamp/duration facts. Row 122 is
also untested and red-marked. These cells must be quarantined during migration
rather than interpreted according to their column headers.

The workbook also contains a chart sheet and a saved internal Power Query
connection named `Dauer_aller_Getr_nke-Segmente`. Those features reference
very large ranges and may explain historical duration/chart work, but they do
not change the verified rating formulas above.

## Blank and error behavior

### VERIFIED

- Missing any one tester cell causes that category formula to return `""`
  because its `COUNTA` is not 3.
- Missing any category result causes Gesamt to return `""`.
- A blank Gesamt causes Rang to return `""`.
- All 108 populated historical rating rows have cached numeric results and no
  cached rating-formula errors.
- The `#VALUE!` stored in 165 cells of column A is the OOXML carrier for
  in-cell rich-data images. It is not a rating error.

### UNRESOLVED

- Valid future input domain and granularity are not proven by validation
  rules. Only the observed integer values 0–10 are known.
- The populated history does not exercise partially filled rows with text,
  booleans, or errors. `COUNTA` counts such values while `AVERAGE` has its own
  coercion/error rules; a future implementation must define compatibility
  from Excel tests before accepting such inputs.
- No tested row has a blank or zero price. The intended business behavior for
  either case remains unresolved even though the unguarded division formula is
  known; current PHP behavior deliberately returns unavailable.
- No historical case establishes what to do when all T values are equal and
  the min-max denominator becomes zero; current PHP behavior returns
  unavailable.
- The formula proves use of Excel `ROUND(..., 2)`, but the data has no known
  exact half-boundary case. `ExcelRounder` isolates a half-away-from-zero exact
  rational policy and has direct boundary tests, but Excel boundary fixtures
  remain a pre-production verification requirement.
- Whether ranking and price/performance are historical snapshots or should be
  dynamically recalculated after later tests is a product decision not proved
  by the workbook.
- The business unit and basis of `Preis` are not stated in the workbook.

## Production gate

Before this rating implementation goes to production, it must be verified
again against the actual Excel formulas, cached historical results, and the
golden fixtures, including deliberately constructed Excel half-boundary cases.
All eight current golden cases pass, but that does not waive the remaining
edge verification. A mismatch is evidence to investigate, not permission to
silently change the historical methodology. Any remaining ambiguity requires
a project-owner decision.
