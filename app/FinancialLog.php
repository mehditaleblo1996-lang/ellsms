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
 * was never sent. That is why an sms_debit row's balance_before equals its balance_after.
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

/** @return array{count:int, increase:int, decrease:int} decrease is a positive total */
function financial_log_totals(string $whereSql, array $params): array {
    $st = db()->prepare('SELECT COUNT(*) n,
                                COALESCE(SUM(CASE WHEN t.amount > 0 THEN t.amount ELSE 0 END), 0) inc,
                                COALESCE(SUM(CASE WHEN t.amount < 0 THEN -t.amount ELSE 0 END), 0) dec_
                         FROM ellsms_wallet_transactions t' . $whereSql);
    $st->execute($params);
    $row = $st->fetch() ?: [];
    return ['count' => (int)($row['n'] ?? 0), 'increase' => (int)($row['inc'] ?? 0), 'decrease' => (int)($row['dec_'] ?? 0)];
}

function financial_log_rows(string $whereSql, array $params, int $limit, int $offset): array {
    $st = db()->prepare('SELECT t.id, t.user_id, t.type, t.amount, t.balance_before, t.balance_after, t.reference_type,
                                t.reference_id, t.metadata, t.actor_user_id, t.created_at
                         FROM ellsms_wallet_transactions t' . $whereSql . '
                         ORDER BY t.id DESC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset));
    $st->execute($params);
    return $st->fetchAll();
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
    if (!empty($meta['clamped']) && ($row['type'] ?? '') === 'manual_debit') {
        $parts[] = 'به‌اندازه‌ی موجودی کسر شد';
    }
    if (isset($meta['reason']) && trim((string)$meta['reason']) !== '') $parts[] = trim((string)$meta['reason']);
    return implode(' — ', $parts);
}
