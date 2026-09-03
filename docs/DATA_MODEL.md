# Data Model Direction

## Status of this document

This document captures known principles and proposed directions. It is **not a
final domain schema**. Packet 3 created only migration infrastructure and the
`schema_migrations` tracking table. Packet 4 audited the legacy workbooks but
did not add domain storage. No drink, image, source, test, tester, or rating
table exists yet.

The workbook audit in `LEGACY_WORKBOOK_AUDIT.md` now supplies evidence for the
next design phase. The actual domain schema remains intentionally unfinalized
until its unresolved identity, event-history, and rating compatibility
decisions are made explicitly.

## Authoritative-data principle

The future database will be Spezitest's single operational source of truth.
Existing Excel workbooks are migration and verification material only and will
eventually be retired from day-to-day operation.

Store authoritative/raw facts. Calculate derived scores and rankings from
those facts unless a proven technical reason justifies caching them. Derived
rankings or scoring values must not be blindly duplicated into authoritative
columns where they can drift from their inputs.

## Core drink principle

One database record represents one distinct Cola-Mix / Spezi product. Each
drink has one current lifecycle state:

`identified -> acquired -> tested`

Status changes update the state of that same record. They must never duplicate
the drink or physically move it between separate datasets.

Drink names must not be assumed globally unique. Duplicate detection is a
domain and import concern and must not be reduced to a simple `UNIQUE(name)`
constraint. The distinguishing facts and matching rules remain to be verified.

This is directly supported by the Primärliste: two distinct rows named
`Spezi` have different manufacturers and locations. Cross-workbook comparison
also found exact overlaps as well as visually similar names belonging to
different manufacturers. A future migration therefore needs a reviewable
candidate-matching process rather than automatic name-based merging.

During historical migration, red-marked drinks in the old Primärliste are not
currently possessed and must migrate as `identified`, even though they appear
in the Primärliste.

## Simple entry is permanent

Adding a newly discovered Spezi must require as little effort as reasonably
possible:

`minimum required information first -> optional enrichment later`

A future phone workflow—for example, while standing in a Getränkemarkt—should
allow rapid creation with conceptually only:

- name;
- lifecycle status; and
- one optional picture.

Optional metadata must not block creation or force a large initial form. The
record can be enriched later.

## Product-image direction

Product pictures are a core requirement, but an image is optional when a drink
is created. The standard admin workflow should behave as though a drink has one
main picture: one file input or phone-camera upload. Additional images may be a
future capability, but must not complicate basic creation.

The intended storage architecture is:

- the image file lives safely on production webspace; and
- the database stores the metadata and relative reference associating that
  file with its drink.

Uploaded image binaries should not be stored in MariaDB BLOB columns by
default. If no image exists, the future public site should display a clean
placeholder.

Users must never have to enter filenames, MIME types, dimensions, or storage
paths manually. A future upload implementation must detect or generate those
values, validate genuine supported image content, create a safe internal
filename, resize/compress for web delivery, store the result safely, and link
it to the drink.

All uploaded content is untrusted. File extensions alone are insufficient.
Future handling must verify image content and type, enforce size limits, ignore
user-controlled filesystem paths, prevent executable uploads, and fail safely
when validation or processing fails. GD and Imagick availability has not been
verified, and no image-processing dependency is selected in this packet.

The workbook audit found one image for 165 of 166 Primärliste records and one
for every Beschaffungsliste record. One missing image is valid source data, so
image absence must never block product creation or migration. Source images
use both PNG and JPEG and have varying dimensions across the workbooks; the
future model must not infer format or size from a user-supplied filename.

## Audited source facts relevant to design

These facts are supported by the legacy workbooks but do not prescribe exact
tables or column types:

- A completed historical test has nine raw inputs: Manu, Fabi, and Schorsch
  each rate Optik, Süffigkeit, and Geschmack.
- Category averages, Gesamt, rank, and price/performance are formula-derived;
  raw tester inputs are the authoritative compatibility inputs.
- Every historical Primärliste row has a stored numeric price, sometimes at
  greater precision than the two-decimal display format. Its business unit and
  basis remain unresolved.
- Tested rows carry a stream number; a subset also has a time-of-day value and
  an integer duration. These appear test-related, but their precise semantics
  and cardinality remain unresolved.
- Location fields are free text that may combine postal code, country prefix,
  and locality. Beschaffungsliste also has region and informal procurement
  geography. Raw values must survive staging before any structured parsing.
- Legacy image relationships can be mapped to product rows and images are
  optional. Image hashes are useful migration evidence but are not sufficient
  product identifiers.
- The source contains anomalies that require traceable review, including a
  shifted row, duplicate candidates, formula values in metadata columns, and
  inflated blank ranges.

## Proposed / non-final entities

The following entities describe likely boundaries only. They are not approved
tables and must not be created from this document:

- `drinks`: one distinct product and its current lifecycle state, with only
  genuinely required creation fields enforced.
- `drink_images`: image references and metadata associated with a drink.
- `drink_sources`: possible provenance or discovery/source facts.
- `tests`: recorded tasting/test events.
- `testers`: the permanent testers Manu, Fabi, and Schorsch.
- `ratings`: authoritative/raw rating inputs associated with tests and testers.

A proposed, non-final `drink_images` entity might eventually contain:

- a stable image ID;
- a drink ID;
- a relative storage path;
- detected MIME type;
- detected width and height;
- whether the image is primary; and
- a creation timestamp.

The relationship and constraints needed to guarantee at most one primary image
without complicating creation remain undecided. No exact columns, types,
indexes, or constraints are finalized here.

## Deliberately unresolved questions

- The exact test and rating schema remains pending design from the verified
  formulas, golden fixtures, and unresolved compatibility edges documented in
  `RATING_SYSTEM.md`.
- PHP numeric representation and Excel-compatible boundary behavior remain
  pending implementation and compatibility testing.
- Retesting behavior is intentionally undecided.
- Historical acquisition or inventory-event tracking is intentionally
  undecided.
- Duplicate matching rules are intentionally undecided.
- Additional-image behavior is optional future scope.
- Image-processing technology remains unselected until production PHP
  capabilities are verified.
