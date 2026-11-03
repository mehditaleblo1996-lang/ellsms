<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * The AI secretary end to end against real MySQL: an inbound SMS on a line with an assistant goes
 * through run_autoreply_pass() (keyword rules first, opt-out keywords never) into ellsms_ai_jobs, and
 * ai_secretary_run_pass() answers it with a fake OpenAI-compatible model
 * (tests/fixtures/fake-openai-server.php, standing in for Hermes) and sends the answer through a
 * recording SMS gateway (tests/fixtures/recording_gateway_server.php).
 *
 * The fake model echoes the last user message as «پاسخ آزمایشی: …» and logs every request it gets,
 * so a test can see what the model was asked — including the conversation memory.
 */
final class AiSecretaryFlowTest extends IntegrationTestCase
{
    private static $aiServer = null;
    private static $gatewayServer = null;
    private static int $aiPort = 0;
    private static string $gatewayBase = '';
    private static string $aiLog = '';
    private static string $gatewayLog = '';

    private string $line = '5000777';
    private string $customer = '989121234567';
    private int $ownerId = 0;
    private int $modelId = 0;
    private int $providerId = 0;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if ((string)getenv('ELLSMS_TEST_DB_HOST') === '' || !function_exists('proc_open')) {
            self::markTestSkipped('needs ELLSMS_TEST_DB_HOST and proc_open()');
        }
        $dir = sys_get_temp_dir() . '/ellsms_ai_sec_' . bin2hex(random_bytes(5));
        mkdir($dir, 0700, true);
        self::$aiLog = $dir . '/ai.jsonl';
        self::$gatewayLog = $dir . '/gw.jsonl';
        [self::$aiPort, self::$aiServer] = self::startServer('fake-openai-server.php', getenv() + ['FAKE_AI_LOG' => self::$aiLog]);
        [$gwPort, self::$gatewayServer] = self::startServer('recording_gateway_server.php', getenv() + ['ELLSMS_RECORDER_FILE' => self::$gatewayLog]);
        self::$gatewayBase = 'http://127.0.0.1:' . $gwPort;
    }

    public static function tearDownAfterClass(): void
    {
        foreach ([self::$aiServer, self::$gatewayServer] as $proc) {
            if ($proc !== null) {
                proc_terminate($proc);
                proc_close($proc);
            }
        }
        foreach ([self::$aiLog, self::$gatewayLog] as $file) {
            if ($file !== '' && is_file($file)) @unlink($file);
        }
        if (self::$aiLog !== '') @rmdir(dirname(self::$aiLog));
        parent::tearDownAfterClass();
    }

    /** @return array{0:int, 1:mixed} */
    private static function startServer(string $fixture, array $env): array
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) self::markTestSkipped("no local port: {$errstr}");
        $name = stream_socket_get_name($socket, false);
        $port = (int)substr($name, strrpos($name, ':') + 1);
        fclose($socket);
        $process = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, dirname(__DIR__) . '/fixtures/' . $fixture],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        if ($process === false) self::markTestSkipped("could not start {$fixture}");
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($conn) { fclose($conn); return [$port, $process]; }
            usleep(50000);
        }
        self::markTestSkipped("{$fixture} did not become reachable");
    }

    protected function setUp(): void
    {
        parent::setUp();
        putenv('SMS_GATEWAY_TRANSPORT=1');
        putenv('API_BASE_URL=' . self::$gatewayBase);
        @file_put_contents(self::$aiLog, '');
        @file_put_contents(self::$gatewayLog, '');
        ai_secretary_cache_reset();
        ai_prices_cache_reset();
        sms_pricing_cache_reset();
        gateway_cache_reset();

        $this->ownerId = $this->makeUser(['originator' => $this->line]);
        db()->prepare('INSERT INTO ellsms_numbers (number, assigned_user_id) VALUES (?, ?)')->execute([$this->line, $this->ownerId]);
        wallet_credit($this->ownerId, 100000000, 'purchase', 'test', 'seed:' . $this->ownerId, 'seed:' . $this->ownerId);
        $this->makeGatewayAndRoute();

        $this->providerId = ai_provider_create(['type' => 'openai_compatible', 'code' => 'sec_' . bin2hex(random_bytes(3)), 'name' => 'Hermes',
            'base_url' => 'http://127.0.0.1:' . self::$aiPort . '/v1', 'auth_scheme' => 'none', 'allow_private_network' => '1',
            'connect_timeout_ms' => '2000', 'request_timeout_ms' => '10000'], $this->ownerId);
        $this->modelId = ai_model_add_manual($this->providerId, 'hermes-agent', 'Hermes', $this->ownerId);
        ai_model_set_status($this->modelId, true, $this->ownerId);
        ai_access_save($this->ownerId, true, [$this->modelId], $this->modelId, $this->ownerId);
        set_setting('ai_secretary_enabled', '1');
        set_setting('ai_secretary_max_attempts', '3');
    }

    protected function tearDown(): void
    {
        putenv('SMS_GATEWAY_TRANSPORT');
        putenv('API_BASE_URL');
        ai_secretary_cache_reset();
        sms_pricing_cache_reset();
        gateway_cache_reset();
        parent::tearDown();
    }

    /* ================= tests ================= */

    public function testAnInboundMessageIsAnsweredAndSentFromTheLine(): void
    {
        $this->assistant();
        $inboundId = $this->inbound($this->customer, 'ساعت کاری فردا چنده؟');

        run_autoreply_pass();
        $job = $this->job($inboundId);
        $this->assertSame('pending', $job['status']);

        $this->assertSame(1, ai_secretary_run_pass(5));
        $job = $this->job($inboundId);
        $this->assertSame('sent', $job['status'], (string)$job['info']);
        $this->assertSame('پاسخ آزمایشی: ساعت کاری فردا چنده؟', $job['reply_text']);
        $this->assertSame($this->modelId, (int)$job['model_id']);
        $this->assertSame([21, 7], [(int)$job['input_tokens'], (int)$job['output_tokens']]);
        $this->assertSame(1, (int)$job['reply_parts']);

        // The model got the business prompt, the right model and the token budget for 2 parts.
        $request = $this->aiRequests()[0];
        $this->assertSame('hermes-agent', $request['model']);
        $this->assertStringContainsString('ساعت کاری: ۹ تا ۱۸', $request['messages'][0]['content']);
        $this->assertSame(300, $request['max_tokens']);

        // The SMS went out from the line to the customer, through the gateway.
        $sent = $this->gatewayRequests();
        $this->assertCount(1, $sent);
        $this->assertStringContainsString('پاسخ آزمایشی', json_encode($sent[0], JSON_UNESCAPED_UNICODE));
        // Charged to the line owner's wallet under the job's own reference (like an auto-reply's rule log id).
        $charge = db()->prepare("SELECT COUNT(*) FROM ellsms_wallet_transactions WHERE user_id = ? AND reference_type = 'ai_secretary' AND reference_id = ?");
        $charge->execute([$this->ownerId, (string)$job['id']]);
        $this->assertGreaterThan(0, (int)$charge->fetchColumn(), 'billed as an AI secretary send');

        // Running again answers nothing twice.
        $this->assertSame(0, ai_secretary_run_pass(5));
        run_autoreply_pass();
        $this->assertCount(1, $this->gatewayRequests());
    }

    public function testTheModelSeesTheEarlierConversationWithTheSameNumber(): void
    {
        $this->assistant();
        $this->inbound($this->customer, 'سلام');
        run_autoreply_pass();
        ai_secretary_run_pass(5);
        $this->inbound($this->customer, 'آدرس کجاست؟');
        run_autoreply_pass();
        ai_secretary_run_pass(5);

        $second = $this->aiRequests()[1];
        $this->assertSame(['system', 'user', 'assistant', 'user'], array_column($second['messages'], 'role'));
        $this->assertSame('سلام', $second['messages'][1]['content']);
        $this->assertSame('پاسخ آزمایشی: سلام', $second['messages'][2]['content']);
        $this->assertSame('آدرس کجاست؟', $second['messages'][3]['content']);

        // Another number's conversation is not mixed in.
        $this->inbound('989351112233', 'سلام');
        run_autoreply_pass();
        ai_secretary_run_pass(5);
        $this->assertCount(2, $this->aiRequests()[2]['messages']);
    }

    public function testAKeywordRuleAnswersFirstAndTheSecretaryStaysOut(): void
    {
        $this->assistant();
        db()->prepare("INSERT INTO ellsms_autoreply_rules (user_id, originator, keyword, match_type, reply_content, is_active)
                       VALUES (?, ?, 'قیمت', 'exact', 'لیست قیمت: ...', 1)")->execute([$this->ownerId, $this->line]);
        $inboundId = $this->inbound($this->customer, 'قیمت');
        run_autoreply_pass();
        $this->assertNull($this->job($inboundId));
    }

    public function testOptOutKeywordsAndNonMobileSendersAreNeverAnswered(): void
    {
        $this->assistant();
        $optout = $this->inbound($this->customer, '11');
        $service = $this->inbound('30001234', 'کد تایید شما 1234');
        run_autoreply_pass();
        $this->assertSame(['skipped', 'optout_keyword'], [$this->job($optout)['status'], $this->job($optout)['skip_reason']]);

        ai_secretary_run_pass(5);
        $this->assertSame(['skipped', 'not_mobile'], [$this->job($service)['status'], $this->job($service)['skip_reason']]);
        $this->assertSame([], $this->aiRequests(), 'the model is never asked');
        // The only SMS is the opt-out confirmation of app/LineOptouts.php — no AI answer.
        $sent = $this->gatewayRequests();
        $this->assertCount(1, $sent);
        $this->assertStringContainsString('لغو شد', (string)$sent[0]['body']);
    }

    public function testALineWithoutAnAssistantOrWithItSwitchedOffIsLeftAlone(): void
    {
        $id = $this->inbound($this->customer, 'سلام');
        run_autoreply_pass();
        $this->assertNull($this->job($id));

        $this->assistant(['enabled' => '0']);
        $id = $this->inbound($this->customer, 'سلام دوباره');
        run_autoreply_pass();
        $this->assertNull($this->job($id));
    }

    public function testTheHourlyLimitPerNumberStopsFurtherAnswers(): void
    {
        $this->assistant(['sender_hourly_limit' => '1']);
        $first = $this->inbound($this->customer, 'سؤال یک');
        $second = $this->inbound($this->customer, 'سؤال دو');
        run_autoreply_pass();
        ai_secretary_run_pass(5);
        ai_secretary_run_pass(5);
        $this->assertSame('sent', $this->job($first)['status']);
        $this->assertSame(['skipped', 'sender_limit'], [$this->job($second)['status'], $this->job($second)['skip_reason']]);
    }

    public function testWithoutAModelTheAssistantStaysSilent(): void
    {
        $this->assistant();
        ai_model_set_status($this->modelId, false, $this->ownerId);
        $id = $this->inbound($this->customer, 'سلام');
        run_autoreply_pass();
        ai_secretary_run_pass(5);
        $this->assertSame(['skipped', 'no_model'], [$this->job($id)['status'], $this->job($id)['skip_reason']]);
    }

    public function testAnUnreachableModelRetriesThenSendsTheFallbackText(): void
    {
        $this->assistant(['fallback_text' => 'پیام شما دریافت شد؛ به‌زودی پاسخ می‌دهیم.']);
        db()->prepare('UPDATE ellsms_ai_providers SET base_url = ? WHERE id = ?')->execute(['http://127.0.0.1:1/v1', $this->providerId]);
        set_setting('ai_secretary_max_attempts', '2');
        $id = $this->inbound($this->customer, 'سلام');
        run_autoreply_pass();

        ai_secretary_run_pass(5);
        $job = $this->job($id);
        $this->assertSame(['pending', 'connection_failed'], [$job['status'], $job['error_code']], 'first failure is retried');
        db()->prepare('UPDATE ellsms_ai_jobs SET next_attempt_at = NOW() WHERE id = ?')->execute([(int)$job['id']]);

        ai_secretary_run_pass(5);
        $job = $this->job($id);
        $this->assertSame('sent', $job['status']);
        $this->assertSame('1', (string)$job['is_fallback']);
        $this->assertSame('پیام شما دریافت شد؛ به‌زودی پاسخ می‌دهیم.', $job['reply_text']);
    }

    public function testAnUnreachableModelWithoutFallbackFailsSilently(): void
    {
        $this->assistant();
        db()->prepare('UPDATE ellsms_ai_providers SET base_url = ? WHERE id = ?')->execute(['http://127.0.0.1:1/v1', $this->providerId]);
        set_setting('ai_secretary_max_attempts', '1');
        $id = $this->inbound($this->customer, 'سلام');
        run_autoreply_pass();
        ai_secretary_run_pass(5);
        $this->assertSame(['failed', 'connection_failed'], [$this->job($id)['status'], $this->job($id)['error_code']]);
        $this->assertSame([], $this->gatewayRequests());
    }

    public function testSimulationAnswersWithoutSendingOrRecordingAJob(): void
    {
        $assistant = ai_assistant_find($this->assistant());
        $result = ai_secretary_simulate($assistant, '', 'تست', $this->ownerId);
        $this->assertTrue($result['ok']);
        $this->assertSame('پاسخ آزمایشی: تست', $result['text']);
        $this->assertSame(0, (int)db()->query('SELECT COUNT(*) FROM ellsms_ai_jobs')->fetchColumn());
        $this->assertSame([], $this->gatewayRequests());
    }

    /* ================= AI charges (phase 3, app/Ai/AiBilling.php) ================= */

    public function testTheAiPartIsChargedOnceTheAnswerHasBeenSent(): void
    {
        // 1,000 Rial per answer including 20 tokens, 100 Rial per 1,000 tokens beyond; the fake model
        // reports 21 + 7 = 28 tokens: 1,000 + 8 x 0.1 = 1,000.8 -> 1,001 Rial.
        ai_prices_save('global', 0, [0 => ['answer' => 1000000, 'overage' => 100000, 'included' => 20]], $this->ownerId);
        set_setting('ai_billing_enabled', '1');
        $this->assistant();
        $id = $this->inbound($this->customer, 'سلام');
        run_autoreply_pass();
        ai_secretary_run_pass(5);

        $job = $this->job($id);
        $this->assertSame('sent', $job['status']);
        $this->assertSame(1001, (int)$job['ai_charge']);
        $charge = db()->prepare("SELECT * FROM ellsms_ai_charges WHERE reference_id = ?");
        $charge->execute(['job:' . $job['id']]);
        $row = $charge->fetch();
        $this->assertSame(['charged', 1001, 1000800, 28], [$row['status'], (int)$row['amount'], (int)$row['amount_millis'], (int)$row['input_tokens'] + (int)$row['output_tokens']]);
        $ledger = db()->prepare("SELECT amount FROM ellsms_wallet_transactions WHERE user_id = ? AND type = 'ai_debit' AND reference_type = 'ai_usage' AND reference_id = ?");
        $ledger->execute([$this->ownerId, 'job:' . $job['id']]);
        $this->assertSame(-1001, (int)$ledger->fetchColumn());

        // Charged once: a second charge call for the same job is a replay.
        $this->assertSame(1001, ai_secretary_charge_job((int)$job['id'], $this->ownerId)['amount']);
        $count = db()->prepare("SELECT COUNT(*) FROM ellsms_wallet_transactions WHERE type = 'ai_debit' AND reference_id = ?");
        $count->execute(['job:' . $job['id']]);
        $this->assertSame(1, (int)$count->fetchColumn());
    }

    public function testTheAccountTariffPercentageApplies(): void
    {
        ai_prices_save('global', 0, [0 => ['answer' => 1000000, 'overage' => 0, 'included' => 100000]], $this->ownerId);
        db()->prepare("INSERT INTO ellsms_customer_accounts (user_id, customer_type, tariff_bp) VALUES (?, 'normal', 15000)")->execute([$this->ownerId]);
        $this->assertSame(1500000, ai_price_resolve($this->ownerId, $this->modelId)['answer'], 'list price x 150%');
        ai_prices_save('account', $this->ownerId, [$this->modelId => ['answer' => 700000, 'overage' => null, 'included' => null]], $this->ownerId);
        $this->assertSame(700000, ai_price_resolve($this->ownerId, $this->modelId)['answer'], 'the account\'s own exact price wins');
    }

    public function testAnAccountThatCannotPayIsNotAnsweredAndTheModelIsNotAsked(): void
    {
        ai_prices_save('global', 0, [0 => ['answer' => 999999999000, 'overage' => 0, 'included' => 0]], $this->ownerId);
        $this->assistant();
        $id = $this->inbound($this->customer, 'سلام');
        run_autoreply_pass();
        ai_secretary_run_pass(5);
        $this->assertSame(['skipped', 'insufficient_credit'], [$this->job($id)['status'], $this->job($id)['skip_reason']]);
        $this->assertSame([], $this->aiRequests());
        $this->assertSame([], $this->gatewayRequests());
    }

    public function testAFallbackTextAndAnUnpricedModelCostNoAi(): void
    {
        $this->assistant(['fallback_text' => 'بعداً پاسخ می‌دهیم.']);
        db()->prepare('UPDATE ellsms_ai_providers SET base_url = ? WHERE id = ?')->execute(['http://127.0.0.1:1/v1', $this->providerId]);
        set_setting('ai_secretary_max_attempts', '1');
        ai_prices_save('global', 0, [0 => ['answer' => 1000000, 'overage' => 0, 'included' => 0]], $this->ownerId);
        $fallback = $this->inbound($this->customer, 'سلام');
        run_autoreply_pass();
        ai_secretary_run_pass(5);
        $this->assertSame('sent', $this->job($fallback)['status']);
        $this->assertNull($this->job($fallback)['ai_charge'], 'no model answer, no AI charge');

        db()->prepare('UPDATE ellsms_ai_providers SET base_url = ? WHERE id = ?')->execute(['http://127.0.0.1:' . self::$aiPort . '/v1', $this->providerId]);
        ai_prices_save('global', 0, [], $this->ownerId);
        $free = $this->inbound('989351112233', 'سلام');
        run_autoreply_pass();
        ai_secretary_run_pass(5);
        $this->assertSame(['sent', 0], [$this->job($free)['status'], (int)$this->job($free)['ai_charge']]);
    }

    public function testACustomerTestIsChargedAndAnAdminTestIsFree(): void
    {
        ai_prices_save('global', 0, [0 => ['answer' => 500000, 'overage' => 0, 'included' => 0]], $this->ownerId);
        $assistant = ai_assistant_find($this->assistant());
        $customer = ai_secretary_simulate($assistant, '', 'تست', $this->ownerId, true);
        $this->assertSame(['charged', 500], [$customer['charge']['status'], $customer['charge']['amount']]);
        $admin = ai_secretary_simulate($assistant, '', 'تست', $this->ownerId, false);
        $this->assertNull($admin['charge']);
        $this->assertSame(1, (int)db()->query("SELECT COUNT(*) FROM ellsms_wallet_transactions WHERE type = 'ai_debit'")->fetchColumn());
    }

    /* ================= helpers ================= */

    private function assistant(array $overrides = []): int
    {
        return ai_assistant_save($this->line, $this->ownerId, null, $overrides + [
            'enabled' => '1', 'mode' => 'business', 'business_name' => 'کلینیک لبخند',
            'instructions' => 'کوتاه جواب بده.', 'knowledge' => 'ساعت کاری: ۹ تا ۱۸', 'max_parts' => '2',
        ], $this->ownerId);
    }

    private function inbound(string $from, string $content): int
    {
        db()->prepare('INSERT INTO inbound_message (destination, originator, content) VALUES (?, ?, ?)')->execute([$this->line, $from, $content]);
        return (int)db()->lastInsertId();
    }

    private function job(int $inboundId): ?array
    {
        $st = db()->prepare('SELECT * FROM ellsms_ai_jobs WHERE inbound_key = ?');
        $st->execute(['b:' . $inboundId]);
        return $st->fetch() ?: null;
    }

    /** @return list<array> chat-completion request bodies the fake model received, in order */
    private function aiRequests(): array
    {
        $out = [];
        foreach (file(self::$aiLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $entry = json_decode($line, true);
            if (($entry['path'] ?? '') === '/v1/chat/completions') $out[] = json_decode((string)$entry['body'], true);
        }
        return $out;
    }

    /** @return list<array> send requests the recording gateway received */
    private function gatewayRequests(): array
    {
        $out = [];
        foreach (file(self::$gatewayLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $entry = json_decode($line, true);
            if (str_starts_with((string)($entry['path'] ?? ''), '/gw/')) $out[] = $entry;
        }
        return $out;
    }

    private function makeGatewayAndRoute(): void
    {
        $db = db();
        $code = 'aisec_' . bin2hex(random_bytes(4));
        $db->prepare("INSERT INTO ellsms_sms_gateways (code, name, status, send_mode, send_enabled, status_enabled, config_version)
                      VALUES (?,?, 'active','batch',1,0,1)")->execute([$code, $code]);
        $gatewayId = (int)$db->lastInsertId();
        $db->prepare(
            "INSERT INTO ellsms_sms_gateway_send_connectors
               (gateway_id, endpoint_url, http_method, content_type, connect_timeout_ms, request_timeout_ms, tls_verify, auth_type, success_rule_json, batch_mapping_json)
             VALUES (?,?, 'POST','application/json',5000,30000,1,'none',?,?)"
        )->execute([
            $gatewayId, self::$gatewayBase . '/gw/' . $code,
            json_encode(['http' => ['min' => 200, 'max' => 299], 'require_json' => true, 'rules' => []]),
            json_encode(['rows_path' => '', 'destination_key' => 'destination', 'status_key' => 'status',
                         'success_values' => ['sent'], 'message_id_key' => 'id']),
        ]);
        foreach ([['destinations', 'recipients', 'string_list', 30], ['content', 'message', 'string', 40]] as [$key, $value, $dataType, $sortOrder]) {
            $db->prepare(
                "INSERT INTO ellsms_sms_gateway_parameters
                   (gateway_id, connector, location, scope, scope_id, param_key, value_type, value, data_type, status, sort_order, active_slot)
                 VALUES (?, 'send', 'body', 'gateway', NULL, ?, 'variable', ?, ?, 'active', ?, ?)"
            )->execute([$gatewayId, $key, $value, $dataType, $sortOrder, "{$gatewayId}:send:body:gateway::{$key}"]);
        }
        $suffix = bin2hex(random_bytes(3));
        $db->prepare('INSERT INTO ellsms_sms_providers (code, name, status) VALUES (?,?,?)')->execute(['prov_' . $suffix, 'prov', 'active']);
        $providerId = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO ellsms_sms_routes (provider_id, code, name, message_type, status, is_default, default_slot, gateway_id) VALUES (?,?,?,?,?,0,NULL,?)')
            ->execute([$providerId, 'route_' . $suffix, 'route', 'default', 'active', $gatewayId]);
        $routeId = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO ellsms_sender_routes (sender, message_type, route_id, status, active_slot) VALUES (?,?,?,?,?)')
            ->execute([$this->line, 'default', $routeId, 'active', $this->line . ':default']);
        gateway_cache_reset();
        sms_pricing_cache_reset();
    }
}
