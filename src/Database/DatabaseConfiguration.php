<?php

declare(strict_types=1);

namespace Spezitest\Database;

use SensitiveParameter;

final readonly class DatabaseConfiguration
{
    public function __construct(
        private string $host,
        private int $port,
        private string $databaseName,
        private string $user,
        #[SensitiveParameter]
        private string $password,
        private string $charset,
    ) {
        $this->assertValid();
    }

    public static function fromEnvironment(): self
    {
        $port = self::requiredEnvironmentValue('DB_PORT');

        if (!ctype_digit($port)) {
            throw new DatabaseConfigurationException('DB_PORT must be an integer from 1 to 65535.');
        }

        return new self(
            self::requiredEnvironmentValue('DB_HOST'),
            (int) $port,
            self::requiredEnvironmentValue('DB_NAME'),
            self::requiredEnvironmentValue('DB_USER'),
            self::requiredEnvironmentValue('DB_PASSWORD'),
            self::requiredEnvironmentValue('DB_CHARSET'),
        );
    }

    public function host(): string
    {
        return $this->host;
    }

    public function port(): int
    {
        return $this->port;
    }

    public function databaseName(): string
    {
        return $this->databaseName;
    }

    public function user(): string
    {
        return $this->user;
    }

    public function password(): string
    {
        return $this->password;
    }

    public function charset(): string
    {
        return $this->charset;
    }

    private function assertValid(): void
    {
        if ($this->port < 1 || $this->port > 65535) {
            throw new DatabaseConfigurationException('DB_PORT must be an integer from 1 to 65535.');
        }

        foreach (
            [
                'DB_HOST' => $this->host,
                'DB_NAME' => $this->databaseName,
                'DB_USER' => $this->user,
                'DB_PASSWORD' => $this->password,
            ] as $name => $value
        ) {
            if ($value === '') {
                throw new DatabaseConfigurationException($name . ' must not be empty.');
            }
        }

        foreach (['DB_HOST' => $this->host, 'DB_NAME' => $this->databaseName] as $name => $value) {
            if (str_contains($value, ';') || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                throw new DatabaseConfigurationException($name . ' contains invalid characters.');
            }
        }

        if (strtolower($this->charset) !== 'utf8mb4') {
            throw new DatabaseConfigurationException('DB_CHARSET must be utf8mb4.');
        }
    }

    private static function requiredEnvironmentValue(string $name): string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        if (!is_string($value) || trim($value) === '') {
            throw new DatabaseConfigurationException(
                'Required database configuration ' . $name . ' is missing.',
            );
        }

        return $value;
    }
}
