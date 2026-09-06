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

    /**
     * The canonical public origin, used wherever an absolute URL is required
     * (link previews, the canonical tag, the sitemap and the feed). Overridable
     * with `APP_URL` so a staging deployment does not advertise production URLs.
     */
    private const DEFAULT_SITE_URL = 'https://www.spezitest.de';

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

    /**
     * The canonical public origin without a trailing slash, e.g.
     * `https://www.spezitest.de`. Falls back to the production origin when
     * `APP_URL` is unset or not an absolute http(s) URL.
     */
    public function siteUrl(): string
    {
        $value = self::environmentValue('APP_URL');

        if ($value === null) {
            return self::DEFAULT_SITE_URL;
        }

        $value = rtrim(trim($value), '/');

        if (!str_starts_with($value, 'https://') && !str_starts_with($value, 'http://')) {
            return self::DEFAULT_SITE_URL;
        }

        return $value;
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
