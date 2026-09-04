# Data Lifecycle

## Canonical model

Every Cola-Mix / Spezi drink is represented by one logical drink record with
one current lifecycle status:

`identified -> acquired -> tested`

The statuses describe the current state of that same record. They may be shown
as separate filtered views or lists, but they are not separate datasets. A
drink must never be duplicated, copied, or physically moved between stores
merely to change its status.

## Status definitions

### `identified`

We know the Cola-Mix exists, but currently do not possess it.

### `acquired`

We physically possess at least one unit, and it is awaiting testing.

### `tested`

The drink has been tested, and its test results have been recorded.

## Transition meaning

- `identified -> acquired`: at least one unit has been physically obtained and
  is waiting to be tested.
- `acquired -> tested`: testing has occurred and the test results have been
  recorded.

The lifecycle value is current state, not a substitute for duplicating a
record. If a future requirement needs event history, inventory history, or
retesting, it must be modeled explicitly without violating the single-record
identity rule. This foundation does not define those future models.

## Historical migration rule

Red-marked drinks in the old Primärliste are **not currently possessed** and
must migrate as `identified`, even though they appear in the Primärliste.
List membership alone must not override the red marking during migration.

Packet 6 implements and locally validates a controlled importer, but no legacy
data has been imported into production. Any real migration must use its
repeatable, auditable mapping and explicit duplicate review.

## Source-of-truth rule

After the controlled migration, the database will be the single source of truth.
The old Excel sheets are migration sources only and will be retired from
day-to-day operation. They must not be kept as a second writable operational
dataset.

## Simple entry principle

Adding a newly discovered Spezi must require as little effort as reasonably
possible. The permanent product direction is:

`minimum required information first -> optional enrichment later`

A future user should be able to record an identified drink quickly from a
phone—for example, while standing in a Getränkemarkt—without completing a large
metadata form. Conceptually, basic creation needs only a name, a lifecycle
status, and an optional picture. Optional metadata must not become a database
or application prerequisite for creating the drink record.
