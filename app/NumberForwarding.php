<?php
/**
 * ELLSMS — forwarding inbound SMS (MO) and delivery reports (DLR) of a line to the customer's URL.
 *
 * The feature the Vesal platform has on every number (receiveUrl / deliveryUrl, its MOForwardJob and
 * DeliveryForwardJob): the owner of a line, or a platform admin, sets an http(s) URL template per
 * line, and ELLSMS calls it
 *  - for every message RECEIVED on that line (MO), from run_autoreply_pass() — both inbound stores;
 *  - for the FINAL delivery state of every message SENT from that line (DLR), from
 *    gateway_status_record() at the one UPDATE that moves the row into a terminal state.
 *
 * Templates use Vesal's ${name} placeholders, so a URL already set on a Vesal number works unchanged:
 *   MO : ${originator} ${destination} ${content} ${udh} ${sourcePort} ${destinationPort} ${id} ${received_at}
 *   DLR: ${id} ${part} ${status} ${dl_time} ${state} ${message_id} ${reference_id} ${originator}
 *        ${destination} ${delivered_at} ${provider_message_id}
 * Values are URL-encoded where they land in the URL. With POST the same values also go in the body.
 *
 * Unlike Vesal (one GET, no retry, ack either way) every forward goes through an outbox
 * (ellsms_number_forward_queue) and its own worker (cron/number-forward-worker.php): exactly-once
 * enqueue, retries with backoff for timeouts / 5xx / 429, a log the customer can read, and an
 * SSRF-safe connection (the address checked is the address connected to). A slow customer URL can
 * only ever delay other forwards — never a send, an auto-reply or a status poll.
 *
 * Schema: db/migrations/2026_10_18_number_forwarding.sql. Admin and customer page:
 * public/number-forwards.php. Docs: docs/number-forwarding.md.
 */

declare(strict_types=1);

const NUMBER_FORWARD_KINDS = ['mo', 'dlr'];

/** Placeholders each kind fills. Order is the order the page lists them in. */
const NUMBER_FORWARD_VARS = [
    'mo'  => ['originator', 'destination', 'content', 'udh', 'sourcePort', 'destinationPort', 'id', 'received_at'],
    'dlr' => ['id', 'part', 'status', 'dl_time', 'state', 'message_id', 'reference_id', 'originator', 'destination', 'delivered_at', 'provider_message_id'],
];

/** Vesal's DestinationStatus ordinals — the ${status} a Vesal customer's code already understands. */
const NUMBER_FORWARD_VESAL_STATUS = [
    'accepted' => 0, 'queued' => 0, 'sent' => 1, 'delivered' => 2, 'failed' => 3,
    'unknown' => 4, 'expired' => 7, 'rejected' => 8,
];

const NUMBER_FORWARD_MAX_URL_LENGTH = 2048;
const NUMBER_FORWARD_MAX_RESPONSE_BYTES = 4096;
/** curl error numbers that mean the TLS handshake or the certificate check failed (shown as tls_error). */
const NUMBER_FORWARD_TLS_ERRNOS = [35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91];
/** Consecutive failures after which the page flags the line in red (forwarding is NOT switched off). */
const NUMBER_FORWARD_WARN_FAILURES = 20;

/* ==========================================================================
   Settings (ellsms_settings, edited on public/number-forwards.php by a platform admin)
   ========================================================================== */

/** key => [default, min, max] for the numeric settings; the rest are '0'/'1' switches. */
const NUMBER_FORWARD_SETTINGS = [
    'number_forward_enabled'         => ['1', 0, 1],   // master switch: enqueue + worker
    'number_forward_user_edit'       => ['1', 0, 1],   // customers may set their own lines' URLs
    'number_forward_allow_http'      => ['1', 0, 1],   // plain http:// allowed (Vesal allowed it)
    'number_forward_timeout_seconds' => ['10', 1, 60],
    'number_forward_max_attempts'    => ['5', 1, 20],     // default for a line without its own value
    'number_forward_retry_base_seconds' => ['30', 5, 3600],  // wait before the 2nd attempt; doubles each time
    'number_forward_retry_max_seconds'  => ['1800', 10, 86400], // the longest wait between attempts
    'number_forward_workers'         => ['4', 1, 32],     // parallel worker processes (the supervisor forks them)
    'number_forward_concurrency'     => ['20', 1, 100],   // simultaneous HTTP calls per worker process
    'number_forward_retention_days'  => ['30', 1, 365],
];

/** A setting, re-read at most every few seconds: enqueue is on hot paths, admin changes are rare. */
function number_forward_setting(string $key): string {
    $default = NUMBER_FORWARD_SETTINGS[$key][0] ?? '';
    $state = &$GLOBALS['__number_forward_settings'];
    if (!is_array($state) || microtime(true) - ($state['at'] ?? 0) > 5) {
        $state = ['at' => microtime(true), 'values' => []];
    }
    if (!array_key_exists($key, $state['values'])) {
        try {
            $state['values'][$key] = (string)setting_fresh($key, $default);
        } catch (Throwable) {
            $state['values'][$key] = $default;
        }
    }
    return $state['values'][$key];
}

function number_forward_setting_int(string $key): int {
    [$default, $min, $max] = NUMBER_FORWARD_SETTINGS[$key];
    $value = number_forward_setting($key);
    $int = ctype_digit($value) ? (int)$value : (int)$default;
    return max($min, min($max, $int));
}

/**
 * Wait before the next try after $attemptCount failed attempts: base, 2×base, 4×base … capped at the
 * maximum (both admin settings). With the defaults: 30 s, 1 m, 2 m, 4 m, 8 m … 30 m.
 */
function number_forward_retry_delay_seconds(int $attemptCount): int {
    $base = number_forward_setting_int('number_forward_retry_base_seconds');
    $max = max($base, number_forward_setting_int('number_forward_retry_max_seconds'));
    return (int)min($max, $base * (2 ** min(max(0, $attemptCount - 1), 20)));
}

/** A line's own attempt count when it has one (1..20), else the service-wide setting. */
function number_forward_max_attempts_for(?array $config): int {
    $own = (int)($config['max_attempts'] ?? 0);
    return $own >= 1 ? min(20, $own) : number_forward_setting_int('number_forward_max_attempts');
}

function number_forward_enabled(): bool {
    return number_forward_setting('number_forward_enabled') === '1';
}

function number_forward_users_can_edit(): bool {
    return number_forward_setting('number_forward_user_edit') === '1';
}

function number_forward_http_allowed(): bool {
    return number_forward_setting('number_forward_allow_http') === '1';
}

/** Saves the admin's settings form. Unknown keys are ignored, numbers are clamped to their range. */
function number_forward_settings_save(array $input): void {
    foreach (NUMBER_FORWARD_SETTINGS as $key => [$default, $min, $max]) {
        if ($min === 0 && $max === 1) {
            set_setting($key, !empty($input[$key]) ? '1' : '0');
            continue;
        }
        $raw = trim((string)($input[$key] ?? $default));
        $value = ctype_digit($raw) ? max($min, min($max, (int)$raw)) : (int)$default;
        set_setting($key, (string)$value);
    }
    number_forward_cache_reset();
}

/** Drops the settings and per-line caches (tests, or right after a save on this process). */
function number_forward_cache_reset(): void {
    $GLOBALS['__number_forward_settings'] = null;
    $GLOBALS['__number_forward_configs'] = null;
}

/** False until 2026_10_18_number_forwarding.sql is applied — the feature then stays silent. */
function number_forward_available(): bool {
    static $available = null;
    if ($available === null) {
        try {
            db()->query('SELECT 1 FROM ellsms_number_forwards LIMIT 0');
            $available = true;
        } catch (PDOException) {
            $available = false;
        }
    }
    return $available;
}

/* ==========================================================================
   Per-line configuration
   ========================================================================== */

/**
 * Every line with at least one switched-on forward, keyed by line. One small query, cached ~30 s per
 * process, so the enqueue on every inbound message / final DLR costs nothing when no line forwards.
 */
function number_forward_active_configs(): array {
    $cache = &$GLOBALS['__number_forward_configs'];
    if (!is_array($cache) || microtime(true) - ($cache['at'] ?? 0) > 30) {
        $rows = [];
        if (number_forward_available()) {
            $st = db()->query("SELECT * FROM ellsms_number_forwards
                               WHERE (mo_enabled = 1 AND mo_url <> '') OR (dlr_enabled = 1 AND dlr_url <> '')");
            foreach ($st->fetchAll() as $row) {
                $rows[(string)$row['number']] = $row;
            }
        }
        $cache = ['at' => microtime(true), 'values' => $rows];
    }
    return $cache['values'];
}

/** The line's switched-on template for $kind, or null when that kind is not forwarded. */
function number_forward_active_url(string $number, string $kind): ?string {
    $config = number_forward_active_configs()[$number] ?? null;
    if ($config === null || !in_array($kind, NUMBER_FORWARD_KINDS, true)) return null;
    if ((int)$config[$kind . '_enabled'] !== 1) return null;
    $url = trim((string)$config[$kind . '_url']);
    return $url !== '' ? $url : null;
}

/** The stored row for one line (switched on or not), or null. */
function number_forward_find(string $number): ?array {
    if (!number_forward_available()) return null;
    $st = db()->prepare('SELECT * FROM ellsms_number_forwards WHERE number = ?');
    $st->execute([$number]);
    return $st->fetch() ?: null;
}

/**
 * Lines $user may set forwarding for, as [number => label].
 *
 * A platform admin: every line in the numbers pool plus every line that already has a row.
 * Anyone else: the lines they OWN — allowed_originators(), i.e. assigned to them, to their
 * organization, or their legacy line. A line merely shared with them is send-only
 * (docs/shared-numbers.md): its inbound messages are not theirs, so neither is its forwarding.
 */
function number_forward_manageable_lines(array $user): array {
    $out = [];
    if (($user['role'] ?? null) === 'admin') {
        foreach (db()->query('SELECT number, label FROM ellsms_numbers ORDER BY number') as $row) {
            $out[(string)$row['number']] = (string)$row['label'];
        }
        if (number_forward_available()) {
            foreach (db()->query('SELECT number FROM ellsms_number_forwards ORDER BY number') as $row) {
                $out[(string)$row['number']] ??= '';
            }
        }
        ksort($out, SORT_STRING);
        return $out;
    }
    $labels = [];
    foreach (array_merge(user_assigned_numbers($user), organization_assigned_numbers((int)($user['organization_id'] ?? 0))) as $row) {
        $labels[(string)$row['number']] = (string)$row['label'];
    }
    foreach (allowed_originators($user) as $line) {
        if ($line === '*') continue;
        $out[(string)$line] = $labels[(string)$line] ?? '';
    }
    ksort($out, SORT_STRING);
    return $out;
}

function number_forward_user_may_edit(array $user, string $number): bool {
    if (($user['role'] ?? null) !== 'admin' && !number_forward_users_can_edit()) return false;
    return array_key_exists($number, number_forward_manageable_lines($user));
}

/**
 * Validates and stores one line's settings. Returns ['ok' => true] or ['ok' => false, 'error' => Persian
 * message]. A URL is validated even when its switch is off, so nothing invalid is ever stored; an
 * empty URL with its switch on is refused.
 *
 * @param array{mo_enabled?:mixed, mo_url?:string, dlr_enabled?:mixed, dlr_url?:string, http_method?:string, post_format?:string} $input
 */
function number_forward_save(string $number, array $input, int $actorUserId): array {
    $line = normalize_originator($number);
    if ($line === null) return ['ok' => false, 'error' => 'شماره‌ی خط معتبر نیست.'];

    $values = [];
    foreach (NUMBER_FORWARD_KINDS as $kind) {
        $url = trim((string)($input[$kind . '_url'] ?? ''));
        $enabled = !empty($input[$kind . '_enabled']);
        if ($url === '' && $enabled) {
            return ['ok' => false, 'error' => ($kind === 'mo' ? 'آدرس دریافت پیامک' : 'آدرس گزارش تحویل') . ' خالی است.'];
        }
        if ($url !== '') {
            $check = number_forward_validate_template($url, $kind);
            if (!$check['ok']) {
                return ['ok' => false, 'error' => ($kind === 'mo' ? 'آدرس دریافت پیامک: ' : 'آدرس گزارش تحویل: ') . number_forward_reason_text($check['reason'])];
            }
        }
        $values[$kind . '_url'] = $url;
        $values[$kind . '_enabled'] = $enabled ? 1 : 0;
    }
    $method = strtoupper((string)($input['http_method'] ?? 'GET')) === 'POST' ? 'POST' : 'GET';
    $format = ($input['post_format'] ?? 'form') === 'json' ? 'json' : 'form';
    $tlsVerify = !empty($input['tls_verify']) ? 1 : 0;
    $rawAttempts = trim((string)($input['max_attempts'] ?? ''));
    $maxAttempts = ctype_digit($rawAttempts) ? max(0, min(20, (int)$rawAttempts)) : 0;

    db()->prepare(
        'INSERT INTO ellsms_number_forwards (number, mo_enabled, mo_url, dlr_enabled, dlr_url, http_method, post_format, tls_verify, max_attempts, updated_by)
         VALUES (?,?,?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE mo_enabled = VALUES(mo_enabled), mo_url = VALUES(mo_url),
           dlr_enabled = VALUES(dlr_enabled), dlr_url = VALUES(dlr_url), http_method = VALUES(http_method),
           post_format = VALUES(post_format), tls_verify = VALUES(tls_verify), max_attempts = VALUES(max_attempts), updated_by = VALUES(updated_by), consecutive_failures = 0'
    )->execute([$line, $values['mo_enabled'], $values['mo_url'], $values['dlr_enabled'], $values['dlr_url'], $method, $format, $tlsVerify, $maxAttempts, $actorUserId ?: null]);
    number_forward_cache_reset();
    audit($actorUserId, 'number_forward.save', sprintf(
        '%s mo=%d dlr=%d %s tls_verify=%d', $line, $values['mo_enabled'], $values['dlr_enabled'], $method, $tlsVerify
    ));
    return ['ok' => true];
}

/** Removes one line's forwarding. Rows still waiting in the outbox are cancelled by the worker. */
function number_forward_delete(string $number, int $actorUserId): void {
    db()->prepare('DELETE FROM ellsms_number_forwards WHERE number = ?')->execute([$number]);
    number_forward_cache_reset();
    audit($actorUserId, 'number_forward.delete', $number);
}

/* ==========================================================================
   Templates
   ========================================================================== */

/** The ${name} placeholders used in $template, in order of first use. */
function number_forward_template_placeholders(string $template): array {
    preg_match_all('/\$\{([A-Za-z_][A-Za-z0-9_]*)\}/', $template, $m);
    return array_values(array_unique($m[1]));
}

/**
 * Pure: $template with every ${name} replaced by its URL-encoded value. Encoded the way Vesal's
 * URLEncoder did (space as "+"), so a receiving script written for Vesal parses it the same way.
 * A placeholder with no value becomes empty.
 *
 * A GET template that uses no placeholder at all gets every value appended as query parameters — a
 * bare https://example.com/receive still receives the message.
 */
function number_forward_render_url(string $template, array $vars, string $method = 'GET'): string {
    $used = number_forward_template_placeholders($template);
    $url = preg_replace_callback(
        '/\$\{([A-Za-z_][A-Za-z0-9_]*)\}/',
        static fn(array $m): string => urlencode((string)($vars[$m[1]] ?? '')),
        $template
    );
    if ($used === [] && $method === 'GET' && $vars !== []) {
        $url .= (str_contains($url, '?') ? (str_ends_with($url, '?') || str_ends_with($url, '&') ? '' : '&') : '?')
              . http_build_query(array_map('strval', $vars));
    }
    return $url;
}

/** Example values for one kind, used by the template check and by the page's "test" button. */
function number_forward_sample_vars(string $kind, string $line = '5000123'): array {
    if ($kind === 'mo') {
        return [
            'originator' => '989120000000', 'destination' => $line, 'content' => 'پیام آزمایشی ELLSMS',
            'udh' => '', 'sourcePort' => '0', 'destinationPort' => '0', 'id' => '0',
            'received_at' => date('Y-m-d H:i:s'),
        ];
    }
    return [
        'id' => '0', 'part' => '1', 'status' => (string)NUMBER_FORWARD_VESAL_STATUS['delivered'],
        'dl_time' => (string)(time() * 1000), 'state' => 'delivered', 'message_id' => 'attempt:0', 'reference_id' => 'test',
        'originator' => $line, 'destination' => '989120000000', 'delivered_at' => date('Y-m-d H:i:s'),
        'provider_message_id' => '',
    ];
}

/**
 * Checks a template without touching the network: length, placeholders known for $kind, and a URL
 * that renders into http(s) with a host and no credentials. The destination address itself is
 * checked by number_forward_validate_template() (DNS) and again before every call.
 */
function number_forward_check_template_syntax(string $template, string $kind): array {
    if ($template === '' || strlen($template) > NUMBER_FORWARD_MAX_URL_LENGTH) {
        return ['ok' => false, 'reason' => $template === '' ? 'url_empty' : 'url_too_long'];
    }
    if (preg_match('/[\x00-\x20\x7f]/', $template)) {
        return ['ok' => false, 'reason' => 'url_whitespace'];
    }
    $unknown = array_diff(number_forward_template_placeholders($template), NUMBER_FORWARD_VARS[$kind] ?? []);
    if ($unknown !== []) {
        return ['ok' => false, 'reason' => 'unknown_placeholder', 'detail' => implode(', ', $unknown)];
    }
    $parts = parse_url(number_forward_render_url($template, number_forward_sample_vars($kind)));
    if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
        return ['ok' => false, 'reason' => 'url_invalid'];
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
        return ['ok' => false, 'reason' => 'credentials_in_url'];
    }
    // The host must be literal: a placeholder there would let message content choose the server.
    $host = parse_url($template, PHP_URL_HOST);
    if (!is_string($host) || $host === '' || str_contains($host, '$') || str_contains($host, '{')) {
        return ['ok' => false, 'reason' => 'url_invalid'];
    }
    $scheme = strtolower($parts['scheme']);
    if (!in_array($scheme, ['http', 'https'], true)) {
        return ['ok' => false, 'reason' => 'unsupported_scheme'];
    }
    if ($scheme === 'http' && !number_forward_http_allowed()) {
        return ['ok' => false, 'reason' => 'https_required'];
    }
    return ['ok' => true];
}

/** Syntax check plus the address check (number_forward_resolve_target()) on the template's host. */
function number_forward_validate_template(string $template, string $kind): array {
    $syntax = number_forward_check_template_syntax($template, $kind);
    if (!$syntax['ok']) return $syntax;
    $target = number_forward_resolve_target(number_forward_render_url($template, number_forward_sample_vars($kind)));
    return $target['ok'] ? ['ok' => true] : $target;
}

/**
 * Resolves the URL's host and refuses it when ANY address lands in a private / loopback / link-local
 * / reserved range (the same list as webhooks, WEBHOOK_BLOCKED_IP_RANGES). Returns
 * ['ok' => true, 'host', 'port', 'ip'] — the caller pins the connection to that ip with
 * CURLOPT_RESOLVE, so the address that was checked is the address connected to (no DNS-rebinding
 * window between the check and curl's own lookup).
 */
function number_forward_resolve_target(string $url): array {
    $parts = parse_url($url);
    if ($parts === false || empty($parts['host']) || empty($parts['scheme'])) {
        return ['ok' => false, 'reason' => 'url_invalid'];
    }
    $scheme = strtolower((string)$parts['scheme']);
    $host = strtolower(trim((string)$parts['host'], '[]'));
    $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
    if (webhook_local_targets_allowed()) {
        return ['ok' => true, 'host' => $host, 'port' => $port, 'ip' => null];
    }
    if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
        return ['ok' => false, 'reason' => 'blocked_host'];
    }
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return webhook_ip_is_blocked($host)
            ? ['ok' => false, 'reason' => 'blocked_ip_range']
            : ['ok' => true, 'host' => $host, 'port' => $port, 'ip' => null];
    }
    $records = @dns_get_record($host, DNS_A + DNS_AAAA);
    $ips = [];
    foreach ($records ?: [] as $r) {
        $ip = $r['ip'] ?? $r['ipv6'] ?? null;
        if ($ip !== null) $ips[] = (string)$ip;
    }
    if ($ips === []) {
        return ['ok' => false, 'reason' => 'dns_resolution_failed'];
    }
    foreach ($ips as $ip) {
        if (webhook_ip_is_blocked($ip)) return ['ok' => false, 'reason' => 'blocked_ip_range'];
    }
    return ['ok' => true, 'host' => $host, 'port' => $port, 'ip' => $ips[0]];
}

/** Persian text for a machine reason code (validation or delivery error). */
function number_forward_reason_text(string $reason): string {
    $code = preg_replace('/^ssrf_/', '', $reason);
    return match (true) {
        $code === 'url_empty'             => 'آدرس خالی است.',
        $code === 'url_too_long'          => 'آدرس بیش از حد طولانی است (حداکثر ۲۰۴۸ نویسه).',
        $code === 'url_whitespace'        => 'آدرس نباید فاصله یا نویسه‌ی کنترلی داشته باشد؛ مقادیر را با ${...} بگذارید.',
        $code === 'url_invalid'           => 'آدرس معتبر نیست (باید با http:// یا https:// و نام سرور شروع شود و نام سرور ثابت باشد).',
        $code === 'unsupported_scheme'    => 'فقط آدرس‌های http و https پشتیبانی می‌شوند.',
        $code === 'https_required'        => 'فقط آدرس https مجاز است (مدیر سامانه http را بسته است).',
        $code === 'credentials_in_url'    => 'نام کاربری و رمز در خود آدرس مجاز نیست؛ آن را به‌صورت پارامتر بفرستید.',
        $code === 'unknown_placeholder'   => 'متغیر ناشناخته در آدرس.',
        $code === 'blocked_host',
        $code === 'blocked_ip_range'      => 'این آدرس به شبکه‌ی داخلی/خصوصی اشاره می‌کند و مجاز نیست.',
        $code === 'dns_resolution_failed' => 'نام سرور این آدرس پیدا نشد (DNS).',
        $code === 'timeout'               => 'سرور مقصد در زمان مجاز پاسخ نداد.',
        $code === 'connection_failed'     => 'اتصال به سرور مقصد برقرار نشد.',
        $code === 'tls_error'             => 'گواهی SSL سرور مقصد معتبر نیست (یا اتصال امن برقرار نشد). اگر عمداً گواهی خودامضا دارید، «بررسی گواهی SSL» را برای این خط خاموش کنید.',
        $code === 'forward_disabled'      => 'ارسال به آدرس برای این خط خاموش یا حذف شد.',
        str_starts_with($code, 'http_')   => 'سرور مقصد کد ' . substr($code, 5) . ' برگرداند.',
        default                           => $code,
    };
}

/* ==========================================================================
   Enqueue (hot paths — never throw, never block the caller)
   ========================================================================== */

/**
 * Queues one forward unless that (kind, source) is already queued. Returns whether a row was added.
 * INSERT IGNORE on UNIQUE(kind, source_key) is the exactly-once guard.
 */
function number_forward_enqueue(string $kind, string $number, string $sourceKey, array $vars): bool {
    $st = db()->prepare('INSERT IGNORE INTO ellsms_number_forward_queue (kind, number, source_key, vars_json) VALUES (?,?,?,?)');
    $st->execute([$kind, $number, $sourceKey, json_encode(array_map('strval', $vars), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)]);
    $added = $st->rowCount() === 1;
    if ($added) Metrics::increment('number_forward.enqueued', 1, ['kind' => $kind]);
    return $added;
}

/** Called for every new inbound message (both stores) by run_autoreply_pass(). */
function number_forward_enqueue_mo(array $msg): void {
    try {
        if (!number_forward_enabled()) return;
        $line = normalize_originator((string)($msg['destination'] ?? ''));
        $inboundId = (int)($msg['id'] ?? 0);
        if ($line === null || $inboundId <= 0) return;
        if (number_forward_active_url($line, 'mo') === null) return;
        number_forward_enqueue('mo', $line, 'inbound:' . $inboundId, [
            'originator'      => (string)($msg['originator'] ?? ''),
            'destination'     => $line,
            'content'         => (string)($msg['content'] ?? ''),
            'udh'             => (string)($msg['udh'] ?? ''),
            'sourcePort'      => (string)($msg['source_port'] ?? '0'),
            'destinationPort' => (string)($msg['destination_port'] ?? '0'),
            'id'              => (string)$inboundId,
            'received_at'     => (string)($msg['received_at'] ?? ''),
        ]);
    } catch (Throwable $t) {
        Logger::warning('number_forward.mo_enqueue_failed', ['inbound_message_id' => $msg['id'] ?? null, 'exception' => $t]);
    }
}

/** Called by gateway_status_record() right after it moved a row into terminal state $state. */
function number_forward_enqueue_dlr(string $source, int $rowId, string $state, ?string $deliveredAt): void {
    try {
        if (!number_forward_enabled()) return;
        // Nothing forwards DLRs at all (the usual case): no query per status write.
        $anyDlr = false;
        foreach (number_forward_active_configs() as $config) {
            if ((int)$config['dlr_enabled'] === 1 && trim((string)$config['dlr_url']) !== '') { $anyDlr = true; break; }
        }
        if (!$anyDlr) return;

        if ($source === 'attempt') {
            $st = db()->prepare('SELECT originator, destination, provider_message_id, delivered_at, reference_id FROM ellsms_message_attempts WHERE id = ?');
            $sourceKey = 'attempt:' . $rowId;
        } else {
            $st = db()->prepare('SELECT j.originator, bi.mobile AS destination, bi.provider_message_id, bi.delivered_at, bi.job_id AS reference_id
                                 FROM ellsms_bulk_items bi JOIN ellsms_bulk_jobs j ON j.id = bi.job_id WHERE bi.id = ?');
            $sourceKey = 'bulk_item:' . $rowId;
        }
        $st->execute([$rowId]);
        $row = $st->fetch();
        if (!$row) return;
        $line = normalize_originator((string)($row['originator'] ?? ''));
        if ($line === null || number_forward_active_url($line, 'dlr') === null) return;

        $at = $deliveredAt ?? ($row['delivered_at'] ?? null);
        $ts = $at !== null ? strtotime((string)$at) : false;
        $ts = $ts === false ? time() : $ts;
        number_forward_enqueue('dlr', $line, $sourceKey, [
            'id'                  => (string)$rowId,
            'part'                => '1',
            'status'              => (string)(NUMBER_FORWARD_VESAL_STATUS[$state] ?? NUMBER_FORWARD_VESAL_STATUS['unknown']),
            'dl_time'             => (string)($ts * 1000),
            'state'               => $state,
            'message_id'          => $sourceKey,
            'reference_id'        => (string)($row['reference_id'] ?? ''),
            'originator'          => $line,
            'destination'         => (string)($row['destination'] ?? ''),
            'delivered_at'        => date('Y-m-d H:i:s', $ts),
            'provider_message_id' => (string)($row['provider_message_id'] ?? ''),
        ]);
    } catch (Throwable $t) {
        Logger::warning('number_forward.dlr_enqueue_failed', ['source' => $source, 'row_id' => $rowId, 'exception' => $t]);
    }
}

/* ==========================================================================
   HTTP
   ========================================================================== */

/**
 * Pure: the request for one forward. $config is the line's row, $kind 'mo'|'dlr'.
 * Returns ['url', 'method', 'body' (?string), 'headers' (list)] or null when that kind is off.
 */
function number_forward_build_request(array $config, string $kind, array $vars, string $deliveryId = ''): ?array {
    if ((int)($config[$kind . '_enabled'] ?? 0) !== 1) return null;
    $template = trim((string)($config[$kind . '_url'] ?? ''));
    if ($template === '') return null;
    $method = ($config['http_method'] ?? 'GET') === 'POST' ? 'POST' : 'GET';
    $headers = [
        'User-Agent: ELLSMS-Forward/1.0',
        'X-ELLSMS-Forward-Kind: ' . $kind,
    ];
    if ($deliveryId !== '') $headers[] = 'X-ELLSMS-Forward-Id: ' . $deliveryId;
    $body = null;
    if ($method === 'POST') {
        if (($config['post_format'] ?? 'form') === 'json') {
            $body = json_encode(array_map('strval', $vars), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            $headers[] = 'Content-Type: application/json; charset=utf-8';
        } else {
            $body = http_build_query(array_map('strval', $vars));
            $headers[] = 'Content-Type: application/x-www-form-urlencoded; charset=utf-8';
        }
    }
    return [
        'url' => number_forward_render_url($template, $vars, $method), 'method' => $method, 'body' => $body, 'headers' => $headers,
        // Off unless the line asked for it: Vesal trusted every certificate, and a customer's
        // self-signed / expired / wrong-host certificate must not stop the forward.
        'tls_verify' => (int)($config['tls_verify'] ?? 0) === 1,
    ];
}

/**
 * Runs requests in parallel (curl_multi sliding window, at most $concurrency at once) and returns one result per key:
 * ['outcome' => 'delivered'|'retry'|'permanent_failure', 'http_status', 'error_code', 'response_excerpt', 'duration_ms'].
 *
 * Every target is re-checked right here and the connection pinned to the checked address; redirects
 * are never followed (a 3xx is just a non-success answer), and at most NUMBER_FORWARD_MAX_RESPONSE_BYTES
 * of the answer is read.
 *
 * Certificates are verified only for a request with 'tls_verify' => true; otherwise an https target
 * with a self-signed, expired or wrong-host certificate is still called (encrypted, unverified).
 * The address check above does not depend on the certificate, so the SSRF guard holds either way.
 *
 * @param array<string|int, array{url:string, method:string, body:?string, headers:list<string>, tls_verify?:bool}> $requests
 */
function number_forward_http_execute(array $requests, int $concurrency, int $timeoutSeconds): array {
    $results = [];
    $queue = [];
    foreach ($requests as $key => $request) {
        // http:// switched off by the admin after the URL was saved: no longer called.
        if (strtolower((string)parse_url($request['url'], PHP_URL_SCHEME)) === 'http' && !number_forward_http_allowed()) {
            $results[$key] = ['outcome' => 'permanent_failure', 'http_status' => null, 'error_code' => 'https_required', 'response_excerpt' => null, 'duration_ms' => 0];
            continue;
        }
        $target = number_forward_resolve_target($request['url']);
        if (!$target['ok']) {
            $results[$key] = ['outcome' => 'permanent_failure', 'http_status' => null, 'error_code' => 'ssrf_' . $target['reason'], 'response_excerpt' => null, 'duration_ms' => 0];
            continue;
        }
        $queue[$key] = $request + ['target' => $target];
    }
    if ($queue === []) return $results;

    // A sliding window, not waves: the moment any call finishes the next one starts, so one slow
    // customer URL holds one slot for at most the timeout and never stalls the calls behind it.
    $concurrency = max(1, $concurrency);
    $multi = curl_multi_init();
    $active = [];   // spl_object_id(handle) => [key, handle]
    $buffers = [];
    $start = static function ($key, array $request) use ($multi, &$active, &$buffers, $timeoutSeconds): void {
        $buffers[$key] = '';
        $verify = !empty($request['tls_verify']);
        $ch = curl_init($request['url']);
        $options = [
            CURLOPT_CUSTOMREQUEST   => $request['method'],
            CURLOPT_HTTPHEADER      => $request['headers'],
            CURLOPT_TIMEOUT         => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT  => min(5, $timeoutSeconds),
            CURLOPT_FOLLOWLOCATION  => false,
            CURLOPT_SSL_VERIFYPEER  => $verify,
            CURLOPT_SSL_VERIFYHOST  => $verify ? 2 : 0,
            CURLOPT_NOSIGNAL        => true,
            CURLOPT_RETURNTRANSFER  => false,
            CURLOPT_WRITEFUNCTION   => function ($ch, string $chunk) use (&$buffers, $key): int {
                $room = NUMBER_FORWARD_MAX_RESPONSE_BYTES - strlen($buffers[$key]);
                if ($room > 0) $buffers[$key] .= substr($chunk, 0, $room);
                return strlen($chunk);
            },
        ];
        if ($request['body'] !== null) {
            $options[CURLOPT_POSTFIELDS] = $request['body'];
        }
        $ip = $request['target']['ip'] ?? null;
        if ($ip !== null) {
            $pin = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
            $options[CURLOPT_RESOLVE] = [$request['target']['host'] . ':' . $request['target']['port'] . ':' . $pin];
        }
        curl_setopt_array($ch, $options);
        curl_multi_add_handle($multi, $ch);
        $active[spl_object_id($ch)] = [$key, $ch];
    };

    while ($queue !== [] || $active !== []) {
        while (count($active) < $concurrency && $queue !== []) {
            $key = array_key_first($queue);
            $start($key, $queue[$key]);
            unset($queue[$key]);
        }
        do {
            $status = curl_multi_exec($multi, $running);
        } while ($status === CURLM_CALL_MULTI_PERFORM);
        $finished = false;
        while ($info = curl_multi_info_read($multi)) {
            $ch = $info['handle'];
            $id = spl_object_id($ch);
            if (!isset($active[$id])) continue;
            [$key] = $active[$id];
            unset($active[$id]);
            $results[$key] = number_forward_http_result((int)$info['result'], (int)curl_getinfo($ch, CURLINFO_HTTP_CODE),
                (int)round((float)curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000), $buffers[$key]);
            unset($buffers[$key]);
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
            $finished = true;
        }
        if ($status !== CURLM_OK) {
            foreach ($active as [$key, $ch]) {
                $results[$key] = ['outcome' => 'retry', 'http_status' => null, 'error_code' => 'connection_failed', 'response_excerpt' => null, 'duration_ms' => 0];
                curl_multi_remove_handle($multi, $ch);
                curl_close($ch);
            }
            $active = [];
        } elseif (!$finished && $active !== []) {
            curl_multi_select($multi, 0.2);
        }
    }
    curl_multi_close($multi);
    foreach ($queue as $key => $_) {
        $results[$key] ??= ['outcome' => 'retry', 'http_status' => null, 'error_code' => 'connection_failed', 'response_excerpt' => null, 'duration_ms' => 0];
    }
    return $results;
}

/** Pure: one finished call's curl error number, HTTP status, time and (capped) body as a result row. */
function number_forward_http_result(int $errno, int $httpStatus, int $durationMs, string $body): array {
    $excerpt = mb_strimwidth(mb_convert_encoding($body, 'UTF-8', 'UTF-8'), 0, 1000, '…', 'UTF-8');
    if ($errno === CURLE_OPERATION_TIMEDOUT) {
        return ['outcome' => 'retry', 'http_status' => null, 'error_code' => 'timeout', 'response_excerpt' => null, 'duration_ms' => $durationMs];
    }
    if (in_array($errno, NUMBER_FORWARD_TLS_ERRNOS, true)) {
        return ['outcome' => 'retry', 'http_status' => null, 'error_code' => 'tls_error', 'response_excerpt' => null, 'duration_ms' => $durationMs];
    }
    if ($errno !== 0) {
        return ['outcome' => 'retry', 'http_status' => null, 'error_code' => 'connection_failed', 'response_excerpt' => null, 'duration_ms' => $durationMs];
    }
    if ($httpStatus >= 200 && $httpStatus < 300) {
        return ['outcome' => 'delivered', 'http_status' => $httpStatus, 'error_code' => null, 'response_excerpt' => $excerpt, 'duration_ms' => $durationMs];
    }
    return [
        'outcome'          => webhook_http_status_is_retryable($httpStatus) ? 'retry' : 'permanent_failure',
        'http_status'      => $httpStatus ?: null,
        'error_code'       => 'http_' . $httpStatus,
        'response_excerpt' => $excerpt,
        'duration_ms'      => $durationMs,
    ];
}

/**
 * Sends one example forward right now, from the page's "test" button, and returns the result plus
 * the URL called. Uses the line's saved settings with $kind switched on, so a URL can be tried
 * before forwarding is turned on.
 */
function number_forward_send_test(string $number, string $kind): array {
    $config = number_forward_find($number);
    if ($config === null || trim((string)$config[$kind . '_url']) === '') {
        return ['ok' => false, 'error' => 'ابتدا آدرس را ذخیره کنید.'];
    }
    $config[$kind . '_enabled'] = 1;
    $request = number_forward_build_request($config, $kind, number_forward_sample_vars($kind, $number), 'test');
    $result = number_forward_http_execute(['test' => $request], 1, number_forward_setting_int('number_forward_timeout_seconds'))['test'];
    return ['ok' => $result['outcome'] === 'delivered', 'result' => $result, 'url' => $request['url'], 'method' => $request['method']];
}

/* ==========================================================================
   Worker (cron/number-forward-worker.php)
   ========================================================================== */

/**
 * Claims up to $limit due rows for this process: pending rows whose time has come, plus processing
 * rows whose lease ran out (a worker that died mid-call). The same two-step UPDATE ... LIMIT claim as
 * webhook_claim_deliveries(), for the reason its docblock gives.
 */
function number_forward_claim(int $limit): array {
    $limit = max(1, $limit);
    $token = worker_id() . ':' . bin2hex(random_bytes(4));
    // The pass calls its rows in waves of `concurrency`, each wave bounded by the timeout, so the
    // lease must outlast every wave — or a second worker could re-claim a row still being called.
    $waves = (int)ceil($limit / number_forward_setting_int('number_forward_concurrency'));
    $lease = $waves * number_forward_setting_int('number_forward_timeout_seconds') + 30;
    db_transaction(function (PDO $db) use ($limit, $token, $lease): void {
        $due = $db->prepare(
            "UPDATE ellsms_number_forward_queue
             SET status = 'processing', claimed_by = ?, lease_expires_at = DATE_ADD(NOW(), INTERVAL ? SECOND), attempt_count = attempt_count + 1
             WHERE status = 'pending' AND (next_attempt_at IS NULL OR next_attempt_at <= NOW())
             ORDER BY id LIMIT {$limit}"
        );
        $due->execute([$token, $lease]);
        $remaining = $limit - $due->rowCount();
        if ($remaining > 0) {
            $db->prepare(
                "UPDATE ellsms_number_forward_queue
                 SET claimed_by = ?, lease_expires_at = DATE_ADD(NOW(), INTERVAL ? SECOND), attempt_count = attempt_count + 1
                 WHERE status = 'processing' AND lease_expires_at IS NOT NULL AND lease_expires_at < NOW()
                 ORDER BY id LIMIT {$remaining}"
            )->execute([$token, $lease]);
        }
    });
    $st = db()->prepare('SELECT * FROM ellsms_number_forward_queue WHERE claimed_by = ? ORDER BY id');
    $st->execute([$token]);
    return $st->fetchAll();
}

/** One worker pass: claim, call, record. Returns how many rows were finished or rescheduled. */
function number_forward_run_pass(int $batchSize): int {
    if (!number_forward_available() || !number_forward_enabled()) return 0;
    $rows = number_forward_claim($batchSize);
    if ($rows === []) return 0;

    $configs = [];
    $st = db()->prepare('SELECT * FROM ellsms_number_forwards WHERE number = ?');
    $requests = [];
    $byKey = [];
    foreach ($rows as $row) {
        $number = (string)$row['number'];
        if (!array_key_exists($number, $configs)) {
            $st->execute([$number]);
            $configs[$number] = $st->fetch() ?: null;
        }
        $vars = json_decode((string)$row['vars_json'], true);
        $request = $configs[$number] !== null && is_array($vars)
            ? number_forward_build_request($configs[$number], (string)$row['kind'], $vars, (string)$row['id'])
            : null;
        if ($request === null) {
            number_forward_finish($row, ['outcome' => 'cancelled', 'http_status' => null, 'error_code' => 'forward_disabled', 'response_excerpt' => null, 'duration_ms' => 0], null);
            continue;
        }
        $requests[(string)$row['id']] = $request;
        $byKey[(string)$row['id']] = $row;
    }

    $results = $requests === [] ? [] : number_forward_http_execute(
        $requests,
        number_forward_setting_int('number_forward_concurrency'),
        number_forward_setting_int('number_forward_timeout_seconds')
    );
    foreach ($results as $key => $result) {
        try {
            $row = $byKey[(string)$key];
            number_forward_finish($row, $result, $requests[$key]['url'], $configs[(string)$row['number']] ?? null);
        } catch (Throwable $t) {
            Logger::error('number_forward.finish_failed', ['queue_id' => $key, 'exception' => $t]);
        }
    }
    return count($rows);
}

/** Records one attempt's result on its row and on the line's health columns. */
function number_forward_finish(array $row, array $result, ?string $url, ?array $config = null): void {
    $outcome = $result['outcome'];
    $attempts = (int)$row['attempt_count'];
    $excerpt = $result['response_excerpt'] !== null ? mb_substr((string)$result['response_excerpt'], 0, 1000) : null;
    $errorCode = $result['error_code'] !== null ? substr((string)$result['error_code'], 0, 80) : null;
    $url = $url !== null ? substr($url, 0, NUMBER_FORWARD_MAX_URL_LENGTH) : null;

    if ($outcome === 'delivered') {
        $status = 'delivered';
    } elseif ($outcome === 'cancelled') {
        $status = 'cancelled';
    } elseif ($outcome === 'retry' && $attempts < number_forward_max_attempts_for($config)) {
        $status = 'pending';
    } else {
        $status = $outcome === 'retry' ? 'dead' : 'failed';
    }

    $delay = $status === 'pending' ? number_forward_retry_delay_seconds($attempts) : 0;
    db()->prepare(
        "UPDATE ellsms_number_forward_queue
         SET status = ?, http_status = ?, error_code = ?, response_excerpt = ?, duration_ms = ?, last_url = COALESCE(?, last_url),
             claimed_by = NULL, lease_expires_at = NULL,
             next_attempt_at = IF(? = 'pending', DATE_ADD(NOW(), INTERVAL ? SECOND), NULL),
             completed_at = IF(? IN ('delivered','failed','dead','cancelled'), NOW(), NULL)
         WHERE id = ?"
    )->execute([$status, $result['http_status'], $errorCode, $excerpt, $result['duration_ms'], $url, $status, $delay, $status, $row['id']]);

    if ($status === 'delivered') {
        db()->prepare('UPDATE ellsms_number_forwards SET last_success_at = NOW(), consecutive_failures = 0 WHERE number = ?')
            ->execute([$row['number']]);
    } elseif ($status !== 'cancelled') {
        db()->prepare('UPDATE ellsms_number_forwards SET last_failure_at = NOW(), last_error = ?, consecutive_failures = consecutive_failures + 1 WHERE number = ?')
            ->execute([$errorCode, $row['number']]);
    }
    Metrics::increment('number_forward.attempt', 1, ['kind' => (string)$row['kind'], 'status' => $status]);
    $log = $status === 'delivered' ? 'info' : 'warning';
    Logger::{$log}('number_forward.' . $status, [
        'queue_id' => $row['id'], 'kind' => $row['kind'], 'number' => $row['number'], 'attempt' => $attempts,
        'http_status' => $result['http_status'], 'error_code' => $errorCode, 'duration_ms' => $result['duration_ms'],
    ]);
}

/**
 * Puts finished-but-failed rows back in line: one row ($queueId), or every failed / dead / cancelled
 * row of a line ($number). Restricted to $allowedNumbers when given (a customer's own lines).
 * Returns how many rows were re-queued.
 */
function number_forward_requeue(?int $queueId, ?string $number, ?array $allowedNumbers = null): int {
    $where = ["status IN ('failed','dead','cancelled')"];
    $params = [];
    if ($queueId !== null) { $where[] = 'id = ?'; $params[] = $queueId; }
    if ($number !== null) { $where[] = 'number = ?'; $params[] = $number; }
    if ($allowedNumbers !== null) {
        if ($allowedNumbers === []) return 0;
        $where[] = 'number IN (' . implode(',', array_fill(0, count($allowedNumbers), '?')) . ')';
        array_push($params, ...array_values($allowedNumbers));
    }
    $st = db()->prepare("UPDATE ellsms_number_forward_queue
                         SET status = 'pending', attempt_count = 0, next_attempt_at = NULL, completed_at = NULL
                         WHERE " . implode(' AND ', $where));
    $st->execute($params);
    return $st->rowCount();
}

/** Deletes finished rows older than the retention setting, in small chunks. Returns rows deleted. */
function number_forward_prune(?int $days = null, int $chunk = 5000): int {
    $days ??= number_forward_setting_int('number_forward_retention_days');
    $total = 0;
    $st = db()->prepare("DELETE FROM ellsms_number_forward_queue
                         WHERE completed_at IS NOT NULL AND completed_at < DATE_SUB(NOW(), INTERVAL ? DAY)
                         LIMIT {$chunk}");
    do {
        $st->execute([$days]);
        $n = $st->rowCount();
        $total += $n;
    } while ($n === $chunk);
    return $total;
}

/** Row counts per status, optionally for some lines only (the page's summary). */
function number_forward_queue_counts(?array $numbers = null): array {
    $counts = array_fill_keys(['pending', 'processing', 'delivered', 'failed', 'dead', 'cancelled'], 0);
    if (!number_forward_available() || $numbers === []) return $counts;
    $sql = 'SELECT status, COUNT(*) c FROM ellsms_number_forward_queue';
    $params = [];
    if ($numbers !== null) {
        $sql .= ' WHERE number IN (' . implode(',', array_fill(0, count($numbers), '?')) . ')';
        $params = array_values($numbers);
    }
    $st = db()->prepare($sql . ' GROUP BY status');
    $st->execute($params);
    foreach ($st->fetchAll() as $row) {
        $counts[(string)$row['status']] = (int)$row['c'];
    }
    return $counts;
}
