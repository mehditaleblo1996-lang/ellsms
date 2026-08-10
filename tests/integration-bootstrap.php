<?php
/**
 * PHPUnit bootstrap for the integration suite ONLY (phpunit.integration.xml).
 *
 * Unlike tests/bootstrap.php (unit suite), this does NOT set BACKEND_DB_*
 * here — IntegrationTestCase::setUp() does that per-test, and skips the
 * test entirely if ELLSMS_TEST_DB_HOST isn't set, so this suite can never
 * accidentally run against a real/production database just because
 * BACKEND_DB_* happened to be present in the environment.
 */

declare(strict_types=1);

putenv('APP_ENV=testing');

require_once __DIR__ . '/../app/backend.php';
require_once __DIR__ . '/../app/zarinpal.php'; // payment_claim_and_credit(), zarinpal_verify() — needed by PaymentIntegrationTest
