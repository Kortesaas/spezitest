<?php

declare(strict_types=1);

namespace Spezitest\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Spezitest\Development\LiveReloadMiddleware;

final class LiveReloadMiddlewareTest extends TestCase
{
    private function middleware(): LiveReloadMiddleware
    {
        return new LiveReloadMiddleware([__DIR__, __FILE__]);
    }

    /**
     * @param callable(): ResponseInterface $factory
     */
    private function handler(callable $factory): RequestHandlerInterface
    {
        return new class ($factory) implements RequestHandlerInterface {
            /** @param callable(): ResponseInterface $factory */
            public function __construct(private $factory)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return ($this->factory)();
            }
        };
    }

    private function request(string $path): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('GET', $path);
    }

    private function htmlResponse(string $body): ResponseInterface
    {
        $response = (new ResponseFactory())->createResponse()->withHeader('Content-Type', 'text/html; charset=UTF-8');
        $response->getBody()->write($body);

        return $response;
    }

    public function testInjectsTheReloadScriptBeforeTheClosingBodyTag(): void
    {
        $response = $this->middleware()->process(
            $this->request('/spezis'),
            $this->handler(fn (): ResponseInterface => $this->htmlResponse('<html><body><h1>Hi</h1></body></html>')),
        );

        self::assertSame(
            '<html><body><h1>Hi</h1><script src="/__dev/live.js" defer></script></body></html>',
            (string) $response->getBody(),
        );
    }

    public function testLeavesNonHtmlResponsesUntouched(): void
    {
        $css = (new ResponseFactory())->createResponse()->withHeader('Content-Type', 'text/css');
        $css->getBody()->write('body{color:red}</body>');

        $response = $this->middleware()->process(
            $this->request('/assets/app.css'),
            $this->handler(static fn (): ResponseInterface => $css),
        );

        self::assertSame('body{color:red}</body>', (string) $response->getBody());
    }

    public function testLeavesRedirectsUntouched(): void
    {
        $redirect = $this->htmlResponse('<body></body>')->withStatus(301)->withHeader('Location', '/x');

        $response = $this->middleware()->process(
            $this->request('/spezi/1'),
            $this->handler(static fn (): ResponseInterface => $redirect),
        );

        self::assertStringNotContainsString('__dev', (string) $response->getBody());
    }

    public function testPollEndpointReturnsAStableShortFingerprint(): void
    {
        $response = $this->middleware()->process(
            $this->request('/__dev/live'),
            $this->handler(static fn (): ResponseInterface => throw new \LogicException('handler must not run')),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertMatchesRegularExpression('/\A[a-f0-9]{16}\z/', (string) $response->getBody());
    }

    public function testScriptEndpointReturnsJavaScript(): void
    {
        $response = $this->middleware()->process(
            $this->request('/__dev/live.js'),
            $this->handler(static fn (): ResponseInterface => throw new \LogicException('handler must not run')),
        );

        self::assertStringContainsString('application/javascript', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString("fetch('/__dev/live'", (string) $response->getBody());
    }
}
