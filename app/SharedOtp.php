<?php
/**
 * Shared OTP (docs/shared-otp.md) — one-time codes sent from the platform's own shared lines, the
 * panel's counterpart of Negar's predefined messages (PreDefinedMessage + sendParameterizedMessage).
 *
 * A customer never names a sender. They register a fixed text with {name} placeholders, a platform
 * admin approves it, and from then on they send it by passing only the values:
 *
 *     template  «کد ورود شما به فروشگاه: {code}»   (approved)
 *     request   {"template_id": 12, "destination": "0912…", "parameters": {"code": "4821"}}
 *     sent      «کد ورود شما به فروشگاه: 4821»     from one of shared_otp_lines
 *
 * The approval is what makes a shared line safe to lend: the fixed text cannot change without going
 * back to review, and each value is short, single-line and link-free (shared_otp_render()), so the
 * variables cannot be used to send an advertisement from the platform's lines.
 *
 * Sending goes through dispatch_message() like every other single send — the same quota, wallet
 * reservation, server-side pricing (message type 'otp', the shared line's route), prohibited-word
 * check and report rows. The only difference is the sender: the shared line is granted to this one
 * dispatch (shared_otp_with_line()), so the customer needs no line of their own, and the line is
 * never added to what they may pick on the send pages. The line for a recipient is chosen by a hash
 * of the number (a recipient keeps seeing codes from the same line); when that line's gateway cannot
 * take the message, the next line is tried.
 *
 * Settings (ellsms_settings, read fresh so a running process sees an admin change):
 *   shared_otp_enabled               '1' to offer the service
 *   shared_otp_lines                 the shared sender lines, comma or newline separated
 *   shared_otp_min_interval_seconds  minimum gap between two codes to one number from one customer (60)
 *
 * Schema: db/migrations/2026_10_25_shared_otp.sql.
 */

declare(strict_types=1);

const SHARED_OTP_REF_TYPE = 'shared_otp';
const SHARED_OTP_MAX_VARIABLES = 5;
const SHARED_OTP_MAX_CONTENT_CHARS = 500;
const SHARED_OTP_MAX_VALUE_CHARS = 30;
const SHARED_OTP_VARIABLE_PATTERN = '/\{([A-Za-z_][A-Za-z0-9_]{0,29})\}/';
const SHARED_OTP_STATUSES = ['pending', 'approved', 'rejected', 'disabled'];

function shared_otp_setting(string $key, string $default = ''): string {
    return (string)setting_fresh($key, $default);
}

function shared_otp_now(): string {
    return date('Y-m-d H:i:s');
}

/** Pure: the configured lines, normalized, de-duplicated, in the admin's order. */
function shared_otp_parse_lines(string $raw): array {
    $lines = [];
    foreach (preg_split('/[\s,،]+/u', from_persian_digits($raw)) ?: [] as $part) {
        $line = normalize_originator($part);
        if ($line !== null) $lines[$line] = true;
    }
    return array_map('strval', array_keys($lines));
}

function shared_otp_lines(): array {
    return shared_otp_parse_lines(shared_otp_setting('shared_otp_lines'));
}

function shared_otp_enabled(): bool {
    return shared_otp_setting('shared_otp_enabled', '0') === '1';
}

/** Offered to customers: switched on AND at least one line to send from. */
function shared_otp_configured(): bool {
    try {
        return shared_otp_enabled() && shared_otp_lines() !== [];
    } catch (PDOException) {
        return false;
    }
}

function shared_otp_min_interval_seconds(): int {
    return max(0, (int)shared_otp_setting('shared_otp_min_interval_seconds', '60'));
}

/**
 * Pure: the order lines are tried in for $destination — starting from a line picked by a hash of the
 * number, then the rest in the admin's order. The same recipient always starts on the same line.
 */
function shared_otp_line_order(array $lines, string $destination): array {
    $lines = array_values($lines);
    $n = count($lines);
    if ($n <= 1) return $lines;
    $start = crc32($destination) % $n;
    return array_merge(array_slice($lines, $start), array_slice($lines, 0, $start));
}

/* ==========================================================================
   The line grant — how one dispatch may send from a line the customer does not own
   ========================================================================== */

/**
 * Runs $send with $line granted as a sender for exactly that call: dispatch_message_raw() then
 * accepts $line for a user who has no line of their own (shared_otp_granted_line()). The grant lives
 * only inside this call — cleared in `finally` — so nothing else in the process ever sees it.
 */
function shared_otp_with_line(string $line, callable $send): mixed {
    $previous = $GLOBALS['__ellsms_shared_otp_line'] ?? null;
    $GLOBALS['__ellsms_shared_otp_line'] = normalize_originator(from_persian_digits($line));
    try {
        return $send();
    } finally {
        $GLOBALS['__ellsms_shared_otp_line'] = $previous;
    }
}

/** The line shared_otp_with_line() is currently granting, or null outside such a call. */
function shared_otp_granted_line(): ?string {
    $line = $GLOBALS['__ellsms_shared_otp_line'] ?? null;
    return is_string($line) && $line !== '' ? $line : null;
}

/* ==========================================================================
   Templates
   ========================================================================== */

/**
 * Pure: validates a template's text and lists its placeholders in order of appearance.
 *
 * @return array{ok:bool, variables?:list<string>, error?:string, error_code?:string}
 */
function shared_otp_template_parse(string $content): array {
    $content = str_replace("\r\n", "\n", trim($content));
    if ($content === '') {
        return ['ok' => false, 'error_code' => 'empty', 'error' => 'متن قالب خالی است.'];
    }
    if (mb_strlen($content) > SHARED_OTP_MAX_CONTENT_CHARS) {
        return ['ok' => false, 'error_code' => 'too_long', 'error' => 'متن قالب حداکثر ' . to_persian_digits((string)SHARED_OTP_MAX_CONTENT_CHARS) . ' نویسه است.'];
    }
    // A brace that is not part of a valid {name} is a typo the customer should hear about, not text.
    $leftover = (string)preg_replace(SHARED_OTP_VARIABLE_PATTERN, '', $content);
    if (str_contains($leftover, '{') || str_contains($leftover, '}')) {
        return ['ok' => false, 'error_code' => 'bad_variable', 'error' => 'نام متغیر فقط حروف لاتین، رقم و _ است، مثل {code} یا {name}.'];
    }
    preg_match_all(SHARED_OTP_VARIABLE_PATTERN, $content, $m);
    $variables = array_values(array_unique($m[1]));
    if ($variables === []) {
        return ['ok' => false, 'error_code' => 'no_variable', 'error' => 'قالب باید دست‌کم یک متغیر مثل {code} داشته باشد.'];
    }
    if (count($variables) > SHARED_OTP_MAX_VARIABLES) {
        return ['ok' => false, 'error_code' => 'too_many_variables', 'error' => 'قالب حداکثر ' . to_persian_digits((string)SHARED_OTP_MAX_VARIABLES) . ' متغیر می‌تواند داشته باشد.'];
    }
    // The fixed text is what the admin approves; a template that is nothing but placeholders would let
    // the caller write the whole message.
    $fixed = trim((string)preg_replace(SHARED_OTP_VARIABLE_PATTERN, '', $content));
    if (mb_strlen($fixed) < 5) {
        return ['ok' => false, 'error_code' => 'no_fixed_text', 'error' => 'قالب باید متن ثابت داشته باشد (مثلاً نام کسب‌وکار)، نه فقط متغیر.'];
    }
    return ['ok' => true, 'variables' => $variables];
}

/** @return array{0:string, 1:list<mixed>} WHERE fragment and parameters: the organization's templates, or the user's own */
function shared_otp_scope_sql(array $user, string $a): array {
    if (($user['role'] ?? null) === 'admin') {
        return ['1=1', []];
    }
    $orgId = (int)($user['organization_id'] ?? 0);
    return $orgId > 0 ? ["{$a}.organization_id = ?", [$orgId]] : ["{$a}.user_id = ?", [(int)$user['id']]];
}

function shared_otp_template_get(int $id): ?array {
    $st = db()->prepare("SELECT * FROM ellsms_otp_templates WHERE id = ? AND status <> 'deleted'");
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** A template $user may see/send, or null — another organization's id answers exactly like a missing one. */
function shared_otp_template_find(array $user, int $id): ?array {
    [$scope, $params] = shared_otp_scope_sql($user, 't');
    $st = db()->prepare("SELECT t.* FROM ellsms_otp_templates t WHERE t.id = ? AND t.status <> 'deleted' AND {$scope}");
    $st->execute([$id, ...$params]);
    return $st->fetch() ?: null;
}

function shared_otp_templates_list(array $user, ?string $status = null, int $limit = 500): array {
    [$scope, $params] = shared_otp_scope_sql($user, 't');
    $where = "t.status <> 'deleted' AND {$scope}";
    if ($status !== null && in_array($status, SHARED_OTP_STATUSES, true)) {
        $where .= ' AND t.status = ?';
        $params[] = $status;
    }
    $limit = max(1, $limit);
    $st = db()->prepare("SELECT t.* FROM ellsms_otp_templates t WHERE {$where} ORDER BY t.id DESC LIMIT {$limit}");
    $st->execute($params);
    return $st->fetchAll();
}

function shared_otp_variables(array $template): array {
    $vars = json_decode((string)($template['variables'] ?? '[]'), true);
    return is_array($vars) ? array_values(array_map('strval', $vars)) : [];
}

function shared_otp_title_clean(string $title): string {
    return mb_substr(trim((string)preg_replace('/\s+/u', ' ', $title)), 0, 190);
}

/** @return array{ok:bool, id?:int, error?:string, error_code?:string} */
function shared_otp_template_create(array $user, string $title, string $content): array {
    $title = shared_otp_title_clean($title);
    if ($title === '') {
        return ['ok' => false, 'error_code' => 'title_required', 'error' => 'عنوان قالب را وارد کنید.'];
    }
    $content = str_replace("\r\n", "\n", trim($content));
    $parsed = shared_otp_template_parse($content);
    if (!$parsed['ok']) return $parsed;
    if (content_policy_violation($content) !== null) {
        return ['ok' => false, 'error_code' => 'prohibited_content', 'error' => CONTENT_POLICY_ERROR];
    }
    $now = shared_otp_now();
    db()->prepare("INSERT INTO ellsms_otp_templates (organization_id, user_id, title, content, variables, status, created_at, updated_at)
                   VALUES (?,?,?,?,?, 'pending', ?, ?)")
        ->execute([((int)($user['organization_id'] ?? 0)) ?: null, (int)$user['id'], $title, $content,
                   json_encode($parsed['variables']), $now, $now]);
    $id = (int)db()->lastInsertId();
    audit((int)$user['id'], 'shared_otp.template_create', '#' . $id);
    return ['ok' => true, 'id' => $id];
}

/** An edit always goes back to review — an approved text is the one the admin read, never a later one. */
function shared_otp_template_update(array $user, int $id, string $title, string $content): array {
    $template = shared_otp_template_find($user, $id);
    if ($template === null) {
        return ['ok' => false, 'error_code' => 'not_found', 'error' => 'قالب پیدا نشد.'];
    }
    $title = shared_otp_title_clean($title);
    if ($title === '') {
        return ['ok' => false, 'error_code' => 'title_required', 'error' => 'عنوان قالب را وارد کنید.'];
    }
    $content = str_replace("\r\n", "\n", trim($content));
    $parsed = shared_otp_template_parse($content);
    if (!$parsed['ok']) return $parsed;
    if (content_policy_violation($content) !== null) {
        return ['ok' => false, 'error_code' => 'prohibited_content', 'error' => CONTENT_POLICY_ERROR];
    }
    $changed = $content !== (string)$template['content'];
    db()->prepare('UPDATE ellsms_otp_templates SET title = ?, content = ?, variables = ?, status = ?, review_note = ?, reviewed_by = ?, reviewed_at = ?, updated_at = ? WHERE id = ?')
        ->execute([$title, $content, json_encode($parsed['variables']),
                   $changed ? 'pending' : (string)$template['status'],
                   $changed ? null : $template['review_note'],
                   $changed ? null : $template['reviewed_by'],
                   $changed ? null : $template['reviewed_at'],
                   shared_otp_now(), $id]);
    audit((int)$user['id'], 'shared_otp.template_update', '#' . $id . ($changed ? ' (back to review)' : ''));
    return ['ok' => true, 'id' => $id, 'status' => $changed ? 'pending' : (string)$template['status']];
}

function shared_otp_template_delete(array $user, int $id): array {
    if (shared_otp_template_find($user, $id) === null) {
        return ['ok' => false, 'error_code' => 'not_found', 'error' => 'قالب پیدا نشد.'];
    }
    db()->prepare("UPDATE ellsms_otp_templates SET status = 'deleted', updated_at = ? WHERE id = ?")->execute([shared_otp_now(), $id]);
    audit((int)$user['id'], 'shared_otp.template_delete', '#' . $id);
    return ['ok' => true];
}

/**
 * Platform admin: approve, reject (a note is required, the customer sees it) or disable a template.
 *
 * @return array{ok:bool, error?:string, error_code?:string}
 */
function shared_otp_template_review(int $adminUserId, int $id, string $decision, string $note = ''): array {
    $template = shared_otp_template_get($id);
    if ($template === null) {
        return ['ok' => false, 'error_code' => 'not_found', 'error' => 'قالب پیدا نشد.'];
    }
    $status = match ($decision) {
        'approve' => 'approved',
        'reject'  => 'rejected',
        'disable' => 'disabled',
        default   => null,
    };
    if ($status === null) {
        return ['ok' => false, 'error_code' => 'invalid_decision', 'error' => 'تصمیم نامعتبر است.'];
    }
    $note = mb_substr(trim($note), 0, 500);
    if ($status === 'rejected' && $note === '') {
        return ['ok' => false, 'error_code' => 'note_required', 'error' => 'دلیل رد قالب را بنویسید؛ مشتری آن را می‌بیند.'];
    }
    $now = shared_otp_now();
    db()->prepare('UPDATE ellsms_otp_templates SET status = ?, review_note = ?, reviewed_by = ?, reviewed_at = ?, updated_at = ? WHERE id = ?')
        ->execute([$status, $note !== '' ? $note : null, $adminUserId, $now, $now, $id]);
    audit($adminUserId, 'shared_otp.template_' . $decision, '#' . $id . ($note !== '' ? ' — ' . $note : ''));

    if ($status !== 'disabled') {
        try {
            notification_insert_panel(
                (int)$template['user_id'],
                $template['organization_id'] !== null ? (int)$template['organization_id'] : null,
                'shared_otp.template_reviewed',
                $status === 'approved' ? 'قالب کد تایید تأیید شد' : 'قالب کد تایید رد شد',
                '«' . (string)$template['title'] . '» ' . ($status === 'approved' ? 'تأیید شد و قابل ارسال است.' : 'رد شد: ' . $note),
                '/otp-templates.php',
                $status === 'approved' ? 'success' : 'warning'
            );
        } catch (Throwable $t) {
            Logger::warning('shared_otp.notification_failed', ['template_id' => $id, 'exception' => $t]);
        }
    }
    return ['ok' => true];
}

/* ==========================================================================
   Rendering and sending
   ========================================================================== */

/**
 * Pure: fills $template's placeholders from $params — a list (in the template's variable order, as
 * Negar's positional `*` parameters were) or a name => value map. Every variable must be given and
 * nothing else; each value is 1..SHARED_OTP_MAX_VALUE_CHARS characters on one line, without a link.
 *
 * @return array{ok:bool, content?:string, fields?:array<string, list<string>>}
 */
function shared_otp_render(string $content, array $variables, array $params): array {
    $fields = [];
    if (array_is_list($params) && $params !== []) {
        if (count($params) !== count($variables)) {
            return ['ok' => false, 'fields' => ['parameters' => ['expected_' . count($variables) . '_values']]];
        }
        $params = array_combine($variables, $params);
    }
    $values = [];
    foreach ($variables as $name) {
        if (!array_key_exists($name, $params)) {
            $fields['parameters.' . $name] = ['required'];
            continue;
        }
        $value = $params[$name];
        if (!is_string($value) && !is_int($value)) {
            $fields['parameters.' . $name] = ['must_be_string'];
            continue;
        }
        $value = trim((string)$value);
        if ($value === '') {
            $fields['parameters.' . $name] = ['required'];
        } elseif (mb_strlen($value) > SHARED_OTP_MAX_VALUE_CHARS) {
            $fields['parameters.' . $name] = ['too_long — max ' . SHARED_OTP_MAX_VALUE_CHARS . ' characters'];
        } elseif (preg_match('/[\x00-\x1F\x7F\x{2028}\x{2029}]/u', $value)) {
            $fields['parameters.' . $name] = ['single_line_only'];
        } elseif (preg_match('#(https?:|www\.|://)|\b[a-z0-9-]+\.(ir|com|net|org|me|link|xyz|info|co)\b#iu', $value)) {
            $fields['parameters.' . $name] = ['links_not_allowed'];
        } elseif (str_contains($value, '{') || str_contains($value, '}')) {
            $fields['parameters.' . $name] = ['invalid_characters'];
        } else {
            $values[$name] = $value;
        }
    }
    foreach (array_keys($params) as $name) {
        if (!in_array((string)$name, $variables, true)) {
            $fields['parameters.' . $name] = ['unknown_variable'];
        }
    }
    if ($fields !== []) {
        return ['ok' => false, 'fields' => $fields];
    }
    $out = $content;
    foreach ($values as $name => $value) {
        $out = str_replace('{' . $name . '}', $value, $out);
    }
    return ['ok' => true, 'content' => $out];
}

function shared_otp_fail(string $code, string $message, array $extra = []): array {
    return ['ok' => false, 'error_code' => $code, 'error' => $message] + $extra;
}

/**
 * Sends one code with an approved template from a shared line.
 *
 * @param array $user ['id', 'role', 'organization_id'] — the customer paying for and owning the send
 * @return array{ok:bool, id?:int, status?:string, originator?:string, parts?:int, error?:string, error_code?:string, fields?:array}
 */
function shared_otp_send(array $user, int $templateId, string $destination, array $params, string $source = 'api', ?int $apiKeyId = null, ?string $clientMessageId = null): array {
    if (!shared_otp_enabled()) {
        return shared_otp_fail('not_enabled', 'سرویس کد تایید اشتراکی فعال نیست.');
    }
    $lines = shared_otp_lines();
    if ($lines === []) {
        return shared_otp_fail('not_enabled', 'هنوز خطی برای سرویس کد تایید اشتراکی تعیین نشده است.');
    }
    $template = shared_otp_template_find($user, $templateId);
    if ($template === null) {
        return shared_otp_fail('template_not_found', 'قالب پیدا نشد.', ['fields' => ['template_id' => ['not_found']]]);
    }
    if ($template['status'] !== 'approved') {
        return shared_otp_fail('template_not_approved', 'این قالب هنوز تأیید نشده است.', ['fields' => ['template_id' => ['not_approved']]]);
    }
    $number = normalize_msisdn(from_persian_digits($destination));
    if ($number === null) {
        return shared_otp_fail('invalid_destination', 'شماره‌ی موبایل نامعتبر است.', ['fields' => ['destination' => ['invalid_format']]]);
    }
    $rendered = shared_otp_render((string)$template['content'], shared_otp_variables($template), $params);
    if (!$rendered['ok']) {
        return shared_otp_fail('invalid_parameters', 'مقدار متغیرها نامعتبر است.', ['fields' => $rendered['fields']]);
    }
    $content = $rendered['content'];
    $userId = (int)$user['id'];

    // One number flooded with codes is harassment, not authentication — and on a shared line it would
    // also be every customer's problem.
    $interval = shared_otp_min_interval_seconds();
    if ($interval > 0) {
        $recent = db()->prepare("SELECT COUNT(*) FROM ellsms_otp_messages WHERE user_id = ? AND destination = ? AND status <> 'failed' AND created_at > ?");
        $recent->execute([$userId, $number, date('Y-m-d H:i:s', time() - $interval)]);
        if ((int)$recent->fetchColumn() > 0) {
            return shared_otp_fail('too_frequent', 'به این شماره به‌تازگی کد ارسال شده است؛ کمی بعد دوباره تلاش کنید.', ['retry_after' => $interval]);
        }
    }

    db()->prepare("INSERT INTO ellsms_otp_messages (organization_id, user_id, template_id, destination, status, source, api_key_id, client_message_id, created_at)
                   VALUES (?,?,?,?, 'sending', ?,?,?,?)")
        ->execute([((int)($user['organization_id'] ?? 0)) ?: null, $userId, $templateId, $number, $source, $apiKeyId, $clientMessageId, shared_otp_now()]);
    $id = (int)db()->lastInsertId();

    $ok = false;
    $info = '';
    $parts = 0;
    $usedLine = null;
    foreach (shared_otp_line_order($lines, $number) as $i => $line) {
        $usedLine = $line;
        // Each line is its own wallet/quota operation: a failed attempt is fully released by
        // dispatch_message() before the next line is tried, so a fail-over never double-charges.
        $result = shared_otp_with_line($line, static fn(): array => dispatch_message(
            $user, $line, [$number], $content, null, SHARED_OTP_REF_TYPE, $id . ':' . $i, 'otp'
        ));
        [$ok, $info, $retryable, , , $parts] = array_pad($result, 6, null);
        if ($ok || !$retryable) break;
        Logger::warning('shared_otp.line_failover', ['otp_message_id' => $id, 'line' => $line, 'attempt' => $i]);
    }

    db()->prepare('UPDATE ellsms_otp_messages SET status = ?, originator = ?, parts = ?, error_code = ?, error = ? WHERE id = ?')
        ->execute([$ok ? 'sent' : 'failed', $usedLine, (int)$parts, $ok ? null : 'send_failed', $ok ? null : mb_substr((string)$info, 0, 500), $id]);
    Metrics::increment('shared_otp.sent', 1, ['outcome' => $ok ? 'sent' : 'failed', 'source' => $source]);

    return $ok
        ? ['ok' => true, 'id' => $id, 'status' => 'sent', 'originator' => (string)$usedLine, 'parts' => (int)$parts]
        : ['ok' => false, 'id' => $id, 'error_code' => 'send_failed', 'error' => (string)$info];
}

function shared_otp_message_find(array $user, int $id): ?array {
    [$scope, $params] = shared_otp_scope_sql($user, 'm');
    $st = db()->prepare("SELECT m.* FROM ellsms_otp_messages m WHERE m.id = ? AND {$scope}");
    $st->execute([$id, ...$params]);
    return $st->fetch() ?: null;
}

function shared_otp_messages_list(array $user, int $limit = 50): array {
    [$scope, $params] = shared_otp_scope_sql($user, 'm');
    $limit = max(1, $limit);
    $st = db()->prepare("SELECT m.*, t.title template_title FROM ellsms_otp_messages m
                         LEFT JOIN ellsms_otp_templates t ON t.id = m.template_id
                         WHERE {$scope} ORDER BY m.id DESC LIMIT {$limit}");
    $st->execute($params);
    return $st->fetchAll();
}

function shared_otp_status_label(string $status): array {
    return [
        'pending'  => ['در انتظار بررسی', 'badge-pending'],
        'approved' => ['تأییدشده', 'badge-ok'],
        'rejected' => ['ردشده', 'badge-failed'],
        'disabled' => ['غیرفعال', 'badge-off'],
        'sending'  => ['در حال ارسال', 'badge-processing'],
        'sent'     => ['ارسال شد', 'badge-sent'],
        'failed'   => ['ناموفق', 'badge-failed'],
    ][$status] ?? [$status, 'badge-unknown'];
}

/** The API's view of a template. */
function shared_otp_template_public(array $t): array {
    return [
        'id'          => (int)$t['id'],
        'title'       => (string)$t['title'],
        'content'     => (string)$t['content'],
        'variables'   => shared_otp_variables($t),
        'status'      => (string)$t['status'],
        'review_note' => $t['review_note'] !== null ? (string)$t['review_note'] : null,
        'created_at'  => (string)$t['created_at'],
    ];
}

/** The API's view of a send. */
function shared_otp_message_public(array $m): array {
    return [
        'id'          => (int)$m['id'],
        'template_id' => (int)$m['template_id'],
        'destination' => (string)$m['destination'],
        'originator'  => $m['originator'] !== null ? (string)$m['originator'] : null,
        'status'      => (string)$m['status'],
        'parts'       => (int)$m['parts'],
        'error'       => $m['error'] !== null ? (string)$m['error'] : null,
        'created_at'  => (string)$m['created_at'],
    ];
}
