<?php

declare(strict_types=1);

const TOTP_MFA_PURPOSE = 'ellsms.auth.totp.v1';
const TOTP_MFA_ISSUER = 'ELLSMS';

function totp_base32_encode(string $data): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($data) as $ch) $bits .= str_pad(decbin(ord($ch)), 8, '0', STR_PAD_LEFT);
    $out = '';
    for ($i = 0, $n = strlen($bits); $i < $n; $i += 5) {
        $chunk = substr($bits, $i, 5);
        if (strlen($chunk) < 5) $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
        $out .= $alphabet[bindec($chunk)];
    }
    return $out;
}

function totp_base32_decode(string $value): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $value = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $value) ?? '');
    $bits = '';
    foreach (str_split($value) as $ch) {
        $p = strpos($alphabet, $ch);
        if ($p === false) continue;
        $bits .= str_pad(decbin($p), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    for ($i = 0, $n = strlen($bits) - 7; $i < $n; $i += 8) $out .= chr(bindec(substr($bits, $i, 8)));
    return $out;
}

function totp_generate_secret(): string {
    return totp_base32_encode(random_bytes(20));
}

function totp_mfa_key(): ?string {
    $master = (string)env('MFA_MASTER_KEY', '');
    if ($master === '') $master = (string)env('SMS_GATEWAY_MASTER_KEY', '');
    if ($master === '') return null;
    if (strlen($master) < 32) throw new RuntimeException('MFA_MASTER_KEY یا SMS_GATEWAY_MASTER_KEY باید حداقل ۳۲ نویسه باشد.');
    return hash_hkdf('sha256', $master, 32, TOTP_MFA_PURPOSE);
}

function totp_mfa_key_fingerprint(): string {
    $key = totp_mfa_key();
    return $key === null ? '' : substr(hash('sha256', $key . '|fingerprint'), 0, 16);
}

function totp_code_for_step(string $secretBase32, int $step): string {
    $secret = totp_base32_decode($secretBase32);
    $high = intdiv($step, 0x100000000);
    $low = $step % 0x100000000;
    $counter = pack('N2', $high, $low);
    $hash = hash_hmac('sha1', $counter, $secret, true);
    $offset = ord($hash[19]) & 0x0f;
    $binary = ((ord($hash[$offset]) & 0x7f) << 24)
        | ((ord($hash[$offset + 1]) & 0xff) << 16)
        | ((ord($hash[$offset + 2]) & 0xff) << 8)
        | (ord($hash[$offset + 3]) & 0xff);
    return str_pad((string)($binary % 1000000), 6, '0', STR_PAD_LEFT);
}

function totp_verify_secret(string $secretBase32, string $code, int $window = 1, ?int &$matchedStep = null): bool {
    $code = preg_replace('/\D+/', '', from_persian_digits($code)) ?? '';
    if (strlen($code) !== 6) return false;
    $nowStep = intdiv(time(), 30);
    for ($delta = -$window; $delta <= $window; $delta++) {
        $step = $nowStep + $delta;
        if ($step < 0) continue;
        if (hash_equals(totp_code_for_step($secretBase32, $step), $code)) {
            $matchedStep = $step;
            return true;
        }
    }
    return false;
}

function totp_enabled(int $userId): bool {
    try {
        $st = db()->prepare('SELECT 1 FROM ellsms_totp_mfa WHERE user_id=? LIMIT 1');
        $st->execute([$userId]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        Logger::error('auth.totp.status_failed', ['user_id' => $userId, 'exception' => $e]);
        return false;
    }
}

function totp_secret_load(int $userId): ?array {
    $key = totp_mfa_key();
    if ($key === null) return null;
    $st = db()->prepare('SELECT secret_ciphertext,secret_nonce,secret_tag,key_fingerprint,last_used_step FROM ellsms_totp_mfa WHERE user_id=? LIMIT 1');
    $st->execute([$userId]);
    $row = $st->fetch();
    if (!$row) return null;
    if ((string)$row['key_fingerprint'] !== '' && !hash_equals((string)$row['key_fingerprint'], totp_mfa_key_fingerprint())) {
        Logger::error('auth.totp.key_mismatch', ['user_id' => $userId]);
        return null;
    }
    $secret = openssl_decrypt($row['secret_ciphertext'], 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $row['secret_nonce'], $row['secret_tag']);
    if ($secret === false) {
        Logger::error('auth.totp.decrypt_failed', ['user_id' => $userId]);
        return null;
    }
    return ['secret' => $secret, 'last_used_step' => $row['last_used_step'] === null ? null : (int)$row['last_used_step']];
}

function totp_enable_for_user(int $userId, string $secretBase32): void {
    $key = totp_mfa_key();
    if ($key === null) throw new RuntimeException('کلید رمزگذاری MFA روی سرور تنظیم نشده است.');
    $nonce = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($secretBase32, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($cipher === false) throw new RuntimeException('رمزگذاری کلید MFA ممکن نشد.');
    db()->prepare(
        'INSERT INTO ellsms_totp_mfa (user_id,secret_ciphertext,secret_nonce,secret_tag,key_fingerprint,enabled_at,last_used_step)
         VALUES (?,?,?,?,?,UTC_TIMESTAMP(),NULL)
         ON DUPLICATE KEY UPDATE secret_ciphertext=VALUES(secret_ciphertext),secret_nonce=VALUES(secret_nonce),secret_tag=VALUES(secret_tag),key_fingerprint=VALUES(key_fingerprint),enabled_at=UTC_TIMESTAMP(),last_used_step=NULL'
    )->execute([$userId, $cipher, $nonce, $tag, totp_mfa_key_fingerprint()]);
}

function totp_disable_for_user(int $userId): void {
    db()->prepare('DELETE FROM ellsms_totp_mfa WHERE user_id=?')->execute([$userId]);
}

function totp_verify_user(int $userId, string $code): bool {
    $row = totp_secret_load($userId);
    if ($row === null) return false;
    $matched = null;
    if (!totp_verify_secret((string)$row['secret'], $code, 1, $matched) || $matched === null) return false;
    $last = $row['last_used_step'];
    if ($last !== null && $matched <= $last) {
        Logger::warning('auth.totp.replay_blocked', ['user_id' => $userId]);
        return false;
    }
    $st = db()->prepare('UPDATE ellsms_totp_mfa SET last_used_step=? WHERE user_id=? AND (last_used_step IS NULL OR last_used_step<?)');
    $st->execute([$matched, $userId, $matched]);
    return $st->rowCount() === 1;
}

function totp_provisioning_uri(string $username, string $secretBase32): string {
    $label = rawurlencode(TOTP_MFA_ISSUER . ':' . $username);
    return 'otpauth://totp/' . $label . '?secret=' . rawurlencode($secretBase32)
        . '&issuer=' . rawurlencode(TOTP_MFA_ISSUER) . '&algorithm=SHA1&digits=6&period=30';
}
