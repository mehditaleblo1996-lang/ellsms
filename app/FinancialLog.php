<?php
/**
 * ELLSMS — financial history (تاریخچه مالی): every increase and decrease of an account's balance,
 * read straight from the wallet ledger (ellsms_wallet_transactions, app/wallet.php).
 *
 * Nothing here writes: the ledger already records every credit and debit with the balance before
 * and after it, so this file only filters, labels and sums those rows for public/financial-log.php.
 * A platform admin sees every account; anyone else sees only their own.
 *
 * Note on sends: a send first reserves its cost (available balance drops, no ledger row), then the
 * 'sms_debit' row records what was actually spent and 'reservation_release' gives back the part that
 * was never sent. That is why an sms_debit row's balance_before equals its balance_after. *
 * A bulk send is shown as ONE row (financial_log_grouped(), financial_log_fold_bulk_rows(),
 * docs/financial-log.md); app/BulkBilling.php flags a send whose charges do not add up.
 */

declare(strict_types=1);

/** Persian label for every ledger type app/wallet.php and its callers write. */
function financial_log_type_labels(): array {
    return [
        'purchase'                  => 'خرید اعتبار',
        'refund'                    => 'بازگشت وجه فاکتور',
        'manual_credit'             => 'افزایش توسط مدیر',
        'manual_debit'              => 'کاهش توسط مدیر',
        'sms_debit'                 => 'هزینه‌ی ارسال',
        'ai_debit'                  => 'هزینه‌ی هوش مصنوعی',
        'reservation_release'       => 'برگشت رزرو ارسال‌نشده',
        'transfer_in'               => 'انتقال اعتبار (دریافت)',
        'transfer_out'              => 'انتقال اعتبار (پرداخت)',
        'reseller_profit'           => 'سود نمایندگی',
        'reseller_package_sale'     => 'تسویه‌ی فروش بسته',
        'delivery_refund'           => 'بازگشت هزینه‌ی پیام تحویل‌نشده',
        'migration_opening_balance' => 'موجودی اولیه',
        'currency_conversion'       => 'تبدیل واحد پول به ریال',
        'adjustment'                => 'اصلاح موجودی',
    ];
}

function financial_log_type_label(string $type): string {
    return financial_log_type_labels()[$type] ?? $type;
}

/**
 * Normalizes the page's query string. $forcedUserId pins the user filter (a non-admin only ever sees
 * their own rows, whatever the URL says); dates come in as Gregorian 'Y-m-d' Tehran-local days.
 *
 * @return array{user_id:int, username:string, type:string, direction:string, from:?string, to:?string}
 */
function financial_log_filters(array $get, ?int $forcedUserId, ?string $from = null, ?string $to = null): array {
    $type = trim((string)($get['type'] ?? ''));
    $direction = (string)($get['direction'] ?? '');
    $valid = static fn(?string $d): ?string => $d !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : null;
    $from = $valid($from);
    $to = $valid($to);
    if ($from !== null && $to !== null && $from > $to) [$from, $to] = [$to, $from];
    return [
        'user_id'   => $forcedUserId ?? max(0, (int)from_persian_digits((string)($get['user_id'] ?? ''))),
        'username'  => $forcedUserId !== null ? '' : trim((string)($get['username'] ?? '')),
        'type'      => isset(financial_log_type_labels()[$type]) ? $type : '',
        'direction' => in_array($direction, ['in', 'out'], true) ? $direction : '',
        'from'      => $from,
        'to'        => $to,
    ];
}

/**
 * Pure: the WHERE clause and its parameters for $filters (financial_log_filters()). A username that
 * matches no account must still narrow the result, so the caller passes $userIdFromName = 0 for it.
 *
 * @return array{0:string, 1:list<int|string>}
 */
function financial_log_where(array $filters, ?int $userIdFromName = null): array {
    $where = [];
    $params = [];
    $userId = $filters['user_id'] > 0 ? $filters['user_id'] : $userIdFromName;
    if ($userId !== null) { $where[] = 't.user_id = ?'; $params[] = $userId; }
    if ($filters['type'] !== '') { $where[] = 't.type = ?'; $params[] = $filters['type']; }
    if ($filters['direction'] === 'in') $where[] = 't.amount > 0';
    if ($filters['direction'] === 'out') $where[] = 't.amount < 0';
    if ($filters['from'] !== null || $filters['to'] !== null) {
        [$fromUtc, $toUtc] = local_day_range_to_utc($filters['from'] ?? '2000-01-01', $filters['to'] ?? '2100-01-01');
        if ($filters['from'] !== null) { $where[] = 't.created_at >= ?'; $params[] = $fromUtc; }
        if ($filters['to'] !== null) { $where[] = 't.created_at < ?'; $params[] = $toUtc; }
    }
    return [$where ? ' WHERE ' . implode(' AND ', $where) : '', $params];
}

/** Resolves the username filter to an id: null when there is none, 0 when it matches no account. */
function financial_log_username_id(array $filters): ?int {
    if ($filters['user_id'] > 0 || $filters['username'] === '') return null;
    $st = db()->prepare('SELECT id FROM user_ WHERE username = ?');
    $st->execute([$filters['username']]);
    $id = $st->fetchColumn();
    return $id !== false ? (int)$id : 0;
}

/**
 * Whether a bulk send is shown as ONE row (docs/financial-log.md). A bulk job charges each message on
 * its own ledger row (sms_debit, reference bulk_job:{id}) and gives back what it did not spend in one
 * reservation_release row; the log sums those into a single «هزینه‌ی ارسال گروهی» row. Only when the
 * filter could show both kinds or just the charges — a filter for releases or increases alone lists
 * the raw rows. Other sends (direct, scheduled, …) were always one row each and stay so.
 */
function financial_log_grouped(array $filters): bool {
    return in_array($filters['type'], ['', 'sms_debit'], true) && in_array($filters['direction'], ['', 'out'], true);
}

/**
 * A bulk job's ledger rows: its per-message charges (sms_debit) and its one reservation_release. Those
 * are the only ledger rows written with reference_type 'bulk_job' (app/wallet.php), so the reference
 * alone identifies them — which the (reference_type, reference_id) index can answer.
 */
const FINANCIAL_LOG_BULK_SQL = "t.reference_type = 'bulk_job'";

/** @return array{count:int, increase:int, decrease:int} decrease is a positive total */
function financial_log_totals(string $whereSql, array $params, bool $grouped = false): array {
    // Grouped, a bulk job's release is folded into its row (it only returns unspent reservation), so
    // it is not an increase of its own; the job's charges stay in the decrease total. The row count is
    // the other rows plus one per bulk job — counted in a second, index-friendly query rather than a
    // COUNT(DISTINCT CASE …) over every row.
    $bulk = FINANCIAL_LOG_BULK_SQL;
    $st = db()->prepare('SELECT COUNT(*) n, ' . ($grouped ? "COALESCE(SUM({$bulk}), 0)" : '0') . ' n_bulk,
                                COALESCE(SUM(CASE WHEN t.amount > 0' . ($grouped ? " AND NOT {$bulk}" : '') . ' THEN t.amount ELSE 0 END), 0) inc,
                                COALESCE(SUM(CASE WHEN t.amount < 0 THEN -t.amount ELSE 0 END), 0) dec_
                         FROM ellsms_wallet_transactions t' . $whereSql);
    $st->execute($params);
    $row = $st->fetch() ?: [];
    $count = (int)($row['n'] ?? 0);
    if ($grouped && (int)($row['n_bulk'] ?? 0) > 0) {
        $jobs = db()->prepare('SELECT COUNT(DISTINCT t.reference_id) FROM ellsms_wallet_transactions t'
            . ($whereSql === '' ? ' WHERE ' : $whereSql . ' AND ') . $bulk);
        $jobs->execute($params);
        $count = $count - (int)$row['n_bulk'] + (int)$jobs->fetchColumn();
    }
    return ['count' => $count, 'increase' => (int)($row['inc'] ?? 0), 'decrease' => (int)($row['dec_'] ?? 0)];
}

const FINANCIAL_LOG_COLUMNS = 't.id, t.user_id, t.type, t.amount, t.balance_before, t.balance_after, t.reference_type,
                               t.reference_id, t.metadata, t.actor_user_id, t.created_at';

function financial_log_rows(string $whereSql, array $params, int $limit, int $offset, bool $grouped = false): array {
    if (!$grouped) {
        $st = db()->prepare('SELECT ' . FINANCIAL_LOG_COLUMNS . ' FROM ellsms_wallet_transactions t' . $whereSql . '
                             ORDER BY t.id DESC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset));
        $st->execute($params);
        return $st->fetchAll();
    }
    $ids = financial_log_page_ids($whereSql, $params, max(1, $limit), max(0, $offset));
    if ($ids === []) return [];
    $st = db()->prepare('SELECT ' . FINANCIAL_LOG_COLUMNS . ' FROM ellsms_wallet_transactions t WHERE t.id IN ('
        . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY t.id DESC');
    $st->execute($ids);
    $rows = $st->fetchAll();
    $jobIds = [];
    foreach ($rows as $r) {
        if ($r['reference_type'] === 'bulk_job') $jobIds[] = (string)$r['reference_id'];
    }
    return financial_log_fold_bulk_rows($rows, financial_log_bulk_job_facts($jobIds));
}

/**
 * The ledger ids of one page of the grouped list, newest first: every row that is not a bulk job's,
 * and for a bulk job only its newest row. Walks the ledger backwards by primary key in chunks of small
 * rows (id and reference only), leaving out the jobs already met, and stops as soon as the page is
 * full — so a page costs the rows of the jobs on it and before it, never a GROUP BY over the ledger
 * (which took seconds on a large one).
 *
 * @return list<int>
 */
function financial_log_page_ids(string $whereSql, array $params, int $limit, int $offset): array {
    $db = db();
    $filter = $whereSql === '' ? '' : ' AND ' . substr($whereSql, strlen(' WHERE '));
    $want = $offset + $limit;
    $ids = [];
    $seen = [];
    $cursor = PHP_INT_MAX;
    while (count($ids) < $want) {
        $exclude = '';
        $excludeParams = [];
        if ($seen !== []) {
            $exclude = ' AND NOT (' . FINANCIAL_LOG_BULK_SQL . ' AND t.reference_id IN (' . implode(',', array_fill(0, count($seen), '?')) . '))';
            $excludeParams = array_map('strval', array_keys($seen));
        }
        $st = $db->prepare('SELECT t.id, t.reference_type, t.reference_id FROM ellsms_wallet_transactions t
                            WHERE t.id < ?' . $filter . $exclude . ' ORDER BY t.id DESC LIMIT ' . FINANCIAL_LOG_SCAN_CHUNK);
        $st->execute(array_merge([$cursor], $params, $excludeParams));
        $chunk = $st->fetchAll(PDO::FETCH_NUM);
        if ($chunk === []) break;
        foreach ($chunk as [$id, $refType, $refId]) {
            $cursor = (int)$id;
            if ($refType === 'bulk_job') {
                if (isset($seen[(string)$refId])) continue;
                $seen[(string)$refId] = true;
            }
            $ids[] = (int)$id;
            if (count($ids) >= $want) break;
        }
        if (count($chunk) < FINANCIAL_LOG_SCAN_CHUNK) break;
    }
    return array_slice($ids, $offset, $limit);
}

/** Ledger rows read per step of financial_log_page_ids(). */
const FINANCIAL_LOG_SCAN_CHUNK = 5000;

/**
 * Per bulk job, from its reservation and a few indexed lookups (never by reading all its rows):
 *   cost       what it charged: reservation − what is still reserved (sending) or − what was released;
 *   count      its charge rows (its ledger rows, by index, less its release);
 *   after      the balance after its release, else after its newest row;
 *   reserved / active   its reservation and whether it is still sending.
 *
 * @return array<string, array{reserved:int, active:bool, cost:int, count:int, after:?int}>
 */
function financial_log_bulk_job_facts(array $jobIds): array {
    $jobIds = array_values(array_unique(array_map('strval', $jobIds)));
    if ($jobIds === []) return [];
    $in = implode(',', array_fill(0, count($jobIds), '?'));
    $db = db();
    $facts = [];
    foreach ($jobIds as $id) {
        $facts[$id] = ['reserved' => 0, 'active' => false, 'cost' => 0, 'count' => 0, 'after' => null, 'has_reservation' => false];
    }
    $remaining = [];
    $st = $db->prepare("SELECT reference_id, amount, remaining_amount, status FROM ellsms_wallet_reservations WHERE reference_type = 'bulk_job' AND reference_id IN ({$in})");
    $st->execute($jobIds);
    foreach ($st->fetchAll() as $r) {
        $id = (string)$r['reference_id'];
        $facts[$id]['reserved'] = (int)$r['amount'];
        $facts[$id]['active'] = (string)$r['status'] === 'active';
        $facts[$id]['has_reservation'] = true;
        $remaining[$id] = (int)$r['remaining_amount'];
    }
    // Index-only: how many ledger rows each job has and its newest one.
    $st = $db->prepare("SELECT reference_id, COUNT(*) n, MAX(id) mid FROM ellsms_wallet_transactions
                        WHERE reference_type = 'bulk_job' AND reference_id IN ({$in}) GROUP BY reference_id");
    $st->execute($jobIds);
    $newest = [];
    foreach ($st->fetchAll() as $r) {
        $facts[(string)$r['reference_id']]['count'] = (int)$r['n'];
        $newest[(int)$r['mid']] = (string)$r['reference_id'];
    }
    // The release row, by its unique idempotency key (app/wallet.php wallet_release_reservation()).
    $keys = array_map(static fn(string $id): string => 'release:bulk_job:' . $id, $jobIds);
    $st = $db->prepare('SELECT reference_id, amount, balance_after FROM ellsms_wallet_transactions WHERE idempotency_key IN ('
        . implode(',', array_fill(0, count($keys), '?')) . ')');
    $st->execute($keys);
    $released = [];
    foreach ($st->fetchAll() as $r) {
        $id = (string)$r['reference_id'];
        $released[$id] = (int)$r['amount'];
        $facts[$id]['after'] = (int)$r['balance_after'];
        $facts[$id]['count']--;
    }
    if ($newest !== []) {
        $st = $db->prepare('SELECT id, balance_after FROM ellsms_wallet_transactions WHERE id IN (' . implode(',', array_fill(0, count($newest), '?')) . ')');
        $st->execute(array_keys($newest));
        foreach ($st->fetchAll() as $r) {
            $id = $newest[(int)$r['id']];
            if (!isset($released[$id])) $facts[$id]['after'] = (int)$r['balance_after'];
        }
    }
    $noReservation = [];
    foreach ($facts as $id => &$f) {
        if ($f['has_reservation']) {
            $f['cost'] = $f['reserved'] - ($f['active'] ? ($remaining[$id] ?? 0) : ($released[$id] ?? 0));
        } else {
            $noReservation[] = $id;
        }
    }
    unset($f);
    if ($noReservation !== []) {
        // No reservation row (should not happen for a charged job): sum its charges directly.
        $st = $db->prepare("SELECT reference_id, COALESCE(SUM(-amount), 0) c FROM ellsms_wallet_transactions
                            WHERE reference_type = 'bulk_job' AND type = 'sms_debit' AND reference_id IN ("
            . implode(',', array_fill(0, count($noReservation), '?')) . ') GROUP BY reference_id');
        $st->execute($noReservation);
        foreach ($st->fetchAll() as $r) $facts[(string)$r['reference_id']]['cost'] = (int)$r['c'];
    }
    foreach ($facts as &$f) unset($f['has_reservation']);
    unset($f);
    return $facts;
}

/**
 * Pure: turns each bulk job's row into one «هزینه‌ی ارسال گروهی» row, from its facts
 * (financial_log_bulk_job_facts()).
 *
 * The money left the available balance when the job RESERVED its cost (no ledger row of its own), and
 * the per-message charges then took it from the reservation, so their own before/after are equal. The
 * row therefore shows: after = the balance once the job settled (after its release; while it is still
 * sending, after its newest charge), before = after + what the job cost (finished) or + its reservation
 * (still sending). Exact when nothing else touched the account while the job ran.
 */
function financial_log_fold_bulk_rows(array $rows, array $facts): array {
    foreach ($rows as &$r) {
        if (($r['reference_type'] ?? '') !== 'bulk_job') continue;
        $f = $facts[(string)$r['reference_id']] ?? null;
        if ($f === null) continue;
        $after = $f['after'] ?? (int)$r['balance_after'];
        $r['type'] = 'sms_debit';
        $r['amount'] = -$f['cost'];
        $r['balance_after'] = $after;
        $r['balance_before'] = $after + ($f['active'] ? $f['reserved'] : $f['cost']);
        $meta = ['bulk_group' => true, 'sent' => max(0, $f['count'])];
        if ($f['active']) $meta['in_progress'] = true;
        $r['metadata'] = json_encode($meta);
    }
    unset($r);
    return $rows;
}

/** The type column of a row: a bulk send's single row reads «هزینه‌ی ارسال گروهی». */
function financial_log_row_label(array $row): string {
    $meta = json_decode((string)($row['metadata'] ?? ''), true);
    if (is_array($meta) && !empty($meta['bulk_group'])) return 'هزینه‌ی ارسال گروهی';
    return financial_log_type_label((string)$row['type']);
}

/** Pure: every user id $rows name — the account, the admin who acted, the other side of a transfer. */
function financial_log_user_ids(array $rows): array {
    $ids = [];
    foreach ($rows as $r) {
        $ids[] = (int)$r['user_id'];
        if (!empty($r['actor_user_id'])) $ids[] = (int)$r['actor_user_id'];
        $meta = json_decode((string)($r['metadata'] ?? ''), true);
        foreach (['to_user_id', 'from_user_id'] as $k) {
            if (is_array($meta) && isset($meta[$k])) $ids[] = (int)$meta[$k];
        }
    }
    return array_values(array_unique($ids));
}

/** What a ledger row's reference_type points at; types not listed (admin adjustments, transfers) show no reference. */
function financial_log_reference_labels(): array {
    return [
        'payment'           => 'پرداخت',
        'invoice'           => 'فاکتور',
        'bulk_job'          => 'ارسال گروهی',
        'import_job'        => 'ارسال از فایل',
        'schedule'          => 'ارسال زمان‌بندی‌شده',
        'direct_send'       => 'ارسال مستقیم',
        'direct_send_queue' => 'ارسال مستقیم',
        'autoreply'         => 'منشی پیامک',
        'ai_secretary'      => 'منشی هوشمند',
        'ai_usage'          => 'منشی هوشمند (هوش مصنوعی)',
        'ai_manual'         => 'پاسخ اپراتور در منشی هوشمند',
        'ai_outreach'       => 'پیام اول منشی هوشمند',
        'regional_bulk'     => 'ارسال منطقه‌ای',
        'voice_message'     => 'پیام صوتی',
        'voice_tts'         => 'ساخت فایل صوتی',
        'shared_otp'        => 'کد تایید اشتراکی',
        'shared_service'    => 'سرویس ارسال اشتراکی',
        'reseller_package'  => 'بسته‌ی نماینده',
    ];
}

/**
 * Pure: one line saying where an entry came from — the admin's reason, the other side of a transfer,
 * the invoice or payment — built from its reference and metadata. $usernames maps user id => name.
 */
function financial_log_description(array $row, array $usernames = []): string {
    $meta = json_decode((string)($row['metadata'] ?? ''), true);
    $meta = is_array($meta) ? $meta : [];
    $name = static fn(int $id): string => $usernames[$id] ?? ('#' . $id);
    $refId = (string)($row['reference_id'] ?? '');
    $parts = [];

    $refLabel = financial_log_reference_labels()[(string)($row['reference_type'] ?? '')] ?? null;
    if ($refLabel !== null) $parts[] = $refLabel . ($refId !== '' ? ' #' . $refId : '');
    if (isset($meta['to_user_id']))   $parts[] = 'به ' . $name((int)$meta['to_user_id']);
    if (isset($meta['from_user_id'])) $parts[] = 'از ' . $name((int)$meta['from_user_id']);
    if (isset($meta['sent']))         $parts[] = to_persian_digits((string)(int)$meta['sent']) . ' پیامک';
    if (!empty($meta['in_progress'])) $parts[] = 'در حال ارسال';
    if (!empty($meta['clamped']) && ($row['type'] ?? '') === 'manual_debit') {
        $parts[] = 'به‌اندازه‌ی موجودی کسر شد';
    }
    if (isset($meta['reason']) && trim((string)$meta['reason']) !== '') $parts[] = trim((string)$meta['reason']);
    return implode(' — ', $parts);
}
