<?php
/**
 * ELLSMS — named tariffs (تعرفه‌های نام‌دار) a reseller defines for its customers, modeled on the
 * Negar platform's Tariff + Price: one Rial price per segment for each destination column (an
 * operator, 'other' = an Iranian number of unknown operator, 'intl') and encoding (fa / en), plus an
 * 'all' column that covers every column without a price of its own.
 *
 * A reseller gives each direct customer one of its tariffs (ellsms_customer_accounts.tariff_id).
 * How a customer under a reseller is priced (sms_pricing_price_messages() through
 * customer_chain_prices()), from the top of its chain down:
 *
 *   - the top account pays its own exact price (the admin's per-account matrix) for that cell, else
 *     the list price times its tariff percentage, exactly as before;
 *   - each account below pays its named tariff's cell, else the list price times its own tariff
 *     percentage; a sub-organization pays its parent organization's price;
 *   - never less than the reseller directly above it pays for the same message. A tariff cell is
 *     checked against the reseller's own price when it is saved, and the floor is applied again at
 *     send time, because the reseller's own price can depend on the gateway that carries the message.
 *
 * Every account's price is written into the send's price snapshot (chain_prices), and the daily
 * profit run pays each reseller the difference between what the account below it paid and what it
 * pays itself (customer_profit_run_day()).
 *
 * Schema: db/migrations/2026_10_24_reseller_panel.sql. Until it is applied no account has a named
 * tariff and pricing is unchanged.
 */

declare(strict_types=1);

/** The pseudo-column of a tariff's "every other destination" row. */
const RESELLER_TARIFF_ALL = 'all';

/** Whether 2026_10_24_reseller_panel.sql has been applied. Cached per process. */
function reseller_panel_available(?bool $set = null): bool {
    static $available = null;
    if ($set !== null) {
        return $available = $set;
    }
    if ($available === null) {
        try {
            db()->query('SELECT 1 FROM ellsms_reseller_tariffs LIMIT 0');
            $available = customer_hierarchy_available();
        } catch (PDOException) {
            $available = false;
        }
    }
    return $available;
}

/** The tariff's columns in display order: 'all' first, then the price matrix's columns. Each {key, label}. */
function reseller_tariff_columns(): array {
    $columns = [['key' => RESELLER_TARIFF_ALL, 'label' => 'همه‌ی مقصدها (پیش‌فرض)', 'code' => null]];
    foreach (gateway_price_matrix_columns() as $column) {
        $columns[] = $column;
    }
    return $columns;
}

/** "column|encoding" — the key of one tariff cell. */
function reseller_tariff_cell_key(string $column, string $encoding): string {
    return $column . '|' . $encoding;
}

/* ==========================================================================
   Reading tariffs
   ========================================================================== */

/** @return list<array> the owner's live tariffs, default first */
function reseller_tariffs_for(int $ownerId): array {
    if ($ownerId <= 0 || !reseller_panel_available()) return [];
    $st = db()->prepare('SELECT * FROM ellsms_reseller_tariffs WHERE owner_user_id = ? AND deleted = 0 ORDER BY is_default DESC, title, id');
    $st->execute([$ownerId]);
    return array_map('reseller_tariff_normalize', $st->fetchAll());
}

function reseller_tariff_normalize(array $row): array {
    return [
        'id'            => (int)$row['id'],
        'owner_user_id' => (int)$row['owner_user_id'],
        'title'         => (string)$row['title'],
        'description'   => (string)$row['description'],
        'is_default'    => (int)$row['is_default'] === 1,
        'deleted'       => (int)$row['deleted'] === 1,
        'updated_at'    => (string)($row['updated_at'] ?? ''),
    ];
}

function reseller_tariff_get(int $tariffId): ?array {
    if ($tariffId <= 0 || !reseller_panel_available()) return null;
    $st = db()->prepare('SELECT * FROM ellsms_reseller_tariffs WHERE id = ?');
    $st->execute([$tariffId]);
    $row = $st->fetch();
    return $row ? reseller_tariff_normalize($row) : null;
}

/** A live tariff owned by $ownerId, or null. */
function reseller_tariff_owned(int $ownerId, int $tariffId): ?array {
    $tariff = reseller_tariff_get($tariffId);
    return $tariff !== null && $tariff['owner_user_id'] === $ownerId && !$tariff['deleted'] ? $tariff : null;
}

/**
 * A tariff's cells in millicredits, keyed by reseller_tariff_cell_key(). Cached per process for the
 * pricing hot path; reseller_tariff_cache_reset() after a save.
 *
 * @return array<string,int>
 */
function reseller_tariff_cells(int $tariffId): array {
    $cache = &reseller_tariff_cache();
    if (array_key_exists($tariffId, $cache)) return $cache[$tariffId];
    $cells = [];
    if ($tariffId > 0 && reseller_panel_available()) {
        try {
            $st = db()->prepare('SELECT p.column_key, p.encoding, p.price_per_segment_millicredits
                                 FROM ellsms_reseller_tariff_prices p JOIN ellsms_reseller_tariffs t ON t.id = p.tariff_id
                                 WHERE p.tariff_id = ? AND t.deleted = 0');
            $st->execute([$tariffId]);
            foreach ($st as $row) {
                $cells[reseller_tariff_cell_key((string)$row['column_key'], (string)$row['encoding'])] = (int)$row['price_per_segment_millicredits'];
            }
        } catch (PDOException $e) {
            Logger::warning('reseller_tariff.lookup_failed', ['tariff_id' => $tariffId, 'exception' => $e]);
        }
    }
    if (count($cache) > 200) $cache = [];
    return $cache[$tariffId] = $cells;
}

function &reseller_tariff_cache(): array {
    static $cache = [];
    return $cache;
}

function reseller_tariff_cache_reset(): void {
    $cache = &reseller_tariff_cache();
    $cache = [];
}

/** Pure: the tariff's price for a message in $column / $encoding, its 'all' row when it has none for that column. */
function reseller_tariff_cell_price(array $cells, string $column, string $encoding): ?int {
    return $cells[reseller_tariff_cell_key($column, $encoding)] ?? $cells[reseller_tariff_cell_key(RESELLER_TARIFF_ALL, $encoding)] ?? null;
}

/** Customers ($ownerId's direct children) currently on each of its tariffs: tariff id => count. */
function reseller_tariff_usage(int $ownerId): array {
    if (!reseller_panel_available()) return [];
    $st = db()->prepare('SELECT tariff_id, COUNT(*) c FROM ellsms_customer_accounts WHERE parent_user_id = ? AND tariff_id IS NOT NULL GROUP BY tariff_id');
    $st->execute([$ownerId]);
    $out = [];
    foreach ($st as $row) $out[(int)$row['tariff_id']] = (int)$row['c'];
    return $out;
}

/* ==========================================================================
   Pricing up the chain
   ========================================================================== */

/**
 * The accounts that price a send by $userId: the account first, then each ancestor, each as
 * {user_id, type, bp, tariff_id, sub_organ}. Null when the account has no parent — it is priced
 * exactly as before, by its own cells and tariff percentage.
 */
function customer_pricing_chain(int $userId): ?array {
    if ($userId <= 0 || !customer_hierarchy_available()) return null;
    try {
        $account = customer_account($userId);
        if ($account['parent_user_id'] === null) return null;
        $chain = [];
        $current = $account;
        $seen = [];
        while (count($chain) <= CUSTOMER_MAX_DEPTH && !isset($seen[$current['user_id']])) {
            $seen[$current['user_id']] = true;
            $parent = $current['parent_user_id'] !== null ? customer_account((int)$current['parent_user_id']) : null;
            $chain[] = [
                'user_id'   => (int)$current['user_id'],
                'type'      => $current['customer_type'],
                'bp'        => $current['tariff_bp'],
                'tariff_id' => $current['tariff_id'] ?? null,
                'sub_organ' => $current['customer_type'] === 'organ' && $parent !== null && $parent['customer_type'] === 'organ',
            ];
            if ($parent === null || !$parent['exists']) break;
            $current = $parent;
        }
        return $chain;
    } catch (PDOException $e) {
        Logger::warning('customer.pricing_chain_failed', ['user_id' => $userId, 'exception' => $e]);
        return null;
    }
}

/**
 * Pure: what each account on $chain pays for one segment of one message, top-down.
 *
 * $listPrice is the list price of that message (the global matrix cell, else the route price), null
 * when there is none. $topCell is the top account's own exact price for that cell (the admin's
 * per-account matrix), null when it has none. $tariffCells maps tariff id => reseller_tariff_cells().
 *
 * @return list<array{user_id:int, type:string, price:?int, source:string}> in $chain's order (spender first)
 */
function customer_chain_prices(array $chain, ?int $listPrice, ?int $topCell, array $tariffCells, string $column, string $encoding): array {
    $n = count($chain);
    $out = array_fill(0, $n, null);
    for ($i = $n - 1; $i >= 0; $i--) {
        $acc = $chain[$i];
        $parent = $i < $n - 1 ? $out[$i + 1] : null;
        $price = null;
        $source = 'list';
        if ($i === $n - 1) {
            if ($topCell !== null) {
                $price = $topCell;
                $source = 'customer_price';
            } elseif ($listPrice !== null) {
                $price = customer_tariff_apply($listPrice, (int)$acc['bp']);
            }
        } elseif ($acc['sub_organ']) {
            $price = $parent['price'];
            $source = $parent['source'];
        } else {
            $cell = $acc['tariff_id'] !== null ? reseller_tariff_cell_price($tariffCells[$acc['tariff_id']] ?? [], $column, $encoding) : null;
            if ($cell !== null) {
                $price = $cell;
                $source = 'reseller_tariff';
            } elseif ($listPrice !== null) {
                $price = customer_tariff_apply($listPrice, (int)$acc['bp']);
            }
            // Never below what the reseller directly above pays for the same message.
            if ($price !== null && $parent['price'] !== null && $chain[$i + 1]['type'] === 'reseller' && $price < $parent['price']) {
                $price = $parent['price'];
                $source = 'reseller_floor';
            }
        }
        $out[$i] = ['user_id' => (int)$acc['user_id'], 'type' => (string)$acc['type'], 'price' => $price, 'source' => $source];
    }
    return $out;
}

/**
 * Per pricing pass: the chain of $userId with everything customer_chain_prices() needs that does not
 * depend on the message. Null when the account has no parent.
 *
 * @return array{chain:list<array>, top_cells:array, tariff_cells:array}|null
 */
function customer_pricing_context(int $userId, string $atUtc): ?array {
    $chain = customer_pricing_chain($userId);
    if ($chain === null) return null;
    $top = $chain[count($chain) - 1];
    $tariffCells = [];
    foreach ($chain as $acc) {
        if ($acc['tariff_id'] !== null && !isset($tariffCells[$acc['tariff_id']])) {
            $tariffCells[$acc['tariff_id']] = reseller_tariff_cells((int)$acc['tariff_id']);
        }
    }
    return [
        'chain'        => $chain,
        'top_cells'    => customer_price_owner((int)$top['user_id']) === (int)$top['user_id'] ? gateway_prices_at((int)$top['user_id'], $atUtc) : [],
        'tariff_cells' => $tariffCells,
    ];
}

/* ==========================================================================
   What the reseller itself pays, for the tariff forms
   ========================================================================== */

/**
 * What $userId pays for one segment in each tariff cell, keyed by reseller_tariff_cell_key() — the
 * floor of a tariff it defines for its customers. Uses the most expensive active gateway for each
 * column, so a tariff that passes this check never sells below the reseller's own cost on any gateway.
 * A cell with no list price at all is left out.
 *
 * @return array<string,int>
 */
function reseller_own_prices(int $userId): array {
    // gateway|column|encoding => list price: the global cell, else what an empty global cell falls back to.
    $listDefaults = gateway_prices_current(null) + gateway_price_defaults(null);
    $chain = customer_pricing_chain($userId);
    $now = gmdate('Y-m-d H:i:s');
    $ownCells = $chain === null && customer_price_owner($userId) === $userId ? gateway_prices_at($userId, $now) : [];
    $context = $chain !== null ? customer_pricing_context($userId, $now) : null;
    $bp = customer_effective_tariff_bp($userId);
    $gatewayIds = array_merge([GATEWAY_PRICE_ALL], array_map(static fn(array $g): int => (int)$g['id'], gateway_price_matrix_gateways()));

    $out = [];
    foreach (gateway_price_matrix_columns() as $column) {
        foreach (array_keys(GATEWAY_PRICE_ENCODINGS) as $encoding) {
            $max = null;
            foreach ($gatewayIds as $gatewayId) {
                $list = $listDefaults[gateway_price_key($gatewayId, $column['key'], $encoding)] ?? null;
                if ($context !== null) {
                    $topCell = $context['top_cells'][gateway_price_key($gatewayId, $column['key'], $encoding)]['millicredits']
                        ?? $context['top_cells'][gateway_price_key(GATEWAY_PRICE_ALL, $column['key'], $encoding)]['millicredits'] ?? null;
                    $price = customer_chain_prices($context['chain'], $list, $topCell, $context['tariff_cells'], $column['key'], $encoding)[0]['price'];
                } else {
                    $cell = $ownCells[gateway_price_key($gatewayId, $column['key'], $encoding)]['millicredits']
                        ?? $ownCells[gateway_price_key(GATEWAY_PRICE_ALL, $column['key'], $encoding)]['millicredits'] ?? null;
                    $price = $cell ?? ($list !== null ? customer_tariff_apply($list, $bp) : null);
                }
                if ($price !== null && ($max === null || $price > $max)) $max = $price;
            }
            if ($max !== null) $out[reseller_tariff_cell_key($column['key'], $encoding)] = $max;
        }
    }
    // The 'all' row's floor is the dearest column: it may stand in for any of them.
    foreach (array_keys(GATEWAY_PRICE_ENCODINGS) as $encoding) {
        $values = array_filter($out, static fn(string $k): bool => str_ends_with($k, '|' . $encoding), ARRAY_FILTER_USE_KEY);
        if ($values) $out[reseller_tariff_cell_key(RESELLER_TARIFF_ALL, $encoding)] = max($values);
    }
    return $out;
}

/* ==========================================================================
   Saving tariffs
   ========================================================================== */

/**
 * Parses the tariff form (price[column][encoding] = "1,250") into cells. Returns [cells, null] or
 * [[], 'invalid_price'].
 *
 * @return array{0:array<string,?int>, 1:?string}
 */
function reseller_tariff_cells_from_form(array $posted): array {
    $valid = array_flip(array_column(reseller_tariff_columns(), 'key'));
    $cells = [];
    foreach ($posted as $column => $encodings) {
        if (!isset($valid[(string)$column])) continue;
        foreach ((array)$encodings as $encoding => $raw) {
            if (!array_key_exists((string)$encoding, GATEWAY_PRICE_ENCODINGS)) continue;
            $raw = trim(from_persian_digits((string)$raw));
            $millis = $raw === '' ? null : sms_pricing_credits_to_millicredits($raw);
            if ($raw !== '' && ($millis === null || $millis <= 0)) {
                return [[], 'invalid_price'];
            }
            $cells[reseller_tariff_cell_key((string)$column, (string)$encoding)] = $millis;
        }
    }
    return [$cells, null];
}

/**
 * Creates or updates one of $ownerId's tariffs. $cells maps reseller_tariff_cell_key() => millicredits
 * or null (empty: falls back to the 'all' row, else the customer's tariff percentage). Every price
 * must be at least what the owner itself pays for that cell (reseller_own_prices()).
 *
 * @return array{ok:bool, reason?:string, id?:int, cell?:string}
 */
function reseller_tariff_save(int $ownerId, ?int $tariffId, string $title, string $description, array $cells, bool $isDefault, int $actorId): array {
    if (!reseller_panel_available()) return ['ok' => false, 'reason' => 'not_migrated'];
    $title = trim(mb_substr($title, 0, 80));
    if ($title === '') return ['ok' => false, 'reason' => 'title_required'];
    if (array_filter($cells, static fn($v): bool => $v !== null) === []) return ['ok' => false, 'reason' => 'no_prices'];
    if ($tariffId !== null && reseller_tariff_owned($ownerId, $tariffId) === null) return ['ok' => false, 'reason' => 'not_found'];

    $floor = reseller_own_prices($ownerId);
    foreach ($cells as $key => $millis) {
        if ($millis !== null && isset($floor[$key]) && $millis < $floor[$key]) {
            return ['ok' => false, 'reason' => 'below_own_price', 'cell' => $key];
        }
    }

    try {
        $id = db_transaction(function (PDO $db) use ($ownerId, $tariffId, $title, $description, $cells, $isDefault, $actorId): int {
            if ($tariffId === null) {
                $db->prepare('INSERT INTO ellsms_reseller_tariffs (owner_user_id, title, description, created_by_user_id) VALUES (?,?,?,?)')
                   ->execute([$ownerId, $title, mb_substr(trim($description), 0, 255), $actorId]);
                $tariffId = (int)$db->lastInsertId();
            } else {
                $db->prepare('UPDATE ellsms_reseller_tariffs SET title = ?, description = ? WHERE id = ? AND owner_user_id = ?')
                   ->execute([$title, mb_substr(trim($description), 0, 255), $tariffId, $ownerId]);
            }
            $db->prepare('DELETE FROM ellsms_reseller_tariff_prices WHERE tariff_id = ?')->execute([$tariffId]);
            $ins = $db->prepare('INSERT INTO ellsms_reseller_tariff_prices (tariff_id, column_key, encoding, price_per_segment_millicredits) VALUES (?,?,?,?)');
            foreach ($cells as $key => $millis) {
                if ($millis === null) continue;
                [$column, $encoding] = explode('|', (string)$key, 2);
                $ins->execute([$tariffId, $column, $encoding, $millis]);
            }
            if ($isDefault) {
                $db->prepare('UPDATE ellsms_reseller_tariffs SET is_default = (id = ?) WHERE owner_user_id = ?')->execute([$tariffId, $ownerId]);
            }
            return $tariffId;
        });
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) === 1062) return ['ok' => false, 'reason' => 'duplicate_title'];
        throw $e;
    }
    reseller_tariff_cache_reset();
    sms_pricing_cache_reset();
    audit($actorId, 'reseller.tariff_saved', "owner=#{$ownerId} tariff=#{$id} {$title}");
    return ['ok' => true, 'id' => $id];
}

/** Soft-deletes a tariff no customer is on any more. */
function reseller_tariff_delete(int $ownerId, int $tariffId, int $actorId): array {
    if (reseller_tariff_owned($ownerId, $tariffId) === null) return ['ok' => false, 'reason' => 'not_found'];
    if ((reseller_tariff_usage($ownerId)[$tariffId] ?? 0) > 0) return ['ok' => false, 'reason' => 'in_use'];
    $st = db()->prepare('SELECT COUNT(*) FROM ellsms_reseller_packages WHERE tariff_id = ? AND deleted = 0');
    $st->execute([$tariffId]);
    if ((int)$st->fetchColumn() > 0) return ['ok' => false, 'reason' => 'in_package'];
    // The title is freed for a new tariff (it is unique per owner).
    db()->prepare("UPDATE ellsms_reseller_tariffs SET deleted = 1, is_default = 0, title = CONCAT(LEFT(title, 60), ' #', id) WHERE id = ? AND owner_user_id = ?")
        ->execute([$tariffId, $ownerId]);
    reseller_tariff_cache_reset();
    audit($actorId, 'reseller.tariff_deleted', "owner=#{$ownerId} tariff=#{$tariffId}");
    return ['ok' => true];
}

/**
 * Puts a direct child on one of the parent's tariffs, or takes it off (null). Re-checks that the
 * tariff belongs to the parent.
 */
function reseller_assign_tariff(int $parentId, int $childId, ?int $tariffId, int $actorId): array {
    if (!reseller_panel_available()) return ['ok' => false, 'reason' => 'not_migrated'];
    if ($tariffId !== null && reseller_tariff_owned($parentId, $tariffId) === null) return ['ok' => false, 'reason' => 'not_found'];
    $child = customer_account($childId);
    if (!$child['exists'] || $child['parent_user_id'] !== $parentId) return ['ok' => false, 'reason' => 'not_your_child'];
    if ($child['customer_type'] === 'organ' && customer_account($parentId)['customer_type'] === 'organ') {
        return ['ok' => false, 'reason' => 'sub_organ_tariff'];
    }
    db()->prepare('UPDATE ellsms_customer_accounts SET tariff_id = ? WHERE user_id = ?')->execute([$tariffId, $childId]);
    sms_pricing_cache_reset();
    audit($actorId, 'reseller.tariff_assigned', "parent=#{$parentId} child=#{$childId} tariff=" . ($tariffId ?? '-'));
    return ['ok' => true];
}

/** The parent's default tariff id, or null. */
function reseller_default_tariff_id(int $ownerId): ?int {
    foreach (reseller_tariffs_for($ownerId) as $tariff) {
        if ($tariff['is_default']) return $tariff['id'];
    }
    return null;
}

function reseller_tariff_error_message(string $reason): string {
    return [
        'not_migrated'     => 'جدول‌های پنل نمایندگی هنوز ساخته نشده‌اند (مهاجرت 2026_10_24_reseller_panel.sql).',
        'title_required'   => 'عنوان تعرفه را وارد کنید.',
        'no_prices'        => 'حداقل یک قیمت وارد کنید.',
        'not_found'        => 'تعرفه پیدا نشد.',
        'below_own_price'  => 'قیمت هیچ خانه‌ای نمی‌تواند کمتر از قیمتی باشد که خودتان برای همان پیام می‌پردازید.',
        'invalid_price'    => 'هر قیمت باید مبلغی بزرگ‌تر از صفر به ریال باشد (حداکثر سه رقم اعشار).',
        'duplicate_title'  => 'تعرفه‌ای با این عنوان از قبل دارید.',
        'in_use'           => 'این تعرفه به مشتریانی داده شده است؛ ابتدا تعرفه‌ی آن‌ها را عوض کنید.',
        'in_package'       => 'این تعرفه در یک بسته استفاده شده است؛ ابتدا بسته را ویرایش کنید.',
        'not_your_child'   => 'این حساب زیرمجموعه‌ی شما نیست.',
        'sub_organ_tariff' => 'زیرسازمان همیشه با تعرفه‌ی سازمان ارسال می‌کند.',
    ][$reason] ?? 'عملیات تعرفه انجام نشد.';
}
