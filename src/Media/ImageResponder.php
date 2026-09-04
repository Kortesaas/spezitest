<?php

declare(strict_types=1);

namespace Spezitest\Media;

use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Stream;
use Spezitest\Admin\Image\ImageStorage;

/**
 * Streams a stored product image through a controlled application route.
 *
 * Image files live outside the public document root. This helper resolves the
 * portable database `storage_path` through {@see ImageStorage} (which rejects
 * traversal and unknown roots), verifies the target is a real regular file,
 * refuses symlinks, and only ever emits an allow-listed image content type so
 * an upload can never be served as executable content.
 */
final readonly class ImageResponder
{
    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(private ImageStorage $storage)
    {
    }

    public function respond(
        ResponseInterface $response,
        string $storagePath,
        string $mimeType,
        ?string $cacheControl = null,
    ): ResponseInterface {
        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            return $response->withStatus(404);
        }

        $path = $this->storage->absolutePath($storagePath);

        if ($path === null || !is_file($path) || is_link($path)) {
            return $response->withStatus(404);
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return $response->withStatus(404);
        }

        $size = filesize($path);

        if ($size === false) {
            fclose($handle);

            return $response->withStatus(404);
        }

        $response = $response
            ->withBody(new Stream($handle))
            ->withHeader('Content-Type', $mimeType)
            ->withHeader('Content-Length', (string) $size)
            ->withHeader('X-Content-Type-Options', 'nosniff');

        if ($cacheControl !== null) {
            $response = $response->withHeader('Cache-Control', $cacheControl);
        }

        return $response;
    }
}
