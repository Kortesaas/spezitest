<?php

declare(strict_types=1);

/**
 * Regenerates src/Website/Catalog/Geo/postal-centroids.php from the GeoNames
 * postal-code dump for Germany.
 *
 * The dump is NOT tracked in this repository (it is ~5 MB). Download it fresh
 * when you need to regenerate the table:
 *
 *   curl -sSLO https://download.geonames.org/export/zip/DE.zip
 *   unzip -o DE.zip DE.txt
 *   php tools/geo/build-plz-centroids.php DE.txt
 *
 * Source: GeoNames (https://www.geonames.org/), licensed CC BY 4.0. The public
 * map page credits GeoNames as required by that licence.
 *
 * The dump has one tab-separated row per (postal code, place, admin area).
 *
 *  - 'exact'  : keyed by five-digit postal code → [lat, lon, place name]. The
 *               name is the plainest town name among the code's rows; codes
 *               whose rows are only large-recipient labels (companies, courts …)
 *               borrow the dominant name of their three-digit region.
 *  - 'prefix' : three-digit Leitregion prefix → [lat, lon], a fallback centre.
 *  - 'byName' : normalised place name → one representative postal code, for the
 *               search box (showing a Spezi's town its PLZ, and the reverse).
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$source = $argv[1] ?? null;

if (!is_string($source) || !is_file($source)) {
    fwrite(STDERR, "Usage: php tools/geo/build-plz-centroids.php <path-to-DE.txt>\n");
    exit(1);
}

$handle = fopen($source, 'r');

if ($handle === false) {
    fwrite(STDERR, "Cannot open {$source}.\n");
    exit(1);
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

/** @var array<string, array{lat: float, lon: float, n: int, names: array<string, int>, district: array<string, int>}> $codes */
$codes = [];

while (($line = fgets($handle)) !== false) {
    $fields = explode("\t", rtrim($line, "\r\n"));

    if (count($fields) < 11) {
        continue;
    }

    [$postalCode, $placeName, $admin3, $latitude, $longitude] = [$fields[1], $fields[2], $fields[7], $fields[9], $fields[10]];

    if (preg_match('/^\d{5}$/', $postalCode) !== 1 || !is_numeric($latitude) || !is_numeric($longitude)) {
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

if ($codes === []) {
    fwrite(STDERR, "No usable rows found in {$source}.\n");
    exit(1);
}

ksort($codes, SORT_STRING);

$mostCommon = static function (array $counts): ?string {
    if ($counts === []) {
        return null;
    }

    arsort($counts);

    return (string) array_key_first($counts);
};

/** @var array<string, array{0: float, 1: float, 2: string}> $exact */
$exact = [];
/** @var array<string, array{lat: float, lon: float, n: int}> $prefixAccumulator */
$prefixAccumulator = [];
/** @var array<string, array<string, int>> $prefixNames */
$prefixNames = [];
/** @var array<string, string> $byName one representative postal code per town */
$byName = [];

foreach ($codes as $postalCode => $sum) {
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

$number = static function (float $value): string {
    $formatted = rtrim(sprintf('%.4f', $value), '0');

    return str_ends_with($formatted, '.') ? $formatted . '0' : $formatted;
};

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

$generated = "<?php\n\n"
    . "declare(strict_types=1);\n\n"
    . "/**\n"
    . " * Postal-code centroids for Germany, generated by tools/geo/build-plz-centroids.php\n"
    . " * from the GeoNames postal-code dump (https://www.geonames.org/, CC BY 4.0).\n"
    . " *\n"
    . " * Do not edit by hand. 'exact' is keyed by five-digit postal code and holds\n"
    . " * [latitude, longitude, place name]; 'prefix' by the three-digit Leitregion\n"
    . " * prefix and holds [latitude, longitude], a fallback centre; 'byName' maps a\n"
    . " * normalised town name to one representative postal code. Coordinates are\n"
    . " * rounded to four decimal places (~11 m).\n"
    . " */\n\n"
    . "return [\n"
    . "    'exact' => [\n" . implode("\n", $exactLines) . "\n    ],\n"
    . "    'prefix' => [\n" . implode("\n", $prefixLines) . "\n    ],\n"
    . "    'byName' => [\n" . implode("\n", $byNameLines) . "\n    ],\n"
    . "];\n";

$target = dirname(__DIR__, 2) . '/src/Website/Catalog/Geo/postal-centroids.php';
file_put_contents($target, $generated);

fwrite(STDOUT, sprintf(
    "Wrote %s\n  %d postal codes, %d three-digit prefixes, %d named towns\n",
    $target,
    count($exact),
    count($prefix),
    count($byName),
));
