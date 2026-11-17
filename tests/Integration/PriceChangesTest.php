<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once dirname(__DIR__, 2) . '/app/PriceChanges.php';

/**
 * Preview, scheduling and one-click revert of price-matrix changes (app/PriceChanges.php): the preview
 * prices last month's real traffic through the real engine at today's and the proposed prices, a change
 * applies now or at its time, and a revert puts back only the cells nobody changed since.
 */
final class PriceChangesTest extends IntegrationTestCase
{
    private string $sender;
    private int $admin;
    private int $mci;
    private int $mtn;
    private int $gatewayId;
    private int $customerA;
    private int $customerB;

    private const MCI = '989121234567';
    private const MTN = '989351234567';

    protected function setUp(): void
    {
        parent::setUp();
        customer_hierarchy_available(true);
        gateway_prices_available(true);
        price_changes_available(true);
        $_SESSION = [];
        $this->sender = '3000' . random_int(1000, 9999);
        $this->admin = $this->makeUser(['is_admin' => 1]);
        $this->mci = (int)db()->query("SELECT id FROM ellsms_sms_operators WHERE code = 'mci'")->fetchColumn();
        $this->mtn = (int)db()->query("SELECT id FROM ellsms_sms_operators WHERE code = 'mtn'")->fetchColumn();

        $db = db();
        $code = 'pcgw_' . bin2hex(random_bytes(3));
        $db->prepare("INSERT INTO ellsms_sms_gateways (code, name, status, send_mode, send_enabled, status_enabled, is_default, config_version)
                      VALUES (?,?, 'active', 'batch', 1, 0, 0, 1)")->execute([$code, 'درگاه آزمایشی']);
        $this->gatewayId = (int)$db->lastInsertId();
        $suffix = bin2hex(random_bytes(3));
        $db->prepare('INSERT INTO ellsms_sms_providers (code, name, status) VALUES (?,?,?)')->execute(['prov_' . $suffix, 'p', 'active']);
        $providerId = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO ellsms_sms_routes (provider_id, code, name, message_type, status, is_default, default_slot, gateway_id) VALUES (?,?,?,?,?,0,NULL,?)')
           ->execute([$providerId, 'route_' . $suffix, 'r', 'default', 'active', $this->gatewayId]);
        $routeId = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO ellsms_sms_route_prices (route_id, operator_id, operator_slot, price_per_segment_millicredits, currency, effective_from, effective_to, status) VALUES (?,?,?,?,?,?,?,?)')
           ->execute([$routeId, null, 0, 1000000, 'IRR', '2000-01-01 00:00:00', null, 'active']); // 1000 Rial on the route
        $db->prepare('INSERT INTO ellsms_sender_routes (sender, message_type, route_id, status, active_slot) VALUES (?,?,?,?,?)')
           ->execute([$this->sender, 'default', $routeId, 'active', $this->sender . ':default']);

        $this->customerA = $this->makeUser();
        $this->customerB = $this->makeUser();
        $this->account($this->customerA, 'normal');
        $this->account($this->customerB, 'normal');
        $this->resetCaches();
        // The global MCI / Persian cell of the test gateway: 1124 Rial, in effect since long ago (a save
        // in the same second as a later one would start a second later, see gateway_prices_save()).
        db()->prepare("INSERT INTO ellsms_gateway_prices (user_id, user_slot, gateway_id, gateway_slot, column_key, encoding, price_per_segment_millicredits, effective_from, created_by)
                       VALUES (NULL, 0, ?, ?, ?, 'fa', 1124000, '2000-01-01 00:00:00', ?)")
            ->execute([$this->gatewayId, $this->gatewayId, (string)$this->mci, $this->admin]);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $this->resetCaches();
        parent::tearDown();
    }

    private function resetCaches(): void
    {
        sms_pricing_cache_reset();
        gateway_cache_reset();
    }

    private function key(string $encoding, ?int $operator = null, ?int $gateway = null): string
    {
        return gateway_price_key($gateway ?? $this->gatewayId, (string)($operator ?? $this->mci), $encoding);
    }

    private function account(int $userId, string $type, ?int $parentId = null, int $bp = 10000): void
    {
        db()->prepare('INSERT INTO ellsms_customer_accounts (user_id, customer_type, parent_user_id, level, max_subset_limit, tariff_bp) VALUES (?,?,?,?,?,?)')
            ->execute([$userId, $type, $parentId, $parentId === null ? 0 : 1, 5, $bp]);
    }

    private function cost(int $userId, string $mobile = self::MCI): int
    {
        $this->resetCaches();
        // A little ahead of now: saves made in the same second as the one before start a second later.
        $priced = sms_pricing_price_single_content([$mobile], 'سلام', $this->sender, null, gmdate('Y-m-d H:i:s', time() + 15), false, $userId);
        $this->assertTrue($priced['ok']);
        return $priced['total_cost'];
    }

    /** Last month's traffic: A sends 3 + 1 messages (7 parts) to MCI, B one message to MTN, the admin one to MCI. */
    private function seedTraffic(): void
    {
        $db = db();
        $when = gmdate('Y-m-d H:i:s', time() - 5 * 86400);
        $job = $db->prepare("INSERT INTO ellsms_bulk_jobs (user_id, type, title, originator, status, created_at) VALUES (?, 'p2p', 'pc', ?, 'done', ?)");
        $item = $db->prepare('INSERT INTO ellsms_bulk_items (job_id, mobile, content, status, operator_id, parts, price_cost_credits, created_at) VALUES (?,?,?,?,?,?,?,?)');
        foreach ([[$this->customerA, $this->mci, self::MCI, 3], [$this->customerB, $this->mtn, self::MTN, 1], [$this->admin, $this->mci, self::MCI, 1]] as [$user, $op, $mobile, $n]) {
            $job->execute([$user, $this->sender, $when]);
            $jobId = (int)$db->lastInsertId();
            for ($i = 0; $i < $n; $i++) {
                $item->execute([$jobId, $mobile, 'سلام دنیا', 'sent', $op, 2, 2248, $when]);
            }
            $item->execute([$jobId, $mobile, 'سلام دنیا', 'failed', $op, 2, null, $when]); // never counted
        }
        // B's MTN message is one part, not two.
        $db->prepare("UPDATE ellsms_bulk_items i JOIN ellsms_bulk_jobs j ON j.id = i.job_id SET i.parts = 1 WHERE j.user_id = ? AND i.status = 'sent'")
           ->execute([$this->customerB]);
        $db->prepare("INSERT INTO ellsms_message_attempts (user_id, reference_type, reference_id, status, destination, originator, content, operator_id, parts, attempted_at)
                      VALUES (?, 'direct_send', 'pc-1', 'accepted', ?, ?, 'سلام', ?, 1, ?)")
           ->execute([$this->customerA, self::MCI, $this->sender, $this->mci, $when]);
        price_usage_profile_build();
    }

    public function testThePreviewPricesLastMonthsTrafficAtTodaysAndTheProposedPrices(): void
    {
        $this->seedTraffic();
        $before = (int)db()->query('SELECT COUNT(*) FROM ellsms_gateway_prices')->fetchColumn();

        $impact = price_change_impact(0, [$this->key('fa') => 1500000]);

        $this->assertSame(1, $impact['customers'], 'only A sends through the changed cell');
        $this->assertSame(2, $impact['customers_total'], 'A and B sent; the admin is never charged');
        $this->assertSame(4, $impact['messages']);
        $this->assertSame(7, $impact['parts']);
        $this->assertSame(7 * 1124, $impact['before']);
        $this->assertSame(7 * 1500, $impact['after']);
        $this->assertSame(7 * 376, $impact['delta']);
        $this->assertSame(7 * 1124 + 1000, $impact['revenue_total'], "B's MTN part costs the route price");
        $this->assertSame(round(7 * 376 * 100 / (7 * 1124 + 1000), 2), $impact['percent']);
        $this->assertSame($this->customerA, $impact['top'][0]['user_id']);
        $this->assertSame(7 * 376, $impact['top'][0]['delta']);
        $this->assertFalse($impact['partial']);
        $this->assertSame($before, (int)db()->query('SELECT COUNT(*) FROM ellsms_gateway_prices')->fetchColumn(), 'a preview writes nothing');
        $this->assertSame(1124, $this->cost($this->customerA), 'and changes no price');
    }

    public function testClearingACellIsPricedAtWhatItFallsBackTo(): void
    {
        $this->seedTraffic();
        $impact = price_change_impact(0, [$this->key('fa') => null]);
        $this->assertSame(7 * 1000, $impact['after'], 'an empty cell falls back to the route price');
        $this->assertSame(7 * (1000 - 1124), $impact['delta']);
    }

    public function testAnAccountsOwnMatrixOnlyLooksAtThatAccountAndTheAccountsUnderIt(): void
    {
        $this->seedTraffic();
        $impact = price_change_impact($this->customerA, [$this->key('fa') => 900000]);
        $this->assertSame(1, $impact['customers_total']);
        $this->assertSame(1, $impact['customers']);
        $this->assertSame(7 * (900 - 1124), $impact['delta'], 'own cells are exact prices');
        $this->assertSame([$this->customerB], price_change_accounts_under($this->customerB));
    }

    public function testApplyingNowRecordsTheChangeAndRevertPutsTheOldPricesBack(): void
    {
        $newCell = $this->key('en'); // had no price of its own
        $applied = price_change_apply_now(0, [$this->key('fa') => 1500000, $newCell => 2000000], $this->admin, ['delta' => 1]);
        $this->assertTrue($applied['ok']);
        $this->assertSame(2, $applied['changed']);
        $this->assertSame(1500, $this->cost($this->customerA));

        $change = price_change_find((int)$applied['change_id']);
        $this->assertSame('applied', $change['status']);
        $this->assertSame([$this->key('fa') => 1124000, $newCell => null], price_change_decode((string)$change['before_json']));

        $revert = price_change_revert((int)$applied['change_id'], $this->admin);
        $this->assertTrue($revert['ok']);
        $this->assertSame(2, $revert['changed']);
        $this->assertSame([], $revert['skipped']);
        $this->assertSame(1124, $this->cost($this->customerA));
        $current = gateway_prices_current(null);
        $this->assertSame(1124000, $current[$this->key('fa')]);
        $this->assertArrayNotHasKey($newCell, $current, 'a cell that had no price has none again');

        $original = price_change_find((int)$applied['change_id']);
        $this->assertSame('reverted', $original['status']);
        $this->assertSame((int)$revert['change_id'], (int)$original['reverted_by_change_id']);
        $this->assertSame((int)$applied['change_id'], (int)price_change_find((int)$revert['change_id'])['reverts_change_id']);
        $this->assertSame('not_applied', price_change_revert((int)$applied['change_id'], $this->admin)['reason'], 'a change is reverted once');

        // History is never rewritten: the reverted period still exists, closed.
        $periods = db()->prepare('SELECT COUNT(*) FROM ellsms_gateway_prices WHERE user_slot = 0 AND gateway_slot = ? AND column_key = ? AND encoding = ?');
        $periods->execute([$this->gatewayId, (string)$this->mci, 'fa']);
        $this->assertSame(3, (int)$periods->fetchColumn(), '1124, 1500, then 1124 again');
    }

    public function testARevertLeavesACellChangedAgainSinceAlone(): void
    {
        $mtnKey = $this->key('fa', $this->mtn);
        $first = price_change_apply_now(0, [$this->key('fa') => 1500000, $mtnKey => 2000000], $this->admin);
        price_change_apply_now(0, [$this->key('fa') => 1700000], $this->admin);

        $revert = price_change_revert((int)$first['change_id'], $this->admin);
        $this->assertTrue($revert['ok']);
        $this->assertSame(1, $revert['changed']);
        $this->assertSame([$this->key('fa')], $revert['skipped']);
        $current = gateway_prices_current(null);
        $this->assertSame(1700000, $current[$this->key('fa')], 'the later change stays');
        $this->assertArrayNotHasKey($mtnKey, $current, 'the untouched cell is reverted');
        $this->assertStringContainsString('1 خانه', (string)price_change_find((int)$first['change_id'])['note']);
    }

    public function testAScheduledChangeWaitsForItsTimeAndCanBeCancelled(): void
    {
        $at = gmdate('Y-m-d H:i:s', time() + 3600);
        $scheduled = price_change_schedule(0, [$this->key('fa') => 1500000], $at, $this->admin, ['delta' => 5]);
        $this->assertTrue($scheduled['ok']);
        $this->assertSame(1124, $this->cost($this->customerA), 'nothing changes before its time');
        $this->assertSame(['applied' => 0, 'failed' => 0, 'unchanged' => 0], price_changes_apply_due());

        $this->assertSame(['applied' => 1, 'failed' => 0, 'unchanged' => 0], price_changes_apply_due(gmdate('Y-m-d H:i:s', time() + 3601)));
        $this->assertSame(1500, $this->cost($this->customerA));
        $change = price_change_find((int)$scheduled['change_id']);
        $this->assertSame('applied', $change['status']);
        $this->assertSame([$this->key('fa') => 1124000], price_change_decode((string)$change['before_json']), 'what it replaced, read when it applied');
        $this->assertSame(['applied' => 0, 'failed' => 0, 'unchanged' => 0], price_changes_apply_due(gmdate('Y-m-d H:i:s', time() + 7200)), 'applied once');

        $other = price_change_schedule(0, [$this->key('fa') => 1800000], $at, $this->admin);
        $this->assertTrue(price_change_cancel((int)$other['change_id'], $this->admin));
        $this->assertFalse(price_change_cancel((int)$other['change_id'], $this->admin), 'cancelled once');
        price_changes_apply_due(gmdate('Y-m-d H:i:s', time() + 7200));
        $this->assertSame(1500, $this->cost($this->customerA), 'a cancelled change never applies');
        $this->assertSame('cancelled', price_change_find((int)$other['change_id'])['status']);

        $this->assertSame('not_in_future', price_change_schedule(0, [$this->key('fa') => 1], gmdate('Y-m-d H:i:s', time() - 60), $this->admin)['reason']);
        $this->assertSame('invalid_cell', price_change_schedule(0, ['abc|other|fa' => 1000], $at, $this->admin)['reason']);
        $this->assertSame('invalid_price', price_change_schedule(0, [$this->key('fa') => 0], $at, $this->admin)['reason']);
    }

    public function testAScheduledChangeThatNoLongerValidatesIsMarkedFailed(): void
    {
        $scheduled = price_change_schedule(0, [$this->key('fa') => 1500000], gmdate('Y-m-d H:i:s', time() + 60), $this->admin);
        db()->prepare("UPDATE ellsms_sms_gateways SET status = 'archived' WHERE id = ?")->execute([$this->gatewayId]);
        $this->assertSame(['applied' => 0, 'failed' => 1, 'unchanged' => 0], price_changes_apply_due(gmdate('Y-m-d H:i:s', time() + 120)));
        $change = price_change_find((int)$scheduled['change_id']);
        $this->assertSame('failed', $change['status']);
        $this->assertNotEmpty($change['note']);
    }

    public function testTheMatrixFormPreviewsFirstAndSavesOnlyOnConfirmation(): void
    {
        $this->seedTraffic();
        $me = ['id' => $this->admin];
        $_POST = ['price' => [$this->gatewayId => [(string)$this->mci => ['fa' => '1,500']]]];
        $this->assertTrue(price_change_handle_post('matrix_save', 0, $me, 'matrix_save'));
        $draft = price_change_draft(0);
        $this->assertNotNull($draft, 'the preview waits in the session');
        $this->assertSame([$this->key('fa') => 1500000], $draft['cells'], 'only the changed cell');
        $this->assertSame(7 * 376, $draft['impact']['delta']);
        $this->assertSame(1124, $this->cost($this->customerA), 'nothing saved yet');

        $_POST = ['token' => 'not-the-token'];
        price_change_handle_post('price_change_confirm', 0, $me, 'matrix_save');
        $this->assertSame(1124, $this->cost($this->customerA), 'a wrong token saves nothing');
        $this->assertNull(price_change_draft(0));

        $_POST = ['price' => [$this->gatewayId => [(string)$this->mci => ['fa' => '1500']]]];
        price_change_handle_post('matrix_save', 0, $me, 'matrix_save');
        $_POST = ['token' => price_change_draft(0)['token'], 'when' => 'now'];
        price_change_handle_post('price_change_confirm', 0, $me, 'matrix_save');
        $this->assertSame(1500, $this->cost($this->customerA));
        $history = price_changes_list(0);
        $this->assertSame('applied', $history[0]['status']);
        $this->assertSame(7 * 376, $history[0]['impact']['delta'], 'the estimate is kept with the change');

        // Later: a Tehran date and time, stored in UTC.
        $_POST = ['price' => [$this->gatewayId => [(string)$this->mci => ['fa' => '1600']]]];
        price_change_handle_post('matrix_save', 0, $me, 'matrix_save');
        $tomorrow = (new \DateTimeImmutable('+1 day', new \DateTimeZone('Asia/Tehran')));
        [$jy, $jm, $jd] = gregorian_to_jalali((int)$tomorrow->format('Y'), (int)$tomorrow->format('n'), (int)$tomorrow->format('j'));
        $_POST = $_REQUEST = ['token' => price_change_draft(0)['token'], 'when' => 'later', 'effective_y' => $jy, 'effective_m' => $jm, 'effective_d' => $jd,
                              'effective_time_h' => '9', 'effective_time_i' => '30'];
        price_change_handle_post('price_change_confirm', 0, $me, 'matrix_save');
        $_REQUEST = [];
        $history = price_changes_list(0);
        $this->assertSame('scheduled', $history[0]['status']);
        $this->assertSame(price_change_local_to_utc($tomorrow->format('Y-m-d'), 9, 30), (string)$history[0]['effective_at']);
        $this->assertSame(1500, $this->cost($this->customerA), 'still the old price until then');

        $_POST = ['price' => [$this->gatewayId => [(string)$this->mci => ['fa' => '1500']]]];
        price_change_handle_post('matrix_save', 0, $me, 'matrix_save');
        $this->assertNull(price_change_draft(0), 'an unchanged form opens no preview');
        $_POST = [];
    }

    public function testPureHelpers(): void
    {
        $this->assertSame(['a' => 2, 'c' => null], price_change_diff(['a' => 2, 'b' => 3, 'c' => null, 'd' => null], ['a' => 1, 'b' => 3, 'c' => 5]));
        $this->assertSame('2026-11-17 06:00:00', price_change_local_to_utc('2026-11-17', 9, 30));
        $this->assertNull(price_change_local_to_utc(null, 9, 30));
        $this->assertNull(price_change_local_to_utc('2026-11-17', 24, 0));
        $plan = price_change_revert_cells(['cells_json' => '{"k1":5,"k2":6,"k3":7}', 'before_json' => '{"k1":1,"k2":null,"k3":3}'], ['k1' => 5, 'k3' => 9]);
        $this->assertSame(['k1' => 1], $plan['cells'], 'k2 was cleared since (current null ≠ 6), k3 changed again');
        $this->assertSame(['k2', 'k3'], $plan['skipped']);
        $this->assertSame('{}', price_change_json([]));
        $this->assertSame(['x|1|fa' => null, 'y|2|en' => 5], price_change_decode('{"x|1|fa":null,"y|2|en":5}'));
        $this->assertSame('+۱,۲۰۰', price_change_format_delta(1200));
        $this->assertSame('−۵', price_change_format_delta(-5));
    }
}
