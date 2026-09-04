<?php

declare(strict_types=1);

namespace Spezitest\Admin\Image;

final readonly class ValidatedImage
{
    public function __construct(
        public string $bytes,
        public string $mimeType,
        public string $extension,
        public int $width,
        public int $height,
    ) {
    }
}
