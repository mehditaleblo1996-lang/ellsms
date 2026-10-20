<?php
/**
 * Voice messages through ZarinCall (docs/voice-messages.md) — the panel's counterpart of the old
 * platform's voice service (Vesal's VoiceRest / VoiceJob / VoiceBulkJob), on a hosted provider
 * instead of an in-house Asterisk.
 *
 * What a customer can do:
 *   - keep voice files: upload a WAV/MP3, or generate one from Persian text (TextToVoice);
 *   - send a file to a list of numbers, now or at a chosen time ('voice');
 *   - send it interactively: a recipient who presses `digit` is sent `sms` ('ivc');
 *   - send a one-time code read out by phone ('otp'), optionally after an announcement file ('otp2').
 *
 * Money (Rial wallet, like every other send): a call is priced in pulses of voice_pulse_seconds
 * (30 s, Vesal's unit) — pulses = ceil(file duration / pulse), unit cost = pulses × voice_price_per_pulse
 * (+ voice_ivc_extra_price for interactive sends; an OTP costs voice_otp_price, an OTP2 that plus the
 * announcement's pulses). The worst case (unit × recipients) is RESERVED when the send is accepted.
 * ZarinCall reports per message how many calls were answered (RecSuccess) and how many were not
 * (RecFailed); once a batch's counts are final, only the answered calls are charged and, when the
 * whole send is settled, the rest of the reservation is released. A batch ZarinCall never finishes
 * reporting is settled after voice_settle_after_hours, charging every call not reported failed.
 *
 * Sending: one message is split into batches of voice_batch_size numbers, each its own provider call
 * and MsgID. A batch is marked 'sending' BEFORE its call, so a crash mid-call is never re-sent (it is
 * recorded failed and not charged) — a recipient must not get the same call twice. Immediate sends
 * are dispatched inline; scheduled ones, progress polling and settlement run in cron/voice-worker.php
 * (the scheduler's `voice-worker` job, every minute).
 *
 * Recipients on the sender's blacklist are skipped (an OTP is not: the recipient asked for it).
 * Prohibited words apply to generated speech and to the interactive SMS text.
 */

declare(strict_types=1);

const VOICE_KINDS = ['voice', 'ivc', 'otp', 'otp2'];
const VOICE_UPLOAD_EXTENSIONS = ['wav', 'mp3'];
const VOICE_MAX_UPLOAD_BYTES = 10 * 1024 * 1024;
const VOICE_TTS_MAX_CHARS = 2000;
const VOICE_REF_TYPE = 'voice_message';
const VOICE_STUCK_SENDING_SECONDS = 900;

function voice_setting(string $key, string $default = ''): string {
    return (string)setting_fresh($key, $default);
}

function voice_now(): string {
    return date('Y-m-d H:i:s');
}

function voice_enabled(): bool {
    return voice_setting('voice_enabled', '0') === '1';
}

/** A price setting in Rial, or null while the admin has not set it (sending stays closed until then). */
function voice_price_setting(string $key): ?int {
    $v = voice_setting($key, '');
    return ctype_digit($v) ? (int)$v : null;
}

function voice_price_per_pulse(): ?int { return voice_price_setting('voice_price_per_pulse'); }
function voice_otp_price(): ?int { return voice_price_setting('voice_otp_price'); }
function voice_ivc_extra_price(): int { return (int)voice_price_setting('voice_ivc_extra_price'); }
function voice_tts_price(): int { return (int)voice_price_setting('voice_tts_price'); }
function voice_pulse_seconds(): int { return max(1, (int)voice_setting('voice_pulse_seconds', '30')); }
function voice_batch_size(): int { return max(1, min(10000, (int)voice_setting('voice_batch_size', '1000'))); }
function voice_max_recipients(): int { return max(1, (int)voice_setting('voice_max_recipients', '10000')); }
function voice_settle_after_hours(): int { return max(1, (int)voice_setting('voice_settle_after_hours', '48')); }
function voice_poll_interval_seconds(): int { return max(30, (int)voice_setting('voice_poll_interval_seconds', '120')); }
function voice_tts_save_days(): int { return max(0, (int)voice_setting('voice_tts_save_days', '365')); }
function voice_otp_min_interval_seconds(): int { return max(0, (int)voice_setting('voice_otp_min_interval_seconds', '60')); }

const VOICE_TTS_SPEAKERS_DEFAULT = "1=گوینده‌ی ۱\n2=گوینده‌ی ۲";

/**
 * Text-to-speech speakers offered to customers: ZarinCall's `gender` value => label, one
 * "value=label" per line in voice_tts_speakers (admin settings). ZarinCall does not document which
 * value is which voice, so the admin sets and names them after trying them.
 *
 * @return array<int, string>
 */
function voice_tts_speakers_parse(string $raw): array {
    $out = [];
    foreach (preg_split('/\R/u', from_persian_digits($raw)) ?: [] as $line) {
        if (preg_match('/^\s*(\d{1,3})\s*=\s*(.+?)\s*$/u', $line, $m) && (int)$m[1] <= 255) {
            $out[(int)$m[1]] = mb_substr($m[2], 0, 60);
        }
    }
    return $out;
}

/** @return array<int, string> */
function voice_tts_speakers(): array {
    return voice_tts_speakers_parse(voice_setting('voice_tts_speakers', VOICE_TTS_SPEAKERS_DEFAULT)) ?: voice_tts_speakers_parse(VOICE_TTS_SPEAKERS_DEFAULT);
}

/** Ready for voice sends: switched on, with an API key and a pulse price. */
function voice_configured(): bool {
    return voice_enabled() && zarincall_api_key_stored() && voice_price_per_pulse() !== null;
}

/** Ready for voice OTPs: voice_configured() plus an OTP price. */
function voice_otp_configured(): bool {
    return voice_configured() && voice_otp_price() !== null;
}

/* ==========================================================================
   Pricing
   ========================================================================== */

/** Billable pulses for a file of $durationSeconds: at least one, rounded up. */
function voice_pulses(int $durationSeconds, ?int $pulseSeconds = null): int {
    $pulse = $pulseSeconds ?? voice_pulse_seconds();
    return max(1, (int)ceil(max(0, $durationSeconds) / max(1, $pulse)));
}

/** Price of one recipient of a $kind send of $pulses pulses (pulses of the announcement for otp2). */
function voice_unit_cost(string $kind, int $pulses): int {
    $perPulse = (int)voice_price_per_pulse();
    return match ($kind) {
        'voice' => $pulses * $perPulse,
        'ivc' => $pulses * $perPulse + voice_ivc_extra_price(),
        'otp' => (int)voice_otp_price(),
        'otp2' => (int)voice_otp_price() + $pulses * $perPulse,
        default => throw new InvalidArgumentException('unknown voice kind'),
    };
}

/**
 * Seconds of audio in a PCM WAV file, from its header — the fallback when ZarinCall does not (yet)
 * report a duration. Null when $bytes is not a WAV this can read.
 */
function voice_wav_duration(string $bytes): ?int {
    if (strlen($bytes) < 44 || substr($bytes, 0, 4) !== 'RIFF' || substr($bytes, 8, 4) !== 'WAVE') {
        return null;
    }
    $byteRate = null;
    $dataSize = null;
    $pos = 12;
    $len = strlen($bytes);
    while ($pos + 8 <= $len) {
        $id = substr($bytes, $pos, 4);
        $size = unpack('V', substr($bytes, $pos + 4, 4))[1];
        if ($id === 'fmt ' && $pos + 16 <= $len) {
            $byteRate = unpack('V', substr($bytes, $pos + 16, 4))[1];
        } elseif ($id === 'data') {
            $dataSize = min($size, $len - $pos - 8);
            break;
        }
        $pos += 8 + $size + ($size % 2);
    }
    if (!$byteRate || $dataSize === null) {
        return null;
    }
    return max(1, (int)ceil($dataSize / $byteRate));
}

/* ==========================================================================
   Visibility — a customer sees their organization's (or, without one, their own) files and sends;
   a platform admin sees everything.
   ========================================================================== */

/** @return array{0:string, 1:list<mixed>} WHERE fragment and its parameters, for alias $a */
function voice_scope_sql(array $user, string $a): array {
    if (($user['role'] ?? null) === 'admin') {
        return ['1=1', []];
    }
    $orgId = (int)($user['organization_id'] ?? 0);
    return $orgId > 0 ? ["{$a}.organization_id = ?", [$orgId]] : ["{$a}.user_id = ?", [(int)$user['id']]];
}

/* ==========================================================================
   Voice files
   ========================================================================== */

function voice_file_find(array $user, int $id): ?array {
    [$scope, $params] = voice_scope_sql($user, 'f');
    $st = db()->prepare("SELECT f.* FROM ellsms_voice_files f WHERE f.id = ? AND f.status = 'active' AND {$scope}");
    $st->execute([$id, ...$params]);
    $row = $st->fetch();
    return $row ?: null;
}

/** Active files the user can use, newest first. */
function voice_files_list(array $user, int $limit = 200, int $offset = 0): array {
    [$scope, $params] = voice_scope_sql($user, 'f');
    $limit = max(1, $limit);
    $offset = max(0, $offset);
    $st = db()->prepare("SELECT f.* FROM ellsms_voice_files f WHERE f.status = 'active' AND {$scope} ORDER BY f.id DESC LIMIT {$limit} OFFSET {$offset}");
    $st->execute($params);
    return $st->fetchAll();
}

function voice_files_count(array $user): int {
    [$scope, $params] = voice_scope_sql($user, 'f');
    $st = db()->prepare("SELECT COUNT(*) FROM ellsms_voice_files f WHERE f.status = 'active' AND {$scope}");
    $st->execute($params);
    return (int)$st->fetchColumn();
}

function voice_title_clean(string $title, string $fallback): string {
    $title = trim(preg_replace('/\s+/u', ' ', $title) ?? '');
    return mb_substr($title !== '' ? $title : $fallback, 0, 190);
}

/** Records a file ZarinCall now holds, with its duration (from ZarinCall, else from the WAV header). */
function voice_file_record(array $user, string $voiceId, string $title, string $source, string $fileName, int $size,
                           ?string $bytes, ?string $ttsText = null, ?int $speaker = null): int {
    $info = zarincall_voice_get($voiceId);
    $duration = $info['ok'] && (int)$info['duration'] > 0 ? (int)$info['duration'] : ($bytes !== null ? voice_wav_duration($bytes) : null);
    db()->prepare("INSERT INTO ellsms_voice_files
                     (organization_id, user_id, title, source, file_name, size_bytes, provider_voice_id, provider_status, duration_seconds, tts_text, tts_speaker, created_at, refreshed_at)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([
            ((int)($user['organization_id'] ?? 0)) ?: null, (int)$user['id'], $title, $source, mb_substr($fileName, 0, 190), $size,
            $voiceId, $info['ok'] ? $info['status'] : null, $duration, $ttsText, $speaker, voice_now(), $info['ok'] ? voice_now() : null,
        ]);
    return (int)db()->lastInsertId();
}

/**
 * Uploads an audio file to ZarinCall and records it.
 *
 * @return array{ok:bool, id?:int, error?:string}
 */
function voice_file_upload(array $user, string $title, string $originalName, string $bytes): array {
    if (!voice_configured()) {
        return ['ok' => false, 'error' => 'سرویس پیام صوتی فعال نیست.'];
    }
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($ext, VOICE_UPLOAD_EXTENSIONS, true)) {
        return ['ok' => false, 'error' => 'فقط فایل‌های WAV و MP3 پذیرفته می‌شوند.'];
    }
    $size = strlen($bytes);
    if ($size === 0) {
        return ['ok' => false, 'error' => 'فایل خالی است.'];
    }
    if ($size > VOICE_MAX_UPLOAD_BYTES) {
        return ['ok' => false, 'error' => 'حجم فایل بیش از ' . to_persian_digits((string)(VOICE_MAX_UPLOAD_BYTES / 1024 / 1024)) . ' مگابایت است.'];
    }
    $magicOk = $ext === 'wav'
        ? substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WAVE'
        : str_starts_with($bytes, 'ID3') || (strlen($bytes) > 1 && ord($bytes[0]) === 0xFF && (ord($bytes[1]) & 0xE0) === 0xE0);
    if (!$magicOk) {
        return ['ok' => false, 'error' => 'محتوای فایل با پسوند آن (' . strtoupper($ext) . ') جور نیست.'];
    }
    $title = voice_title_clean($title, pathinfo($originalName, PATHINFO_FILENAME));
    $providerName = 'ellsms-' . (int)$user['id'] . '-' . bin2hex(random_bytes(6)) . '.' . $ext;

    $up = zarincall_voice_upload($providerName, $bytes);
    if (!$up['ok']) {
        Logger::warning('voice.file.upload_failed', ['user_id' => (int)$user['id'], 'error' => $up['error']]);
        return ['ok' => false, 'error' => 'زرین‌کال فایل را نپذیرفت: ' . (string)$up['error']];
    }
    $id = voice_file_record($user, (string)$up['voice_id'], $title, 'upload', $originalName, $size, $bytes);
    audit((int)$user['id'], 'voice.file.upload', 'file=' . $id . ' size=' . $size);
    Metrics::increment('voice.file.created', 1, ['source' => 'upload']);
    return ['ok' => true, 'id' => $id];
}

/**
 * Generates speech from Persian text at ZarinCall and records it as a voice file. Costs
 * voice_tts_price (reserved first, charged only if the speech was made).
 *
 * @return array{ok:bool, id?:int, error?:string}
 */
function voice_file_tts(array $user, string $title, string $text, int $speaker): array {
    if (!voice_configured()) {
        return ['ok' => false, 'error' => 'سرویس پیام صوتی فعال نیست.'];
    }
    $text = trim($text);
    if ($text === '') {
        return ['ok' => false, 'error' => 'متن خالی است.'];
    }
    if (mb_strlen($text) > VOICE_TTS_MAX_CHARS) {
        return ['ok' => false, 'error' => 'متن حداکثر ' . to_persian_digits((string)VOICE_TTS_MAX_CHARS) . ' نویسه می‌تواند باشد.'];
    }
    if (!array_key_exists($speaker, voice_tts_speakers())) {
        return ['ok' => false, 'error' => 'گوینده نامعتبر است.'];
    }
    if (($ruleId = content_policy_violation($text)) !== null) {
        content_policy_log_refusal($ruleId, 'voice_tts', (int)$user['id']);
        return ['ok' => false, 'error' => CONTENT_POLICY_ERROR];
    }
    $title = voice_title_clean($title, mb_substr($text, 0, 40));
    $price = ($user['role'] ?? null) === 'admin' ? 0 : voice_tts_price();
    $ref = 'tts-' . bin2hex(random_bytes(10));
    if ($price > 0) {
        $res = wallet_reserve((int)$user['id'], $price, 'voice_tts', $ref, "reserve:voice_tts:{$ref}");
        if (!$res['ok']) {
            return ['ok' => false, 'error' => 'اعتبار کافی نیست: ساخت صدا ' . number_format($price) . ' ریال هزینه دارد.'];
        }
    }

    $tts = zarincall_voice_text_to_voice($text, $speaker, $title, voice_tts_save_days());
    $voiceId = $tts['voice_id'];
    $bytes = $tts['voice_base64'] !== null ? (base64_decode($tts['voice_base64'], true) ?: null) : null;
    if ($tts['ok'] && $voiceId === null && $bytes !== null) {
        // ZarinCall returned the audio without keeping it: store it there as an ordinary upload.
        $up = zarincall_voice_upload('ellsms-tts-' . bin2hex(random_bytes(6)) . '.wav', $bytes);
        $voiceId = $up['ok'] ? $up['voice_id'] : null;
    }
    if (!$tts['ok'] || $voiceId === null) {
        if ($price > 0) wallet_release_reservation('voice_tts', $ref);
        Logger::warning('voice.file.tts_failed', ['user_id' => (int)$user['id'], 'error' => $tts['error']]);
        return ['ok' => false, 'error' => 'تبدیل متن به صوت انجام نشد: ' . (string)($tts['error'] ?? 'no_voice_id')];
    }
    $id = voice_file_record($user, $voiceId, $title, 'tts', '', $bytes !== null ? strlen($bytes) : 0, $bytes, $text, $speaker);
    if ($price > 0) {
        wallet_commit_reservation('voice_tts', $ref, $price, "commit:voice_tts:{$ref}", null, ['voice_file_id' => $id]);
    }
    audit((int)$user['id'], 'voice.file.tts', 'file=' . $id . ' chars=' . mb_strlen($text));
    Metrics::increment('voice.file.created', 1, ['source' => 'tts']);
    return ['ok' => true, 'id' => $id];
}

/** Re-reads a file's duration and status from ZarinCall. Returns the updated row. */
function voice_file_refresh(array $file): array {
    $info = zarincall_voice_get((string)$file['provider_voice_id']);
    if (!$info['ok']) {
        return $file;
    }
    $duration = (int)$info['duration'] > 0 ? (int)$info['duration'] : ($file['duration_seconds'] !== null ? (int)$file['duration_seconds'] : null);
    db()->prepare('UPDATE ellsms_voice_files SET duration_seconds = ?, provider_status = ?, refreshed_at = ? WHERE id = ?')
        ->execute([$duration, $info['status'], voice_now(), (int)$file['id']]);
    $file['duration_seconds'] = $duration;
    $file['provider_status'] = $info['status'];
    return $file;
}

/** @return array{ok:bool, error?:string} */
function voice_file_delete(array $user, int $id): array {
    $file = voice_file_find($user, $id);
    if ($file === null) {
        return ['ok' => false, 'error' => 'فایل پیدا نشد.'];
    }
    $busy = db()->prepare("SELECT COUNT(*) FROM ellsms_voice_messages WHERE voice_file_id = ? AND status IN ('scheduled','sending')");
    $busy->execute([$id]);
    if ((int)$busy->fetchColumn() > 0) {
        return ['ok' => false, 'error' => 'این فایل در ارسالی زمان‌بندی‌شده یا در حال ارسال استفاده شده است؛ ابتدا آن را لغو کنید.'];
    }
    $del = zarincall_voice_delete((string)$file['provider_voice_id']);
    if (!$del['ok']) {
        Logger::warning('voice.file.provider_delete_failed', ['file_id' => $id, 'error' => $del['error']]);
    }
    db()->prepare("UPDATE ellsms_voice_files SET status = 'deleted', deleted_at = ? WHERE id = ?")->execute([voice_now(), $id]);
    audit((int)$user['id'], 'voice.file.delete', 'file=' . $id);
    return ['ok' => true];
}

/* ==========================================================================
   Sending
   ========================================================================== */

/**
 * Splits raw destinations into ZarinCall numbers (09…), dropping invalid ones and duplicates.
 *
 * @return array{numbers:list<string>, msisdns:list<string>, invalid:int}
 */
function voice_parse_numbers(array $raw): array {
    $numbers = [];
    $invalid = 0;
    foreach ($raw as $value) {
        $n = is_string($value) || is_int($value) ? zarincall_number(from_persian_digits((string)$value)) : null;
        if ($n === null) {
            $invalid++;
            continue;
        }
        $numbers[$n] = true;
    }
    $numbers = array_map('strval', array_keys($numbers));
    return ['numbers' => $numbers, 'msisdns' => array_map(static fn(string $n): string => '98' . substr($n, 1), $numbers), 'invalid' => $invalid];
}

function voice_fail(string $code, string $message): array {
    return ['ok' => false, 'error_code' => $code, 'error' => $message];
}

/**
 * Accepts a voice or interactive send: validates, prices, reserves the worst case, stores the
 * message and its batches. An immediate send is dispatched before returning; a scheduled one is
 * left for the worker.
 *
 * $input: kind ('voice'|'ivc'), voice_file_id, destinations (list of strings), ivc_digit, ivc_sms,
 * scheduled_at (local 'Y-m-d H:i:s', or null for now), title.
 *
 * @return array{ok:bool, id?:int, recipients?:int, invalid?:int, blocked?:int, cost?:int, status?:string, error?:string, error_code?:string}
 */
function voice_message_create(array $user, array $input, string $source = 'panel', ?int $apiKeyId = null): array {
    if (!voice_configured()) {
        return voice_fail('not_configured', 'سرویس پیام صوتی فعال نیست.');
    }
    $kind = (string)($input['kind'] ?? 'voice');
    if (!in_array($kind, ['voice', 'ivc'], true)) {
        return voice_fail('invalid_kind', 'نوع ارسال نامعتبر است.');
    }
    $scheduledAt = $input['scheduled_at'] ?? null;
    $action = $scheduledAt !== null ? 'send.schedule' : 'send.direct';
    if (!impersonation_action_allowed($action)) {
        impersonation_record_block($action);
        return voice_fail('impersonation_blocked', impersonation_block_message($action));
    }
    $now = voice_now();
    if ($scheduledAt !== null) {
        $ts = is_string($scheduledAt) ? strtotime($scheduledAt) : false;
        if ($ts === false || $ts <= time()) {
            return voice_fail('schedule_in_past', 'زمان ارسال باید در آینده باشد.');
        }
        if ($ts > time() + 90 * 86400) {
            return voice_fail('schedule_too_far', 'زمان ارسال حداکثر تا ۹۰ روز آینده می‌تواند باشد.');
        }
        $scheduledAt = date('Y-m-d H:i:s', $ts);
    }

    $file = voice_file_find($user, (int)($input['voice_file_id'] ?? 0));
    if ($file === null) {
        return voice_fail('voice_file_not_found', 'فایل صوتی پیدا نشد.');
    }
    if ((int)$file['duration_seconds'] <= 0) {
        $file = voice_file_refresh($file);
    }
    if ((int)$file['duration_seconds'] <= 0) {
        return voice_fail('voice_file_not_ready', 'مدت فایل صوتی هنوز از زرین‌کال دریافت نشده است؛ چند دقیقه بعد دوباره تلاش کنید.');
    }

    $digit = null;
    $sms = null;
    if ($kind === 'ivc') {
        $digitRaw = from_persian_digits((string)($input['ivc_digit'] ?? ''));
        if (!preg_match('/^\d$/', $digitRaw)) {
            return voice_fail('invalid_ivc_digit', 'کلید پاسخ باید یک رقم ۰ تا ۹ باشد.');
        }
        $digit = (int)$digitRaw;
        $sms = trim((string)($input['ivc_sms'] ?? ''));
        if ($sms === '' || mb_strlen($sms) > 500) {
            return voice_fail('invalid_ivc_sms', 'متن پیامک پاسخ لازم است (حداکثر ۵۰۰ نویسه).');
        }
        if (($ruleId = content_policy_violation($sms)) !== null) {
            content_policy_log_refusal($ruleId, 'voice_ivc', (int)$user['id']);
            return voice_fail('content_prohibited', CONTENT_POLICY_ERROR);
        }
    }

    $parsed = voice_parse_numbers(is_array($input['destinations'] ?? null) ? $input['destinations'] : []);
    [$keptMsisdns, $blocked] = filter_blacklist((int)$user['id'], $parsed['msisdns']);
    $numbers = array_map(static fn(string $m): string => '0' . substr($m, 2), $keptMsisdns);
    if ($numbers === []) {
        return voice_fail('no_destination', $blocked > 0 ? 'همه‌ی شماره‌ها در لیست سیاه هستند.' : 'شماره‌ی موبایل معتبری وارد نشده است.');
    }
    if (count($numbers) > voice_max_recipients()) {
        return voice_fail('too_many_destinations', 'حداکثر ' . to_persian_digits((string)voice_max_recipients()) . ' شماره در هر ارسال.');
    }

    $pulses = voice_pulses((int)$file['duration_seconds']);
    $isAdmin = ($user['role'] ?? null) === 'admin';
    $unit = $isAdmin ? 0 : voice_unit_cost($kind, $pulses);
    $total = $unit * count($numbers);
    $userId = (int)$user['id'];
    $title = voice_title_clean((string)($input['title'] ?? ''), (string)$file['title']);

    try {
        $id = db_transaction(function (PDO $db) use ($user, $userId, $kind, $file, $title, $digit, $sms, $numbers, $blocked, $pulses, $unit, $total, $source, $apiKeyId, $scheduledAt, $now): int {
            $db->prepare("INSERT INTO ellsms_voice_messages
                            (organization_id, user_id, kind, voice_file_id, provider_voice_id, title, ivc_digit, ivc_sms, recipients_count, blocked_count,
                             pulses, unit_cost, reserved_cost, status, source, api_key_id, scheduled_at, created_at)
                          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?, 'scheduled', ?,?,?,?)")
               ->execute([((int)($user['organization_id'] ?? 0)) ?: null, $userId, $kind, (int)$file['id'], (string)$file['provider_voice_id'], $title,
                          $digit, $sms, count($numbers), $blocked, $pulses, $unit, $total, $source, $apiKeyId, $scheduledAt ?? $now, $now]);
            $id = (int)$db->lastInsertId();
            $batch = $db->prepare('INSERT INTO ellsms_voice_message_batches (message_id, batch_no, recipients, recipients_count) VALUES (?,?,?,?)');
            foreach (array_chunk($numbers, voice_batch_size()) as $i => $chunk) {
                $batch->execute([$id, $i + 1, implode("\n", $chunk), count($chunk)]);
            }
            if ($total > 0) {
                $res = wallet_reserve($userId, $total, VOICE_REF_TYPE, (string)$id, 'reserve:' . VOICE_REF_TYPE . ':' . $id);
                if (!$res['ok']) {
                    throw new WalletInsufficientBalanceException('voice reservation refused');
                }
            }
            return $id;
        });
    } catch (WalletInsufficientBalanceException) {
        return voice_fail('insufficient_credit', 'اعتبار کافی نیست: این ارسال تا ' . number_format($total) . ' ریال هزینه دارد.');
    }

    audit($userId, 'voice.message.create', "id={$id} kind={$kind} recipients=" . count($numbers) . ($scheduledAt ? " at={$scheduledAt}" : ''));
    Logger::info('voice.message.created', ['id' => $id, 'user_id' => $userId, 'kind' => $kind, 'recipients' => count($numbers), 'reserved' => $total, 'scheduled' => $scheduledAt !== null]);
    Metrics::increment('voice.message.created', 1, ['kind' => $kind, 'source' => $source]);

    if ($scheduledAt === null) {
        voice_message_dispatch($id);
    }
    $row = voice_message_get($id);
    return [
        'ok' => true, 'id' => $id, 'recipients' => count($numbers), 'invalid' => $parsed['invalid'], 'blocked' => $blocked,
        'cost' => $total, 'status' => (string)($row['status'] ?? 'scheduled'),
    ];
}

/**
 * Sends a voice OTP to one number, synchronously.
 *
 * @return array{ok:bool, id?:int, status?:string, cost?:int, error?:string, error_code?:string}
 */
function voice_otp_send(array $user, string $destination, string $code, ?int $voiceFileId = null, string $source = 'panel', ?int $apiKeyId = null): array {
    if (!voice_otp_configured()) {
        return voice_fail('not_configured', 'کد تایید صوتی فعال نیست.');
    }
    if (!impersonation_action_allowed('send.direct')) {
        impersonation_record_block('send.direct');
        return voice_fail('impersonation_blocked', impersonation_block_message('send.direct'));
    }
    $code = from_persian_digits(trim($code));
    if (!preg_match('/^\d{3,10}$/', $code)) {
        return voice_fail('invalid_code', 'کد باید ۳ تا ۱۰ رقم باشد.');
    }
    $number = zarincall_number(from_persian_digits($destination));
    if ($number === null) {
        return voice_fail('invalid_destination', 'شماره‌ی موبایل نامعتبر است.');
    }
    $file = null;
    $pulses = 0;
    if ($voiceFileId !== null && $voiceFileId > 0) {
        $file = voice_file_find($user, $voiceFileId);
        if ($file === null) {
            return voice_fail('voice_file_not_found', 'فایل صوتی پیدا نشد.');
        }
        if ((int)$file['duration_seconds'] <= 0) {
            $file = voice_file_refresh($file);
        }
        if ((int)$file['duration_seconds'] <= 0) {
            return voice_fail('voice_file_not_ready', 'مدت فایل صوتی هنوز از زرین‌کال دریافت نشده است.');
        }
        $pulses = voice_pulses((int)$file['duration_seconds']);
    }
    $kind = $file === null ? 'otp' : 'otp2';
    $userId = (int)$user['id'];

    // A phone that is called over and over is harassment, not authentication.
    $interval = voice_otp_min_interval_seconds();
    if ($interval > 0) {
        $recent = db()->prepare("SELECT COUNT(*) FROM ellsms_voice_messages m JOIN ellsms_voice_message_batches b ON b.message_id = m.id
                                 WHERE m.user_id = ? AND m.kind IN ('otp','otp2') AND b.recipients = ? AND m.created_at > ?");
        $recent->execute([$userId, $number, date('Y-m-d H:i:s', time() - $interval)]);
        if ((int)$recent->fetchColumn() > 0) {
            return voice_fail('too_frequent', 'به این شماره به‌تازگی کد صوتی ارسال شده است؛ کمی بعد دوباره تلاش کنید.');
        }
    }

    $unit = ($user['role'] ?? null) === 'admin' ? 0 : voice_unit_cost($kind, $pulses);
    $now = voice_now();
    try {
        $id = db_transaction(function (PDO $db) use ($user, $userId, $kind, $file, $number, $pulses, $unit, $source, $apiKeyId, $now): int {
            $db->prepare("INSERT INTO ellsms_voice_messages
                            (organization_id, user_id, kind, voice_file_id, provider_voice_id, title, recipients_count, pulses, unit_cost, reserved_cost,
                             status, source, api_key_id, scheduled_at, created_at)
                          VALUES (?,?,?,?,?,?,1,?,?,?, 'scheduled', ?,?,?,?)")
               ->execute([((int)($user['organization_id'] ?? 0)) ?: null, $userId, $kind, $file ? (int)$file['id'] : null, $file ? (string)$file['provider_voice_id'] : null,
                          $kind === 'otp' ? 'کد تایید صوتی' : 'کد تایید صوتی با اعلان', $pulses, $unit, $unit, $source, $apiKeyId, $now, $now]);
            $id = (int)$db->lastInsertId();
            $db->prepare('INSERT INTO ellsms_voice_message_batches (message_id, batch_no, recipients, recipients_count) VALUES (?,1,?,1)')->execute([$id, $number]);
            if ($unit > 0) {
                $res = wallet_reserve($userId, $unit, VOICE_REF_TYPE, (string)$id, 'reserve:' . VOICE_REF_TYPE . ':' . $id);
                if (!$res['ok']) {
                    throw new WalletInsufficientBalanceException('voice reservation refused');
                }
            }
            return $id;
        });
    } catch (WalletInsufficientBalanceException) {
        return voice_fail('insufficient_credit', 'اعتبار کافی نیست: کد صوتی ' . number_format($unit) . ' ریال هزینه دارد.');
    }

    // The code itself is never stored: it goes to ZarinCall and nowhere else.
    voice_message_dispatch($id, $code);
    Metrics::increment('voice.message.created', 1, ['kind' => $kind, 'source' => $source]);
    $row = voice_message_get($id);
    $ok = $row !== null && $row['status'] !== 'failed';
    return $ok
        ? ['ok' => true, 'id' => $id, 'status' => (string)$row['status'], 'cost' => $unit]
        : ['ok' => false, 'id' => $id, 'error_code' => 'provider_refused', 'error' => 'زرین‌کال تماس را نپذیرفت: ' . (string)($row['error'] ?? '')];
}

function voice_message_get(int $id): ?array {
    $st = db()->prepare('SELECT * FROM ellsms_voice_messages WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function voice_message_find(array $user, int $id): ?array {
    [$scope, $params] = voice_scope_sql($user, 'm');
    $st = db()->prepare("SELECT m.* FROM ellsms_voice_messages m WHERE m.id = ? AND {$scope}");
    $st->execute([$id, ...$params]);
    return $st->fetch() ?: null;
}

function voice_message_batches(int $messageId): array {
    $st = db()->prepare('SELECT * FROM ellsms_voice_message_batches WHERE message_id = ? ORDER BY batch_no');
    $st->execute([$messageId]);
    return $st->fetchAll();
}

/**
 * Claims a due 'scheduled' message and sends its batches. $otpCode is only ever passed for an OTP,
 * straight from the request (never stored). Returns false when the message was not claimable (not
 * due, already claimed, cancelled).
 */
function voice_message_dispatch(int $id, ?string $otpCode = null): bool {
    $now = voice_now();
    $claim = db()->prepare("UPDATE ellsms_voice_messages SET status = 'sending', sending_started_at = ?
                            WHERE id = ? AND status = 'scheduled' AND scheduled_at <= ?");
    $claim->execute([$now, $id, $now]);
    if ($claim->rowCount() !== 1) {
        return false;
    }
    if (!voice_configured()) {
        // Switched off between acceptance and the due time: give the reservation back.
        db()->prepare("UPDATE ellsms_voice_message_batches SET status = 'failed', error = 'service_disabled' WHERE message_id = ? AND status = 'pending'")->execute([$id]);
        voice_message_finish_dispatch($id);
        return true;
    }
    voice_message_send_batches($id, $otpCode);
    return true;
}

/** Sends every still-pending batch of a claimed message, then moves the message on. */
function voice_message_send_batches(int $id, ?string $otpCode = null): void {
    $message = voice_message_get($id);
    if ($message === null) {
        return;
    }
    $markSending = db()->prepare("UPDATE ellsms_voice_message_batches SET status = 'sending' WHERE id = ? AND status = 'pending'");
    $markSent = db()->prepare("UPDATE ellsms_voice_message_batches SET status = 'sent', provider_msg_id = ?, sent_at = ? WHERE id = ?");
    $markFailed = db()->prepare("UPDATE ellsms_voice_message_batches SET status = 'failed', error = ? WHERE id = ?");

    foreach (voice_message_batches($id) as $batch) {
        if ($batch['status'] !== 'pending') {
            continue;
        }
        $markSending->execute([(int)$batch['id']]);
        if ($markSending->rowCount() !== 1) {
            continue;
        }
        $numbers = array_values(array_filter(explode("\n", (string)$batch['recipients'])));
        $voiceId = (string)$message['provider_voice_id'];
        $result = match ((string)$message['kind']) {
            'voice' => zarincall_message_send($voiceId, $numbers),
            'ivc' => zarincall_message_send_ivc($voiceId, $numbers, (int)$message['ivc_digit'], (string)$message['ivc_sms']),
            'otp', 'otp2' => $otpCode === null
                ? ['ok' => false, 'msg_id' => null, 'error' => 'otp_code_lost', 'transport' => false]
                : zarincall_message_send_otp($otpCode, $numbers[0] ?? '', $message['kind'] === 'otp2' ? $voiceId : null),
        };
        if (!$result['ok']) {
            $markFailed->execute([mb_strimwidth((string)$result['error'], 0, 480, '…'), (int)$batch['id']]);
            Metrics::increment('voice.batch.failed', 1, ['kind' => (string)$message['kind']]);
            continue;
        }
        $markSent->execute([$result['msg_id'], voice_now(), (int)$batch['id']]);
        Metrics::increment('voice.batch.sent', 1, ['kind' => (string)$message['kind']]);
        if ($result['msg_id'] === null) {
            // Accepted without a MsgID (ZarinCall's SendOTP answer may carry none): nothing to poll,
            // so the accepted calls are charged now.
            $batch['status'] = 'sent';
            $batch['recipients_count'] = count($numbers);
            voice_batch_settle($batch, $message, count($numbers), 0, 'accepted_without_msg_id');
        }
    }
    voice_message_finish_dispatch($id);
}

/** After a dispatch pass: 'sent' while any batch went out, 'failed' (reservation released) when none did. */
function voice_message_finish_dispatch(int $id): void {
    $st = db()->prepare("SELECT status, COUNT(*) c FROM ellsms_voice_message_batches WHERE message_id = ? GROUP BY status");
    $st->execute([$id]);
    $counts = array_map('intval', array_column($st->fetchAll(), 'c', 'status'));
    if (($counts['pending'] ?? 0) > 0 || ($counts['sending'] ?? 0) > 0) {
        return;
    }
    if (($counts['sent'] ?? 0) === 0 && ($counts['settled'] ?? 0) === 0) {
        $err = db()->prepare("SELECT error FROM ellsms_voice_message_batches WHERE message_id = ? AND error IS NOT NULL LIMIT 1");
        $err->execute([$id]);
        db()->prepare("UPDATE ellsms_voice_messages SET status = 'failed', error = ?, completed_at = ?, failed_count = recipients_count WHERE id = ? AND status = 'sending'")
            ->execute([mb_strimwidth((string)$err->fetchColumn(), 0, 480, '…'), voice_now(), $id]);
        wallet_release_reservation(VOICE_REF_TYPE, (string)$id);
        Logger::warning('voice.message.failed', ['id' => $id]);
        Metrics::increment('voice.message.failed', 1);
        return;
    }
    db()->prepare("UPDATE ellsms_voice_messages SET status = 'sent', sent_at = ? WHERE id = ? AND status = 'sending'")->execute([voice_now(), $id]);
    voice_message_finalize_if_done($id);
}

/**
 * Charges a batch for $answered calls and marks it settled. Idempotent per batch (the ledger key
 * and the status guard). $failed only feeds the counts shown to the customer.
 */
function voice_batch_settle(array $batch, array $message, int $answered, int $failed, string $reason): void {
    $answered = max(0, min($answered, (int)$batch['recipients_count']));
    $cost = $answered * (int)$message['unit_cost'];
    $upd = db()->prepare("UPDATE ellsms_voice_message_batches SET status = 'settled', rec_success = ?, rec_failed = ?, charged_cost = ?, settled_at = ?
                          WHERE id = ? AND status = 'sent'");
    $upd->execute([$answered, max(0, $failed), $cost, voice_now(), (int)$batch['id']]);
    if ($upd->rowCount() !== 1) {
        return;
    }
    if ($cost > 0) {
        $commit = wallet_commit_reservation(VOICE_REF_TYPE, (string)$message['id'], $cost, 'commit:voice_batch:' . (int)$batch['id'], null,
            ['voice_message_id' => (int)$message['id'], 'batch' => (int)$batch['batch_no'], 'answered' => $answered]);
        if (!$commit['ok']) {
            Logger::error('voice.batch.commit_failed', ['batch_id' => (int)$batch['id'], 'reason' => $commit['reason'] ?? null]);
        }
    }
    Logger::info('voice.batch.settled', ['batch_id' => (int)$batch['id'], 'message_id' => (int)$message['id'], 'answered' => $answered, 'failed' => $failed, 'cost' => $cost, 'reason' => $reason]);
}

/** Once no batch is waiting any more: totals, release of what was not charged, message completed. */
function voice_message_finalize_if_done(int $id): void {
    $st = db()->prepare("SELECT
                           SUM(status IN ('pending','sending','sent')) AS open_batches,
                           SUM(status = 'settled') AS settled_batches,
                           COALESCE(SUM(rec_success), 0) AS answered,
                           COALESCE(SUM(CASE WHEN status = 'failed' THEN recipients_count ELSE rec_failed END), 0) AS failed,
                           COALESCE(SUM(charged_cost), 0) AS charged
                         FROM ellsms_voice_message_batches WHERE message_id = ?");
    $st->execute([$id]);
    $t = $st->fetch();
    if ($t === false || (int)$t['open_batches'] > 0) {
        return;
    }
    $upd = db()->prepare("UPDATE ellsms_voice_messages SET status = ?, success_count = ?, failed_count = ?, charged_cost = ?, completed_at = ?
                          WHERE id = ? AND status = 'sent'");
    $upd->execute([(int)$t['settled_batches'] > 0 ? 'completed' : 'failed', (int)$t['answered'], (int)$t['failed'], (int)$t['charged'], voice_now(), $id]);
    if ($upd->rowCount() === 1) {
        $released = wallet_release_reservation(VOICE_REF_TYPE, (string)$id);
        Logger::info('voice.message.completed', ['id' => $id, 'answered' => (int)$t['answered'], 'charged' => (int)$t['charged'], 'released' => $released['released_amount'] ?? 0]);
        Metrics::increment('voice.message.completed', 1);
    }
}

/** Cancels a scheduled send and gives back its reservation. @return array{ok:bool, error?:string} */
function voice_message_cancel(array $user, int $id): array {
    $message = voice_message_find($user, $id);
    if ($message === null) {
        return ['ok' => false, 'error' => 'ارسال پیدا نشد.'];
    }
    $upd = db()->prepare("UPDATE ellsms_voice_messages SET status = 'cancelled', completed_at = ? WHERE id = ? AND status = 'scheduled'");
    $upd->execute([voice_now(), $id]);
    if ($upd->rowCount() !== 1) {
        return ['ok' => false, 'error' => 'فقط ارسالی که هنوز شروع نشده لغو می‌شود.'];
    }
    db()->prepare("UPDATE ellsms_voice_message_batches SET status = 'failed', error = 'cancelled' WHERE message_id = ? AND status = 'pending'")->execute([$id]);
    wallet_release_reservation(VOICE_REF_TYPE, (string)$id);
    audit((int)$user['id'], 'voice.message.cancel', 'id=' . $id);
    return ['ok' => true];
}

/* ==========================================================================
   Worker (cron/voice-worker.php)
   ========================================================================== */

/** Sends scheduled messages that are due. @return int messages dispatched */
function voice_dispatch_due(int $limit = 50): int {
    if (!voice_configured()) {
        return 0;
    }
    $st = db()->prepare("SELECT id FROM ellsms_voice_messages WHERE status = 'scheduled' AND scheduled_at <= ? ORDER BY scheduled_at, id LIMIT " . max(1, $limit));
    $st->execute([voice_now()]);
    $n = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
        if (voice_message_dispatch((int)$id)) $n++;
    }
    return $n;
}

/**
 * Messages left in 'sending' by a crashed process: a batch whose call was under way is recorded
 * failed (never re-sent — the call may have gone out), pending batches are sent now.
 */
function voice_recover_stuck(): int {
    $cutoff = date('Y-m-d H:i:s', time() - VOICE_STUCK_SENDING_SECONDS);
    $st = db()->prepare("SELECT id FROM ellsms_voice_messages WHERE status = 'sending' AND sending_started_at < ? LIMIT 50");
    $st->execute([$cutoff]);
    $n = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $claim = db()->prepare("UPDATE ellsms_voice_messages SET sending_started_at = ? WHERE id = ? AND status = 'sending' AND sending_started_at < ?");
        $claim->execute([voice_now(), (int)$id, $cutoff]);
        if ($claim->rowCount() !== 1) continue;
        db()->prepare("UPDATE ellsms_voice_message_batches SET status = 'failed', error = 'interrupted' WHERE message_id = ? AND status = 'sending'")->execute([(int)$id]);
        Logger::warning('voice.message.recovered', ['id' => (int)$id]);
        $message = voice_message_get((int)$id);
        if ($message !== null && in_array($message['kind'], ['otp', 'otp2'], true)) {
            db()->prepare("UPDATE ellsms_voice_message_batches SET status = 'failed', error = 'interrupted' WHERE message_id = ? AND status = 'pending'")->execute([(int)$id]);
            voice_message_finish_dispatch((int)$id);
        } else {
            voice_message_send_batches((int)$id);
        }
        $n++;
    }
    return $n;
}

/** Whether ZarinCall's counts for a batch are final: every reported recipient answered or failed. */
function voice_batch_counts_final(?int $recCount, int $success, int $failed): bool {
    return $recCount !== null && $recCount > 0 && $success + $failed >= $recCount;
}

/** Polls the progress of sent batches and settles the finished ones. @return int batches settled */
function voice_poll_sent(int $limit = 200): int {
    if (!zarincall_api_key_stored()) {
        return 0;
    }
    $pollBefore = date('Y-m-d H:i:s', time() - voice_poll_interval_seconds());
    $st = db()->prepare("SELECT * FROM ellsms_voice_message_batches
                         WHERE status = 'sent' AND provider_msg_id IS NOT NULL AND (last_polled_at IS NULL OR last_polled_at < ?)
                         ORDER BY last_polled_at IS NOT NULL, last_polled_at, id LIMIT " . max(1, $limit));
    $st->execute([$pollBefore]);
    $deadline = time() - voice_settle_after_hours() * 3600;
    $settled = 0;
    $touched = [];
    foreach ($st->fetchAll() as $batch) {
        $r = zarincall_message_get((string)$batch['provider_msg_id']);
        if ($r['ok']) {
            db()->prepare('UPDATE ellsms_voice_message_batches
                           SET rec_count = ?, rec_success = ?, rec_failed = ?, provider_send_status = ?, provider_send_des = ?, poll_count = poll_count + 1, last_polled_at = ?
                           WHERE id = ?')
                ->execute([$r['rec_count'], $r['rec_success'], $r['rec_failed'], $r['send_status'], $r['send_des'], voice_now(), (int)$batch['id']]);
            $batch = array_merge($batch, ['rec_count' => $r['rec_count'], 'rec_success' => $r['rec_success'], 'rec_failed' => $r['rec_failed']]);
        } else {
            db()->prepare('UPDATE ellsms_voice_message_batches SET poll_count = poll_count + 1, last_polled_at = ?, error = ? WHERE id = ?')
                ->execute([voice_now(), mb_strimwidth((string)$r['error'], 0, 480, '…'), (int)$batch['id']]);
        }
        $message = voice_message_get((int)$batch['message_id']);
        if ($message === null) continue;

        $success = (int)$batch['rec_success'];
        $failed = (int)$batch['rec_failed'];
        if ($r['ok'] && voice_batch_counts_final($r['rec_count'], $success, $failed)) {
            voice_batch_settle($batch, $message, $success, $failed, 'final');
        } elseif (strtotime((string)$batch['sent_at']) < $deadline) {
            // No final answer in time: every call not reported failed is charged.
            voice_batch_settle($batch, $message, (int)$batch['recipients_count'] - $failed, $failed, 'timeout');
        } else {
            continue;
        }
        $settled++;
        $touched[(int)$batch['message_id']] = true;
    }
    foreach (array_keys($touched) as $messageId) {
        voice_message_finalize_if_done($messageId);
    }
    return $settled;
}

/** Files whose duration ZarinCall had not reported yet, re-read for up to two days. */
function voice_refresh_pending_files(int $limit = 50): int {
    if (!zarincall_api_key_stored()) {
        return 0;
    }
    $st = db()->prepare("SELECT * FROM ellsms_voice_files WHERE status = 'active' AND (duration_seconds IS NULL OR duration_seconds = 0) AND created_at > ? ORDER BY id LIMIT " . max(1, $limit));
    $st->execute([date('Y-m-d H:i:s', time() - 2 * 86400)]);
    $n = 0;
    foreach ($st->fetchAll() as $file) {
        if ((int)voice_file_refresh($file)['duration_seconds'] > 0) $n++;
    }
    return $n;
}

/** One worker pass. @return array{dispatched:int, recovered:int, settled:int, files:int} */
function voice_worker_run(): array {
    return [
        'recovered' => voice_recover_stuck(),
        'dispatched' => voice_dispatch_due(),
        'settled' => voice_poll_sent(),
        'files' => voice_refresh_pending_files(),
    ];
}

/* ==========================================================================
   Reporting
   ========================================================================== */

function voice_kind_label(string $kind): string {
    return ['voice' => 'پیام صوتی', 'ivc' => 'پیام صوتی تعاملی', 'otp' => 'کد تایید صوتی', 'otp2' => 'کد تایید با اعلان'][$kind] ?? $kind;
}

/** @return array{0:string, 1:string} label, badge class */
function voice_status_label(string $status): array {
    return [
        'scheduled' => ['زمان‌بندی‌شده', 'badge-pending'],
        'sending' => ['در حال ارسال', 'badge-pending'],
        'sent' => ['در حال تماس', 'badge-processing'],
        'completed' => ['پایان‌یافته', 'badge-done'],
        'failed' => ['ناموفق', 'badge-failed'],
        'cancelled' => ['لغوشده', 'badge-cancelled'],
    ][$status] ?? [$status, 'badge-off'];
}

/** @return array{rows:list<array>, total:int} */
function voice_messages_list(array $user, int $limit, int $offset, ?string $status = null): array {
    [$scope, $params] = voice_scope_sql($user, 'm');
    $where = $scope;
    if ($status !== null && in_array($status, ['scheduled', 'sending', 'sent', 'completed', 'failed', 'cancelled'], true)) {
        $where .= ' AND m.status = ?';
        $params[] = $status;
    }
    $count = db()->prepare("SELECT COUNT(*) FROM ellsms_voice_messages m WHERE {$where}");
    $count->execute($params);
    $limit = max(1, $limit);
    $offset = max(0, $offset);
    $st = db()->prepare("SELECT m.*, f.title AS file_title FROM ellsms_voice_messages m LEFT JOIN ellsms_voice_files f ON f.id = m.voice_file_id
                         WHERE {$where} ORDER BY m.id DESC LIMIT {$limit} OFFSET {$offset}");
    $st->execute($params);
    return ['rows' => $st->fetchAll(), 'total' => (int)$count->fetchColumn()];
}

/** The public shape of a message (API and panel detail). */
function voice_message_public(array $m): array {
    return [
        'id' => (int)$m['id'],
        'kind' => (string)$m['kind'],
        'status' => (string)$m['status'],
        'voice_file_id' => $m['voice_file_id'] !== null ? (int)$m['voice_file_id'] : null,
        'title' => (string)$m['title'],
        'recipients' => (int)$m['recipients_count'],
        'blocked' => (int)$m['blocked_count'],
        'pulses' => (int)$m['pulses'],
        'unit_cost' => (int)$m['unit_cost'],
        'reserved_cost' => (int)$m['reserved_cost'],
        'charged_cost' => (int)$m['charged_cost'],
        'answered' => (int)$m['success_count'],
        'not_answered' => (int)$m['failed_count'],
        'scheduled_at' => (string)$m['scheduled_at'],
        'sent_at' => $m['sent_at'],
        'completed_at' => $m['completed_at'],
        'created_at' => (string)$m['created_at'],
        'error' => $m['error'],
    ];
}

function voice_file_public(array $f): array {
    return [
        'id' => (int)$f['id'],
        'title' => (string)$f['title'],
        'source' => (string)$f['source'],
        'duration_seconds' => $f['duration_seconds'] !== null ? (int)$f['duration_seconds'] : null,
        'pulses' => (int)$f['duration_seconds'] > 0 ? voice_pulses((int)$f['duration_seconds']) : null,
        'created_at' => (string)$f['created_at'],
    ];
}
