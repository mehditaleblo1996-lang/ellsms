<?php
/**
 * ELLSMS — self-hosted proof-of-work CAPTCHA (ALTCHA protocol v3, PBKDF2/SHA-256).
 *
 * Shown only after repeated failures (login, 2FA code, or a tripped registration limit). It never
 * calls a third-party service, so it keeps working when international connectivity is cut, and
 * needs no API keys. The widget (public/assets/vendor/altcha-3.2.4, MIT) makes the browser find
 * the counter that reproduces a key prefix we derived from a secret random counter: about a second
 * for a person, but a real CPU cost on every single attempt for a bot.
 *
 * A challenge is stateless (HMAC-signed parameters with an expiry); a solved one is single-use,
 * enforced by recording its nonce in ellsms_rate_limits for the challenge's lifetime.
 */

declare(strict_types=1);

const CAPTCHA_ALGORITHM = 'PBKDF2/SHA-256';
const CAPTCHA_KEY_LENGTH = 32;
const CAPTCHA_FIELD = 'altcha';
const CAPTCHA_ASSET_DIR = '/assets/vendor/altcha-3.2.4';

/**
 * HMAC key for signing challenges: CAPTCHA_HMAC_SECRET when set, otherwise derived from the MFA /
 * gateway master key, otherwise a random secret generated once and kept in ellsms_settings, so the
 * CAPTCHA works on an install with no extra configuration.
 */
function captcha_secret(): string {
    $explicit = (string)env('CAPTCHA_HMAC_SECRET', '');
    if ($explicit !== '') return $explicit;

    $master = (string)env('MFA_MASTER_KEY', '');
    if ($master === '') $master = (string)env('SMS_GATEWAY_MASTER_KEY', '');
    if ($master !== '') return hash_hkdf('sha256', $master, 32, 'ellsms-captcha-hmac');

    static $stored = null;
    if ($stored === null) {
        db()->prepare('INSERT IGNORE INTO ellsms_settings (skey, svalue) VALUES (?, ?)')
            ->execute(['captcha_hmac_secret', bin2hex(random_bytes(32))]);
        $stored = (string)setting_fresh('captcha_hmac_secret', '');
        if ($stored === '') throw new RuntimeException('captcha_hmac_secret could not be stored');
    }
    return $stored;
}

function captcha_challenge_ttl(): int {
    return rate_limit_config('CAPTCHA_CHALLENGE_TTL_SECONDS', 600);
}

/** Signed fields only, in the protocol's canonical (key-sorted) order; null if anything is off. */
function captcha_canonical_parameters(mixed $params): ?array {
    if (!is_array($params)) return null;
    $hex = static fn($v, int $len): bool => is_string($v) && preg_match('/\A[0-9a-f]{' . $len . '}\z/', $v) === 1;

    if (($params['algorithm'] ?? null) !== CAPTCHA_ALGORITHM) return null;
    if (!is_int($params['cost'] ?? null) || $params['cost'] < 1 || $params['cost'] > 1_000_000) return null;
    if (!is_int($params['expiresAt'] ?? null)) return null;
    if (($params['keyLength'] ?? null) !== CAPTCHA_KEY_LENGTH) return null;
    if (!$hex($params['keyPrefix'] ?? null, CAPTCHA_KEY_LENGTH)) return null;
    if (!$hex($params['nonce'] ?? null, 32) || !$hex($params['salt'] ?? null, 32)) return null;

    return [
        'algorithm' => $params['algorithm'],
        'cost' => $params['cost'],
        'expiresAt' => $params['expiresAt'],
        'keyLength' => $params['keyLength'],
        'keyPrefix' => $params['keyPrefix'],
        'nonce' => $params['nonce'],
        'salt' => $params['salt'],
    ];
}

function captcha_sign(array $canonical): string {
    return hash_hmac('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), captcha_secret());
}

function captcha_derive_key(string $nonceHex, string $saltHex, int $counter, int $cost): string {
    return hash_pbkdf2('sha256', hex2bin($nonceHex) . pack('N', $counter), hex2bin($saltHex), $cost, CAPTCHA_KEY_LENGTH, true);
}

/**
 * A new challenge for the widget. The key prefix is half the derived key, so exactly one counter
 * solves it and the work is the (secret, random) counter times the PBKDF2 cost — a predictable
 * effort rather than a lucky-or-not prefix search.
 */
function captcha_create_challenge(?int $now = null): array {
    $cost = rate_limit_config('CAPTCHA_POW_COST', 5000);
    $maxCounter = rate_limit_config('CAPTCHA_POW_MAX_COUNTER', 400);
    $nonce = bin2hex(random_bytes(16));
    $salt = bin2hex(random_bytes(16));
    $counter = random_int(intdiv($maxCounter, 2), $maxCounter);
    $key = captcha_derive_key($nonce, $salt, $counter, $cost);

    $parameters = captcha_canonical_parameters([
        'algorithm' => CAPTCHA_ALGORITHM,
        'cost' => $cost,
        'expiresAt' => ($now ?? time()) + captcha_challenge_ttl(),
        'keyLength' => CAPTCHA_KEY_LENGTH,
        'keyPrefix' => bin2hex(substr($key, 0, CAPTCHA_KEY_LENGTH / 2)),
        'nonce' => $nonce,
        'salt' => $salt,
    ]);
    return ['parameters' => $parameters, 'signature' => captcha_sign($parameters)];
}

/**
 * Check the widget's base64 payload. $consumeNonce records a solved challenge and returns false if
 * it was already used (defaults to a one-hit rate-limit bucket); tests pass their own.
 */
function captcha_verify_payload(string $payload, ?callable $consumeNonce = null, ?int $now = null): bool {
    if ($payload === '' || strlen($payload) > 4096) return false;
    $json = base64_decode($payload, true);
    $data = $json === false ? null : json_decode($json, true, 8);
    if (!is_array($data)) return false;

    $params = captcha_canonical_parameters($data['challenge']['parameters'] ?? null);
    $signature = $data['challenge']['signature'] ?? null;
    $counter = $data['solution']['counter'] ?? null;
    if ($params === null || !is_string($signature) || !is_int($counter) || $counter < 0 || $counter > 0xFFFFFFFF) {
        return false;
    }
    if (!hash_equals(captcha_sign($params), $signature)) return false;
    if (($now ?? time()) > $params['expiresAt']) return false;

    $key = captcha_derive_key($params['nonce'], $params['salt'], $counter, $params['cost']);
    if (!hash_equals($params['keyPrefix'], bin2hex(substr($key, 0, CAPTCHA_KEY_LENGTH / 2)))) return false;

    $consumeNonce ??= static fn(string $nonce): bool =>
        rate_limit_hit(rate_limit_bucket('captcha_used', 'nonce', $nonce), 1, captcha_challenge_ttl() + 60);
    return $consumeNonce($params['nonce']);
}

/** Verify whatever the form posted for the widget. */
function captcha_verify_request(): bool {
    $ok = captcha_verify_payload((string)($_POST[CAPTCHA_FIELD] ?? ''));
    if (!$ok) Logger::warning('captcha.failed', ['ip' => client_ip()]);
    return $ok;
}

/* ---------- "Show the CAPTCHA after N failures" ---------- */

function captcha_failure_threshold(): int {
    return rate_limit_config('CAPTCHA_AFTER_FAILURES', 3);
}

function captcha_failure_window(): int {
    return rate_limit_config('CAPTCHA_FAILURE_WINDOW_SECONDS', 900);
}

/** True once any of $buckets has reached the failure threshold within the window. */
function captcha_required_for(array $buckets): bool {
    foreach ($buckets as $bucket) {
        if (rate_limit_count($bucket, captcha_failure_window()) >= captcha_failure_threshold()) return true;
    }
    return false;
}

function captcha_record_failure(array $buckets): void {
    foreach ($buckets as $bucket) {
        rate_limit_record($bucket, captcha_failure_window());
    }
}

/* ---------- Widget markup ---------- */

/** Stylesheet link for the page <head>. */
function captcha_head(): string {
    return '<link rel="stylesheet" href="' . e(asset_url(CAPTCHA_ASSET_DIR . '/altcha.css')) . '">';
}

/**
 * The widget itself, placed inside the form; it posts its proof in the "altcha" field. Its
 * checkbox is a required field, so the form cannot be sent unverified; solving starts as soon as
 * the person focuses the form (auto="onfocus"), so it is usually done before they press submit.
 */
function captcha_widget(): string {
    $scripts = '';
    foreach ([CAPTCHA_ASSET_DIR . '/altcha.min.js', CAPTCHA_ASSET_DIR . '/i18n-fa.js', '/assets/js/captcha.js'] as $src) {
        $scripts .= '<script type="module" src="' . e(asset_url($src)) . '"></script>';
    }
    return '<div class="captcha-box">'
        . '<altcha-widget challenge="/captcha/challenge" name="' . CAPTCHA_FIELD . '" language="fa" auto="onfocus" '
        . 'configuration=\'{"hideFooter":true,"hideLogo":true}\'></altcha-widget>'
        . '<noscript><p class="captcha-noscript">برای ادامه، جاوااسکریپت مرورگر را فعال کنید.</p></noscript>'
        . '</div>' . $scripts;
}
