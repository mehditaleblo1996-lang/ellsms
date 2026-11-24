<?php
/**
 * PishgamRayan's "smart SMS" web service — the provider behind LBA gateways (app/Lba.php, docs/lba.md).
 *
 * The contract, from PishgamRayan's own guide ("راهنمای مرجع و جامع وب‌سرویس ارسال پیامک هوشمند",
 * revision 2.8):
 *   {base}/SmartSms/<Method>               base: https://smsapi.pishgamrayan.com
 *   header  Authorization: <token>         (no "Bearer"); the calling server's IP must be whitelisted
 *   body    JSON, camelCase; GET methods take query parameters
 *   answer  {"statusCode": int, "message": string, "validationErrors": [...]|null, ...}; 0 = success.
 *           The list methods (GetCurrentOrganization*, GetProvinces, ...) may answer a bare array.
 *   dates   Jalali, "YYYY/MM/DD HH:MM:SS" (Tehran time)
 *   operators 1 = MCI (همراه اول), 2 = Irancell, 3 = Rightel
 *
 * The token and the optional panel username/password are stored encrypted per gateway
 * (ellsms_lba_gateway_secrets, AES-256-GCM, a key derived from SMS_GATEWAY_MASTER_KEY under its own
 * purpose label). The username/password are kept only for the admin's reference: this API uses the
 * token alone. Nothing secret is ever logged: failures log the method and the HTTP status.
 */

declare(strict_types=1);

const LBA_PISHGAM_DEFAULT_BASE_URL = 'https://smsapi.pishgamrayan.com';
const LBA_SECRET_PURPOSE = 'ellsms.lba_gateway.secret.v1';
const LBA_SECRET_KEYS = ['token', 'username', 'password'];

const LBA_OPERATORS = [1 => 'همراه اول', 2 => 'ایرانسل', 3 => 'رایتل'];

/** Every statusCode of the guide's reference table (pages 44–45). */
const LBA_STATUS_MESSAGES = [
    0  => 'موفقیت‌آمیز',
    1  => 'ارسال تکی انجام شده است.',
    2  => 'ارسال نظیر به نظیر انجام شده است.',
    4  => 'تعداد گیرندگان با تعداد پیامک همخوانی ندارد.',
    5  => 'متن پیامک دارای کلمه(کلمات) فیلترشده است.',
    6  => 'ارسال لینک در متن پیام مجاز نیست.',
    7  => 'لیست گیرندگان خالی است.',
    8  => 'سازمان یافت نشد، غیرفعال است، احراز هویت نشده یا ارسال آن غیرفعال است.',
    9  => 'سازمان غیرفعال است.',
    10 => 'اعتبار زمانی سازمان به پایان رسیده است.',
    11 => 'سازمان جاری یا یکی از سازمان‌های والد غیرفعال است، احراز هویت نشده یا ارسال آن غیرفعال است.',
    12 => 'زمان ارسال خارج از بازه‌ی مجاز سازمان است.',
    13 => 'ارسال کاربر غیرفعال است.',
    14 => 'حساب کاربری منقضی شده است.',
    15 => 'اطلاعات کاربر پیدا نشد، احراز هویت انجام نشده یا کاربر غیرفعال است.',
    16 => 'سازمان والد منقضی شده است.',
    17 => 'شماره فرستنده یا عامل ارسال معتبر یا فعال نیست، ارسال غیرفعال است یا خط منقضی شده است.',
    18 => 'شماره فرستنده غیرفعال است.',
    19 => 'شماره فرستنده منقضی شده است.',
    20 => 'سرویس عامل ارسال در دسترس نیست.',
    21 => 'خطا در ثبت در صف ارسال.',
    22 => 'لیست گیرندگان با توجه به لیست سیاه خالی است.',
    23 => 'تعداد گیرندگان با توجه به بخش‌های پیامک بیشتر از ۱۰۰ است.',
    24 => 'متن پیامک خالی است.',
    25 => 'ناموفق.',
    26 => 'تعداد گیرندگان بیشتر از ۱۰۰ است.',
    27 => 'خطا در ثبت در صف.',
    29 => 'پارامترها با پیام تعریف‌شده همخوانی ندارد.',
    30 => 'پارامترهای پیام نامعتبر است.',
    31 => 'اطلاعات قالب پیامک احراز هویت یافت نشد.',
    32 => 'ارسال پیامک احراز هویت انجام شده است.',
    33 => 'توکن در هدر درخواست ارسال نشده است.',
    34 => 'توکن پیدا نشد، اشتباه است یا هنوز تأیید نشده است.',
    35 => 'آی‌پی این سرور در پنل پیشگام رایان ثبت یا تأیید نشده است.',
    36 => 'خطا در حذف لیست سیاه.',
    37 => 'تاریخ به اشتباه وارد شده است.',
    38 => 'خطا در بررسی استثناها.',
    39 => 'خطا در دریافت اطلاعات توکن.',
    40 => 'خط امکان ارسال صوتی ندارد.',
    41 => 'ارسال رمز یک‌بار مصرف صوتی انجام شد.',
    42 => 'کد دلخواه باید حداقل ۴ و حداکثر ۸ رقم باشد.',
    43 => 'شماره همراه یا ثابت وارد نشده است.',
    44 => 'شماره فرستنده وارد نشده است.',
    45 => 'خطا در کسر هزینه.',
    46 => 'خطا در ثبت در صندوق ارسال و حذف از صف.',
    47 => 'عنوان وارد نشده است.',
    48 => 'فایل وارد نشده است.',
    49 => 'فرمت فایل معتبر نیست.',
    50 => 'آپلود فایل صوتی انجام شد.',
    51 => 'فایل صوتی تأیید نشده است.',
    52 => 'شناسه‌ی صوتی یافت نشد.',
    53 => 'حداکثر تلاش باید بزرگ‌تر از صفر باشد.',
    54 => 'خطا در آپلود فایل صوتی.',
    55 => 'نوع اپراتور ارسال‌کننده صحیح نیست.',
    56 => 'زمان ارسال خارج از بازه‌ی مجاز عامل ارسال پیامک است.',
    57 => 'در ارسال نظیر به نظیر، همه‌ی متن‌ها کلمه‌ی فیلترشده یا لینک دارند.',
    58 => 'کمپین (لیست خاکستری) پیدا نشد.',
    59 => 'باشگاه هوشمند پیدا نشد.',
    60 => 'در هر بار حداکثر ۱۰۰ شماره به باشگاه اضافه می‌شود.',
    61 => 'خطای اعتبارسنجی.',
    62 => 'دسترسی به این متد مجاز نیست.',
    63 => 'درخواست ارسال هوشمند پیدا نشد.',
    64 => 'وضعیت درخواست ارسال هوشمند برای این کار معتبر نیست.',
    65 => 'اپراتور انتخابی معتبر نیست.',
    66 => 'موجودی حساب پیشگام رایان کافی نیست.',
    67 => 'گروه صدا یافت نشد.',
    69 => 'فایل شبکه‌ی اجتماعی وجود ندارد، تأیید نشده یا متعلق به سازمان نیست.',
];

/** Smart request states (guide page 46). */
const LBA_REQUEST_STATES = [0 => 'تکمیل شده', 1 => 'در انتظار', 2 => 'ناموفق', 3 => 'غیرمجاز', 4 => 'منقضی شده', 5 => 'فعال', 6 => 'غیرفعال',
    7 => 'بخشی از درخواست تکمیل شده', 8 => 'تمام شده'];

/** Delivery states of the statistics methods (guide page 46). */
const LBA_DELIVERY_STATES = [1 => 'رسیده به گوشی', 2 => 'نرسیده به گوشی', 3 => 'ارسال شده به مخابرات', 4 => 'نرسیده به مخابرات', 5 => 'لیست سیاه', 6 => 'خط ارسال‌کننده نامعتبر'];

class LbaSecretException extends AppException {}

function lba_status_message(int $code): string {
    return LBA_STATUS_MESSAGES[$code] ?? ('خطای پیشگام رایان (کد ' . $code . ')');
}

/** The derived encryption key, or null without SMS_GATEWAY_MASTER_KEY (≥ 32 characters). */
function lba_secret_key(): ?string {
    $master = (string)env('SMS_GATEWAY_MASTER_KEY', '');
    if (strlen($master) < 32) {
        return null;
    }
    return hash_hkdf('sha256', $master, 32, LBA_SECRET_PURPOSE);
}

function lba_secret_fingerprint(?string $key): string {
    return $key === null ? '' : substr(hash('sha256', $key . '|fingerprint'), 0, 16);
}

/** Encrypts and stores one secret of a gateway; '' removes it. */
function lba_secret_put(int $gatewayId, string $name, string $plaintext): void {
    if (!in_array($name, LBA_SECRET_KEYS, true)) {
        throw new LbaSecretException('کلید محرمانه‌ی نامعتبر.');
    }
    if ($plaintext === '') {
        db()->prepare('DELETE FROM ellsms_lba_gateway_secrets WHERE gateway_id = ? AND secret_key = ?')->execute([$gatewayId, $name]);
        return;
    }
    $key = lba_secret_key();
    if ($key === null) {
        throw new LbaSecretException('برای ذخیره‌ی توکن، SMS_GATEWAY_MASTER_KEY (حداقل ۳۲ نویسه) باید تنظیم شده باشد.');
    }
    $nonce = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($ciphertext === false) {
        throw new LbaSecretException('رمزگذاری توکن ممکن نشد.');
    }
    db()->prepare('INSERT INTO ellsms_lba_gateway_secrets (gateway_id, secret_key, ciphertext, nonce, tag, key_fingerprint) VALUES (?,?,?,?,?,?)
                   ON DUPLICATE KEY UPDATE ciphertext = VALUES(ciphertext), nonce = VALUES(nonce), tag = VALUES(tag), key_fingerprint = VALUES(key_fingerprint)')
       ->execute([$gatewayId, $name, $ciphertext, $nonce, $tag, lba_secret_fingerprint($key)]);
    Logger::info('lba.gateway.secret_changed', ['gateway_id' => $gatewayId, 'secret_key' => $name]);
}

/** The decrypted secret, or '' when absent or encrypted under another master key. */
function lba_secret_get(int $gatewayId, string $name): string {
    $key = lba_secret_key();
    if ($key === null) {
        return '';
    }
    $st = db()->prepare('SELECT ciphertext, nonce, tag, key_fingerprint FROM ellsms_lba_gateway_secrets WHERE gateway_id = ? AND secret_key = ?');
    $st->execute([$gatewayId, $name]);
    $row = $st->fetch();
    if (!$row || ($row['key_fingerprint'] !== '' && $row['key_fingerprint'] !== lba_secret_fingerprint($key))) {
        return '';
    }
    $plain = openssl_decrypt((string)$row['ciphertext'], 'aes-256-gcm', $key, OPENSSL_RAW_DATA, (string)$row['nonce'], (string)$row['tag']);
    return $plain === false ? '' : $plain;
}

/** @return array<string,bool> which secrets a gateway has stored (never their values) */
function lba_secrets_stored(int $gatewayId): array {
    $st = db()->prepare('SELECT secret_key FROM ellsms_lba_gateway_secrets WHERE gateway_id = ?');
    $st->execute([$gatewayId]);
    $stored = array_fill_keys(LBA_SECRET_KEYS, false);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $name) $stored[(string)$name] = true;
    return $stored;
}

/** A field of an answer, matched case-insensitively (the guide mixes `Title` / `title`). */
function lba_field(mixed $data, string $name): mixed {
    if (!is_array($data)) {
        return null;
    }
    if (array_key_exists($name, $data)) {
        return $data[$name];
    }
    foreach ($data as $k => $v) {
        if (is_string($k) && strcasecmp($k, $name) === 0) return $v;
    }
    return null;
}

/**
 * One call to the smart SMS API.
 *
 * ok is true only for an HTTP 2xx answer whose statusCode is 0 (or that has no statusCode at all, as the
 * list methods answer). transport_error is true when the provider's outcome is unknown (no answer, a
 * non-2xx status, or an unreadable body) — the request may still have been carried out.
 *
 * @return array{ok:bool, http:int, data:mixed, status_code:?int, error:?string, validation_errors:list<string>, transport_error:bool}
 */
function lba_pishgam_call(array $gateway, string $httpMethod, string $method, array $query = [], ?array $body = null): array {
    $fail = static fn(string $error, int $http = 0, bool $transport = false, mixed $data = null, ?int $code = null, array $validation = []): array =>
        ['ok' => false, 'http' => $http, 'data' => $data, 'status_code' => $code, 'error' => $error, 'validation_errors' => $validation, 'transport_error' => $transport];

    $token = lba_secret_get((int)$gateway['id'], 'token');
    if ($token === '') {
        return $fail('توکن درگاه LBA ذخیره نشده یا با کلید فعلی قابل خواندن نیست.');
    }
    $base = rtrim((string)($gateway['base_url'] ?: LBA_PISHGAM_DEFAULT_BASE_URL), '/');
    $url = $base . '/SmartSms/' . $method;
    $query = array_filter($query, static fn($v): bool => $v !== null && $v !== '');
    if ($query !== []) {
        $url .= '?' . http_build_query($query);
    }
    $check = gateway_endpoint_allowed($url);
    if (!$check['ok']) {
        return $fail('آدرس درگاه LBA مجاز نیست (' . $check['reason'] . ').');
    }
    $headers = ['Authorization: ' . $token, 'Accept: application/json'];
    $ch = curl_init($url);
    $options = [
        CURLOPT_CUSTOMREQUEST => strtoupper($httpMethod), CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT_MS => 5000, CURLOPT_TIMEOUT_MS => 60000, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
    ];
    if ($body !== null) {
        $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers[] = 'Content-Type: application/json';
    }
    $options[CURLOPT_HTTPHEADER] = $headers;
    if ($check['resolve'] !== []) $options[CURLOPT_RESOLVE] = $check['resolve'];
    curl_setopt_array($ch, $options);
    $started = microtime(true);
    $raw = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    Metrics::timing('lba.provider.duration', (microtime(true) - $started) * 1000, ['method' => $method]);

    $data = is_string($raw) && $raw !== '' ? json_decode($raw, true, 512, JSON_BIGINT_AS_STRING) : null;
    $code = is_array($data) && lba_field($data, 'statusCode') !== null && is_numeric(lba_field($data, 'statusCode')) ? (int)lba_field($data, 'statusCode') : null;
    $validation = [];
    foreach ((array)(is_array($data) ? (lba_field($data, 'validationErrors') ?? lba_field($data, 'errors') ?? []) : []) as $k => $v) {
        foreach ((array)$v as $msg) {
            if (is_scalar($msg) && (string)$msg !== '') $validation[] = (is_string($k) ? $k . ': ' : '') . (string)$msg;
        }
    }
    // A failed call is kept for the admin's «جزئیات فنی» (lba_debug_record): request without the token, raw answer.
    $record = static function (array $result) use ($gateway, $httpMethod, $method, $query, $body, $raw, $started): array {
        lba_debug_record($gateway, $httpMethod, $method, $query, $body, $result, is_string($raw) ? $raw : null, (int)round((microtime(true) - $started) * 1000));
        return $result;
    };
    if ($raw === false || $http === 0) {
        Logger::warning('lba.provider.no_answer', ['gateway_id' => (int)$gateway['id'], 'method' => $method]);
        Metrics::increment('lba.provider.calls', 1, ['method' => $method, 'result' => 'transport']);
        return $record($fail('درگاه LBA پاسخ نداد.', 0, true));
    }
    if ($http < 200 || $http >= 300) {
        Logger::warning('lba.provider.http_error', ['gateway_id' => (int)$gateway['id'], 'method' => $method, 'http' => $http, 'status_code' => $code]);
        Metrics::increment('lba.provider.calls', 1, ['method' => $method, 'result' => 'http_' . $http]);
        // A 4xx that carries a statusCode is a definite refusal; anything else leaves the outcome unknown.
        if ($code !== null && $http >= 400 && $http < 500) {
            return $record($fail(lba_status_message($code), $http, false, $data, $code, $validation));
        }
        if ($http === 401 || $http === 403) {
            return $record($fail('درگاه LBA درخواست را نپذیرفت (HTTP ' . $http . '): توکن یا آی‌پی سرور را بررسی کنید.', $http, false, $data));
        }
        return $record($fail('درگاه LBA خطا داد (HTTP ' . $http . ').', $http, $http >= 500, $data, $code, $validation));
    }
    if (!is_array($data)) {
        Metrics::increment('lba.provider.calls', 1, ['method' => $method, 'result' => 'unreadable']);
        return $record($fail('پاسخ درگاه LBA قابل خواندن نبود.', $http, true));
    }
    if ($code !== null && $code !== 0) {
        Metrics::increment('lba.provider.calls', 1, ['method' => $method, 'result' => 'status_' . $code]);
        return $record($fail(lba_status_message($code), $http, false, $data, $code, $validation));
    }
    Metrics::increment('lba.provider.calls', 1, ['method' => $method, 'result' => 'ok']);
    return ['ok' => true, 'http' => $http, 'data' => $data, 'status_code' => $code, 'error' => null, 'validation_errors' => [], 'transport_error' => false];
}

/** The error text for a failed call, with the provider's validation errors when it gave some. */
function lba_call_error(array $r): string {
    $error = (string)($r['error'] ?? 'خطای ناشناخته');
    $providerMessage = is_array($r['data'] ?? null) ? trim((string)(lba_field($r['data'], 'message') ?? '')) : '';
    if ($providerMessage !== '' && !str_contains($error, $providerMessage) && ($r['status_code'] ?? null) !== null) {
        $error .= ' — ' . $providerMessage;
    }
    if (($r['validation_errors'] ?? []) !== []) {
        $error .= ' (' . implode('؛ ', array_slice($r['validation_errors'], 0, 8)) . ')';
    }
    return $error;
}

/** How many failed calls «جزئیات فنی» keeps per gateway. */
const LBA_DEBUG_KEEP = 50;

/**
 * Keeps one failed provider call for the admin's «جزئیات فنی» (ellsms_lba_call_log,
 * db/migrations/2026_11_27_lba_call_log.sql): the method, query and JSON body as sent — the token is a
 * header and is never stored — and the HTTP status, statusCode and raw answer. Never breaks the call:
 * without the table (migration not applied) or on any database error it does nothing.
 */
function lba_debug_record(array $gateway, string $httpMethod, string $method, array $query, ?array $body, array $result, ?string $raw, int $durationMs): void {
    try {
        $gid = (int)$gateway['id'];
        $uid = !empty($_SESSION['uid']) ? (int)$_SESSION['uid'] : null;
        $json = static fn($v): ?string => $v === null || $v === [] ? null : (string)json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $bodyJson = $json($body);
        db()->prepare('INSERT INTO ellsms_lba_call_log (gateway_id, user_id, http_method, method, query_json, body_json, http_status, status_code, error, response_raw, duration_ms)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?)')
           ->execute([$gid, $uid, strtoupper($httpMethod), mb_substr($method, 0, 80), $json($query),
               $bodyJson === null ? null : mb_strcut($bodyJson, 0, 2000000), (int)$result['http'], $result['status_code'],
               mb_strimwidth((string)$result['error'], 0, 1000, '…'), $raw === null ? null : mb_strcut(mb_convert_encoding($raw, 'UTF-8', 'UTF-8'), 0, 200000), max(0, $durationMs)]);
        $st = db()->prepare('SELECT id FROM ellsms_lba_call_log WHERE gateway_id = ? ORDER BY id DESC LIMIT 1 OFFSET ' . LBA_DEBUG_KEEP);
        $st->execute([$gid]);
        $oldest = $st->fetchColumn();
        if ($oldest !== false) db()->prepare('DELETE FROM ellsms_lba_call_log WHERE gateway_id = ? AND id <= ?')->execute([$gid, (int)$oldest]);
    } catch (Throwable) {
        // The log is a convenience; the call's own result is what matters.
    }
}

/** @return list<array> the newest failed calls of a gateway, newest first */
function lba_debug_list(int $gatewayId, int $limit = 20): array {
    try {
        $st = db()->prepare('SELECT * FROM ellsms_lba_call_log WHERE gateway_id = ? ORDER BY id DESC LIMIT ' . max(1, min($limit, LBA_DEBUG_KEEP)));
        $st->execute([$gatewayId]);
        return $st->fetchAll();
    } catch (Throwable) {
        return [];
    }
}

/** The newest failed call of a gateway and method since a moment, or null. */
function lba_debug_last(int $gatewayId, string $method, int $sinceId = 0): ?array {
    $rows = lba_debug_since($gatewayId, $method, $sinceId);
    return $rows === [] ? null : $rows[count($rows) - 1];
}

/** @return list<array> the failed calls of a gateway and method since a moment, oldest first (at most 10) */
function lba_debug_since(int $gatewayId, string $method, int $sinceId): array {
    try {
        $st = db()->prepare('SELECT * FROM ellsms_lba_call_log WHERE gateway_id = ? AND method = ? AND id > ? ORDER BY id DESC LIMIT 10');
        $st->execute([$gatewayId, $method, $sinceId]);
        return array_reverse($st->fetchAll());
    } catch (Throwable) {
        return [];
    }
}

/** The highest log id so far (0 when empty, -1 without the table): taken before a call, it tells which entry the call wrote. */
function lba_debug_mark(): int {
    try {
        return (int)db()->query('SELECT COALESCE(MAX(id), 0) FROM ellsms_lba_call_log')->fetchColumn();
    } catch (Throwable) {
        return -1;
    }
}

/**
 * A logged call as two texts to read or hand to PishgamRayan's support: the HTTP request (method, URL,
 * JSON body — the Authorization header shown as a placeholder) and the answer (HTTP status, raw body).
 *
 * @return array{request:string, response:string}
 */
function lba_debug_texts(array $row, array $gateway): array {
    $base = rtrim((string)($gateway['base_url'] ?: LBA_PISHGAM_DEFAULT_BASE_URL), '/');
    $query = json_decode((string)($row['query_json'] ?? ''), true);
    $url = $base . '/SmartSms/' . $row['method'] . (is_array($query) && $query !== [] ? '?' . http_build_query($query) : '');
    $request = $row['http_method'] . ' ' . $url . "\nAuthorization: <token>\n";
    if ($row['body_json'] !== null && $row['body_json'] !== '') {
        $request .= "Content-Type: application/json\n\n" . lba_debug_pretty((string)$row['body_json']);
    }
    $response = (int)$row['http_status'] === 0 ? '(no answer — ' . $row['error'] . ')' : 'HTTP ' . (int)$row['http_status'] . "\n\n" . lba_debug_pretty($row['response_raw'] ?? '');
    return ['request' => $request, 'response' => $response];
}

/** A JSON text pretty-printed for reading, or the text as it is when it is not JSON. */
function lba_debug_pretty(?string $text): string {
    if ($text === null || $text === '') return '';
    $data = json_decode($text, true);
    if (!is_array($data)) return $text;
    $pretty = (string)json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    // A polygon point on one line: [51.38, 35.7] rather than four.
    return (string)preg_replace('/\[\s*(-?[\d.]+),\s*(-?[\d.]+)\s*\]/', '[$1, $2]', $pretty);
}

/** The list a list method answered: a bare array, or the array under data / result / items. */
function lba_list_of(mixed $data): array {
    if (!is_array($data)) return [];
    if (array_is_list($data)) return $data;
    foreach (['data', 'result', 'items'] as $k) {
        $v = lba_field($data, $k);
        if (is_array($v) && array_is_list($v)) return $v;
    }
    return [];
}

/** @return list<array{id:int,name:string}>|string a GetProvinces / GetCities / ... list, or an error */
function lba_pishgam_lookup(array $gateway, string $method, array $query = []): array|string {
    $r = lba_pishgam_call($gateway, 'GET', $method, $query);
    if (!$r['ok']) return lba_call_error($r);
    $out = [];
    foreach (lba_list_of($r['data']) as $row) {
        if (!is_array($row)) continue;
        $id = lba_field($row, 'id');
        $name = lba_field($row, 'name') ?? lba_field($row, 'title');
        if (is_numeric($id)) $out[] = ['id' => (int)$id, 'name' => trim((string)$name)];
    }
    return $out;
}
