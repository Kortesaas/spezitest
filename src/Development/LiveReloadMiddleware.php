<?php

declare(strict_types=1);

namespace Spezitest\Development;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Optional, dependency-free live reload for local development.
 *
 * It is registered by {@see \Spezitest\Application\AppFactory} ONLY when the
 * environment is non-production and `APP_DEBUG` is true, so it can never affect
 * a production deployment. There is no build step, no websocket, and no Node.
 *
 * How it works:
 *  - a tiny script is injected before `</body>` of every HTML response;
 *  - that script polls `GET /__dev/live` once per second;
 *  - the endpoint returns a short fingerprint of the watched source trees
 *    (newest mtime + file count); when it changes, the browser reloads.
 *
 * The injected `<script src>` and the `fetch()` are same-origin, so the normal
 * `script-src 'self'` / `default-src 'self'` CSP already allows them.
 */
final class LiveReloadMiddleware implements MiddlewareInterface
{
    private const POLL_PATH = '/__dev/live';

    private const SCRIPT_PATH = '/__dev/live.js';

    /**
     * @param list<string> $watchedPaths Absolute files/directories to fingerprint.
     */
    public function __construct(private readonly array $watchedPaths)
    {
    }

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $path = $request->getUri()->getPath();

        if ($path === self::POLL_PATH) {
            return $this->textResponse($this->fingerprint(), 'text/plain; charset=UTF-8');
        }

        if ($path === self::SCRIPT_PATH) {
            return $this->textResponse($this->clientScript(), 'application/javascript; charset=UTF-8');
        }

        $response = $handler->handle($request);

        return $this->inject($response);
    }

    private function inject(ResponseInterface $response): ResponseInterface
    {
        $contentType = strtolower($response->getHeaderLine('Content-Type'));

        if (!str_contains($contentType, 'text/html') || $response->getStatusCode() >= 300 && $response->getStatusCode() < 400) {
            return $response;
        }

        $body = (string) $response->getBody();
        $marker = '</body>';
        $position = strripos($body, $marker);

        if ($position === false) {
            return $response;
        }

        $tag = '<script src="' . self::SCRIPT_PATH . '" defer></script>';
        $patched = substr($body, 0, $position) . $tag . substr($body, $position);

        $stream = (new ResponseFactory())->createResponse()->getBody();
        $stream->write($patched);
        $stream->rewind();

        return $response
            ->withBody($stream)
            ->withHeader('Content-Length', (string) strlen($patched));
    }

    private function fingerprint(): string
    {
        $newest = 0;
        $count = 0;

        foreach ($this->watchedPaths as $path) {
            if (is_file($path)) {
                $newest = max($newest, (int) @filemtime($path));
                ++$count;

                continue;
            }

            if (!is_dir($path)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                    continue;
                }

                $newest = max($newest, (int) $file->getMTime());
                ++$count;
            }
        }

        return substr(hash('sha256', $newest . ':' . $count), 0, 16);
    }

    private function clientScript(): string
    {
        return <<<'JS'
            /* Spezitest local live reload — active only with APP_ENV != production and APP_DEBUG=true. */
            (function () {
              var known = null;
              function tick() {
                fetch('/__dev/live', { cache: 'no-store' })
                  .then(function (r) { return r.text(); })
                  .then(function (token) {
                    if (known === null) { known = token; }
                    else if (token !== known) { window.location.reload(); }
                  })
                  .catch(function () {})
                  .then(function () { window.setTimeout(tick, 1000); });
              }
              tick();
            })();
            JS;
    }

    private function textResponse(string $body, string $contentType): ResponseInterface
    {
        $response = (new ResponseFactory())->createResponse();
        $response->getBody()->write($body);

        return $response
            ->withHeader('Content-Type', $contentType)
            ->withHeader('Cache-Control', 'no-store');
    }
}
