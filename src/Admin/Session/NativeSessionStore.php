<?php

declare(strict_types=1);

namespace Spezitest\Admin\Session;

use RuntimeException;

final class NativeSessionStore implements SessionStore
{
    private bool $started = false;

    public function __construct(
        private readonly string $name,
        private readonly bool $secureCookie,
    ) {
    }

    public function start(): void
    {
        if ($this->started) {
            return;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->started = true;

            return;
        }

        if (headers_sent()) {
            throw new RuntimeException('The secure session could not be started.');
        }

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        session_name($this->name);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $this->secureCookie,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);

        if (!session_start()) {
            throw new RuntimeException('The secure session could not be started.');
        }

        $this->started = true;
    }

    public function get(string $key): mixed
    {
        $this->start();

        return $_SESSION[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->start();
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        $this->start();
        unset($_SESSION[$key]);
    }

    public function regenerate(): void
    {
        $this->start();

        if (!session_regenerate_id(true)) {
            throw new RuntimeException('The secure session could not be renewed.');
        }
    }

    public function destroy(): void
    {
        $this->start();
        $_SESSION = [];

        if (filter_var(ini_get('session.use_cookies'), FILTER_VALIDATE_BOOL)) {
            $parameters = session_get_cookie_params();
            setcookie($this->name, '', [
                'expires' => time() - 42000,
                'path' => $parameters['path'],
                'domain' => $parameters['domain'],
                'secure' => $parameters['secure'],
                'httponly' => $parameters['httponly'],
                'samesite' => $parameters['samesite'],
            ]);
        }

        session_destroy();
        $this->started = false;
    }
}
