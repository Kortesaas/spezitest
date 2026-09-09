<?php

declare(strict_types=1);

/**
 * Regenerates src/Website/Catalog/Geo/postal-centroids.php from the GeoNames
 * postal-code dumps for Germany, Austria, Switzerland, Liechtenstein, Sweden
 * and Luxembourg.
 *
 * The dumps are NOT tracked in this repository. Download them fresh when you
 * need to regenerate the table:
 *
 *   for c in DE AT CH LI SE LU; do
 *     curl -sSLO "https://download.geonames.org/export/zip/$c.zip"
 *     unzip -o "$c.zip" "$c.txt"
 *   done
 *   php tools/geo/build-plz-centroids.php DE.txt AT.txt CH.txt LI.txt SE.txt LU.txt
 *
 * The first file must be Germany; the rest are the foreign entries and may
 * be omitted. Source: GeoNames (https://www.geonames.org/), licensed CC BY 4.0.
 * The public map page credits GeoNames as required by that licence.
 *
 * Each dump has one tab-separated row per (postal code, place, admin area):
 *   [0] country  [1] postal code  [2] place name  [7] admin3 name
 *   [9] latitude [10] longitude
 *
 * Output:
 *  - 'exact'   : German five-digit code → [lat, lon, place name]. The name is
 *                the plainest town name among the code's rows; large-recipient
 *                codes borrow the dominant name of their three-digit region.
 *  - 'prefix'  : German three-digit Leitregion prefix → [lat, lon] fallback.
 *  - 'byName'  : normalised German town name → one representative code, for the
 *                search box.
 *  - 'foreign' : country code → postal key → [lat, lon, place]. AT/CH/LI keep
 *                the four-digit code; Sweden and Luxembourg are aggregated to
 *                the three-digit prefix (the postal town) because their code
 *                lists are large. No prefix fallback and no name index — the
 *                map labels foreign pins from the drink's own origin string.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$sources = array_slice($argv, 1);

if ($sources === []) {
    fwrite(STDERR, "Usage: php tools/geo/build-plz-centroids.php <DE.txt> [AT.txt CH.txt LI.txt]\n");
    exit(1);
}

foreach ($sources as $source) {
    if (!is_file($source)) {
        fwrite(STDERR, "Not a file: {$source}\n");
        exit(1);
    }
}

$normalise = static function (string $value): string {
    $value = mb_strtolower(trim($value), 'UTF-8');
    $value = strtr($value, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);

    return (string) preg_replace('/[^a-z0-9]+/', '', $value);
};

// A "place name" that is really an organisation, a PO box or otherwise not a
// town: skip it when choosing a code's town name.
$isTownName = static function (string $name): bool {
    if ($name === '' || mb_strlen($name) > 40 || preg_match('/\d/', $name) === 1) {
        return false;
    }

    return preg_match(
        '/\b(gmbh|mbh|ag|kg|ohg|co|e\.?\s?v|amtsgericht|landgericht|oberlandesgericht|'
        . 'staatsanwaltschaft|generalstaatsanwaltschaft|finanzamt|hauptzollamt|zollamt|'
        . 'postfach|grosskunden|großkunden|deutsche\s?post|bundes|ministerium|beh(oe|ö)rde|'
        . 'sparkasse|\bbank\b|versicherung|bausparkasse|aok|krankenkasse|verlag|redaktion|'
        . 'klinik|klinikum|krankenhaus|hochschule|universit(ae|ä)t|stadtverwaltung|'
        . 'kreisverwaltung|landratsamt|bundeswehr|bundesagentur|rundfunk|lotto)\b/iu',
        $name,
    ) !== 1;
};

// "Kreisfreie Stadt München" / "Berlin, Stadt" / "Landkreis Passau" → the
// bare town, used as a fallback when a code carries no plain place name.
$districtTown = static function (string $admin3): string {
    $admin3 = trim($admin3);
    $admin3 = trim((string) preg_replace('/,\s*(Freie und Hansestadt|Hansestadt|Landeshauptstadt|Stadt|Landkreis|Kreis)$/iu', '', $admin3));
    $admin3 = trim((string) preg_replace('/^(Kreisfreie Stadt|Landeshauptstadt|Freie und Hansestadt|Hansestadt|Landkreis|Kreis|Stadt)\s+/iu', '', $admin3));

    return $admin3;
};

$mostCommon = static function (array $counts): ?string {
    if ($counts === []) {
        return null;
    }

    arsort($counts);

    return (string) array_key_first($counts);
};

$number = static function (float $value): string {
    $formatted = rtrim(sprintf('%.4f', $value), '0');

    return str_ends_with($formatted, '.') ? $formatted . '0' : $formatted;
};

/**
 * Aggregates one dump into per-code sums. `$codePattern` decides which postal
 * codes count (five digits for Germany, four for the neighbours).
 *
 * @return array<string, array{lat: float, lon: float, n: int, names: array<string, int>, district: array<string, int>}>
 */
$readDump = static function (string $path, string $codePattern) use ($isTownName, $districtTown): array {
    $handle = fopen($path, 'r');

    if ($handle === false) {
        fwrite(STDERR, "Cannot open {$path}.\n");
        exit(1);
    }

    /** @var array<string, array{lat: float, lon: float, n: int, names: array<string, int>, district: array<string, int>}> $codes */
    $codes = [];

    while (($line = fgets($handle)) !== false) {
        $fields = explode("\t", rtrim($line, "\r\n"));

        if (count($fields) < 11) {
            continue;
        }

        [$postalCode, $placeName, $admin3, $latitude, $longitude] = [$fields[1], $fields[2], $fields[7], $fields[9], $fields[10]];

        if (preg_match($codePattern, $postalCode) !== 1 || !is_numeric($latitude) || !is_numeric($longitude)) {
            continue;
        }

        $codes[$postalCode] ??= ['lat' => 0.0, 'lon' => 0.0, 'n' => 0, 'names' => [], 'district' => []];
        $codes[$postalCode]['lat'] += (float) $latitude;
        $codes[$postalCode]['lon'] += (float) $longitude;
        ++$codes[$postalCode]['n'];

        $placeName = trim($placeName);

        if ($isTownName($placeName)) {
            $codes[$postalCode]['names'][$placeName] = ($codes[$postalCode]['names'][$placeName] ?? 0) + 1;
        }

        $town = $districtTown($admin3);

        if ($isTownName($town)) {
            $codes[$postalCode]['district'][$town] = ($codes[$postalCode]['district'][$town] ?? 0) + 1;
        }
    }

    fclose($handle);

    return $codes;
};

// --- Germany -------------------------------------------------------------

$germany = $readDump($sources[0], '/^\d{5}$/');

if ($germany === []) {
    fwrite(STDERR, "No usable German rows found in {$sources[0]}.\n");
    exit(1);
}

ksort($germany, SORT_STRING);

/** @var array<string, array{0: float, 1: float, 2: string}> $exact */
$exact = [];
/** @var array<string, array{lat: float, lon: float, n: int}> $prefixAccumulator */
$prefixAccumulator = [];
/** @var array<string, array<string, int>> $prefixNames */
$prefixNames = [];
/** @var array<string, string> $byName one representative postal code per town */
$byName = [];

foreach ($germany as $postalCode => $sum) {
    $postalCode = (string) $postalCode;
    $lat = round($sum['lat'] / $sum['n'], 4);
    $lon = round($sum['lon'] / $sum['n'], 4);
    $prefixKey = substr($postalCode, 0, 3);

    // A code with a handful of place names is a real town (its name, or the two
    // halves of a hyphenated one). A code with a long list of them is a
    // large-recipient code — companies, courts — so fall back to its district
    // town; failing that, the region's dominant name (filled in below).
    $fromPlaceName = count($sum['names']) <= 3 && $sum['names'] !== [];
    $town = $fromPlaceName
        ? ($mostCommon($sum['names']) ?? '')
        : ($mostCommon($sum['district']) ?? '');
    $exact[$postalCode] = [$lat, $lon, $town];

    // The name → code map only takes codes whose town is a real place name (not
    // a district fallback), so "München" resolves to an 80xxx code, not to a
    // large-recipient code that happens to sit in Munich.
    if ($fromPlaceName) {
        $key = $normalise($town);

        if ($key !== '' && (!isset($byName[$key]) || $postalCode < $byName[$key])) {
            $byName[$key] = $postalCode;
        }
    }

    $prefixAccumulator[$prefixKey] ??= ['lat' => 0.0, 'lon' => 0.0, 'n' => 0];
    $prefixAccumulator[$prefixKey]['lat'] += $lat;
    $prefixAccumulator[$prefixKey]['lon'] += $lon;
    ++$prefixAccumulator[$prefixKey]['n'];

    foreach ($sum['names'] as $name => $count) {
        $prefixNames[$prefixKey][$name] = ($prefixNames[$prefixKey][$name] ?? 0) + $count;
    }
}

// Codes with no town name of their own borrow their region's dominant name.
foreach ($exact as $postalCode => $value) {
    if ($value[2] === '') {
        $exact[$postalCode][2] = $mostCommon($prefixNames[substr((string) $postalCode, 0, 3)] ?? []) ?? '';
    }
}

ksort($prefixAccumulator, SORT_STRING);

/** @var array<string, array{0: float, 1: float}> $prefix */
$prefix = [];

foreach ($prefixAccumulator as $key => $sum) {
    $prefix[$key] = [round($sum['lat'] / $sum['n'], 4), round($sum['lon'] / $sum['n'], 4)];
}

ksort($byName, SORT_STRING);

// --- Foreign neighbours (Austria, Switzerland, Liechtenstein, Sweden, Luxembourg) ---

// AT/CH/LI keep their full four-digit code. Sweden and Luxembourg have dense
// code lists, so they are aggregated to the three-digit prefix, which is the
// postal town — enough for a map pin and it keeps the table small.
$foreignKeyBy = ['AT' => 4, 'CH' => 4, 'LI' => 4, 'SE' => 3, 'LU' => 3];

/** @var array<string, array<string, array{0: float, 1: float, 2: string}>> $foreign */
$foreign = [];

foreach (array_slice($sources, 1) as $path) {
    // GeoNames names the file by ISO country code (AT.txt, CH.txt, LI.txt, SE.txt).
    $country = strtoupper(substr(basename($path), 0, 2));

    if (!isset($foreignKeyBy[$country])) {
        fwrite(STDERR, "Skipping {$path}: expected AT.txt, CH.txt, LI.txt, SE.txt or LU.txt.\n");
        continue;
    }

    $keyLength = $foreignKeyBy[$country];
    // Swedish codes are written "352 46"; Luxembourg's carry an "L-" prefix.
    $pattern = match ($country) {
        'SE' => '/^\d{3} ?\d{2}$/',
        'LU' => '/^L-\d{4}$/',
        default => '/^\d{4}$/',
    };

    /** @var array<string, array{lat: float, lon: float, n: int, names: array<string, int>, district: array<string, int>}> $grouped */
    $grouped = [];

    foreach ($readDump($path, $pattern) as $rawCode => $sum) {
        $key = substr((string) preg_replace('/\D/', '', (string) $rawCode), 0, $keyLength);
        $grouped[$key] ??= ['lat' => 0.0, 'lon' => 0.0, 'n' => 0, 'names' => [], 'district' => []];
        $grouped[$key]['lat'] += $sum['lat'];
        $grouped[$key]['lon'] += $sum['lon'];
        $grouped[$key]['n'] += $sum['n'];

        foreach (['names', 'district'] as $bucket) {
            foreach ($sum[$bucket] as $name => $count) {
                $grouped[$key][$bucket][$name] = ($grouped[$key][$bucket][$name] ?? 0) + $count;
            }
        }
    }

    ksort($grouped, SORT_STRING);

    foreach ($grouped as $key => $sum) {
        $fromPlaceName = count($sum['names']) <= 3 && $sum['names'] !== [];
        $town = $fromPlaceName
            ? ($mostCommon($sum['names']) ?? '')
            : ($mostCommon($sum['district']) ?? ($mostCommon($sum['names']) ?? ''));

        $foreign[$country][$key] = [
            round($sum['lat'] / $sum['n'], 4),
            round($sum['lon'] / $sum['n'], 4),
            $town,
        ];
    }
}

ksort($foreign, SORT_STRING);

// --- Emit --------------------------------------------------------------

$exactLines = [];

foreach ($exact as $key => [$lat, $lon, $name]) {
    $exactLines[] = sprintf("        '%s' => [%s, %s, %s],", (string) $key, $number($lat), $number($lon), var_export($name, true));
}

$prefixLines = [];

foreach ($prefix as $key => [$lat, $lon]) {
    $prefixLines[] = sprintf("        '%s' => [%s, %s],", (string) $key, $number($lat), $number($lon));
}

$byNameLines = [];

foreach ($byName as $key => $code) {
    $byNameLines[] = sprintf("        '%s' => '%s',", $key, $code);
}

$foreignLines = [];

foreach ($foreign as $country => $codes) {
    $foreignLines[] = sprintf("        '%s' => [", $country);

    foreach ($codes as $key => [$lat, $lon, $name]) {
        $foreignLines[] = sprintf("            '%s' => [%s, %s, %s],", (string) $key, $number($lat), $number($lon), var_export($name, true));
    }

    $foreignLines[] = '        ],';
}

$generated = "<?php\n\n"
    . "declare(strict_types=1);\n\n"
    . "/**\n"
    . " * Postal-code centroids for Germany and its neighbours, generated by\n"
    . " * tools/geo/build-plz-centroids.php from the GeoNames postal-code dumps\n"
    . " * (https://www.geonames.org/, CC BY 4.0).\n"
    . " *\n"
    . " * Do not edit by hand. 'exact' is keyed by German five-digit postal code and\n"
    . " * holds [latitude, longitude, place name]; 'prefix' by the three-digit\n"
    . " * Leitregion prefix and holds [latitude, longitude], a fallback centre;\n"
    . " * 'byName' maps a normalised German town name to one representative code;\n"
    . " * 'foreign' is keyed by country code then postal key: AT/CH/LI by the\n"
    . " * four-digit code, SE and LU by the three-digit prefix (the postal town),\n"
    . " * each holding [latitude, longitude, place name].\n"
    . " * Coordinates are rounded to four decimal places (~11 m).\n"
    . " */\n\n"
    . "return [\n"
    . "    'exact' => [\n" . implode("\n", $exactLines) . "\n    ],\n"
    . "    'prefix' => [\n" . implode("\n", $prefixLines) . "\n    ],\n"
    . "    'byName' => [\n" . implode("\n", $byNameLines) . "\n    ],\n"
    . "    'foreign' => [\n" . implode("\n", $foreignLines) . "\n    ],\n"
    . "];\n";

$target = dirname(__DIR__, 2) . '/src/Website/Catalog/Geo/postal-centroids.php';
file_put_contents($target, $generated);

$foreignCount = array_sum(array_map('count', $foreign));

fwrite(STDOUT, sprintf(
    "Wrote %s\n  %d German codes, %d Leitregion prefixes, %d named towns, %d foreign codes (%s)\n",
    $target,
    count($exact),
    count($prefix),
    count($byName),
    $foreignCount,
    implode(', ', array_map(static fn (string $c): string => $c . ':' . count($foreign[$c]), array_keys($foreign))) ?: 'none',
));
