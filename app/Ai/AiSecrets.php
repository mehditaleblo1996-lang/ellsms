<?php
/**
 * ELLSMS — encrypted storage for AI provider credentials (docs/ai-engines.md §Secrets).
 *
 * Same rules as the SMS gateway vault (app/Sms/GatewaySecrets.php), and deliberately a SEPARATE
 * vault: an AI key spends money on a foreign account, an SMS token sends messages, and a leak or a
 * rotation in one must never reach the other. Values are AES-256-GCM encrypted, never rendered back
 * to a form, never logged, never put in a cache file.
 *
 * KEY. AI_MASTER_KEY when set, otherwise SMS_GATEWAY_MASTER_KEY — so an install that already has
 * the gateway key works without new configuration. Either way the encryption key is derived with
 * HKDF and an AI-only purpose label, so even from the same master key it is a different key from
 * the gateway vault's. The master key is never stored in the database.
 */

declare(strict_types=1);

/** Raised for a secret-store configuration problem — safe to show to a platform admin. */
class AiSecretException extends AppException {}

const AI_SECRET_KEY_PURPOSE = 'ellsms.ai_provider.secret.v1';

/** The derived key, or null when neither master key is configured. */
function ai_secret_key(): ?string {
    if (($GLOBALS['__ai_secret_key_resolved'] ?? false) === true) {
        return $GLOBALS['__ai_secret_key'] ?? null;
    }
    $GLOBALS['__ai_secret_key_resolved'] = true;
    $GLOBALS['__ai_secret_key'] = ai_secret_derive_key(
        (string)env('AI_MASTER_KEY', '') !== '' ? (string)env('AI_MASTER_KEY', '') : (string)env('SMS_GATEWAY_MASTER_KEY', '')
    );
    return $GLOBALS['__ai_secret_key'];
}

/** Pure: master key -> derived key (null for an empty master key; a short one is refused). */
function ai_secret_derive_key(string $master): ?string {
    if ($master === '') {
        return null;
    }
    if (strlen($master) < 32) {
        throw new AiSecretException('کلید اصلی (AI_MASTER_KEY یا SMS_GATEWAY_MASTER_KEY) باید حداقل ۳۲ نویسه باشد.');
    }
    return hash_hkdf('sha256', $master, 32, AI_SECRET_KEY_PURPOSE);
}

function ai_secret_key_reset(): void {
    $GLOBALS['__ai_secret_key_resolved'] = false;
    $GLOBALS['__ai_secret_key'] = null;
}

function ai_secrets_configured(): bool {
    return ai_secret_key() !== null;
}

/** A short non-reversible fingerprint of a derived key, stored beside each ciphertext for diagnosis. */
function ai_secret_fingerprint(string $key): string {
    return substr(hash('sha256', $key . '|fingerprint'), 0, 16);
}

/**
 * Pure: encrypts $plaintext with $key.
 * @return array{ciphertext:string, nonce:string, tag:string}
 */
function ai_secret_encrypt(string $key, string $plaintext): array {
    $nonce = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($ciphertext === false) {
        throw new AiSecretException('رمزگذاری کلید محرمانه ممکن نشد.');
    }
    return ['ciphertext' => $ciphertext, 'nonce' => $nonce, 'tag' => $tag];
}

/** Pure: the plaintext, or null when the ciphertext was tampered with or the key is wrong. */
function ai_secret_decrypt(string $key, string $ciphertext, string $nonce, string $tag): ?string {
    $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
    return $plaintext === false ? null : $plaintext;
}

/** Encrypts and stores one secret, replacing any previous value for the same (provider, key). */
function ai_secret_put(int $providerId, string $secretKey, string $plaintext): void {
    $key = ai_secret_key();
    if ($key === null) {
        throw new AiSecretException('برای ذخیره‌ی کلید API، متغیر محیطی AI_MASTER_KEY یا SMS_GATEWAY_MASTER_KEY باید تنظیم شده باشد.');
    }
    if (preg_match('/^[a-z0-9_]{2,60}$/', $secretKey) !== 1) {
        throw new AiSecretException('نام کلید محرمانه نامعتبر است.');
    }
    if (strlen($plaintext) > 3000) {
        throw new AiSecretException('کلید API بیش از حد طولانی است.');
    }
    $sealed = ai_secret_encrypt($key, $plaintext);
    db()->prepare(
        'INSERT INTO ellsms_ai_provider_secrets (provider_id, secret_key, ciphertext, nonce, tag, key_fingerprint)
         VALUES (?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE ciphertext = VALUES(ciphertext), nonce = VALUES(nonce),
                                 tag = VALUES(tag), key_fingerprint = VALUES(key_fingerprint)'
    )->execute([$providerId, $secretKey, $sealed['ciphertext'], $sealed['nonce'], $sealed['tag'], ai_secret_fingerprint($key)]);
    Logger::info('ai.secret.changed', ['provider_id' => $providerId, 'secret_key' => $secretKey, 'changed' => true]);
}

/** One decrypted secret, or null when it is not set or cannot be decrypted (logged, never thrown). */
function ai_secret_get(int $providerId, string $secretKey): ?string {
    $key = ai_secret_key();
    if ($key === null) {
        return null;
    }
    $st = db()->prepare('SELECT ciphertext, nonce, tag, key_fingerprint FROM ellsms_ai_provider_secrets WHERE provider_id = ? AND secret_key = ?');
    $st->execute([$providerId, $secretKey]);
    $row = $st->fetch();
    if (!$row) {
        return null;
    }
    if ($row['key_fingerprint'] !== '' && $row['key_fingerprint'] !== ai_secret_fingerprint($key)) {
        Logger::error('ai.secret.key_mismatch', ['provider_id' => $providerId, 'secret_key' => $secretKey]);
        return null;
    }
    $plaintext = ai_secret_decrypt($key, (string)$row['ciphertext'], (string)$row['nonce'], (string)$row['tag']);
    if ($plaintext === null) {
        Logger::error('ai.secret.decrypt_failed', ['provider_id' => $providerId, 'secret_key' => $secretKey]);
    }
    return $plaintext;
}

/** Whether a secret is stored (for the "set / not set" badge). Never returns a value. */
function ai_secret_is_set(int $providerId, string $secretKey): bool {
    $st = db()->prepare('SELECT 1 FROM ellsms_ai_provider_secrets WHERE provider_id = ? AND secret_key = ?');
    $st->execute([$providerId, $secretKey]);
    return (bool)$st->fetchColumn();
}

function ai_secret_delete(int $providerId, string $secretKey): void {
    db()->prepare('DELETE FROM ellsms_ai_provider_secrets WHERE provider_id = ? AND secret_key = ?')
        ->execute([$providerId, $secretKey]);
    Logger::info('ai.secret.deleted', ['provider_id' => $providerId, 'secret_key' => $secretKey]);
}
