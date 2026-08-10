<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Phase 12 (STEP 30): AES-256-GCM envelope encryption for webhook signing secrets. Uses a
 * freshly-generated, valid 32-byte WEBHOOK_MASTER_KEY for the duration of each test so this suite
 * never depends on (or leaks into) whatever the real environment has configured.
 */
final class WebhookSecretEncryptionTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('WEBHOOK_MASTER_KEY=' . base64_encode(random_bytes(32)));
    }

    protected function tearDown(): void
    {
        putenv('WEBHOOK_MASTER_KEY');
    }

    public function testEncryptThenDecryptRoundTrips(): void
    {
        $secret = 'whsec_' . bin2hex(random_bytes(24));
        [$ciphertext, $nonce, $tag] = \webhook_encrypt_secret($secret);
        $this->assertNotSame($secret, $ciphertext, 'ciphertext must not equal the plaintext');
        $decrypted = \webhook_decrypt_secret($ciphertext, $nonce, $tag);
        $this->assertSame($secret, $decrypted);
    }

    public function testDecryptFailsWithWrongKey(): void
    {
        $secret = 'whsec_test';
        [$ciphertext, $nonce, $tag] = \webhook_encrypt_secret($secret);

        putenv('WEBHOOK_MASTER_KEY=' . base64_encode(random_bytes(32))); // swap to a different key
        $decrypted = \webhook_decrypt_secret($ciphertext, $nonce, $tag);
        $this->assertNull($decrypted, 'decryption with the wrong key must fail closed, not return garbage');
    }

    public function testDecryptFailsWithTamperedCiphertext(): void
    {
        [$ciphertext, $nonce, $tag] = \webhook_encrypt_secret('whsec_test');
        $tampered = substr($ciphertext, 0, -1) . chr(ord(substr($ciphertext, -1)) ^ 0xFF);
        $this->assertNull(\webhook_decrypt_secret($tampered, $nonce, $tag), 'GCM must reject a tampered ciphertext, not silently decrypt it wrong');
    }

    public function testMasterKeyRejectsMissingValue(): void
    {
        putenv('WEBHOOK_MASTER_KEY');
        $this->expectException(\WebhookMasterKeyException::class);
        \webhook_master_key();
    }

    public function testMasterKeyRejectsWrongLength(): void
    {
        putenv('WEBHOOK_MASTER_KEY=' . base64_encode('too-short'));
        $this->expectException(\WebhookMasterKeyException::class);
        \webhook_master_key();
    }

    public function testTwoEncryptionsOfTheSameSecretProduceDifferentCiphertextAndNonce(): void
    {
        // A fresh random nonce every call (STEP 30) -- two encryptions of the identical plaintext
        // must never produce identical ciphertext, or an observer could correlate rotated secrets.
        [$c1, $n1] = \webhook_encrypt_secret('same-secret');
        [$c2, $n2] = \webhook_encrypt_secret('same-secret');
        $this->assertNotSame($n1, $n2);
        $this->assertNotSame($c1, $c2);
    }
}
