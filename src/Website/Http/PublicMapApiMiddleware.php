<?php

declare(strict_types=1);

namespace Spezitest\Website\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Headers for the public map API under `/api/map/…`.
 *
 * These endpoints exist to be read cross-origin by a third-party map viewer
 * (uMap), so their responses carry `Access-Control-Allow-Origin: *`. The data
 * is a public subset of the catalogue — Spezi name and an approximate
 * coordinate — and nothing here is authenticated. `nosniff` is kept; the
 * site-wide CSP and frame headers are not, because they only matter for
 * rendered pages.
 *
 * A cross-origin `GET` of an `application/geo+json` body is a CORS "simple
 * request" and triggers no preflight, so no `OPTIONS` handling is needed.
 */
final class PublicMapApiMiddleware implements MiddlewareInterface
{
    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        return $handler->handle($request)
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }
}
