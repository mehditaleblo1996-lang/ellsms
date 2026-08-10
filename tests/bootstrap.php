<?php
/**
 * PHPUnit bootstrap — distinct from app/bootstrap.php, which this loads.
 *
 * Deliberately loads the real app/bootstrap.php (and app/backend.php) so
 * tests exercise the actual functions the app runs, not copies/reimplementations.
 * This is safe without a database: db() is lazy (only connects when a test
 * actually calls it, which none of the current tests do — see
 * tests/Unit/README or each test class's own docblock for what's covered),
 * and app/bootstrap.php's session_start() is already skipped under CLI SAPI
 * (the same condition that makes `php cron/worker.php` safe without a
 * browser session applies equally here).
 */

declare(strict_types=1);

putenv('APP_ENV=testing');

require_once __DIR__ . '/../app/backend.php'; // pulls in app/bootstrap.php too
