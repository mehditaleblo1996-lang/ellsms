<?php
/**
 * ELLSMS — settle a bulk job's [SEND_UNCERTAIN] rows from the provider's own outbox report
 * (docs/job-queue-architecture.md «Reconciling uncertain rows from the provider's report»). CLI:
 * cron/bulk-reconcile-provider-report.php.
 *
 * A row is failed as [SEND_UNCERTAIN] when the worker cannot tell whether the provider took it. The
 * provider's report (PishgamRayan: panel → outbox → CSV export) says what really went out. Every
 * uncertain row of the job that the report lists becomes «ارسال‌شده», with the provider's message id
 * and delivery state (so the status worker keeps following it), and — unless the job belongs to an
 * admin, or $charge is false — its frozen price is charged to the owner, as bulk_finalize_item()
 * would have done (a direct debit: the job's reservation was released when it finished). A row the
 * report does not list stays failed: it never reached the recipient.
 *
 * A report row is matched to its bulk item by the request tag `ellsms:<first>-<last>` (the item id
 * range of the request, send variable `request_tag`) and the recipient number (last 10 digits).
 * Safe to run again: only rows still failed as [SEND_UNCERTAIN] are touched, and each charge carries
 * the item's own idempotency key ('commit:bulk_item:<id>'), so nothing is charged twice.
 */

declare(strict_types=1);

/** PishgamRayan's MessageStatus text => [delivery_status, provider status code]. */
const RECONCILE_STATUS_MAP = [
    'رسیده به گوشی'      => ['delivered', '10'],
    'رسیده به گوشی جزئی' => ['sent', '5'],
    'ارسال به مخابرات'   => ['sent', '1'],
    'در انتظار ارسال'    => ['sent', '0'],
    'نرسیده به مخابرات'  => ['sent', '2'],
    'نرسیده به گوشی'     => ['sent', '4'],
    'لیست سیاه'          => ['sent', '3'],
    'خطا در ارسال'       => ['sent', '13'],
];

/**
 * @return list<string> report lines
 * @throws RuntimeException bad job id or CSV
 */
function bulk_reconcile_provider_report(int $jobId, string $csvPath, bool $apply, bool $charge = true): array {
    $out = [];
    $db = db();
    $jobSt = $db->prepare('SELECT id, user_id, title FROM ellsms_bulk_jobs WHERE id = ?');
    $jobSt->execute([$jobId]);
    $job = $jobSt->fetch();
    if (!$job) {
        throw new RuntimeException("job {$jobId} not found");
    }
    $owner = backend_find_user_by_id((int)$job['user_id']);
    $chargeOwner = $charge && $owner && empty($owner['is_admin']);

    // The job's uncertain rows, by id.
    $st = $db->prepare("SELECT id, mobile, content, price_cost_credits FROM ellsms_bulk_items
                        WHERE job_id = ? AND status = 'failed' AND error LIKE ?");
    $st->execute([$jobId, '[' . BULK_SEND_UNCERTAIN_CODE . ']%']);
    $uncertain = [];
    foreach ($st->fetchAll() as $r) {
        $uncertain[(int)$r['id']] = $r;
    }
    // last 10 digits of the number => uncertain item ids with that number.
    $last10 = static fn(string $n): string => substr(preg_replace('/\D/', '', $n) ?? '', -10);
    $byNumber = [];
    foreach ($uncertain as $id => $item) {
        $byNumber[$last10((string)$item['mobile'])][] = $id;
    }

    // The gateway/route the job's settled rows used: the uncertain rows went out the same way.
    $via = $db->prepare("SELECT gateway_id, route_id, gateway_config_version FROM ellsms_bulk_items
                         WHERE job_id = ? AND status = 'sent' AND gateway_id IS NOT NULL
                         GROUP BY gateway_id, route_id, gateway_config_version ORDER BY COUNT(*) DESC LIMIT 1");
    $via->execute([$jobId]);
    $via = $via->fetch() ?: ['gateway_id' => null, 'route_id' => null, 'gateway_config_version' => null];

    $fh = @fopen($csvPath, 'rb');
    if ($fh === false) {
        throw new RuntimeException("cannot read {$csvPath}");
    }
    $header = fgetcsv($fh);
    if ($header === false) {
        throw new RuntimeException('empty CSV');
    }
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
    $col = array_flip($header);
    foreach (['Number', 'UserTag', 'RecipientId', 'MessageStatus'] as $need) {
        if (!isset($col[$need])) {
            throw new RuntimeException("CSV has no {$need} column");
        }
    }

    $matches = [];   // item id => [provider id, delivery status, provider code]
    $unknownStatus = [];
    $reportRows = 0;
    while (($row = fgetcsv($fh)) !== false) {
        $tag = (string)($row[$col['UserTag']] ?? '');
        if (!preg_match('/^ellsms:(\d+)(?:-(\d+))?$/', $tag, $m)) {
            continue;
        }
        $from = (int)$m[1];
        $to = isset($m[2]) && $m[2] !== '' ? (int)$m[2] : $from;
        $reportRows++;
        $number = $last10((string)$row[$col['Number']]);
        foreach ($byNumber[$number] ?? [] as $id) {
            if ($id >= $from && $id <= $to) {
                $text = trim((string)$row[$col['MessageStatus']]);
                if (!isset(RECONCILE_STATUS_MAP[$text])) {
                    $unknownStatus[$text] = true;
                }
                [$delivery, $code] = RECONCILE_STATUS_MAP[$text] ?? ['sent', null];
                $matches[$id] = [(string)$row[$col['RecipientId']], $delivery, $code];
                break;
            }
        }
    }
    fclose($fh);

    $cost = 0;
    foreach (array_keys($matches) as $id) {
        $item = $uncertain[$id];
        $cost += $item['price_cost_credits'] !== null ? (int)$item['price_cost_credits'] : sms_pricing_legacy_cost_for_segments(sms_parts((string)$item['content']));
    }
    $out[] = sprintf("job %d (%s): %d [SEND_UNCERTAIN] row(s); %d tagged rows in the report; %d sent according to the report, %d not in it (stay failed)\n",
        $jobId, $job['title'], count($uncertain), $reportRows, count($matches), count($uncertain) - count($matches));
    $out[] = sprintf("charge: %s\n", $chargeOwner ? number_format($cost) . " Rial to user #{$job['user_id']}" : ($charge ? 'admin-owned job, not charged' : 'skipped (--no-charge)'));
    if ($unknownStatus !== []) {
        $out[] = sprintf("unrecognised MessageStatus values (settled as sent): %s\n", implode(', ', array_keys($unknownStatus)));
    }
    if (!$apply) {
        $out[] = "Dry run — add --apply to settle them.\n";
        return $out;
    }

    $settled = 0;
    $charged = 0;
    $notCharged = 0;
    foreach ($matches as $id => [$providerId, $delivery, $code]) {
        $item = $uncertain[$id];
        $amount = $item['price_cost_credits'] !== null ? (int)$item['price_cost_credits'] : sms_pricing_legacy_cost_for_segments(sms_parts((string)$item['content']));
        $u = $db->prepare("UPDATE ellsms_bulk_items
                           SET status = 'sent', error = NULL, provider_message_id = ?, delivery_status = ?, provider_status = ?,
                               delivered_at = IF(? = 'delivered', NOW(), delivered_at),
                               gateway_id = COALESCE(gateway_id, ?), route_id = COALESCE(route_id, ?), gateway_config_version = COALESCE(gateway_config_version, ?)
                           WHERE id = ? AND status = 'failed' AND error LIKE ?");
        $u->execute([$providerId !== '' ? $providerId : null, $delivery, $code, $delivery, $via['gateway_id'], $via['route_id'], $via['gateway_config_version'],
                     $id, '[' . BULK_SEND_UNCERTAIN_CODE . ']%']);
        if ($u->rowCount() === 0) {
            continue; // settled by an earlier run
        }
        $db->prepare('UPDATE ellsms_bulk_jobs SET sent_rows = sent_rows + 1, failed_rows = GREATEST(0, failed_rows - 1) WHERE id = ?')->execute([$jobId]);
        $settled++;
        if ($chargeOwner && $amount > 0) {
            // A direct debit: the job's reservation was released when it finished.
            $r = wallet_debit((int)$job['user_id'], $amount, 'sms_debit', 'bulk_job', (string)$jobId, 'commit:bulk_item:' . $id, null,
                ['reconciled_from_provider_report' => true, 'bulk_item_id' => $id]);
            if ($r['ok'] ?? false) {
                $charged += $amount;
            } else {
                $notCharged++;
            }
        }
    }
    audit(0, 'bulk.reconcile_provider_report', "job {$jobId}: {$settled} [SEND_UNCERTAIN] rows settled as sent from the provider report, {$charged} Rial charged"
        . ($notCharged > 0 ? ", {$notCharged} not charged (insufficient balance)" : ''));
    $out[] = sprintf("Settled %d row(s) as sent; charged %s Rial%s.\n", $settled, number_format($charged),
        $notCharged > 0 ? "; {$notCharged} row(s) NOT charged — insufficient balance (the job is flagged as a billing mismatch)" : '');
    // Re-check the job's figures (app/BulkBilling.php): flags anything that still does not add up.
    $problem = bulk_billing_check_job($jobId);
    $out[] = $problem === null ? "Billing check: OK\n" : "Billing check: {$problem}\n";
    return $out;
}
