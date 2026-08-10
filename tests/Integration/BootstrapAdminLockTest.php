<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Phase 10 / TD-034: proves the mutual-exclusion primitive `public/bootstrap-admin.php` now wraps
 * its check-then-insert critical section in — MySQL's `GET_LOCK()`/`RELEASE_LOCK()` — actually
 * excludes a second connection while held, which is what makes the race (two concurrent
 * first-admin submissions for two different accounts both passing the "no admin yet" check before
 * either INSERT commits) structurally impossible now. `GET_LOCK()` is a server-side named lock
 * scoped per MySQL session/connection, not a PHP-level one, so two genuinely separate PDO
 * connections (no subprocess needed, unlike RbacConcurrencyTest/WalletConcurrencyTest, which need
 * separate processes specifically to get separate DB TRANSACTIONS — a named lock doesn't need that)
 * are sufficient to demonstrate real cross-connection exclusion.
 */
final class BootstrapAdminLockTest extends TestCase
{
    private const LOCK_NAME = 'ellsms_bootstrap_admin';

    protected function setUp(): void
    {
        IntegrationTestCase::skipUnlessTestDatabaseConfigured($this);
    }

    private function newConnection(): \PDO {
        return new \PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', getenv('BACKEND_DB_HOST'), getenv('BACKEND_DB_PORT'), getenv('BACKEND_DB_NAME')),
            getenv('BACKEND_DB_USER'),
            getenv('BACKEND_DB_PASS'),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );
    }

    public function testSecondConnectionCannotAcquireTheLockWhileTheFirstHoldsIt(): void
    {
        $a = $this->newConnection();
        $b = $this->newConnection();

        try {
            $gotA = (bool)$a->query("SELECT GET_LOCK('" . self::LOCK_NAME . "', 5)")->fetchColumn();
            $this->assertTrue($gotA, 'the first connection must acquire the previously-free lock');

            $gotB = (bool)$b->query("SELECT GET_LOCK('" . self::LOCK_NAME . "', 0)")->fetchColumn();
            $this->assertFalse($gotB, 'a second connection must NOT be able to acquire the same named lock while the first holds it -- this is exactly the exclusion bootstrap-admin.php\'s check-then-insert now relies on');

            $released = (bool)$a->query("SELECT RELEASE_LOCK('" . self::LOCK_NAME . "')")->fetchColumn();
            $this->assertTrue($released);

            $gotBAfterRelease = (bool)$b->query("SELECT GET_LOCK('" . self::LOCK_NAME . "', 5)")->fetchColumn();
            $this->assertTrue($gotBAfterRelease, 'once released, a waiting connection must be able to acquire the lock');
            $b->query("SELECT RELEASE_LOCK('" . self::LOCK_NAME . "')");
        } finally {
            // Best-effort cleanup regardless of which assertion failed -- never leave the lock
            // held for other tests/processes sharing this database.
            @$a->query("SELECT RELEASE_LOCK('" . self::LOCK_NAME . "')");
            @$b->query("SELECT RELEASE_LOCK('" . self::LOCK_NAME . "')");
        }
    }
}
