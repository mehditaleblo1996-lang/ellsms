<?php
/**
 * ELLSMS — Saman Electronic Payment (SEP / پرداخت الکترونیک سامان), OnlinePG token API.
 *
 * Checked against SEP's merchant technical guide, revision 3.6 (Dey 1404), and the
 * vendor sample (token POST, HTML form redirect, verify, reverse). Amounts are Rial
 * integers, the same unit ellsms_payments.amount_rial already uses.
 *
 * Flow:
 *   1. sep_request_token() — POST Action=token, store the token on ellsms_payments.authority
 *      and a fresh ResNum on gateway_resnum (a retry must not reuse ResNum).
 *   2. public/sep-redirect.php — the browser itself POSTs the token to SEP. A server
 *      redirect is not enough: SEP refuses the payment page unless the browser sends
 *      this site's address as Referer.
 *   3. SEP returns the buyer to public/sep-callback.php (GET when the form sends
 *      GetMethod=true, so the Lax session cookie still arrives; POST is accepted too).
 *   4. sep_complete_payment() calls VerifyTransaction. SEP will verify the same RefNum
 *      again, so gateway_ref is UNIQUE and one digital receipt can never credit two
 *      payments. OrginalAmount must equal the invoice total; a mismatch is reversed.
 *
 * A buyer who never returns cannot be verified: SEP identifies the receipt only by
 * RefNum, which exists only after the callback. SEP itself reverses an unverified
 * transaction after 30 minutes.
 */

declare(strict_types=1);

const SEP_TOKEN_URL = 'https://sep.shaparak.ir/OnlinePG/OnlinePG';
const SEP_VERIFY_URL = 'https://sep.shaparak.ir/verifyTxnRandomSessionkey/ipg/VerifyTransaction';
const SEP_REVERSE_URL = 'https://sep.shaparak.ir/verifyTxnRandomSessionkey/ipg/ReverseTransaction';

function sep_terminal_id(): string {
    $raw = (string)(setting('sep_terminal_id', env('SEP_TERMINAL_ID', '')) ?? '');
    return preg_replace('/\D/', '', $raw) ?? '';
}

function sep_callback_url(): string {
    // Same reseller-host rule as ZarinPal: the buyer returns to the host their session is on.
    // That host must be the site registered on the SEP terminal, or SEP rejects the token.
    if (env('RESELLER_HOST_PAYMENT_CALLBACK', '0') === '1' && ($brand = reseller_brand_for_host()) !== null && $brand['host'] !== null) {
        return 'https://' . $brand['host'] . '/sep-callback.php';
    }
    $configured = setting('sep_callback_url', env('SEP_CALLBACK_URL', ''));
    if ($configured) {
        return rtrim((string)$configured, '/');
    }
    if (app_url() !== '') {
        return app_url() . '/sep-callback.php';
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/sep-callback.php';
}

/** Minutes the token stays valid, or null to leave SEP's default (20). Clamped to SEP's 20–3600. */
function sep_token_expiry_minutes(): ?int {
    $raw = trim((string)(setting('sep_token_expiry_min', env('SEP_TOKEN_EXPIRY_MIN', '')) ?? ''));
    if ($raw === '' || !ctype_digit($raw)) {
        return null;
    }
    return max(20, min(3600, (int)$raw));
}

function sep_callback_url_acceptable(string $url): bool {
    if ($url === '' || strlen($url) > 1500) {
        return $url === '';
    }
    if (!preg_match('#^https://#i', $url)) {
        return false;
    }
    $parts = parse_url($url);
    return is_array($parts) && !empty($parts['host']) && !isset($parts['user']) && !isset($parts['pass']);
}

/** The value SEP's merchant form calls «آدرس سایت»: https origin, no path. */
function sep_site_url_from_callback(string $callbackUrl): string {
    $parts = parse_url($callbackUrl);
    if (!is_array($parts) || empty($parts['host'])) {
        return '';
    }
    if (strtolower((string)($parts['scheme'] ?? '')) !== 'https') {
        return '';
    }
    return 'https://' . $parts['host'];
}

function sep_new_resnum(int $paymentId): string {
    return $paymentId . '-' . bin2hex(random_bytes(4));
}

/** SEP wants the buyer's mobile as 9xxxxxxxxx, without a leading 0 or 98. Empty if it isn't a mobile. */
function sep_normalize_cell(string $mobile): string {
    $n = normalize_msisdn($mobile);
    if ($n === null || !str_starts_with($n, '98')) {
        return '';
    }
    $local = substr($n, 2);
    return preg_match('/^9\d{9}$/', $local) === 1 ? $local : '';
}

function sep_lower_keys(array $row): array {
    $out = [];
    foreach ($row as $key => $value) {
        $out[strtolower(trim((string)$key))] = $value;
    }
    return $out;
}

/** @return array{ok:bool,message:string,authority:?string} */
function sep_interpret_token(?array $body): array {
    if ($body === null) {
        return ['ok' => false, 'message' => 'اتصال به درگاه سامان برقرار نشد.', 'authority' => null];
    }
    $body = sep_lower_keys($body);
    $token = trim((string)($body['token'] ?? ''));
    if ((string)($body['status'] ?? '') === '1' && $token !== '') {
        return ['ok' => true, 'message' => 'ok', 'authority' => $token];
    }
    $desc = trim((string)($body['errordesc'] ?? ''));
    if (function_exists('mb_substr')) {
        $desc = mb_substr($desc, 0, 180);
    } else {
        $desc = substr($desc, 0, 180);
    }
    $code = trim((string)($body['errorcode'] ?? ''));
    $detail = $desc !== '' ? $desc : ('کد ' . ($code !== '' ? $code : '?'));
    return ['ok' => false, 'message' => 'درگاه سامان درخواست را رد کرد: ' . $detail, 'authority' => null];
}

function sep_verify_message(?int $code): string {
    return match ($code) {
        -2 => 'تراکنش در بانک پیدا نشد.',
        -6 => 'مهلت تأیید تراکنش (۳۰ دقیقه) گذشته و مبلغ برگشت خورده است.',
        -104 => 'ترمینال سامان غیرفعال است.',
        -105 => 'شماره ترمینال در سامان وجود ندارد.',
        -106 => 'آی‌پی سرور برای تأیید تراکنش در سامان مجاز نیست.',
        5 => 'تراکنش در بانک برگشت خورده است.',
        2 => 'این تراکنش قبلاً تأیید شده بود.',
        0 => 'پرداخت تأیید شد.',
        default => 'پرداخت تأیید نشد (کد ' . ($code === null ? '?' : (string)$code) . ').',
    };
}

/**
 * @return array{ok:bool,message:string,ref_id:?string,verified_amount_rial:?int,final:bool,result_code:?int}
 */
function sep_interpret_verify(?array $body, string $terminalId, string $refNum): array {
    $fail = static function (string $message, bool $final, ?int $code, ?int $amount = null) use ($refNum): array {
        return [
            'ok' => false,
            'message' => $message,
            'ref_id' => null,
            'verified_amount_rial' => $amount,
            'final' => $final,
            'result_code' => $code,
        ];
    };
    if ($body === null) {
        return $fail('اتصال به درگاه سامان برای تأیید پرداخت برقرار نشد.', false, null);
    }
    $body = sep_lower_keys($body);
    $code = isset($body['resultcode']) && $body['resultcode'] !== '' ? (int)$body['resultcode'] : null;
    $detail = is_array($body['transactiondetail'] ?? null) ? sep_lower_keys($body['transactiondetail']) : [];
    $original = array_key_exists('orginalamount', $detail) ? (int)$detail['orginalamount'] : null;
    $terminal = isset($detail['terminalnumber']) ? preg_replace('/\D/', '', (string)$detail['terminalnumber']) : '';
    $detailRef = trim((string)($detail['refnum'] ?? ''));
    $successRaw = $body['success'] ?? null;
    $success = $successRaw === true || $successRaw === 1 || $successRaw === '1' || $successRaw === 'true';
    $final = in_array($code, [-6, 5], true);

    $accepted = $code === 0 || $code === 2;
    if ($code === 0 && array_key_exists('success', $body) && !$success) {
        $accepted = false;
    }
    if (!$accepted) {
        return $fail(sep_verify_message($code), $final, $code, $original);
    }
    if ($terminal !== '' && $terminal !== preg_replace('/\D/', '', $terminalId)) {
        return $fail('ترمینال تأییدشده با ترمینال تنظیم‌شده یکی نیست.', true, $code, $original);
    }
    if ($detailRef !== '' && (strlen($detailRef) !== strlen($refNum) || !hash_equals($detailRef, $refNum))) {
        return $fail('رسید تأییدشده با رسید برگشتی یکی نیست.', true, $code, $original);
    }
    if ($original === null) {
        return $fail('مبلغ تأییدشده از درگاه دریافت نشد.', false, $code);
    }
    return [
        'ok' => true,
        'message' => sep_verify_message($code),
        'ref_id' => $refNum,
        'verified_amount_rial' => $original,
        'final' => false,
        'result_code' => $code,
    ];
}

/**
 * @return array{ok:bool,message:string}
 */
function sep_interpret_reverse(?array $body): array {
    if ($body === null) {
        return ['ok' => false, 'message' => 'اتصال به سامان برای برگشت مبلغ برقرار نشد.'];
    }
    $body = sep_lower_keys($body);
    $code = isset($body['resultcode']) ? (int)$body['resultcode'] : null;
    if ($code === 0 || $code === 2) {
        return ['ok' => true, 'message' => $code === 2 ? 'برگشت این تراکنش قبلاً ثبت شده بود.' : 'مبلغ به کارت برگشت.'];
    }
    return ['ok' => false, 'message' => 'برگشت مبلغ در سامان انجام نشد (کد ' . ($code === null ? '?' : (string)$code) . ').'];
}

function sep_state_ok(string $state, string $status): bool {
    if ($state !== 'OK') {
        return false;
    }
    return $status === '' || $status === '2';
}

/** Buyer-visible failures from SEP's state table. An empty or unknown state is not one of these. */
function sep_state_is_final_failure(string $state): bool {
    return in_array($state, [
        'CanceledByUser',
        'Failed',
        'SessionIsNull',
        'InvalidParameters',
        'MerchantIpAddressIsInvalid',
        'TokenNotFound',
        'TokenRequired',
        'TerminalNotFound',
    ], true);
}

function sep_state_message(string $state): string {
    return match ($state) {
        'CanceledByUser' => 'پرداخت توسط شما لغو شد.',
        'Failed' => 'پرداخت انجام نشد.',
        'SessionIsNull' => 'مهلت پرداخت تمام شد. دوباره تلاش کنید.',
        'InvalidParameters' => 'اطلاعات ارسال‌شده به درگاه نامعتبر بود.',
        'MerchantIpAddressIsInvalid' => 'درگاه سامان آی‌پی سرور را نپذیرفت. مدیر سامانه باید آی‌پی را در پنل سامان ثبت کند.',
        'TokenNotFound' => 'توکن پرداخت پیدا نشد یا منقضی شده است. دوباره تلاش کنید.',
        'TokenRequired' => 'توکن پرداخت ارسال نشد.',
        'TerminalNotFound' => 'شماره ترمینال در سامان پیدا نشد.',
        default => 'پرداخت ناموفق بود.',
    };
}

/** POST fields win. GET is what SEP sends when the redirect form sets GetMethod=true. */
function sep_callback_params(array $post, array $get): array {
    $src = $post !== [] ? $post : $get;
    $pick = static function (string $name) use ($src): string {
        foreach ($src as $key => $value) {
            if (strcasecmp((string)$key, $name) === 0) {
                return trim((string)$value);
            }
        }
        return '';
    };
    $terminal = $pick('TerminalId');
    if ($terminal === '') {
        $terminal = $pick('MID');
    }
    return [
        'state' => $pick('State'),
        'status' => $pick('Status'),
        'ref_num' => $pick('RefNum'),
        'res_num' => $pick('ResNum'),
        'token' => $pick('Token'),
        'terminal_id' => $terminal,
        'trace_no' => $pick('TraceNo'),
    ];
}

function sep_http_post(string $url, array $payload): ?array {
    $ch = curl_init($url);
    if ($ch === false) {
        return null;
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
    ]);
    $body = curl_exec($ch);
    if ($body === false) {
        Logger::warning('sep.http_failed', ['host' => (string)parse_url($url, PHP_URL_HOST), 'error' => curl_error($ch)]);
        curl_close($ch);
        return null;
    }
    curl_close($ch);
    $decoded = json_decode((string)$body, true);
    return is_array($decoded) ? $decoded : null;
}

/** @return array{ok:bool,message:string,authority:?string} */
function sep_request_token(int $amountRial, int $paymentId, string $resnum, string $mobile): array {
    $terminal = sep_terminal_id();
    if ($terminal === '') {
        return ['ok' => false, 'message' => 'شماره ترمینال سامان تنظیم نشده — از مدیر بخواهید آن را در تنظیمات پرداخت وارد کند.', 'authority' => null];
    }
    if ($amountRial <= 0) {
        return ['ok' => false, 'message' => 'مبلغ پرداخت نامعتبر است.', 'authority' => null];
    }
    if ($resnum === '' || strlen($resnum) > 40) {
        return ['ok' => false, 'message' => 'شناسه خرید پرداخت نامعتبر است.', 'authority' => null];
    }
    $payload = [
        'action' => 'token',
        'TerminalId' => $terminal,
        'Amount' => $amountRial,
        'ResNum' => $resnum,
        'RedirectUrl' => sep_callback_url(),
    ];
    $cell = sep_normalize_cell($mobile);
    if ($cell !== '') {
        $payload['CellNumber'] = $cell;
    }
    $expiry = sep_token_expiry_minutes();
    if ($expiry !== null) {
        $payload['TokenExpiryInMin'] = $expiry;
    }
    return sep_interpret_token(sep_http_post(SEP_TOKEN_URL, $payload));
}

function sep_store_attempt(int $paymentId, string $token, string $resnum): bool {
    $st = db()->prepare(
        "UPDATE ellsms_payments
         SET authority=?, gateway_resnum=?, gateway_ref=NULL
         WHERE id=? AND status IN ('pending','verification_failed','failed')"
    );
    $st->execute([$token, $resnum, $paymentId]);
    return $st->rowCount() > 0;
}

/** @return array{ok:bool,message:string,authority:?string} */
function payment_sep_gateway_create(int $amountRial, int $paymentId, string $description, string $mobile): array {
    unset($description);
    $resnum = sep_new_resnum($paymentId);
    try {
        $created = sep_request_token($amountRial, $paymentId, $resnum, $mobile);
    } catch (Throwable $t) {
        Logger::error('sep.token_failed', ['payment_id' => $paymentId, 'exception' => $t]);
        return ['ok' => false, 'message' => 'شروع پرداخت سامان ممکن نشد.', 'authority' => null];
    }
    if (!$created['ok'] || $created['authority'] === null) {
        return $created;
    }
    try {
        $stored = sep_store_attempt($paymentId, $created['authority'], $resnum);
    } catch (Throwable $t) {
        Logger::error('sep.store_attempt_failed', ['payment_id' => $paymentId, 'exception' => $t]);
        return ['ok' => false, 'message' => 'ثبت توکن سامان ممکن نشد. اگر به‌روزرسانی پایگاه‌داده انجام نشده، مهاجرت درگاه سامان را اعمال کنید.', 'authority' => null];
    }
    if (!$stored) {
        return ['ok' => false, 'message' => 'این پرداخت دیگر قابل ادامه نیست.', 'authority' => null];
    }
    return $created;
}

function payment_sep_redirect_url(string $authority): string {
    $st = db()->prepare("SELECT id FROM ellsms_payments WHERE authority=? AND gateway='sep' ORDER BY id DESC LIMIT 1");
    $st->execute([$authority]);
    $id = (int)$st->fetchColumn();
    return $id > 0 ? '/sep-redirect.php?payment_id=' . $id : '/buy-credit.php';
}

/** @return array{ok:bool,message:string,ref_id:?string,verified_amount_rial:?int,final:bool,result_code:?int} */
function payment_sep_gateway_verify(int $amountRial, string $refNum): array {
    unset($amountRial);
    $terminal = sep_terminal_id();
    $refNum = trim($refNum);
    if ($terminal === '' || $refNum === '') {
        return [
            'ok' => false,
            'message' => 'اطلاعات تأیید تراکنش سامان کامل نیست.',
            'ref_id' => null,
            'verified_amount_rial' => null,
            'final' => false,
            'result_code' => null,
        ];
    }
    $body = sep_http_post(SEP_VERIFY_URL, [
        'RefNum' => $refNum,
        'TerminalNumber' => $terminal,
    ]);
    return sep_interpret_verify($body, $terminal, $refNum);
}

/** @return array{ok:bool,message:string} */
function payment_sep_reverse(string $refNum): array {
    $terminal = sep_terminal_id();
    $refNum = trim($refNum);
    if ($terminal === '' || $refNum === '') {
        return ['ok' => false, 'message' => 'اطلاعات برگشت تراکنش سامان کامل نیست.'];
    }
    return sep_interpret_reverse(sep_http_post(SEP_REVERSE_URL, [
        'RefNum' => $refNum,
        'TerminalNumber' => $terminal,
    ]));
}

/**
 * Bind a receipt to this payment.
 *
 * `ok` — this row now holds it (or already did). `held` — this row holds a different receipt.
 * `conflict` — another payment already holds it (UNIQUE). `rejected` — the value cannot be stored.
 */
function sep_remember_ref(int $paymentId, string $refNum): string {
    $refNum = trim($refNum);
    if ($refNum === '' || strlen($refNum) > 64) {
        return 'rejected';
    }
    try {
        $st = db()->prepare(
            'UPDATE ellsms_payments SET gateway_ref=? WHERE id=? AND (gateway_ref IS NULL OR gateway_ref=?)'
        );
        $st->execute([$refNum, $paymentId, $refNum]);
        if ($st->rowCount() > 0) {
            return 'ok';
        }
        $cur = db()->prepare('SELECT gateway_ref FROM ellsms_payments WHERE id=?');
        $cur->execute([$paymentId]);
        $owned = $cur->fetchColumn();
        if (is_string($owned) && $owned !== '' && strlen($owned) === strlen($refNum) && hash_equals($owned, $refNum)) {
            return 'ok';
        }
        if (is_string($owned) && $owned !== '') {
            return 'held';
        }
        return 'conflict';
    } catch (PDOException $e) {
        Logger::warning('sep.refnum_conflict', ['payment_id' => $paymentId]);
        return 'conflict';
    }
}

/** Swap a receipt that never became the paid one. Rolls back if the new value is already taken. */
function sep_replace_ref(int $paymentId, string $previous, string $refNum): bool {
    $previous = trim($previous);
    $refNum = trim($refNum);
    if ($previous === '' || $refNum === '' || strlen($refNum) > 64 || $previous === $refNum) {
        return false;
    }
    $db = db();
    $db->beginTransaction();
    try {
        $clear = $db->prepare(
            "UPDATE ellsms_payments SET gateway_ref=NULL
             WHERE id=? AND gateway_ref=? AND status IN ('pending','verification_failed','failed')"
        );
        $clear->execute([$paymentId, $previous]);
        if ($clear->rowCount() < 1) {
            $db->rollBack();
            return false;
        }
        $set = $db->prepare(
            "UPDATE ellsms_payments SET gateway_ref=?
             WHERE id=? AND gateway_ref IS NULL AND status IN ('pending','verification_failed','failed')"
        );
        $set->execute([$refNum, $paymentId]);
        if ($set->rowCount() < 1) {
            $db->rollBack();
            return false;
        }
        $db->commit();
        return true;
    } catch (Throwable $t) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        Logger::warning('sep.refnum_replace_failed', ['payment_id' => $paymentId, 'exception' => $t]);
        return false;
    }
}

function sep_payment_for_callback(string $resNum, string $token): ?array {
    if ($resNum === '' || strlen($resNum) > 40) {
        return null;
    }
    if ($token !== '' && strlen($token) > 64) {
        return null;
    }
    $st = db()->prepare('SELECT * FROM ellsms_payments WHERE gateway_resnum=? LIMIT 1');
    $st->execute([$resNum]);
    $row = $st->fetch();
    if (!$row || (string)($row['gateway'] ?? '') !== 'sep') {
        return null;
    }
    if ($token !== '') {
        $stored = (string)($row['authority'] ?? '');
        if ($stored === '' || strlen($stored) !== strlen($token) || !hash_equals($stored, $token)) {
            return null;
        }
    }
    return $row;
}

function sep_mark_status(int $paymentId, string $status): void {
    db()->prepare("UPDATE ellsms_payments SET status=? WHERE id=? AND status IN ('pending','verification_failed')")
        ->execute([$status, $paymentId]);
}

/**
 * @param array<string,mixed> $payment
 * @return array{ok:bool,message:string,claimed:bool,notify:?array}
 */
function sep_fulfill(array $payment, string $refId): array {
    $paymentId = (int)$payment['id'];
    $isSubscription = ($payment['purpose'] ?? 'credit') === 'subscription';
    try {
        $txResult = $isSubscription
            ? payment_claim_and_activate_subscription($payment, $refId)
            : payment_claim_and_credit($payment, $refId);
    } catch (Throwable $t) {
        Logger::error('payment.credit_transaction_failed', ['payment_id' => $paymentId, 'user_id' => $payment['user_id'], 'exception' => $t]);
        $txResult = null;
    }

    $notify = static function (string $event, string $title, string $body, string $severity) use ($paymentId): array {
        return ['event' => $event, 'title' => $title, 'body' => $body, 'severity' => $severity, 'payment_id' => $paymentId];
    };

    if ($txResult !== null && $isSubscription) {
        if ($txResult['claimed'] && $txResult['activated']) {
            audit((int)$payment['user_id'], 'billing.subscription.paid', "#{$paymentId} ref={$refId}");
            return ['ok' => true, 'message' => 'پرداخت با موفقیت انجام شد و اشتراک سازمان شما فعال گردید.', 'claimed' => true,
                'notify' => $notify('payment.success', 'پرداخت موفق', 'پرداخت #' . $paymentId . ' انجام شد و اشتراک سازمان فعال گردید.', 'success')];
        }
        if ($txResult['claimed']) {
            Logger::critical('billing.payment.activation_failed', ['payment_id' => $paymentId, 'reason' => $txResult['reason']]);
            return ['ok' => false, 'message' => 'پرداخت شما ثبت شد اما فعال‌سازی اشتراک ناتمام ماند — لطفاً با پشتیبانی تماس بگیرید.', 'claimed' => true,
                'notify' => $notify('payment.failed', 'فعال‌سازی اشتراک ناتمام ماند', 'پرداخت #' . $paymentId . ' ثبت شد اما فعال‌سازی سرویس نیاز به بررسی پشتیبانی دارد.', 'error')];
        }
        return ['ok' => true, 'message' => 'این پرداخت قبلاً پردازش شده است.', 'claimed' => false, 'notify' => null];
    }
    if ($txResult === null) {
        return ['ok' => false, 'message' => 'مشکلی در ثبت اعتبار رخ داد — لطفاً کمی بعد دوباره تلاش کنید یا با پشتیبانی تماس بگیرید.', 'claimed' => false,
            'notify' => $notify('payment.failed', 'ثبت پرداخت ناتمام ماند', 'پرداخت #' . $paymentId . ' هنوز نهایی نشده و قابل بازیابی است.', 'warning')];
    }
    if ($txResult['claimed']) {
        audit((int)$payment['user_id'], 'payment.paid', "#{$paymentId} +{$payment['credits']}cr ref={$refId}");
        Logger::info('payment.credited', [
            'payment_id' => $paymentId,
            'user_id' => $payment['user_id'],
            'credits' => $payment['credits'],
            'amount_rial' => $payment['amount_rial'],
            'gateway' => 'sep',
            'replayed' => $txResult['credit']['replayed'] ?? false,
        ]);
        return ['ok' => true, 'message' => !empty($payment['number_order_id'])
                ? 'پرداخت با موفقیت انجام شد. وضعیت سفارش شماره را در «شماره‌ها ← سفارش‌های من» ببینید.'
                : 'پرداخت با موفقیت انجام شد و اعتبار شما افزایش یافت.', 'claimed' => true,
            'notify' => $notify('payment.success', 'پرداخت موفق', 'پرداخت #' . $paymentId . ' با موفقیت انجام شد و اعتبار حساب افزایش یافت.', 'success')];
    }
    Logger::info('payment.replayed_already_paid', ['payment_id' => $paymentId, 'user_id' => $payment['user_id']]);
    return ['ok' => true, 'message' => 'این پرداخت قبلاً پردازش شده است.', 'claimed' => false, 'notify' => null];
}

/**
 * Verify a SEP receipt and, only then, credit or activate. Safe to call again from reconciliation.
 *
 * @param array<string,mixed> $payment
 * @return array{ok:bool,message:string,payment:array,notify:?array,claimed:bool}
 */
function sep_complete_payment(array $payment, string $refNum): array {
    $paymentId = (int)$payment['id'];
    $expected = (int)$payment['amount_rial'];
    $refNum = trim($refNum);
    $verify = payment_gateway_verify('sep', $expected, $refNum);
    $code = isset($verify['result_code']) ? (int)$verify['result_code'] : null;
    // A not-found or a network failure is not proof this receipt exists. Storing it would block
    // the real callback. -6, 5, and any successful verify are receipts the bank actually has.
    $receiptIsReal = $verify['ok'] || !empty($verify['final']) || $code === -6 || $code === 5;

    if (!$receiptIsReal) {
        $stored = trim((string)($payment['gateway_ref'] ?? ''));
        $sameStored = $stored !== '' && strlen($stored) === strlen($refNum) && hash_equals($stored, $refNum);
        $gaveUp = false;
        if ($sameStored && $code === -2) {
            $created = strtotime((string)($payment['created_at'] ?? '')) ?: time();
            $gaveUp = time() - $created > 40 * 60;
            sep_mark_status($paymentId, $gaveUp ? 'failed' : 'verification_failed');
        } elseif ($sameStored) {
            sep_mark_status($paymentId, 'verification_failed');
        }
        Logger::warning('sep.verify_unconfirmed', ['payment_id' => $paymentId, 'info' => $verify['message'], 'final' => $gaveUp]);
        $retrying = $sameStored && !$gaveUp;
        return [
            'ok' => false,
            'message' => $retrying
                ? ('تأیید پرداخت ناموفق بود، اما پرداخت شما گم نشده — تأیید به‌صورت خودکار دوباره بررسی می‌شود: ' . $verify['message'])
                : ($gaveUp
                    ? ('پرداخت تأیید نشد: ' . $verify['message'])
                    : 'تأیید این رسید از بانک دریافت نشد. اگر مبلغ از کارت کم شده همین صفحه را دوباره باز کنید.'),
            'payment' => $payment,
            'claimed' => false,
            'notify' => $sameStored ? [
                'event' => 'payment.failed',
                'title' => $gaveUp ? 'پرداخت ناموفق' : 'بررسی پرداخت ناتمام است',
                'body' => $gaveUp
                    ? ('پرداخت #' . $paymentId . ' تأیید نشد.')
                    : ('پرداخت #' . $paymentId . ' هنوز تأیید نشده و دوباره بررسی خواهد شد.'),
                'severity' => $gaveUp ? 'error' : 'warning',
            ] : null,
        ];
    }

    $claim = sep_remember_ref($paymentId, $refNum);
    if ($claim === 'held' && $verify['ok']) {
        $cur = db()->prepare('SELECT gateway_ref FROM ellsms_payments WHERE id=?');
        $cur->execute([$paymentId]);
        $stored = trim((string)$cur->fetchColumn());
        $old = $stored !== '' ? payment_gateway_verify('sep', $expected, $stored) : ['ok' => false, 'verified_amount_rial' => null];
        $oldAmount = $old['verified_amount_rial'] ?? null;
        if (!empty($old['ok']) && $oldAmount !== null && (int)$oldAmount === $expected) {
            $reverse = payment_sep_reverse($refNum);
            Logger::critical('sep.second_receipt', [
                'payment_id' => $paymentId,
                'reversed' => $reverse['ok'],
            ]);
            $refNum = $stored;
            $verify = $old;
            $claim = 'ok';
        } elseif ($stored !== '' && sep_replace_ref($paymentId, $stored, $refNum)) {
            $claim = 'ok';
        } else {
            $claim = 'conflict';
        }
    }
    if ($claim === 'held') {
        return [
            'ok' => false,
            'message' => $verify['message'],
            'payment' => $payment,
            'claimed' => false,
            'notify' => null,
        ];
    }
    if ($claim !== 'ok') {
        Logger::critical('sep.refnum_reused', ['payment_id' => $paymentId, 'claim' => $claim]);
        return [
            'ok' => false,
            'message' => 'این رسید دیجیتال قبلاً برای پرداخت دیگری استفاده شده است.',
            'payment' => $payment,
            'claimed' => false,
            'notify' => ['event' => 'payment.failed', 'title' => 'پرداخت رد شد', 'body' => 'رسید پرداخت #' . $paymentId . ' تکراری بود.', 'severity' => 'error'],
        ];
    }

    $final = !empty($verify['final']);
    if (!$verify['ok']) {
        sep_mark_status($paymentId, $final ? 'failed' : 'verification_failed');
        if ($final) {
            payment_purchase_order_fail($payment);
        }
        Logger::error('sep.verify_failed', ['payment_id' => $paymentId, 'info' => $verify['message'], 'final' => $final]);
        $message = $final
            ? ('پرداخت تأیید نشد: ' . $verify['message'])
            : ('تأیید پرداخت ناموفق بود، اما پرداخت شما گم نشده — تأیید به‌صورت خودکار دوباره بررسی می‌شود: ' . $verify['message']);
        return [
            'ok' => false,
            'message' => $message,
            'payment' => $payment,
            'claimed' => false,
            'notify' => [
                'event' => 'payment.failed',
                'title' => $final ? 'پرداخت ناموفق' : 'بررسی پرداخت ناتمام است',
                'body' => $final ? ('پرداخت #' . $paymentId . ' تأیید نشد.') : ('پرداخت #' . $paymentId . ' هنوز تأیید نشده و دوباره بررسی خواهد شد.'),
                'severity' => $final ? 'error' : 'warning',
            ],
        ];
    }

    $verifiedAmount = $verify['verified_amount_rial'];
    if ($verifiedAmount !== null && (int)$verifiedAmount !== $expected) {
        $reverse = payment_sep_reverse($refNum);
        // Failed reverse stays verification_failed so the next reconcile retries ReverseTransaction
        // inside SEP's 50-minute window. A successful reverse is final.
        sep_mark_status($paymentId, $reverse['ok'] ? 'failed' : 'verification_failed');
        if ($reverse['ok']) {
            payment_purchase_order_fail($payment);
        }
        Logger::critical('payment.amount_mismatch', [
            'payment_id' => $paymentId,
            'user_id' => $payment['user_id'],
            'gateway' => 'sep',
            'expected_amount_rial' => $expected,
            'verified_amount_rial' => (int)$verifiedAmount,
            'reversed' => $reverse['ok'],
        ]);
        audit((int)$payment['user_id'], 'security.payment.amount_mismatch', "#{$paymentId} expected={$expected} verified={$verifiedAmount} reversed=" . (int)$reverse['ok']);
        $message = $reverse['ok']
            ? 'مبلغ تأییدشده توسط بانک با مبلغ فاکتور یکی نبود. مبلغ به کارت برگردانده شد.'
            : 'مبلغ تأییدشده توسط بانک با مبلغ فاکتور یکی نبود. برگشت خودکار مبلغ ناموفق بود و دوباره تلاش می‌شود — اگر به کارت برنگشت با پشتیبانی تماس بگیرید.';
        return [
            'ok' => false,
            'message' => $message,
            'payment' => $payment,
            'claimed' => false,
            'notify' => ['event' => 'payment.failed', 'title' => 'پرداخت لغو شد', 'body' => 'مبلغ پرداخت #' . $paymentId . ' با فاکتور یکی نبود.', 'severity' => 'error'],
        ];
    }

    $done = sep_fulfill($payment, (string)$verify['ref_id']);
    return [
        'ok' => $done['ok'],
        'message' => $done['message'],
        'payment' => $payment,
        'claimed' => $done['claimed'],
        'notify' => $done['notify'],
    ];
}

/**
 * @param array{state:string,status:string,ref_num:string,res_num:string,token:string} $params
 * @return array{ok:bool,message:string,payment:?array,notify:?array,claimed:bool}
 */
function sep_handle_callback(array $params): array {
    $none = static function (string $message): array {
        return ['ok' => false, 'message' => $message, 'payment' => null, 'notify' => null, 'claimed' => false];
    };
    if ($params['res_num'] === '') {
        return $none('اطلاعات بازگشت از درگاه سامان کامل نیست.');
    }
    $payment = sep_payment_for_callback($params['res_num'], $params['token']);
    if (!$payment) {
        Logger::warning('sep.callback_unmatched', ['has_ref' => $params['ref_num'] !== '']);
        return $none('پرداخت پیدا نشد یا اطلاعات بازگشتی با آن یکی نیست.');
    }
    if ($payment['status'] === 'paid') {
        return ['ok' => true, 'message' => 'این پرداخت قبلاً با موفقیت ثبت شده است.', 'payment' => $payment, 'notify' => null, 'claimed' => false];
    }
    if (!sep_state_ok($params['state'], $params['status'])) {
        if ($payment['status'] === 'failed') {
            return ['ok' => false, 'message' => 'این پرداخت ناموفق بوده است.', 'payment' => $payment, 'notify' => null, 'claimed' => false];
        }
        // Only the states in SEP's table close the payment. A truncated callback must not.
        if (sep_state_is_final_failure($params['state'])) {
            sep_mark_status((int)$payment['id'], 'failed');
            payment_purchase_order_fail($payment);
            Logger::warning('payment.cancelled_or_failed_at_gateway', [
                'payment_id' => $payment['id'],
                'user_id' => $payment['user_id'],
                'gateway' => 'sep',
                'state' => $params['state'],
            ]);
        }
        return [
            'ok' => false,
            'message' => sep_state_message($params['state']),
            'payment' => $payment,
            'claimed' => false,
            'notify' => ['event' => 'payment.failed', 'title' => 'پرداخت ناموفق', 'body' => 'پرداخت #' . $payment['id'] . ' تکمیل نشد.', 'severity' => 'error'],
        ];
    }
    if ($payment['status'] === 'failed') {
        // A cancel callback can arrive before the success redirect. Claim only accepts
        // pending / verification_failed, so reopen and let VerifyTransaction decide.
        db()->prepare("UPDATE ellsms_payments SET status='verification_failed' WHERE id=? AND status='failed'")
            ->execute([(int)$payment['id']]);
        $payment['status'] = 'verification_failed';
    }
    if ($params['ref_num'] === '') {
        sep_mark_status((int)$payment['id'], 'failed');
        payment_purchase_order_fail($payment);
        return [
            'ok' => false,
            'message' => 'بانک رسید تراکنش را برنگرداند.',
            'payment' => $payment,
            'claimed' => false,
            'notify' => ['event' => 'payment.failed', 'title' => 'پرداخت ناموفق', 'body' => 'پرداخت #' . $payment['id'] . ' رسید نداشت.', 'severity' => 'error'],
        ];
    }
    if ($params['terminal_id'] !== '') {
        $ours = sep_terminal_id();
        $theirs = preg_replace('/\D/', '', $params['terminal_id']) ?? '';
        if ($ours !== '' && $theirs !== '' && $theirs !== $ours) {
            Logger::warning('sep.callback_terminal_mismatch', ['payment_id' => $payment['id']]);
            return [
                'ok' => false,
                'message' => 'ترمینال برگشتی با ترمینال تنظیم‌شده یکی نیست.',
                'payment' => $payment,
                'claimed' => false,
                'notify' => null,
            ];
        }
    }
    return sep_complete_payment($payment, $params['ref_num']);
}
