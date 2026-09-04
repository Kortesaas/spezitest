<?php

declare(strict_types=1);

namespace Spezitest\Admin\Security;

use Spezitest\Admin\Configuration\AdminConfiguration;
use Spezitest\Admin\Session\SessionStore;

final readonly class AdminAuthenticator
{
    private const SESSION_KEY = 'admin_authenticated';

    public function __construct(
        private AdminConfiguration $configuration,
        private SessionStore $session,
    ) {
    }

    public function isAuthenticated(): bool
    {
        return $this->session->get(self::SESSION_KEY) === true;
    }

    public function login(string $username, string $password): bool
    {
        $expectedUsername = $this->configuration->username();
        $expectedHash = $this->configuration->passwordHash();

        if ($expectedUsername === null || $expectedHash === null) {
            return false;
        }

        $usernameMatches = hash_equals($expectedUsername, $username);
        $passwordMatches = password_verify($password, $expectedHash);

        if (!$usernameMatches || !$passwordMatches) {
            return false;
        }

        $this->session->regenerate();
        $this->session->set(self::SESSION_KEY, true);

        return true;
    }

    public function logout(): void
    {
        $this->session->destroy();
    }
}
