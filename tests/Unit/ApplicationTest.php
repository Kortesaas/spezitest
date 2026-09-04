<?php

declare(strict_types=1);

namespace Spezitest\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Spezitest\Admin\Configuration\AdminConfiguration;
use Spezitest\Application\AdminRuntime;
use Spezitest\Application\AppFactory;
use Spezitest\Configuration\AppConfiguration;
use Spezitest\Tests\Support\InMemorySessionStore;

final class ApplicationTest extends TestCase
{
    public function testUnknownRouteReturnsBrandedNotFoundWithoutDatabase(): void
    {
        $response = $this->app()->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/not-a-real-route'),
        );
        $body = (string) $response->getBody();

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('text/html; charset=UTF-8', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('Spezitest', $body);
        self::assertStringContainsString('Diese Flasche ist leer', $body);
    }

    public function testNotFoundResponseCarriesWebsiteSecurityHeaders(): void
    {
        $response = $this->app()->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/nope'),
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString("default-src 'self'", $response->getHeaderLine('Content-Security-Policy'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
    }

    public function testProductionErrorResponseDoesNotExposeInternalDetails(): void
    {
        $configuration = new AppConfiguration('production', true);
        $app = AppFactory::create($configuration, new NullLogger());
        $internalMessage = 'private exception marker that visitors must never see';

        $app->get(
            '/_test/error',
            static function () use ($internalMessage): never {
                throw new RuntimeException($internalMessage);
            },
        );

        $response = $app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/_test/error'),
        );
        $body = (string) $response->getBody();

        self::assertFalse($configuration->debug());
        self::assertSame(500, $response->getStatusCode());
        self::assertStringNotContainsString($internalMessage, $body);
        self::assertStringNotContainsString(RuntimeException::class, $body);
        self::assertStringNotContainsString(__FILE__, $body);
    }

    public function testAdminDatabaseFailureDoesNotExposeInternalDetails(): void
    {
        $internalMessage = 'private admin database marker';
        $session = new InMemorySessionStore();
        $session->set('admin_authenticated', true);
        $configuration = new AdminConfiguration(
            'admin',
            password_hash('unused-test-password', PASSWORD_DEFAULT),
            'SPEZITEST_TEST',
            true,
            sys_get_temp_dir() . '/spezitest-unused-admin-images',
            null,
            1024,
        );
        $runtime = new AdminRuntime(
            $configuration,
            $session,
            static function () use ($internalMessage): never {
                throw new RuntimeException($internalMessage);
            },
        );
        $app = AppFactory::create(
            new AppConfiguration('production', true),
            new NullLogger(),
            $runtime,
        );

        $response = $app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/admin'),
        );
        $body = (string) $response->getBody();

        self::assertSame(500, $response->getStatusCode());
        self::assertStringNotContainsString($internalMessage, $body);
        self::assertStringNotContainsString(RuntimeException::class, $body);
        self::assertStringNotContainsString(__FILE__, $body);
    }

    public function testProductionAdminConfigurationRequiresSecureCookies(): void
    {
        $configuration = AdminConfiguration::fromEnvironment(
            new AppConfiguration('production', false),
            dirname(__DIR__, 2),
        );

        self::assertTrue($configuration->secureCookie());
    }

    /**
     * @return App<ContainerInterface|null>
     */
    private function app(): App
    {
        return AppFactory::create(
            new AppConfiguration('testing', false),
            new NullLogger(),
        );
    }
}
