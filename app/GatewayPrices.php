<?php
/**
 * ELLSMS — SMS price matrix: sending gateway x destination operator x encoding
 * (docs/gateway-price-matrix.md).
 *
 * Each cell is the Rial price of one segment sent through a gateway (عامل ارسال) to a destination
 * column — an operator, 'other' (an Iranian number of unknown operator) or 'intl' (a number outside
 * Iran) — for Persian ('fa') or Latin ('en') text. A platform admin fills in a global matrix and,
 * per account, an own matrix. Each matrix also has a "default for every gateway" row
 * (GATEWAY_PRICE_ALL) that covers every gateway without a price of its own in that cell. For one
 * message sms_pricing_price_messages() takes:
 *
 *   1. the account's cell for that gateway, else the account's default row — exact, no percentage;
 *   2. else the global cell for that gateway, else the global default row — a list price, times
 *      the account's tariff percentage;
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

/** The gateway id of the "default for every gateway" row (stored as gateway_id NULL, gateway_slot 0). */
const GATEWAY_PRICE_ALL = 0;

/**
 * The shared sending service's two price rows (app/SharedService.php, docs/shared-service.md): virtual
 * "gateways" stored as gateway_id NULL with these slots, far above any real gateway id. A send of the
 * shared service is priced from its row (account's, else global × tariff percentage) whatever line and
 * provider it actually leaves from; an empty row falls back to that line's normal price.
 */
const GATEWAY_PRICE_SHARED_SERVICE = 4294967001;
const GATEWAY_PRICE_SHARED_PROMO = 4294967002;
const GATEWAY_PRICE_SHARED_ROWS = [
    GATEWAY_PRICE_SHARED_SERVICE => 'سرویس اشتراکی خدماتی',
    GATEWAY_PRICE_SHARED_PROMO   => 'سرویس اشتراکی تبلیغاتی',
];

/** Whether $gatewayId is a row with no real gateway behind it (stored with gateway_id NULL). */
function gateway_price_is_virtual_row(int $gatewayId): bool {
    return $gatewayId === GATEWAY_PRICE_ALL || isset(GATEWAY_PRICE_SHARED_ROWS[$gatewayId]);
}

/**
 * Runs $fn with every price pass inside it reading the shared-service row $slot first
 * (sms_pricing_price_messages()). The override lives only inside this call.
 */
function gateway_price_with_row(int $slot, callable $fn): mixed {
    $previous = $GLOBALS['__ellsms_gateway_price_row'] ?? null;
    $GLOBALS['__ellsms_gateway_price_row'] = $slot;
    try {
        return $fn();
    } finally {
        $GLOBALS['__ellsms_gateway_price_row'] = $previous;
    }
}

/** The shared-service price row gateway_price_with_row() is granting, or null. */
function gateway_price_row_override(): ?int {
    $slot = $GLOBALS['__ellsms_gateway_price_row'] ?? null;
    return is_int($slot) && isset(GATEWAY_PRICE_SHARED_ROWS[$slot]) ? $slot : null;
}

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
        $st = db()->prepare('SELECT id, gateway_slot, column_key, encoding, price_per_segment_millicredits FROM ellsms_gateway_prices
                             WHERE user_slot = ? AND effective_from <= ? AND (effective_to IS NULL OR effective_to > ?)
                             ORDER BY effective_from, id');
        $st->execute([$userId ?? 0, $atUtc, $atUtc]);
    } catch (PDOException $e) {
        Logger::warning('gateway_prices.lookup_failed', ['user_id' => $userId, 'exception' => $e]);
        return [];
    }
    $out = [];
    foreach ($st as $row) {
        $out[gateway_price_key((int)$row['gateway_slot'], (string)$row['column_key'], (string)$row['encoding'])] =
            ['id' => (int)$row['id'], 'millicredits' => (int)$row['price_per_segment_millicredits']];
    }
    return $out;
}

/**
 * The latest saved cells (millicredits) keyed by gateway_price_key(), for the admin forms: every
 * open period, including one a save in the same second scheduled to start a second from now.
 */
function gateway_prices_current(?int $userId): array {
    if (!gateway_prices_available()) {
        return [];
    }
    $st = db()->prepare('SELECT gateway_slot, column_key, encoding, price_per_segment_millicredits FROM ellsms_gateway_prices
                         WHERE user_slot = ? AND effective_to IS NULL');
    $st->execute([$userId ?? 0]);
    $out = [];
    foreach ($st as $row) {
        $out[gateway_price_key((int)$row['gateway_slot'], (string)$row['column_key'], (string)$row['encoding'])] = (int)$row['price_per_segment_millicredits'];
    }
    return $out;
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
 * What each cell costs when it is left empty, keyed by gateway_price_key() (millicredits), for the
 * forms to show in grey in place of an empty cell. The "every gateway" row is GATEWAY_PRICE_ALL.
 *
 *  - global matrix ($userId null): a gateway cell defaults to the global default row, which itself
 *    defaults to the route price (the default route's price for that operator, else the fallback);
 *  - an account's matrix: a gateway cell defaults to the account's default row; failing that, and
 *    for the default row itself, to what the global matrix gives that cell times the account's
 *    tariff percentage — exactly what the account pays when the cell is left empty.
 *
 * The route price is per operator, not per gateway or encoding. A send routed by sender or operator
 * assignment can land on another route's price; this is the default the admin sees, not a guarantee
 * for every sender.
 */
function gateway_price_defaults(?int $userId): array {
    $now = gmdate('Y-m-d H:i:s');
    $routePrices = [];
    try {
        $route = sms_pricing_route_for_sender('', sms_pricing_default_message_type(), null);
        $prices = $route !== null ? sms_pricing_prices_for_route((int)$route['route_id']) : [];
    } catch (Throwable) {
        $prices = [];
    }
    $fallback = sms_pricing_legacy_fallback_enabled() ? sms_pricing_legacy_unit_price() : null;
    foreach (gateway_price_matrix_columns() as $column) {
        $operatorId = ctype_digit($column['key']) ? (int)$column['key'] : null;
        $price = $prices !== [] ? sms_pricing_select_price($prices, $operatorId, $now) : null;
        $routePrices[$column['key']] = $price !== null ? (int)$price['price_per_segment_millicredits'] : $fallback;
    }

    $global = gateway_prices_current(null);
    $own = $userId !== null ? gateway_prices_current($userId) : [];
    $tariffBp = $userId !== null ? customer_effective_tariff_bp($userId) : CUSTOMER_TARIFF_BASE_BP;
    $rows = array_merge([GATEWAY_PRICE_ALL], array_map(static fn(array $g): int => (int)$g['id'], gateway_price_matrix_gateways()));
    $defaults = [];
    foreach ($rows as $gatewayId) {
        foreach ($routePrices as $columnKey => $routePrice) {
            foreach (array_keys(GATEWAY_PRICE_ENCODINGS) as $encoding) {
                $key = gateway_price_key($gatewayId, (string)$columnKey, $encoding);
                $allKey = gateway_price_key(GATEWAY_PRICE_ALL, (string)$columnKey, $encoding);
                if ($userId === null) {
                    $default = $gatewayId === GATEWAY_PRICE_ALL ? $routePrice : ($global[$allKey] ?? $routePrice);
                } else {
                    $list = $gatewayId === GATEWAY_PRICE_ALL
                        ? ($global[$allKey] ?? $routePrice)
                        : ($global[$key] ?? $global[$allKey] ?? $routePrice);
                    $listed = $list !== null ? customer_tariff_apply($list, $tariffBp) : null;
                    $default = $gatewayId === GATEWAY_PRICE_ALL ? $listed : ($own[$allKey] ?? $listed);
                }
                if ($default !== null) {
                    $defaults[$key] = $default;
                }
            }
        }
    }
    // The shared-service rows: empty on the global matrix means "the sending line's own price", which
    // has no single value to show; on an account's matrix, the global row × the tariff percentage.
    if ($userId !== null) {
        foreach (array_keys(GATEWAY_PRICE_SHARED_ROWS) as $slot) {
            foreach (array_keys($routePrices) as $columnKey) {
                foreach (array_keys(GATEWAY_PRICE_ENCODINGS) as $encoding) {
                    $key = gateway_price_key($slot, (string)$columnKey, $encoding);
                    if (isset($global[$key])) {
                        $defaults[$key] = customer_tariff_apply($global[$key], $tariffBp);
                    }
                }
            }
        }
    }
    return $defaults;
}

/**
 * The forms show each empty cell filled with its default (in grey). A submitted value equal to the
 * default, in a cell that has no own price yet, is therefore "left as default", not a new price:
 * saving it would pin today's default and stop the cell following later changes to the route or
 * global price, or to the tariff percentage.
 */
function gateway_prices_drop_defaults(array $cells, array $current, array $defaults): array {
    foreach ($cells as $key => $millis) {
        if ($millis !== null && !isset($current[$key]) && ($defaults[$key] ?? null) === $millis) {
            $cells[$key] = null;
        }
    }
    return $cells;
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
    $gateways[GATEWAY_PRICE_ALL] = true;
    foreach (array_keys(GATEWAY_PRICE_SHARED_ROWS) as $sharedRow) {
        $gateways[$sharedRow] = true;
    }
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
        $open = $db->prepare('SELECT gateway_slot, column_key, encoding, price_per_segment_millicredits FROM ellsms_gateway_prices
                              WHERE user_slot = ? AND effective_to IS NULL FOR UPDATE');
        $open->execute([$slot]);
        $current = [];
        foreach ($open as $row) {
            $current[gateway_price_key((int)$row['gateway_slot'], (string)$row['column_key'], (string)$row['encoding'])] = (int)$row['price_per_segment_millicredits'];
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
                               WHERE user_slot = ? AND gateway_slot = ? AND column_key = ? AND encoding = ? AND effective_to IS NULL');
        $insert = $db->prepare('INSERT INTO ellsms_gateway_prices
            (user_id, user_slot, gateway_id, gateway_slot, column_key, encoding, price_per_segment_millicredits, effective_from, created_by)
            VALUES (?,?,?,?,?,?,?,?,?)');
        $changed = 0;
        foreach ($cells as $key => $millis) {
            if (($current[$key] ?? null) === $millis) {
                continue;
            }
            [$gatewayId, $column, $encoding] = explode('|', (string)$key);
            $close->execute([$now, $slot, (int)$gatewayId, $column, $encoding]);
            if ($millis !== null) {
                $insert->execute([$userId, $slot, gateway_price_is_virtual_row((int)$gatewayId) ? null : (int)$gatewayId, (int)$gatewayId, $column, $encoding, $millis, $now, $actorId]);
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
