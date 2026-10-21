<?php
/**
 * ELLSMS — packages a reseller sells to its customers and its gift / discount codes, modeled on
 * the Negar platform's Package (credit, tariff, quota and a validity period in its metadata),
 * PackageBusiness's manual assignment and Voucher.
 *
 * A package gives its buyer, all in one transaction:
 *   - credit_rial added to the buyer's wallet,
 *   - optionally one of the reseller's named tariffs (app/ResellerTariffs.php),
 *   - optionally validity_days more before the account expires (from today, or from its current
 *     expiry when that is later; never past the reseller's own expiry),
 *   - optionally a sub-account quota (for a sub-reseller or organization).
 *
 * Who pays for the credit (Negar: "both"):
 *   - assigned by the reseller, or redeemed with a gift code: the credit moves from the reseller's
 *     wallet to the customer's (wallet_transfer()); the reseller collects the price itself;
 *   - bought online by the customer: the customer pays the platform's payment gateway the price
 *     after discounts (+ VAT, on the invoice). When the payment is claimed
 *     (payment_claim_and_credit()) the customer gets the credit and the reseller's wallet is
 *     settled by the difference: price − credit (positive = the reseller earns it, negative = the
 *     reseller funds the discount). A checkout whose reseller cannot fund that difference is refused.
 *
 * The reseller's usual profit on the customer's sends (the tariff difference) is paid by the daily
 * run as before.
 *
 * Schema: db/migrations/2026_10_24_reseller_panel.sql.
 */

declare(strict_types=1);

const RESELLER_VOUCHER_KINDS = ['gift' => 'هدیه (بسته‌ی رایگان)', 'discount' => 'تخفیف درصدی خرید'];

/* ==========================================================================
   Packages
   ========================================================================== */

function reseller_package_normalize(array $row): array {
    $int = static fn($v): ?int => $v !== null && $v !== '' ? (int)$v : null;
    return [
        'id'               => (int)$row['id'],
        'owner_user_id'    => (int)$row['owner_user_id'],
        'name'             => (string)$row['name'],
        'description'      => (string)$row['description'],
        'price_rial'       => (int)$row['price_rial'],
        'discount_percent' => (int)$row['discount_percent'],
        'credit_rial'      => (int)$row['credit_rial'],
        'tariff_id'        => $int($row['tariff_id']),
        'validity_days'    => $int($row['validity_days']),
        'max_subset_limit' => $int($row['max_subset_limit']),
        'customer_types'   => array_values(array_intersect(array_keys(CUSTOMER_TYPES), explode(',', (string)$row['customer_types']))),
        'online_sale'      => (int)$row['online_sale'] === 1,
        'active'           => (int)$row['active'] === 1,
        'deleted'          => (int)$row['deleted'] === 1,
        'sort_order'       => (int)$row['sort_order'],
    ];
}

/** @return list<array> the owner's packages (deleted ones left out), in display order */
function reseller_packages_for(int $ownerId, bool $onlyOnSale = false): array {
    if ($ownerId <= 0 || !reseller_panel_available()) return [];
    $st = db()->prepare('SELECT * FROM ellsms_reseller_packages WHERE owner_user_id = ? AND deleted = 0' . ($onlyOnSale ? ' AND active = 1 AND online_sale = 1' : '') . ' ORDER BY sort_order, price_rial, id');
    $st->execute([$ownerId]);
    return array_map('reseller_package_normalize', $st->fetchAll());
}

function reseller_package_get(int $packageId, ?PDO $db = null): ?array {
    if ($packageId <= 0 || !reseller_panel_available()) return null;
    $st = ($db ?? db())->prepare('SELECT * FROM ellsms_reseller_packages WHERE id = ?');
    $st->execute([$packageId]);
    $row = $st->fetch();
    return $row ? reseller_package_normalize($row) : null;
}

/** A live package of $ownerId, or null. */
function reseller_package_owned(int $ownerId, int $packageId): ?array {
    $package = reseller_package_get($packageId);
    return $package !== null && $package['owner_user_id'] === $ownerId && !$package['deleted'] ? $package : null;
}

/** Pure: the package's price after its own discount and an optional voucher percentage, rounded down to a Rial. */
function reseller_package_final_price(array $package, int $voucherPercent = 0): int {
    $price = intdiv($package['price_rial'] * (100 - max(0, min(100, $package['discount_percent']))), 100);
    return intdiv($price * (100 - max(0, min(100, $voucherPercent))), 100);
}

/** Whether a customer of type $type may receive the package. */
function reseller_package_allows_type(array $package, string $type): bool {
    return in_array($type, $package['customer_types'], true);
}

/**
 * Creates or updates one of the owner's packages.
 *
 * @return array{ok:bool, reason?:string, id?:int}
 */
function reseller_package_save(int $ownerId, ?int $packageId, array $input, int $actorId): array {
    if (!reseller_panel_available()) return ['ok' => false, 'reason' => 'not_migrated'];
    $name = mb_substr(trim((string)($input['name'] ?? '')), 0, 120);
    if ($name === '') return ['ok' => false, 'reason' => 'package_name'];
    $rial = static fn($v): int => (int)str_replace([',', '٬'], '', from_persian_digits(trim((string)$v)));
    $price = $rial($input['price_rial'] ?? '0');
    $credit = $rial($input['credit_rial'] ?? '0');
    $discount = (int)from_persian_digits((string)($input['discount_percent'] ?? '0'));
    if ($price < 0 || $credit < 0 || $discount < 0 || $discount > 100) return ['ok' => false, 'reason' => 'package_amounts'];
    $tariffId = (int)($input['tariff_id'] ?? 0) ?: null;
    if ($tariffId !== null && reseller_tariff_owned($ownerId, $tariffId) === null) return ['ok' => false, 'reason' => 'tariff_not_found'];
    $validity = trim(from_persian_digits((string)($input['validity_days'] ?? '')));
    $validity = $validity === '' ? null : max(1, min(3650, (int)$validity));
    $quota = trim(from_persian_digits((string)($input['max_subset_limit'] ?? '')));
    $quota = $quota === '' ? null : max(0, min(100000, (int)$quota));
    $types = array_values(array_intersect(array_keys(CUSTOMER_TYPES), (array)($input['customer_types'] ?? [])));
    if ($types === []) return ['ok' => false, 'reason' => 'package_types'];
    if ($credit === 0 && $tariffId === null && $validity === null && $quota === null) return ['ok' => false, 'reason' => 'package_empty'];
    $values = [
        $name, mb_substr(trim((string)($input['description'] ?? '')), 0, 500), $price, $discount, $credit, $tariffId, $validity, $quota,
        implode(',', $types), !empty($input['online_sale']) ? 1 : 0, !empty($input['active']) ? 1 : 0, (int)($input['sort_order'] ?? 0),
    ];
    if ($packageId === null) {
        db()->prepare('INSERT INTO ellsms_reseller_packages (name, description, price_rial, discount_percent, credit_rial, tariff_id, validity_days, max_subset_limit,
                         customer_types, online_sale, active, sort_order, owner_user_id, created_by_user_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([...$values, $ownerId, $actorId]);
        $packageId = (int)db()->lastInsertId();
    } else {
        if (reseller_package_owned($ownerId, $packageId) === null) return ['ok' => false, 'reason' => 'package_not_found'];
        db()->prepare('UPDATE ellsms_reseller_packages SET name = ?, description = ?, price_rial = ?, discount_percent = ?, credit_rial = ?, tariff_id = ?, validity_days = ?,
                         max_subset_limit = ?, customer_types = ?, online_sale = ?, active = ?, sort_order = ? WHERE id = ? AND owner_user_id = ?')
            ->execute([...$values, $packageId, $ownerId]);
    }
    audit($actorId, 'reseller.package_saved', "owner=#{$ownerId} package=#{$packageId} {$name}");
    return ['ok' => true, 'id' => $packageId];
}

function reseller_package_delete(int $ownerId, int $packageId, int $actorId): array {
    if (reseller_package_owned($ownerId, $packageId) === null) return ['ok' => false, 'reason' => 'package_not_found'];
    db()->prepare('UPDATE ellsms_reseller_packages SET deleted = 1, active = 0 WHERE id = ? AND owner_user_id = ?')->execute([$packageId, $ownerId]);
    audit($actorId, 'reseller.package_deleted', "owner=#{$ownerId} package=#{$packageId}");
    return ['ok' => true];
}

/* ==========================================================================
   Applying a package
   ========================================================================== */

/**
 * Applies a package's tariff, validity and quota to the buyer, inside the caller's transaction.
 * The buyer's credit is the caller's (it differs by source).
 */
function reseller_package_apply_resources(PDO $db, array $package, int $resellerId, int $buyerId, int $actorId): void {
    $buyer = customer_account($buyerId, $db, true);
    $parent = customer_account($resellerId, $db);
    $sets = [];
    $params = [];
    if ($package['tariff_id'] !== null && reseller_tariff_owned($resellerId, $package['tariff_id']) !== null) {
        $sets[] = 'tariff_id = ?';
        $params[] = $package['tariff_id'];
    }
    if ($package['validity_days'] !== null) {
        $today = customer_today();
        $from = $buyer['expires_on'] !== null && $buyer['expires_on'] > $today ? $buyer['expires_on'] : $today;
        $until = (new DateTimeImmutable($from))->modify('+' . $package['validity_days'] . ' days')->format('Y-m-d');
        if ($parent['expires_on'] !== null && $until > $parent['expires_on']) $until = $parent['expires_on'];
        $sets[] = 'expires_on = ?';
        $params[] = $until;
    }
    if ($package['max_subset_limit'] !== null && customer_can_have_children($buyer)) {
        // Only ever raised by a package, and only within what the reseller has left.
        $wanted = max($buyer['max_subset_limit'], $package['max_subset_limit']);
        $remaining = $parent['max_subset_limit'] - customer_used_subset($resellerId, $db) + $buyer['max_subset_limit'];
        $sets[] = 'max_subset_limit = ?';
        $params[] = min($wanted, max($buyer['max_subset_limit'], $remaining));
    }
    if ($sets) {
        $params[] = $buyerId;
        $db->prepare('UPDATE ellsms_customer_accounts SET ' . implode(', ', $sets) . ' WHERE user_id = ?')->execute($params);
    }
    sms_pricing_cache_reset();
}

/**
 * The reseller gives one of its packages to a direct customer (Negar's manual assignment), or a
 * gift code / the sign-up welcome package does: the credit moves from the reseller to the customer
 * and the package's resources apply. $operationKey makes a double submit a replay. A reseller whose
 * balance cannot cover the credit is refused before anything is written.
 *
 * @return array{ok:bool, reason?:string, order_id?:int, replayed?:bool}
 */
function reseller_package_assign(int $resellerId, int $buyerId, int $packageId, int $actorId, string $operationKey, string $source = 'manual', ?int $voucherId = null): array {
    if (!reseller_panel_available()) return ['ok' => false, 'reason' => 'not_migrated'];
    $package = reseller_package_owned($resellerId, $packageId);
    if ($package === null) return ['ok' => false, 'reason' => 'package_not_found'];
    $buyer = customer_account($buyerId);
    if (!$buyer['exists'] || $buyer['parent_user_id'] !== $resellerId) return ['ok' => false, 'reason' => 'not_your_child'];
    if (!reseller_package_allows_type($package, $buyer['customer_type'])) return ['ok' => false, 'reason' => 'package_type_mismatch'];

    $key = 'reseller_package:' . mb_substr($operationKey, 0, 100);
    return db_transaction(function (PDO $db) use ($package, $resellerId, $buyerId, $actorId, $key, $source, $voucherId): array {
        $existing = $db->prepare('SELECT id FROM ellsms_reseller_package_orders WHERE operation_key = ?');
        $existing->execute([$key]);
        if (($orderId = $existing->fetchColumn()) !== false) {
            return ['ok' => true, 'order_id' => (int)$orderId, 'replayed' => true];
        }
        if ($package['credit_rial'] > 0) {
            // First, so a refusal (the reseller's balance) leaves nothing behind.
            // reference_id holds at most 64 characters; the full key is the idempotency key.
            $transfer = wallet_transfer($resellerId, $buyerId, $package['credit_rial'], 'reseller_package', substr(hash('sha256', $key), 0, 40), $key, $actorId,
                ['package_id' => $package['id'], 'source' => $source]);
            if (!$transfer['ok']) {
                return ['ok' => false, 'reason' => (string)($transfer['reason'] ?? 'insufficient_balance')];
            }
        }
        $db->prepare("INSERT INTO ellsms_reseller_package_orders
                        (package_id, reseller_user_id, buyer_user_id, source, price_rial, credit_rial, reseller_amount, voucher_id, status, package_snapshot, operation_key, created_by_user_id, applied_at)
                      VALUES (?,?,?,?,0,?,?,?,'applied',?,?,?,UTC_TIMESTAMP())")
           ->execute([$package['id'], $resellerId, $buyerId, $source, $package['credit_rial'], -$package['credit_rial'], $voucherId,
                      json_encode($package, JSON_UNESCAPED_UNICODE), $key, $actorId]);
        $orderId = (int)$db->lastInsertId();
        reseller_package_apply_resources($db, $package, $resellerId, $buyerId, $actorId);
        audit($actorId, 'reseller.package_assigned', "reseller=#{$resellerId} buyer=#{$buyerId} package=#{$package['id']} order=#{$orderId} source={$source}");
        return ['ok' => true, 'order_id' => $orderId];
    });
}

/* ==========================================================================
   Vouchers
   ========================================================================== */

function reseller_voucher_normalize(array $row): array {
    return [
        'id'            => (int)$row['id'],
        'owner_user_id' => (int)$row['owner_user_id'],
        'code'          => (string)$row['code'],
        'kind'          => (string)$row['kind'],
        'package_id'    => $row['package_id'] !== null ? (int)$row['package_id'] : null,
        'percent'       => (int)$row['percent'],
        'max_uses'      => (int)$row['max_uses'],
        'used_count'    => (int)$row['used_count'],
        'expires_on'    => $row['expires_on'] !== null ? (string)$row['expires_on'] : null,
        'active'        => (int)$row['active'] === 1,
        'note'          => (string)$row['note'],
        'created_at'    => (string)$row['created_at'],
    ];
}

/** 'AB12-CD34' style, unambiguous characters only. */
function reseller_voucher_new_code(): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i = 0; $i < 8; $i++) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return substr($code, 0, 4) . '-' . substr($code, 4);
}

function reseller_voucher_normalize_code(string $raw): string {
    return strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', from_persian_digits(trim($raw))) ?? '');
}

/** @return list<array> */
function reseller_vouchers_for(int $ownerId): array {
    if (!reseller_panel_available()) return [];
    $st = db()->prepare('SELECT * FROM ellsms_reseller_vouchers WHERE owner_user_id = ? ORDER BY id DESC LIMIT 500');
    $st->execute([$ownerId]);
    return array_map('reseller_voucher_normalize', $st->fetchAll());
}

/**
 * Creates $count codes of one kind. A gift code needs a package; a discount code takes a percentage
 * and optionally a package (none = any of the owner's packages).
 *
 * @return array{ok:bool, reason?:string, codes?:list<string>}
 */
function reseller_vouchers_create(int $ownerId, array $input, int $actorId): array {
    if (!reseller_panel_available()) return ['ok' => false, 'reason' => 'not_migrated'];
    $kind = (string)($input['kind'] ?? '');
    if (!isset(RESELLER_VOUCHER_KINDS[$kind])) return ['ok' => false, 'reason' => 'voucher_kind'];
    $packageId = (int)($input['package_id'] ?? 0) ?: null;
    if ($packageId !== null && reseller_package_owned($ownerId, $packageId) === null) return ['ok' => false, 'reason' => 'package_not_found'];
    if ($kind === 'gift' && $packageId === null) return ['ok' => false, 'reason' => 'voucher_package_required'];
    $percent = $kind === 'discount' ? (int)from_persian_digits((string)($input['percent'] ?? '0')) : 0;
    if ($kind === 'discount' && ($percent < 1 || $percent > 100)) return ['ok' => false, 'reason' => 'voucher_percent'];
    $count = max(1, min(200, (int)from_persian_digits((string)($input['count'] ?? '1'))));
    $maxUses = max(1, min(100000, (int)from_persian_digits((string)($input['max_uses'] ?? '1'))));
    $expiresOn = $input['expires_on'] ?? null;
    $custom = reseller_voucher_normalize_code((string)($input['code'] ?? ''));
    if ($custom !== '' && (!preg_match('/^[A-Z0-9-]{4,32}$/', $custom) || $count !== 1)) return ['ok' => false, 'reason' => 'voucher_code'];

    $codes = [];
    $ins = db()->prepare('INSERT INTO ellsms_reseller_vouchers (owner_user_id, code, kind, package_id, percent, max_uses, expires_on, note, created_by_user_id) VALUES (?,?,?,?,?,?,?,?,?)');
    for ($i = 0; $i < $count; $i++) {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $code = $custom !== '' ? $custom : reseller_voucher_new_code();
            try {
                $ins->execute([$ownerId, $code, $kind, $packageId, $percent, $maxUses, $expiresOn, mb_substr(trim((string)($input['note'] ?? '')), 0, 190), $actorId]);
                $codes[] = $code;
                break;
            } catch (PDOException $e) {
                if ((int)($e->errorInfo[1] ?? 0) !== 1062) throw $e;
                if ($custom !== '') return ['ok' => false, 'reason' => 'voucher_code_taken'];
            }
        }
    }
    audit($actorId, 'reseller.vouchers_created', "owner=#{$ownerId} kind={$kind} count=" . count($codes));
    return ['ok' => true, 'codes' => $codes];
}

function reseller_voucher_set_active(int $ownerId, int $voucherId, bool $active, int $actorId): void {
    db()->prepare('UPDATE ellsms_reseller_vouchers SET active = ? WHERE id = ? AND owner_user_id = ?')->execute([$active ? 1 : 0, $voucherId, $ownerId]);
    audit($actorId, 'reseller.voucher_' . ($active ? 'activated' : 'deactivated'), "owner=#{$ownerId} voucher=#{$voucherId}");
}

/**
 * Pure: why $voucher may not be used by $userId (a customer of $parentId) for $packageId today, or
 * null. $alreadyUsed = whether this user has used it before.
 */
function reseller_voucher_refusal(?array $voucher, int $parentId, ?int $packageId, bool $alreadyUsed, string $today): ?string {
    if ($voucher === null || $voucher['owner_user_id'] !== $parentId) return 'voucher_not_found';
    if (!$voucher['active']) return 'voucher_inactive';
    if ($voucher['expires_on'] !== null && $voucher['expires_on'] < $today) return 'voucher_expired';
    if ($voucher['used_count'] >= $voucher['max_uses']) return 'voucher_used_up';
    if ($alreadyUsed) return 'voucher_already_used';
    if ($packageId !== null && $voucher['package_id'] !== null && $voucher['package_id'] !== $packageId) return 'voucher_other_package';
    return null;
}

function reseller_voucher_by_code(string $code, ?PDO $db = null, bool $forUpdate = false): ?array {
    $code = reseller_voucher_normalize_code($code);
    if ($code === '' || !reseller_panel_available()) return null;
    $st = ($db ?? db())->prepare('SELECT * FROM ellsms_reseller_vouchers WHERE code = ?' . ($forUpdate ? ' FOR UPDATE' : ''));
    $st->execute([$code]);
    $row = $st->fetch();
    return $row ? reseller_voucher_normalize($row) : null;
}

function reseller_voucher_code_by_id(int $voucherId): ?string {
    $st = db()->prepare('SELECT code FROM ellsms_reseller_vouchers WHERE id = ?');
    $st->execute([$voucherId]);
    $code = $st->fetchColumn();
    return $code !== false ? (string)$code : null;
}

function reseller_voucher_used_by(int $voucherId, int $userId, ?PDO $db = null): bool {
    $st = ($db ?? db())->prepare('SELECT 1 FROM ellsms_reseller_voucher_uses WHERE voucher_id = ? AND user_id = ?');
    $st->execute([$voucherId, $userId]);
    return (bool)$st->fetchColumn();
}

/** Counts one use of the voucher by $userId, inside the caller's transaction. False when it can no longer be used. */
function reseller_voucher_consume(PDO $db, int $voucherId, int $userId, ?int $orderId): bool {
    $st = $db->prepare('UPDATE ellsms_reseller_vouchers SET used_count = used_count + 1 WHERE id = ? AND used_count < max_uses AND active = 1');
    $st->execute([$voucherId]);
    if ($st->rowCount() !== 1) return false;
    try {
        $db->prepare('INSERT INTO ellsms_reseller_voucher_uses (voucher_id, user_id, order_id) VALUES (?,?,?)')->execute([$voucherId, $userId, $orderId]);
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) === 1062) return false;
        throw $e;
    }
    return true;
}

/**
 * A customer redeems a gift code from its parent reseller: the code's package is given to it, paid by
 * the reseller.
 *
 * @return array{ok:bool, reason?:string, order_id?:int, package?:array}
 */
function reseller_voucher_redeem_gift(int $userId, string $code): array {
    $account = customer_account($userId);
    $parentId = $account['parent_user_id'];
    if (!$account['exists'] || $parentId === null) return ['ok' => false, 'reason' => 'voucher_not_found'];
    $voucher = reseller_voucher_by_code($code);
    if ($voucher !== null && $voucher['kind'] !== 'gift') return ['ok' => false, 'reason' => 'voucher_is_discount'];
    $refusal = reseller_voucher_refusal($voucher, $parentId, null, $voucher !== null && reseller_voucher_used_by($voucher['id'], $userId), customer_today());
    if ($refusal !== null) return ['ok' => false, 'reason' => $refusal];
    $package = reseller_package_owned($parentId, (int)$voucher['package_id']);
    if ($package === null) return ['ok' => false, 'reason' => 'package_not_found'];
    $result = db_transaction(function (PDO $db) use ($voucher, $userId, $parentId, $package): array {
        // Locked and re-checked, so the use counted below cannot fail after the package is given.
        $locked = reseller_voucher_by_code($voucher['code'], $db, true);
        $refusal = reseller_voucher_refusal($locked, $parentId, null, reseller_voucher_used_by($voucher['id'], $userId, $db), customer_today());
        if ($refusal !== null) return ['ok' => false, 'reason' => $refusal];
        $order = reseller_package_assign($parentId, $userId, $package['id'], $userId, 'voucher:' . $voucher['id'] . ':' . $userId, 'voucher', $voucher['id']);
        if (!$order['ok']) {
            // The reseller's own balance cannot cover the gift: the customer is told the code is not usable now.
            return ['ok' => false, 'reason' => $order['reason'] === 'insufficient_balance' ? 'voucher_reseller_balance' : (string)$order['reason']];
        }
        reseller_voucher_consume($db, $voucher['id'], $userId, $order['order_id']);
        return $order;
    });
    if (!$result['ok']) return $result;
    notification_insert_panel($parentId, null, 'reseller.package', 'استفاده از کد هدیه', 'کد ' . $voucher['code'] . ' برای بسته‌ی «' . $package['name'] . '» استفاده شد.', '/reseller/packages', 'info');
    return $result + ['package' => $package];
}

/* ==========================================================================
   Online purchase
   ========================================================================== */

/**
 * What a customer would pay for its parent's package with an optional discount code: the checks of
 * a checkout without any side effect.
 *
 * @return array{ok:bool, reason?:string, package?:array, voucher?:?array, price?:int, reseller_amount?:int}
 */
function reseller_package_quote(int $buyerId, int $packageId, string $voucherCode = ''): array {
    if (!reseller_panel_available()) return ['ok' => false, 'reason' => 'not_migrated'];
    $account = customer_account($buyerId);
    $parentId = $account['parent_user_id'];
    if (!$account['exists'] || $parentId === null || !reseller_is_reseller($parentId)) return ['ok' => false, 'reason' => 'package_not_found'];
    $package = reseller_package_owned($parentId, $packageId);
    if ($package === null || !$package['active'] || !$package['online_sale']) return ['ok' => false, 'reason' => 'package_not_found'];
    if (!reseller_package_allows_type($package, $account['customer_type'])) return ['ok' => false, 'reason' => 'package_type_mismatch'];
    $voucher = null;
    if (trim($voucherCode) !== '') {
        $voucher = reseller_voucher_by_code($voucherCode);
        if ($voucher !== null && $voucher['kind'] !== 'discount') return ['ok' => false, 'reason' => 'voucher_is_gift'];
        $refusal = reseller_voucher_refusal($voucher, $parentId, $packageId, $voucher !== null && reseller_voucher_used_by($voucher['id'], $buyerId), customer_today());
        if ($refusal !== null) return ['ok' => false, 'reason' => $refusal];
    }
    $price = reseller_package_final_price($package, $voucher['percent'] ?? 0);
    $resellerAmount = $price - $package['credit_rial'];
    if ($resellerAmount < 0 && wallet_balance($parentId)['available'] < -$resellerAmount) {
        return ['ok' => false, 'reason' => 'package_unavailable'];
    }
    return ['ok' => true, 'package' => $package, 'voucher' => $voucher, 'price' => $price, 'reseller_amount' => $resellerAmount, 'reseller_id' => $parentId];
}

/**
 * Starts an online purchase: a pending order, its payment row and invoice. Returns the payment id
 * for the caller to send to the gateway. A package that costs nothing after discounts is applied at
 * once (paid by the reseller) and returns applied = true.
 *
 * @return array{ok:bool, reason?:string, payment_id?:int, order_id?:int, total?:int, applied?:bool}
 */
function reseller_package_checkout(array $buyer, int $packageId, string $voucherCode = ''): array {
    $buyerId = (int)$buyer['id'];
    $quote = reseller_package_quote($buyerId, $packageId, $voucherCode);
    if (!$quote['ok']) return $quote;
    $package = $quote['package'];
    $resellerId = (int)$quote['reseller_id'];
    $voucherId = $quote['voucher']['id'] ?? null;

    if ($quote['price'] <= 0) {
        $order = db_transaction(function (PDO $db) use ($resellerId, $buyerId, $package, $voucherId): array {
            if ($voucherId !== null) {
                $locked = reseller_voucher_by_code((string)reseller_voucher_code_by_id($voucherId), $db, true);
                $refusal = reseller_voucher_refusal($locked, $resellerId, $package['id'], reseller_voucher_used_by($voucherId, $buyerId, $db), customer_today());
                if ($refusal !== null) return ['ok' => false, 'reason' => $refusal];
            }
            $order = reseller_package_assign($resellerId, $buyerId, $package['id'], $buyerId, 'free:' . $package['id'] . ':' . $buyerId . ':' . bin2hex(random_bytes(6)), 'online', $voucherId);
            if (!$order['ok']) return ['ok' => false, 'reason' => $order['reason'] === 'insufficient_balance' ? 'package_unavailable' : (string)$order['reason']];
            if ($voucherId !== null) reseller_voucher_consume($db, $voucherId, $buyerId, $order['order_id']);
            return $order;
        });
        if (!$order['ok']) return $order;
        return ['ok' => true, 'applied' => true, 'order_id' => $order['order_id']];
    }

    $organizationId = $buyer['organization_id'] ?? user_default_organization_id($buyerId);
    return db_transaction(function (PDO $db) use ($buyerId, $resellerId, $package, $quote, $voucherId, $organizationId): array {
        $db->prepare("INSERT INTO ellsms_reseller_package_orders
                        (package_id, reseller_user_id, buyer_user_id, source, price_rial, credit_rial, reseller_amount, voucher_id, status, package_snapshot, created_by_user_id)
                      VALUES (?,?,?,'online',?,?,?,?,'pending',?,?)")
           ->execute([$package['id'], $resellerId, $buyerId, $quote['price'], $package['credit_rial'], $quote['reseller_amount'], $voucherId,
                      json_encode($package, JSON_UNESCAPED_UNICODE), $buyerId]);
        $orderId = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO ellsms_payments (user_id, organization_id, credits, amount_rial, gateway, reseller_order_id) VALUES (?,?,?,?,?,?)')
           ->execute([$buyerId, $organizationId, $package['credit_rial'], $quote['price'], payment_gateway_name(), $orderId]);
        $paymentId = (int)$db->lastInsertId();
        $db->prepare('UPDATE ellsms_reseller_package_orders SET payment_id = ? WHERE id = ?')->execute([$paymentId, $orderId]);
        $invoice = billing_invoice_create($paymentId, $organizationId, $buyerId, 'credit', [[
            'item_type' => 'sms_credit', 'reference_code' => 'reseller_package:' . $package['id'],
            'description' => 'بسته‌ی «' . $package['name'] . '»', 'quantity' => 1, 'unit_price' => $quote['price'],
        ]]);
        if (!$invoice['ok']) throw new RuntimeException('invoice_failed');
        return ['ok' => true, 'payment_id' => $paymentId, 'order_id' => $orderId, 'total' => (int)$invoice['total_amount']];
    });
}

/**
 * Fulfils an online order inside the payment claim's transaction (payment_claim_and_credit()): the
 * customer gets the credit, the reseller is settled by price − credit, the discount code is used up
 * and the package's resources apply. Never throws for a business reason — the customer has paid:
 * a reseller that cannot cover a negative settlement any more, or a code used up in the meantime, is
 * logged and reported to the platform admins instead.
 *
 * @return array{ok:bool, credit:array}
 */
function reseller_package_fulfil_payment(PDO $db, array $payment): array {
    $st = $db->prepare('SELECT * FROM ellsms_reseller_package_orders WHERE id = ? FOR UPDATE');
    $st->execute([(int)$payment['reseller_order_id']]);
    $order = $st->fetch();
    $credit = ['ok' => true, 'replayed' => true];
    if (!$order || $order['status'] !== 'pending' || (int)$order['payment_id'] !== (int)$payment['id']) {
        Logger::critical('reseller.package_order_mismatch', ['payment_id' => $payment['id'], 'order_id' => $payment['reseller_order_id']]);
        // Paid money is never lost: the customer gets the payment's credit as an ordinary purchase.
        $credit = wallet_credit((int)$payment['user_id'], (int)$payment['credits'], 'purchase', 'payment', (string)$payment['id'], 'payment_credit:' . $payment['id']);
        return ['ok' => false, 'credit' => $credit];
    }
    $orderId = (int)$order['id'];
    $buyerId = (int)$order['buyer_user_id'];
    $resellerId = (int)$order['reseller_user_id'];
    $package = json_decode((string)$order['package_snapshot'], true) ?: [];
    $notes = [];

    $credit = wallet_credit($buyerId, (int)$order['credit_rial'], 'purchase', 'payment', (string)$payment['id'], 'payment_credit:' . $payment['id'],
        null, ['reseller_order_id' => $orderId]);
    $amount = (int)$order['reseller_amount'];
    if ($amount > 0) {
        wallet_credit($resellerId, $amount, 'reseller_package_sale', 'reseller_package', (string)$orderId, 'reseller_package_sale:' . $orderId, null, ['payment_id' => (int)$payment['id']]);
    } elseif ($amount < 0) {
        $debit = wallet_debit($resellerId, -$amount, 'reseller_package_sale', 'reseller_package', (string)$orderId, 'reseller_package_sale:' . $orderId, null, ['payment_id' => (int)$payment['id']]);
        if (!$debit['ok']) {
            $notes[] = 'reseller_shortfall';
            Logger::critical('reseller.package_settlement_shortfall', ['order_id' => $orderId, 'reseller_id' => $resellerId, 'amount' => -$amount]);
        }
    }
    if ($order['voucher_id'] !== null && !reseller_voucher_consume($db, (int)$order['voucher_id'], $buyerId, $orderId)) {
        $notes[] = 'voucher_used_up';
        Logger::warning('reseller.package_voucher_used_up', ['order_id' => $orderId, 'voucher_id' => $order['voucher_id']]);
    }
    if ($package !== []) {
        // The package as it was sold (its tariff only if the reseller still has it).
        reseller_package_apply_resources($db, reseller_package_normalize_snapshot($package), $resellerId, $buyerId, $buyerId);
    }
    $db->prepare("UPDATE ellsms_reseller_package_orders SET status = 'applied', applied_at = UTC_TIMESTAMP(), failure_reason = ? WHERE id = ?")
       ->execute([implode(',', $notes), $orderId]);
    if ($notes !== []) {
        notification_dispatch_admins('reseller.package', 'بررسی سفارش بسته‌ی نماینده', 'سفارش #' . $orderId . ' پرداخت و اعمال شد اما نیاز به بررسی دارد: ' . implode('، ', $notes), '/admin/users/reseller-profits', 'warning');
    }
    notification_insert_panel($resellerId, null, 'reseller.package', 'فروش آنلاین بسته', 'بسته‌ی «' . ($package['name'] ?? '') . '» خریداری شد.', '/reseller/finance', 'success');
    return ['ok' => true, 'credit' => $credit];
}

/** The fields of a stored package snapshot, typed. */
function reseller_package_normalize_snapshot(array $snapshot): array {
    $int = static fn($v): ?int => $v !== null && $v !== '' ? (int)$v : null;
    return [
        'tariff_id'        => $int($snapshot['tariff_id'] ?? null),
        'validity_days'    => $int($snapshot['validity_days'] ?? null),
        'max_subset_limit' => $int($snapshot['max_subset_limit'] ?? null),
        'credit_rial'      => (int)($snapshot['credit_rial'] ?? 0),
        'name'             => (string)($snapshot['name'] ?? ''),
    ];
}

/** Marks a pending online order failed when its payment failed or was cancelled. */
function reseller_package_order_fail_for_payment(int $paymentId): void {
    if (!reseller_panel_available()) return;
    db()->prepare("UPDATE ellsms_reseller_package_orders SET status = 'failed' WHERE payment_id = ? AND status = 'pending'")->execute([$paymentId]);
}

/* ==========================================================================
   Reports
   ========================================================================== */

/** Sum of what online package sales paid the reseller over the last $days days. */
function reseller_package_sales_total(int $resellerId, int $days): int {
    if (!reseller_panel_available()) return 0;
    $st = db()->prepare("SELECT COALESCE(SUM(price_rial), 0) FROM ellsms_reseller_package_orders
                         WHERE reseller_user_id = ? AND status = 'applied' AND source = 'online' AND created_at >= UTC_TIMESTAMP() - INTERVAL ? DAY");
    $st->execute([$resellerId, $days]);
    return (int)$st->fetchColumn();
}

/** @return list<array> the reseller's latest orders */
function reseller_package_orders(int $resellerId, int $limit = 100, ?int $buyerId = null): array {
    if (!reseller_panel_available()) return [];
    $sql = 'SELECT o.*, p.name package_name FROM ellsms_reseller_package_orders o LEFT JOIN ellsms_reseller_packages p ON p.id = o.package_id WHERE o.reseller_user_id = ?';
    $params = [$resellerId];
    if ($buyerId !== null) {
        $sql .= ' AND o.buyer_user_id = ?';
        $params[] = $buyerId;
    }
    $st = db()->prepare($sql . ' ORDER BY o.id DESC LIMIT ' . max(1, min(500, $limit)));
    $st->execute($params);
    return $st->fetchAll();
}

function reseller_package_error_message(string $reason): string {
    return [
        'package_name'            => 'نام بسته را وارد کنید.',
        'package_amounts'         => 'قیمت و اعتبار باید عدد مثبت و تخفیف بین ۰ تا ۱۰۰ باشد.',
        'package_types'           => 'حداقل یک نوع مشتری برای بسته انتخاب کنید.',
        'package_empty'           => 'بسته باید حداقل یکی از این‌ها را داشته باشد: اعتبار، تعرفه، اعتبار زمانی یا سقف زیرمجموعه.',
        'package_not_found'       => 'بسته پیدا نشد یا فعال نیست.',
        'package_type_mismatch'   => 'این بسته برای نوع حساب این مشتری تعریف نشده است.',
        'package_unavailable'     => 'خرید این بسته در حال حاضر ممکن نیست؛ با نماینده‌ی خود تماس بگیرید.',
        'insufficient_balance'    => 'اعتبار شما برای دادن اعتبار این بسته کافی نیست.',
        'tariff_not_found'        => 'تعرفه پیدا نشد.',
        'not_your_child'          => 'این حساب زیرمجموعه‌ی شما نیست.',
        'voucher_kind'            => 'نوع کد نامعتبر است.',
        'voucher_package_required'=> 'برای کد هدیه، بسته را انتخاب کنید.',
        'voucher_percent'         => 'درصد تخفیف باید بین ۱ تا ۱۰۰ باشد.',
        'voucher_code'            => 'کد دلخواه باید ۴ تا ۳۲ حرف لاتین یا عدد باشد و فقط برای ساخت یک کد.',
        'voucher_code_taken'      => 'این کد قبلاً ساخته شده است.',
        'voucher_not_found'       => 'کد وارد‌شده معتبر نیست.',
        'voucher_inactive'        => 'این کد غیرفعال شده است.',
        'voucher_expired'         => 'مهلت استفاده از این کد تمام شده است.',
        'voucher_used_up'         => 'ظرفیت استفاده از این کد تمام شده است.',
        'voucher_already_used'    => 'شما قبلاً از این کد استفاده کرده‌اید.',
        'voucher_other_package'   => 'این کد برای بسته‌ی دیگری است.',
        'voucher_is_discount'     => 'این کد، کد تخفیف خرید است؛ آن را هنگام خرید بسته وارد کنید.',
        'voucher_is_gift'         => 'این کد، کد هدیه است؛ آن را در بخش «کد هدیه» وارد کنید.',
        'voucher_reseller_balance'=> 'این کد در حال حاضر قابل استفاده نیست؛ با نماینده‌ی خود تماس بگیرید.',
        'not_migrated'            => 'جدول‌های پنل نمایندگی هنوز ساخته نشده‌اند.',
    ][$reason] ?? 'عملیات بسته انجام نشد.';
}
