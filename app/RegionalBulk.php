<?php
/**
 * #43 — regional bulk SMS through Vesal's /backend/bulk API.
 *
 * "Send to every subscriber in province / city / postal code / number prefix X" needs the operators'
 * subscriber bank, which only Vesal (as the operator-side platform) holds. ELLSMS therefore acts purely
 * as a client of Vesal's own flow — count → request → price → confirm → status — and never sees or
 * stores a subscriber number.
 *
 * Money: nothing is reserved until the customer confirms the priced request; confirmation reserves
 * the ELLSMS price (Vesal's price × regional_bulk_credits_per_price_unit × (1 + margin %)); when Vesal
 * reports the request finished, the customer is charged for the share actually sent
 * (total_sent / total_request) and the rest is released.
 *
 * Idempotency: every request carries a unique numeric userSuppliedId; if the answer to a request call is
 * lost, /checkDuplicateRequest recovers the reference Vesal already created instead of creating a second
 * one.
 *
 * Configuration: settings regional_bulk_enabled, vesal_bulk_base_url, vesal_bulk_username,
 * regional_bulk_credits_per_price_unit, regional_bulk_margin_percent; the password is the
 * VESAL_BULK_PASSWORD environment variable. KYC gate `regional_bulk` (required by default).
 *
 * Only Vesal's working paths are offered: its "extended" and Irancell-specific endpoints are empty stubs
 * in its current code; Irancell (MTN) goes through the regular endpoints with mobileOperator = MTN.
 */

declare(strict_types=1);

const REGIONAL_BULK_KINDS = ['province', 'city', 'postal', 'prefix'];
const REGIONAL_BULK_SIM_TYPES = ['all' => 2, 'postpaid' => 0, 'prepaid' => 1]; // Vesal SimType ordinals
const REGIONAL_BULK_OPERATORS = ['MCI', 'MTN'];
const REGIONAL_BULK_USER_SUPPLIED_BASE = 880000000000;
/** Vesal MtMessageStatus ordinals after which nothing more will be sent. */
const REGIONAL_BULK_FINAL_VESAL_STATES = [5, 6, 7]; // TOTAL_SENT_READY, CANCELED, DELIVERED

const REGIONAL_BULK_ERRORS = [
    -101 => 'نام کاربری یا گذرواژه‌ی سرویس Vesal نادرست است.',
    -102 => 'خط ارسال برای این حساب Vesal مجاز نیست.',
    -103 => 'اعتبار حساب Vesal کافی نیست.',
    -104 => 'درخواست نامعتبر است.',
    -105 => 'حساب Vesal غیرفعال است.',
    -106 => 'حساب Vesal منقضی شده است.',
    -107 => 'IP این سرور در Vesal مجاز نشده است.',
    -108 => 'درخواست تکراری است.',
    -109 => 'کد استان نامعتبر است.',
    -110 => 'کد شهر نامعتبر است.',
    -111 => 'درخواست در Vesal پیدا نشد.',
    -112 => 'تعداد درخواستی بیشتر از شماره‌های موجود است.',
    -113 => 'زمان ارسال خارج از بازه‌ی مجاز است.',
    -114 => 'این درخواست قبلاً تأیید شده است.',
    -137 => 'متن پیام شامل عبارت غیرمجاز است.',
];

function regional_bulk_setting(string $key, string $default = ''): string {
    return (string)setting_fresh($key, $default);
}

function regional_bulk_configured(): bool {
    return regional_bulk_setting('regional_bulk_enabled', '0') === '1'
        && regional_bulk_setting('vesal_bulk_base_url') !== ''
        && regional_bulk_setting('vesal_bulk_username') !== ''
        && trim((string)env('VESAL_BULK_PASSWORD', '')) !== '';
}

function regional_bulk_error_message(int $code): string {
    return REGIONAL_BULK_ERRORS[$code] ?? ('خطای Vesal (کد ' . $code . ')');
}

/**
 * One call to Vesal. The credentials go in the body, as Vesal expects.
 *
 * @return array{ok:bool, http:int, data:mixed, error:?string, transport_error:bool}
 */
function vesal_bulk_call(string $endpoint, array $payload): array {
    $url = rtrim(regional_bulk_setting('vesal_bulk_base_url'), '/') . '/backend/bulk/' . $endpoint;
    $check = gateway_endpoint_allowed($url);
    if (!$check['ok']) {
        return ['ok' => false, 'http' => 0, 'data' => null, 'error' => $check['reason'], 'transport_error' => false];
    }
    $body = json_encode($payload + [
        'username' => regional_bulk_setting('vesal_bulk_username'),
        'password' => (string)env('VESAL_BULK_PASSWORD', ''),
    ], JSON_UNESCAPED_UNICODE);
    $ch = curl_init($url);
    $options = [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_CONNECTTIMEOUT_MS => 5000, CURLOPT_TIMEOUT_MS => 30000, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
    ];
    if ($check['resolve'] !== []) $options[CURLOPT_RESOLVE] = $check['resolve'];
    curl_setopt_array($ch, $options);
    $raw = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($raw === false || $http < 200 || $http >= 300) {
        // Never log the body we sent: it carries the password.
        Logger::warning('regional_bulk.vesal_call_failed', ['endpoint' => $endpoint, 'http' => $http]);
        return ['ok' => false, 'http' => $http, 'data' => null, 'error' => 'Vesal پاسخ نداد (HTTP ' . $http . ')', 'transport_error' => true];
    }
    return ['ok' => true, 'http' => $http, 'data' => json_decode((string)$raw, true, 512, JSON_BIGINT_AS_STRING), 'error' => null, 'transport_error' => false];
}

/** @return list<array{code:int,name:string}>|string list, or an error message */
function regional_bulk_provinces(): array|string {
    $r = vesal_bulk_call('provinces', []);
    if (!$r['ok']) return (string)$r['error'];
    $code = (int)($r['data']['errorCode'] ?? 0);
    if ($code < 0) return regional_bulk_error_message($code);
    return array_map(static fn(array $p): array => ['code' => (int)$p['code'], 'name' => (string)$p['name']], (array)($r['data']['provinces'] ?? []));
}

/** @return list<array{code:int,name:string}>|string */
function regional_bulk_cities(int $provinceCode): array|string {
    $r = vesal_bulk_call('citiesOfProvince', ['provinceCode' => $provinceCode]);
    if (!$r['ok']) return (string)$r['error'];
    $code = (int)($r['data']['errorCode'] ?? 0);
    if ($code < 0) return regional_bulk_error_message($code);
    return array_map(static fn(array $c): array => ['code' => (int)$c['code'], 'name' => (string)$c['name']], (array)($r['data']['cities'] ?? []));
}

/**
 * Validates and normalizes a targeting choice.
 *
 * @return array{ok:bool, criteria?:array, error?:string}
 */
function regional_bulk_criteria(array $in): array {
    $kind = (string)($in['kind'] ?? '');
    if (!in_array($kind, REGIONAL_BULK_KINDS, true)) return ['ok' => false, 'error' => 'نوع هدف‌گیری نامعتبر است.'];
    $sim = array_key_exists((string)($in['sim'] ?? 'all'), REGIONAL_BULK_SIM_TYPES) ? (string)($in['sim'] ?? 'all') : 'all';
    $operator = in_array(strtoupper((string)($in['operator'] ?? 'MCI')), REGIONAL_BULK_OPERATORS, true) ? strtoupper((string)($in['operator'] ?? 'MCI')) : 'MCI';
    $prefix = preg_replace('/\D/', '', from_persian_digits((string)($in['prefix'] ?? ''))) ?? '';
    $criteria = ['kind' => $kind, 'sim' => $sim, 'operator' => $operator, 'prefix' => $prefix];
    switch ($kind) {
        case 'province':
            $criteria['province_code'] = (int)($in['province_code'] ?? 0);
            if ($criteria['province_code'] <= 0) return ['ok' => false, 'error' => 'استان را انتخاب کنید.'];
            break;
        case 'city':
            $criteria['city_code'] = (int)($in['city_code'] ?? 0);
            if ($criteria['city_code'] <= 0) return ['ok' => false, 'error' => 'شهر را انتخاب کنید.'];
            break;
        case 'postal':
            $criteria['postal_code'] = preg_replace('/\D/', '', from_persian_digits((string)($in['postal_code'] ?? ''))) ?? '';
            if (strlen($criteria['postal_code']) < 3 || strlen($criteria['postal_code']) > 10) return ['ok' => false, 'error' => 'کد پستی (یا ابتدای آن) نامعتبر است.'];
            break;
        case 'prefix':
            if (strlen($prefix) < 3 || strlen($prefix) > 8) return ['ok' => false, 'error' => 'پیش‌شماره نامعتبر است (مثلاً ۰۹۱۲).'];
            break;
    }
    return ['ok' => true, 'criteria' => $criteria];
}

/** The BulkModel fields for a criteria set. */
function regional_bulk_vesal_fields(array $criteria): array {
    $fields = ['type' => REGIONAL_BULK_SIM_TYPES[$criteria['sim']] ?? 2, 'prefix' => (string)$criteria['prefix']];
    if ($criteria['operator'] === 'MTN') $fields['mobileOperator'] = 'MTN';
    return $fields + match ($criteria['kind']) {
        'province' => ['provinceCode' => (int)$criteria['province_code']],
        'city'     => ['cityCode' => (int)$criteria['city_code']],
        'postal'   => ['postalCode' => (string)$criteria['postal_code']],
        default    => [],
    };
}

/** @return array{ok:bool, count?:int, error?:string} */
function regional_bulk_count(array $criteria): array {
    $endpoint = ['province' => 'countByProvince', 'city' => 'countByCity', 'postal' => 'countByPostalCode', 'prefix' => 'countByPrefix'][$criteria['kind']];
    $r = vesal_bulk_call($endpoint, regional_bulk_vesal_fields($criteria));
    if (!$r['ok']) return ['ok' => false, 'error' => (string)$r['error']];
    $n = (int)$r['data'];
    return $n < 0 ? ['ok' => false, 'error' => regional_bulk_error_message($n)] : ['ok' => true, 'count' => $n];
}

/** ELLSMS credits for a Vesal price. */
function regional_bulk_credits_for_price(float $vesalPrice): int {
    $perUnit = (float)regional_bulk_setting('regional_bulk_credits_per_price_unit', '1');
    $margin = (float)regional_bulk_setting('regional_bulk_margin_percent', '0');
    return (int)ceil(max(0.0, $vesalPrice) * max(0.0, $perUnit) * (1 + max(0.0, $margin) / 100));
}

function regional_bulk_find(int $id, array $user): ?array {
    $st = db()->prepare('SELECT * FROM ellsms_regional_bulk_requests WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) return null;
    if (($user['role'] ?? null) === 'admin') return $row;
    $orgId = (int)($user['organization_id'] ?? 0);
    $mine = $orgId > 0 ? (int)($row['organization_id'] ?? 0) === $orgId : (int)$row['user_id'] === (int)$user['id'];
    return $mine ? $row : null;
}

function regional_bulk_fail(int $id, string $error): void {
    db()->prepare("UPDATE ellsms_regional_bulk_requests SET status = 'failed', error = ?, finished_at = NOW() WHERE id = ?")->execute([mb_strimwidth($error, 0, 480, '…'), $id]);
}

/**
 * Creates the request at Vesal and prices it. Nothing is reserved yet.
 *
 * @return array{ok:bool, id?:int, error?:string}
 */
function regional_bulk_create(array $user, array $criteria, string $originator, string $content, ?string $scheduledAt = null, ?int $count = null): array {
    if (!regional_bulk_configured()) return ['ok' => false, 'error' => 'ارسال منطقه‌ای فعال نیست.'];
    $orgId = (int)($user['organization_id'] ?? 0);
    // The gate is read fresh (not through setting()'s per-process cache) so a long-lived caller sees an
    // admin's change at once.
    $gateRequired = setting_fresh(kyc_gate_setting_key('regional_bulk'), '0') === '1';
    if (($user['role'] ?? null) !== 'admin'
        && !kyc_feature_allowed_for_status($gateRequired, (string)(kyc_request_get($orgId)['status'] ?? 'draft'))) {
        return ['ok' => false, 'error' => kyc_gate_denial_message($orgId, 'regional_bulk')];
    }
    if (!impersonation_action_allowed('send.bulk')) {
        impersonation_record_block('send.bulk');
        return ['ok' => false, 'error' => impersonation_block_message('send.bulk')];
    }
    $originator = normalize_originator($originator) ?? '';
    if ($originator === '' || !can_use_originator($user, $originator)) return ['ok' => false, 'error' => 'استفاده از این خط ارسال برای شما مجاز نیست.'];
    $content = trim($content);
    if ($content === '') return ['ok' => false, 'error' => 'متن پیام خالی است.'];
    if (($ruleId = content_policy_violation($content)) !== null) {
        content_policy_log_refusal($ruleId, 'regional_bulk', (int)$user['id']);
        return ['ok' => false, 'error' => CONTENT_POLICY_ERROR];
    }

    $db = db();
    $db->prepare('INSERT INTO ellsms_regional_bulk_requests (organization_id, user_id, criteria_json, originator, content, scheduled_at, user_supplied_id, estimated_count)
                  VALUES (?,?,?,?,?,?,NULL,?)')
       ->execute([$orgId ?: null, (int)$user['id'], json_encode($criteria, JSON_UNESCAPED_UNICODE), $originator, $content, $scheduledAt, $count]);
    $id = (int)$db->lastInsertId();
    $userSuppliedId = REGIONAL_BULK_USER_SUPPLIED_BASE + $id;
    $db->prepare('UPDATE ellsms_regional_bulk_requests SET user_supplied_id = ? WHERE id = ?')->execute([$userSuppliedId, $id]);

    $endpoint = ['province' => 'requestBulkByProvince', 'city' => 'requestBulkByCity', 'postal' => 'requestBulkByPostalCode', 'prefix' => 'requestBulkByPrefix'][$criteria['kind']];
    $payload = regional_bulk_vesal_fields($criteria) + [
        'userSuppliedId' => $userSuppliedId,
        'originator' => $originator,
        'content' => $content,
        'datetime' => $scheduledAt !== null ? str_replace(' ', 'T', $scheduledAt) : null,
        'startIndex' => 0,
        'count' => $count ?? 0,
    ];
    $r = vesal_bulk_call($endpoint, $payload);
    $reference = $r['ok'] ? (int)$r['data'] : null;
    if (!$r['ok'] && $r['transport_error']) {
        // The answer may have been lost after Vesal created the request: ask before giving up, so a
        // retry never creates a second request for the same ELLSMS row.
        $dup = vesal_bulk_call('checkDuplicateRequest', ['userSuppliedId' => $userSuppliedId]);
        $recovered = $dup['ok'] ? (int)($dup['data']['referenceId'] ?? 0) : 0;
        if ($recovered >= 1000) {
            $reference = $recovered;
            Logger::info('regional_bulk.reference_recovered', ['id' => $id, 'reference' => $recovered]);
        }
    }
    if ($reference === null) {
        regional_bulk_fail($id, (string)$r['error']);
        return ['ok' => false, 'id' => $id, 'error' => (string)$r['error']];
    }
    if ($reference < 1000) {
        $message = regional_bulk_error_message($reference);
        regional_bulk_fail($id, $message);
        return ['ok' => false, 'id' => $id, 'error' => $message];
    }
    $db->prepare('UPDATE ellsms_regional_bulk_requests SET vesal_reference_id = ? WHERE id = ?')->execute([$reference, $id]);

    $price = vesal_bulk_call('requestPrice', ['referenceId' => [$reference]]);
    $priceCode = $price['ok'] ? (int)($price['data']['errorCode'] ?? 0) : null;
    if (!$price['ok'] || $priceCode < 0 || !isset($price['data']['price'])) {
        $message = !$price['ok'] ? (string)$price['error'] : regional_bulk_error_message((int)$priceCode);
        regional_bulk_fail($id, $message);
        return ['ok' => false, 'id' => $id, 'error' => $message];
    }
    $vesalPrice = (float)$price['data']['price'];
    $credits = ($user['role'] ?? null) === 'admin' ? 0 : regional_bulk_credits_for_price($vesalPrice);
    $db->prepare("UPDATE ellsms_regional_bulk_requests SET vesal_price = ?, charged_credits = ?, status = 'priced' WHERE id = ?")->execute([$vesalPrice, $credits, $id]);
    audit((int)$user['id'], 'regional_bulk.priced', "#{$id} ref={$reference} credits={$credits}");
    return ['ok' => true, 'id' => $id];
}

/** Reserves the price and confirms at Vesal. @return array{ok:bool, error?:string} */
function regional_bulk_confirm(array $user, int $id): array {
    $row = regional_bulk_find($id, $user);
    if ($row === null) return ['ok' => false, 'error' => 'درخواست پیدا نشد.'];
    if ($row['status'] !== 'priced') return ['ok' => false, 'error' => 'این درخواست در وضعیت قابل تأیید نیست.'];
    if (!impersonation_action_allowed('send.bulk')) {
        impersonation_record_block('send.bulk');
        return ['ok' => false, 'error' => impersonation_block_message('send.bulk')];
    }
    $credits = (int)$row['charged_credits'];
    $userId = (int)$row['user_id'];
    if ($credits > 0) {
        $reservation = wallet_reserve($userId, $credits, 'regional_bulk', (string)$id, 'reserve:regional_bulk:' . $id);
        if (!$reservation['ok']) {
            return ['ok' => false, 'error' => 'اعتبار کافی نیست: این ارسال به ' . to_persian_digits((string)$credits) . ' واحد اعتبار نیاز دارد.'];
        }
    }
    // Claim the transition first so a double click cannot confirm twice.
    $claim = db()->prepare("UPDATE ellsms_regional_bulk_requests SET status = 'confirmed', confirmed_at = NOW() WHERE id = ? AND status = 'priced'");
    $claim->execute([$id]);
    if ($claim->rowCount() !== 1) return ['ok' => false, 'error' => 'این درخواست همین حالا تأیید شد.'];

    $r = vesal_bulk_call('confirmBulkRequest', ['referenceId' => [(int)$row['vesal_reference_id']]]);
    $code = $r['ok'] ? (int)($r['data']['errorCode'] ?? 0) : null;
    if ($r['ok'] && ($code >= 0 || $code === -114)) {
        audit((int)$user['id'], 'regional_bulk.confirmed', "#{$id}");
        return ['ok' => true];
    }
    if (!$r['ok'] && $r['transport_error']) {
        // Unknown outcome: keep it confirmed and let status polling tell whether Vesal is sending.
        Logger::warning('regional_bulk.confirm_unknown', ['id' => $id]);
        return ['ok' => true];
    }
    if ($credits > 0) wallet_release_reservation('regional_bulk', (string)$id);
    $message = $r['ok'] ? regional_bulk_error_message((int)$code) : (string)$r['error'];
    regional_bulk_fail($id, $message);
    return ['ok' => false, 'error' => $message];
}

/** Only before confirmation (Vesal has no cancel call). */
function regional_bulk_cancel(array $user, int $id): bool {
    $row = regional_bulk_find($id, $user);
    if ($row === null) return false;
    $st = db()->prepare("UPDATE ellsms_regional_bulk_requests SET status = 'cancelled', finished_at = NOW() WHERE id = ? AND status IN ('draft','priced')");
    $st->execute([$id]);
    return $st->rowCount() === 1;
}

/**
 * Polls confirmed/sending requests and settles finished ones: the customer pays for the share Vesal
 * reports as sent; the rest of the reservation is released.
 *
 * @return array{polled:int, finished:int}
 */
function regional_bulk_poll_pass(int $limit = 50): array {
    $stats = ['polled' => 0, 'finished' => 0];
    if (!regional_bulk_configured()) return $stats;
    $db = db();
    try {
        $st = $db->prepare("SELECT * FROM ellsms_regional_bulk_requests
                            WHERE status IN ('confirmed','sending') AND (last_polled_at IS NULL OR last_polled_at <= NOW() - INTERVAL 60 SECOND)
                            ORDER BY last_polled_at IS NOT NULL, last_polled_at LIMIT " . max(1, $limit));
        $st->execute();
    } catch (PDOException) {
        return $stats;
    }
    foreach ($st->fetchAll() as $row) {
        $claim = $db->prepare('UPDATE ellsms_regional_bulk_requests SET last_polled_at = NOW() WHERE id = ? AND (last_polled_at IS NULL OR last_polled_at <= NOW() - INTERVAL 60 SECOND)');
        $claim->execute([(int)$row['id']]);
        if ($claim->rowCount() !== 1) continue;
        $stats['polled']++;
        $r = vesal_bulk_call('bulkStatus', ['referenceId' => [(int)$row['vesal_reference_id']]]);
        if (!$r['ok'] || (int)($r['data']['errorCode'] ?? 0) < 0) continue;
        $d = $r['data'];
        $vesalState = (int)($d['bulkStatus'] ?? -1);
        $totalRequest = (int)($d['totalRequest'] ?? 0);
        $totalSent = (int)($d['totalSent'] ?? 0);
        $db->prepare("UPDATE ellsms_regional_bulk_requests SET vesal_status = ?, total_request = ?, total_sent = ?, total_delivered = ?,
                             status = IF(status = 'confirmed' AND ? > 0, 'sending', status) WHERE id = ?")
           ->execute([$vesalState, $totalRequest, $totalSent, (int)($d['totalDelivered'] ?? 0), $totalSent, (int)$row['id']]);

        $tooOld = $row['confirmed_at'] !== null && strtotime((string)$row['confirmed_at']) < time() - 7 * 86400;
        if (in_array($vesalState, REGIONAL_BULK_FINAL_VESAL_STATES, true) || $tooOld) {
            regional_bulk_settle($row, $totalRequest, $totalSent, $vesalState === 6 ? 'cancelled' : 'done');
            $stats['finished']++;
        }
    }
    return $stats;
}

function regional_bulk_settle(array $row, int $totalRequest, int $totalSent, string $finalStatus): void {
    $id = (int)$row['id'];
    $credits = (int)$row['charged_credits'];
    $share = $totalRequest > 0 ? min(1.0, max(0.0, $totalSent / $totalRequest)) : 0.0;
    $charge = (int)ceil($credits * $share);
    $db = db();
    $done = $db->prepare("UPDATE ellsms_regional_bulk_requests SET status = ?, settled_credits = ?, finished_at = NOW() WHERE id = ? AND status IN ('confirmed','sending')");
    $done->execute([$finalStatus, $charge, $id]);
    if ($done->rowCount() !== 1) return; // someone else settled it
    if ($credits > 0) {
        if ($charge > 0) {
            wallet_commit_reservation('regional_bulk', (string)$id, $charge, 'commit:regional_bulk:' . $id, null, ['sent' => $totalSent, 'requested' => $totalRequest]);
        }
        wallet_release_reservation('regional_bulk', (string)$id);
    }
    Logger::info('regional_bulk.settled', ['id' => $id, 'charged' => $charge, 'sent' => $totalSent, 'requested' => $totalRequest]);
}
