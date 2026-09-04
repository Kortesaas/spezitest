<?php

declare(strict_types=1);

namespace Spezitest\Website\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Baseline security headers for the public website.
 *
 * The Content-Security-Policy keeps everything first-party: the one stylesheet,
 * the one small script and product images all load from the site's own origin
 * (plus `data:` for the inline select-arrow icon, and inline `style` attributes
 * carried over from the design system). A response that already set its own
 * `Cache-Control` (the image route) keeps it.
 */
final class WebsiteSecurityHeadersMiddleware implements MiddlewareInterface
{
    private const CONTENT_SECURITY_POLICY = "default-src 'self'; img-src 'self' data:; "
        . "style-src 'self' 'unsafe-inline'; script-src 'self'; base-uri 'none'; "
        . "frame-ancestors 'none'; form-action 'self'; object-src 'none'";

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        return self::apply($handler->handle($request));
    }

    public static function apply(ResponseInterface $response): ResponseInterface
    {
        $response = $response
            ->withHeader('Content-Security-Policy', self::CONTENT_SECURITY_POLICY)
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY');

        if (!$response->hasHeader('Cache-Control')) {
            $response = $response->withHeader('Cache-Control', 'no-cache');
        }

        return $response;
    }
}
