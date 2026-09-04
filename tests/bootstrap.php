<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap.
 *
 * Loads Composer autoloading and, when present, the git-ignored local
 * `.env.testing` file. That file supplies the disposable database and testing
 * environment used by `composer test:integration` so DB_* variables never have
 * to be typed by hand. Real process environment variables always win over the
 * file, so CI can still inject its own configuration.
 *
 * Unit tests never open a database connection; loading `.env.testing` here is
 * harmless for them and keeps a single bootstrap for both test suites.
 */

use Dotenv\Dotenv;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);

if (is_file($root . '/.env.testing')) {
    Dotenv::createImmutable($root, '.env.testing')->safeLoad();
}
