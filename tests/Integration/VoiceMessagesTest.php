<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Voice messages through ZarinCall (app/VoiceMessages.php) against a ZarinCall-shaped API
 * (tests/fixtures/fake_zarincall_server.php): request shapes, the encrypted API key, pricing in
 * pulses, the reserve → charge answered → release unanswered money flow, scheduling and cancelling,
 * provider refusal, crash recovery, voice OTP and text-to-speech.
 */
final class VoiceMessagesTest extends IntegrationTestCase
{
    private static $serverProcess = null;
    private static string $baseUrl = '';
    private static string $stateFile = '';
    private static string $recordFile = '';

    private int $userId;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if ((string)getenv('ELLSMS_TEST_DB_HOST') === '' || !function_exists('proc_open')) {
            self::markTestSkipped('needs ELLSMS_TEST_DB_HOST and proc_open()');
        }
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $name = stream_socket_get_name($socket, false);
        $port = (int)substr($name, strrpos($name, ':') + 1);
        fclose($socket);
        self::$baseUrl = 'http://127.0.0.1:' . $port . '/Apiv2';
        $tag = bin2hex(random_bytes(6));
        self::$stateFile = sys_get_temp_dir() . '/ellsms_zc_state_' . $tag . '.json';
        self::$recordFile = sys_get_temp_dir() . '/ellsms_zc_record_' . $tag . '.jsonl';
        $env = getenv();
        $env['ZARINCALL_FAKE_STATE'] = self::$stateFile;
        $env['ZARINCALL_FAKE_RECORD'] = self::$recordFile;
        self::$serverProcess = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, dirname(__DIR__) . '/fixtures/fake_zarincall_server.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env
        );
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($conn) { fclose($conn); return; }
            usleep(50000);
        }
        self::markTestSkipped('Fake ZarinCall did not become reachable in time.');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$serverProcess !== null) {
            proc_terminate(self::$serverProcess);
            proc_close(self::$serverProcess);
            self::$serverProcess = null;
        }
        @unlink(self::$stateFile);
        @unlink(self::$recordFile);
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        putenv('SMS_GATEWAY_MASTER_KEY=voice-test-master-key-0123456789abcdef');
        file_put_contents(self::$stateFile, '');
        file_put_contents(self::$recordFile, '');
        $settings = [
            'voice_enabled' => '1', 'voice_base_url' => self::$baseUrl,
            'voice_price_per_pulse' => '1000', 'voice_otp_price' => '500', 'voice_ivc_extra_price' => '200', 'voice_tts_price' => '300',
            'voice_pulse_seconds' => '30', 'voice_batch_size' => '2', 'voice_max_recipients' => '100',
            'voice_poll_interval_seconds' => '30', 'voice_settle_after_hours' => '48', 'voice_otp_min_interval_seconds' => '60',
        ];
        foreach ($settings as $k => $v) {
            set_setting($k, $v);
        }
        zarincall_api_key_save('test-key');
        $this->userId = $this->makeUser();
        wallet_credit($this->userId, 100000, 'purchase', 'test', 'seed:' . $this->userId, 'seed:' . $this->userId);
    }

    protected function tearDown(): void
    {
        putenv('SMS_GATEWAY_MASTER_KEY');
        parent::tearDown();
    }

    private function user(?int $id = null): array
    {
        return ['id' => $id ?? $this->userId, 'role' => 'user', 'organization_id' => null];
    }

    private function available(): int
    {
        return (int)wallet_balance($this->userId)['available'];
    }

    /** @return list<array{path:string, authorization:string, body:array}> */
    private function recorded(?string $action = null): array
    {
        $out = [];
        foreach (array_filter(explode("\n", (string)file_get_contents(self::$recordFile))) as $line) {
            $r = json_decode($line, true);
            $r['body'] = json_decode((string)$r['body'], true) ?? [];
            if ($action === null || str_ends_with((string)$r['path'], '/' . $action)) $out[] = $r;
        }
        return $out;
    }

    private function wav(int $seconds): string
    {
        $rate = 8000;
        $data = str_repeat("\x00", $seconds * $rate);
        return 'RIFF' . pack('V', 36 + strlen($data)) . 'WAVE'
            . 'fmt ' . pack('V', 16) . pack('v', 1) . pack('v', 1) . pack('V', $rate) . pack('V', $rate) . pack('v', 1) . pack('v', 8)
            . 'data' . pack('V', strlen($data)) . $data;
    }

    private function uploadFile(int $seconds = 45, ?int $userId = null): int
    {
        $r = voice_file_upload($this->user($userId), 'نوروز', 'greeting.wav', $this->wav($seconds));
        $this->assertTrue($r['ok'], (string)($r['error'] ?? ''));
        return (int)$r['id'];
    }

    public function testApiKeyIsEncryptedAtRestAndUnreadableWithAnotherMasterKey(): void
    {
        $stored = (string)setting_fresh('voice_api_key', '');
        $this->assertStringNotContainsString('test-key', $stored);
        $this->assertSame('test-key', zarincall_api_key());
        putenv('SMS_GATEWAY_MASTER_KEY=another-master-key-0123456789abcdefgh');
        $this->assertSame('', zarincall_api_key(), 'a different master key must not decrypt it');
        $this->assertTrue(zarincall_api_key_stored());
    }

    public function testUploadSendsTheSdkRequestShapeAndRecordsTheDuration(): void
    {
        $bytes = $this->wav(45);
        $id = $this->uploadFile(45);

        $call = $this->recorded('Voice/UploadBase64')[0];
        $this->assertSame('/Apiv2/Voice/UploadBase64', $call['path']);
        $this->assertSame('basic apikey:test-key', $call['authorization']);
        $this->assertSame(base64_encode($bytes), $call['body']['fileByteBase64']);
        $this->assertStringEndsWith('.wav', $call['body']['fileName']);

        $file = voice_file_find($this->user(), $id);
        $this->assertSame(45, (int)$file['duration_seconds']);
        $this->assertSame(2, voice_pulses(45));
        $this->assertSame('نوروز', $file['title']);
    }

    public function testUploadRefusesAFileWhoseContentDoesNotMatchItsExtension(): void
    {
        $r = voice_file_upload($this->user(), 't', 'x.wav', 'not audio at all');
        $this->assertFalse($r['ok']);
        $this->assertSame([], $this->recorded());
    }

    public function testSendReservesTheWorstCaseThenChargesOnlyAnsweredCalls(): void
    {
        $fileId = $this->uploadFile(45); // 2 pulses → 2000 Rial per recipient
        db()->prepare('INSERT INTO ellsms_blacklist (user_id, mobile) VALUES (?, ?)')->execute([$this->userId, '989124444444']);

        $r = voice_message_create($this->user(), [
            'kind' => 'voice', 'voice_file_id' => $fileId,
            'destinations' => ['09121111111', '+989121111199', '۰۹۱۲۲۲۲۲۲۲۲', '9123333399', '09124444444', 'not-a-number', '09121111111'],
        ]);
        $this->assertTrue($r['ok'], (string)($r['error'] ?? ''));
        $this->assertSame(4, $r['recipients']);
        $this->assertSame(1, $r['blocked']);
        $this->assertSame(1, $r['invalid']);
        $this->assertSame(8000, $r['cost']);
        $this->assertSame('sent', $r['status']);
        $this->assertSame(92000, $this->available(), 'the worst case is reserved');

        $sends = $this->recorded('Message/Send');
        $this->assertCount(2, $sends, 'batch size 2 → two provider calls');
        $voiceId = voice_file_find($this->user(), $fileId)['provider_voice_id'];
        $this->assertSame(['voiceId' => $voiceId, 'numbers' => ['09121111111', '09121111199']], $sends[0]['body']);
        $this->assertSame(['09122222222', '09123333399'], $sends[1]['body']['numbers']);

        $this->assertSame(2, voice_poll_sent());
        $m = voice_message_get((int)$r['id']);
        $this->assertSame('completed', $m['status']);
        $this->assertSame(2, (int)$m['success_count']);
        $this->assertSame(2, (int)$m['failed_count']);
        $this->assertSame(4000, (int)$m['charged_cost']);
        $this->assertSame(96000, $this->available(), 'unanswered calls are given back');
        $this->assertSame(0, (int)wallet_balance($this->userId)['reserved']);

        $this->assertSame(0, voice_poll_sent(), 'settled batches are not polled again');
        $this->assertSame(96000, $this->available());
    }

    public function testInteractiveSendCarriesDigitAndSmsAndCostsTheExtra(): void
    {
        $fileId = $this->uploadFile(20); // 1 pulse
        $r = voice_message_create($this->user(), [
            'kind' => 'ivc', 'voice_file_id' => $fileId, 'destinations' => ['09121111111'],
            'ivc_digit' => '۱', 'ivc_sms' => 'برای ثبت‌نام کلیک کنید',
        ]);
        $this->assertTrue($r['ok'], (string)($r['error'] ?? ''));
        $this->assertSame(1200, $r['cost']);
        $body = $this->recorded('Message/SendIVC')[0]['body'];
        $this->assertSame(1, $body['digit']);
        $this->assertSame('برای ثبت‌نام کلیک کنید', $body['sms']);
        $this->assertSame(['09121111111'], $body['numbers']);

        $bad = voice_message_create($this->user(), ['kind' => 'ivc', 'voice_file_id' => $fileId, 'destinations' => ['09121111111'], 'ivc_digit' => '12', 'ivc_sms' => 'x']);
        $this->assertSame('invalid_ivc_digit', $bad['error_code']);
    }

    public function testScheduledSendWaitsForItsTimeAndCancellingGivesTheMoneyBack(): void
    {
        $fileId = $this->uploadFile(20);
        $later = date('Y-m-d H:i:s', time() + 3600);
        $a = voice_message_create($this->user(), ['voice_file_id' => $fileId, 'destinations' => ['09121111111', '09122222222'], 'scheduled_at' => $later]);
        $b = voice_message_create($this->user(), ['voice_file_id' => $fileId, 'destinations' => ['09123333333'], 'scheduled_at' => $later]);
        $this->assertSame('scheduled', $a['status']);
        $this->assertSame(100000 - 3000, $this->available());
        $this->assertSame(0, voice_dispatch_due());
        $this->assertSame([], $this->recorded('Message/Send'));

        $this->assertTrue(voice_message_cancel($this->user(), (int)$b['id'])['ok']);
        $this->assertSame(100000 - 2000, $this->available());
        $this->assertFalse(voice_message_cancel($this->user(), (int)$b['id'])['ok'], 'cancelled once only');

        db()->prepare('UPDATE ellsms_voice_messages SET scheduled_at = ? WHERE id = ?')->execute([date('Y-m-d H:i:s', time() - 60), (int)$a['id']]);
        $this->assertSame(1, voice_dispatch_due());
        $this->assertSame('sent', voice_message_get((int)$a['id'])['status']);
        $this->assertCount(1, $this->recorded('Message/Send'));
        $this->assertFalse(voice_message_cancel($this->user(), (int)$a['id'])['ok'], 'a started send cannot be cancelled');

        $past = voice_message_create($this->user(), ['voice_file_id' => $fileId, 'destinations' => ['09121111111'], 'scheduled_at' => date('Y-m-d H:i:s', time() - 60)]);
        $this->assertSame('schedule_in_past', $past['error_code']);
    }

    public function testProviderRefusalFailsTheSendAndReleasesEverything(): void
    {
        $fileId = $this->uploadFile(20);
        $r = voice_message_create($this->user(), ['voice_file_id' => $fileId, 'destinations' => ['09120000000', '09121111111']]);
        $this->assertTrue($r['ok']);
        $this->assertSame('failed', $r['status']);
        $m = voice_message_get((int)$r['id']);
        $this->assertSame('blocked number', $m['error']);
        $this->assertSame(100000, $this->available());
        $this->assertSame(0, (int)wallet_balance($this->userId)['reserved']);
    }

    public function testInsufficientCreditIsRefusedBeforeAnyCall(): void
    {
        $poor = $this->makeUser();
        wallet_credit($poor, 500, 'purchase', 'test', 'seed:' . $poor, 'seed:' . $poor);
        $fileId = $this->uploadFile(20, $poor);
        file_put_contents(self::$recordFile, '');
        $r = voice_message_create($this->user($poor), ['voice_file_id' => $fileId, 'destinations' => ['09121111111']]);
        $this->assertFalse($r['ok']);
        $this->assertSame('insufficient_credit', $r['error_code']);
        $this->assertSame([], $this->recorded('Message/Send'));
        $this->assertSame(500, (int)wallet_balance($poor)['available']);
    }

    public function testUnfinishedCountsWaitThenSettleAtTheDeadlineChargingAllButReportedFailures(): void
    {
        $fileId = $this->uploadFile(20);
        set_setting('voice_batch_size', '10');
        $r = voice_message_create($this->user(), ['voice_file_id' => $fileId, 'destinations' => ['09121111111', '09121111188', '09121111199']]);
        $this->assertSame(97000, $this->available());

        $this->assertSame(0, voice_poll_sent(), 'one recipient has no outcome yet');
        $batch = voice_message_batches((int)$r['id'])[0];
        $this->assertSame('sent', $batch['status']);
        $this->assertSame(3, (int)$batch['rec_count']);
        $this->assertSame(0, voice_poll_sent(), 'not polled again before the interval');

        db()->prepare('UPDATE ellsms_voice_message_batches SET sent_at = ?, last_polled_at = NULL WHERE id = ?')
            ->execute([date('Y-m-d H:i:s', time() - 49 * 3600), (int)$batch['id']]);
        $this->assertSame(1, voice_poll_sent());
        $m = voice_message_get((int)$r['id']);
        $this->assertSame('completed', $m['status']);
        $this->assertSame(2000, (int)$m['charged_cost'], 'answered + unknown are charged, the reported failure is not');
        $this->assertSame(98000, $this->available());
    }

    public function testCrashedSendIsRecoveredWithoutCallingAnyoneTwice(): void
    {
        $fileId = $this->uploadFile(20);
        $r = voice_message_create($this->user(), ['voice_file_id' => $fileId, 'destinations' => ['09121111111', '09122222222', '09123333333'], 'scheduled_at' => date('Y-m-d H:i:s', time() + 600)]);
        // A process claimed the send, was mid-call on batch 1 and died before batch 2.
        db()->prepare("UPDATE ellsms_voice_messages SET status = 'sending', sending_started_at = ? WHERE id = ?")->execute([date('Y-m-d H:i:s', time() - 3600), (int)$r['id']]);
        db()->prepare("UPDATE ellsms_voice_message_batches SET status = 'sending' WHERE message_id = ? AND batch_no = 1")->execute([(int)$r['id']]);

        $this->assertSame(1, voice_recover_stuck());
        $sends = $this->recorded('Message/Send');
        $this->assertCount(1, $sends);
        $this->assertSame(['09123333333'], $sends[0]['body']['numbers']);
        $batches = voice_message_batches((int)$r['id']);
        $this->assertSame('failed', $batches[0]['status']);
        $this->assertSame('interrupted', $batches[0]['error']);
        $this->assertSame('sent', $batches[1]['status']);

        voice_poll_sent();
        $m = voice_message_get((int)$r['id']);
        $this->assertSame('completed', $m['status']);
        $this->assertSame(1000, (int)$m['charged_cost']);
        $this->assertSame(99000, $this->available());
    }

    public function testVoiceOtpIsChargedForAnAnsweredCallAndNeverStoresTheCode(): void
    {
        $r = voice_otp_send($this->user(), '09121111111', '48213');
        $this->assertTrue($r['ok'], (string)($r['error'] ?? ''));
        $call = $this->recorded('Message/SendOTP')[0]['body'];
        $this->assertSame(['code' => '48213', 'number' => '09121111111'], $call);
        $this->assertSame(99500, $this->available());

        $stored = json_encode(db()->query('SELECT * FROM ellsms_voice_messages m JOIN ellsms_voice_message_batches b ON b.message_id = m.id WHERE m.id = ' . (int)$r['id'])->fetchAll());
        $this->assertStringNotContainsString('48213', (string)$stored);

        voice_poll_sent();
        $this->assertSame('completed', voice_message_get((int)$r['id'])['status']);
        $this->assertSame(99500, $this->available());

        $again = voice_otp_send($this->user(), '09121111111', '11111');
        $this->assertSame('too_frequent', $again['error_code']);
    }

    public function testVoiceOtpWithoutAMsgIdIsChargedAtOnceAndOtp2CarriesTheAnnouncement(): void
    {
        $r = voice_otp_send($this->user(), '09122222222', '00000');
        $this->assertTrue($r['ok']);
        $this->assertSame('completed', voice_message_get((int)$r['id'])['status']);
        $this->assertSame(99500, $this->available());

        $fileId = $this->uploadFile(45);
        $r2 = voice_otp_send($this->user(), '09123333333', '7788', $fileId);
        $this->assertTrue($r2['ok']);
        $this->assertSame(2500, $r2['cost'], 'OTP price + 2 pulses of announcement');
        $call = $this->recorded('Message/SendOTP2')[0]['body'];
        $this->assertSame(voice_file_find($this->user(), $fileId)['provider_voice_id'], $call['voiceId']);
        $this->assertSame('7788', $call['code']);

        $this->assertSame('invalid_code', voice_otp_send($this->user(), '09124444444', '12')['error_code']);
        $this->assertSame('invalid_destination', voice_otp_send($this->user(), '0212222', '1234')['error_code']);
    }

    public function testTextToVoiceIsChargedAndRecorded(): void
    {
        $r = voice_file_tts($this->user(), 'خوش‌آمد', 'سلام، به سامانه خوش آمدید', 2);
        $this->assertTrue($r['ok'], (string)($r['error'] ?? ''));
        $body = $this->recorded('Voice/TextToVoice')[0]['body'];
        $this->assertSame(['title' => 'خوش‌آمد', 'gender' => 2, 'text' => 'سلام، به سامانه خوش آمدید', 'saveDays' => 365], $body);
        $file = voice_file_find($this->user(), (int)$r['id']);
        $this->assertSame('tts', $file['source']);
        $this->assertSame(12, (int)$file['duration_seconds']);
        $this->assertSame(99700, $this->available());
    }

    public function testOnlyTheSpeakersTheAdminConfiguredAreAccepted(): void
    {
        set_setting('voice_tts_speakers', "5=زن\n6=مرد");
        $this->assertSame([5 => 'زن', 6 => 'مرد'], voice_tts_speakers());
        $this->assertFalse(voice_file_tts($this->user(), 't', 'سلام', 2)['ok']);
        $this->assertSame([], $this->recorded('Voice/TextToVoice'));
        $this->assertTrue(voice_file_tts($this->user(), 't', 'سلام', 6)['ok']);
        $this->assertSame(6, $this->recorded('Voice/TextToVoice')[0]['body']['gender']);
    }

    public function testFilesAndSendsAreInvisibleToOtherCustomers(): void
    {
        $fileId = $this->uploadFile(20);
        $r = voice_message_create($this->user(), ['voice_file_id' => $fileId, 'destinations' => ['09121111111']]);
        $other = $this->makeUser();
        $this->assertNull(voice_file_find($this->user($other), $fileId));
        $this->assertNull(voice_message_find($this->user($other), (int)$r['id']));
        $this->assertSame('voice_file_not_found', voice_message_create($this->user($other), ['voice_file_id' => $fileId, 'destinations' => ['09121111111']])['error_code']);
        $this->assertNotNull(voice_message_find(['id' => $other, 'role' => 'admin'], (int)$r['id']), 'a platform admin sees every send');
    }

    public function testDeletingAFileInUseIsRefusedAndOtherwiseRemovesItAtZarinCall(): void
    {
        $fileId = $this->uploadFile(20);
        $s = voice_message_create($this->user(), ['voice_file_id' => $fileId, 'destinations' => ['09121111111'], 'scheduled_at' => date('Y-m-d H:i:s', time() + 600)]);
        $this->assertFalse(voice_file_delete($this->user(), $fileId)['ok']);
        voice_message_cancel($this->user(), (int)$s['id']);
        $this->assertTrue(voice_file_delete($this->user(), $fileId)['ok']);
        $this->assertCount(1, $this->recorded('Voice/Delete'));
        $this->assertNull(voice_file_find($this->user(), $fileId));
    }

    public function testDisabledServiceRefusesAndConnectionCheckReadsTheBalance(): void
    {
        $this->assertSame(['ok' => true, 'pulse' => 5000, 'error' => null], zarincall_user_pulse());
        set_setting('voice_enabled', '0');
        $this->assertSame('not_configured', voice_message_create($this->user(), ['voice_file_id' => 1, 'destinations' => ['09121111111']])['error_code']);
        $this->assertSame('not_configured', voice_otp_send($this->user(), '09121111111', '1234')['error_code']);
    }
}
