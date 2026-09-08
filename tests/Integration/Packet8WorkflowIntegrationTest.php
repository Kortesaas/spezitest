<?php

declare(strict_types=1);

namespace Spezitest\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Stream;
use Slim\Psr7\UploadedFile;
use Spezitest\Admin\Configuration\AdminConfiguration;
use Spezitest\Application\AdminRuntime;
use Spezitest\Application\AppFactory;
use Spezitest\Configuration\AppConfiguration;
use Spezitest\Database\Migration\Migrator;
use Spezitest\Tests\Support\InMemorySessionStore;
use Spezitest\Tests\Support\InteractsWithTestDatabase;

/**
 * Packet 8 end-to-end coverage: test/rating entry and the completion
 * transition, the public website, catalog search/filter, image access, the
 * authorization boundary and generic error responses.
 */
final class Packet8WorkflowIntegrationTest extends TestCase
{
    use InteractsWithTestDatabase;

    private PDO $connection;

    private InMemorySessionStore $session;

    /** @var App<ContainerInterface|null> */
    private App $app;

    private string $temporaryRoot;

    protected function setUp(): void
    {
        $this->connection = $this->connectToTestDatabase();
        $this->dropAllTables();
        (new Migrator($this->connection, dirname(__DIR__, 2) . '/database/migrations'))->migrate();
        $this->temporaryRoot = sys_get_temp_dir() . '/spezitest-packet8-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->temporaryRoot, 0700, true));
        $this->session = new InMemorySessionStore();
        $this->app = $this->buildApp(fn (): PDO => $this->connection);
    }

    protected function tearDown(): void
    {
        $this->dropAllTables();
        $this->removeTree($this->temporaryRoot);
    }

    public function testTestingEnvironmentGuardIsActive(): void
    {
        $configuration = $this->testDatabaseConfiguration();

        self::assertStringEndsWith('_test', $configuration->databaseName());
        self::assertSame('testing', $_ENV['APP_ENV'] ?? getenv('APP_ENV'));
    }

    public function testDraftThenCompleteMovesDrinkToTestedAndRanks(): void
    {
        $this->login();
        $first = $this->createDrink('Flötzinger Cola-Mix', 'acquired');
        $second = $this->createDrink('Zweitplatzierter Spezi', 'acquired');

        // A partial draft keeps the drink on "acquired".
        $draft = $this->request('POST', "/admin/drinks/$first/test", [
            '_csrf' => $this->csrfToken(),
            'manu_optik' => '9', 'manu_sueffigkeit' => '10', 'manu_geschmack' => '10',
        ]);
        self::assertSame(303, $draft->getStatusCode());
        self::assertSame('acquired', $this->drinkStatus($first));
        self::assertSame('draft', $this->testStatus($first));

        // Completing with the full golden set transitions to "tested".
        $complete = $this->request('POST', "/admin/drinks/$first/test/complete", $this->goldenBody() + [
            'price' => '0,89',
            'notes' => 'Klassisches Verhältnis.',
        ]);
        self::assertSame(303, $complete->getStatusCode());
        self::assertSame('tested', $this->drinkStatus($first));
        self::assertSame('completed', $this->testStatus($first));
        self::assertSame(3, $this->ratingRowCount($first));

        // The verified engine's Gesamt for this golden set is 55,33 (derived, never stored).
        $canonical = $this->request('GET', "/spezi/$first")->getHeaderLine('Location');
        $detail = (string) $this->request('GET', $canonical)->getBody();
        self::assertStringContainsString('55,33', $detail);
        self::assertNull($this->storedGesamtColumn());

        // Each category bar exposes the three testers' grades (hover/focus peek).
        self::assertStringContainsString('rating--peek', $detail);
        self::assertStringContainsString(
            'Einzelnoten: Manu 9, Fabi 9, Schorsch 8.',
            $detail,
        );

        // The admin result view shows the per-tester matrix and links to editing.
        $result = (string) $this->request('GET', "/admin/drinks/$first/test/result")->getBody();
        self::assertStringContainsString('Einzelnoten', $result);
        self::assertStringContainsString('result-matrix', $result);
        self::assertStringContainsString('Schorsch', $result);
        self::assertStringContainsString('Ergebnis bearbeiten', $result);
        self::assertStringContainsString("/admin/drinks/$first/test\"", $result);

        // The Spezi overview links a tested drink straight to its result.
        self::assertStringContainsString(
            "/admin/drinks/$first/test/result\">Ergebnis</a>",
            (string) $this->request('GET', '/admin/drinks')->getBody(),
        );

        // A weaker completed test for the second drink establishes ranking order.
        $this->request('POST', "/admin/drinks/$second/test/complete", $this->body([
            'manu' => [4, 4, 4], 'fabi' => [4, 4, 4], 'schorsch' => [4, 4, 4],
        ]));
        self::assertSame('tested', $this->drinkStatus($second));

        $ranking = (string) $this->request('GET', '/ranking')->getBody();
        self::assertLessThan(
            strpos($ranking, 'Zweitplatzierter Spezi'),
            strpos($ranking, 'Flötzinger Cola-Mix'),
        );
    }

    public function testDrinkPriceFeedsPublicPreisLeistung(): void
    {
        $this->login();

        // Preis/Leistung is a normalised comparison across every priced,
        // tested Spezi, so a figure only exists once there are at least two of
        // them: with a single one the population's minimum equals its maximum.
        $best = $this->createPricedTestedDrink('Bepreister Spezi', '0,89', [
            'manu' => [9, 10, 10],
            'fabi' => [9, 10, 10],
            'schorsch' => [8, 8, 8],
        ]);
        $this->createPricedTestedDrink('Teurer Spezi', '2,49', [
            'manu' => [4, 4, 4],
            'fabi' => [4, 4, 4],
            'schorsch' => [4, 4, 4],
        ]);
        $this->logout();

        $canonical = $this->request('GET', "/spezi/$best")->getHeaderLine('Location');
        $detail = (string) $this->request('GET', $canonical)->getBody();

        // The recorded price stands next to the Gesamtwertung it is judged with.
        self::assertStringContainsString('0,89 €', $detail);
        self::assertStringContainsString('Preis / 500 ml', $detail);

        // The cheaper Spezi with the better grades is the reference: 100 of 100.
        self::assertStringContainsString('Preis / Leistung · von 100', $detail);
        self::assertStringContainsString(
            '<span class="score__num">100</span><span class="score__label">Preis / Leistung',
            $detail,
        );
    }

    /**
     * A drink that is priced, fully graded and therefore part of the
     * Preis/Leistung comparison population.
     *
     * @param array<string, array{int, int, int}> $grades
     */
    private function createPricedTestedDrink(string $name, string $price, array $grades): int
    {
        $id = $this->createDrink($name, 'acquired');

        $update = $this->request('POST', "/admin/drinks/$id", [
            '_csrf' => $this->csrfToken(),
            'name' => $name,
            'lifecycle_status' => 'acquired',
            'price' => $price,
            'price_volume_ml' => '500',
        ]);
        self::assertSame(303, $update->getStatusCode());

        $complete = $this->request('POST', "/admin/drinks/$id/test/complete", $this->body($grades));
        self::assertSame(303, $complete->getStatusCode());

        return $id;
    }

    public function testSpezistreamCollectsItsTestsAndDeepLinksIntoTheStream(): void
    {
        $this->login();

        // A brand-new evening takes the next free number.
        $start = $this->request('POST', '/admin/testabende', [
            '_csrf' => $this->csrfToken(),
            'number' => '1',
        ]);
        self::assertSame(303, $start->getStatusCode());
        self::assertSame('/admin/testabende/1', $start->getHeaderLine('Location'));

        // Anything completed while it runs is filed under it, without the
        // person entering the test having to say so.
        $id = $this->createDrink('Im Stream getestet', 'acquired');
        $this->request('POST', "/admin/drinks/$id/test/complete", $this->goldenBody());
        self::assertSame(1, $this->streamReference($id));

        // The episode's details, including the address the deep link needs.
        $details = $this->request('POST', '/admin/testabende/1', [
            '_csrf' => $this->csrfToken(),
            'title' => 'Spezi mit den Spezis #1',
            'recorded_on' => '2025-02-16',
            'stream_url' => 'https://www.example.org/watch?v=abc',
            'notes' => '',
        ]);
        self::assertSame(303, $details->getStatusCode());

        // A segment timestamp entered on the test form drives the jump target.
        // A completed test saves through the engine again, never as a draft.
        $position = $this->request('POST', "/admin/drinks/$id/test/complete", $this->body([
            'manu' => [9, 10, 10],
            'fabi' => [9, 10, 10],
            'schorsch' => [8, 8, 8],
        ]) + [
            'stream_reference' => '1',
            'recorded_time' => '1:07:24',
            'duration_value' => '319',
        ]);
        self::assertSame(303, $position->getStatusCode());

        $report = (string) $this->request('GET', '/admin/testabende/1')->getBody();
        self::assertStringContainsString('Spezi mit den Spezis #1', $report);
        self::assertStringContainsString('1:07:24', $report);
        self::assertStringContainsString(
            'https://www.example.org/watch?v=abc&amp;t=4044s',
            $report,
        );

        // Closing the evening stops it collecting further tests.
        $complete = $this->request('POST', '/admin/testabende/1/complete', ['_csrf' => $this->csrfToken()]);
        self::assertSame(303, $complete->getStatusCode());

        $second = $this->createDrink('Nach dem Spezistream', 'acquired');
        $this->request('POST', "/admin/drinks/$second/test/complete", $this->goldenBody());
        self::assertNull($this->streamReference($second));

        // And the public detail page offers the same jump.
        $this->logout();
        $canonical = $this->request('GET', "/spezi/$id")->getHeaderLine('Location');
        $detail = (string) $this->request('GET', $canonical)->getBody();
        // Two quiet routes: the evening's own page and the recording itself.
        self::assertStringContainsString('href="/streams/1"', $detail);
        self::assertStringContainsString('Auf YouTube ab 1:07:24', $detail);
        self::assertStringContainsString('https://www.example.org/watch?v=abc&amp;t=4044s', $detail);
    }

    public function testStreamsPageListsEpisodesAndLinksIntoTheRecording(): void
    {
        $this->login();
        $this->request('POST', '/admin/testabende', ['_csrf' => $this->csrfToken(), 'number' => '1']);

        $id = $this->createPricedTestedDrink('Im Stream verkostet', '0,89', [
            'manu' => [9, 10, 10],
            'fabi' => [9, 10, 10],
            'schorsch' => [8, 8, 8],
        ]);

        $this->request('POST', '/admin/testabende/1', [
            '_csrf' => $this->csrfToken(),
            'title' => 'Spezi mit den Spezis',
            'stream_url' => 'https://www.example.org/watch?v=abc',
        ]);
        $this->request('POST', "/admin/drinks/$id/test/complete", $this->body([
            'manu' => [9, 10, 10],
            'fabi' => [9, 10, 10],
            'schorsch' => [8, 8, 8],
        ]) + ['stream_reference' => '1', 'recorded_time' => '7:24', 'duration_value' => '319']);
        $this->logout();

        // The overview names the episode and links to its own page.
        $overview = (string) $this->request('GET', '/streams')->getBody();
        self::assertStringContainsString('Spezi mit den Spezis', $overview);
        self::assertStringContainsString('href="/streams/1"', $overview);
        self::assertStringContainsString('https://www.example.org/watch?v=abc', $overview);

        // The episode page carries the evening's figures and a jump per Spezi.
        $episode = (string) $this->request('GET', '/streams/1')->getBody();
        self::assertStringContainsString('Im Stream verkostet', $episode);
        self::assertStringContainsString('5:19 min', $episode);
        self::assertStringContainsString('Ø Gesamtwertung', $episode);
        self::assertStringContainsString('https://www.example.org/watch?v=abc&amp;t=444s', $episode);

        // An episode that does not exist is a 404, not a crash.
        self::assertSame(404, $this->request('GET', '/streams/99')->getStatusCode());
    }

    public function testStreamUrlMustBeAnAbsoluteHttpAddress(): void
    {
        $this->login();
        $this->request('POST', '/admin/testabende', ['_csrf' => $this->csrfToken(), 'number' => '1']);

        $response = $this->request('POST', '/admin/testabende/1', [
            '_csrf' => $this->csrfToken(),
            'stream_url' => 'javascript:alert(1)',
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('http:// oder https://', (string) $response->getBody());
        self::assertStringNotContainsString('javascript:alert(1)"', (string) $response->getBody());
    }

    private function streamReference(int $drinkId): ?int
    {
        $statement = $this->connection->prepare(
            'SELECT stream_reference FROM drink_tests WHERE drink_id = :drink_id ORDER BY id DESC LIMIT 1',
        );
        $statement->execute(['drink_id' => $drinkId]);
        $value = $statement->fetchColumn();

        return $value === false || $value === null ? null : (int) $value;
    }

    public function testIncompleteRatingCannotCompleteATest(): void
    {
        $this->login();
        $id = $this->createDrink('Unvollständig', 'acquired');

        $body = $this->goldenBody();
        unset($body['schorsch_geschmack']);

        $response = $this->request('POST', "/admin/drinks/$id/test/complete", $body);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('9 Noten', (string) $response->getBody());
        self::assertSame('acquired', $this->drinkStatus($id));
        self::assertContains($this->testStatus($id), ['draft', 'none']);
    }

    public function testStatusOnlyActionCannotFabricateTested(): void
    {
        $this->login();
        $id = $this->createDrink('Kein Test', 'acquired');

        $response = $this->request('POST', "/admin/drinks/$id/status", [
            '_csrf' => $this->csrfToken(),
            'lifecycle_status' => 'tested',
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('acquired', $this->drinkStatus($id));
    }

    public function testTestingAnIdentifiedDrinkIsRejected(): void
    {
        $this->login();
        $id = $this->createDrink('Nur gesehen', 'identified');

        $response = $this->request('POST', "/admin/drinks/$id/test/complete", $this->goldenBody());

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Erworben', (string) $response->getBody());
        self::assertSame('identified', $this->drinkStatus($id));
    }

    public function testPublicPagesRenderCatalogSearchAndDetail(): void
    {
        $this->login();
        $tested = $this->createDrink('Süffiger Testsieger', 'acquired');
        $this->request('POST', "/admin/drinks/$tested/test/complete", $this->goldenBody());
        $this->createDrink('Wartender Kandidat', 'acquired');
        $this->createDrink('Nur identifiziert', 'identified');
        $this->logout();

        $home = $this->request('GET', '/');
        self::assertSame(200, $home->getStatusCode());
        self::assertStringContainsString('Süffiger Testsieger', (string) $home->getBody());
        self::assertStringNotContainsString('Beispieldaten', (string) $home->getBody());

        $catalog = $this->requestWithQuery('GET', '/spezis', ['q' => 'süffiger']);
        self::assertSame(200, $catalog->getStatusCode());
        self::assertStringContainsString('Süffiger Testsieger', (string) $catalog->getBody());
        self::assertStringNotContainsString('Wartender Kandidat', (string) $catalog->getBody());

        $suggestions = $this->requestWithQuery('GET', '/spezis/vorschlaege', ['q' => 'kandidat']);
        self::assertSame(200, $suggestions->getStatusCode());
        self::assertSame('application/json; charset=UTF-8', $suggestions->getHeaderLine('Content-Type'));
        $payload = json_decode((string) $suggestions->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        $items = $payload['items'] ?? null;
        self::assertIsArray($items);
        self::assertSame(
            ['Wartender Kandidat'],
            array_column($items, 'name'),
        );

        $filtered = $this->requestWithQuery('GET', '/spezis', ['status' => ['identified']]);
        self::assertStringContainsString('Nur identifiziert', (string) $filtered->getBody());
        self::assertStringNotContainsString('Süffiger Testsieger', (string) $filtered->getBody());

        $redirect = $this->request('GET', "/spezi/$tested");
        self::assertSame(301, $redirect->getStatusCode());
        self::assertStringStartsWith("/spezi/$tested-", $redirect->getHeaderLine('Location'));

        $detail = $this->request('GET', $redirect->getHeaderLine('Location'));
        self::assertSame(200, $detail->getStatusCode());
        self::assertStringContainsString('55,33', (string) $detail->getBody());
        self::assertStringContainsString('Gesamtwertung', (string) $detail->getBody());

        $ranking = $this->request('GET', '/ranking');
        self::assertSame(200, $ranking->getStatusCode());
        self::assertStringContainsString('Süffiger Testsieger', (string) $ranking->getBody());

        $statistik = $this->request('GET', '/statistik');
        self::assertSame(200, $statistik->getStatusCode());
        self::assertStringContainsString('>getestet</p>', (string) $statistik->getBody());

        self::assertSame(200, $this->request('GET', '/ueber')->getStatusCode());
    }

    public function testHuntMapPlacesIdentifiedDrinksAndListsForeignOrigins(): void
    {
        $this->login();
        $nahe = $this->createDrinkWithOrigin('Nahe Limo', 'identified', '69115 Heidelberg', 'Baden-Württemberg');
        $this->createDrinkWithOrigin('Ferne Limo', 'identified', 'A-5020 Salzburg', 'Österreich');
        $this->createDrinkWithOrigin('Schon da', 'acquired', '69115 Heidelberg', 'Baden-Württemberg');
        $this->logout();

        $karte = $this->request('GET', '/karte');
        self::assertSame(200, $karte->getStatusCode());

        $body = (string) $karte->getBody();
        self::assertStringContainsString('Nahe Limo', $body);
        self::assertStringContainsString('id="karte-data"', $body);
        self::assertStringContainsString('Heidelberg', $body);
        self::assertStringContainsString('id="ort-69115"', $body);
        // "Open in maps" links that work in any browser; OpenStreetMap and Apple
        // Maps keep the Spezi name, Google Maps gets the exact coordinates. The
        // "geo:" link is added by JavaScript only on touch devices.
        self::assertStringContainsString('openstreetmap.org/?mlat=', $body);
        self::assertStringContainsString('maps.apple.com/?ll=', $body);
        self::assertStringContainsString('q=Nahe%20Limo', $body);
        self::assertStringContainsString('google.com/maps/search/?api=1', $body);
        self::assertStringNotContainsString('geo:', $body);
        // A GPX waypoint file is offered per place and for the whole map.
        self::assertStringContainsString('href="/karte/ort/69115.gpx"', $body);
        self::assertStringContainsString('href="/karte/spezikarte.gpx"', $body);
        // The acquired drink is not part of the hunt.
        self::assertStringNotContainsString('Schon da', $body);
        // Foreign origin is listed but not on the map.
        self::assertStringContainsString('Nicht auf der Karte', $body);
        self::assertStringContainsString('Ferne Limo', $body);

        // The map stays first-party: tiles are served through our own route,
        // so the CSP still allows images only from 'self'.
        self::assertStringContainsString("img-src 'self' data:;", $karte->getHeaderLine('Content-Security-Policy'));
        self::assertStringNotContainsString('openstreetmap.org', $karte->getHeaderLine('Content-Security-Policy'));

        // An out-of-range tile coordinate is rejected without any upstream call.
        self::assertSame(404, $this->request('GET', '/karte/kachel/2/1/1.png')->getStatusCode());

        // The "PLZ oder Ort" search resolves a postal code and a town, 404s otherwise.
        $plz = $this->requestWithQuery('GET', '/karte/suche', ['q' => '69115']);
        self::assertSame(200, $plz->getStatusCode());
        self::assertStringContainsString('application/json', $plz->getHeaderLine('Content-Type'));
        self::assertStringContainsString('"label":"69115 Heidelberg"', (string) $plz->getBody());
        $town = $this->requestWithQuery('GET', '/karte/suche', ['q' => 'Heidelberg']);
        self::assertSame(200, $town->getStatusCode());
        self::assertStringContainsString('"lat":', (string) $town->getBody());
        self::assertSame(404, $this->requestWithQuery('GET', '/karte/suche', ['q' => 'xyzzy-nowhere'])->getStatusCode());

        // Search-box type-ahead: prefix matches, capped, no results below two chars.
        $suggest = $this->requestWithQuery('GET', '/karte/vorschlaege', ['q' => 'Heidel']);
        self::assertSame(200, $suggest->getStatusCode());
        /** @var array{items: list<array{label: string, sub: ?string}>} $body */
        $body = json_decode((string) $suggest->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertLessThanOrEqual(8, count($body['items']));
        self::assertContains('Heidelberg', array_map(static fn (array $i): string => $i['label'], $body['items']));
        self::assertSame(
            '{"items":[]}',
            (string) $this->requestWithQuery('GET', '/karte/vorschlaege', ['q' => 'x'])->getBody(),
        );

        // The GPX endpoints return a real waypoint file for a place and the map.
        $placeGpx = $this->request('GET', '/karte/ort/69115.gpx');
        self::assertSame(200, $placeGpx->getStatusCode());
        self::assertStringContainsString('application/gpx+xml', $placeGpx->getHeaderLine('Content-Type'));
        self::assertStringContainsString('filename="nahe-limo.gpx"', $placeGpx->getHeaderLine('Content-Disposition'));
        self::assertStringContainsString('<name>Nahe Limo</name>', (string) $placeGpx->getBody());
        self::assertStringContainsString('<wpt ', (string) $this->request('GET', '/karte/spezikarte.gpx')->getBody());
        self::assertSame(404, $this->request('GET', '/karte/ort/00000.gpx')->getStatusCode());

        // The Spezi detail page links back to its spot on the map.
        $detail = $this->request('GET', "/spezi/$nahe");
        $detail = $this->request('GET', $detail->getHeaderLine('Location'));
        self::assertStringContainsString('/karte#ort-69115', (string) $detail->getBody());
    }

    public function testPublicMapGeoJsonFeedForUmap(): void
    {
        $this->login();
        $eligible = $this->createDrinkWithOrigin('GeoJSON Sichtbar', 'identified', '69115 Heidelberg', 'Baden-Württemberg');
        $this->createDrinkWithOrigin('GeoJSON Erworben', 'acquired', '69115 Heidelberg', 'Baden-Württemberg');
        $this->createDrinkWithOrigin('GeoJSON Ausland', 'identified', 'A-5020 Salzburg', 'Österreich');
        $this->logout();

        $response = $this->request('GET', '/api/map/spezis.geojson');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/geo+json; charset=UTF-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertStringContainsString('max-age=', $response->getHeaderLine('Cache-Control'));

        /** @var array{type: string, features: list<array{type: string, id: int, properties: array{name: string}, geometry: array{type: string, coordinates: array{0: float, 1: float}}}>} $document */
        $document = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('FeatureCollection', $document['type']);

        $byName = [];

        foreach ($document['features'] as $feature) {
            self::assertSame('Feature', $feature['type']);
            self::assertSame('Point', $feature['geometry']['type']);
            self::assertSame(['name'], array_keys($feature['properties']));
            [$longitude, $latitude] = $feature['geometry']['coordinates'];
            self::assertGreaterThanOrEqual(-180.0, $longitude);
            self::assertLessThanOrEqual(180.0, $longitude);
            self::assertGreaterThanOrEqual(-90.0, $latitude);
            self::assertLessThanOrEqual(90.0, $latitude);
            $byName[$feature['properties']['name']] = $feature;
        }

        // Eligible Spezi: present, keyed by its database id, coordinates in Heidelberg.
        self::assertArrayHasKey('GeoJSON Sichtbar', $byName);
        self::assertSame($eligible, $byName['GeoJSON Sichtbar']['id']);
        [$longitude, $latitude] = $byName['GeoJSON Sichtbar']['geometry']['coordinates'];
        self::assertEqualsWithDelta(8.69, $longitude, 0.3);
        self::assertEqualsWithDelta(49.41, $latitude, 0.3);

        // Not on the public map: an acquired drink and one without a usable origin.
        self::assertArrayNotHasKey('GeoJSON Erworben', $byName);
        self::assertArrayNotHasKey('GeoJSON Ausland', $byName);
    }

    public function testPublicMapTestGeoJsonFile(): void
    {
        $response = $this->request('GET', '/api/map/test-spezis.geojson');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/geo+json; charset=UTF-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));

        /** @var array{type: string, features: array<int, array{properties: array{name: string}, geometry: array{coordinates: array{0: float, 1: float}}}>} $document */
        $document = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('FeatureCollection', $document['type']);
        self::assertCount(3, $document['features']);
        self::assertSame('TEST Spezi Berlin', $document['features'][0]['properties']['name']);
        self::assertSame([13.405, 52.52], $document['features'][0]['geometry']['coordinates']);
    }

    public function testPublicImageIsServedWithoutAuthenticationAndMissingImageIs404(): void
    {
        $this->login();
        $withImage = $this->createDrinkWithImage('Bild Spezi');
        $withoutImage = $this->createDrink('Ohne Bild', 'acquired');
        $this->logout();

        $image = $this->request('GET', "/spezi/$withImage/bild");
        self::assertSame(200, $image->getStatusCode());
        self::assertSame('image/png', $image->getHeaderLine('Content-Type'));
        self::assertSame('nosniff', $image->getHeaderLine('X-Content-Type-Options'));
        self::assertStringContainsString('max-age', $image->getHeaderLine('Cache-Control'));

        self::assertSame(404, $this->request('GET', "/spezi/$withoutImage/bild")->getStatusCode());
        self::assertSame(404, $this->request('GET', '/spezi/999999/bild')->getStatusCode());
    }

    public function testTestRoutesRequireAuthentication(): void
    {
        $this->login();
        $id = $this->createDrink('Geschützt', 'acquired');
        $this->logout();

        self::assertSame(302, $this->request('GET', "/admin/drinks/$id/test")->getStatusCode());
        self::assertSame(
            302,
            $this->request('POST', "/admin/drinks/$id/test/complete", $this->goldenBody())->getStatusCode(),
        );
        self::assertSame('acquired', $this->drinkStatus($id));
    }

    public function testPublicPageDoesNotLeakInternalErrorDetails(): void
    {
        $marker = 'internal marker the public must never see';
        $app = $this->buildApp(static function () use ($marker): never {
            throw new RuntimeException($marker);
        }, 'production');

        $response = $app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/spezis'),
        );
        $body = (string) $response->getBody();

        self::assertSame(500, $response->getStatusCode());
        self::assertStringNotContainsString($marker, $body);
        self::assertStringNotContainsString(RuntimeException::class, $body);
    }

    // --- helpers --------------------------------------------------------

    /**
     * @param callable(): PDO $connectionFactory
     * @return App<ContainerInterface|null>
     */
    private function buildApp(callable $connectionFactory, string $environment = 'testing'): App
    {
        $configuration = new AdminConfiguration(
            'admin',
            password_hash('secret-pass-phrase', PASSWORD_DEFAULT),
            'SPEZITEST_TEST',
            false,
            $this->temporaryRoot,
            null,
            1024 * 1024,
        );
        $runtime = new AdminRuntime($configuration, $this->session, \Closure::fromCallable($connectionFactory));

        return AppFactory::create(new AppConfiguration($environment, false), new NullLogger(), $runtime);
    }

    private function login(): void
    {
        $this->request('GET', '/admin/login');
        $response = $this->request('POST', '/admin/login', [
            '_csrf' => $this->csrfToken(),
            'username' => 'admin',
            'password' => 'secret-pass-phrase',
        ]);
        self::assertSame(303, $response->getStatusCode());
    }

    private function logout(): void
    {
        $this->request('POST', '/admin/logout', ['_csrf' => $this->csrfToken()]);
    }

    private function createDrink(string $name, string $status): int
    {
        $response = $this->request('POST', '/admin/drinks', [
            '_csrf' => $this->csrfToken(),
            'name' => $name,
            'lifecycle_status' => $status,
        ]);
        self::assertSame(303, $response->getStatusCode());

        return $this->lastDrinkId();
    }

    private function createDrinkWithOrigin(string $name, string $status, string $location, string $region): int
    {
        $response = $this->request('POST', '/admin/drinks', [
            '_csrf' => $this->csrfToken(),
            'name' => $name,
            'lifecycle_status' => $status,
            'origin_location' => $location,
            'origin_region' => $region,
        ]);
        self::assertSame(303, $response->getStatusCode());

        return $this->lastDrinkId();
    }

    private function createDrinkWithImage(string $name): int
    {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        );
        self::assertIsString($png);
        $resource = fopen('php://temp', 'w+b');
        self::assertIsResource($resource);
        fwrite($resource, $png);
        rewind($resource);
        $upload = new UploadedFile(new Stream($resource), 'x.png', 'image/png', strlen($png));

        $response = $this->request(
            'POST',
            '/admin/drinks',
            ['_csrf' => $this->csrfToken(), 'name' => $name, 'lifecycle_status' => 'acquired'],
            ['picture' => $upload],
        );
        self::assertSame(303, $response->getStatusCode());

        return $this->lastDrinkId();
    }

    /**
     * @return array<string, string>
     */
    private function goldenBody(): array
    {
        return $this->body([
            'manu' => [9, 10, 10],
            'fabi' => [9, 10, 10],
            'schorsch' => [8, 8, 8],
        ]);
    }

    /**
     * @param array<string, array{int, int, int}> $grades
     * @return array<string, string>
     */
    private function body(array $grades): array
    {
        $body = ['_csrf' => $this->csrfToken()];

        foreach ($grades as $code => [$optik, $sueffigkeit, $geschmack]) {
            $body[$code . '_optik'] = (string) $optik;
            $body[$code . '_sueffigkeit'] = (string) $sueffigkeit;
            $body[$code . '_geschmack'] = (string) $geschmack;
        }

        return $body;
    }

    private function ratingRowCount(int $drinkId): int
    {
        $statement = $this->connection->prepare(
            'SELECT COUNT(*) FROM ratings WHERE test_id IN (SELECT id FROM drink_tests WHERE drink_id = :d)',
        );
        $statement->execute(['d' => $drinkId]);

        return (int) $statement->fetchColumn();
    }

    /**
     * Derived rating results must never become authoritative columns.
     */
    private function storedGesamtColumn(): ?string
    {
        $statement = $this->connection->query(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME IN ('drinks', 'drink_tests')
               AND LOWER(COLUMN_NAME) IN ('gesamt', 'gesamtwertung', 'rank', 'rang', 'score')",
        );
        self::assertNotFalse($statement);
        $value = $statement->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, UploadedFileInterface> $files
     */
    private function request(string $method, string $path, ?array $body = null, array $files = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path);

        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }

        if ($files !== []) {
            $request = $request->withUploadedFiles($files);
        }

        return $this->app->handle($request);
    }

    /**
     * @param array<string, string|list<string>> $query
     */
    private function requestWithQuery(string $method, string $path, array $query): ResponseInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, $path . '?' . http_build_query($query))
            ->withQueryParams($query);

        return $this->app->handle($request);
    }

    private function csrfToken(): string
    {
        $token = $this->session->get('csrf_token');

        if (!is_string($token)) {
            $this->request('GET', '/admin/login');
            $token = $this->session->get('csrf_token');
        }

        self::assertIsString($token);

        return $token;
    }

    private function lastDrinkId(): int
    {
        $statement = $this->connection->query('SELECT MAX(id) FROM drinks');
        self::assertNotFalse($statement);

        return (int) $statement->fetchColumn();
    }

    private function drinkStatus(int $id): string
    {
        $statement = $this->connection->prepare('SELECT lifecycle_status FROM drinks WHERE id = :id');
        $statement->execute(['id' => $id]);

        return (string) $statement->fetchColumn();
    }

    private function testStatus(int $drinkId): string
    {
        $statement = $this->connection->prepare('SELECT status FROM drink_tests WHERE drink_id = :id ORDER BY id DESC LIMIT 1');
        $statement->execute(['id' => $drinkId]);
        $value = $statement->fetchColumn();

        return $value === false ? 'none' : (string) $value;
    }

    private function dropAllTables(): void
    {
        $this->connection->exec(
            <<<'SQL'
                DROP TABLE IF EXISTS
                    ratings, drink_images, drink_tests, test_runs, legacy_import_runs,
                    testers, drinks, schema_migrations
                SQL,
        );
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $child = $path . DIRECTORY_SEPARATOR . $item;
            is_dir($child) ? $this->removeTree($child) : unlink($child);
        }

        rmdir($path);
    }
}
