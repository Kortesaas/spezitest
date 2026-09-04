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

    public function testIncompleteRatingCannotCompleteATest(): void
    {
        $this->login();
        $id = $this->createDrink('Unvollständig', 'acquired');

        $body = $this->goldenBody();
        unset($body['schorsch_geschmack']);

        $response = $this->request('POST', "/admin/drinks/$id/test/complete", $body);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('neun Noten', (string) $response->getBody());
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
        self::assertStringContainsString('Spezis getestet', (string) $statistik->getBody());

        self::assertSame(200, $this->request('GET', '/ueber')->getStatusCode());
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
                    ratings, drink_images, drink_tests, legacy_import_runs,
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
