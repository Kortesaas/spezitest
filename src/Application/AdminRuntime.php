<?php

declare(strict_types=1);

namespace Spezitest\Application;

use Closure;
use PDO;
use Spezitest\Admin\Configuration\AdminConfiguration;
use Spezitest\Admin\Session\NativeSessionStore;
use Spezitest\Admin\Session\SessionStore;
use Spezitest\Configuration\AppConfiguration;
use Spezitest\Database\ConnectionFactory;
use Spezitest\Database\DatabaseConfiguration;

final readonly class AdminRuntime
{
    /**
     * @param Closure(): PDO $connectionFactory
     */
    public function __construct(
        private AdminConfiguration $configuration,
        private SessionStore $session,
        private Closure $connectionFactory,
    ) {
    }

    public static function fromEnvironment(
        AppConfiguration $applicationConfiguration,
        string $rootDirectory,
    ): self {
        $configuration = AdminConfiguration::fromEnvironment(
            $applicationConfiguration,
            $rootDirectory,
        );

        return new self(
            $configuration,
            new NativeSessionStore(
                $configuration->sessionName(),
                $configuration->secureCookie(),
            ),
            static fn (): PDO => (new ConnectionFactory(
                DatabaseConfiguration::fromEnvironment(),
            ))->create(),
        );
    }

    public function configuration(): AdminConfiguration
    {
        return $this->configuration;
    }

    public function session(): SessionStore
    {
        return $this->session;
    }

    public function connection(): PDO
    {
        return ($this->connectionFactory)();
    }
}
