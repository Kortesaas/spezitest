<?php

declare(strict_types=1);

namespace Spezitest\Admin\Image;

use RuntimeException;

final readonly class ImageStorage
{
    public function __construct(
        private string $adminRoot,
        private ?string $legacyRoot,
    ) {
    }

    public function store(ValidatedImage $image): StoredImage
    {
        $directory = $this->adminRoot . DIRECTORY_SEPARATOR . 'admin';

        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Das Bild konnte nicht gespeichert werden.');
        }

        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $filename = bin2hex(random_bytes(24)) . '.' . $image->extension;
            $relativePath = 'admin/' . $filename;
            $absolutePath = $directory . DIRECTORY_SEPARATOR . $filename;
            $handle = fopen($absolutePath, 'xb');

            if ($handle === false) {
                continue;
            }

            try {
                $remaining = $image->bytes;

                while ($remaining !== '') {
                    $written = fwrite($handle, $remaining);

                    if ($written === false || $written === 0) {
                        throw new RuntimeException('Das Bild konnte nicht gespeichert werden.');
                    }

                    $remaining = substr($remaining, $written);
                }
            } catch (\Throwable $exception) {
                fclose($handle);
                unlink($absolutePath);
                throw $exception;
            }

            fclose($handle);

            if (!chmod($absolutePath, 0640)) {
                unlink($absolutePath);
                throw new RuntimeException('Das Bild konnte nicht sicher gespeichert werden.');
            }

            return new StoredImage(
                $relativePath,
                $image->mimeType,
                $image->width,
                $image->height,
            );
        }

        throw new RuntimeException('Das Bild konnte nicht unter einem sicheren Namen gespeichert werden.');
    }

    public function absolutePath(string $relativePath): ?string
    {
        if (!$this->isSafeRelativePath($relativePath)) {
            return null;
        }

        if (str_starts_with($relativePath, 'admin/')) {
            return $this->adminRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        }

        if (str_starts_with($relativePath, 'legacy/') && $this->legacyRoot !== null) {
            return $this->legacyRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        }

        return null;
    }

    public function delete(string $relativePath): bool
    {
        $absolutePath = $this->absolutePath($relativePath);

        if ($absolutePath === null || !is_file($absolutePath) || is_link($absolutePath)) {
            return false;
        }

        return unlink($absolutePath);
    }

    private function isSafeRelativePath(string $path): bool
    {
        return preg_match('~\A(?:admin|legacy)/[A-Za-z0-9][A-Za-z0-9._/-]*\z~D', $path) === 1
            && !str_contains($path, '..')
            && !str_contains($path, '//')
            && !str_contains($path, '\\');
    }
}
