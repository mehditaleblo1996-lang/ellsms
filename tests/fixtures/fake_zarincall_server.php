<?php
/**
 * A ZarinCall-shaped API for the voice message tests (tests/Integration/VoiceMessagesTest.php,
 * VoiceApiHttpTest.php), following the contract of ZarinCall's own client library: POST {base}/{Ctrl}/{Action},
 * `Authorization: basic apikey:<key>`, JSON in and out, {R_Success, R_Code, R_Error, R_Message, ...}.
 *
 * Run under PHP's built-in server: php -S 127.0.0.1:PORT tests/fixtures/fake_zarincall_server.php
 * Env: ZARINCALL_FAKE_STATE (JSON state file), ZARINCALL_FAKE_RECORD (one JSON line per request),
 *      ZARINCALL_FAKE_KEY (expected API key, default test-key).
 *
 * Scripted outcomes, by the recipient number's last digits:
 *   ...99  the call is not answered (RecFailed)
 *   ...88  no outcome yet (neither RecSuccess nor RecFailed) — the message never finishes
 *   09120000000 in a send: the whole request is refused (R_Success false)
 * An OTP with code 00000 is accepted without a MsgID. A WAV upload reports its real duration,
 * anything else 20 seconds; text-to-speech reports 12 seconds.
 */

declare(strict_types=1);

$path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
$raw = (string)file_get_contents('php://input');
$auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');

$recordFile = getenv('ZARINCALL_FAKE_RECORD');
if (is_string($recordFile) && $recordFile !== '') {
    file_put_contents($recordFile, json_encode(['path' => $path, 'authorization' => $auth, 'body' => $raw], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}

header('Content-Type: application/json; charset=utf-8');
$reply = static function (array $data, bool $ok = true, ?string $error = null): never {
    echo json_encode(['R_Success' => $ok, 'R_Code' => $ok ? 0 : 1, 'R_Error' => $error, 'R_Message' => $ok ? 'ok' : null] + $data, JSON_UNESCAPED_UNICODE);
    exit;
};

$key = getenv('ZARINCALL_FAKE_KEY') ?: 'test-key';
if ($auth !== 'basic apikey:' . $key) {
    http_response_code(401);
    $reply([], false, 'unauthorized');
}

$stateFile = (string)getenv('ZARINCALL_FAKE_STATE');
$fp = fopen($stateFile, 'c+');
flock($fp, LOCK_EX);
$state = json_decode((string)stream_get_contents($fp), true) ?: ['voices' => [], 'messages' => []];
$save = static function () use (&$state, $fp): void {
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($state));
    fflush($fp);
};
$body = json_decode($raw, true) ?: [];
$uuid = static fn(): string => strtoupper(bin2hex(random_bytes(4)) . '-' . bin2hex(random_bytes(2)) . '-' . bin2hex(random_bytes(2)) . '-' . bin2hex(random_bytes(2)) . '-' . bin2hex(random_bytes(6)));
$wavSeconds = static function (string $bytes): ?int {
    if (substr($bytes, 0, 4) !== 'RIFF') return null;
    $rate = unpack('V', substr($bytes, 28, 4))[1] ?? 0;
    return $rate > 0 ? (int)ceil((strlen($bytes) - 44) / $rate) : null;
};
$messageFor = static function (array $numbers) use ($uuid, &$state, $save): string {
    $id = $uuid();
    $failed = count(array_filter($numbers, static fn($n) => str_ends_with((string)$n, '99')));
    $pending = count(array_filter($numbers, static fn($n) => str_ends_with((string)$n, '88')));
    $state['messages'][$id] = ['RecCount' => count($numbers), 'RecFailed' => $failed, 'RecSuccess' => count($numbers) - $failed - $pending];
    $save();
    return $id;
};

$action = preg_replace('#^.*/Apiv2/#i', '', $path);
switch ($action) {
    case 'Voice/UploadBase64':
        $bytes = base64_decode((string)($body['fileByteBase64'] ?? ''), true);
        if ($bytes === false || $bytes === '' || !is_string($body['fileName'] ?? null)) $reply([], false, 'bad file');
        $id = $uuid();
        $state['voices'][$id] = ['Duration' => $wavSeconds($bytes) ?? 20, 'Title' => $body['fileName'], 'b64' => base64_encode($bytes)];
        $save();
        $reply(['VoiceID' => $id]);
    case 'Voice/TextToVoice':
        $id = $uuid();
        $state['voices'][$id] = ['Duration' => 12, 'Title' => (string)($body['title'] ?? ''), 'b64' => base64_encode('RIFF-tts')];
        $save();
        $reply(['VoiceID' => $id, 'VoiceBase64' => base64_encode('RIFF-tts')]);
    case 'Voice/Get':
        $v = $state['voices'][$body['voiceId'] ?? ''] ?? null;
        if ($v === null) $reply(['Data' => null], false, 'not found');
        $reply(['Data' => ['VoiceID' => $body['voiceId'], 'Title' => $v['Title'], 'Duration' => $v['Duration'], 'VoiceStatus' => 1, 'MediaTypeName' => 'audio/wav', 'DataSize' => 100, 'StoreForEver' => false]]);
    case 'Voice/Download':
        $v = $state['voices'][$body['voiceId'] ?? ''] ?? null;
        if ($v === null) $reply([], false, 'not found');
        $reply(['FileName' => 'voice.wav', 'FileByteBase64' => $v['b64']]);
    case 'Voice/Delete':
        unset($state['voices'][$body['voiceId'] ?? '']);
        $save();
        $reply([]);
    case 'Message/Send':
    case 'Message/SendIVC':
        $numbers = (array)($body['numbers'] ?? []);
        if (in_array('09120000000', $numbers, true)) $reply([], false, 'blocked number');
        $reply(['MsgID' => $messageFor($numbers)]);
    case 'Message/SendOTP':
    case 'Message/SendOTP2':
        if (($body['code'] ?? '') === '00000') $reply([]);
        $reply(['MsgID' => $messageFor([(string)($body['number'] ?? '')])]);
    case 'Message/Get':
        $m = $state['messages'][$body['msgId'] ?? ''] ?? null;
        if ($m === null) $reply(['Data' => null], false, 'not found');
        $reply(['Data' => $m + ['MsgID' => $body['msgId'], 'SendStatus' => 2, 'SendDes' => 'ارسال شد']]);
    case 'User/GetPulse':
        $reply(['Pulse' => 5000]);
}
http_response_code(404);
$reply([], false, 'unknown action ' . $action);
