<?php

declare(strict_types=1);

namespace Spezitest\Admin\Configuration;

use InvalidArgumentException;
use Spezitest\Configuration\AppConfiguration;

final readonly class AdminConfiguration
{
    public function __construct(
        private ?string $username,
        private ?string $passwordHash,
        private string $sessionName,
        private bool $secureCookie,
        private string $imageStorageRoot,
        private ?string $legacyImageStorageRoot,
        private int $imageMaximumBytes,
    ) {
        if (($this->username === null) !== ($this->passwordHash === null)) {
            throw new InvalidArgumentException('Admin username and password hash must be configured together.');
        }

        if ($this->username !== null && ($this->username === '' || strlen($this->username) > 190)) {
            throw new InvalidArgumentException('The configured admin username is invalid.');
        }

        if ($this->passwordHash !== null && password_get_info($this->passwordHash)['algoName'] === 'unknown') {
            throw new InvalidArgumentException('ADMIN_PASSWORD_HASH must be created by password_hash().');
        }

        if (preg_match('/\A[A-Za-z][A-Za-z0-9_-]{0,47}\z/D', $this->sessionName) !== 1) {
            throw new InvalidArgumentException('ADMIN_SESSION_NAME is invalid.');
        }

        if ($this->imageMaximumBytes < 1 || $this->imageMaximumBytes > 64 * 1024 * 1024) {
            throw new InvalidArgumentException('ADMIN_IMAGE_MAX_BYTES must be between 1 and 67108864.');
        }

        $this->assertStorageRoot($this->imageStorageRoot);

        if ($this->legacyImageStorageRoot !== null) {
            $this->assertStorageRoot($this->legacyImageStorageRoot);
        }
    }

    public static function fromEnvironment(AppConfiguration $application, string $rootDirectory): self
    {
        $username = self::nullableEnvironmentValue('ADMIN_USERNAME');
        $passwordHash = self::nullableEnvironmentValue('ADMIN_PASSWORD_HASH');
        $maximum = self::nullableEnvironmentValue('ADMIN_IMAGE_MAX_BYTES') ?? '5242880';

        if (!ctype_digit($maximum)) {
            throw new InvalidArgumentException('ADMIN_IMAGE_MAX_BYTES must be an integer.');
        }

        $imageStorageRoot = self::resolveStorageRoot(
            self::nullableEnvironmentValue('ADMIN_IMAGE_STORAGE_ROOT') ?? 'var/admin-images',
            $rootDirectory,
        );
        self::assertOutsidePublicDirectory($imageStorageRoot, $rootDirectory);

        return new self(
            $username,
            $passwordHash,
            self::nullableEnvironmentValue('ADMIN_SESSION_NAME') ?? 'SPEZITEST_ADMIN',
            $application->environment() === 'production',
            $imageStorageRoot,
            self::optionalStorageRoot('LEGACY_IMAGE_STORAGE_ROOT', $rootDirectory),
            (int) $maximum,
        );
    }

    public function username(): ?string
    {
        return $this->username;
    }

    public function passwordHash(): ?string
    {
        return $this->passwordHash;
    }

    public function sessionName(): string
    {
        return $this->sessionName;
    }

    public function secureCookie(): bool
    {
        return $this->secureCookie;
    }

    public function imageStorageRoot(): string
    {
        return $this->imageStorageRoot;
    }

    public function legacyImageStorageRoot(): ?string
    {
        return $this->legacyImageStorageRoot;
    }

    public function imageMaximumBytes(): int
    {
        return $this->imageMaximumBytes;
    }

    private static function optionalStorageRoot(string $name, string $rootDirectory): ?string
    {
        $value = self::nullableEnvironmentValue($name);

        return $value === null ? null : self::resolveStorageRoot($value, $rootDirectory);
    }

    private static function resolveStorageRoot(string $path, string $rootDirectory): string
    {
        if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return rtrim($path, DIRECTORY_SEPARATOR);
        }

        return rtrim($rootDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . trim($path, DIRECTORY_SEPARATOR);
    }

    private static function nullableEnvironmentValue(string $name): ?string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function assertOutsidePublicDirectory(string $storageRoot, string $rootDirectory): void
    {
        $publicDirectory = rtrim($rootDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'public';
        $normalizedStorage = rtrim(str_replace('\\', '/', $storageRoot), '/');
        $normalizedPublic = rtrim(str_replace('\\', '/', $publicDirectory), '/');

        if (
            $normalizedStorage === $normalizedPublic
            || str_starts_with($normalizedStorage, $normalizedPublic . '/')
        ) {
            throw new InvalidArgumentException('ADMIN_IMAGE_STORAGE_ROOT must be outside public/.');
        }
    }

    private function assertStorageRoot(string $path): void
    {
        if ($path === '' || str_contains($path, "\0")) {
            throw new InvalidArgumentException('An image storage root is invalid.');
        }

        $segments = preg_split('~[/\\\\]+~', $path);

        if (
            $segments === false
            || in_array('..', $segments, true)
            || in_array('.', $segments, true)
        ) {
            throw new InvalidArgumentException('Image storage roots must not contain dot-segment traversal.');
        }
    }
}
