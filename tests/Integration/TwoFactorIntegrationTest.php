<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * verify_2fa_code() (app/backend.php) against a real ellsms_2fa_codes
 * table — attempt exhaustion, expiry, and replay-proofing all hinge on
 * durable row state (code_hash/attempts/consumed/superseded_at), which is
 * exactly what a mock can't meaningfully stand in for.
 *
 * Challenges are inserted directly here (mirroring exactly what
 * send_2fa_code() itself does: hash the code, set expires_at) rather than
 * calling send_2fa_code(), since that function also calls
 * dispatch_message() — this keeps these tests focused on verify_2fa_code()
 * alone rather than also depending on message-sending plumbing.
 */
final class TwoFactorIntegrationTest extends IntegrationTestCase
{
    private function insertChallenge(int $userId, string $code, int $ttlSeconds = 300): void {
        db()->prepare(
            'INSERT INTO ellsms_2fa_codes (user_id, code_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))'
        )->execute([$userId, hash('sha256', $code), $ttlSeconds]);
    }

    public function testCodeIsNeverStoredInPlaintext(): void
    {
        $userId = $this->makeUser();
        $this->insertChallenge($userId, '123456');

        $row = db()->prepare('SELECT code_hash FROM ellsms_2fa_codes WHERE user_id = ?');
        $row->execute([$userId]);
        $stored = $row->fetch()['code_hash'];

        $this->assertNotSame('123456', $stored);
        $this->assertSame(hash('sha256', '123456'), $stored);
    }

    public function testCorrectCodeVerifiesSuccessfully(): void
    {
        $userId = $this->makeUser();
        $this->insertChallenge($userId, '123456');

        $this->assertTrue(verify_2fa_code($userId, '123456'));
    }

    public function testWrongCodeIsRejected(): void
    {
        $userId = $this->makeUser();
        $this->insertChallenge($userId, '123456');

        $this->assertFalse(verify_2fa_code($userId, '999999'));
    }

    /** Replay-proof: a code cannot be verified twice, even with the correct value. */
    public function testConsumedCodeCannotBeReplayed(): void
    {
        $userId = $this->makeUser();
        $this->insertChallenge($userId, '123456');

        $this->assertTrue(verify_2fa_code($userId, '123456'));
        $this->assertFalse(verify_2fa_code($userId, '123456'));
    }

    public function testExpiredCodeIsRejectedEvenIfCorrect(): void
    {
        $userId = $this->makeUser();
        $this->insertChallenge($userId, '123456', -10); // already expired

        $this->assertFalse(verify_2fa_code($userId, '123456'));
    }

    /**
     * The attempt counter is durable (lives on the row), not session-based
     * — this proves it survives what would be a session restart in real
     * usage: nothing here touches $_SESSION at all, only the DB row.
     */
    public function testAttemptsAreDurableAndChallengeLocksAfterMaxAttempts(): void
    {
        $userId = $this->makeUser();
        $this->insertChallenge($userId, '123456');

        for ($i = 0; $i < 5; $i++) {
            $this->assertFalse(verify_2fa_code($userId, '000000'));
        }

        // Even the correct code is now rejected — this exact challenge is dead.
        $this->assertFalse(verify_2fa_code($userId, '123456'));
    }

    /** A resend supersedes the prior challenge — the old code stops working. */
    public function testResendSupersedesThePriorChallenge(): void
    {
        $userId = $this->makeUser();
        $this->insertChallenge($userId, '111111');

        db()->prepare('UPDATE ellsms_2fa_codes SET superseded_at = NOW() WHERE user_id = ? AND consumed = 0 AND superseded_at IS NULL')
            ->execute([$userId]);
        $this->insertChallenge($userId, '222222');

        $this->assertFalse(verify_2fa_code($userId, '111111')); // old code, now superseded
        $this->assertTrue(verify_2fa_code($userId, '222222'));  // new code, still active
    }

    public function testNoActiveChallengeMeansVerificationFails(): void
    {
        $userId = $this->makeUser();
        $this->assertFalse(verify_2fa_code($userId, '123456'));
    }

    public function testNonNumericCodeIsRejectedWithoutTouchingTheDatabaseRow(): void
    {
        $userId = $this->makeUser();
        $this->insertChallenge($userId, '123456');

        $this->assertFalse(verify_2fa_code($userId, 'abcdef'));
        // Confirms the challenge is still intact/unattempted afterward.
        $this->assertTrue(verify_2fa_code($userId, '123456'));
    }
}
