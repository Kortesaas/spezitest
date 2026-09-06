<?php

declare(strict_types=1);

/**
 * Stream index: match the reviewed chapter list in
 * `resources/stream-index/streams.txt` against the catalog and report what
 * would change. Read-only — `apply.php` is what writes.
 *
 * Usage:  php tools/stream-index/plan.php [--json=var/stream-index-plan.json]
 *
 * Matching is deliberately conservative. A chapter label only binds to a drink
 * when normalising both sides yields exactly one candidate; anything ambiguous
 * or unmatched is reported and left alone rather than guessed at.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/config/environment.php';

use Spezitest\Database\ConnectionFactory;
use Spezitest\Database\DatabaseConfiguration;

const SOURCE = 'resources/stream-index/streams.txt';

/**
 * Chapter labels that are part of the show rather than a tested Spezi. Matched
 * case-insensitively against the whole label or its opening words.
 */
const NON_PRODUCT_PREFIXES = [
    'countdown',
    'intro',
    'outro',
    'spezi ausblick',
    'bewertungskriterien',
    'pause',
    'große pause',
    'kurze pause',
    'weiter geht',
    'weiter mit den regulären tests',
    'auswertung',
    'rückblick',
    'fazit',
    'special guest',
    'unsere eigene spezi',
];

/**
 * Chapter labels that do not equal their catalogued product name — usually
 * because the label leads with the maker, or because two products share a name.
 * Keyed by "stream number:lowercased label", the value is the drink id.
 *
 * Each entry was checked against the drink's manufacturer, so a label can never
 * bind to a merely similarly-named product. Resolving these to an id rather
 * than to another name is deliberate: an earlier name-based mapping silently
 * bound "colamix Brauerei Gold Ochsen" to the unrelated drink named "Cola Mix".
 */
const CHAPTER_OVERRIDES = [
    // Two catalogued drinks are simply called "Spezi"; the maker separates them.
    '1:riegele spezi' => 153,                   // Spezi · Riegele
    '2:spezi (aber aus österreich) von almdudler' => 117, // Spezi · Almdudler Limonade
    // Labels that lead with the maker; the id was read back from the catalog
    // and the maker in the comment is the one stored on that drink.
    '1:red bull black orange' => 166,           // Black Orange · Red Bull
    '1:staudenbräu kola-mix' => 114,           // Kola-Mix · Staudenbräu
    '1:fritz-kola mischmasch' => 113,           // MischMasch · fritz-kola
    '1:feldschlößchen mixx' => 108,            // MIXX · Feldschlößchen
    '1:frankenbrunnen mexi' => 186,             // Mexi · Frankenbrunnen
    '1:autenrieder orange-cola' => 189,         // Orange-Cola · Autenrieder Schlossbrauerei
    '5:elephant bay cola mix' => 50,            // Cola Mix · Elephant Bay
    // The chapter list spells this one with an "ä"; the catalog (and the
    // Primärliste it came from) has "Böhringer Mix", Hirschbrauerei Schilling.
    '3:bähringer mix' => 135,                   // Böhringer Mix · Hirschbrauerei Schilling
];

function normalise(string $value): string
{
    $value = mb_strtolower(trim($value), 'UTF-8');
    $value = strtr($value, [
        'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
        '’' => '', "'" => '', '`' => '', '´' => '',
    ]);

    return (string) preg_replace('/[^a-z0-9]+/', '', $value);
}

function isNonProduct(string $label): bool
{
    $lower = mb_strtolower(trim($label), 'UTF-8');

    foreach (NON_PRODUCT_PREFIXES as $prefix) {
        if (str_starts_with($lower, $prefix)) {
            return true;
        }
    }

    return false;
}

function seconds(string $timestamp): ?int
{
    $parts = array_map('trim', explode(':', $timestamp));

    if (count($parts) === 2) {
        array_unshift($parts, '0');
    }

    if (count($parts) !== 3) {
        return null;
    }

    foreach ($parts as $part) {
        if (!ctype_digit($part)) {
            return null;
        }
    }

    return (int) $parts[0] * 3600 + (int) $parts[1] * 60 + (int) $parts[2];
}

/** @return array<int, array{url: string, title: ?string, date: ?string, chapters: list<array{seconds: int, label: string}>}> */
function parseSource(string $path): array
{
    $lines = file($path, FILE_IGNORE_NEW_LINES);

    if ($lines === false) {
        fwrite(STDERR, "Cannot read $path\n");
        exit(1);
    }

    $streams = [];
    $current = null;

    foreach ($lines as $number => $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        if (preg_match('/\A\[stream (\d+)\]\z/', $line, $match) === 1) {
            $current = (int) $match[1];
            $streams[$current] = ['url' => '', 'title' => null, 'date' => null, 'chapters' => []];

            continue;
        }

        if ($current === null) {
            fwrite(STDERR, "Line " . ($number + 1) . " outside a stream block.\n");
            exit(1);
        }

        foreach (['url' => 4, 'title' => 6, 'date' => 5] as $field => $width) {
            if (!str_starts_with($line, $field . ':')) {
                continue;
            }

            $value = trim(substr($line, $width));

            if ($field === 'date' && $value !== '' && preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $value) !== 1) {
                fwrite(STDERR, "Line " . ($number + 1) . ": date must be YYYY-MM-DD.\n");
                exit(1);
            }

            $streams[$current][$field] = $field === 'url' ? $value : ($value === '' ? null : $value);

            continue 2;
        }

        if (preg_match('/\A([0-9:]+)\s+(.+)\z/u', $line, $match) !== 1) {
            fwrite(STDERR, "Line " . ($number + 1) . " is not a chapter: $line\n");
            exit(1);
        }

        $offset = seconds($match[1]);

        if ($offset === null) {
            fwrite(STDERR, "Line " . ($number + 1) . " has an invalid timestamp: {$match[1]}\n");
            exit(1);
        }

        $streams[$current]['chapters'][] = ['seconds' => $offset, 'label' => trim($match[2])];
    }

    return $streams;
}

$pdo = (new ConnectionFactory(DatabaseConfiguration::fromEnvironment()))->create();

/** @var array<string, list<array{id: int, name: string}>> $byName */
$byName = [];
$drinkStatus = [];
$drinkNames = [];

$statement = $pdo->query('SELECT id, name, lifecycle_status FROM drinks ORDER BY id');

foreach ($statement as $row) {
    $byName[normalise((string) $row['name'])][] = ['id' => (int) $row['id'], 'name' => (string) $row['name']];
    $drinkStatus[(int) $row['id']] = (string) $row['lifecycle_status'];
    $drinkNames[(int) $row['id']] = (string) $row['name'];
}

$existing = [];

foreach ($pdo->query('SELECT drink_id, stream_reference, recorded_time FROM drink_tests') as $row) {
    $existing[(int) $row['drink_id']] = [
        'stream' => $row['stream_reference'] === null ? null : (int) $row['stream_reference'],
        'time' => $row['recorded_time'],
    ];
}

$streams = parseSource($root . '/' . SOURCE);
$plan = ['source' => SOURCE, 'streams' => [], 'assignments' => []];
$unmatched = [];
$ambiguous = [];
$matchedIds = [];
$totals = ['chapters' => 0, 'skipped' => 0, 'matched' => 0];

foreach ($streams as $number => $stream) {
    $plan['streams'][] = [
        'number' => $number,
        'url' => $stream['url'],
        'title' => $stream['title'],
        'date' => $stream['date'],
    ];
    echo "\n=== Stream $number — {$stream['url']} ===\n";

    foreach ($stream['chapters'] as $position => $chapter) {
        ++$totals['chapters'];
        // A Spezi's segment runs until the next chapter marker, whatever it
        // is — the next Spezi, a break, or the closing recap. The last
        // chapter of a stream has no successor and therefore no length.
        $next = $stream['chapters'][$position + 1]['seconds'] ?? null;
        $duration = $next === null ? null : max(0, $next - $chapter['seconds']);

        if (isNonProduct($chapter['label'])) {
            ++$totals['skipped'];

            continue;
        }

        $lower = mb_strtolower($chapter['label'], 'UTF-8');
        $override = CHAPTER_OVERRIDES[$number . ':' . $lower] ?? null;
        $candidates = $override === null ? ($byName[normalise($chapter['label'])] ?? []) : [];

        if ($override !== null) {
            if (!isset($drinkStatus[$override])) {
                fwrite(STDERR, "Override for {$chapter['label']} names unknown drink $override.\n");
                exit(1);
            }

            $drinkId = $override;
            $candidates = [['id' => $override, 'name' => $drinkNames[$override]]];
        } elseif (count($candidates) === 1) {
            $drinkId = $candidates[0]['id'];
        } elseif (count($candidates) > 1) {
            $ambiguous[] = [
                'stream' => $number,
                'label' => $chapter['label'],
                'candidates' => array_map(
                    static fn (array $c): string => $c['id'] . ' ' . $c['name'] . ' [stream '
                        . ($existing[$c['id']]['stream'] ?? '-') . ']',
                    $candidates,
                ),
            ];

            continue;
        } else {
            $unmatched[] = ['stream' => $number, 'label' => $chapter['label']];

            continue;
        }

        ++$totals['matched'];
        $matchedIds[$drinkId] = true;
        $before = $existing[$drinkId] ?? ['stream' => null, 'time' => null];
        $after = sprintf('%02d:%02d:%02d', intdiv($chapter['seconds'], 3600), intdiv($chapter['seconds'] % 3600, 60), $chapter['seconds'] % 60);
        $change = [];

        if ($before['stream'] !== $number) {
            $change[] = 'stream ' . ($before['stream'] ?? '-') . '→' . $number;
        }

        if ($before['time'] !== $after) {
            $change[] = 'time ' . ($before['time'] ?? '-') . '→' . $after;
        }

        $plan['assignments'][] = [
            'drink_id' => $drinkId,
            'name' => $candidates[0]['name'] ?? '',
            'stream' => $number,
            'offset' => $after,
            'duration' => $duration,
        ];

        printf(
            "  %-46s -> #%-4d %-44s %s\n",
            mb_substr($chapter['label'], 0, 46),
            $drinkId,
            mb_substr($candidates[0]['name'] ?? '?', 0, 44),
            $change === [] ? 'unchanged' : implode(', ', $change),
        );
    }
}

echo "\n================ summary ================\n";
printf("chapters: %d   non-product: %d   matched: %d\n", $totals['chapters'], $totals['skipped'], $totals['matched']);
printf("distinct drinks matched: %d\n", count($matchedIds));

if ($ambiguous !== []) {
    echo "\n--- AMBIGUOUS (" . count($ambiguous) . ") ---\n";

    foreach ($ambiguous as $entry) {
        echo "  stream {$entry['stream']}: {$entry['label']}\n";

        foreach ($entry['candidates'] as $candidate) {
            echo "      $candidate\n";
        }
    }
}

if ($unmatched !== []) {
    echo "\n--- UNMATCHED (" . count($unmatched) . ") ---\n";

    foreach ($unmatched as $entry) {
        echo "  stream {$entry['stream']}: {$entry['label']}\n";
    }
}

$testedWithoutChapter = [];

foreach ($existing as $drinkId => $row) {
    if ($row['stream'] !== null && !isset($matchedIds[$drinkId])) {
        $testedWithoutChapter[] = $drinkId;
    }
}

if ($testedWithoutChapter !== []) {
    echo "\n--- tested drinks with a stream but no chapter in the source (" . count($testedWithoutChapter) . ") ---\n";
    $names = $pdo->query(
        'SELECT id, name FROM drinks WHERE id IN (' . implode(',', $testedWithoutChapter) . ') ORDER BY name',
    );

    foreach ($names as $row) {
        echo "  #{$row['id']} {$row['name']} [stream " . ($existing[(int) $row['id']]['stream'] ?? '-') . "]\n";
    }
}

foreach ($argv as $argument) {
    if (str_starts_with($argument, '--json=')) {
        $target = $root . '/' . substr($argument, 7);
        @mkdir(dirname($target), 0o775, true);
        file_put_contents($target, json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        echo "\nplan written to " . substr($argument, 7) . "\n";
    }
}

exit($ambiguous === [] && $unmatched === [] ? 0 : 1);
