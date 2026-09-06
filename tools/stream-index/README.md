# Stream index import

Links each tested Spezi to the livestream it was tasted in and to the second
that segment starts, so the site can jump straight into the video.

## Source

`resources/stream-index/streams.txt` is the reviewed source: one block per
Testabend with the episode's YouTube address and its chapter markers, exactly as
they appear on the video. It is the only place this data is edited — never the
database directly.

Chapter labels that are part of the show rather than a tested Spezi (intro,
breaks, recap, outro) are recognised by their opening words and skipped.

## Matching

`plan.php` matches every remaining chapter label against `drinks.name` after
normalising case, umlauts, punctuation and spacing. A label only binds when
exactly one drink matches. Anything ambiguous or unmatched is reported and left
alone — nothing is guessed.

Labels that legitimately differ from the catalogued name (they lead with the
maker, or two products share a name) are resolved in `CHAPTER_OVERRIDES`, keyed
by `stream:label` and pointing at a **drink id**. Ids rather than names on
purpose: an earlier name-based mapping silently bound
"colamix Brauerei Gold Ochsen" to the unrelated drink named "Cola Mix". Every
override carries the drink's stored manufacturer in a comment so the review is
checkable.

```bash
php tools/stream-index/plan.php                          # report only
php tools/stream-index/plan.php --json=var/stream-index-plan.json
```

The plan exits non-zero while anything is ambiguous or unmatched.

## Applying

```bash
php tools/stream-index/apply.php verify                  # changes only, writes nothing
php tools/stream-index/apply.php apply
```

`apply` writes the episode rows (`test_runs`) and the per-test segment offsets
(`drink_tests.stream_reference` / `recorded_time`) in one transaction. It

- refuses to run outside `local`, `development` or `testing`;
- refuses a database whose name looks like production;
- requires the `test_runs` migration to be applied;
- rebuilds the plan from the source and refuses to run if the JSON differs, so
  an edited plan file cannot introduce an assignment the matcher would not make;
- writes the title and address from the source (the source is authoritative
  for those), but never clears a recording date the admin has already set.

The import is idempotent: re-running it writes the same values.

## Segment lengths

A Spezi's segment runs until the next chapter marker, whatever that is — the
next Spezi, a break, or the closing recap. `plan.php` derives every length
from that gap, which is why the chapter list must stay complete including the
non-Spezi markers. The 47 lengths the Primärliste already carried match the
derived ones exactly, which is what confirmed the rule.

## Dates

`date:` is empty for every episode. YouTube's watch pages sit behind a consent
redirect, so the recording dates could not be read automatically; oEmbed
returns the title but no date. Fill them in here (or in the admin) and
re-apply — the import never overwrites a date with nothing.

## Current state

All five streamed Testabende and all 125 tested Spezis are covered — every
test carries its stream number, its segment offset and its segment length.
