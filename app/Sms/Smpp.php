<?php
/**
 * #46 — SMPP gateways (docs/smpp-gateway.md).
 *
 * A gateway whose protocol is 'smpp' has no HTTP connector: its sessions live in the smpp-bridge
 * container (smpp-bridge/, Java/jSMPP), which reads the same gateway configuration from the shared
 * database. This file is the PHP half:
 *
 *   - smpp_gateway_compile()  the compiled connector for an SMPP gateway (same cache/versioning as HTTP)
 *   - smpp_gateway_send()     gateway_send() for SMPP: one call to the bridge's /v1/submit, answered
 *                             in exactly the shape gateway_send() returns, so bulk, direct sends,
 *                             wallet settlement and retries behave identically for both protocols
 *   - smpp_events_process_pass()  applies delivery receipts and stores received messages that the
 *                             bridge wrote to ellsms_smpp_events (run by the status-worker)
 *   - smpp_bridge_*()         the bridge's internal HTTP API (status, reconnect, test bind)
 */

declare(strict_types=1);

const SMPP_BRIDGE_MAX_RECIPIENTS = 500;
/** A bridge request is not started with less time than this left in the send's budget. */
const SMPP_MIN_REQUEST_MS = 15000;

function smpp_bridge_url(): string {
    return rtrim((string)(env('SMPP_BRIDGE_URL', 'http://smpp-bridge:8090') ?? 'http://smpp-bridge:8090'), '/');
}

/**
 * One request to the bridge. The bridge is an internal service on the Docker network, called with a
 * fixed base URL from the environment — never an admin-supplied address — so it deliberately does not
 * go through gateway_endpoint_allowed()'s public-address rules.
 *
 * not_sent: the request never reached the bridge (it is down or restarting: connection refused, name
 * not resolved), so nothing can have been submitted to the SMSC.
 *
 * @return array{ok: bool, http: int, data: mixed, error: ?string, timed_out: bool, not_sent: bool}
 */
function smpp_bridge_request(string $method, string $path, ?array $body = null, int $timeoutMs = 60000): array {
    $ch = curl_init(smpp_bridge_url() . $path);
    $headers = ['Accept: application/json', 'Authorization: Bearer ' . (string)env('SMPP_BRIDGE_TOKEN', '')];
    if ($body !== null) $headers[] = 'Content-Type: application/json';
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT_MS => 3000,
        CURLOPT_TIMEOUT_MS => $timeoutMs,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($raw === false) {
        return ['ok' => false, 'http' => 0, 'data' => null, 'error' => 'smpp-bridge unreachable: ' . $error, 'timed_out' => $errno === CURLE_OPERATION_TIMEDOUT,
                'not_sent' => in_array($errno, [CURLE_COULDNT_RESOLVE_PROXY, CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT], true)];
    }
    $data = json_decode((string)$raw, true);
    $ok = $http >= 200 && $http < 300 && is_array($data);
    return ['ok' => $ok, 'http' => $http, 'data' => $data, 'error' => $ok ? null : (is_array($data) ? (string)($data['detail'] ?? $data['error'] ?? 'error') : 'invalid response'), 'timed_out' => false, 'not_sent' => false];
}

/**
 * How long one send may spend in the bridge in total (all its requests). A bulk batch's lease is
 * renewed right before the send (bulk_hold_claim()); a send that outlived it would see its rows failed
 * as [SEND_UNCERTAIN] by another worker while the bridge was still submitting them, so the budget stays
 * 30 s inside the lease.
 */
function smpp_send_budget_ms(): int {
    $lease = function_exists('job_lease_seconds') ? job_lease_seconds() : 300;
    return max(30, $lease - 30) * 1000;
}

/**
 * The HTTP timeout for one bridge request: the time its messages need at the gateway's TPS (each part
 * takes a token, and other workers share the same TPS), three times over plus 30 s, at least 60 s,
 * never beyond what is left of the send's budget.
 */
function smpp_submit_timeout_ms(int $recipients, int $parts, int $tps, int $budgetLeftMs): int {
    $needMs = (int)ceil(max(1, $recipients) * max(1, $parts) * 1000 / max(1, $tps));
    return max(1000, min($budgetLeftMs, max(60000, 30000 + 3 * $needMs)));
}

/**
 * The deadline the bridge is given: after it no new message is started (those come back not_sent), so
 * the bridge answers — with the id of every message it did submit — before PHP's own timeout. The
 * margin covers the messages still in flight: one submit_sm_resp wait plus 15 s.
 */
function smpp_submit_deadline_ms(int $timeoutMs, int $submitTimeoutMs): int {
    return max(5000, $timeoutMs - max(1000, $submitTimeoutMs) - 5000 - 15000);
}

/**
 * The most parts any of the send's texts needs — the bridge spends one TPS token per part. Counted with
 * the gateway's own part sizes ($limits, sms_gateway_part_limits()), which the bridge splits with too.
 */
function smpp_send_parts(array $input, ?array $limits = null): int {
    $parts = sms_parts((string)($input['message'] ?? ''), $limits);
    foreach ((array)($input['messages'] ?? []) as $text) {
        $parts = max($parts, sms_parts((string)$text, $limits));
    }
    return max(1, $parts);
}

/**
 * SMPP 3.4 command_status → [short Persian label, what to do]. Shown wherever an SMSC's refusal appears:
 * the row's error code (`0x0000000A فرستنده نامعتبر`), its error text, the test-bind message and the
 * session table's last error.
 */
const SMPP_COMMAND_STATUS_LABELS = [
    0x01 => ['طول پیام نامعتبر', 'اپراتور طول پیام را نپذیرفت.'],
    0x02 => ['طول فرمان نامعتبر', 'اپراتور فرمان SMPP را نامعتبر دانست.'],
    0x03 => ['فرمان نامعتبر', 'اپراتور این فرمان SMPP را پشتیبانی نمی‌کند.'],
    0x04 => ['وضعیت اتصال نامعتبر', 'نوع اتصال (bind) اجازه‌ی این کار را نمی‌دهد؛ «نوع اتصال» را بررسی کنید.'],
    0x05 => ['حساب از قبل متصل است', 'حساب قبلاً متصل است یا تعداد نشست‌های مجاز پر است.'],
    0x06 => ['اولویت نامعتبر', 'اپراتور پرچم اولویت پیام را نپذیرفت.'],
    0x07 => ['پرچم تحویل نامعتبر', 'اپراتور درخواست رسید تحویل را نپذیرفت؛ «درخواست رسید تحویل» را بررسی کنید.'],
    0x08 => ['خطای سیستمی اپراتور', 'خطای داخلی سامانه‌ی اپراتور؛ بعداً دوباره تلاش کنید یا به اپراتور اطلاع دهید.'],
    0x0A => ['فرستنده نامعتبر', 'اپراتور شماره‌ی فرستنده را نپذیرفت: «TON/NPI فرستنده» در تنظیمات SMPP، قالب شماره‌ی خط (با یا بدون 98) و ثبت این خط روی حساب نزد اپراتور را بررسی کنید.'],
    0x0B => ['گیرنده نامعتبر', 'اپراتور شماره‌ی گیرنده را نپذیرفت: شماره، «قالب شماره گیرنده» و «TON/NPI گیرنده» را بررسی کنید.'],
    0x0C => ['شناسه‌ی پیام نامعتبر', 'اپراتور شناسه‌ی پیام را نامعتبر دانست.'],
    0x0D => ['اتصال رد شد', 'اپراتور اتصال را رد کرد؛ معمولاً IP مجاز نیست یا حساب غیرفعال است.'],
    0x0E => ['رمز نامعتبر', 'رمز SMPP اشتباه است.'],
    0x0F => ['system_id نامعتبر', 'system_id اشتباه است.'],
    0x11 => ['لغو ناموفق', 'لغو پیام ناموفق بود.'],
    0x14 => ['صف اپراتور پر است', 'صف پیام اپراتور پر است؛ کمی بعد دوباره ارسال می‌شود.'],
    0x15 => ['نوع سرویس نامعتبر', 'service_type نامعتبر است.'],
    0x33 => ['تعداد گیرنده نامعتبر', 'اپراتور تعداد گیرنده‌ها را نپذیرفت.'],
    0x34 => ['نام فهرست توزیع نامعتبر', 'اپراتور فهرست توزیع را نپذیرفت.'],
    0x40 => ['پرچم مقصد نامعتبر', 'اپراتور نوع مقصد را نپذیرفت.'],
    0x45 => ['ارسال ناموفق', 'اپراتور ثبت پیام را رد کرد (ESME_RSUBMITFAIL)؛ معمولاً اعتبار حساب، مجوز خط یا محتوای پیام. با اپراتور بررسی کنید.'],
    0x48 => ['TON فرستنده نامعتبر', 'عدد اول «TON/NPI فرستنده» را مطابق مستند اپراتور تغییر دهید.'],
    0x49 => ['NPI فرستنده نامعتبر', 'عدد دوم «TON/NPI فرستنده» را مطابق مستند اپراتور تغییر دهید.'],
    0x50 => ['TON گیرنده نامعتبر', 'عدد اول «TON/NPI گیرنده» را مطابق مستند اپراتور تغییر دهید.'],
    0x51 => ['NPI گیرنده نامعتبر', 'عدد دوم «TON/NPI گیرنده» را مطابق مستند اپراتور تغییر دهید.'],
    0x53 => ['system_type نامعتبر', 'system_type را مطابق مستند اپراتور وارد کنید.'],
    0x54 => ['پرچم جایگزینی نامعتبر', 'اپراتور پرچم جایگزینی پیام را نپذیرفت.'],
    0x55 => ['تعداد پیام نامعتبر', 'اپراتور تعداد پیام‌ها را نپذیرفت.'],
    0x58 => ['محدودیت سرعت', 'از سقف سرعت اپراتور بیشتر ارسال شده؛ «سقف سرعت (TPS)» را کم کنید.'],
    0x61 => ['زمان‌بندی نامعتبر', 'زمان ارسال برنامه‌ریزی‌شده را اپراتور نپذیرفت.'],
    0x62 => ['مدت اعتبار نامعتبر', '«اعتبار پیام» را صفر بگذارید یا مطابق مستند اپراتور تنظیم کنید.'],
    0x63 => ['پیام پیش‌فرض نامعتبر', 'اپراتور شناسه‌ی پیام پیش‌فرض را نپذیرفت.'],
    0x64 => ['رد موقت اپراتور', 'اپراتور پیام را موقتاً رد کرد؛ بعداً دوباره تلاش کنید.'],
    0x65 => ['رد دائمی اپراتور', 'اپراتور پیام را برای همیشه رد کرد (مثلاً مسدودی یا محتوای غیرمجاز).'],
    0x66 => ['رد اپراتور', 'اپراتور پیام را رد کرد.'],
    0x67 => ['استعلام ناموفق', 'استعلام وضعیت پیام ناموفق بود.'],
    0xC0 => ['بخش اختیاری نامعتبر', 'اپراتور یکی از پارامترهای اختیاری (TLV) را نپذیرفت؛ «پیام چندبخشی» را UDH بگذارید.'],
    0xC1 => ['پارامتر اختیاری غیرمجاز', 'اپراتور یک پارامتر اختیاری (TLV) را نمی‌پذیرد؛ «پیام چندبخشی» را UDH بگذارید.'],
    0xC2 => ['طول پارامتر نامعتبر', 'طول یکی از پارامترهای اختیاری نامعتبر است.'],
    0xC3 => ['پارامتر لازم وجود ندارد', 'اپراتور یک پارامتر لازم را نیافت؛ مستند اپراتور را بررسی کنید.'],
    0xC4 => ['مقدار پارامتر نامعتبر', 'مقدار یکی از پارامترها نامعتبر است.'],
    0xFE => ['تحویل ناموفق', 'اپراتور نتوانست پیام را تحویل دهد.'],
    0xFF => ['خطای نامشخص اپراتور', 'اپراتور خطای نامشخص برگرداند.'],
];

/** "فرستنده نامعتبر" (short) or the full advice for an SMPP command_status; '' when unknown. */
function smpp_command_status_label(int $status, bool $advice = false): string {
    $label = SMPP_COMMAND_STATUS_LABELS[$status] ?? null;
    return $label === null ? '' : $label[$advice ? 1 : 0];
}

/** Appends the Persian meaning to every SMPP status code (0x0000000A, 0xa) found in a text. */
function smpp_annotate_status_codes(string $text): string {
    return (string)preg_replace_callback('/\b0x([0-9a-fA-F]{1,8})\b/', static function (array $m): string {
        $label = smpp_command_status_label((int)hexdec($m[1]));
        return $label === '' ? $m[0] : $m[0] . ' (' . $label . ')';
    }, $text);
}

/** The per-recipient error code a failed SMPP submit is recorded with (command_status, else the class). */
function smpp_result_error_code(array $result): string {
    $status = $result['command_status'] ?? null;
    if (is_int($status) || (is_string($status) && ctype_digit($status))) {
        $label = smpp_command_status_label((int)$status);
        return sprintf('0x%08X', (int)$status) . ($label !== '' ? ' ' . $label : '');
    }
    return (string)($result['error_class'] ?? '') !== '' ? 'smpp_' . (string)$result['error_class'] : 'smpp_failed';
}

/**
 * The panel's message for one «اتصال آزمایشی» answer: [ok, Persian text]. The bridge diagnoses a
 * failed bind (smpp-bridge BindTester: TCP, own sessions, enquire_link probe, TLS, transmitter bind);
 * this says what to change. Technical detail is appended for the admin.
 *
 * @return array{0: bool, 1: string}
 */
function smpp_test_bind_message(array $d): array {
    $fa = static fn(int|string $n): string => to_persian_digits((string)$n);
    $where = (string)($d['host'] ?? '') . ':' . (int)($d['port'] ?? 0);
    if (!empty($d['ok'])) {
        return [true, 'اتصال آزمایشی موفق بود (' . (string)($d['bind_type'] ?? '') . '، ' . $fa((int)($d['elapsed_ms'] ?? 0)) . ' میلی‌ثانیه؛ SMSC: ' . (string)($d['smsc_system_id'] ?? '') . ').'];
    }
    $code = (string)($d['command_status'] ?? '');
    $text = match ((string)($d['diagnosis'] ?? '')) {
        'no_password' => 'رمز SMPP ذخیره نشده است: system_id و رمز را در «تنظیمات SMPP» وارد و ذخیره کنید.',
        'not_configured' => 'سرویس SMPP این درگاه را اجرا نمی‌کند: درگاه باید فعال باشد و آدرس سرور داشته باشد.',
        'tcp_refused' => 'اتصال TCP به ' . $where . ' رد شد: روی این پورت سرویسی گوش نمی‌دهد. آدرس و پورت را با اپراتور چک کنید.',
        'tcp_failed' => 'اتصال TCP به ' . $where . ' برقرار نشد: سرور شما (کانتینر smpp-bridge) به این آدرس راه ندارد. مسیر شبکه، VPN یا فایروال را بررسی کنید.',
        'invalid_password' => 'SMSC رمز را رد کرد (ESME_RINVPASWD): رمز را دوباره وارد کنید (حداکثر ۸ نویسه).',
        'invalid_system_id' => 'SMSC این system_id را نمی‌شناسد (ESME_RINVSYSID).',
        'already_bound' => 'SMSC می‌گوید این حساب قبلاً متصل است (ESME_RALYBND): اپراتور تعداد نشست را محدود کرده یا نشست دیگری با همین حساب (مثلاً از سرور دیگری) باز است.',
        'bind_failed' => 'SMSC اتصال را رد کرد (ESME_RBINDFAIL): معمولاً یعنی IP سرور شما برای این حساب مجاز نیست یا حساب غیرفعال است. با اپراتور هماهنگ کنید.',
        'bind_rejected' => 'SMSC اتصال را با کد ' . $code . ' رد کرد' . ($code !== '' && smpp_command_status_label((int)hexdec(substr($code, 2))) !== '' ? ': ' . smpp_command_status_label((int)hexdec(substr($code, 2)), true) : '.'),
        'closed_by_peer' => 'SMSC اتصال را بلافاصله بست: معمولاً یعنی IP سرور شما در SMSC مجاز (whitelist) نشده یا تعداد نشست‌های حساب پر است.',
        'session_limit' => 'نشست اصلی این درگاه همین الان متصل است، پس آدرس، system_id و رمز درست است و درگاه کار می‌کند. SMSC به نشست آزمایشیِ اضافه جواب نداد چون احتمالاً فقط ' . $fa((int)($d['gateway_sessions_bound'] ?? 1)) . ' نشست اجازه می‌دهد.',
        'use_tx_rx' => 'SMSC به اتصال Transceiver جواب نمی‌دهد ولی با Transmitter وصل شد: «نوع اتصال» را «Transmitter + Receiver جدا» بگذارید و ذخیره کنید.',
        'enable_tls', 'not_smpp_tls' => 'این پورت با TLS کار می‌کند: گزینه‌ی «اتصال TLS» را روشن کنید و ذخیره کنید.',
        'not_smpp_http' => 'روی این پورت یک سرویس HTTP است، نه SMPP: پورت اشتباه است.',
        'smsc_ignores_bind' => 'SMSC روی این پورت فعال است و به پیام SMPP جواب می‌دهد، ولی به درخواست اتصال (bind) با این system_id جواب نداد. معمولاً یعنی IP سرور شما برای این حساب مجاز نشده یا system_id / system_type اشتباه است. IP سرور را به اپراتور بدهید.',
        'second_connection_closed' => 'SMSC به درخواست اتصال (bind) جواب نداد و اتصال بعدی را فوراً بست' . (!empty($d['gateway_sessions_paused']) ? ' (نشست‌های خود درگاه در این تست متوقف بودند)' : '') . '. یعنی چیزی که پشت این پورت است اتصال را قبول می‌کند ولی این bind را نمی‌پذیرد. رایج‌ترین دلیل‌ها: ۱) اتصالِ قبلی هنوز در SMSC یا HAProxy باز مانده و حساب فقط یک نشست مجاز دارد؛ HAProxy را ری‌استارت کنید و چند دقیقه بعد دوباره تست کنید. ۲) IP جدید هنوز در SMSC اعمال نشده. ۳) system_id، system_type یا نسخه‌ی پروتکل با تنظیمات اپراتور نمی‌خواند؛ این سه را از اپراتور بپرسید.',
        'silent' => 'اتصال TCP به ' . $where . ' برقرار می‌شود ولی هیچ پاسخ SMPP نمی‌آید (نه به bind، نه به enquire_link؛ TLS هم نیست). رایج‌ترین دلیل‌ها: ۱) IP سرور شما در SMSC مجاز (whitelist) نشده؛ بعضی SMSCها بی‌صدا رد می‌کنند. IP خروجی سرور را به اپراتور بدهید. ۲) پورت اشتباه است. ۳) یک پراکسی یا VPN وسط راه هست که به SMSC اصلی وصل نیست.',
        default => 'اتصال آزمایشی ناموفق بود.',
    };
    $detail = trim((string)($d['error'] ?? ''));
    return [false, 'اتصال آزمایشی ناموفق: ' . $text . ($detail !== '' ? ' — جزئیات: ' . mb_strimwidth($detail, 0, 200, '…') : '')];
}

/** The live view of every gateway/session the bridge runs (null when unreachable). */
function smpp_bridge_status(): ?array {
    $r = smpp_bridge_request('GET', '/v1/status', null, 5000);
    return $r['ok'] ? $r['data'] : null;
}

/* ==========================================================================
   Compile + send
   ========================================================================== */

/** The SMPP settings row for a gateway, or null. */
function smpp_connector_row(int $gatewayId): ?array {
    try {
        $st = db()->prepare('SELECT * FROM ellsms_sms_gateway_smpp_connectors WHERE gateway_id = ?');
        $st->execute([$gatewayId]);
        return $st->fetch() ?: null;
    } catch (PDOException) {
        return null; // 2026_10_06_smpp_gateway.sql not applied
    }
}

/**
 * Called by gateway_compile() for protocol = 'smpp'. Same top-level shape as an HTTP connector so every
 * caller (route resolution, operator support, health checks, the panel) works unchanged; 'send' carries
 * no HTTP request description, only the recipient cap and a smpp:// endpoint for the active TCP health
 * probe. Delivery state arrives as receipts, never by polling, so 'status' is null.
 */
function smpp_gateway_compile(array $gateway): ?array {
    $gatewayId = (int)$gateway['id'];
    $row = smpp_connector_row($gatewayId);
    if ($row === null || trim((string)$row['host']) === '') {
        Logger::error('gateway.compile_failed', ['gateway_id' => $gatewayId, 'reason' => 'no_smpp_connector']);
        return null;
    }
    $st = db()->prepare("SELECT o.id, o.code FROM ellsms_sms_gateway_operators go JOIN ellsms_sms_operators o ON o.id = go.operator_id
                         WHERE go.gateway_id = ? AND go.status = 'active'");
    $st->execute([$gatewayId]);
    $operators = [];
    foreach ($st->fetchAll() as $op) $operators[(int)$op['id']] = (string)$op['code'];
    gateway_counter_increment('compile');
    $empty = ['gateway' => [], 'route' => [], 'operator' => []];
    return [
        'gateway_id' => $gatewayId,
        'gateway_code' => (string)$gateway['code'],
        'config_version' => (int)$gateway['config_version'],
        'is_mock' => (bool)($gateway['is_mock'] ?? false),
        'protocol' => 'smpp',
        'send_mode' => 'batch',
        'send_enabled' => (bool)$gateway['send_enabled'],
        'status_enabled' => false,
        'receive_enabled' => false,
        'operators' => $operators,
        'send' => [
            'endpoint' => 'smpp://' . $row['host'] . ':' . (int)$row['port'],
            'batch' => ['max_recipients' => SMPP_BRIDGE_MAX_RECIPIENTS],
            'parameters' => $empty,
        ],
        'status' => null,
        'receive' => null,
        'smpp' => $row,
    ];
}

/**
 * gateway_send() for an SMPP gateway — same input, same result shape.
 *
 * Besides the shared shape it fills, like the HTTP path:
 *   rejected              recipient => error code (the SMSC's command_status as 0x…, else smpp_<class>)
 *   not_sent              true when NO recipient reached the SMSC and every one may safely be tried again
 *                         (bridge down, gateway not running, no bound session, throttled, deadline)
 *   not_sent_destinations recipient => true for each one that never reached the SMSC and may be retried,
 *                         even when others were accepted — the bulk worker re-queues exactly those
 *                         (bulk_finalize_item()), never an accepted or uncertain one.
 */
function smpp_gateway_send(array $connector, array $input, ?int $routeId, ?int $operatorId = null): array {
    $destinations = array_values(array_map('strval', $input['recipients'] ?? []));
    if ($destinations === []) return gateway_send_failure('no destinations', BackendError::REJECTED);
    if (empty($connector['send_enabled'])) {
        $off = gateway_send_failure('ارسال این درگاه خاموش است.', BackendError::UNAVAILABLE);
        $off['retryable'] = true;
        $off['not_sent'] = true;
        return $off;
    }

    $carried = [];
    $operators = [];
    $rejected = [];
    foreach ($destinations as $destination) {
        $operator = $operatorId !== null ? ['operator_id' => $operatorId] : gateway_resolve_recipient_operator($destination);
        if (!gateway_supports_operator($connector, $operator['operator_id'])) {
            $rejected[$destination] = 'operator_not_carried';
            continue;
        }
        $carried[] = $destination;
        $operators[$destination] = $operator['operator_id'];
    }
    if ($carried === []) return gateway_send_failure('gateway does not carry this operator', BackendError::REJECTED);

    $perRecipient = is_array($input['messages'] ?? null) ? $input['messages'] : null;
    $tps = max(1, (int)($connector['smpp']['tps'] ?? 50));
    $submitTimeoutMs = (int)($connector['smpp']['submit_timeout_ms'] ?? 10000);
    $parts = smpp_send_parts($input, sms_gateway_part_limits((int)$connector['gateway_id']));
    $budgetEnd = microtime(true) + smpp_send_budget_ms() / 1000;
    $sent = [];
    $messageIds = [];
    $notSent = [];
    $lastError = null;
    $lastClass = null;
    $lastHttp = 0;
    $retryable = false;
    $groups = 0;
    $markNotSent = static function (array $recipients, string $error, string $code, bool $retry) use (&$rejected, &$notSent, &$lastError, &$lastClass, &$retryable): void {
        foreach ($recipients as $to) {
            $rejected[$to] = $code;
            if ($retry) $notSent[$to] = true;
        }
        $lastError = $error;
        $lastClass = BackendError::UNAVAILABLE;
        $retryable = $retryable || $retry;
    };
    foreach (array_chunk($carried, SMPP_BRIDGE_MAX_RECIPIENTS) as $chunk) {
        $groups++;
        $budgetLeftMs = (int)floor(($budgetEnd - microtime(true)) * 1000);
        if ($budgetLeftMs < SMPP_MIN_REQUEST_MS) {
            // No time left in this send for another request: these were never submitted.
            $markNotSent($chunk, 'زمان این ارسال تمام شد؛ پیام‌ها به اپراتور فرستاده نشدند.', 'smpp_budget', true);
            continue;
        }
        $timeoutMs = smpp_submit_timeout_ms(count($chunk), $parts, $tps, $budgetLeftMs);
        $body = [
            'gateway_id' => (int)$connector['gateway_id'],
            'sender' => (string)($input['sender'] ?? ''),
            'recipients' => $chunk,
            'message' => (string)($input['message'] ?? ''),
            'deadline_ms' => smpp_submit_deadline_ms($timeoutMs, $submitTimeoutMs),
        ];
        if ($perRecipient !== null) {
            $body['messages'] = array_intersect_key($perRecipient, array_flip($chunk));
        }
        $started = microtime(true);
        $r = smpp_bridge_request('POST', '/v1/submit', $body, $timeoutMs);
        $elapsed = (int)round((microtime(true) - $started) * 1000);
        $lastHttp = $r['http'];
        $tags = ['gateway' => $connector['gateway_code'], 'connector' => 'smpp'];
        if (!$r['ok']) {
            Logger::error('gateway.smpp.submit_failed', ['gateway_id' => $connector['gateway_id'], 'http' => $r['http'], 'error' => $r['error'], 'elapsed_ms' => $elapsed, 'not_sent' => !empty($r['not_sent'])]);
            if (!empty($r['not_sent']) || $r['http'] === 503) {
                // The bridge was not reached, or it does not run this gateway (yet): nothing was submitted.
                $markNotSent($chunk, (string)$r['error'], $r['http'] === 503 ? 'smpp_gateway_not_running' : 'smpp_bridge_unreachable', true);
                Metrics::increment('gateway_send_failure', 1, $tags + ['error_class' => BackendError::UNAVAILABLE]);
                continue;
            }
            $lastError = (string)$r['error'];
            $lastClass = $r['timed_out'] ? BackendError::TIMEOUT : ($r['http'] === 401 ? BackendError::UNAUTHORIZED : ($r['http'] === 400 ? BackendError::REJECTED : BackendError::UNAVAILABLE));
            $retryable = $retryable || BackendError::isRetryable($lastClass);
            foreach ($chunk as $to) $rejected[$to] = $r['timed_out'] ? 'smpp_bridge_timeout' : 'smpp_bridge_http_' . $r['http'];
            Metrics::increment('gateway_send_failure', 1, $tags + ['error_class' => $lastClass]);
            continue;
        }
        $answered = [];
        foreach ((array)($r['data']['results'] ?? []) as $result) {
            $to = (string)($result['recipient'] ?? '');
            if (!in_array($to, $chunk, true)) continue;
            $answered[$to] = true;
            if (!empty($result['ok']) && ($id = gateway_provider_message_id_normalize($result['message_id'] ?? null)) !== null) {
                $sent[] = $to;
                $messageIds[$to] = $id;
                continue;
            }
            $lastError = (string)($result['error'] ?? 'SMPP submit failed');
            $status = $result['command_status'] ?? null;
            if (is_int($status) && smpp_command_status_label($status) !== '') {
                // The text admins and the send report show: what the SMSC said, in Persian, and what to do.
                $lastError = sprintf('اپراتور پیام را رد کرد (0x%08X): ', $status) . smpp_command_status_label($status, true);
            }
            $lastClass = match ((string)($result['error_class'] ?? '')) {
                'timeout' => BackendError::TIMEOUT,
                'unavailable' => BackendError::UNAVAILABLE,
                default => BackendError::REJECTED,
            };
            $rejected[$to] = smpp_result_error_code($result);
            if (!empty($result['not_sent']) && $lastClass === BackendError::UNAVAILABLE) {
                // Never reached the SMSC (no session, throttled, deadline): safe to send again later.
                $notSent[$to] = true;
            }
            $retryable = $retryable || BackendError::isRetryable($lastClass);
        }
        foreach ($chunk as $to) {
            // A recipient the bridge did not answer for is uncertain, never assumed unsent.
            if (!isset($answered[$to])) $rejected[$to] = 'smpp_no_result';
        }
        Logger::info('gateway.request_completed', ['gateway_id' => $connector['gateway_id'], 'config_version' => $connector['config_version'], 'connector' => 'smpp', 'recipients' => count($chunk), 'accepted' => count($sent), 'not_sent' => count($notSent), 'elapsed_ms' => $elapsed, 'timeout_ms' => $timeoutMs]);
        Metrics::timing('gateway_request', $elapsed, $tags + ['result' => $sent !== [] ? 'success' : 'failed']);
        Metrics::increment('gateway_send_total', 1, $tags);
    }
    if ($sent === [] && $lastClass === null) {
        $lastError = 'gateway rejected every destination';
        $lastClass = BackendError::REJECTED;
    }
    foreach ($sent as $to) unset($rejected[$to]);
    $lastCode = $sent === [] && $rejected !== [] ? (string)end($rejected) : null;
    return [
        'ok' => $sent !== [], 'sent' => $sent, 'message_ids' => $messageIds, 'rejected' => $rejected,
        'error' => $sent === [] ? $lastError : null, 'error_class' => $sent === [] ? $lastClass : null,
        'http' => $lastHttp, 'retryable' => $sent === [] ? $retryable : false,
        'not_sent' => $sent === [] && count($notSent) === count($carried),
        'not_sent_destinations' => $notSent,
        'groups' => $groups, 'operators' => $operators,
        'normalized_outcome' => $sent !== [] ? PROVIDER_RESPONSE_SUCCESS : PROVIDER_RESPONSE_FAILED,
        'provider_error_code' => $lastCode, 'provider_error_detail' => $sent === [] ? $lastError : null,
    ];
}

/* ==========================================================================
   Receipts and received messages (ellsms_smpp_events)
   ========================================================================== */

/**
 * Receipt `stat` → ELLSMS delivery state when the gateway's «نگاشت وضعیت رسید تحویل» is empty
 * (2026_11_20_smpp_status_mapping.sql). UNDELIV / EXPIRED are the SMSC's final "did not reach the
 * handset": `undelivered`, which stays charged — the operator bills them. REJECTD / DELETED are refunded
 * (`rejected` / `failed`, docs/delivery-states.md).
 */
const SMPP_DLR_DEFAULT_MAPPING = [
    'DELIVRD' => 'delivered', 'UNDELIV' => 'undelivered', 'EXPIRED' => 'undelivered', 'REJECTD' => 'rejected',
    'DELETED' => 'failed', 'ACCEPTD' => 'accepted', 'ENROUTE' => 'sent', 'UNKNOWN' => 'unknown',
];

/** Long spellings some SMSCs use for the standard receipt states. */
const SMPP_DLR_STAT_ALIASES = ['DELIVERED' => 'DELIVRD', 'UNDELIVERABLE' => 'UNDELIV', 'REJECTED' => 'REJECTD', 'ACCEPTED' => 'ACCEPTD'];

/** Persian meanings of the receipt states, shown to admins next to the raw code («کد سرویس‌دهنده»). */
const SMPP_DLR_STAT_LABELS = [
    'DELIVRD' => 'رسیده به گوشی', 'UNDELIV' => 'نرسیده به گوشی', 'EXPIRED' => 'منقضی شد (نرسیده به گوشی)',
    'REJECTD' => 'رد شده توسط اپراتور', 'DELETED' => 'حذف‌شده در اپراتور', 'ACCEPTD' => 'پذیرفته‌شده', 'ENROUTE' => 'در مسیر',
    'UNKNOWN' => 'نامشخص',
];

function smpp_dlr_stat_normalize(?string $stat): string {
    $stat = strtoupper(trim((string)$stat));
    return SMPP_DLR_STAT_ALIASES[$stat] ?? $stat;
}

/**
 * The gateway's effective receipt mapping: the built-in default with the admin's entries on top.
 *
 * @return array<string, string> STAT => delivery state
 */
function smpp_dlr_mapping(?string $json): array {
    $map = SMPP_DLR_DEFAULT_MAPPING;
    $custom = $json !== null && trim($json) !== '' ? json_decode($json, true) : null;
    if (is_array($custom)) {
        foreach ($custom as $stat => $state) {
            if (is_string($state) && in_array($state, GATEWAY_DELIVERY_STATES, true)) {
                $map[smpp_dlr_stat_normalize((string)$stat)] = $state;
            }
        }
    }
    return $map;
}

/**
 * SMPP receipt "stat" → the delivery state shared with polled HTTP gateways. A stat neither the
 * mapping nor the standard list knows stays `unknown` (not final, charged) and is logged: an SMSC's own
 * token is never guessed to be a failure or a delivery.
 */
function smpp_dlr_canonical(?string $stat, ?array $mapping = null): string {
    $token = smpp_dlr_stat_normalize($stat);
    if ($token === '') return 'unknown';
    $mapping ??= SMPP_DLR_DEFAULT_MAPPING;
    if (isset($mapping[$token])) return $mapping[$token];
    Logger::warning('gateway.smpp.unmapped_stat', ['stat' => mb_substr($token, 0, 20)]);
    Metrics::increment('gateway_status_unmapped_token', 1, ['connector' => 'smpp']);
    return 'unknown';
}

/**
 * Validates the «نگاشت وضعیت رسید تحویل» field: a JSON object {"STAT": "state"}. Returns [error, json]
 * where json is null when the field is empty or equal to the default (so the gateway keeps following
 * the default), else the normalized object.
 *
 * @return array{0: ?string, 1: ?string}
 */
function smpp_dlr_mapping_validate(string $raw): array {
    $raw = trim($raw);
    if ($raw === '') return [null, null];
    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
        return ['نگاشت وضعیت رسید باید یک شیء JSON مثل {"UNDELIV": "undelivered"} باشد.', null];
    }
    $map = [];
    foreach ($decoded as $stat => $state) {
        $stat = smpp_dlr_stat_normalize((string)$stat);
        if (preg_match('/^[A-Z0-9_]{1,20}$/', $stat) !== 1) {
            return ['کد وضعیت «' . mb_substr((string)$stat, 0, 30) . '» معتبر نیست (حروف لاتین، حداکثر ۲۰ نویسه).', null];
        }
        if (!is_string($state) || !in_array($state, GATEWAY_DELIVERY_STATES, true)) {
            return ['وضعیت «' . mb_substr(is_scalar($state) ? (string)$state : '?', 0, 30) . '» برای ' . $stat . ' معتبر نیست. مجاز: ' . implode('، ', GATEWAY_DELIVERY_STATES) . '.', null];
        }
        $map[$stat] = $state;
    }
    $effective = SMPP_DLR_DEFAULT_MAPPING;
    foreach ($map as $stat => $state) $effective[$stat] = $state;
    if ($effective === SMPP_DLR_DEFAULT_MAPPING) return [null, null];
    return [null, json_encode($map, JSON_UNESCAPED_UNICODE)];
}

/**
 * The provider ids a receipt id may correspond to. SMSCs disagree on whether the receipt repeats the
 * submit_sm_resp id as-is, in decimal, or in hex — 'auto' tries all of them, the other formats pin one.
 *
 * @return list<string>
 */
function smpp_message_id_variants(string $id, string $format = 'auto'): array {
    $id = trim($id);
    if ($id === '') return [];
    $out = [];
    if ($format === 'as_is' || $format === 'auto') $out[] = $id;
    if (($format === 'hex_to_dec' || $format === 'auto') && ctype_xdigit($id) && strlen($id) <= 30) {
        $out[] = smpp_base_convert($id, 16, 10);
    }
    if (($format === 'dec_to_hex' || $format === 'auto') && ctype_digit($id) && strlen($id) <= 30) {
        $hex = smpp_base_convert($id, 10, 16);
        $out[] = strtoupper($hex);
        $out[] = strtolower($hex);
    }
    if ($format === 'auto' && ctype_digit($id)) $out[] = ltrim($id, '0') === '' ? '0' : ltrim($id, '0');
    return array_values(array_unique($out));
}

/** Arbitrary-precision base conversion for ids beyond 2^53 (hexdec() would lose digits). */
function smpp_base_convert(string $number, int $from, int $to): string {
    $digits = '0123456789abcdef';
    $number = strtolower($number);
    $result = '';
    $values = array_map(static fn(string $c): int => strpos($digits, $c), str_split($number));
    while ($values !== []) {
        $remainder = 0;
        $next = [];
        foreach ($values as $v) {
            $acc = $remainder * $from + $v;
            $q = intdiv($acc, $to);
            $remainder = $acc % $to;
            if ($next !== [] || $q > 0) $next[] = $q;
        }
        $result = $digits[$remainder] . $result;
        $values = $next;
    }
    return $result === '' ? '0' : $result;
}

/**
 * A received message's destination as ELLSMS stores lines: SMSCs often send it with the country code
 * (98300012 for line 300012). The prefix is removed only when the shorter form is an actual line.
 */
function smpp_mo_destination(string $raw): string {
    $digits = preg_replace('/\D/', '', $raw) ?? '';
    foreach (['0098', '98'] as $prefix) {
        if (str_starts_with($digits, $prefix) && strlen($digits) > strlen($prefix) + 3) {
            $short = substr($digits, strlen($prefix));
            $st = db()->prepare('SELECT 1 FROM ellsms_numbers WHERE number = ? LIMIT 1');
            $st->execute([$short]);
            if ($st->fetchColumn()) return $short;
        }
    }
    return $digits !== '' ? $digits : $raw;
}

/**
 * Applies pending events. A receipt can arrive before the send that produced it has recorded its
 * provider id (the SMSC is fast, the worker commits after the whole batch), so an unmatched receipt is
 * retried with backoff for ~1 hour before it is marked 'unmatched'.
 *
 * @return array{processed: int, updated: int, unchanged: int, unmatched: int, retry: int, mo_stored: int}
 */
function smpp_events_process_pass(int $limit = 500): array {
    $stats = ['processed' => 0, 'updated' => 0, 'unchanged' => 0, 'unmatched' => 0, 'retry' => 0, 'mo_stored' => 0];
    $db = db();
    try {
        $st = $db->prepare(
            'SELECT * FROM ellsms_smpp_events
              WHERE processed_at IS NULL
                AND created_at <= DATE_SUB(NOW(), INTERVAL LEAST(600, attempts * attempts * 10) SECOND)
              ORDER BY id LIMIT ' . max(1, $limit)
        );
        $st->execute();
        $events = $st->fetchAll();
    } catch (PDOException) {
        return $stats; // 2026_10_06_smpp_gateway.sql not applied yet
    }
    $receiptSettings = [];
    foreach ($events as $event) {
        // Claim: two status-workers never apply the same event.
        $claim = $db->prepare("UPDATE ellsms_smpp_events SET processed_at = NOW(), result = 'processing' WHERE id = ? AND processed_at IS NULL");
        $claim->execute([$event['id']]);
        if ($claim->rowCount() !== 1) continue;
        $gatewayId = (int)$event['gateway_id'];
        try {
            if ($event['event_type'] === 'mo') {
                $newId = gateway_receive_store($db, $gatewayId, [
                    'originator' => mb_substr(preg_replace('/\D/', '', (string)$event['source']) ?: (string)$event['source'], 0, 20),
                    'destination' => mb_substr(smpp_mo_destination((string)$event['destination']), 0, 20),
                    'content' => (string)$event['content'],
                    'received_at' => (string)$event['received_at'],
                    'provider_message_id' => 'smpp-event:' . $event['id'],
                ]);
                $result = $newId !== null ? 'stored' : 'duplicate';
                if ($newId !== null) {
                    $stats['mo_stored']++;
                    Metrics::increment('gateway.inbound.stored', 1, ['gateway' => 'smpp:' . $gatewayId]);
                }
            } else {
                if (!isset($receiptSettings[$gatewayId])) {
                    $row = smpp_connector_row($gatewayId);
                    $receiptSettings[$gatewayId] = [(string)($row['dlr_id_format'] ?? 'auto'), smpp_dlr_mapping($row['status_mapping_json'] ?? null)];
                }
                [$format, $mapping] = $receiptSettings[$gatewayId];
                $result = smpp_apply_receipt($gatewayId, (string)$event['message_id'], (string)$event['dlr_stat'], $event['done_at'], $format, $mapping);
                if ($result === 'unmatched' && (int)$event['attempts'] < 20) {
                    $db->prepare('UPDATE ellsms_smpp_events SET processed_at = NULL, result = NULL, attempts = attempts + 1 WHERE id = ?')->execute([$event['id']]);
                    $stats['retry']++;
                    continue;
                }
                $stats[$result]++;
            }
            $db->prepare('UPDATE ellsms_smpp_events SET result = ? WHERE id = ?')->execute([$result, $event['id']]);
            $stats['processed']++;
        } catch (Throwable $t) {
            Logger::error('gateway.smpp.event_failed', ['event_id' => $event['id'], 'exception' => $t]);
            $db->prepare('UPDATE ellsms_smpp_events SET processed_at = NULL, result = NULL, attempts = attempts + 1 WHERE id = ?')->execute([$event['id']]);
        }
    }
    return $stats;
}

/** @return 'updated'|'unchanged'|'unmatched' */
function smpp_apply_receipt(int $gatewayId, string $messageId, string $stat, ?string $doneAt, string $format = 'auto', ?array $mapping = null): string {
    $ids = smpp_message_id_variants($messageId, $format);
    if ($ids === []) return 'unmatched';
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $rows = [];
    $st = db()->prepare("SELECT id, delivery_status FROM ellsms_bulk_items WHERE gateway_id = ? AND provider_message_id IN ({$placeholders})");
    $st->execute(array_merge([$gatewayId], $ids));
    foreach ($st->fetchAll() as $r) $rows[] = ['bulk_item', (int)$r['id'], $r['delivery_status']];
    $st = db()->prepare("SELECT id, delivery_status FROM ellsms_message_attempts WHERE gateway_id = ? AND provider_message_id IN ({$placeholders})");
    $st->execute(array_merge([$gatewayId], $ids));
    foreach ($st->fetchAll() as $r) $rows[] = ['attempt', (int)$r['id'], $r['delivery_status']];
    if ($rows === []) return 'unmatched';
    $next = smpp_dlr_canonical($stat, $mapping);
    $deliveredAt = $next === 'delivered' ? ($doneAt ?: date('Y-m-d H:i:s')) : null;
    $changed = false;
    foreach ($rows as [$source, $rowId, $current]) {
        $changed = gateway_status_record($source, $rowId, $current !== null ? (string)$current : null, $next, $deliveredAt, smpp_dlr_stat_normalize($stat)) || $changed;
    }
    return $changed ? 'updated' : 'unchanged';
}

/* ==========================================================================
   Panel: SMPP settings
   ========================================================================== */

/** Defaults for a new SMPP gateway (also the form's initial values). */
function smpp_connector_defaults(): array {
    return [
        'host' => '', 'port' => 2775, 'use_tls' => 0, 'system_id' => '', 'system_type' => '', 'interface_version' => '3.4',
        'bind_mode' => 'trx', 'session_count' => 1, 'tps' => 50, 'window_size' => 10, 'enquire_link_s' => 30,
        'reconnect_delay_s' => 5, 'submit_timeout_ms' => 10000, 'source_ton' => 5, 'source_npi' => 0, 'dest_ton' => 1,
        'dest_npi' => 1, 'data_coding' => 'auto', 'long_message' => 'udh', 'registered_delivery' => 1,
        'validity_minutes' => 0, 'destination_format' => 'international', 'dlr_id_format' => 'auto', 'receive_enabled' => 1,
        'status_mapping_json' => null,
    ];
}

/**
 * Validates the SMPP settings form. Returns [errors, row]. Field limits are the SMPP 3.4 ones
 * (system_id ≤ 15, password ≤ 8, system_type ≤ 12 octets) — a longer value is refused by the SMSC
 * (and by jSMPP before it is even sent), so it is caught here instead of as a bind failure later.
 *
 * @return array{0: array<string, string>, 1: array}
 */
function smpp_connector_validate(array $in): array {
    $errors = [];
    $row = smpp_connector_defaults();
    $row['host'] = trim((string)($in['host'] ?? ''));
    if (filter_var($row['host'], FILTER_VALIDATE_IP) === false && preg_match('/^[A-Za-z0-9]([A-Za-z0-9.\-]{0,188})$/', $row['host']) !== 1) {
        $errors['host'] = 'آدرس سرور SMPP باید نام دامنه یا IP باشد.';
    }
    $int = static function (string $key, int $min, int $max) use ($in, &$row, &$errors): void {
        $v = filter_var($in[$key] ?? null, FILTER_VALIDATE_INT);
        if ($v === false || $v < $min || $v > $max) {
            $errors[$key] = "مقدار باید بین {$min} و {$max} باشد.";
            return;
        }
        $row[$key] = $v;
    };
    $int('port', 1, 65535);
    $int('session_count', 1, 10);
    $int('tps', 1, 5000);
    $int('window_size', 1, 500);
    $int('enquire_link_s', 5, 600);
    $int('reconnect_delay_s', 1, 300);
    $int('submit_timeout_ms', 1000, 120000);
    $int('source_ton', 0, 6);
    $int('source_npi', 0, 18);
    $int('dest_ton', 0, 6);
    $int('dest_npi', 0, 18);
    $int('validity_minutes', 0, 10080);
    $row['system_id'] = trim((string)($in['system_id'] ?? ''));
    if ($row['system_id'] === '' || strlen($row['system_id']) > 15) $errors['system_id'] = 'system_id لازم است (حداکثر ۱۵ نویسه).';
    $row['system_type'] = trim((string)($in['system_type'] ?? ''));
    if (strlen($row['system_type']) > 12) $errors['system_type'] = 'system_type حداکثر ۱۲ نویسه است.';
    $enum = static function (string $key, array $allowed) use ($in, &$row): void {
        $v = (string)($in[$key] ?? '');
        if (in_array($v, $allowed, true)) $row[$key] = $v;
    };
    $enum('interface_version', ['3.4', '5.0']);
    $enum('bind_mode', ['trx', 'tx_rx', 'tx']);
    $enum('data_coding', ['auto', 'gsm7', 'ucs2', 'latin1']);
    $enum('long_message', ['udh', 'sar', 'payload']);
    $enum('destination_format', ['international', 'national', 'as_is']);
    $enum('dlr_id_format', ['auto', 'as_is', 'hex_to_dec', 'dec_to_hex']);
    $row['use_tls'] = empty($in['use_tls']) ? 0 : 1;
    $row['registered_delivery'] = empty($in['registered_delivery']) ? 0 : 1;
    $row['receive_enabled'] = empty($in['receive_enabled']) ? 0 : 1;
    [$mappingError, $row['status_mapping_json']] = smpp_dlr_mapping_validate((string)($in['status_mapping_json'] ?? ''));
    if ($mappingError !== null) $errors['status_mapping_json'] = $mappingError;
    $password = (string)($in['password'] ?? '');
    if (strlen($password) > 8) $errors['password'] = 'رمز SMPP حداکثر ۸ نویسه است (محدودیت پروتکل).';
    return [$errors, $row];
}

/** Inserts or updates the SMPP settings row. The password goes to the encrypted vault, not here. */
function smpp_connector_save(int $gatewayId, array $row): void {
    $cols = array_keys(smpp_connector_defaults());
    $values = array_map(static fn(string $c) => $row[$c], $cols);
    $update = implode(', ', array_map(static fn(string $c): string => "{$c} = VALUES({$c})", $cols));
    db()->prepare('INSERT INTO ellsms_sms_gateway_smpp_connectors (gateway_id, ' . implode(', ', $cols) . ') VALUES (?' . str_repeat(',?', count($cols)) . ") ON DUPLICATE KEY UPDATE {$update}")
        ->execute(array_merge([$gatewayId], $values));
}

/** Session rows the bridge published for one gateway (the monitoring card). */
function smpp_sessions_for_gateway(int $gatewayId): array {
    try {
        $st = db()->prepare('SELECT *, TIMESTAMPDIFF(SECOND, updated_at, NOW()) AS age_s FROM ellsms_smpp_sessions WHERE gateway_id = ? ORDER BY session_key');
        $st->execute([$gatewayId]);
        return $st->fetchAll();
    } catch (PDOException) {
        return [];
    }
}

/** Receipt/MO counts for the monitoring card (last 24 hours). */
function smpp_event_stats(int $gatewayId): array {
    try {
        $st = db()->prepare(
            "SELECT
                SUM(event_type = 'dlr') AS dlr,
                SUM(event_type = 'dlr' AND dlr_stat = 'DELIVRD') AS dlr_delivered,
                SUM(event_type = 'dlr' AND dlr_stat IN ('UNDELIV','UNDELIVERABLE','EXPIRED','REJECTD','REJECTED','DELETED')) AS dlr_failed,
                SUM(event_type = 'dlr' AND result = 'unmatched') AS dlr_unmatched,
                SUM(event_type = 'mo') AS mo,
                SUM(processed_at IS NULL) AS pending
               FROM ellsms_smpp_events WHERE gateway_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)"
        );
        $st->execute([$gatewayId]);
        return array_map('intval', $st->fetch() ?: []);
    } catch (PDOException) {
        return [];
    }
}
