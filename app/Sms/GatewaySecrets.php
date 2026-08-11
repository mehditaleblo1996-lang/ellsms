<?php
/**
 * ELLSMS — encrypted storage for SMS gateway credentials
 * (docs/sms-gateway-connectors.md §Secrets).
 *
 * A gateway's API token is a credential that can send messages at the customer's expense, so it is
 * never stored in plaintext, never rendered back to an admin form after it has been saved, and never
 * written to a log, a metric label, or a cache file. The plaintext exists in exactly one place: worker
 * memory, for as long as a compiled connector is held.
 *
 * A SEPARATE VAULT, deliberately. This project already has three secret stores — public-API key
 * hashes, webhook signing secrets, and the backend HMAC secret — and none of them is reused here.
 * They have different lifecycles, different rotation stories, and different blast radii; sharing one
 * key would mean rotating a webhook secret forced a gateway outage, and a leak in one domain would
 * compromise all four.
 *
 * ENCRYPTION. AES-256-GCM (authenticated, so tampering with a stored ciphertext is detected rather
 * than silently decrypting to garbage), with a key derived from SMS_GATEWAY_MASTER_KEY via HKDF and a
 * purpose label. The master key is NEVER stored in the database and therefore never appears in a
 * backup — restoring a database onto a host without the same key yields secrets that cannot be
 * decrypted, which is the intended behaviour and is documented in
 * docs/backup-and-disaster-recovery.md.
 */

declare(strict_types=1);

/** Raised for a configuration problem with the secret store — safe to show to a platform admin. */
class GatewaySecretException extends AppException {}

/** The purpose label bound into the derived key, so this key can never decrypt another domain's data. */
const GATEWAY_SECRET_KEY_PURPOSE = 'ellsms.sms_gateway.secret.v1';

/**
 * The derived encryption key, or null when no master key is configured.
 *
 * Returning null rather than throwing lets the rest of the system behave sensibly on an install that
 * has not configured one: gateways using env-backed secret references keep working, and only a
 * gateway that actually needs a DB secret fails — with a clear message, at the point it is needed.
 */
function gateway_secret_key(): ?string {
    // Memoized in $GLOBALS rather than a function static so it can be dropped deliberately
    // (gateway_secret_key_reset()). A function-static memo would make key rotation invisible until
    // the process restarted — true in production, but it would also make the rotation path
    // impossible to test, and an untestable security path is one nobody can verify.
    if (($GLOBALS['__gateway_secret_key_resolved'] ?? false) === true) {
        return $GLOBALS['__gateway_secret_key'] ?? null;
    }
    $GLOBALS['__gateway_secret_key_resolved'] = true;

    $master = (string)env('SMS_GATEWAY_MASTER_KEY', '');
    if ($master === '') {
        $GLOBALS['__gateway_secret_key'] = null;
        return null;
    }
    // A short key is worse than no key: it looks configured while providing little. Refuse it loudly
    // rather than deriving a weak key from it.
    if (strlen($master) < 32) {
        throw new GatewaySecretException('SMS_GATEWAY_MASTER_KEY باید حداقل ۳۲ نویسه باشد.');
    }
    $GLOBALS['__gateway_secret_key'] = hash_hkdf('sha256', $master, 32, GATEWAY_SECRET_KEY_PURPOSE);
    return $GLOBALS['__gateway_secret_key'];
}

/**
 * Drops the derived-key memo so the next call re-reads SMS_GATEWAY_MASTER_KEY.
 *
 * Called by gateway_cache_reset(), because a compiled connector holds decrypted secrets: keeping the
 * old key after dropping the connectors that were built with it would be an inconsistent half-reset.
 */
function gateway_secret_key_reset(): void {
    $GLOBALS['__gateway_secret_key_resolved'] = false;
    $GLOBALS['__gateway_secret_key'] = null;
}

/**
 * A short, non-reversible fingerprint of the active master key, stored beside each ciphertext.
 *
 * Its only job is diagnosis: after a restore onto a host with the wrong key, "this row was encrypted
 * with a different key" is a far more useful message than "decryption failed", and the fingerprint
 * reveals nothing about the key itself.
 */
function gateway_secret_key_fingerprint(): string {
    $key = gateway_secret_key();
    return $key === null ? '' : substr(hash('sha256', $key . '|fingerprint'), 0, 16);
}

function gateway_secrets_configured(): bool {
    return gateway_secret_key() !== null;
}

/** Encrypts and stores one secret, replacing any previous value for the same (gateway, key). */
function gateway_secret_put(int $gatewayId, string $secretKey, string $plaintext): void {
    $key = gateway_secret_key();
    if ($key === null) {
        throw new GatewaySecretException('برای ذخیره‌ی کلید محرمانه، SMS_GATEWAY_MASTER_KEY باید تنظیم شده باشد.');
    }
    if (preg_match('/^[a-z0-9_]{2,60}$/', $secretKey) !== 1) {
        throw new GatewaySecretException('نام کلید محرمانه باید ۲ تا ۶۰ نویسه‌ی لاتین کوچک، عدد یا زیرخط باشد.');
    }

    $nonce = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($ciphertext === false) {
        throw new GatewaySecretException('رمزگذاری کلید محرمانه ممکن نشد.');
    }

    db()->prepare(
        'INSERT INTO ellsms_sms_gateway_secrets (gateway_id, secret_key, ciphertext, nonce, tag, key_fingerprint)
         VALUES (?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE ciphertext = VALUES(ciphertext), nonce = VALUES(nonce),
                                 tag = VALUES(tag), key_fingerprint = VALUES(key_fingerprint)'
    )->execute([$gatewayId, $secretKey, $ciphertext, $nonce, $tag, gateway_secret_key_fingerprint()]);

    // Deliberately records only that the value changed. Length is not logged either — it narrows the
    // search space for a token of known format.
    Logger::info('gateway.secret.changed', ['gateway_id' => $gatewayId, 'secret_key' => $secretKey, 'changed' => true]);
}

/**
 * Loads and decrypts every secret for one gateway, as [key => plaintext].
 *
 * Called ONCE per connector compilation — never per message (Invariant H). A row encrypted under a
 * different master key is skipped rather than failing the whole gateway, so one stale secret cannot
 * take a gateway offline silently; the integrity check reports it.
 */
function gateway_secrets_load(int $gatewayId): array {
    $key = gateway_secret_key();
    if ($key === null) {
        return [];
    }
    $st = db()->prepare('SELECT secret_key, ciphertext, nonce, tag, key_fingerprint FROM ellsms_sms_gateway_secrets WHERE gateway_id = ?');
    $st->execute([$gatewayId]);

    $fingerprint = gateway_secret_key_fingerprint();
    $secrets = [];
    foreach ($st->fetchAll() as $row) {
        if ($row['key_fingerprint'] !== '' && $row['key_fingerprint'] !== $fingerprint) {
            Logger::error('gateway.secret.key_mismatch', [
                'gateway_id' => $gatewayId, 'secret_key' => $row['secret_key'],
                'hint' => 'encrypted with a different SMS_GATEWAY_MASTER_KEY — see docs/backup-and-disaster-recovery.md',
            ]);
            continue;
        }
        $plaintext = openssl_decrypt($row['ciphertext'], 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $row['nonce'], $row['tag']);
        if ($plaintext === false) {
            Logger::error('gateway.secret.decrypt_failed', ['gateway_id' => $gatewayId, 'secret_key' => $row['secret_key']]);
            continue;
        }
        $secrets[(string)$row['secret_key']] = $plaintext;
    }
    return $secrets;
}

/** The secret KEY names a gateway has, for admin display. Never returns a value. */
function gateway_secret_keys(int $gatewayId): array {
    $st = db()->prepare('SELECT secret_key, updated_at FROM ellsms_sms_gateway_secrets WHERE gateway_id = ? ORDER BY secret_key');
    $st->execute([$gatewayId]);
    return $st->fetchAll();
}

function gateway_secret_delete(int $gatewayId, string $secretKey): void {
    db()->prepare('DELETE FROM ellsms_sms_gateway_secrets WHERE gateway_id = ? AND secret_key = ?')
        ->execute([$gatewayId, $secretKey]);
    Logger::info('gateway.secret.deleted', ['gateway_id' => $gatewayId, 'secret_key' => $secretKey]);
}

/**
 * Environment-backed secret references, for migrating the existing gateway without moving live
 * credentials into the database as a side effect of a schema change (STEP 46).
 *
 * ALLOWLISTED. An admin cannot name an arbitrary environment variable and read it back through a
 * request preview — that would turn the connector builder into an environment dump. Only variables
 * this application already uses as outbound credentials are readable.
 */
const GATEWAY_ENV_SECRET_ALLOWLIST = [
    'BACKEND_SERVICE_ID',
    'BACKEND_SERVICE_SECRET',
];

function gateway_env_secret(string $name): ?string {
    if (!in_array($name, GATEWAY_ENV_SECRET_ALLOWLIST, true)) {
        return null;
    }
    $value = (string)env($name, '');
    return $value === '' ? null : $value;
}

/** Replaces a secret with a fixed-width mask for previews and logs. Never reveals length. */
function gateway_mask_secret(string $value): string {
    return $value === '' ? '' : '••••••••';
}
