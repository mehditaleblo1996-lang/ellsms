<?php
/**
 * ELLSMS — sends SMS by calling the backend platform's REST API
 * (POST {API_BASE_URL}/api/messages/send), the same endpoint the very
 * first curl example for this project used. The API itself performs the
 * gateway call and writes the resulting rows into the shared
 * `outbound_message` table — ELLSMS reads those rows back from the
 * response instead of writing them itself, so there's a single source
 * of truth for what was actually sent.
 *
 * Request:
 *   { "sender_user_id": 1, "originator": 5000435800,
 *     "destinations": ["989..."], "content": "..." }
 * Response: a JSON array, one object per destination —
 *   { "id", "sender_user_id", "originator", "destination", "content",
 *     "reference_id", "status", "error_code", "sent_at",
 *     "delivered_at", "delivery_status_code" }
 *   status is "sent" on success or "send_failed" on failure.
 *
 * If the API itself can't be reached at all (network down, wrong URL),
 * ELLSMS falls back to writing its own "send_failed" rows directly so
 * the attempt is still visible in the report — but on a normal failure
 * response from the API, nothing is double-written.
 *
 * Delivery reports and inbound (MO) messages are received by the
 * backend platform's own /delivery and /mo endpoints straight into the
 * shared outbound_message/inbound_message tables — ELLSMS only reads
 * them, it does not need its own webhook for those.
 */

require_once __DIR__ . '/bootstrap.php';

/** Low-level call to the backend API. Returns [ok, httpCode, decodedBodyOrNull, rawError]. */
function backend_api_send(int $senderUserId, string $originator, array $destinations, string $content): array {
    $base = rtrim((string)setting('api_base_url', env('API_BASE_URL', '')), '/');
    if ($base === '') {
        return [false, 0, null, 'آدرس API تنظیم نشده است — آن را در بخش تنظیمات وارد کنید.'];
    }
    $url = $base . '/api/messages/send';

    $payload = json_encode([
        'sender_user_id' => $senderUserId,
        'originator'     => ctype_digit($originator) ? (int)$originator : $originator,
        'destinations'   => array_values(array_map('strval', $destinations)),
        'content'        => $content,
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($body === false) {
        return [false, 0, null, $err ?: 'برقراری اتصال ناموفق بود.'];
    }
    $decoded = json_decode($body, true);
    $ok = $code >= 200 && $code < 300 && is_array($decoded);
    return [$ok, $code, $ok ? $decoded : null, $ok ? null : (is_string($body) ? substr($body, 0, 1000) : 'unexpected response')];
}

/**
 * Turn a failed API response into a readable message. FastAPI validation
 * errors (HTTP 422) come back as {"detail": [{"loc": [...], "msg": "..."}]}
 * — this pulls the field name and reason out instead of just showing the
 * HTTP status code, which on its own doesn't say what was actually wrong.
 */
function describe_api_error(int $http, ?string $rawBody): string {
    if ($http === 0) {
        return $rawBody ?: 'اتصال به API برقرار نشد.';
    }
    $decoded = $rawBody ? json_decode($rawBody, true) : null;
    if (is_array($decoded['detail'] ?? null)) {
        $parts = [];
        foreach ($decoded['detail'] as $d) {
            $field = is_array($d['loc'] ?? null) ? end($d['loc']) : null;
            $msg   = $d['msg'] ?? null;
            if ($field && $msg) $parts[] = "{$field}: {$msg}";
        }
        if ($parts) return 'درخواست توسط API رد شد (HTTP ' . $http . '): ' . implode('؛ ', $parts) . '.';
    } elseif (is_string($decoded['detail'] ?? null)) {
        return 'درخواست توسط API رد شد (HTTP ' . $http . '): ' . $decoded['detail'] . '.';
    }
    return "API پاسخ HTTP {$http} را برگرداند" . ($rawBody ? ': ' . mb_strimwidth($rawBody, 0, 200, '…') : '.') . '.';
}

/**
 * Send a message batch for a user: credit check, API call, and (only if
 * the API itself was unreachable) a fallback row per destination.
 * Returns [ok, infoMessage].
 */
function dispatch_message(array $user, string $originator, array $destinations, string $content, ?int $scheduleId = null): array {
    if (!$destinations)          return [false, 'شماره مقصد معتبری وارد نشده است.'];
    if (trim($content) === '')   return [false, 'متن پیام خالی است.'];

    $originator = normalize_originator($originator);
    if ($originator === null) return [false, 'خط ارسال‌کننده خالی یا غیرعددی است — آن را بالا یا در تنظیمات وارد کنید.'];

    $parts = sms_parts($content);
    $cost  = $parts * count($destinations);

    if ($user['role'] !== 'admin' && (float)$user['credit'] < $cost) {
        return [false, "اعتبار کافی نیست: این ارسال به {$cost} واحد اعتبار نیاز دارد، اعتبار فعلی شما " . (int)$user['credit'] . ' است.'];
    }

    [$reached, $http, $rows, $err] = backend_api_send((int)$user['id'], $originator, $destinations, $content);

    if (!$reached) {
        // Couldn't get a usable success response — write our own failed
        // rows so the attempt is still visible in the report, and surface
        // the real reason instead of a generic HTTP-code message.
        $db = db();
        $ins = $db->prepare(
            'INSERT INTO outbound_message (sender_user_id, originator, destination, content, status, error_code, sent_at)
             VALUES (?,?,?,?,?,?, NOW())'
        );
        foreach ($destinations as $dest) {
            $ins->execute([$user['id'], $originator, $dest, $content, 'send_failed', -501]);
        }
        return [false, describe_api_error($http, $err) . ' جزئیات در گزارش موجود است.'];
    }

    $sentCount = 0;
    foreach ($rows as $r) {
        if (($r['status'] ?? '') === 'sent') $sentCount++;
    }
    $allOk = $sentCount === count($destinations);

    if ($allOk && $user['role'] !== 'admin') {
        db()->prepare('UPDATE user_ SET currentcredit = currentcredit - ? WHERE id = ?')
           ->execute([$cost, $user['id']]);
    } elseif ($sentCount > 0 && $user['role'] !== 'admin') {
        // Partial success — only charge for the parts that actually sent.
        db()->prepare('UPDATE user_ SET currentcredit = currentcredit - ? WHERE id = ?')
           ->execute([$parts * $sentCount, $user['id']]);
    }

    if ($allOk) {
        return [true, 'به ' . to_persian_digits((string)count($destinations)) . " شماره ارسال شد — {$parts} بخش برای هرکدام، " . to_persian_digits((string)($parts * $sentCount)) . ' واحد اعتبار.'];
    }
    if ($sentCount > 0) {
        return [true, 'به ' . to_persian_digits((string)$sentCount) . ' از ' . to_persian_digits((string)count($destinations)) . ' شماره ارسال شد — برای مشاهده‌ی موارد ناموفق به گزارش مراجعه کنید.'];
    }
    return [false, 'گیت‌وی همه‌ی مقصدها را رد کرد. جزئیات در گزارش موجود است.'];
}

/** Process due scheduled messages. Returns number processed. Used by the worker. */
function run_due_schedules(): int {
    $db = db();
    $due = $db->query("SELECT * FROM ellsms_schedule
                       WHERE status = 'active' AND run_at <= NOW()
                       ORDER BY run_at ASC LIMIT 20")->fetchAll();
    $n = 0;
    foreach ($due as $s) {
        $claim = $db->prepare("UPDATE ellsms_schedule SET status='processing' WHERE id=? AND status='active'");
        $claim->execute([$s['id']]);
        if ($claim->rowCount() === 0) continue;

        $ust = $db->prepare(
            'SELECT u.id, u.active, u.deleted, u.currentcredit AS credit, m.is_admin, m.panel_access
             FROM user_ u JOIN ellsms_meta m ON m.user_id = u.id WHERE u.id = ?'
        );
        $ust->execute([$s['user_id']]);
        $row = $ust->fetch();

        $dests = json_decode($s['destinations'], true) ?: [];
        if ($row && $row['active'] && !$row['deleted']) {
            $user = ['id' => $row['id'], 'role' => $row['is_admin'] ? 'admin' : 'user', 'credit' => $row['credit']];
            [$ok, $info] = dispatch_message($user, $s['originator'], $dests, $s['content'], (int)$s['id']);
        } else {
            [$ok, $info] = [false, 'حساب کاربری وجود ندارد، غیرفعال است، یا دیگر دسترسی پنل ندارد.'];
        }

        $next = null;
        if ($s['repeat_type'] !== 'none') {
            $iv = ['daily' => '+1 day', 'weekly' => '+1 week', 'monthly' => '+1 month'][$s['repeat_type']];
            $t  = strtotime($s['run_at']);
            while ($t <= time()) $t = strtotime($iv, $t);
            $next = date('Y-m-d H:i:s', $t);
        }

        $db->prepare('UPDATE ellsms_schedule SET status=?, run_at=COALESCE(?, run_at),
                      last_run_at=NOW(), last_result=?, run_count=run_count+1 WHERE id=?')
           ->execute([$next ? 'active' : 'done', $next, ($ok ? 'موفق: ' : 'ناموفق: ') . $info, $s['id']]);
        $n++;
    }
    return $n;
}

/**
 * منشی پیامک — SMS auto-responder.
 *
 * Scans `inbound_message` for rows newer than a saved cursor
 * (ellsms_settings.autoreply_last_inbound_id), matches each one against
 * active ellsms_autoreply_rules for the line it arrived on, and sends a
 * templated reply through dispatch_message() for the first rule that
 * matches. The cursor always advances past every row seen, matched or
 * not, so nothing is ever reprocessed even if it triggered no rule.
 *
 * Two safeguards against duplicate replies:
 *  - Each inbound_message_id is claimed with an INSERT protected by a
 *    UNIQUE key before sending — if two worker passes ever raced on the
 *    same row (e.g. a slow pass overlapping the next tick, or more than
 *    one worker container running), only the first claim wins.
 *  - A short per-(rule, sender) cooldown skips sending again if this
 *    rule already replied to the same number very recently — this is
 *    what actually protects against a gateway delivering the same
 *    physical SMS more than once (each delivery becomes its own,
 *    distinct inbound_message row with its own id, so the claim above
 *    can't catch it; the cooldown can).
 *
 * Returns the number of replies actually sent.
 */
const AUTOREPLY_COOLDOWN_SECONDS = 120;

function run_autoreply_pass(): int {
    $db = db();
    $lastId = (int)setting('autoreply_last_inbound_id', '0');

    $rows = $db->prepare('SELECT * FROM inbound_message WHERE id > ? ORDER BY id ASC LIMIT 100');
    $rows->execute([$lastId]);
    $rows = $rows->fetchAll();
    if (!$rows) return 0;

    $maxId = $lastId;
    $sent  = 0;

    foreach ($rows as $msg) {
        $maxId = max($maxId, (int)$msg['id']);
        try {
            autoreply_process_one($db, $msg, $sent);
        } catch (Throwable $t) {
            // Never let one bad row block the cursor from moving past it —
            // that was the actual root cause of duplicate replies before
            // this fix: an exception here left the cursor stuck, so the
            // same row kept getting re-fetched and re-sent every tick.
            error_log('[ellsms autoreply] row ' . $msg['id'] . ' failed: ' . $t->getMessage());
        }
    }

    set_setting('autoreply_last_inbound_id', (string)$maxId);
    return $sent;
}

/** Process a single inbound_message row: match, claim, cooldown-check, send. Throws on real errors — caller isolates them per-row. */
function autoreply_process_one(PDO $db, array $msg, int &$sent): void {
    $line   = normalize_originator((string)$msg['destination']); // our line that received it
    $sender = normalize_originator((string)$msg['originator']);  // the customer's number
    $content = trim((string)$msg['content']);
    if (!$line || !$sender || $line === $sender) return; // malformed row / self-loop

    $rst = $db->prepare(
        "SELECT * FROM ellsms_autoreply_rules
         WHERE originator = ? AND is_active = 1
         ORDER BY FIELD(match_type,'exact','starts_with','contains'), id
         LIMIT 20"
    );
    $rst->execute([$line]);
    $rule = null;
    foreach ($rst->fetchAll() as $candidate) {
        if (autoreply_matches($content, $candidate['keyword'], $candidate['match_type'])) {
            $rule = $candidate;
            break;
        }
    }
    if (!$rule) return;

    // Claim this specific inbound row first — if another pass already
    // claimed it (duplicate key), skip without sending anything.
    try {
        $claim = $db->prepare(
            'INSERT INTO ellsms_autoreply_log (rule_id, inbound_message_id, sender, originator, reply_content, ok, info)
             VALUES (?,?,?,?,?,0,?)'
        );
        $claim->execute([$rule['id'], $msg['id'], $sender, $line, '', 'در حال پردازش']);
        $logId = (int)$db->lastInsertId();
    } catch (PDOException $e) {
        return; // duplicate key on inbound_message_id => already claimed
    }

    // Cooldown — this same rule already replied to this same number very
    // recently, so treat this as a duplicate delivery / repeat send
    // rather than firing again.
    $cd = $db->prepare(
        "SELECT COUNT(*) c FROM ellsms_autoreply_log
         WHERE rule_id = ? AND sender = ? AND ok = 1
           AND created_at >= (NOW() - INTERVAL ? SECOND) AND id <> ?"
    );
    $cd->execute([$rule['id'], $sender, AUTOREPLY_COOLDOWN_SECONDS, $logId]);
    if ((int)$cd->fetch()['c'] > 0) {
        $db->prepare('UPDATE ellsms_autoreply_log SET info = ? WHERE id = ?')
           ->execute(['رد شد: به‌تازگی برای همین شماره ارسال شده بود', $logId]);
        return;
    }

    $ust = $db->prepare(
        'SELECT u.id, u.active, u.deleted, u.currentcredit AS credit, m.is_admin
         FROM user_ u JOIN ellsms_meta m ON m.user_id = u.id WHERE u.id = ?'
    );
    $ust->execute([$rule['user_id']]);
    $owner = $ust->fetch();
    if (!$owner || !$owner['active'] || $owner['deleted']) {
        $db->prepare('UPDATE ellsms_autoreply_log SET info = ? WHERE id = ?')
           ->execute(['رد شد: حساب مالک قانون غیرفعال است', $logId]);
        return;
    }

    $rendered = autoreply_render($rule['reply_content'], (int)$rule['user_id'], $sender, $line, $content, $rule['keyword']);
    $user = ['id' => $owner['id'], 'role' => $owner['is_admin'] ? 'admin' : 'user', 'credit' => $owner['credit']];
    [$ok, $info] = dispatch_message($user, $line, [$sender], $rendered);

    $db->prepare('UPDATE ellsms_autoreply_rules SET hit_count = hit_count + 1 WHERE id = ?')->execute([$rule['id']]);
    $db->prepare('UPDATE ellsms_autoreply_log SET reply_content = ?, ok = ?, info = ? WHERE id = ?')
       ->execute([$rendered, (int)$ok, $info, $logId]);

    if ($ok) $sent++;
}

function autoreply_matches(string $content, string $keyword, string $matchType): bool {
    $c = mb_strtolower(trim(from_persian_digits($content)));
    $k = mb_strtolower(trim(from_persian_digits($keyword)));
    if ($k === '') return false;
    return match ($matchType) {
        'starts_with' => str_starts_with($c, $k),
        'contains'    => str_contains($c, $k),
        default       => $c === $k, // 'exact'
    };
}

/** Substitute {sender} {originator} {name} {date} {time} {keyword} + per-user custom {vars}. */
function autoreply_render(string $template, int $ownerUserId, string $sender, string $originator, string $incomingContent, string $keyword): string {
    $now = date('Y-m-d H:i:s');
    $vars = [
        'sender'     => $sender,
        'originator' => $originator,
        'date'       => jdate($now, false),
        'time'       => to_persian_digits(date('H:i')),
        'keyword'    => $keyword,
        'message'    => $incomingContent,
    ];

    $cst = db()->prepare('SELECT name FROM ellsms_contacts WHERE user_id = ? AND mobile = ? LIMIT 1');
    $cst->execute([$ownerUserId, $sender]);
    $vars['name'] = (string)($cst->fetchColumn() ?: '');

    $vst = db()->prepare('SELECT var_name, var_value FROM ellsms_autoreply_variables WHERE user_id = ?');
    $vst->execute([$ownerUserId]);
    foreach ($vst->fetchAll() as $v) {
        $vars[$v['var_name']] = $v['var_value'];
    }

    $out = $template;
    foreach ($vars as $k => $v) {
        $out = str_replace('{' . $k . '}', $v, $out);
    }
    return $out;
}

/* ==========================================================================
   SMS-based two-factor login
   ========================================================================== */

/** Generate a fresh 6-digit code for $userId, store it, and text it to $mobile. Returns [ok, info]. */
function send_2fa_code(int $userId, string $mobile): array {
    $mobile = normalize_msisdn($mobile) ?? '';
    if ($mobile === '') {
        return [false, 'شماره موبایل معتبری برای این حساب ثبت نشده — از مدیر بخواهید آن را اصلاح کند.'];
    }

    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    db()->prepare('INSERT INTO ellsms_2fa_codes (user_id, code, expires_at) VALUES (?,?, DATE_ADD(NOW(), INTERVAL ? SECOND))')
       ->execute([$userId, $code, TWOFA_CODE_TTL_SECONDS]);

    $originator = normalize_originator(setting('default_originator', '') ?? '') ?? '';
    if ($originator === '') {
        return [false, 'خط ارسال پیش‌فرض تنظیم نشده — از مدیر بخواهید آن را در تنظیمات مشخص کند.'];
    }
    $text = "کد ورود شما به ELLSMS: {$code}\nاین کد تا ۵ دقیقه معتبر است.";

    // System message — sent under the target user's own id but with role
    // forced to 'admin' here only to bypass dispatch_message()'s credit
    // check, since logging in shouldn't cost the user SMS credit.
    [$ok, $info] = dispatch_message(['id' => $userId, 'role' => 'admin', 'credit' => 0], $originator, [$mobile], $text);
    return [$ok, $ok ? 'کد ارسال شد.' : $info];
}

/** Verify a submitted 2FA code for $userId; marks it consumed on success. */
function verify_2fa_code(int $userId, string $code): bool {
    $code = from_persian_digits(trim($code));
    $st = db()->prepare(
        "SELECT id FROM ellsms_2fa_codes
         WHERE user_id = ? AND code = ? AND consumed = 0 AND expires_at > NOW()
         ORDER BY id DESC LIMIT 1"
    );
    $st->execute([$userId, $code]);
    $row = $st->fetch();
    if (!$row) return false;
    db()->prepare('UPDATE ellsms_2fa_codes SET consumed = 1 WHERE id = ?')->execute([$row['id']]);
    return true;
}

/* ==========================================================================
   Real account creation
   Reuses the backend's own POST /api/users/ endpoint (rest_api/routers/
   users.py) instead of ELLSMS writing directly into user_ — that endpoint
   already knows the exact required columns, applies the same password
   hashing every other login on the platform expects, and lets the real
   UNIQUE constraints (username/email/code) produce a clean 409 on
   conflict instead of ELLSMS having to guess at that logic itself.
   ========================================================================== */

/**
 * Create a real backend account. $data must match CreateUserRequest in
 * rest_api/routers/users.py: username, password, first_name, last_name,
 * email, mobile (int), national_id, domain_id (int), gender
 * ('MALE'|'FEMALE'), code, daily_limit, min_credit_notify,
 * limit_time_from, limit_time_to.
 * Returns [ok, message, createdUserOrNull].
 */
function backend_create_account(array $data): array {
    $base = rtrim((string)setting('api_base_url', env('API_BASE_URL', '')), '/');
    if ($base === '') {
        return [false, 'آدرس API تنظیم نشده است — آن را در بخش تنظیمات وارد کنید.', null];
    }
    $url = $base . '/api/users/';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($data, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 20,
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($body === false) {
        return [false, $err ?: 'اتصال به API برقرار نشد.', null];
    }
    $decoded = json_decode($body, true);

    if ($code === 201 && is_array($decoded)) {
        return [true, 'حساب ساخته شد.', $decoded];
    }

    return [false, describe_api_error($code, is_string($body) ? $body : null), null];
}
