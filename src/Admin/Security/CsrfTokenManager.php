<?php

declare(strict_types=1);

namespace Spezitest\Admin\Security;

use Spezitest\Admin\Session\SessionStore;

final readonly class CsrfTokenManager
{
    private const SESSION_KEY = 'csrf_token';

    public function __construct(private SessionStore $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->get(self::SESSION_KEY);

        if (is_string($token) && preg_match('/\A[a-f0-9]{64}\z/D', $token) === 1) {
            return $token;
        }

        $token = bin2hex(random_bytes(32));
        $this->session->set(self::SESSION_KEY, $token);

        return $token;
    }

    public function validate(mixed $candidate): bool
    {
        return is_string($candidate) && hash_equals($this->token(), $candidate);
    }

    public function rotate(): void
    {
        $this->session->remove(self::SESSION_KEY);
        $this->token();
    }
}
