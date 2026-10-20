<?php
/**
 * ZarinCall (zarincall.ir) — the voice-call provider behind the voice message service
 * (app/VoiceMessages.php, docs/voice-messages.md).
 *
 * The contract, as ZarinCall's own client library (the "ZarinCall" NuGet package, 1.7.x) calls it:
 *   POST {base}/{Controller}/{Action}          base: https://ws.zarincall.ir/Apiv2
 *   header  Authorization: basic apikey:<key>
 *   body    JSON, camelCase field names
 *   answer  {"R_Success": bool, "R_Code": int, "R_Error": string|null, "R_Message": string|null, ...}
 *
 *   Message/Send         {voiceId, numbers[]}                    -> MsgID
 *   Message/SendIVC      {voiceId, numbers[], digit, sms}        -> MsgID   (press `digit`, get `sms`)
 *   Message/SendOTP      {code, number}                          -> (MsgID)
 *   Message/SendOTP2     {code, number, voiceId}                 -> (MsgID) (code after an announcement)
 *   Message/Get          {msgId}   -> Data {MsgID, VoiceID, RecCount, RecSuccess, RecFailed, SendStatus, SendDes, ...}
 *   Message/Delete       {msgId}
 *   Voice/UploadBase64   {fileName, fileByteBase64}              -> VoiceID
 *   Voice/TextToVoice    {title, gender, text, saveDays}         -> VoiceID, VoiceBase64
 *   Voice/Get            {voiceId} -> Data {VoiceID, Title, Duration, VoiceStatus, MediaTypeName, DataSize, ...}
 *   Voice/List           {voiceStatus, addDateFrom, addDateTo, page, pageSize, search} -> DataList[]
 *   Voice/Download       {voiceId} -> FileName, FileByteBase64
 *   Voice/Delete         {voiceId}
 *   User/GetPulse        {}        -> Pulse (the account's remaining balance at ZarinCall)
 *
 * Configuration lives in ellsms_settings, edited on /admin/voice: voice_base_url, and the API key,
 * which is encrypted (AES-256-GCM, key derived from SMS_GATEWAY_MASTER_KEY with its own purpose label)
 * and never shown again once saved.
 */

declare(strict_types=1);

const ZARINCALL_DEFAULT_BASE_URL = 'https://ws.zarincall.ir/Apiv2';
const ZARINCALL_API_KEY_PURPOSE = 'ellsms.voice.zarincall_api_key.v1';

class ZarinCallKeyException extends AppException {}

function zarincall_base_url(): string {
    return rtrim((string)setting_fresh('voice_base_url', ZARINCALL_DEFAULT_BASE_URL), '/');
}

/** The derived encryption key, or null without SMS_GATEWAY_MASTER_KEY (same master key as gateway secrets). */
function zarincall_secret_key(): ?string {
    $master = (string)env('SMS_GATEWAY_MASTER_KEY', '');
    if (strlen($master) < 32) {
        return null;
    }
    return hash_hkdf('sha256', $master, 32, ZARINCALL_API_KEY_PURPOSE);
}

function zarincall_secret_fingerprint(?string $key): string {
    return $key === null ? '' : substr(hash('sha256', $key . '|fingerprint'), 0, 16);
}

/** Encrypts and stores the API key; '' removes it. */
function zarincall_api_key_save(string $plaintext): void {
    $plaintext = trim($plaintext);
    if ($plaintext === '') {
        set_setting('voice_api_key', '');
        return;
    }
    $key = zarincall_secret_key();
    if ($key === null) {
        throw new ZarinCallKeyException('برای ذخیره‌ی کلید زرین‌کال، SMS_GATEWAY_MASTER_KEY (حداقل ۳۲ نویسه) باید تنظیم شده باشد.');
    }
    $nonce = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($ciphertext === false) {
        throw new ZarinCallKeyException('رمزگذاری کلید زرین‌کال ممکن نشد.');
    }
    set_setting('voice_api_key', json_encode([
        'c' => base64_encode($ciphertext),
        'n' => base64_encode($nonce),
        't' => base64_encode($tag),
        'f' => zarincall_secret_fingerprint($key),
    ]));
}

function zarincall_api_key_stored(): bool {
    return (string)setting_fresh('voice_api_key', '') !== '';
}

/** The decrypted API key, or '' when none is stored or it cannot be decrypted (wrong master key). */
function zarincall_api_key(): string {
    $stored = json_decode((string)setting_fresh('voice_api_key', ''), true);
    if (!is_array($stored) || !isset($stored['c'], $stored['n'], $stored['t'])) {
        return '';
    }
    $key = zarincall_secret_key();
    if ($key === null || (($stored['f'] ?? '') !== '' && $stored['f'] !== zarincall_secret_fingerprint($key))) {
        return '';
    }
    $plain = openssl_decrypt((string)base64_decode((string)$stored['c']), 'aes-256-gcm', $key, OPENSSL_RAW_DATA,
        (string)base64_decode((string)$stored['n']), (string)base64_decode((string)$stored['t']));
    return $plain === false ? '' : $plain;
}

/** A field of a ZarinCall answer, matched case-insensitively (MsgID / msgId / MsgId). */
function zarincall_field(?array $data, string $name): mixed {
    if ($data === null) {
        return null;
    }
    if (array_key_exists($name, $data)) {
        return $data[$name];
    }
    foreach ($data as $key => $value) {
        if (is_string($key) && strcasecmp($key, $name) === 0) {
            return $value;
        }
    }
    return null;
}

/** A provider id (MsgID / VoiceID) as a trimmed string, or null when absent or empty. */
function zarincall_id(mixed $value): ?string {
    if (!is_string($value) && !is_int($value)) {
        return null;
    }
    $value = trim((string)$value);
    return $value === '' || strlen($value) > 64 ? null : $value;
}

/**
 * One API call.
 *
 * ok = HTTP 2xx and R_Success true. `error` is a short reason fit for an admin (never the API key):
 * the provider's R_Error / R_Message, an HTTP status, or a connection error. `transport` is true when
 * no answer came back at all — the call may or may not have been carried out.
 *
 * @return array{ok:bool, http:int, data:?array, error:?string, code:?int, transport:bool}
 */
function zarincall_call(string $action, array $body, int $timeoutMs = 30000): array {
    $result = static fn(bool $ok, int $http, ?array $data, ?string $error, bool $transport = false): array => [
        'ok' => $ok, 'http' => $http, 'data' => $data, 'error' => $error, 'transport' => $transport,
        'code' => is_numeric(zarincall_field($data, 'R_Code')) ? (int)zarincall_field($data, 'R_Code') : null,
    ];
    $apiKey = zarincall_api_key();
    if ($apiKey === '') {
        return $result(false, 0, null, 'api_key_missing');
    }
    $url = zarincall_base_url() . '/' . $action;
    $check = gateway_endpoint_allowed($url);
    if (!$check['ok']) {
        return $result(false, 0, null, (string)$check['reason']);
    }

    $ch = curl_init($url);
    $options = [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body === [] ? new stdClass() : $body, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'Authorization: basic apikey:' . $apiKey],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT_MS => 5000,
        CURLOPT_TIMEOUT_MS => $timeoutMs,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];
    if ($check['resolve'] !== []) {
        $options[CURLOPT_RESOLVE] = $check['resolve'];
    }
    curl_setopt_array($ch, $options);
    $started = microtime(true);
    $raw = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    Metrics::timing('voice.provider.duration', (microtime(true) - $started) * 1000, ['action' => $action]);

    if ($raw === false) {
        Metrics::increment('voice.provider.calls', 1, ['action' => $action, 'result' => 'transport']);
        Logger::warning('voice.provider.transport_error', ['action' => $action, 'error' => mb_strimwidth($curlError, 0, 200, '…')]);
        return $result(false, 0, null, 'connection: ' . mb_strimwidth($curlError, 0, 200, '…'), true);
    }
    $decoded = json_decode((string)$raw, true);
    $data = is_array($decoded) ? $decoded : null;
    $success = zarincall_field($data, 'R_Success');
    $ok = $http >= 200 && $http < 300 && ($success === true || $success === 1 || $success === 'true');
    Metrics::increment('voice.provider.calls', 1, ['action' => $action, 'result' => $ok ? 'ok' : 'error']);
    if ($ok) {
        return $result(true, $http, $data, null);
    }
    $reason = trim((string)(zarincall_field($data, 'R_Error') ?? ''));
    if ($reason === '') {
        $reason = trim((string)(zarincall_field($data, 'R_Message') ?? ''));
    }
    if ($reason === '') {
        $reason = 'http ' . $http . ': ' . mb_strimwidth((string)$raw, 0, 200, '…');
    }
    Logger::warning('voice.provider.refused', ['action' => $action, 'http' => $http, 'code' => zarincall_field($data, 'R_Code')]);
    return $result(false, $http, $data, mb_strimwidth($reason, 0, 400, '…'));
}

/** A mobile number in ZarinCall's 09xxxxxxxxx form, from any form normalize_msisdn() accepts. */
function zarincall_number(string $msisdn): ?string {
    $n = normalize_msisdn($msisdn);
    if ($n === null || !preg_match('/^989\d{9}$/', $n)) {
        return null;
    }
    return '0' . substr($n, 2);
}

/* ---------- Typed calls ---------- */

/** @return array{ok:bool, msg_id:?string, error:?string, transport:bool} */
function zarincall_message_send(string $voiceId, array $numbers): array {
    $r = zarincall_call('Message/Send', ['voiceId' => $voiceId, 'numbers' => array_values($numbers)]);
    return ['ok' => $r['ok'], 'msg_id' => zarincall_id(zarincall_field($r['data'], 'MsgID')), 'error' => $r['error'], 'transport' => $r['transport']];
}

/** @return array{ok:bool, msg_id:?string, error:?string, transport:bool} */
function zarincall_message_send_ivc(string $voiceId, array $numbers, int $digit, string $sms): array {
    $r = zarincall_call('Message/SendIVC', ['voiceId' => $voiceId, 'numbers' => array_values($numbers), 'digit' => $digit, 'sms' => $sms]);
    return ['ok' => $r['ok'], 'msg_id' => zarincall_id(zarincall_field($r['data'], 'MsgID')), 'error' => $r['error'], 'transport' => $r['transport']];
}

/** OTP read out by phone; with $voiceId (OTP2) an announcement plays first. @return array{ok:bool, msg_id:?string, error:?string, transport:bool} */
function zarincall_message_send_otp(string $code, string $number, ?string $voiceId = null): array {
    $r = $voiceId === null
        ? zarincall_call('Message/SendOTP', ['code' => $code, 'number' => $number], 15000)
        : zarincall_call('Message/SendOTP2', ['code' => $code, 'number' => $number, 'voiceId' => $voiceId], 15000);
    return ['ok' => $r['ok'], 'msg_id' => zarincall_id(zarincall_field($r['data'], 'MsgID')), 'error' => $r['error'], 'transport' => $r['transport']];
}

/**
 * A sent message's progress.
 *
 * @return array{ok:bool, error:?string, rec_count:?int, rec_success:int, rec_failed:int, send_status:?int, send_des:?string}
 */
function zarincall_message_get(string $msgId): array {
    $r = zarincall_call('Message/Get', ['msgId' => $msgId], 15000);
    $data = zarincall_field($r['data'], 'Data');
    $data = is_array($data) ? $data : null;
    $int = static fn(string $name): ?int => is_numeric(zarincall_field($data, $name)) ? (int)zarincall_field($data, $name) : null;
    $des = zarincall_field($data, 'SendDes');
    return [
        'ok' => $r['ok'] && $data !== null,
        'error' => $r['ok'] && $data === null ? 'no_data' : $r['error'],
        'rec_count' => $int('RecCount'),
        'rec_success' => max(0, (int)$int('RecSuccess')),
        'rec_failed' => max(0, (int)$int('RecFailed')),
        'send_status' => $int('SendStatus'),
        'send_des' => is_string($des) && $des !== '' ? mb_strimwidth($des, 0, 480, '…') : null,
    ];
}

/** @return array{ok:bool, error:?string} */
function zarincall_message_delete(string $msgId): array {
    $r = zarincall_call('Message/Delete', ['msgId' => $msgId], 15000);
    return ['ok' => $r['ok'], 'error' => $r['error']];
}

/** @return array{ok:bool, voice_id:?string, error:?string} */
function zarincall_voice_upload(string $fileName, string $bytes): array {
    $r = zarincall_call('Voice/UploadBase64', ['fileName' => $fileName, 'fileByteBase64' => base64_encode($bytes)], 120000);
    $voiceId = zarincall_id(zarincall_field($r['data'], 'VoiceID'));
    return ['ok' => $r['ok'] && $voiceId !== null, 'voice_id' => $voiceId, 'error' => $r['ok'] && $voiceId === null ? 'no_voice_id' : $r['error']];
}

/** @return array{ok:bool, voice_id:?string, voice_base64:?string, error:?string} */
function zarincall_voice_text_to_voice(string $text, int $speaker, string $title, int $saveDays): array {
    $r = zarincall_call('Voice/TextToVoice', ['title' => $title, 'gender' => $speaker, 'text' => $text, 'saveDays' => $saveDays], 120000);
    $voiceId = zarincall_id(zarincall_field($r['data'], 'VoiceID'));
    $b64 = zarincall_field($r['data'], 'VoiceBase64');
    $b64 = is_string($b64) && $b64 !== '' ? $b64 : null;
    $ok = $r['ok'] && ($voiceId !== null || $b64 !== null);
    return ['ok' => $ok, 'voice_id' => $voiceId, 'voice_base64' => $b64, 'error' => $r['ok'] && !$ok ? 'no_voice' : $r['error']];
}

/** @return array{ok:bool, error:?string, duration:?int, status:?int, title:?string, media_type:?string, size:?int} */
function zarincall_voice_get(string $voiceId): array {
    $r = zarincall_call('Voice/Get', ['voiceId' => $voiceId], 15000);
    $data = zarincall_field($r['data'], 'Data');
    $data = is_array($data) ? $data : null;
    $int = static fn(string $name): ?int => is_numeric(zarincall_field($data, $name)) ? (int)zarincall_field($data, $name) : null;
    $str = static fn(string $name): ?string => is_string(zarincall_field($data, $name)) ? (string)zarincall_field($data, $name) : null;
    return [
        'ok' => $r['ok'] && $data !== null,
        'error' => $r['ok'] && $data === null ? 'no_data' : $r['error'],
        'duration' => $int('Duration'),
        'status' => $int('VoiceStatus'),
        'title' => $str('Title'),
        'media_type' => $str('MediaTypeName'),
        'size' => $int('DataSize'),
    ];
}

/** @return array{ok:bool, file_name:?string, bytes:?string, error:?string} */
function zarincall_voice_download(string $voiceId): array {
    $r = zarincall_call('Voice/Download', ['voiceId' => $voiceId], 60000);
    $b64 = zarincall_field($r['data'], 'FileByteBase64');
    $bytes = is_string($b64) ? base64_decode($b64, true) : false;
    $name = zarincall_field($r['data'], 'FileName');
    return [
        'ok' => $r['ok'] && $bytes !== false && $bytes !== '',
        'file_name' => is_string($name) ? $name : null,
        'bytes' => $bytes === false ? null : $bytes,
        'error' => $r['ok'] && ($bytes === false || $bytes === '') ? 'no_file' : $r['error'],
    ];
}

/**
 * The account's voice files at ZarinCall, newest first, with their VoiceStatus — what the admin reads
 * the "ready to send" code from (ZarinCall does not document its status codes).
 *
 * @return array{ok:bool, error:?string, files:list<array{voice_id:?string, title:?string, duration:?int, status:?int, add_date:?string}>}
 */
function zarincall_voice_list(int $page = 1, int $pageSize = 50): array {
    $r = zarincall_call('Voice/List', ['voiceStatus' => null, 'addDateFrom' => null, 'addDateTo' => null,
        'page' => max(1, $page), 'pageSize' => max(1, min(200, $pageSize)), 'search' => null], 20000);
    $list = zarincall_field($r['data'], 'DataList');
    $files = [];
    foreach (is_array($list) ? $list : [] as $row) {
        if (!is_array($row)) continue;
        $int = static fn(string $n): ?int => is_numeric(zarincall_field($row, $n)) ? (int)zarincall_field($row, $n) : null;
        $str = static fn(string $n): ?string => is_scalar(zarincall_field($row, $n)) ? (string)zarincall_field($row, $n) : null;
        $files[] = ['voice_id' => zarincall_id(zarincall_field($row, 'VoiceID')), 'title' => $str('Title'), 'duration' => $int('Duration'),
                    'status' => $int('VoiceStatus'), 'add_date' => $str('AddDateFarsi') ?? $str('AddDate')];
    }
    return ['ok' => $r['ok'], 'error' => $r['error'], 'files' => $files];
}

/** @return array{ok:bool, error:?string} */
function zarincall_voice_delete(string $voiceId): array {
    $r = zarincall_call('Voice/Delete', ['voiceId' => $voiceId], 15000);
    return ['ok' => $r['ok'], 'error' => $r['error']];
}

/** The ZarinCall account's remaining balance. @return array{ok:bool, pulse:?int, error:?string} */
function zarincall_user_pulse(): array {
    $r = zarincall_call('User/GetPulse', [], 15000);
    $pulse = zarincall_field($r['data'], 'Pulse');
    return ['ok' => $r['ok'], 'pulse' => is_numeric($pulse) ? (int)$pulse : null, 'error' => $r['error']];
}
