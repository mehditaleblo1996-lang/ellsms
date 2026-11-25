<?php
/**
 * Number shop («خرید شماره», docs/number-shop.md, db/migrations/2026_11_28_number_shop.sql).
 *
 * An admin lists an unassigned line from ellsms_numbers for sale (/number-shop-admin.php) with a
 * purchase price, a yearly renewal price, a category, a description, features and a «ویژه» badge. A
 * customer picks one on /my-numbers.php and pays from the wallet or online; either way an invoice
 * (purpose 'number') is issued in «فاکتورها».
 *
 * Money always passes through the wallet, so nothing paid can be lost:
 *   - wallet: the total is debited at checkout (type number_purchase / number_renewal);
 *   - online: the gateway payment is credited to the wallet like any credit purchase and the order
 *     is debited from it in the same claim transaction (number_shop_fulfil_payment(), called from
 *     payment_claim_and_credit()). A payment that arrives after its reservation lapsed and the line
 *     was sold to someone else simply stays in the wallet (order 'refunded').
 * A rejected purchase is credited back to the wallet (type number_refund) and its invoice refunded.
 *
 * Purchase lifecycle: listing on_sale → reserved (online unpaid: for NUMBER_SHOP_RESERVE_MINUTES;
 * paid: until the admin decides) → sold on approval (line assigned, active, expires one Jalali year
 * from today), or back on_sale on rejection / failed / lapsed payment. A renewal needs no approval:
 * once paid the expiry moves one Jalali year past the later of today and the current expiry.
 */

declare(strict_types=1);

/** A business refusal thrown inside a checkout transaction, so the whole order rolls back. */
final class NumberShopRefusal extends RuntimeException {}

/** How long an unpaid online checkout keeps the line reserved for its buyer. */
const NUMBER_SHOP_RESERVE_MINUTES = 30;

const NUMBER_SHOP_MAX_PRICE = 100_000_000_000; // rial
const NUMBER_SHOP_MAX_FEATURES = 12;

const NUMBER_SHOP_LISTING_STATES = [
    'on_sale'   => 'در حال فروش',
    'reserved'  => 'رزرو شده',
    'sold'      => 'فروخته شده',
    'withdrawn' => 'خارج از فروش',
];

const NUMBER_SHOP_ORDER_STATUSES = [
    'pending_payment'   => 'در انتظار پرداخت',
    'awaiting_approval' => 'در انتظار تأیید مدیر',
    'completed'         => 'انجام شد',
    'rejected'          => 'رد شد (مبلغ به کیف پول برگشت)',
    'failed'            => 'پرداخت ناموفق',
    'expired'           => 'منقضی (پرداخت نشد)',
    'refunded'          => 'مبلغ به کیف پول برگشت',
];

/** True once the migration has created the shop tables. */
function number_shop_ready(): bool {
    if (array_key_exists('__number_shop_ready', $GLOBALS)) return $GLOBALS['__number_shop_ready'];
    try {
        db()->query('SELECT 1 FROM ellsms_number_listings LIMIT 0');
        db()->query('SELECT 1 FROM ellsms_number_orders LIMIT 0');
        db()->query('SELECT number_order_id FROM ellsms_payments LIMIT 0');
        return $GLOBALS['__number_shop_ready'] = number_details_ready();
    } catch (PDOException) {
        return $GLOBALS['__number_shop_ready'] = false;
    }
}

/* ==========================================================================
   Pure helpers
   ========================================================================== */

/** The features typed one per line, trimmed, empty lines and bullets dropped. @return list<string> */
function number_shop_features(string $text): array {
    $out = [];
    foreach (preg_split('/\R/u', $text) ?: [] as $line) {
        $line = trim(preg_replace('/^[\s\-–•*✓✔]+/u', '', $line) ?? '');
        if ($line !== '') $out[] = mb_substr($line, 0, 120);
    }
    return array_slice($out, 0, NUMBER_SHOP_MAX_FEATURES);
}

/** A rial amount typed by an admin (Persian digits, thousands separators allowed), or null. */
function number_shop_parse_amount(string $raw): ?int {
    $v = str_replace([',', '٬', '،', ' ', "\u{200c}"], '', trim(from_persian_digits($raw)));
    if ($v === '' || !ctype_digit($v) || strlen($v) > 13) return null;
    return (int)$v;
}

/**
 * Validates the listing form. Price, renewal price and description are required.
 *
 * @return array{ok:bool, error?:string, data?:array{category:string,title:string,description:string,features:string,price:int,renewal_price:int,featured:int}}
 */
function number_shop_validate_listing(array $in): array {
    $price = number_shop_parse_amount((string)($in['price'] ?? ''));
    $renewal = number_shop_parse_amount((string)($in['renewal_price'] ?? ''));
    $description = trim((string)($in['description'] ?? ''));
    $features = number_shop_features((string)($in['features'] ?? ''));
    if ($price === null || $price < 1000 || $price > NUMBER_SHOP_MAX_PRICE) {
        return ['ok' => false, 'error' => 'قیمت خرید را به ریال وارد کنید (حداقل ۱٬۰۰۰ ریال).'];
    }
    if ($renewal === null || $renewal < 1000 || $renewal > NUMBER_SHOP_MAX_PRICE) {
        return ['ok' => false, 'error' => 'هزینه‌ی تمدید سالانه را به ریال وارد کنید (حداقل ۱٬۰۰۰ ریال).'];
    }
    if (mb_strlen($description) < 10) {
        return ['ok' => false, 'error' => 'توضیحات شماره را بنویسید (حداقل ۱۰ حرف) تا مشتری بداند چه می‌خرد.'];
    }
    if (mb_strlen($description) > 2000) {
        return ['ok' => false, 'error' => 'توضیحات حداکثر ۲٬۰۰۰ حرف باشد.'];
    }
    return ['ok' => true, 'data' => [
        'category'      => mb_substr(trim((string)($in['category'] ?? '')), 0, 60),
        'title'         => mb_substr(trim((string)($in['title'] ?? '')), 0, 120),
        'description'   => $description,
        'features'      => implode("\n", $features),
        'price'         => $price,
        'renewal_price' => $renewal,
        'featured'      => !empty($in['featured']) ? 1 : 0,
    ]];
}

/** Subtotal, VAT and total for $price, by the same formula every invoice uses (app/Financial.php). */
function number_shop_quote_amounts(int $price): array {
    $tax = billing_calculate_tax($price, billing_tax_percent());
    return ['price' => $price, 'tax' => $tax, 'total' => $price + $tax, 'tax_percent' => billing_tax_percent()];
}

/** Digits of a line in groups of four from the left, for reading (9000 1234 56). */
function number_shop_pretty(string $number): string {
    return strlen($number) <= 5 ? $number : implode(' ', str_split($number, 4));
}

function number_shop_error_message(string $reason): string {
    return match ($reason) {
        'not_ready'            => 'فروش شماره هنوز راه‌اندازی نشده است.',
        'listing_not_found'    => 'این شماره دیگر برای فروش نیست.',
        'unavailable'          => 'این شماره همین حالا توسط مشتری دیگری رزرو یا خریده شد.',
        'not_owner'            => 'این شماره متعلق به شما نیست.',
        'insufficient_balance' => 'موجودی کیف پول شما کافی نیست؛ «پرداخت آنلاین» را انتخاب کنید یا ابتدا اعتبار بخرید.',
        'pending_order'        => 'برای این شماره یک سفارش پرداخت‌نشده دارید؛ آن را پرداخت کنید یا ۳۰ دقیقه صبر کنید.',
        'invalid_method'       => 'روش پرداخت معتبر نیست.',
        'invoice_failed'       => 'صدور فاکتور ممکن نشد؛ کمی بعد دوباره تلاش کنید.',
        'order_not_found'      => 'سفارش پیدا نشد یا دیگر در انتظار تأیید نیست.',
        'number_taken'         => 'این خط در این فاصله به کاربر دیگری تخصیص داده شده است؛ سفارش را رد کنید تا مبلغ برگردد.',
        'reason_required'      => 'دلیل رد را بنویسید؛ برای مشتری نمایش داده می‌شود.',
        default                => 'عملیات انجام نشد؛ کمی بعد دوباره تلاش کنید.',
    };
}

/* ==========================================================================
   Listings (admin)
   ========================================================================== */

/** One listing with its line, or null. */
function number_shop_listing(int $listingId, ?PDO $db = null, bool $lock = false): ?array {
    $db ??= db();
    $st = $db->prepare('SELECT l.*, n.number, n.assigned_user_id, n.status AS number_status, n.expires_on
                          FROM ellsms_number_listings l JOIN ellsms_numbers n ON n.id = l.number_id
                         WHERE l.id = ?' . ($lock ? ' FOR UPDATE' : ''));
    $st->execute([$listingId]);
    return $st->fetch() ?: null;
}

/**
 * Puts lines up for sale with the same price, renewal price, description and features. Each number
 * is created if it is not registered yet; an existing one is listed only when nobody owns it and it
 * is not listed already.
 *
 * @param list<string> $raw
 * @return array{ok:bool, error?:string, listed?:list<string>, skipped?:list<array{0:string,1:string}>}
 */
function number_shop_list_numbers(array $raw, array $data, int $actorId): array {
    if (!number_shop_ready()) return ['ok' => false, 'error' => number_shop_error_message('not_ready')];
    $added = number_bulk_add($raw, ['label' => $data['title'] !== '' ? $data['title'] : 'فروشی', 'user_id' => 0, 'status' => 'active'], $actorId);
    if (!$added['ok']) return ['ok' => false, 'error' => $added['error']];
    $listed = [];
    $skipped = [];
    foreach ($added['rows'] as [$number, $code]) {
        if (!in_array($code, ['added', 'exists'], true)) { $skipped[] = [$number, NUMBER_BULK_RESULTS[$code]]; continue; }
        $st = db()->prepare('SELECT n.id, n.assigned_user_id, l.id AS listing_id, l.state
                               FROM ellsms_numbers n LEFT JOIN ellsms_number_listings l ON l.number_id = n.id WHERE n.number = ?');
        $st->execute([$number]);
        $row = $st->fetch();
        if (!$row) { $skipped[] = [$number, 'ثبت نشد']; continue; }
        if ($row['assigned_user_id'] !== null) { $skipped[] = [$number, 'به کاربری تخصیص داده شده است']; continue; }
        if ($row['listing_id'] !== null && $row['state'] !== 'withdrawn') { $skipped[] = [$number, 'از قبل در فروش است']; continue; }
        if ($row['listing_id'] !== null) {
            db()->prepare("UPDATE ellsms_number_listings SET category=?, title=?, description=?, features=?, price=?, renewal_price=?, featured=?,
                                  state='on_sale', order_id=NULL, reserved_until=NULL, sold_to_user_id=NULL, sold_at=NULL WHERE id=?")
               ->execute([$data['category'], $data['title'], $data['description'], $data['features'], $data['price'], $data['renewal_price'], $data['featured'], (int)$row['listing_id']]);
        } else {
            db()->prepare('INSERT INTO ellsms_number_listings (number_id, category, title, description, features, price, renewal_price, featured, created_by)
                           VALUES (?,?,?,?,?,?,?,?,?)')
               ->execute([(int)$row['id'], $data['category'], $data['title'], $data['description'], $data['features'], $data['price'], $data['renewal_price'], $data['featured'], $actorId]);
        }
        $listed[] = $number;
    }
    if ($listed) audit($actorId, 'number_shop.list', count($listed) . ' line(s): ' . implode(',', array_slice($listed, 0, 20)) . " price={$data['price']} renewal={$data['renewal_price']}");
    return ['ok' => true, 'listed' => $listed, 'skipped' => $skipped];
}

/** Edits a listing's text and prices. A price change applies to new orders only. */
function number_shop_listing_update(int $listingId, array $data, int $actorId): array {
    $listing = number_shop_listing($listingId);
    if ($listing === null) return ['ok' => false, 'error' => number_shop_error_message('listing_not_found')];
    db()->prepare('UPDATE ellsms_number_listings SET category=?, title=?, description=?, features=?, price=?, renewal_price=?, featured=? WHERE id=?')
       ->execute([$data['category'], $data['title'], $data['description'], $data['features'], $data['price'], $data['renewal_price'], $data['featured'], $listingId]);
    audit($actorId, 'number_shop.update', "{$listing['number']} price={$data['price']} renewal={$data['renewal_price']}");
    return ['ok' => true, 'number' => (string)$listing['number']];
}

/** Takes an on-sale line off sale, or puts a withdrawn one (still unowned) back on sale. */
function number_shop_listing_set_on_sale(int $listingId, bool $onSale, int $actorId): array {
    return db_transaction(static function (PDO $db) use ($listingId, $onSale, $actorId): array {
        $listing = number_shop_listing($listingId, $db, true);
        if ($listing === null) return ['ok' => false, 'error' => number_shop_error_message('listing_not_found')];
        if ($onSale) {
            if ($listing['state'] !== 'withdrawn') return ['ok' => false, 'error' => 'فقط شماره‌ی خارج از فروش را می‌توان دوباره به فروش گذاشت.'];
            if ($listing['assigned_user_id'] !== null) return ['ok' => false, 'error' => 'این خط به کاربری تخصیص داده شده و نمی‌تواند فروخته شود.'];
            $db->prepare("UPDATE ellsms_number_listings SET state='on_sale', order_id=NULL, reserved_until=NULL, sold_to_user_id=NULL, sold_at=NULL WHERE id=?")->execute([$listingId]);
        } else {
            if ($listing['state'] !== 'on_sale') return ['ok' => false, 'error' => 'فقط شماره‌ی در حال فروش را می‌توان از فروش خارج کرد (رزرو یا فروخته‌شده نه).'];
            $db->prepare("UPDATE ellsms_number_listings SET state='withdrawn' WHERE id=?")->execute([$listingId]);
        }
        audit($actorId, $onSale ? 'number_shop.relist' : 'number_shop.withdraw', (string)$listing['number']);
        return ['ok' => true, 'number' => (string)$listing['number']];
    });
}

/**
 * Lines whose online checkout lapsed go back on sale and their orders read «منقضی». Cheap (one
 * indexed UPDATE when nothing lapsed); called before every read of the shop.
 */
function number_shop_release_stale(): void {
    if (!number_shop_ready()) return;
    try {
        $st = db()->query("SELECT id, order_id FROM ellsms_number_listings WHERE state = 'reserved' AND reserved_until IS NOT NULL AND reserved_until < UTC_TIMESTAMP()");
        foreach ($st->fetchAll() as $row) {
            db_transaction(static function (PDO $db) use ($row): void {
                $up = $db->prepare("UPDATE ellsms_number_listings SET state='on_sale', order_id=NULL, reserved_until=NULL
                                     WHERE id = ? AND state='reserved' AND reserved_until < UTC_TIMESTAMP()");
                $up->execute([(int)$row['id']]);
                if ($up->rowCount() > 0 && $row['order_id'] !== null) {
                    $db->prepare("UPDATE ellsms_number_orders SET status='expired' WHERE id = ? AND status='pending_payment'")->execute([(int)$row['order_id']]);
                }
            });
        }
        // Unpaid renewals hold nothing; they just stop being payable after the same window.
        db()->exec("UPDATE ellsms_number_orders SET status='expired'
                     WHERE status='pending_payment' AND kind='renewal' AND created_at < UTC_TIMESTAMP() - INTERVAL " . NUMBER_SHOP_RESERVE_MINUTES . ' MINUTE');
    } catch (PDOException $e) {
        Logger::error('number_shop.release_failed', ['exception' => $e]);
    }
}

/**
 * The storefront: lines on sale, featured first, filtered by search (digits), category, price range
 * and length.
 *
 * @param array{q?:string, category?:string, min?:?int, max?:?int, len?:string, sort?:string} $f
 * @return array{rows:list<array>, total:int}
 */
function number_shop_storefront(array $f, int $limit, int $offset): array {
    $where = ["l.state = 'on_sale'", 'n.assigned_user_id IS NULL'];
    $params = [];
    $q = preg_replace('/\D/', '', from_persian_digits((string)($f['q'] ?? ''))) ?? '';
    if ($q !== '') { $where[] = 'n.number LIKE ?'; $params[] = '%' . $q . '%'; }
    if (($f['category'] ?? '') !== '') { $where[] = 'l.category = ?'; $params[] = (string)$f['category']; }
    if (($f['min'] ?? null) !== null) { $where[] = 'l.price >= ?'; $params[] = (int)$f['min']; }
    if (($f['max'] ?? null) !== null) { $where[] = 'l.price <= ?'; $params[] = (int)$f['max']; }
    $len = (string)($f['len'] ?? '');
    if ($len === 'short') $where[] = 'CHAR_LENGTH(n.number) <= 8';
    elseif ($len === 'mid') $where[] = 'CHAR_LENGTH(n.number) BETWEEN 9 AND 11';
    elseif ($len === 'long') $where[] = 'CHAR_LENGTH(n.number) >= 12';
    $order = match ((string)($f['sort'] ?? '')) {
        'cheap'  => 'l.price ASC, n.number',
        'costly' => 'l.price DESC, n.number',
        'new'    => 'l.created_at DESC',
        default  => 'l.featured DESC, l.price ASC, n.number',
    };
    $sql = 'FROM ellsms_number_listings l JOIN ellsms_numbers n ON n.id = l.number_id WHERE ' . implode(' AND ', $where);
    $st = db()->prepare("SELECT COUNT(*) {$sql}");
    $st->execute($params);
    $total = (int)$st->fetchColumn();
    $st = db()->prepare("SELECT l.*, n.number {$sql} ORDER BY {$order} LIMIT " . (int)$limit . ' OFFSET ' . (int)$offset);
    $st->execute($params);
    return ['rows' => $st->fetchAll(), 'total' => $total];
}

/** Categories that have a line on sale, with counts. @return array<string,int> */
function number_shop_categories(bool $onSaleOnly = true): array {
    if (!number_shop_ready()) return [];
    $sql = "SELECT l.category, COUNT(*) FROM ellsms_number_listings l JOIN ellsms_numbers n ON n.id = l.number_id WHERE l.category <> ''"
         . ($onSaleOnly ? " AND l.state = 'on_sale' AND n.assigned_user_id IS NULL" : '') . ' GROUP BY l.category ORDER BY l.category';
    return array_map('intval', db()->query($sql)->fetchAll(PDO::FETCH_KEY_PAIR));
}

/* ==========================================================================
   Checkout
   ========================================================================== */

/**
 * Starts a purchase or renewal. Wallet: charged at once (a purchase then waits for the admin, a
 * renewal is applied). Online: returns payment_id and total for the caller to send to the gateway.
 *
 * @return array{ok:bool, reason?:string, order_id?:int, payment_id?:int, total?:int, paid?:bool, expires_on?:string}
 */
function number_shop_checkout(array $buyer, int $listingId, string $kind, string $method): array {
    if (!number_shop_ready()) return ['ok' => false, 'reason' => 'not_ready'];
    if (!in_array($method, ['wallet', 'online'], true) || !in_array($kind, ['purchase', 'renewal'], true)) return ['ok' => false, 'reason' => 'invalid_method'];
    number_shop_release_stale();
    $buyerId = (int)$buyer['id'];
    $organizationId = isset($buyer['organization_id']) && $buyer['organization_id'] !== null ? (int)$buyer['organization_id'] : user_default_organization_id($buyerId);

    try {
        return db_transaction(static function (PDO $db) use ($buyerId, $organizationId, $listingId, $kind, $method): array {
            $listing = number_shop_listing($listingId, $db, true);
            if ($listing === null) return ['ok' => false, 'reason' => 'listing_not_found'];
            if ($kind === 'purchase') {
                if ($listing['state'] !== 'on_sale') return ['ok' => false, 'reason' => $listing['state'] === 'withdrawn' ? 'listing_not_found' : 'unavailable'];
                if ($listing['assigned_user_id'] !== null) return ['ok' => false, 'reason' => 'unavailable'];
                $price = (int)$listing['price'];
            } else {
                if ($listing['state'] !== 'sold' || (int)$listing['sold_to_user_id'] !== $buyerId || (int)$listing['assigned_user_id'] !== $buyerId) {
                    return ['ok' => false, 'reason' => 'not_owner'];
                }
                $pending = $db->prepare("SELECT 1 FROM ellsms_number_orders WHERE listing_id = ? AND kind = 'renewal' AND status = 'pending_payment' AND user_id = ?");
                $pending->execute([$listingId, $buyerId]);
                if ($pending->fetchColumn() !== false) return ['ok' => false, 'reason' => 'pending_order'];
                $price = (int)$listing['renewal_price'];
            }
            $amounts = number_shop_quote_amounts($price);
            // Checked under the wallet's row lock before anything is written, so a short balance
            // leaves no order, payment or invoice behind even inside a caller's transaction.
            if ($method === 'wallet') {
                if (wallet_lock_account($db, $buyerId) < $amounts['total']) return ['ok' => false, 'reason' => 'insufficient_balance'];
            }
            $snapshot = json_encode([
                'category' => $listing['category'], 'title' => $listing['title'], 'description' => $listing['description'],
                'features' => $listing['features'], 'price' => (int)$listing['price'], 'renewal_price' => (int)$listing['renewal_price'],
            ], JSON_UNESCAPED_UNICODE);

            $db->prepare("INSERT INTO ellsms_number_orders (listing_id, number_id, number, user_id, organization_id, kind, pay_method, price, total_amount, listing_snapshot, status)
                          VALUES (?,?,?,?,?,?,?,?,?,?,'pending_payment')")
               ->execute([$listingId, (int)$listing['number_id'], (string)$listing['number'], $buyerId, $organizationId, $kind, $method, $price, $amounts['total'], $snapshot]);
            $orderId = (int)$db->lastInsertId();

            // The payment row an invoice hangs off. Online: a gateway payment that credits the wallet
            // with exactly the total, which the claim then spends on this order. Wallet: already paid.
            $gateway = $method === 'online' ? payment_gateway_name() : 'wallet';
            $db->prepare('INSERT INTO ellsms_payments (user_id, organization_id, credits, amount_rial, gateway, status, number_order_id) VALUES (?,?,?,?,?,?,?)')
               ->execute([$buyerId, $organizationId, $method === 'online' ? $amounts['total'] : 0, $amounts['total'], $gateway, $method === 'online' ? 'pending' : 'paid', $orderId]);
            $paymentId = (int)$db->lastInsertId();
            $line = number_shop_pretty((string)$listing['number']);
            $invoice = billing_invoice_create($paymentId, $organizationId, $buyerId, 'number', [[
                'item_type'      => $kind === 'purchase' ? 'number_purchase' : 'number_renewal',
                'reference_code' => 'number:' . $listing['number'],
                'description'    => ($kind === 'purchase' ? 'خرید شماره‌ی اختصاصی ' : 'تمدید یک‌ساله‌ی شماره‌ی ') . $line
                                    . ($listing['category'] !== '' ? ' (' . $listing['category'] . ')' : ''),
                'quantity'       => 1,
                'unit_price'     => $price,
            ]], null, $method === 'online' ? NUMBER_SHOP_RESERVE_MINUTES : null);
            if (!$invoice['ok'] || (int)$invoice['total_amount'] !== $amounts['total']) throw new RuntimeException('invoice_failed');
            $db->prepare('UPDATE ellsms_number_orders SET payment_id = ?, invoice_id = ? WHERE id = ?')->execute([$paymentId, (int)$invoice['invoice_id'], $orderId]);

            if ($method === 'online') {
                if ($kind === 'purchase') {
                    $db->prepare("UPDATE ellsms_number_listings SET state='reserved', order_id=?, reserved_until=UTC_TIMESTAMP() + INTERVAL " . NUMBER_SHOP_RESERVE_MINUTES . ' MINUTE WHERE id=?')
                       ->execute([$orderId, $listingId]);
                }
                return ['ok' => true, 'order_id' => $orderId, 'payment_id' => $paymentId, 'total' => $amounts['total'], 'paid' => false];
            }

            $debit = wallet_debit($buyerId, $amounts['total'], $kind === 'purchase' ? 'number_purchase' : 'number_renewal', 'number_order', (string)$orderId,
                'number_order:' . $orderId, $buyerId, ['number' => (string)$listing['number']]);
            if (!$debit['ok']) throw new NumberShopRefusal('insufficient_balance');
            billing_invoice_mark_paid($db, (int)$invoice['invoice_id']);
            $result = number_shop_after_payment($db, $orderId);
            return ['ok' => true, 'order_id' => $orderId, 'payment_id' => $paymentId, 'total' => $amounts['total'], 'paid' => true] + $result;
        });
    } catch (NumberShopRefusal $e) {
        return ['ok' => false, 'reason' => $e->getMessage()];
    } catch (RuntimeException $e) {
        if ($e->getMessage() === 'invoice_failed') return ['ok' => false, 'reason' => 'invoice_failed'];
        throw $e;
    }
}

/**
 * Once an order's money is in hand (wallet already debited): a purchase reserves the line for the
 * admin's decision, a renewal is applied. Runs inside the caller's transaction.
 *
 * @return array{status:string, expires_on?:string}
 */
function number_shop_after_payment(PDO $db, int $orderId): array {
    $st = $db->prepare('SELECT * FROM ellsms_number_orders WHERE id = ? FOR UPDATE');
    $st->execute([$orderId]);
    $order = $st->fetch();
    if ($order['kind'] === 'renewal') {
        $expiresOn = number_shop_apply_renewal($db, (int)$order['number_id']);
        $db->prepare("UPDATE ellsms_number_orders SET status='completed', paid_at=UTC_TIMESTAMP(), decided_at=UTC_TIMESTAMP() WHERE id=?")->execute([$orderId]);
        audit((int)$order['user_id'], 'number_shop.renewed', "{$order['number']} -> {$expiresOn} order=#{$orderId}");
        notification_insert_panel((int)$order['user_id'], null, 'number_shop.decision', 'شماره تمدید شد',
            'شماره‌ی ' . $order['number'] . ' تا ' . jdate($expiresOn, false) . ' تمدید شد.', '/my-numbers.php', 'success');
        return ['status' => 'completed', 'expires_on' => $expiresOn];
    }
    $db->prepare("UPDATE ellsms_number_listings SET state='reserved', order_id=?, reserved_until=NULL WHERE id=?")->execute([$orderId, (int)$order['listing_id']]);
    $db->prepare("UPDATE ellsms_number_orders SET status='awaiting_approval', paid_at=UTC_TIMESTAMP() WHERE id=?")->execute([$orderId]);
    audit((int)$order['user_id'], 'number_shop.purchased', "{$order['number']} order=#{$orderId} total={$order['total_amount']} via={$order['pay_method']}");
    notification_dispatch_admins('number_shop.order', 'سفارش خرید شماره', 'شماره‌ی ' . $order['number'] . ' خریداری شد و منتظر تأیید شماست.',
        '/number-shop-admin.php', 'warning');
    return ['status' => 'awaiting_approval'];
}

/** Moves a line's expiry one Jalali year past the later of today and its current expiry; returns it. */
function number_shop_apply_renewal(PDO $db, int $numberId): string {
    $st = $db->prepare('SELECT number, expires_on FROM ellsms_numbers WHERE id = ? FOR UPDATE');
    $st->execute([$numberId]);
    $row = $st->fetch();
    $base = max((string)($row['expires_on'] ?? ''), number_today());
    $new = number_add_jalali_year($base);
    $db->prepare('UPDATE ellsms_numbers SET expires_on = ? WHERE id = ?')->execute([$new, $numberId]);
    number_unsendable_map(true);
    return $new;
}

/**
 * Fulfils an online number payment inside the payment claim (payment_claim_and_credit()): the
 * gateway money is credited to the wallet, then this order is paid from it. If the order can no
 * longer be served (its reservation lapsed and someone else bought the line) the money stays in
 * the wallet and the buyer is told. Never throws for a business reason — the customer has paid.
 *
 * @return array{ok:bool, credit:array}
 */
function number_shop_fulfil_payment(PDO $db, array $payment): array {
    $userId = (int)$payment['user_id'];
    $credit = wallet_credit($userId, (int)$payment['credits'], 'purchase', 'payment', (string)$payment['id'], 'payment_credit:' . $payment['id'],
        null, ['number_order_id' => (int)$payment['number_order_id']]);
    $st = $db->prepare('SELECT * FROM ellsms_number_orders WHERE id = ? FOR UPDATE');
    $st->execute([(int)$payment['number_order_id']]);
    $order = $st->fetch();
    if (!$order || (int)$order['payment_id'] !== (int)$payment['id'] || !in_array($order['status'], ['pending_payment', 'expired', 'failed'], true)) {
        Logger::critical('number_shop.order_mismatch', ['payment_id' => $payment['id'], 'order_id' => $payment['number_order_id']]);
        return ['ok' => false, 'credit' => $credit];
    }
    $orderId = (int)$order['id'];
    $refund = static function (string $why) use ($db, $orderId, $order, $userId): void {
        $db->prepare("UPDATE ellsms_number_orders SET status='refunded', paid_at=UTC_TIMESTAMP(), admin_note=? WHERE id=?")->execute([$why, $orderId]);
        notification_insert_panel($userId, null, 'number_shop.decision', 'خرید شماره انجام نشد',
            'پرداخت شما برای شماره‌ی ' . $order['number'] . ' دریافت شد اما ' . $why . ' مبلغ در کیف پول شما است.', '/my-numbers.php?tab=orders', 'warning');
        Logger::warning('number_shop.paid_but_unavailable', ['order_id' => $orderId, 'reason' => $why]);
    };

    if ($order['kind'] === 'purchase') {
        $listing = number_shop_listing((int)$order['listing_id'], $db, true);
        $heldByUs = $listing !== null && $listing['state'] === 'reserved' && (int)$listing['order_id'] === $orderId;
        $free = $listing !== null && $listing['state'] === 'on_sale';
        if ($listing === null || $listing['assigned_user_id'] !== null || (!$heldByUs && !$free)) {
            $refund('مهلت رزرو گذشته بود و شماره به مشتری دیگری رسید؛');
            return ['ok' => true, 'credit' => $credit];
        }
    } else {
        $st = $db->prepare('SELECT assigned_user_id FROM ellsms_numbers WHERE id = ?');
        $st->execute([(int)$order['number_id']]);
        if ((int)$st->fetchColumn() !== $userId) {
            $refund('این شماره دیگر به حساب شما تخصیص ندارد؛');
            return ['ok' => true, 'credit' => $credit];
        }
    }
    $debit = wallet_debit($userId, (int)$order['total_amount'], $order['kind'] === 'purchase' ? 'number_purchase' : 'number_renewal', 'number_order', (string)$orderId,
        'number_order:' . $orderId, null, ['number' => (string)$order['number'], 'payment_id' => (int)$payment['id']]);
    if (!$debit['ok']) {
        $refund('برداشت از کیف پول ممکن نشد؛');
        return ['ok' => true, 'credit' => $credit];
    }
    number_shop_after_payment($db, $orderId);
    return ['ok' => true, 'credit' => $credit];
}

/** The online payment failed or was cancelled: the order fails and the line goes back on sale. */
function number_shop_fail_for_payment(int $paymentId): void {
    if (!number_shop_ready()) return;
    db_transaction(static function (PDO $db) use ($paymentId): void {
        $st = $db->prepare("SELECT id, listing_id FROM ellsms_number_orders WHERE payment_id = ? AND status = 'pending_payment' FOR UPDATE");
        $st->execute([$paymentId]);
        if (!($order = $st->fetch())) return;
        $db->prepare("UPDATE ellsms_number_orders SET status='failed' WHERE id=?")->execute([(int)$order['id']]);
        $db->prepare("UPDATE ellsms_number_listings SET state='on_sale', order_id=NULL, reserved_until=NULL WHERE id=? AND state='reserved' AND order_id=?")
           ->execute([(int)$order['listing_id'], (int)$order['id']]);
    });
}

/* ==========================================================================
   Admin decision
   ========================================================================== */

/** Approves a paid purchase: the line is assigned to the buyer, active, for one Jalali year. */
function number_shop_approve(int $orderId, int $actorId, string $note = ''): array {
    return db_transaction(static function (PDO $db) use ($orderId, $actorId, $note): array {
        $st = $db->prepare("SELECT * FROM ellsms_number_orders WHERE id = ? AND kind = 'purchase' FOR UPDATE");
        $st->execute([$orderId]);
        $order = $st->fetch();
        if (!$order || $order['status'] !== 'awaiting_approval') return ['ok' => false, 'error' => number_shop_error_message('order_not_found')];
        $st = $db->prepare('SELECT * FROM ellsms_numbers WHERE id = ? FOR UPDATE');
        $st->execute([(int)$order['number_id']]);
        $line = $st->fetch();
        $userId = (int)$order['user_id'];
        if (!$line || ($line['assigned_user_id'] !== null && (int)$line['assigned_user_id'] !== $userId)) {
            return ['ok' => false, 'error' => number_shop_error_message('number_taken')];
        }
        if (($err = number_quota_error($userId, (int)$line['bandwidth'], 'active', (int)$line['id'])) !== null) {
            return ['ok' => false, 'error' => $err . ' (سقف را در صفحه‌ی ویرایش شماره بالا ببرید یا سفارش را رد کنید.)'];
        }
        $expiresOn = number_add_jalali_year(number_today());
        $db->prepare("UPDATE ellsms_numbers SET assigned_user_id = ?, status = 'active', expires_on = ? WHERE id = ?")->execute([$userId, $expiresOn, (int)$line['id']]);
        if (number_extras_ready()) $db->prepare("UPDATE ellsms_numbers SET status_reason = '', status_changed_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([(int)$line['id']]);
        $db->prepare("UPDATE ellsms_number_listings SET state='sold', order_id=?, reserved_until=NULL, sold_to_user_id=?, sold_at=UTC_TIMESTAMP() WHERE id=?")
           ->execute([$orderId, $userId, (int)$order['listing_id']]);
        $db->prepare("UPDATE ellsms_number_orders SET status='completed', admin_note=?, decided_by=?, decided_at=UTC_TIMESTAMP() WHERE id=?")
           ->execute([mb_substr(trim($note), 0, 500), $actorId, $orderId]);
        audit($actorId, 'number_shop.approve', "{$order['number']} -> user #{$userId} order=#{$orderId} expires={$expiresOn}");
        number_unsendable_map(true);
        notification_insert_panel($userId, null, 'number_shop.decision', 'شماره‌ی شما فعال شد',
            'خرید شماره‌ی ' . $order['number'] . ' تأیید شد و تا ' . jdate($expiresOn, false) . ' برای ارسال در دسترس است.', '/my-numbers.php', 'success');
        return ['ok' => true, 'number' => (string)$order['number'], 'expires_on' => $expiresOn];
    });
}

/** Rejects a paid purchase: the total goes back to the buyer's wallet, the invoice is refunded and the line goes back on sale. */
function number_shop_reject(int $orderId, int $actorId, string $reason): array {
    $reason = mb_substr(trim($reason), 0, 500);
    if ($reason === '') return ['ok' => false, 'error' => number_shop_error_message('reason_required')];
    return db_transaction(static function (PDO $db) use ($orderId, $actorId, $reason): array {
        $st = $db->prepare("SELECT * FROM ellsms_number_orders WHERE id = ? AND kind = 'purchase' FOR UPDATE");
        $st->execute([$orderId]);
        $order = $st->fetch();
        if (!$order || $order['status'] !== 'awaiting_approval') return ['ok' => false, 'error' => number_shop_error_message('order_not_found')];
        $userId = (int)$order['user_id'];
        $credit = wallet_credit($userId, (int)$order['total_amount'], 'number_refund', 'number_order', (string)$orderId, 'number_refund:' . $orderId,
            $actorId, ['number' => (string)$order['number'], 'reason' => $reason]);
        if (!($credit['ok'] ?? false)) throw new RuntimeException('number_refund_failed');
        if ($order['invoice_id'] !== null) {
            $refund = billing_refund_invoice((int)$order['invoice_id'], $actorId, 'رد خرید شماره: ' . $reason);
            if (!$refund['ok']) Logger::warning('number_shop.invoice_refund_failed', ['order_id' => $orderId, 'reason' => $refund['reason']]);
        }
        $db->prepare("UPDATE ellsms_number_listings SET state='on_sale', order_id=NULL, reserved_until=NULL WHERE id=? AND order_id=?")
           ->execute([(int)$order['listing_id'], $orderId]);
        $db->prepare("UPDATE ellsms_number_orders SET status='rejected', admin_note=?, decided_by=?, decided_at=UTC_TIMESTAMP() WHERE id=?")
           ->execute([$reason, $actorId, $orderId]);
        audit($actorId, 'number_shop.reject', "{$order['number']} user #{$userId} order=#{$orderId} refunded={$order['total_amount']} ({$reason})");
        notification_insert_panel($userId, null, 'number_shop.decision', 'خرید شماره تأیید نشد',
            'خرید شماره‌ی ' . $order['number'] . ' تأیید نشد: ' . $reason . ' — مبلغ ' . to_persian_digits(number_format((int)$order['total_amount'])) . ' ریال به کیف پول شما برگشت.',
            '/my-numbers.php?tab=orders', 'warning');
        return ['ok' => true, 'number' => (string)$order['number'], 'amount' => (int)$order['total_amount']];
    });
}

/* ==========================================================================
   Reads
   ========================================================================== */

/** @return list<array> the user's orders, newest first */
function number_shop_orders_for_user(int $userId, int $limit = 50): array {
    if (!number_shop_ready()) return [];
    $st = db()->prepare('SELECT o.*, i.invoice_number FROM ellsms_number_orders o LEFT JOIN ellsms_invoices i ON i.id = o.invoice_id
                          WHERE o.user_id = ? ORDER BY o.id DESC LIMIT ' . (int)$limit);
    $st->execute([$userId]);
    return $st->fetchAll();
}

/** Lines assigned to the user, with the shop listing (renewal price) of those bought here. @return list<array> */
function number_shop_user_lines(int $userId): array {
    $cols = number_details_ready() ? 'n.status, n.expires_on' : "'active' AS status, NULL AS expires_on";
    $join = number_shop_ready() ? 'LEFT JOIN ellsms_number_listings l ON l.number_id = n.id AND l.state = \'sold\' AND l.sold_to_user_id = n.assigned_user_id' : '';
    $listingCols = number_shop_ready() ? ', l.id AS listing_id, l.renewal_price, l.category, l.title, l.features' : ', NULL AS listing_id, NULL AS renewal_price, NULL AS category, NULL AS title, NULL AS features';
    $st = db()->prepare("SELECT n.id, n.number, n.label, {$cols}{$listingCols} FROM ellsms_numbers n {$join} WHERE n.assigned_user_id = ? ORDER BY n.number");
    $st->execute([$userId]);
    return $st->fetchAll();
}

function number_shop_pending_approval_count(): int {
    if (!number_shop_ready()) return 0;
    try {
        return (int)db()->query("SELECT COUNT(*) FROM ellsms_number_orders WHERE status = 'awaiting_approval'")->fetchColumn();
    } catch (PDOException) {
        return 0;
    }
}

/** Admin's order list, optionally by status. @return array{rows:list<array>, total:int} */
function number_shop_orders_admin(string $status, int $limit, int $offset): array {
    $where = isset(NUMBER_SHOP_ORDER_STATUSES[$status]) ? 'WHERE o.status = ?' : '';
    $params = $where !== '' ? [$status] : [];
    $st = db()->prepare("SELECT COUNT(*) FROM ellsms_number_orders o {$where}");
    $st->execute($params);
    $total = (int)$st->fetchColumn();
    $st = db()->prepare("SELECT o.*, i.invoice_number FROM ellsms_number_orders o LEFT JOIN ellsms_invoices i ON i.id = o.invoice_id {$where}
                          ORDER BY (o.status = 'awaiting_approval') DESC, o.id DESC LIMIT " . (int)$limit . ' OFFSET ' . (int)$offset);
    $st->execute($params);
    return ['rows' => $st->fetchAll(), 'total' => $total];
}

/** Admin's listing table, optionally by state and digits. @return array{rows:list<array>, total:int} */
function number_shop_listings_admin(string $state, string $q, int $limit, int $offset): array {
    $where = [];
    $params = [];
    if (isset(NUMBER_SHOP_LISTING_STATES[$state])) { $where[] = 'l.state = ?'; $params[] = $state; }
    $q = preg_replace('/\D/', '', from_persian_digits($q)) ?? '';
    if ($q !== '') { $where[] = 'n.number LIKE ?'; $params[] = '%' . $q . '%'; }
    $sql = 'FROM ellsms_number_listings l JOIN ellsms_numbers n ON n.id = l.number_id' . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
    $st = db()->prepare("SELECT COUNT(*) {$sql}");
    $st->execute($params);
    $total = (int)$st->fetchColumn();
    $st = db()->prepare("SELECT l.*, n.number, n.assigned_user_id, n.expires_on {$sql} ORDER BY FIELD(l.state,'reserved','on_sale','sold','withdrawn'), l.featured DESC, n.number LIMIT "
        . (int)$limit . ' OFFSET ' . (int)$offset);
    $st->execute($params);
    return ['rows' => $st->fetchAll(), 'total' => $total];
}

/** Count of listings per state. @return array<string,int> */
function number_shop_state_counts(): array {
    return array_map('intval', db()->query('SELECT state, COUNT(*) FROM ellsms_number_listings GROUP BY state')->fetchAll(PDO::FETCH_KEY_PAIR));
}
