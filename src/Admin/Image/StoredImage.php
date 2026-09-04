<?php

declare(strict_types=1);

namespace Spezitest\Admin\Image;

final readonly class StoredImage
{
    public function __construct(
        public string $relativePath,
        public string $mimeType,
        public int $width,
        public int $height,
    ) {
    }
}
