<?php

declare(strict_types=1);

namespace Spezitest\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\NullLogger;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Stream;
use Slim\Psr7\UploadedFile;
use Spezitest\Admin\Configuration\AdminConfiguration;
use Spezitest\Application\AdminRuntime;
use Spezitest\Application\AppFactory;
use Spezitest\Configuration\AppConfiguration;
use Spezitest\Database\Migration\Migrator;
use Spezitest\Tests\Support\InteractsWithTestDatabase;
use Spezitest\Tests\Support\InMemorySessionStore;

final class AdminApplicationIntegrationTest extends TestCase
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
        $this->temporaryRoot = sys_get_temp_dir() . '/spezitest-admin-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->temporaryRoot, 0700, true));
        $this->session = new InMemorySessionStore();
        $this->app = $this->app($this->connection, $this->session);
    }

    protected function tearDown(): void
    {
        $this->dropAllTables();
        $this->removeTree($this->temporaryRoot);
    }

    public function testAuthenticationLoginFailureSuccessLogoutAndCsrf(): void
    {
        $response = $this->request('GET', '/admin');
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/admin/login', $response->getHeaderLine('Location'));

        $loginForm = $this->request('GET', '/admin/login');
        self::assertSame(200, $loginForm->getStatusCode());
        self::assertSame('no-store', $loginForm->getHeaderLine('Cache-Control'));
        $token = $this->csrfToken();

        $missingCsrf = $this->request('POST', '/admin/login', [
            'username' => 'admin',
            'password' => 'correct horse battery staple',
        ]);
        self::assertSame(400, $missingCsrf->getStatusCode());

        $failed = $this->request('POST', '/admin/login', [
            '_csrf' => $token,
            'username' => 'admin',
            'password' => 'incorrect',
        ]);
        self::assertSame(401, $failed->getStatusCode());
        self::assertStringContainsString('Anmeldung fehlgeschlagen', (string) $failed->getBody());

        $success = $this->request('POST', '/admin/login', [
            '_csrf' => $token,
            'username' => 'admin',
            'password' => 'correct horse battery staple',
        ]);
        self::assertSame(303, $success->getStatusCode());
        self::assertSame('/admin', $success->getHeaderLine('Location'));
        self::assertSame(1, $this->session->generation());

        $dashboard = $this->request('GET', '/admin');
        self::assertSame(200, $dashboard->getStatusCode());
        self::assertStringContainsString('Identifiziert', (string) $dashboard->getBody());

        $csrfRejected = $this->request('POST', '/admin/drinks', [
            'name' => 'No CSRF',
            'lifecycle_status' => 'identified',
        ]);
        self::assertSame(400, $csrfRejected->getStatusCode());
        self::assertSame(0, $this->tableCount('drinks'));

        $logout = $this->request('POST', '/admin/logout', ['_csrf' => $this->csrfToken()]);
        self::assertSame(303, $logout->getStatusCode());
        self::assertSame('/admin/login', $logout->getHeaderLine('Location'));
        self::assertSame(2, $this->session->generation());
        self::assertSame(302, $this->request('GET', '/admin/drinks')->getStatusCode());
    }

    public function testCreateEditFilterStatusDuplicateNamesAndDelete(): void
    {
        $this->login();
        $firstId = $this->createDrink('Doppelter Name', 'identified');
        $secondId = $this->createDrink('Doppelter Name', 'acquired');
        self::assertNotSame($firstId, $secondId);
        self::assertSame(2, $this->tableCount('drinks'));

        $dashboard = (string) $this->request('GET', '/admin')->getBody();
        self::assertMatchesRegularExpression(
            '/state--identified">Identifiziert<\/span><\/div><span class="figure__num"[^>]*>1<\/span>/',
            $dashboard,
        );
        self::assertMatchesRegularExpression(
            '/state--acquired">Erworben<\/span><\/div><span class="figure__num"[^>]*>1<\/span>/',
            $dashboard,
        );

        $filteredRequest = (new ServerRequestFactory())
            ->createServerRequest('GET', '/admin/drinks?lifecycle_status=acquired&q=Doppelter')
            ->withQueryParams(['lifecycle_status' => 'acquired', 'q' => 'Doppelter']);
        $filtered = $this->app->handle($filteredRequest);
        self::assertSame(200, $filtered->getStatusCode());
        self::assertSame(1, substr_count((string) $filtered->getBody(), 'Bearbeiten</a>'));

        $edit = $this->request('POST', '/admin/drinks/' . $firstId, [
            '_csrf' => $this->csrfToken(),
            'name' => 'Bearbeitete Spezi',
            'lifecycle_status' => 'acquired',
            'manufacturer' => 'Hersteller & Co.',
            'origin_location' => 'München',
            'origin_region' => 'Bayern',
            'notes' => '<script>alert(1)</script>',
        ]);
        self::assertSame(303, $edit->getStatusCode());
        $editForm = (string) $this->request('GET', '/admin/drinks/' . $firstId . '/edit')->getBody();
        self::assertStringContainsString('Hersteller &amp; Co.', $editForm);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $editForm);
        self::assertStringNotContainsString('<script>alert(1)</script>', $editForm);

        $status = $this->request('POST', '/admin/drinks/' . $secondId . '/status', [
            '_csrf' => $this->csrfToken(),
            'lifecycle_status' => 'identified',
        ]);
        self::assertSame(303, $status->getStatusCode());
        self::assertSame('identified', $this->drinkStatus($secondId));

        $testedWithoutResults = $this->request('POST', '/admin/drinks/' . $secondId . '/status', [
            '_csrf' => $this->csrfToken(),
            'lifecycle_status' => 'tested',
        ]);
        self::assertSame(422, $testedWithoutResults->getStatusCode());
        self::assertSame('identified', $this->drinkStatus($secondId));

        $confirmation = $this->request('GET', '/admin/drinks/' . $secondId . '/delete');
        self::assertSame(200, $confirmation->getStatusCode());
        self::assertStringContainsString('wirklich gelöscht', (string) $confirmation->getBody());

        $deleted = $this->request('POST', '/admin/drinks/' . $secondId . '/delete', [
            '_csrf' => $this->csrfToken(),
        ]);
        self::assertSame(303, $deleted->getStatusCode());
        self::assertSame(1, $this->tableCount('drinks'));
    }

    public function testImageValidationGeneratedNameReplacementRemovalAndExecutableRejection(): void
    {
        $this->login();
        $png = $this->png();
        $create = $this->request(
            'POST',
            '/admin/drinks',
            [
                '_csrf' => $this->csrfToken(),
                'name' => 'Bild Spezi',
                'lifecycle_status' => 'identified',
            ],
            ['picture' => $this->upload($png, '../../unsafe.php', 'application/x-php')],
        );
        self::assertSame(303, $create->getStatusCode());
        $drinkId = $this->lastDrinkId();
        $first = $this->primaryImage($drinkId);
        self::assertMatchesRegularExpression('~\Aadmin/[a-f0-9]{48}\.png\z~D', $first['storage_path']);
        self::assertSame('image/png', $first['mime_type']);
        $firstPath = $this->temporaryRoot . '/' . $first['storage_path'];
        self::assertFileExists($firstPath);

        $served = $this->request('GET', '/admin/drinks/' . $drinkId . '/image');
        self::assertSame(200, $served->getStatusCode());
        self::assertSame('image/png', $served->getHeaderLine('Content-Type'));
        self::assertSame($png, (string) $served->getBody());

        $replace = $this->request(
            'POST',
            '/admin/drinks/' . $drinkId,
            [
                '_csrf' => $this->csrfToken(),
                'name' => 'Bild Spezi',
                'lifecycle_status' => 'acquired',
            ],
            ['picture' => $this->upload($png, 'replacement.png', 'image/png')],
        );
        self::assertSame(303, $replace->getStatusCode());
        $second = $this->primaryImage($drinkId);
        self::assertNotSame($first['storage_path'], $second['storage_path']);
        self::assertFileDoesNotExist($firstPath);
        self::assertFileExists($this->temporaryRoot . '/' . $second['storage_path']);

        $remove = $this->request('POST', '/admin/drinks/' . $drinkId, [
            '_csrf' => $this->csrfToken(),
            'name' => 'Bild Spezi',
            'lifecycle_status' => 'acquired',
            'remove_image' => '1',
        ]);
        self::assertSame(303, $remove->getStatusCode());
        self::assertSame(0, $this->tableCount('drink_images'));
        self::assertFileDoesNotExist($this->temporaryRoot . '/' . $second['storage_path']);
        self::assertSame(404, $this->request('GET', '/admin/drinks/' . $drinkId . '/image')->getStatusCode());

        $executable = '<?php system($_GET["command"]);';
        $rejected = $this->request(
            'POST',
            '/admin/drinks',
            [
                '_csrf' => $this->csrfToken(),
                'name' => 'Reject executable',
                'lifecycle_status' => 'identified',
            ],
            ['picture' => $this->upload($executable, 'payload.php', 'image/png')],
        );
        self::assertSame(422, $rejected->getStatusCode());
        self::assertStringContainsString('JPEG-, PNG- oder WebP', (string) $rejected->getBody());
        self::assertSame(1, $this->tableCount('drinks'));
    }

    public function testInvalidLifecycleIsRejectedServerSide(): void
    {
        $this->login();
        $response = $this->request('POST', '/admin/drinks', [
            '_csrf' => $this->csrfToken(),
            'name' => 'Invalid status',
            'lifecycle_status' => 'archived',
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(0, $this->tableCount('drinks'));
    }

    /** @return App<ContainerInterface|null> */
    private function app(PDO $connection, InMemorySessionStore $session): App
    {
        $configuration = new AdminConfiguration(
            'admin',
            password_hash('correct horse battery staple', PASSWORD_DEFAULT),
            'SPEZITEST_TEST',
            false,
            $this->temporaryRoot,
            null,
            1024 * 1024,
        );
        $runtime = new AdminRuntime(
            $configuration,
            $session,
            static fn (): PDO => $connection,
        );

        return AppFactory::create(
            new AppConfiguration('testing', false),
            new NullLogger(),
            $runtime,
        );
    }

    private function login(): void
    {
        $this->request('GET', '/admin/login');
        $response = $this->request('POST', '/admin/login', [
            '_csrf' => $this->csrfToken(),
            'username' => 'admin',
            'password' => 'correct horse battery staple',
        ]);
        self::assertSame(303, $response->getStatusCode());
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

    private function csrfToken(): string
    {
        $token = $this->session->get('csrf_token');
        self::assertIsString($token);

        return $token;
    }

    private function upload(string $bytes, string $name, string $clientMime): UploadedFile
    {
        $resource = fopen('php://temp', 'w+b');
        self::assertIsResource($resource);
        self::assertSame(strlen($bytes), fwrite($resource, $bytes));
        rewind($resource);

        return new UploadedFile(new Stream($resource), $name, $clientMime, strlen($bytes));
    }

    private function png(): string
    {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        );
        self::assertIsString($png);

        return $png;
    }

    private function lastDrinkId(): int
    {
        $statement = $this->connection->query('SELECT MAX(id) FROM drinks');
        self::assertNotFalse($statement);

        return (int) $statement->fetchColumn();
    }

    private function tableCount(string $table): int
    {
        self::assertContains($table, ['drinks', 'drink_images']);
        $statement = $this->connection->query('SELECT COUNT(*) FROM ' . $table);
        self::assertNotFalse($statement);

        return (int) $statement->fetchColumn();
    }

    private function drinkStatus(int $id): string
    {
        $statement = $this->connection->prepare('SELECT lifecycle_status FROM drinks WHERE id = :id');
        $statement->execute(['id' => $id]);

        return (string) $statement->fetchColumn();
    }

    /** @return array{storage_path: string, mime_type: string} */
    private function primaryImage(int $drinkId): array
    {
        $statement = $this->connection->prepare(
            'SELECT storage_path, mime_type FROM drink_images WHERE drink_id = :drink_id',
        );
        $statement->execute(['drink_id' => $drinkId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            self::fail('The primary image row is missing.');
        }

        $storagePath = $row['storage_path'] ?? null;
        $mimeType = $row['mime_type'] ?? null;

        if (!is_string($storagePath) || !is_string($mimeType)) {
            self::fail('The primary image row is invalid.');
        }

        return [
            'storage_path' => $storagePath,
            'mime_type' => $mimeType,
        ];
    }

    private function dropAllTables(): void
    {
        $this->connection->exec(
            <<<'SQL'
                DROP TABLE IF EXISTS
                    ratings,
                    drink_images,
                    drink_tests,
                    legacy_import_runs,
                    testers,
                    drinks,
                    schema_migrations
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

            if (is_dir($child)) {
                $this->removeTree($child);
            } else {
                unlink($child);
            }
        }

        rmdir($path);
    }
}
