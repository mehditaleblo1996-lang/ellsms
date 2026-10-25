<?php
/**
 * ELLSMS — manual payment methods for buying credit (docs/manual-payments.md).
 *
 * Next to the online gateway (app/Payment/PaymentGateway.php), a customer can report a payment it
 * made outside the panel: card-to-card (کارت به کارت), a bank deposit (واریز به حساب), cash (نقدی)
 * or a cheque (پرداخت چک). Each report becomes:
 *
 *   - an ellsms_payments row with gateway = the method and status 'pending',
 *   - its immutable invoice (billing_invoice_create(), VAT included — the customer pays the total),
 *   - an ellsms_manual_payments row with what the customer entered and the review state.
 *
 * Nothing is credited until the payment is reviewed. Approving it claims the payment exactly like a
 * gateway callback does (status pending -> paid in one UPDATE, the invoice marked paid in the same
 * transaction), so a double click or two reviewers at once credit it once.
 *
 * WHO RECEIVES THE MONEY (the "collector"). The platform admin configures the platform's cards and
 * accounts and which methods it accepts (owner 0). A reseller (app/ResellerPanel.php) may do the
 * same for its own customers: for a customer under a reseller, a method the reseller accepts is paid
 * to the reseller and reviewed by the reseller; any other method falls back to the platform's.
 *
 *   - collector 0 (the platform): approval credits the wallet (wallet_credit, type 'purchase' — the
 *     same ledger entry an online purchase writes).
 *   - collector = a reseller: the reseller holds the money, so approval moves the credit from the
 *     reseller's wallet to the customer's (wallet_transfer), exactly like a reseller giving credit on
 *     the customer's page. It is refused, and nothing changes, while the reseller's balance is short.
 *
 * Inert until db/migrations/2026_10_25_manual_payments.sql is applied: manual_payments_available()
 * is false and only the online gateway is offered.
 */

declare(strict_types=1);

/** Method key => label. The key is also ellsms_payments.gateway for these payments. */
const MANUAL_PAYMENT_METHODS = [
    'card'    => 'کارت به کارت',
    'account' => 'واریز به حساب',
    'cash'    => 'نقدی',
    'cheque'  => 'پرداخت چک',
];

/** Which kind of destination a method is paid into (cash has none). */
const MANUAL_PAYMENT_DESTINATION_KIND = ['card' => 'card', 'account' => 'account', 'cheque' => 'account'];

const MANUAL_PAYMENT_REVIEW_STATES = [
    'pending'  => 'در انتظار بررسی',
    'approved' => 'تأییدشده',
    'rejected' => 'ردشده',
];

const MANUAL_PAYMENT_BANKS = [
    'melli' => 'ملی', 'mellat' => 'ملت', 'tejarat' => 'تجارت', 'saderat' => 'صادرات', 'sepah' => 'سپه',
    'keshavarzi' => 'کشاورزی', 'maskan' => 'مسکن', 'refah' => 'رفاه', 'pasargad' => 'پاسارگاد',
    'parsian' => 'پارسیان', 'saman' => 'سامان', 'eghtesad_novin' => 'اقتصاد نوین', 'sina' => 'سینا',
    'shahr' => 'شهر', 'dey' => 'دی', 'ayandeh' => 'آینده', 'sarmayeh' => 'سرمایه', 'karafarin' => 'کارآفرین',
    'tosee_saderat' => 'توسعه صادرات', 'sanat_madan' => 'صنعت و معدن', 'post_bank' => 'پست بانک',
    'tosee_taavon' => 'توسعه تعاون', 'gardeshgari' => 'گردشگری', 'iran_zamin' => 'ایران زمین',
    'khavarmianeh' => 'خاورمیانه', 'resalat' => 'قرض‌الحسنه رسالت', 'mehr_iran' => 'قرض‌الحسنه مهر ایران',
    'blu' => 'بلو', 'other' => 'سایر',
];

const MANUAL_PAYMENT_ATTACHMENT_MAX_BYTES = 3145728;

function manual_payments_available(?bool $set = null): bool {
    static $available = null;
    if ($set !== null) {
        return $available = $set;
    }
    if ($available === null) {
        try {
            db()->query('SELECT 1 FROM ellsms_manual_payments LIMIT 0');
            $available = true;
        } catch (PDOException) {
            $available = false;
        }
    }
    return $available;
}

function manual_payment_method_label(string $method): string {
    return MANUAL_PAYMENT_METHODS[$method] ?? ($method === 'zarinpal' ? 'پرداخت آنلاین (زرین‌پال)' : ($method === 'fake' ? 'پرداخت آنلاین (آزمایشی)' : $method));
}

function manual_payment_is_manual_gateway(?string $gateway): bool {
    return $gateway !== null && isset(MANUAL_PAYMENT_METHODS[$gateway]);
}

function manual_payment_bank_label(?string $bank): string {
    return MANUAL_PAYMENT_BANKS[(string)$bank] ?? (string)$bank;
}

/* ==========================================================================
   Validation helpers (shape and check digits only — nothing is looked up at a bank)
   ========================================================================== */

/** ASCII digits only (Persian/Arabic digits converted, spaces and dashes dropped). */
function manual_payment_digits(string $raw): string {
    return preg_replace('/\D+/', '', from_persian_digits($raw)) ?? '';
}

/** A 16-digit card number that passes the Luhn check, or ''. */
function manual_payment_normalize_card(string $raw): string {
    $digits = manual_payment_digits($raw);
    if (strlen($digits) !== 16) return '';
    $sum = 0;
    for ($i = 0; $i < 16; $i++) {
        $d = (int)$digits[$i];
        if ($i % 2 === 0) {
            $d *= 2;
            if ($d > 9) $d -= 9;
        }
        $sum += $d;
    }
    return $sum % 10 === 0 ? $digits : '';
}

/** An Iranian IBAN (شبا): IR + 24 digits with a valid ISO 13616 mod-97 check, as 'IR…', or ''. */
function manual_payment_normalize_sheba(string $raw): string {
    $digits = manual_payment_digits($raw);
    if (strlen($digits) !== 24) return '';
    // Move "IR" + check digits to the end; I = 18, R = 27.
    $rearranged = substr($digits, 2) . '1827' . substr($digits, 0, 2);
    $mod = 0;
    foreach (str_split($rearranged, 7) as $chunk) {
        $mod = (int)(($mod . $chunk) % 97);
    }
    return $mod === 1 ? 'IR' . $digits : '';
}

/** A bank account number: digits with optional dashes or dots, 5–30 characters, or ''. */
function manual_payment_normalize_account(string $raw): string {
    $value = trim(from_persian_digits($raw));
    return preg_match('/^\d[\d.\-]{3,28}\d$/', $value) ? $value : '';
}

function manual_payment_format_card(string $card): string {
    return strlen($card) === 16 ? implode('-', str_split($card, 4)) : $card;
}

/** How a destination is shown to a customer: "کارت ۶۰۳۷-…-۱۲۳۴ — بانک ملی — علی رضایی". */
function manual_payment_destination_label(array $destination): string {
    $parts = [];
    if ($destination['kind'] === 'card') {
        $parts[] = manual_payment_format_card((string)$destination['card_number']);
    } else {
        $parts[] = (string)$destination['account_number'];
        if (!empty($destination['sheba'])) $parts[] = (string)$destination['sheba'];
    }
    if ((string)$destination['bank'] !== '') $parts[] = 'بانک ' . manual_payment_bank_label((string)$destination['bank']);
    $parts[] = (string)$destination['holder_name'];
    return implode(' — ', $parts);
}

/* ==========================================================================
   Configuration: accepted methods and destinations (owner 0 = the platform, else a reseller)
   ========================================================================== */

/** @return array<string,bool> method => enabled, for every manual method */
function manual_payment_methods_enabled(int $ownerId): array {
    $out = array_fill_keys(array_keys(MANUAL_PAYMENT_METHODS), false);
    if (!manual_payments_available()) return $out;
    $st = db()->prepare('SELECT method, enabled FROM ellsms_payment_method_settings WHERE owner_user_id = ?');
    $st->execute([$ownerId]);
    foreach ($st->fetchAll() as $row) {
        if (isset($out[$row['method']])) $out[$row['method']] = (int)$row['enabled'] === 1;
    }
    return $out;
}

/** @param list<string> $enabledMethods */
function manual_payment_methods_save(int $ownerId, array $enabledMethods, int $actorId): void {
    if (!manual_payments_available()) return;
    $st = db()->prepare('INSERT INTO ellsms_payment_method_settings (owner_user_id, method, enabled, updated_by) VALUES (?,?,?,?)
                         ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), updated_by = VALUES(updated_by)');
    $on = [];
    foreach (array_keys(MANUAL_PAYMENT_METHODS) as $method) {
        $enabled = in_array($method, $enabledMethods, true);
        if ($enabled) $on[] = $method;
        $st->execute([$ownerId, $method, $enabled ? 1 : 0, $actorId]);
    }
    audit($actorId, 'payment_methods.saved', "owner=#{$ownerId} enabled=" . implode(',', $on));
}

/** @return list<array> the owner's destinations (not deleted), optionally of one kind and only active ones */
function manual_payment_destinations(int $ownerId, ?string $kind = null, bool $activeOnly = false): array {
    if (!manual_payments_available()) return [];
    $sql = 'SELECT * FROM ellsms_payment_destinations WHERE owner_user_id = ? AND deleted = 0';
    $params = [$ownerId];
    if ($kind !== null) {
        $sql .= ' AND kind = ?';
        $params[] = $kind;
    }
    if ($activeOnly) $sql .= ' AND active = 1';
    $st = db()->prepare($sql . ' ORDER BY sort_order, id');
    $st->execute($params);
    return $st->fetchAll();
}

function manual_payment_destination(int $id): ?array {
    if ($id <= 0 || !manual_payments_available()) return null;
    $st = db()->prepare('SELECT * FROM ellsms_payment_destinations WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/**
 * Adds ($id null) or edits one of the owner's cards/accounts.
 *
 * @return array{ok:bool, reason?:string, id?:int}
 */
function manual_payment_destination_save(int $ownerId, ?int $id, array $input, int $actorId): array {
    if (!manual_payments_available()) return ['ok' => false, 'reason' => 'not_migrated'];
    $existing = null;
    if ($id !== null) {
        $existing = manual_payment_destination($id);
        if ($existing === null || (int)$existing['owner_user_id'] !== $ownerId || (int)$existing['deleted'] === 1) {
            return ['ok' => false, 'reason' => 'destination_not_found'];
        }
    }
    $kind = $existing['kind'] ?? (string)($input['kind'] ?? '');
    if (!in_array($kind, ['card', 'account'], true)) return ['ok' => false, 'reason' => 'destination_kind'];
    $holder = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($input['holder_name'] ?? '')) ?? ''), 0, 120);
    if (mb_strlen($holder) < 2) return ['ok' => false, 'reason' => 'holder_name'];
    $bank = (string)($input['bank'] ?? '');
    if (!isset(MANUAL_PAYMENT_BANKS[$bank])) return ['ok' => false, 'reason' => 'bank'];

    $card = $account = $sheba = null;
    if ($kind === 'card') {
        $card = manual_payment_normalize_card((string)($input['card_number'] ?? ''));
        if ($card === '') return ['ok' => false, 'reason' => 'card_number'];
    } else {
        $account = manual_payment_normalize_account((string)($input['account_number'] ?? ''));
        if ($account === '') return ['ok' => false, 'reason' => 'account_number'];
        $shebaRaw = trim((string)($input['sheba'] ?? ''));
        if ($shebaRaw !== '' && $shebaRaw !== 'IR') {
            $sheba = manual_payment_normalize_sheba($shebaRaw);
            if ($sheba === '') return ['ok' => false, 'reason' => 'sheba'];
        }
    }
    $active = !empty($input['active']) ? 1 : 0;
    $sort = max(-1000, min(1000, (int)($input['sort_order'] ?? 0)));

    if ($existing === null) {
        db()->prepare('INSERT INTO ellsms_payment_destinations (owner_user_id, kind, bank, holder_name, card_number, account_number, sheba, active, sort_order, updated_by)
                       VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$ownerId, $kind, $bank, $holder, $card, $account, $sheba, $active, $sort, $actorId]);
        $id = (int)db()->lastInsertId();
        audit($actorId, 'payment_destination.created', "owner=#{$ownerId} destination=#{$id} kind={$kind}");
    } else {
        db()->prepare('UPDATE ellsms_payment_destinations SET bank = ?, holder_name = ?, card_number = ?, account_number = ?, sheba = ?, active = ?, sort_order = ?, updated_by = ? WHERE id = ?')
            ->execute([$bank, $holder, $card, $account, $sheba, $active, $sort, $actorId, $id]);
        audit($actorId, 'payment_destination.updated', "owner=#{$ownerId} destination=#{$id}");
    }
    return ['ok' => true, 'id' => (int)$id];
}

/** Soft delete: payments already made into it still show where they went. */
function manual_payment_destination_delete(int $ownerId, int $id, int $actorId): bool {
    if (!manual_payments_available()) return false;
    $st = db()->prepare('UPDATE ellsms_payment_destinations SET deleted = 1, active = 0, updated_by = ? WHERE id = ? AND owner_user_id = ? AND deleted = 0');
    $st->execute([$actorId, $id, $ownerId]);
    if ($st->rowCount() === 0) return false;
    audit($actorId, 'payment_destination.deleted', "owner=#{$ownerId} destination=#{$id}");
    return true;
}

/** Whether $ownerId can take $method right now: enabled, and (card/account/cheque) with an active destination to pay into. */
function manual_payment_owner_accepts(int $ownerId, string $method, ?array $enabled = null): bool {
    if (!isset(MANUAL_PAYMENT_METHODS[$method])) return false;
    $enabled ??= manual_payment_methods_enabled($ownerId);
    if (!$enabled[$method]) return false;
    $kind = MANUAL_PAYMENT_DESTINATION_KIND[$method] ?? null;
    return $kind === null || manual_payment_destinations($ownerId, $kind, true) !== [];
}

/**
 * The manual methods $userId may use and who collects each:
 * method => ['collector' => 0|reseller id, 'destinations' => list<array>].
 * A method the customer's nearest reseller accepts goes to that reseller; otherwise to the platform.
 */
function manual_payment_options_for(int $userId): array {
    if (!manual_payments_available()) return [];
    $reseller = null;
    if (function_exists('reseller_panel_available') && reseller_panel_available()) {
        $reseller = reseller_nearest_ancestor($userId);
    }
    $resellerEnabled = $reseller !== null ? manual_payment_methods_enabled($reseller) : null;
    $platformEnabled = manual_payment_methods_enabled(0);
    $out = [];
    foreach (array_keys(MANUAL_PAYMENT_METHODS) as $method) {
        $collector = null;
        if ($reseller !== null && manual_payment_owner_accepts($reseller, $method, $resellerEnabled)) {
            $collector = $reseller;
        } elseif (manual_payment_owner_accepts(0, $method, $platformEnabled)) {
            $collector = 0;
        }
        if ($collector === null) continue;
        $kind = MANUAL_PAYMENT_DESTINATION_KIND[$method] ?? null;
        $out[$method] = [
            'collector'    => $collector,
            'destinations' => $kind !== null ? manual_payment_destinations($collector, $kind, true) : [],
        ];
    }
    return $out;
}

/* ==========================================================================
   Receipt / cheque images
   ========================================================================== */

function manual_payment_attachment_dir(): string {
    return dirname(__DIR__) . '/storage/payment-receipts';
}

/**
 * Stores an uploaded receipt image (JPEG, PNG or WebP, a real image, at most 3 MB) and returns its
 * generated file name. Nothing about the name comes from the upload.
 *
 * @return array{ok:bool, reason?:string, file?:string}
 */
function manual_payment_attachment_store(array $file): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name'] ?? ''))) {
        return ['ok' => false, 'reason' => 'attachment_upload'];
    }
    return manual_payment_attachment_store_path((string)$file['tmp_name'], (int)($file['size'] ?? 0));
}

/** manual_payment_attachment_store() for a file already on disk (also what the tests call). */
function manual_payment_attachment_store_path(string $path, int $size): array {
    if ($size <= 0 || $size > MANUAL_PAYMENT_ATTACHMENT_MAX_BYTES) return ['ok' => false, 'reason' => 'attachment_size'];
    $info = @getimagesize($path);
    $ext = match ($info[2] ?? null) {
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_WEBP => 'webp',
        default        => null,
    };
    if ($ext === null) return ['ok' => false, 'reason' => 'attachment_type'];
    $dir = manual_payment_attachment_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) return ['ok' => false, 'reason' => 'storage'];
    $name = 'r' . bin2hex(random_bytes(12)) . '.' . $ext;
    if (!@copy($path, $dir . '/' . $name)) return ['ok' => false, 'reason' => 'storage'];
    return ['ok' => true, 'file' => $name];
}

function manual_payment_attachment_path(?string $file): ?string {
    if ($file === null || !preg_match('/^r[0-9a-f]{24}\.(jpg|png|webp)$/', $file)) return null;
    $path = manual_payment_attachment_dir() . '/' . $file;
    return is_file($path) ? $path : null;
}

/* ==========================================================================
   Submitting a manual payment (customer)
   ========================================================================== */

/**
 * Reads and validates the method-specific fields of the buy-credit form. $totalRial is the amount
 * payable (the invoice total) — a cheque must be written for exactly that.
 *
 * @return array{ok:bool, reason?:string, data?:array}
 */
function manual_payment_validate_input(string $method, array $option, array $input, int $totalRial): array {
    $data = ['destination_id' => null, 'destination_label' => null];
    $kind = MANUAL_PAYMENT_DESTINATION_KIND[$method] ?? null;
    if ($kind !== null) {
        $destinationId = (int)($input['destination_id'] ?? 0);
        $destination = null;
        foreach ($option['destinations'] as $candidate) {
            if ((int)$candidate['id'] === $destinationId) $destination = $candidate;
        }
        if ($destination === null) return ['ok' => false, 'reason' => 'destination'];
        $data['destination_id'] = (int)$destination['id'];
        $data['destination_label'] = mb_substr(manual_payment_destination_label($destination), 0, 255);
    }

    $document = mb_substr(trim(from_persian_digits((string)($input['document_number'] ?? ''))), 0, 64);
    if ($document === '') return ['ok' => false, 'reason' => 'document_number'];
    $data['document_number'] = $document;

    $paidOn = (string)($input['paid_on'] ?? '');
    $paidTime = trim(from_persian_digits((string)($input['paid_time'] ?? '')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $paidOn)) return ['ok' => false, 'reason' => 'paid_on'];
    if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $paidTime)) return ['ok' => false, 'reason' => 'paid_time'];
    $paidAt = $paidOn . ' ' . $paidTime . ':00';
    if (strtotime($paidAt) === false || strtotime($paidAt) > time() + 300) return ['ok' => false, 'reason' => 'paid_in_future'];
    if (strtotime($paidAt) < time() - 365 * 86400) return ['ok' => false, 'reason' => 'paid_too_old'];
    $data['paid_at'] = $paidAt;

    $description = mb_substr(trim(strip_tags((string)($input['description'] ?? ''))), 0, 500);
    $data['description'] = $description !== '' ? $description : null;

    if ($method === 'cheque') {
        $chequeAmount = (int)manual_payment_digits((string)($input['cheque_amount'] ?? ''));
        if ($chequeAmount !== $totalRial) return ['ok' => false, 'reason' => 'cheque_amount'];
        $bank = (string)($input['cheque_bank'] ?? '');
        if (!isset(MANUAL_PAYMENT_BANKS[$bank])) return ['ok' => false, 'reason' => 'cheque_bank'];
        $branch = manual_payment_digits((string)($input['cheque_branch_code'] ?? ''));
        if ($branch === '' || strlen($branch) > 10) return ['ok' => false, 'reason' => 'cheque_branch_code'];
        $owner = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($input['cheque_owner_name'] ?? '')) ?? ''), 0, 120);
        if (mb_strlen($owner) < 2) return ['ok' => false, 'reason' => 'cheque_owner_name'];
        $sheba = manual_payment_normalize_sheba((string)($input['cheque_owner_sheba'] ?? ''));
        if ($sheba === '') return ['ok' => false, 'reason' => 'cheque_owner_sheba'];
        $nationalId = manual_payment_digits((string)($input['cheque_owner_national_id'] ?? ''));
        if (!in_array(strlen($nationalId), [10, 11], true)) return ['ok' => false, 'reason' => 'cheque_owner_national_id'];
        $sayad = manual_payment_digits((string)($input['cheque_sayad_number'] ?? ''));
        if (strlen($sayad) !== 16) return ['ok' => false, 'reason' => 'cheque_sayad_number'];
        $serial = manual_payment_digits((string)($input['cheque_serial'] ?? ''));
        if ($serial === '' || strlen($serial) > 20) return ['ok' => false, 'reason' => 'cheque_serial'];
        $series = mb_substr(trim(from_persian_digits((string)($input['cheque_series'] ?? ''))), 0, 20);
        if ($series === '') return ['ok' => false, 'reason' => 'cheque_series'];
        $dueOn = (string)($input['cheque_due_on'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueOn)) return ['ok' => false, 'reason' => 'cheque_due_on'];
        $data += [
            'cheque_amount' => $chequeAmount, 'cheque_bank' => $bank, 'cheque_branch_code' => $branch,
            'cheque_owner_name' => $owner, 'cheque_owner_sheba' => $sheba, 'cheque_owner_national_id' => $nationalId,
            'cheque_sayad_number' => $sayad, 'cheque_serial' => $serial, 'cheque_series' => $series, 'cheque_due_on' => $dueOn,
        ];
    }
    return ['ok' => true, 'data' => $data];
}

/**
 * Records a customer's manual payment: payment + invoice + manual row, in one transaction, then
 * tells whoever reviews it. $credits is the Rial added to the wallet on approval (before VAT); the
 * customer pays the invoice total. $attachment is the $_FILES entry of the receipt / cheque image
 * (required for a cheque, optional for card and account, not taken for cash).
 *
 * @return array{ok:bool, reason?:string, invoice_id?:int, manual_id?:int, total?:int}
 */
function manual_payment_submit(array $user, string $method, int $credits, array $input, ?array $attachment): array {
    if (!manual_payments_available()) return ['ok' => false, 'reason' => 'not_migrated'];
    $userId = (int)$user['id'];
    $options = manual_payment_options_for($userId);
    if (!isset($options[$method])) return ['ok' => false, 'reason' => 'method_unavailable'];
    $option = $options[$method];
    if ($credits <= 0) return ['ok' => false, 'reason' => 'amount'];

    $taxPercent = billing_tax_percent();
    $total = $credits + billing_calculate_tax($credits, $taxPercent);
    $validated = manual_payment_validate_input($method, $option, $input, $total);
    if (!$validated['ok']) return $validated;
    $data = $validated['data'];

    // The same document number reported twice to the same collector for the same method is almost
    // always a double submission; refuse it unless the earlier one was rejected.
    $dup = db()->prepare("SELECT 1 FROM ellsms_manual_payments WHERE collector_user_id = ? AND method = ? AND document_number = ? AND review_state <> 'rejected' LIMIT 1");
    $dup->execute([$option['collector'], $method, $data['document_number']]);
    if ($dup->fetchColumn()) return ['ok' => false, 'reason' => 'duplicate_document'];

    $file = null;
    $hasUpload = $attachment !== null && ($attachment['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    if ($method !== 'cash' && $hasUpload) {
        $stored = manual_payment_attachment_store($attachment);
        if (!$stored['ok']) return $stored;
        $file = $stored['file'];
    }
    if ($method === 'cheque' && $file === null) return ['ok' => false, 'reason' => 'attachment_required'];

    $organizationId = isset($user['organization_id']) && $user['organization_id'] !== null ? (int)$user['organization_id'] : null;
    try {
        $result = db_transaction(function (PDO $db) use ($userId, $organizationId, $method, $credits, $option, $data, $file): array {
            $db->prepare('INSERT INTO ellsms_payments (user_id, organization_id, credits, amount_rial, gateway) VALUES (?,?,?,?,?)')
               ->execute([$userId, $organizationId, $credits, $credits, $method]);
            $paymentId = (int)$db->lastInsertId();
            $invoice = billing_invoice_create($paymentId, $organizationId, $userId, 'credit', [[
                'item_type' => 'sms_credit', 'reference_code' => null,
                'description' => 'خرید ' . number_format($credits) . ' ریال اعتبار پیامک (' . MANUAL_PAYMENT_METHODS[$method] . ')',
                'quantity' => 1, 'unit_price' => $credits,
            ]]);
            if (!$invoice['ok']) {
                throw new RuntimeException('manual_payment_invoice_failed:' . ($invoice['reason'] ?? ''));
            }
            $total = (int)$invoice['total_amount'];
            $db->prepare('UPDATE ellsms_payments SET amount_rial = ? WHERE id = ?')->execute([$total, $paymentId]);

            $columns = [
                'payment_id' => $paymentId, 'user_id' => $userId, 'organization_id' => $organizationId, 'method' => $method,
                'collector_user_id' => $option['collector'], 'credits' => $credits, 'amount_rial' => $total, 'attachment_file' => $file,
            ] + $data;
            $db->prepare('INSERT INTO ellsms_manual_payments (' . implode(',', array_keys($columns)) . ') VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')')
               ->execute(array_values($columns));
            return ['ok' => true, 'manual_id' => (int)$db->lastInsertId(), 'invoice_id' => (int)$invoice['invoice_id'],
                    'invoice_number' => (string)$invoice['invoice_number'], 'total' => $total, 'payment_id' => $paymentId];
        });
    } catch (Throwable $e) {
        if ($file !== null) @unlink(manual_payment_attachment_dir() . '/' . $file);
        Logger::error('payment.manual.submit_failed', ['user_id' => $userId, 'method' => $method, 'exception' => $e]);
        return ['ok' => false, 'reason' => 'storage'];
    }

    audit($userId, 'payment.manual_submitted', "payment=#{$result['payment_id']} invoice={$result['invoice_number']} method={$method} collector=#{$option['collector']} total={$result['total']}rial");
    Metrics::increment('billing.manual_payment.submitted', 1, ['method' => $method]);
    $title = 'پرداخت جدید برای بررسی';
    $body = MANUAL_PAYMENT_METHODS[$method] . ' — ' . number_format($result['total']) . ' ریال، فاکتور ' . $result['invoice_number'];
    try {
        if ($option['collector'] === 0) {
            notification_dispatch_admins('payment.manual_submitted', $title, $body, '/admin/billing/manual-payments', 'info');
        } else {
            notification_insert_panel($option['collector'], null, 'payment.manual_submitted', $title, $body, '/reseller/payments', 'info');
        }
    } catch (Throwable $e) {
        Logger::error('payment.manual.notify_failed', ['manual_id' => $result['manual_id'], 'exception' => $e]);
    }
    return $result;
}

/* ==========================================================================
   Reading
   ========================================================================== */

function manual_payment_get(int $id): ?array {
    if ($id <= 0 || !manual_payments_available()) return null;
    $st = db()->prepare('SELECT * FROM ellsms_manual_payments WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function manual_payment_for_payment(int $paymentId): ?array {
    return manual_payments_for_payments([$paymentId])[$paymentId] ?? null;
}

/** @return array<int,array> payment id => its manual row, for the given payment ids */
function manual_payments_for_payments(array $paymentIds): array {
    $paymentIds = array_values(array_unique(array_filter(array_map('intval', $paymentIds))));
    if ($paymentIds === [] || !manual_payments_available()) return [];
    $st = db()->prepare('SELECT * FROM ellsms_manual_payments WHERE payment_id IN (' . implode(',', array_fill(0, count($paymentIds), '?')) . ')');
    $st->execute($paymentIds);
    $out = [];
    foreach ($st->fetchAll() as $row) $out[(int)$row['payment_id']] = $row;
    return $out;
}

/** Filters for the review lists: collector (an owner id, 'resellers' = any reseller, or null = all — the last two for platform admins only), state, method, user_id. */
function manual_payments_where(array $filters): array {
    $where = [];
    $params = [];
    if (($filters['collector'] ?? null) === 'resellers') {
        $where[] = 'collector_user_id > 0';
    } elseif (array_key_exists('collector', $filters) && $filters['collector'] !== null) {
        $where[] = 'collector_user_id = ?';
        $params[] = (int)$filters['collector'];
    }
    if (!empty($filters['state']) && isset(MANUAL_PAYMENT_REVIEW_STATES[$filters['state']])) {
        $where[] = 'review_state = ?';
        $params[] = $filters['state'];
    }
    if (!empty($filters['method']) && isset(MANUAL_PAYMENT_METHODS[$filters['method']])) {
        $where[] = 'method = ?';
        $params[] = $filters['method'];
    }
    if (!empty($filters['user_id'])) {
        $where[] = 'user_id = ?';
        $params[] = (int)$filters['user_id'];
    }
    return [$where ? ' WHERE ' . implode(' AND ', $where) : '', $params];
}

function manual_payments_count(array $filters): int {
    if (!manual_payments_available()) return 0;
    [$whereSql, $params] = manual_payments_where($filters);
    $st = db()->prepare('SELECT COUNT(*) FROM ellsms_manual_payments' . $whereSql);
    $st->execute($params);
    return (int)$st->fetchColumn();
}

/** @return list<array> newest first, each with its invoice's number and id */
function manual_payments_list(array $filters, int $limit = 30, int $offset = 0): array {
    if (!manual_payments_available()) return [];
    [$whereSql, $params] = manual_payments_where($filters);
    $limit = max(1, min(200, $limit));
    $st = db()->prepare('SELECT m.*, i.id AS invoice_id, i.invoice_number, i.status AS invoice_status
                         FROM (SELECT * FROM ellsms_manual_payments' . $whereSql . ' ORDER BY id DESC LIMIT ' . $limit . ' OFFSET ' . max(0, $offset) . ') m
                         LEFT JOIN ellsms_invoices i ON i.payment_id = m.payment_id
                         ORDER BY m.id DESC');
    $st->execute($params);
    return $st->fetchAll();
}

function manual_payments_pending_count(?int $collector): int {
    return manual_payments_count(['collector' => $collector, 'state' => 'pending']);
}

/**
 * The status a customer and an admin should read for an invoice: a manual payment waiting for its
 * review reads «در انتظار بررسی», a rejected one «ردشده», everything else the invoice's own status.
 *
 * @return array{0:string,1:string} [label, badge class suffix: ok|pending|off|review]
 */
function manual_payment_invoice_status(array $invoice, ?array $manual): array {
    $status = (string)($invoice['status'] ?? '');
    if ($manual !== null && $status === 'issued' && $manual['review_state'] === 'pending') return ['در انتظار بررسی', 'review'];
    if ($manual !== null && $manual['review_state'] === 'rejected') return ['ردشده', 'off'];
    $labels = ['issued' => 'پرداخت‌نشده', 'paid' => 'پرداخت‌شده', 'cancelled' => 'لغوشده', 'expired' => 'منقضی', 'refunded' => 'بازگشت‌داده‌شده'];
    return [$labels[$status] ?? $status, $status === 'paid' ? 'ok' : ($status === 'issued' ? 'pending' : 'off')];
}

/* ==========================================================================
   Review (platform admin for collector 0, the reseller for its own)
   ========================================================================== */

final class ManualPaymentRefused extends RuntimeException {}

/**
 * Approves or rejects a pending manual payment. $collector is who is reviewing: 0 = a platform
 * admin, else the reseller — it may only review payments made to it. A rejection needs a note.
 *
 * Approval, in one transaction: lock the manual row, claim the payment (pending -> paid), mark the
 * invoice paid, then credit the customer — from nowhere for the platform (a purchase), from the
 * reseller's wallet for a reseller. A short reseller balance rolls all of it back.
 *
 * @return array{ok:bool, reason:string}
 */
function manual_payment_review(int $manualId, string $decision, int $collector, int $actorId, string $note = ''): array {
    if (!manual_payments_available()) return ['ok' => false, 'reason' => 'not_migrated'];
    if (!in_array($decision, ['approve', 'reject'], true)) return ['ok' => false, 'reason' => 'invalid_request'];
    $note = mb_substr(trim($note), 0, 500);
    if ($decision === 'reject' && $note === '') return ['ok' => false, 'reason' => 'note_required'];

    try {
        $manual = db_transaction(function (PDO $db) use ($manualId, $decision, $collector, $actorId, $note): array {
            $st = $db->prepare('SELECT * FROM ellsms_manual_payments WHERE id = ? FOR UPDATE');
            $st->execute([$manualId]);
            $manual = $st->fetch();
            if (!$manual || (int)$manual['collector_user_id'] !== $collector) throw new ManualPaymentRefused('not_found');
            if ($manual['review_state'] !== 'pending') throw new ManualPaymentRefused('already_reviewed');
            $pst = $db->prepare('SELECT * FROM ellsms_payments WHERE id = ? FOR UPDATE');
            $pst->execute([(int)$manual['payment_id']]);
            $payment = $pst->fetch();
            if (!$payment || $payment['status'] !== 'pending') throw new ManualPaymentRefused('payment_state_invalid');

            if ($decision === 'reject') {
                $db->prepare("UPDATE ellsms_payments SET status = 'failed' WHERE id = ? AND status = 'pending'")->execute([(int)$payment['id']]);
                if (!empty($payment['invoice_id'])) {
                    $db->prepare("UPDATE ellsms_invoices SET status = 'cancelled', cancelled_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'issued'")
                       ->execute([(int)$payment['invoice_id']]);
                }
            } else {
                // The credit moves first: a refused transfer (the reseller's balance is short) then
                // throws before anything was written, even when this runs inside a caller's
                // transaction. The payment row is locked above, so the claim below cannot miss.
                $meta = ['manual_payment_id' => (int)$manual['id'], 'method' => $manual['method'], 'document_number' => $manual['document_number']];
                if ($collector === 0) {
                    $credit = wallet_credit((int)$manual['user_id'], (int)$manual['credits'], 'purchase', 'payment', (string)$payment['id'],
                        'payment_credit:' . $payment['id'], $actorId, $meta);
                } else {
                    $credit = wallet_transfer($collector, (int)$manual['user_id'], (int)$manual['credits'], 'manual_payment', (string)$manual['id'],
                        'manual_payment:' . $manual['id'], $actorId, $meta);
                }
                if (!($credit['ok'] ?? false)) throw new ManualPaymentRefused((string)($credit['reason'] ?? 'credit_failed'));

                $ref = 'MANUAL-' . strtoupper((string)$manual['method']) . '-' . $manual['id'];
                $db->prepare("UPDATE ellsms_payments SET status = 'paid', ref_id = ? WHERE id = ? AND status = 'pending'")->execute([$ref, (int)$payment['id']]);
                if (!empty($payment['invoice_id'])) billing_invoice_mark_paid($db, (int)$payment['invoice_id']);
            }

            $db->prepare('UPDATE ellsms_manual_payments SET review_state = ?, review_note = ?, reviewed_by = ?, reviewed_at = UTC_TIMESTAMP() WHERE id = ?')
               ->execute([$decision === 'approve' ? 'approved' : 'rejected', $note !== '' ? $note : null, $actorId, $manualId]);
            return $manual;
        });
    } catch (ManualPaymentRefused $e) {
        return ['ok' => false, 'reason' => $e->getMessage()];
    }

    $approved = $decision === 'approve';
    audit($actorId, $approved ? 'payment.manual_approved' : 'payment.manual_rejected',
        "manual=#{$manualId} payment=#{$manual['payment_id']} method={$manual['method']} collector=#{$collector} credits={$manual['credits']}" . ($note !== '' ? " note={$note}" : ''));
    Metrics::increment('billing.manual_payment.reviewed', 1, ['decision' => $decision, 'method' => (string)$manual['method']]);

    $invoiceSt = db()->prepare('SELECT id, invoice_number FROM ellsms_invoices WHERE payment_id = ?');
    $invoiceSt->execute([(int)$manual['payment_id']]);
    $invoice = $invoiceSt->fetch() ?: null;
    $organizationId = $manual['organization_id'] !== null ? (int)$manual['organization_id'] : null;
    try {
        notification_insert_panel(
            (int)$manual['user_id'], $organizationId,
            $approved ? 'payment.success' : 'payment.failed',
            $approved ? 'پرداخت شما تأیید شد' : 'پرداخت شما رد شد',
            $approved
                ? number_format((int)$manual['credits']) . ' ریال به اعتبار شما اضافه شد (' . MANUAL_PAYMENT_METHODS[$manual['method']] . ').'
                : 'پرداخت ' . MANUAL_PAYMENT_METHODS[$manual['method']] . ' با شماره سند ' . $manual['document_number'] . ' رد شد. دلیل: ' . $note,
            $invoice ? '/billing/invoices?id=' . (int)$invoice['id'] : '/billing/credit',
            $approved ? 'success' : 'warning'
        );
    } catch (Throwable $e) {
        Logger::error('payment.manual.notify_failed', ['manual_id' => $manualId, 'exception' => $e]);
    }
    if ($approved && $organizationId !== null) {
        try {
            webhook_event_emit($organizationId, WebhookEvents::PAYMENT_CREDITED, 'payment', (string)$manual['payment_id'], [
                'payment_id' => (int)$manual['payment_id'], 'user_id' => (int)$manual['user_id'],
                'credits' => (int)$manual['credits'], 'ref_id' => 'MANUAL-' . strtoupper((string)$manual['method']) . '-' . $manualId,
            ]);
        } catch (Throwable $e) {
            Logger::error('webhook.event.emit_failed', ['payment_id' => $manual['payment_id'], 'exception' => $e]);
        }
    }
    return ['ok' => true, 'reason' => $approved ? 'approved' : 'rejected'];
}

/* ==========================================================================
   The review / settings page, shared by /admin/billing/manual-payments and /reseller/payments
   ========================================================================== */

/** Persian message for a refusal reason from this file. */
function manual_payment_error_message(string $reason): string {
    return [
        'not_migrated'             => 'روش‌های پرداخت دستی هنوز نصب نشده‌اند (مهاجرت 2026_10_25_manual_payments.sql).',
        'method_unavailable'       => 'این روش پرداخت در حال حاضر فعال نیست.',
        'amount'                   => 'مبلغ معتبر نیست.',
        'destination'              => 'کارت یا حساب مقصد را انتخاب کنید.',
        'document_number'          => 'شماره سند / پیگیری را وارد کنید.',
        'paid_on'                  => 'تاریخ پرداخت معتبر نیست.',
        'paid_time'                => 'ساعت پرداخت را به شکل ۱۴:۳۰ وارد کنید.',
        'paid_in_future'           => 'تاریخ و ساعت پرداخت نمی‌تواند در آینده باشد.',
        'paid_too_old'             => 'تاریخ پرداخت بیش از یک سال پیش است.',
        'cheque_amount'            => 'مبلغ چک باید دقیقاً برابر مبلغ قابل پرداخت (با ارزش افزوده) باشد.',
        'cheque_bank'              => 'بانک چک را انتخاب کنید.',
        'cheque_branch_code'       => 'کد شعبه را وارد کنید.',
        'cheque_owner_name'        => 'نام صاحب حساب را وارد کنید.',
        'cheque_owner_sheba'       => 'شماره شبای صاحب چک معتبر نیست.',
        'cheque_owner_national_id' => 'کد ملی (۱۰ رقم) یا شناسه ملی (۱۱ رقم) صاحب چک را وارد کنید.',
        'cheque_sayad_number'      => 'شماره صیادی باید ۱۶ رقم باشد.',
        'cheque_serial'            => 'شماره سریال چک را وارد کنید.',
        'cheque_series'            => 'شماره سری چک را وارد کنید.',
        'cheque_due_on'            => 'تاریخ سررسید چک را انتخاب کنید.',
        'attachment_required'      => 'تصویر چک را بارگذاری کنید.',
        'attachment_upload'        => 'بارگذاری تصویر ناموفق بود.',
        'attachment_size'          => 'حجم تصویر حداکثر ۳ مگابایت است.',
        'attachment_type'          => 'تصویر باید JPG، PNG یا WebP باشد.',
        'duplicate_document'       => 'پرداختی با همین شماره سند قبلاً ثبت شده است.',
        'storage'                  => 'ثبت پرداخت ممکن نشد. دوباره تلاش کنید.',
        'note_required'            => 'برای رد پرداخت، ذکر دلیل الزامی است.',
        'not_found'                => 'پرداخت یافت نشد یا بررسی آن با شما نیست.',
        'already_reviewed'         => 'این پرداخت قبلاً بررسی شده است.',
        'payment_state_invalid'    => 'وضعیت پرداخت اجازه‌ی این کار را نمی‌دهد.',
        'insufficient_balance'     => 'اعتبار کیف پول نماینده برای انتقال این مبلغ به مشتری کافی نیست. ابتدا حساب خود را شارژ کنید.',
        'destination_not_found'    => 'کارت/حساب یافت نشد.',
        'destination_kind'         => 'نوع (کارت یا حساب) را انتخاب کنید.',
        'holder_name'              => 'نام صاحب کارت/حساب را وارد کنید.',
        'bank'                     => 'بانک را انتخاب کنید.',
        'card_number'              => 'شماره کارت ۱۶ رقمی معتبر نیست.',
        'account_number'           => 'شماره حساب معتبر نیست.',
        'sheba'                    => 'شماره شبا معتبر نیست (IR و ۲۴ رقم).',
    ][$reason] ?? ('عملیات ممکن نشد (' . $reason . ').');
}

/**
 * Handles the POST actions of the review/settings page for $ownerId (0 = platform). The caller has
 * already checked who may act for $ownerId (and CSRF). Redirects to $back.
 */
function manual_payments_handle_post(int $ownerId, int $actorId, string $back): never {
    $do = (string)($_POST['do'] ?? '');
    if ($do === 'review') {
        $result = manual_payment_review((int)($_POST['manual_id'] ?? 0), (string)($_POST['decision'] ?? ''), $ownerId, $actorId, (string)($_POST['note'] ?? ''));
        flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? ($result['reason'] === 'approved' ? 'پرداخت تأیید شد و اعتبار به حساب مشتری اضافه شد.' : 'پرداخت رد شد و به مشتری اطلاع داده شد.')
            : manual_payment_error_message($result['reason']));
    } elseif ($do === 'methods') {
        $methods = array_values(array_intersect(array_keys(MANUAL_PAYMENT_METHODS), (array)($_POST['methods'] ?? [])));
        manual_payment_methods_save($ownerId, $methods, $actorId);
        flash('success', 'روش‌های پرداخت ذخیره شد.');
        $back .= (str_contains($back, '?') ? '&' : '?') . 'tab=settings';
    } elseif ($do === 'destination_save') {
        $id = (int)($_POST['destination_id'] ?? 0);
        $result = manual_payment_destination_save($ownerId, $id > 0 ? $id : null, $_POST, $actorId);
        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'کارت/حساب ذخیره شد.' : manual_payment_error_message($result['reason']));
        $back .= (str_contains($back, '?') ? '&' : '?') . 'tab=settings';
    } elseif ($do === 'destination_delete') {
        $ok = manual_payment_destination_delete($ownerId, (int)($_POST['destination_id'] ?? 0), $actorId);
        flash($ok ? 'info' : 'error', $ok ? 'کارت/حساب حذف شد.' : 'کارت/حساب یافت نشد.');
        $back .= (str_contains($back, '?') ? '&' : '?') . 'tab=settings';
    }
    redirect($back);
}
