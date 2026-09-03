<?php

declare(strict_types=1);

namespace Spezitest\Configuration;

use InvalidArgumentException;

final readonly class AppConfiguration
{
    private const ENVIRONMENTS = [
        'local',
        'testing',
        'production',
    ];

    private string $environment;

    private bool $debug;

    public function __construct(string $environment = 'production', bool $debug = false)
    {
        $environment = strtolower(trim($environment));

        if (!in_array($environment, self::ENVIRONMENTS, true)) {
            throw new InvalidArgumentException('APP_ENV must be local, testing, or production.');
        }

        $this->environment = $environment;
        $this->debug = $environment !== 'production' && $debug;
    }

    public static function fromEnvironment(): self
    {
        return new self(
            self::environmentValue('APP_ENV') ?? 'production',
            self::booleanEnvironmentValue('APP_DEBUG'),
        );
    }

    public function environment(): string
    {
        return $this->environment;
    }

    public function debug(): bool
    {
        return $this->debug;
    }

    private static function environmentValue(string $name): ?string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function booleanEnvironmentValue(string $name): bool
    {
        $value = self::environmentValue($name);

        if ($value === null) {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false;
    }
}
