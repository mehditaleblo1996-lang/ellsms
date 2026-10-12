<?php
/**
 * ELLSMS — SMS price matrix: sending gateway x destination operator x encoding
 * (docs/gateway-price-matrix.md).
 *
 * Each cell is the Rial price of one segment sent through a gateway (عامل ارسال) to a destination
 * column — an operator, 'other' (an Iranian number of unknown operator) or 'intl' (a number outside
 * Iran) — for Persian ('fa') or Latin ('en') text. A platform admin fills in a global matrix and,
 * per account, an own matrix. For one message sms_pricing_price_messages() takes:
 *
 *   1. the account's own cell — the exact price, no tariff percentage;
 *   2. else the global cell — a list price, times the account's tariff percentage;
 *   3. else the route price, as before.
 *
 * The gateway is the one that will carry the message: the gateway pinned to the sending number,
 * else the gateway of the route chosen for that recipient, else the default gateway.
 *
 * Account prices follow the same ownership rule as the tariff percentage: a sub-organization uses
 * its parent organization's, and an account under a reseller has none (its price is the reseller's
 * tariff, from which the reseller's profit is computed).
 *
 * Cells are effective-dated like route prices, so a send priced at an earlier instant (an import
 * queued after analysis, a retried send) gets the price that was in effect then.
 */

declare(strict_types=1);

const GATEWAY_PRICE_ENCODINGS = ['fa' => 'فارسی', 'en' => 'لاتین'];

/** Whether 2026_10_14_gateway_price_matrix.sql has been applied. Cached per process. */
function gateway_prices_available(?bool $set = null): bool {
    static $available = null;
    if ($set !== null) {
        return $available = $set;
    }
    if ($available === null) {
        try {
            db()->query('SELECT 1 FROM ellsms_gateway_prices LIMIT 0');
            $available = true;
        } catch (PDOException) {
            $available = false;
        }
    }
    return $available;
}

/** 'fa' when the text needs UCS-2 (any non-ASCII character), else 'en' — the same rule as sms_parts(). */
function sms_encoding(string $content): string {
    return preg_match('/[^\x20-\x7E\r\n]/u', $content) ? 'fa' : 'en';
}

/** The matrix column a recipient falls in: its operator id, 'other' or 'intl'. */
function gateway_price_column_for(string $normalizedPhone, ?int $operatorId): string {
    if (!str_starts_with($normalizedPhone, '98')) {
        return 'intl';
    }
    return $operatorId !== null ? (string)$operatorId : 'other';
}

/** The gateway that will carry a message from $sender to $phone (see this file's header), or 0. */
function sms_pricing_gateway_for(string $sender, string $messageType, string $phone): int {
    try {
        $pinned = gateway_number_pinned_id($sender);
        if ($pinned > 0) {
            return $pinned;
        }
        $route = sms_pricing_route_for_sender($sender, $messageType, $phone);
        $gatewayId = (int)($route['gateway_id'] ?? 0);
        return $gatewayId > 0 ? $gatewayId : gateway_default_id();
    } catch (Throwable) {
        return 0;
    }
}

/**
 * The account whose own prices $userId pays: itself, or its parent organization for a
 * sub-organization. Null when it can have none (a reseller above it) or the matrix is not installed.
 */
function customer_price_owner(int $userId): ?int {
    if ($userId <= 0 || !gateway_prices_available()) {
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

/** Whether an admin may give $userId its own prices (not under a reseller, not a sub-organization). */
function customer_prices_eligible(int $userId): bool {
    return customer_price_owner($userId) === $userId;
}

/** "gateway|column|encoding" — the key of one cell. */
function gateway_price_key(int $gatewayId, string $column, string $encoding): string {
    return $gatewayId . '|' . $column . '|' . $encoding;
}

/**
 * Cells in effect at $atUtc, keyed by gateway_price_key(). $userId null = the global matrix.
 *
 * @return array<string, array{id:int, millicredits:int}>
 */
function gateway_prices_at(?int $userId, string $atUtc): array {
    if (!gateway_prices_available()) {
        return [];
    }
    try {
        $st = db()->prepare('SELECT id, gateway_id, column_key, encoding, price_per_segment_millicredits FROM ellsms_gateway_prices
                             WHERE user_slot = ? AND effective_from <= ? AND (effective_to IS NULL OR effective_to > ?)
                             ORDER BY effective_from, id');
        $st->execute([$userId ?? 0, $atUtc, $atUtc]);
    } catch (PDOException $e) {
        Logger::warning('gateway_prices.lookup_failed', ['user_id' => $userId, 'exception' => $e]);
        return [];
    }
    $out = [];
    foreach ($st as $row) {
        $out[gateway_price_key((int)$row['gateway_id'], (string)$row['column_key'], (string)$row['encoding'])] =
            ['id' => (int)$row['id'], 'millicredits' => (int)$row['price_per_segment_millicredits']];
    }
    return $out;
}

/** Current cells (millicredits) keyed by gateway_price_key(), for the admin forms. */
function gateway_prices_current(?int $userId): array {
    return array_map(static fn(array $c): int => $c['millicredits'], gateway_prices_at($userId, gmdate('Y-m-d H:i:s')));
}

/** Rows of the matrix: active gateways. */
function gateway_price_matrix_gateways(): array {
    try {
        return db()->query("SELECT id, code, name FROM ellsms_sms_gateways WHERE status = 'active' ORDER BY is_default DESC, name, id")->fetchAll();
    } catch (PDOException) {
        return [];
    }
}

/** Columns of the matrix: active operators, then 'other' and 'intl'. Each {key, label, code}. */
function gateway_price_matrix_columns(): array {
    $columns = [];
    try {
        foreach (db()->query("SELECT id, code, name FROM ellsms_sms_operators WHERE status = 'active' ORDER BY priority, code") as $o) {
            $columns[] = ['key' => (string)$o['id'], 'label' => (string)$o['name'], 'code' => (string)$o['code']];
        }
    } catch (PDOException) {
    }
    $columns[] = ['key' => 'other', 'label' => 'سایر', 'code' => null];
    $columns[] = ['key' => 'intl', 'label' => 'بین‌الملل', 'code' => null];
    return $columns;
}

/**
 * Saves a whole matrix from now on. $cells maps gateway_price_key() => millicredits, or null for an
 * empty cell; cells not listed are left as they are. Only cells whose value changed get a new period.
 * Returns ['ok' => bool, 'reason' => ?string, 'changed' => int].
 */
function gateway_prices_save(?int $userId, array $cells, int $actorId): array {
    if (!gateway_prices_available()) {
        return ['ok' => false, 'reason' => 'not_migrated', 'changed' => 0];
    }
    if ($userId !== null && !customer_prices_eligible($userId)) {
        return ['ok' => false, 'reason' => 'not_eligible', 'changed' => 0];
    }
    $gateways = array_flip(array_map(static fn(array $g): int => (int)$g['id'], gateway_price_matrix_gateways()));
    $columns = array_flip(array_column(gateway_price_matrix_columns(), 'key'));
    foreach ($cells as $key => $millis) {
        [$gatewayId, $column, $encoding] = array_pad(explode('|', (string)$key), 3, '');
        if (!isset($gateways[(int)$gatewayId], $columns[$column]) || !array_key_exists($encoding, GATEWAY_PRICE_ENCODINGS)) {
            return ['ok' => false, 'reason' => 'invalid_cell', 'changed' => 0];
        }
        if ($millis !== null && (!is_int($millis) || $millis <= 0)) {
            return ['ok' => false, 'reason' => 'invalid_price', 'changed' => 0];
        }
    }

    $slot = $userId ?? 0;
    $changed = db_transaction(function (PDO $db) use ($userId, $slot, $cells, $actorId): int {
        $open = $db->prepare('SELECT gateway_id, column_key, encoding, price_per_segment_millicredits FROM ellsms_gateway_prices
                              WHERE user_slot = ? AND effective_to IS NULL FOR UPDATE');
        $open->execute([$slot]);
        $current = [];
        foreach ($open as $row) {
            $current[gateway_price_key((int)$row['gateway_id'], (string)$row['column_key'], (string)$row['encoding'])] = (int)$row['price_per_segment_millicredits'];
        }

        // effective_from has one-second granularity and is part of the unique key: a second save in
        // the same second starts one second after the latest period instead of colliding with it.
        $now = gmdate('Y-m-d H:i:s');
        $latest = $db->prepare('SELECT MAX(effective_from) FROM ellsms_gateway_prices WHERE user_slot = ?');
        $latest->execute([$slot]);
        $latestFrom = $latest->fetchColumn();
        if ($latestFrom && (string)$latestFrom >= $now) {
            $now = gmdate('Y-m-d H:i:s', strtotime((string)$latestFrom . ' UTC') + 1);
        }

        $close = $db->prepare('UPDATE ellsms_gateway_prices SET effective_to = ?
                               WHERE user_slot = ? AND gateway_id = ? AND column_key = ? AND encoding = ? AND effective_to IS NULL');
        $insert = $db->prepare('INSERT INTO ellsms_gateway_prices
            (user_id, user_slot, gateway_id, column_key, encoding, price_per_segment_millicredits, effective_from, created_by)
            VALUES (?,?,?,?,?,?,?,?)');
        $changed = 0;
        foreach ($cells as $key => $millis) {
            if (($current[$key] ?? null) === $millis) {
                continue;
            }
            [$gatewayId, $column, $encoding] = explode('|', (string)$key);
            $close->execute([$now, $slot, (int)$gatewayId, $column, $encoding]);
            if ($millis !== null) {
                $insert->execute([$userId, $slot, (int)$gatewayId, $column, $encoding, $millis, $now, $actorId]);
            }
            $changed++;
        }
        return $changed;
    });

    if ($changed > 0) {
        sms_pricing_cache_reset();
        audit($actorId, 'gateway_prices.saved', ($userId === null ? 'global' : "#{$userId}") . " cells={$changed}");
    }
    return ['ok' => true, 'reason' => null, 'changed' => $changed];
}

/**
 * Parses the matrix form (price[gateway][column][encoding] = "1,124") into gateway_prices_save()
 * cells. Returns [cells, null] or [[], 'invalid_price'] when any value is not a positive Rial amount.
 */
function gateway_prices_from_form(array $posted): array {
    $cells = [];
    foreach ($posted as $gatewayId => $columns) {
        foreach ((array)$columns as $column => $encodings) {
            foreach ((array)$encodings as $encoding => $raw) {
                $raw = trim((string)$raw);
                $millis = $raw === '' ? null : sms_pricing_credits_to_millicredits($raw);
                if ($raw !== '' && ($millis === null || $millis <= 0)) {
                    return [[], 'invalid_price'];
                }
                $cells[gateway_price_key((int)$gatewayId, (string)$column, (string)$encoding)] = $millis;
            }
        }
    }
    return [$cells, null];
}

function gateway_price_error_message(string $reason): string {
    return [
        'not_migrated'  => 'جدول تعرفه‌ی درگاه‌ها هنوز ساخته نشده است (مهاجرت 2026_10_14_gateway_price_matrix.sql).',
        'not_eligible'  => 'این حساب زیرمجموعه‌ی نماینده یا زیرسازمان است و تعرفه‌اش از والدش می‌آید؛ تعرفه‌ی اختصاصی برایش تعریف نمی‌شود.',
        'invalid_price' => 'هر قیمت باید مبلغی بزرگ‌تر از صفر به ریال باشد (حداکثر سه رقم اعشار).',
        'invalid_cell'  => 'درگاه یا اپراتور یکی از خانه‌ها دیگر فعال نیست؛ صفحه را دوباره باز کنید.',
    ][$reason] ?? 'ذخیره‌ی تعرفه انجام نشد.';
}
