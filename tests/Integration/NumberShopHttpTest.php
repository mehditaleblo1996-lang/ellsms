<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Number shop pages (public/number-shop-admin.php, public/my-numbers.php): the admin lists a line
 * with its price and description, the customer sees it in «خرید شماره», buys it from the wallet,
 * the admin approves and the line shows under «شماره‌های من».
 */
final class NumberShopHttpTest extends TestCase
{
    private $serverProc = null;
    private int $port;
    private string $sessionDir;
    private array $userIds = [];
    private int $adminId;
    private int $buyerId;
    private string $number;

    protected function setUp(): void
    {
        IntegrationTestCase::skipUnlessTestDatabaseConfigured($this);
        IntegrationTestCase::ensureSchemaLoaded();
        $this->adminId = $this->makeUser(true);
        $this->buyerId = $this->makeUser(false);
        $this->number = '9800' . random_int(100000, 999999);

        $this->sessionDir = sys_get_temp_dir() . '/ellsms_numshop_sess_' . bin2hex(random_bytes(6));
        mkdir($this->sessionDir, 0700, true);
        $this->port = 21100 + random_int(0, 200);
        $env = ['APP_ENV' => 'testing', 'BILLING_TAX_PERCENT' => '10'];
        foreach (['BACKEND_DB_HOST', 'BACKEND_DB_PORT', 'BACKEND_DB_NAME', 'BACKEND_DB_USER', 'BACKEND_DB_PASS'] as $k) {
            $env[$k] = (string)getenv($k);
        }
        $this->serverProc = proc_open(
            [PHP_BINARY, '-d', 'session.save_path=' . $this->sessionDir, '-S', "127.0.0.1:{$this->port}", '-t', dirname(__DIR__, 2) . '/public'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env
        );
        for ($i = 0; $i < 40; $i++) {
            usleep(150000);
            $conn = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.2);
            if ($conn) { fclose($conn); return; }
        }
        $this->fail('throwaway dev server never accepted connections');
    }

    protected function tearDown(): void
    {
        if ($this->serverProc !== null) {
            proc_terminate($this->serverProc);
            proc_close($this->serverProc);
        }
        foreach (glob($this->sessionDir . '/*') ?: [] as $f) { @unlink($f); }
        @rmdir($this->sessionDir);
        $st = db()->prepare('SELECT id FROM ellsms_numbers WHERE number = ?');
        $st->execute([$this->number]);
        if (($numberId = $st->fetchColumn()) !== false) {
            db()->prepare('DELETE FROM ellsms_number_orders WHERE number_id = ?')->execute([$numberId]);
            db()->prepare('DELETE FROM ellsms_numbers WHERE id = ?')->execute([$numberId]);
        }
        foreach ($this->userIds as $id) {
            db()->prepare('DELETE FROM ellsms_wallet_transactions WHERE user_id = ?')->execute([$id]);
            db()->prepare('DELETE FROM ellsms_wallet_accounts WHERE user_id = ?')->execute([$id]);
            db()->prepare('DELETE FROM ellsms_audit_log WHERE user_id = ?')->execute([$id]);
            db()->prepare('DELETE FROM ellsms_notifications WHERE user_id = ?')->execute([$id]);
            db()->prepare('DELETE FROM ellsms_meta WHERE user_id = ?')->execute([$id]);
            db()->prepare('DELETE FROM user_ WHERE id = ?')->execute([$id]);
        }
    }

    private function makeUser(bool $admin): int
    {
        db()->prepare('INSERT INTO user_ (username, active, deleted) VALUES (?,1,0)')->execute(['numshop_' . bin2hex(random_bytes(5))]);
        $id = (int)db()->lastInsertId();
        db()->prepare('INSERT INTO ellsms_meta (user_id, panel_access, is_admin, originator) VALUES (?,1,?,?)')->execute([$id, $admin ? 1 : 0, '']);
        $this->userIds[] = $id;
        return $id;
    }

    private function session(int $userId): string
    {
        $sid = bin2hex(random_bytes(16));
        $now = time();
        file_put_contents($this->sessionDir . '/sess_' . $sid, 'uid|' . serialize($userId) . '_created_at|' . serialize($now) . '_last_activity|' . serialize($now));
        return $sid;
    }

    private function request(string $method, string $sid, string $path, array $post = []): array
    {
        $ch = curl_init("http://127.0.0.1:{$this->port}{$path}");
        $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_COOKIE => 'ELLSMS_SESSION=' . $sid, CURLOPT_HEADER => true];
        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = http_build_query($post);
        }
        curl_setopt_array($ch, $opts);
        $body = (string)curl_exec($ch);
        return ['code' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE), 'body' => $body];
    }

    private function csrf(string $body): string
    {
        preg_match('/name="_csrf" value="([^"]+)"/', $body, $m);
        return $m[1] ?? '';
    }

    private function assertNoPhpError(string $body): void
    {
        $this->assertDoesNotMatchRegularExpression('/(Fatal error|Warning:|Notice:|Deprecated:|Uncaught)/', $body);
    }

    public function testListBuyApproveThroughThePages(): void
    {
        $admin = $this->session($this->adminId);
        foreach (['orders', 'listings', 'add'] as $tab) {
            $page = $this->request('GET', $admin, '/number-shop-admin.php?tab=' . $tab);
            $this->assertSame(200, $page['code'], "admin tab {$tab}");
            $this->assertNoPhpError($page['body']);
        }
        $add = $this->request('GET', $admin, '/number-shop-admin.php?tab=add');
        $this->assertStringContainsString('قیمت خرید (ریال)', $add['body']);
        $this->assertStringContainsString('هزینه‌ی تمدید سالانه', $add['body']);
        $this->assertStringContainsString('مشتری این‌طور می‌بیند', $add['body']);

        // Without a price nothing is listed.
        $this->request('POST', $admin, '/number-shop-admin.php', ['_csrf' => $this->csrf($add['body']), 'do' => 'list', 'mode' => 'single',
            'number' => $this->number, 'price' => '', 'renewal_price' => '5000', 'description' => 'توضیحات کامل این شماره']);
        $this->assertSame(0, (int)db()->query("SELECT COUNT(*) FROM ellsms_number_listings l JOIN ellsms_numbers n ON n.id = l.number_id WHERE n.number = '{$this->number}'")->fetchColumn());

        $save = $this->request('POST', $admin, '/number-shop-admin.php', ['_csrf' => $this->csrf($add['body']), 'do' => 'list', 'mode' => 'single',
            'number' => $this->number, 'price' => '2,000,000', 'renewal_price' => '500000', 'category' => 'رند', 'title' => 'خط رند',
            'description' => 'شماره‌ی رند ۱۰ رقمی مناسب کسب‌وکار', 'features' => "ارسال به همه‌ی اپراتورها\nدریافت پیامک", 'featured' => '1']);
        $this->assertSame(302, $save['code']);
        $listingId = (int)db()->query("SELECT l.id FROM ellsms_number_listings l JOIN ellsms_numbers n ON n.id = l.number_id WHERE n.number = '{$this->number}'")->fetchColumn();
        $this->assertGreaterThan(0, $listingId);

        $buyer = $this->session($this->buyerId);
        $shop = $this->request('GET', $buyer, '/my-numbers.php?tab=shop&q=' . $this->number);
        $this->assertSame(200, $shop['code']);
        $this->assertNoPhpError($shop['body']);
        $this->assertStringContainsString(number_shop_pretty($this->number), $shop['body']);
        $this->assertStringContainsString('ارسال به همه‌ی اپراتورها', $shop['body']);
        $this->assertStringContainsString('ویژه', $shop['body']);

        $checkout = $this->request('GET', $buyer, '/my-numbers.php?buy=' . $listingId);
        $this->assertSame(200, $checkout['code']);
        $this->assertNoPhpError($checkout['body']);
        $this->assertStringContainsString('۲٬۲۰۰٬۰۰۰', str_replace(',', '٬', $checkout['body']), 'the total with VAT is shown');

        wallet_credit($this->buyerId, 2_200_000, 'manual_credit', 'test', 'fund', 'numshop_http:' . bin2hex(random_bytes(8)));
        $pay = $this->request('POST', $buyer, '/my-numbers.php', ['_csrf' => $this->csrf($checkout['body']), 'kind' => 'purchase',
            'listing_id' => (string)$listingId, 'method' => 'wallet', 'accept_terms' => '1']);
        $this->assertSame(302, $pay['code']);
        $this->assertStringContainsString('Location: /my-numbers.php?tab=orders', $pay['body']);
        $orderId = (int)db()->query("SELECT id FROM ellsms_number_orders WHERE listing_id = {$listingId} AND status = 'awaiting_approval'")->fetchColumn();
        $this->assertGreaterThan(0, $orderId);
        $orders = $this->request('GET', $buyer, '/my-numbers.php?tab=orders');
        $this->assertStringContainsString('در انتظار تأیید مدیر', $orders['body']);

        $queue = $this->request('GET', $admin, '/number-shop-admin.php?tab=orders');
        $this->assertStringContainsString('تأیید و تخصیص', $queue['body']);
        $approve = $this->request('POST', $admin, '/number-shop-admin.php', ['_csrf' => $this->csrf($queue['body']), 'do' => 'approve', 'order_id' => (string)$orderId]);
        $this->assertSame(302, $approve['code']);
        $st = db()->prepare('SELECT assigned_user_id FROM ellsms_numbers WHERE number = ?');
        $st->execute([$this->number]);
        $this->assertSame($this->buyerId, (int)$st->fetchColumn());

        $mine = $this->request('GET', $buyer, '/my-numbers.php?tab=mine');
        $this->assertSame(200, $mine['code']);
        $this->assertNoPhpError($mine['body']);
        $this->assertStringContainsString(number_shop_pretty($this->number), $mine['body']);
        $this->assertStringContainsString('تمدید یک‌ساله', $mine['body']);
        $renew = $this->request('GET', $buyer, '/my-numbers.php?renew=' . $listingId);
        $this->assertSame(200, $renew['code']);
        $this->assertNoPhpError($renew['body']);

        $this->assertSame('کسر از کیف پول', manual_payment_method_label('wallet'));
    }
}
