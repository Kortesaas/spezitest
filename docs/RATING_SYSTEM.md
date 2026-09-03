# Rating System

## Immutable compatibility requirement

Spezitest already has an established rating methodology. It must remain
**exactly unchanged**. This foundation intentionally does not document or
invent formulas, weights, scales, aggregation behavior, missing-value behavior,
or rounding rules.

There are three permanent testers, whose canonical names are:

- Manu
- Fabi
- Schorsch

## Current verification status

The exact Excel formulas and rounding semantics are **not yet considered
formally verified**. They must not be reimplemented from memory, visual
impressions, assumptions, or a newly invented interpretation.

No rating calculation should be treated as production-ready until a later,
explicitly authorized analysis has:

1. inspected the relevant existing Excel workbooks and cells;
2. documented the exact formulas, inputs, scales, weights, aggregation order,
   intermediate precision, rounding mode, rounding points, and treatment of
   blank/error values actually in use;
3. resolved any differences between workbook versions or sheets;
4. created representative compatibility fixtures from historical data; and
5. demonstrated that the implementation reproduces historical results,
   including boundary and rounding cases.

The Excel workbooks serve as migration and verification sources during this
process. They will not remain an operational source of truth after the future
database-backed system is established.

## Change control

A mismatch with historical results is evidence to investigate, not permission
to adjust the established methodology silently. Any ambiguity must be surfaced
for a project-owner decision before implementation or production release.

Changes to tester membership, formulas, weights, scales, or rounding behavior
are outside the scope of routine implementation and must not be inferred from
technical convenience.
