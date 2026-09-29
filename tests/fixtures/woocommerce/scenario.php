<?php
/**
 * WooCommerce plugin scenario, run in its own PHP process by tests/Integration/WooCommercePluginTest.php.
 * Loads the plugin from the BUILT zip (ELLSMS_PLUGIN_DIR) on top of wp_stub.php and drives it against the
 * real ELLSMS API. Env: ELLSMS_PLUGIN_DIR, ELLSMS_BASE_URL, ELLSMS_API_KEY, ELLSMS_API_KEY_NO_SEND,
 * ELLSMS_ORIGINATOR, ELLSMS_RECORD (the recording gateway's request log).
 * Prints "OK <checks>" or the first failure (exit 1).
 */

declare(strict_types=1);

require __DIR__ . '/wp_stub.php';
require getenv('ELLSMS_PLUGIN_DIR') . '/ellsms-woocommerce.php';

$checks = 0;
function check(bool $ok, string $what): void {
    global $checks;
    if (!$ok) { fwrite(STDERR, "FAILED: {$what}\n"); exit(1); }
    $checks++;
}
function sent(): array {
    $out = [];
    foreach (array_filter(explode("\n", (string)@file_get_contents(getenv('ELLSMS_RECORD')))) as $line) {
        $r = json_decode($line, true);
        if (str_starts_with((string)($r['path'] ?? ''), '/vesal/wc')) $out[] = json_decode((string)$r['body'], true);
    }
    return $out;
}
function note_has(Stub_WC_Order $o, string $needle): bool {
    foreach ($o->notes as $n) if (str_contains($n, $needle)) return true;
    return false;
}

try {
    do_action('plugins_loaded');
    check(isset($GLOBALS['wp_hooks']['woocommerce_order_status_changed']), 'status hook registered');

    // --- pure helpers ---
    foreach (['09121234567' => '989121234567', '+98 912 123 4567' => '989121234567', '00989121234567' => '989121234567',
              '9121234567' => '989121234567', '۰۹۱۲۱۲۳۴۵۶۷' => '989121234567', '989121234567' => '989121234567',
              '02188776655' => null, '0912123' => null, '' => null] as $in => $want) {
        check(Ellsms_WC_Plugin::normalize_mobile((string)$in) === $want, "normalize_mobile({$in})");
    }

    $template = "{first_name} عزیز، سفارش {order_number} ({items}) به مبلغ {total} {currency} در وضعیت «{status}» است.\n{site_name} {unknown}";
    update_option(Ellsms_WC_Plugin::OPTION, [
        'base_url' => getenv('ELLSMS_BASE_URL'),
        'api_key' => getenv('ELLSMS_API_KEY'),
        'originator' => getenv('ELLSMS_ORIGINATOR'),
        'statuses' => [
            'completed' => ['enabled' => true, 'template' => $template],
            'processing' => ['enabled' => true, 'template' => 'سفارش {order_number} در حال انجام است'],
            'cancelled' => ['enabled' => false, 'template' => 'لغو شد'],
        ],
    ]);
    $order = new Stub_WC_Order(['id' => 1001, 'number' => 'A-1001', 'first' => 'مریم', 'last' => 'احمدی', 'phone' => '۰۹۱۲ ۱۲۷ ۰۰۵۵',
        'total' => '1250000.00', 'currency' => 'IRT', 'payment' => 'زرین‌پال', 'created' => 1760000000, 'items' => ['کتاب', 'خودکار']]);
    $GLOBALS['wc_orders'][1001] = $order;

    $order->set_status_raw('completed');
    $expected = "مریم عزیز، سفارش A-1001 (کتاب، خودکار) به مبلغ 1,250,000 تومان در وضعیت «تکمیل شده» است.\nفروشگاه نمونه & شرکا {unknown}";
    check(Ellsms_WC_Plugin::render($template, $order) === $expected, 'render: ' . Ellsms_WC_Plugin::render($template, $order));

    // --- status change: queued, not sent inline; a duplicate hook queues nothing more ---
    do_action('woocommerce_order_status_changed', 1001, 'processing', 'completed', $order);
    do_action('woocommerce_order_status_changed', 1001, 'processing', 'completed', $order);
    check(count($GLOBALS['as_queue']) === 1 && $GLOBALS['as_queue'][0][2] === 'ellsms', 'one background job in group ellsms');
    check(sent() === [], 'nothing sent during the status change itself');

    check(as_run_queue() === [], 'job ran without failing');
    $got = sent();
    check(count($got) === 1, 'exactly one SMS reached the gateway (got ' . count($got) . ')');
    check($got[0]['destinations'] === ['989121270055'], 'sent to the normalised billing phone');
    check($got[0]['contents'] === [$expected], 'the gateway received exactly the rendered text');
    check(note_has($order, 'ارسال شد'), 'success note: ' . implode(' | ', $order->notes));
    check(isset($order->meta['_ellsms_wc_sent']['completed']), 'sent flag stored');

    // --- the same status again (hook fired later, or the job re-run): never a second SMS ---
    do_action('woocommerce_order_status_changed', 1001, 'on-hold', 'completed', $order);
    as_run_queue();
    Ellsms_WC_Plugin::instance()->send_status_sms(1001, 'completed');
    check(count(sent()) === 1, 'a status SMS goes out once per order');
    // The plugin's own guard, not only the server's replay: no second attempt, no second note.
    check(count(array_filter($order->notes, static fn($n) => str_contains($n, 'ارسال شد'))) === 1, 'the plugin itself did not try again');

    // --- a disabled status queues nothing; a job for a status the order left sends nothing ---
    do_action('woocommerce_order_status_changed', 1001, 'completed', 'cancelled', $order);
    check($GLOBALS['as_queue'] === [], 'disabled status is not queued');
    do_action('woocommerce_order_status_changed', 1001, 'pending', 'processing', $order); // order is still "completed"
    as_run_queue();
    check(count(sent()) === 1, 'stale status job sends nothing');

    // --- invalid phone: a note, no request ---
    $landline = new Stub_WC_Order(['id' => 1002, 'number' => '1002', 'first' => 'علی', 'last' => '', 'phone' => '021-88776655',
        'total' => '10', 'currency' => 'IRR', 'payment' => '', 'created' => 1760000000, 'status' => 'completed']);
    $GLOBALS['wc_orders'][1002] = $landline;
    Ellsms_WC_Plugin::instance()->send_status_sms(1002, 'completed');
    check(note_has($landline, 'معتبر نیست') && count(sent()) === 1, 'invalid phone: note, nothing sent');

    // --- a definite refusal (key without messages:send): noted, NOT thrown, attempt number moves on ---
    $refused = new Stub_WC_Order(['id' => 1003, 'number' => '1003', 'first' => 'سارا', 'last' => '', 'phone' => '09121270056',
        'total' => '10', 'currency' => 'IRR', 'payment' => '', 'created' => 1760000000, 'status' => 'completed']);
    $GLOBALS['wc_orders'][1003] = $refused;
    $settings = get_option(Ellsms_WC_Plugin::OPTION);
    update_option(Ellsms_WC_Plugin::OPTION, ['api_key' => getenv('ELLSMS_API_KEY_NO_SEND')] + $settings);
    Ellsms_WC_Plugin::instance()->send_status_sms(1003, 'completed');
    check(note_has($refused, 'messages:send'), 'refusal explained in Persian: ' . implode(' | ', $refused->notes));
    check(($refused->meta['_ellsms_wc_attempts']['completed'] ?? 0) === 1, 'definite refusal moves to a fresh idempotency key');
    check(!isset($refused->meta['_ellsms_wc_sent']['completed']), 'refused is not marked sent');

    // --- unknown outcome (panel unreachable): rethrown for Action Scheduler, same key kept ---
    update_option(Ellsms_WC_Plugin::OPTION, ['base_url' => 'http://127.0.0.1:1'] + $settings);
    $threw = false;
    try { Ellsms_WC_Plugin::instance()->send_status_sms(1003, 'completed'); } catch (\Ellsms\EllsmsException $e) { $threw = $e->getStatus() === 0; }
    check($threw, 'network failure is rethrown so the scheduled job shows as failed');
    check(($refused->meta['_ellsms_wc_attempts']['completed'] ?? 0) === 1, 'unknown outcome keeps the same key');

    // --- back to a working key: the order-page "resend" now really sends (fresh key, no replayed refusal) ---
    update_option(Ellsms_WC_Plugin::OPTION, $settings);
    Ellsms_WC_Plugin::instance()->resend_current_status($refused);
    $got = sent();
    check(count($got) === 2 && $got[1]['destinations'] === ['989121270056'], 'resend after a refusal sends');

    // --- resend of an already-sent status sends again, under a new key ---
    Ellsms_WC_Plugin::instance()->resend_current_status($order);
    check(count(sent()) === 3, 'manual resend of a sent status sends again');
    check(($order->meta['_ellsms_wc_attempts']['completed'] ?? 0) === 1, 'manual resend uses a fresh key');
    check(Ellsms_WC_Plugin::client_message_id(1001, 'completed', 1) === 'wc-' . substr(md5('https://shop.example'), 0, 10) . '-1001-completed-r1', 'key format');

    // --- order actions box entry ---
    $actions = apply_filters('woocommerce_order_actions', [], $order);
    check(isset($actions['ellsms_resend']), 'resend offered in the order actions box');

    // --- settings page: save (sanitised), key kept when left blank, test connection against the real API ---
    $post = static function (array $fields): ?array {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = $fields;
        try {
            return Ellsms_WC_Settings::handle_post();
        } finally {
            // Real WordPress ends the request on a bad nonce; here the next "request" must start clean.
            $_POST = [];
            $_SERVER['REQUEST_METHOD'] = 'GET';
        }
    };
    $saved = $post(['ellsms_wc_do' => 'save', 'base_url' => getenv('ELLSMS_BASE_URL'), 'api_key' => '', 'originator' => '5000-9009 55x',
        'statuses' => ['processing' => ['enabled' => '1', 'template' => '<b>سلام</b> {first_name}'], 'bogus-status' => ['enabled' => '1', 'template' => 'x']]]);
    $s = get_option(Ellsms_WC_Plugin::OPTION);
    check($saved[0] === 'success', 'settings saved');
    check($s['api_key'] === getenv('ELLSMS_API_KEY'), 'a blank key field keeps the stored key');
    check($s['originator'] === '5000900955', 'originator reduced to digits');
    check($s['statuses']['processing'] === ['enabled' => true, 'template' => 'سلام {first_name}'], 'template sanitised');
    check(!isset($s['statuses']['bogus-status']) && isset($s['statuses']['completed']) && $s['statuses']['completed']['enabled'] === false,
        'only real WooCommerce statuses are stored; unticked ones are off');
    check($post(['ellsms_wc_do' => 'save', 'base_url' => 'http://evil.example'])[0] === 'error', 'plain http panel address refused');
    $s['originator'] = getenv('ELLSMS_ORIGINATOR');
    update_option(Ellsms_WC_Plugin::OPTION, $s);

    $conn = $post(['ellsms_wc_do' => 'test_connection']);
    check($conn[0] === 'success' && str_contains($conn[1], 'اعتبار'), 'test connection shows the balance: ' . $conn[1]);
    update_option(Ellsms_WC_Plugin::OPTION, ['api_key' => getenv('ELLSMS_API_KEY_NO_SEND')] + $s);
    $conn = $post(['ellsms_wc_do' => 'test_connection']);
    check($conn[0] === 'error' && str_contains($conn[1], 'messages:send'), 'test connection warns about a key that cannot send');
    update_option(Ellsms_WC_Plugin::OPTION, $s);
    $before = count(sent());
    $test = $post(['ellsms_wc_do' => 'test_sms', 'test_mobile' => '0912 127 0057']);
    check($test[0] === 'success' && count(sent()) === $before + 1 && sent()[$before]['destinations'] === ['989121270057'], 'test SMS really sent');

    $GLOBALS['wp_can'] = false;
    check($post(['ellsms_wc_do' => 'forget_key'])[0] === 'error' && get_option(Ellsms_WC_Plugin::OPTION)['api_key'] !== '', 'no capability, no change');
    $GLOBALS['wp_can'] = true;
    $GLOBALS['wp_nonce_ok'] = false;
    $nonceRejected = false;
    try { $post(['ellsms_wc_do' => 'forget_key']); } catch (RuntimeException $e) { $nonceRejected = true; }
    check($nonceRejected && get_option(Ellsms_WC_Plugin::OPTION)['api_key'] !== '', 'a forged form without a valid nonce changes nothing');
    $GLOBALS['wp_nonce_ok'] = true;

    ob_start();
    Ellsms_WC_Settings::render();
    $page = (string)ob_get_clean();
    check(!str_contains($page, getenv('ELLSMS_API_KEY')), 'the stored API key is never printed back');
    check(str_contains($page, '…' . substr(getenv('ELLSMS_API_KEY'), -4)), 'only its last 4 characters are hinted');
    check(substr_count($page, 'name="statuses[') === 2 * count(wc_get_order_statuses()), 'one switch and one text per status');
    check(str_contains($page, '{order_number}') && str_contains($page, '_wpnonce'), 'variables listed, forms carry a nonce');
} catch (Throwable $e) {
    fwrite(STDERR, 'FAILED: ' . get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
}
echo "OK {$checks}\n";
