<?php
/**
 * ELLSMS — per-customer SMS price per operator (docs/customer-operator-prices.md).
 *
 * A platform admin can give an account a fixed Rial price per segment for each operator, and
 * optionally one "every other operator" price that also covers numbers of unknown operator. For an
 * operator with such a price the account pays exactly that, instead of the route price times its
 * tariff percentage; every other operator is priced as before. sms_pricing_price_messages() applies
 * it, so previews, direct sends, bulk jobs and imports all charge it and snapshot it.
 *
 * Rules:
 *  - A sub-organization pays its parent organization's prices, as it pays its tariff
 *    (customer_effective_tariff_bp()).
 *  - Accounts under a reseller cannot have one: their price is the reseller's tariff, and the
 *    reseller's nightly profit is computed from tariffs (customer_profit_split()). An exact price
 *    there would leave that profit undefined. customer_operator_prices_eligible() is checked when
 *    saving AND when pricing, so moving an account under a reseller later disables its prices.
 *  - Prices are effective-dated (a change closes the current row and opens a new one), and pricing
 *    reads the row in effect at the pricing instant, like ellsms_sms_route_prices.
 */

declare(strict_types=1);

/** Whether 2026_10_13_customer_operator_prices.sql has been applied. Cached per process. */
function customer_operator_prices_available(?bool $set = null): bool {
    static $available = null;
    if ($set !== null) {
        return $available = $set;
    }
    if ($available === null) {
        try {
            db()->query('SELECT 1 FROM ellsms_customer_operator_prices LIMIT 0');
            $available = true;
        } catch (PDOException) {
            $available = false;
        }
    }
    return $available;
}

/**
 * The account whose custom prices $userId pays: itself, or its parent organization for a
 * sub-organization. Null when custom prices cannot apply (no hierarchy, or a reseller above it).
 */
function customer_operator_price_owner(int $userId): ?int {
    if ($userId <= 0 || !customer_operator_prices_available()) {
        return null;
    }
    if (!customer_hierarchy_available()) {
        return $userId;
    }
    foreach (customer_ancestor_ids($userId) as $ancestorId) {
        if (customer_account($ancestorId)['customer_type'] === 'reseller') {
            return null;
        }
    }
    $account = customer_account($userId);
    if ($account['customer_type'] === 'organ' && $account['parent_user_id'] !== null) {
        $parent = customer_account((int)$account['parent_user_id']);
        if ($parent['customer_type'] === 'organ') {
            return (int)$parent['user_id'];
        }
    }
    return $userId;
}

/** Whether an admin may give $userId its own prices (it is not under a reseller, nor a sub-organization). */
function customer_operator_prices_eligible(int $userId): bool {
    return customer_operator_price_owner($userId) === $userId;
}

/**
 * The prices $userId pays at $atUtc, keyed by operator id (0 = every other operator). Empty when
 * it has none or they cannot apply. One query per pricing pass.
 *
 * @return array<int, array{id:int, millicredits:int}>
 */
function customer_operator_prices_at(int $userId, string $atUtc): array {
    $ownerId = customer_operator_price_owner($userId);
    if ($ownerId === null) {
        return [];
    }
    try {
        $st = db()->prepare('SELECT id, operator_slot, price_per_segment_millicredits FROM ellsms_customer_operator_prices
                             WHERE user_id = ? AND effective_from <= ? AND (effective_to IS NULL OR effective_to > ?)
                             ORDER BY effective_from, id');
        $st->execute([$ownerId, $atUtc, $atUtc]);
    } catch (PDOException $e) {
        Logger::warning('customer.operator_prices_lookup_failed', ['user_id' => $userId, 'exception' => $e]);
        return [];
    }
    $out = [];
    foreach ($st as $row) {
        // Later periods win if two ever overlap; the writer below never creates an overlap.
        $out[(int)$row['operator_slot']] = ['id' => (int)$row['id'], 'millicredits' => (int)$row['price_per_segment_millicredits']];
    }
    return $out;
}

/**
 * The price row for one recipient: its operator's own price, else the "every other operator" one.
 *
 * @param array<int, array{id:int, millicredits:int}> $prices
 */
function customer_operator_price_for(array $prices, ?int $operatorId): ?array {
    if ($prices === []) {
        return null;
    }
    return ($operatorId !== null ? ($prices[$operatorId] ?? null) : null) ?? ($prices[0] ?? null);
}

/** Current prices of $userId for the admin page: list of {operator_id, operator_name, millicredits, since, note}. */
function customer_operator_prices_current(int $userId): array {
    if (!customer_operator_prices_available()) {
        return [];
    }
    $st = db()->prepare('SELECT p.operator_id, o.name AS operator_name, p.price_per_segment_millicredits, p.effective_from, p.note
                         FROM ellsms_customer_operator_prices p
                         LEFT JOIN ellsms_sms_operators o ON o.id = p.operator_id
                         WHERE p.user_id = ? AND p.effective_to IS NULL
                         ORDER BY p.operator_id IS NULL, o.priority, o.code');
    $st->execute([$userId]);
    return array_map(static fn(array $r): array => [
        'operator_id'   => $r['operator_id'] === null ? null : (int)$r['operator_id'],
        'operator_name' => $r['operator_id'] === null ? 'سایر اپراتورها و شماره‌های ناشناخته' : (string)$r['operator_name'],
        'millicredits'  => (int)$r['price_per_segment_millicredits'],
        'since'         => (string)$r['effective_from'],
        'note'          => (string)$r['note'],
    ], $st->fetchAll());
}

/**
 * Sets (or with $millicredits = null removes) $userId's price for one operator from now on. The
 * period in effect is closed at the same instant the new one starts, so there is never a gap or an
 * overlap. Returns ['ok' => bool, 'reason' => ?string].
 */
function customer_operator_price_set(int $userId, ?int $operatorId, ?int $millicredits, int $actorId, string $note = ''): array {
    if (!customer_operator_prices_available()) {
        return ['ok' => false, 'reason' => 'not_migrated'];
    }
    if (!customer_operator_prices_eligible($userId)) {
        return ['ok' => false, 'reason' => 'not_eligible'];
    }
    if ($millicredits !== null && $millicredits <= 0) {
        return ['ok' => false, 'reason' => 'invalid_price'];
    }
    if ($operatorId !== null) {
        $st = db()->prepare("SELECT COUNT(*) FROM ellsms_sms_operators WHERE id = ? AND status = 'active'");
        $st->execute([$operatorId]);
        if ((int)$st->fetchColumn() === 0) {
            return ['ok' => false, 'reason' => 'invalid_operator'];
        }
    }
    $slot = $operatorId ?? 0;

    $result = db_transaction(function (PDO $db) use ($userId, $operatorId, $slot, $millicredits, $actorId, $note): array {
        $current = $db->prepare('SELECT id, effective_from, price_per_segment_millicredits FROM ellsms_customer_operator_prices
                                 WHERE user_id = ? AND operator_slot = ? AND effective_to IS NULL FOR UPDATE');
        $current->execute([$userId, $slot]);
        $open = $current->fetchAll();
        if ($millicredits === null && $open === []) {
            return ['ok' => true, 'reason' => null, 'changed' => false];
        }
        if ($millicredits !== null && count($open) === 1 && (int)$open[0]['price_per_segment_millicredits'] === $millicredits) {
            return ['ok' => true, 'reason' => null, 'changed' => false];
        }

        // effective_from has one-second granularity and is part of the unique key: an admin
        // correcting a price within the same second gets the next free second instead.
        $now = gmdate('Y-m-d H:i:s');
        $latest = $db->prepare('SELECT MAX(effective_from) FROM ellsms_customer_operator_prices WHERE user_id = ? AND operator_slot = ?');
        $latest->execute([$userId, $slot]);
        $latestFrom = $latest->fetchColumn();
        if ($latestFrom !== null && $latestFrom !== false && (string)$latestFrom >= $now) {
            $now = gmdate('Y-m-d H:i:s', strtotime((string)$latestFrom . ' UTC') + 1);
        }
        $db->prepare('UPDATE ellsms_customer_operator_prices SET effective_to = ? WHERE user_id = ? AND operator_slot = ? AND effective_to IS NULL')
           ->execute([$now, $userId, $slot]);
        if ($millicredits !== null) {
            $db->prepare('INSERT INTO ellsms_customer_operator_prices
                          (user_id, operator_id, operator_slot, price_per_segment_millicredits, effective_from, note, created_by)
                          VALUES (?,?,?,?,?,?,?)')
               ->execute([$userId, $operatorId, $slot, $millicredits, $now, mb_substr(trim($note), 0, 255), $actorId]);
        }
        return ['ok' => true, 'reason' => null, 'changed' => true];
    });

    if ($result['changed']) {
        sms_pricing_cache_reset();
        audit($actorId, $millicredits === null ? 'customer.operator_price_removed' : 'customer.operator_price_set',
            "#{$userId} operator=" . ($operatorId ?? 'other') . ($millicredits === null ? '' : ' price=' . sms_pricing_millicredits_to_credits($millicredits) . 'rial'));
    }
    return ['ok' => $result['ok'], 'reason' => $result['reason']];
}

function customer_operator_price_error_message(string $reason): string {
    return [
        'not_migrated'     => 'جدول تعرفه‌ی اختصاصی هنوز ساخته نشده است (مهاجرت 2026_10_13_customer_operator_prices.sql).',
        'not_eligible'     => 'این حساب زیرمجموعه‌ی نماینده یا زیرسازمان است و تعرفه‌اش از والدش می‌آید؛ تعرفه‌ی اختصاصی برایش تعریف نمی‌شود.',
        'invalid_price'    => 'قیمت باید مبلغی بزرگ‌تر از صفر به ریال باشد (حداکثر سه رقم اعشار).',
        'invalid_operator' => 'اپراتور انتخاب‌شده معتبر یا فعال نیست.',
    ][$reason] ?? 'ثبت تعرفه‌ی اختصاصی انجام نشد.';
}
