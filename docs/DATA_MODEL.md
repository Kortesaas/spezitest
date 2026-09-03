# Data Model Direction

## Status of this document

This document captures known principles and proposed directions. It is **not a
final domain schema**. Packet 3 creates only migration infrastructure and the
`schema_migrations` tracking table. No drink, image, source, test, tester, or
rating table exists yet.

The actual domain schema remains blocked on formal review of the historical
Excel structure and exact rating semantics.

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

- The exact test and rating schema remains pending formal Excel verification.
- Exact formulas, precision, and rounding semantics remain pending verification
  against historical results.
- Retesting behavior is intentionally undecided.
- Historical acquisition or inventory-event tracking is intentionally
  undecided.
- Duplicate matching rules are intentionally undecided.
- Additional-image behavior is optional future scope.
- Image-processing technology remains unselected until production PHP
  capabilities are verified.
