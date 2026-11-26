<?php
/**
 * ELLSMS — how many characters one SMS part holds, per gateway (docs/gateway-part-limits.md).
 *
 * The platform default is the usual one: a Persian/Unicode message is one part up to 70 characters
 * and 67 characters per part beyond that; a Latin (GSM-7) message 160 and 153. An admin can change
 * the four numbers for each gateway on «درگاه‌های پیامک» (ellsms_sms_gateways.part_fa_single,
 * part_fa_multi, part_en_single, part_en_multi — db/migrations/2026_11_29_gateway_part_limits.sql;
 * NULL = default).
 *
 * A send is counted with the numbers of the gateway its sender line goes out through —
 * sms_gateway_id_for_sender(): the gateway pinned to the number, else the gateway of the sender's
 * route (sender assignment, else the default route), else the default gateway. With no sender line, or
 * when gateway transport is off, the default numbers apply. The part count feeds pricing, the wallet
 * charge and the stored `parts`; an SMPP gateway's bridge also splits the text with the same numbers.
 */

declare(strict_types=1);

/** The platform default part sizes. */
const SMS_PART_LIMITS_DEFAULT = ['fa_single' => 70, 'fa_multi' => 67, 'en_single' => 160, 'en_multi' => 153];
/** The ellsms_sms_gateways column of each key. */
const SMS_PART_LIMIT_COLUMNS = ['fa_single' => 'part_fa_single', 'fa_multi' => 'part_fa_multi', 'en_single' => 'part_en_single', 'en_multi' => 'part_en_multi'];
/** The largest value an admin may set (an SMS carries at most 160 GSM-7 / 70 UCS-2 characters a part, with headroom). */
const SMS_PART_LIMIT_MAX = 1000;

/**
 * Pure: a complete, valid set of part sizes from $limits — a missing, empty or out-of-range value takes
 * the default, and a multi-part size larger than the single-part one is capped to it (otherwise a
 * message could need fewer parts by getting longer).
 */
function sms_part_limits_normalize(?array $limits): array {
    $out = SMS_PART_LIMITS_DEFAULT;
    foreach ($out as $key => $default) {
        $value = $limits[$key] ?? null;
        if ($value !== null && $value !== '' && is_numeric($value) && (int)$value >= 1 && (int)$value <= SMS_PART_LIMIT_MAX) {
            $out[$key] = (int)$value;
        }
    }
    $out['fa_multi'] = min($out['fa_multi'], $out['fa_single']);
    $out['en_multi'] = min($out['en_multi'], $out['en_single']);
    return $out;
}

/** Pure: whether $limits (normalized) are the platform default. */
function sms_part_limits_is_default(array $limits): bool {
    return sms_part_limits_normalize($limits) === SMS_PART_LIMITS_DEFAULT;
}

/**
 * Every gateway's own part sizes (gateway id => normalized limits), only for gateways that set at
 * least one. One query, cached for the same interval as the gateway versions, so the send path gains
 * no per-message query. Before the migration (no columns) the map is empty.
 */
function sms_gateway_part_limits_map(bool $force = false): array {
    $cached = $GLOBALS['__sms_part_limits_map'] ?? null;
    $loadedAt = (int)($GLOBALS['__sms_part_limits_map_at'] ?? 0);
    $ttl = function_exists('gateway_version_check_seconds') ? gateway_version_check_seconds() : 30;
    if (!$force && is_array($cached) && $ttl > 0 && (time() - $loadedAt) < $ttl) {
        return $cached;
    }
    $map = [];
    try {
        $rows = db()->query(
            'SELECT id, part_fa_single, part_fa_multi, part_en_single, part_en_multi FROM ellsms_sms_gateways
             WHERE part_fa_single IS NOT NULL OR part_fa_multi IS NOT NULL OR part_en_single IS NOT NULL OR part_en_multi IS NOT NULL'
        );
        foreach ($rows as $row) {
            $map[(int)$row['id']] = sms_part_limits_from_row($row);
        }
    } catch (Throwable) {
        $map = [];
    }
    $GLOBALS['__sms_part_limits_map'] = $map;
    $GLOBALS['__sms_part_limits_map_at'] = time();
    return $map;
}

/** Pure: the normalized limits of an ellsms_sms_gateways row (its part_* columns). */
function sms_part_limits_from_row(array $row): array {
    $limits = [];
    foreach (SMS_PART_LIMIT_COLUMNS as $key => $column) {
        $limits[$key] = $row[$column] ?? null;
    }
    return sms_part_limits_normalize($limits);
}

function sms_part_limits_cache_reset(): void {
    unset($GLOBALS['__sms_part_limits_map'], $GLOBALS['__sms_part_limits_map_at']);
}

/** The part sizes of gateway $gatewayId (the default for 0, an unknown gateway or one that sets none). */
function sms_gateway_part_limits(int $gatewayId): array {
    if ($gatewayId <= 0) {
        return SMS_PART_LIMITS_DEFAULT;
    }
    return sms_gateway_part_limits_map()[$gatewayId] ?? SMS_PART_LIMITS_DEFAULT;
}

/**
 * The gateway a send from $originator goes out through, or 0 when it is not known: the gateway pinned
 * to the number (numbers page), else the gateway of the route the sender resolves to (its own route
 * assignment, else the default route), else the default gateway. Same order as gateway_for_sender().
 * The count follows the sender line only: neither the message type nor destination-operator routes are
 * consulted, so one text from one line is always counted the same way (preview, charge, stored parts).
 * 0 when gateway transport is off (sends then go to the backend platform, not a gateway).
 */
function sms_gateway_id_for_sender(?string $originator): int {
    $sender = (string)(normalize_originator((string)$originator) ?? '');
    if ($sender === '' || !function_exists('gateway_transport_enabled') || !gateway_transport_enabled()) {
        return 0;
    }
    try {
        $pinned = gateway_number_pinned_id($sender);
        if ($pinned > 0) {
            return $pinned;
        }
        $route = sms_pricing_route_for_sender($sender, sms_pricing_normalize_message_type(null));
        if ($route !== null && (int)($route['gateway_id'] ?? 0) > 0) {
            return (int)$route['gateway_id'];
        }
        return $route !== null ? gateway_default_id() : 0;
    } catch (Throwable) {
        return 0;
    }
}

/** The part sizes a send from $originator is counted with (the default with no sender line). */
function sms_part_limits_for_sender(?string $originator): array {
    $map = sms_gateway_part_limits_map();
    if ($map === []) {
        return SMS_PART_LIMITS_DEFAULT; // no gateway sets its own sizes: skip the resolution
    }
    return sms_gateway_part_limits(sms_gateway_id_for_sender($originator));
}

/** sms_parts() of $content counted with the part sizes of the gateway $originator sends through. */
function sms_parts_for_sender(string $content, ?string $originator): int {
    return sms_parts($content, sms_part_limits_for_sender($originator));
}

/**
 * The part sizes of each of $originators (sender => [fa_single, fa_multi, en_single, en_multi]) for the
 * send form's live counter, plus '' => the default. Only senders whose sizes differ from the default
 * are listed; the counter uses '' for the rest.
 */
function sms_part_limits_for_senders(array $originators): array {
    $out = ['' => array_values(SMS_PART_LIMITS_DEFAULT)];
    if (sms_gateway_part_limits_map() === []) {
        return $out;
    }
    foreach ($originators as $originator) {
        $sender = (string)(normalize_originator((string)$originator) ?? '');
        if ($sender === '' || isset($out[$sender])) continue;
        $limits = sms_part_limits_for_sender($sender);
        if ($limits !== SMS_PART_LIMITS_DEFAULT) {
            $out[$sender] = array_values($limits);
        }
    }
    return $out;
}

/** The largest sizes an SMPP gateway can take: the bridge cannot put more in one SMS (smpp-bridge Codec). */
const SMS_PART_LIMITS_SMPP_MAX = ['fa_single' => 70, 'fa_multi' => 67, 'en_single' => 160, 'en_multi' => 153];

/** Persian labels of the four sizes, as on the gateway page. */
const SMS_PART_LIMIT_LABELS = [
    'fa_single' => 'فارسی — پیام تک‌پارتی',
    'fa_multi'  => 'فارسی — هر پارت پیام چندپارتی',
    'en_single' => 'انگلیسی — پیام تک‌پارتی',
    'en_multi'  => 'انگلیسی — هر پارت پیام چندپارتی',
];

/**
 * Pure: the admin's four sizes ($input: key => string from the form) checked for saving. An empty field
 * is NULL (= the default). Each value is 1–1000 (an SMPP gateway: at most 70/67/160/153, what one SMS
 * carries), and a per-part size may not exceed the single-part size of the same alphabet.
 *
 * @return array{ok:bool, values?:array<string,?int>, error?:string}
 */
function sms_part_limits_validate(array $input, bool $isSmpp = false): array {
    $values = [];
    foreach (SMS_PART_LIMITS_DEFAULT as $key => $default) {
        $raw = trim(strtr((string)($input[$key] ?? ''), ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']));
        if ($raw === '') {
            $values[$key] = null;
            continue;
        }
        if (!ctype_digit($raw)) {
            return ['ok' => false, 'error' => '«' . SMS_PART_LIMIT_LABELS[$key] . '» باید عدد باشد.'];
        }
        $value = (int)$raw;
        $max = $isSmpp ? SMS_PART_LIMITS_SMPP_MAX[$key] : SMS_PART_LIMIT_MAX;
        if ($value < 1 || $value > $max) {
            return ['ok' => false, 'error' => '«' . SMS_PART_LIMIT_LABELS[$key] . '» باید بین ۱ و ' . to_persian_digits((string)$max) . ' باشد'
                . ($isSmpp ? ' (در درگاه SMPP بیش از ظرفیت یک پیامک ممکن نیست).' : '.')];
        }
        $values[$key] = $value;
    }
    foreach (['fa', 'en'] as $alphabet) {
        $single = $values[$alphabet . '_single'] ?? SMS_PART_LIMITS_DEFAULT[$alphabet . '_single'];
        $multi = $values[$alphabet . '_multi'] ?? SMS_PART_LIMITS_DEFAULT[$alphabet . '_multi'];
        if ($multi > $single) {
            return ['ok' => false, 'error' => '«' . SMS_PART_LIMIT_LABELS[$alphabet . '_multi'] . '» نمی‌تواند از «' . SMS_PART_LIMIT_LABELS[$alphabet . '_single'] . '» بیشتر باشد.'];
        }
    }
    return ['ok' => true, 'values' => $values];
}

/** Whether ellsms_sms_gateways has the part_* columns (db/migrations/2026_11_29_gateway_part_limits.sql). */
function sms_part_limits_available(): bool {
    static $has = null;
    if ($has === null) {
        try {
            $st = db()->query("SELECT COUNT(*) FROM information_schema.columns
                               WHERE table_schema = DATABASE() AND table_name = 'ellsms_sms_gateways' AND column_name = 'part_fa_single'");
            $has = (int)$st->fetchColumn() === 1;
        } catch (Throwable) {
            $has = false;
        }
    }
    return $has;
}

/**
 * Saves gateway $gatewayId's sizes from the admin form and bumps its config version (so every worker,
 * and the SMPP bridge, picks them up). @return array{ok:bool, error?:string}
 */
function sms_gateway_part_limits_save(array $me, int $gatewayId, array $input, bool $isSmpp): array {
    if (!sms_part_limits_available()) {
        return ['ok' => false, 'error' => 'ابتدا مایگریشن 2026_11_29_gateway_part_limits.sql را اجرا کنید.'];
    }
    $check = sms_part_limits_validate($input, $isSmpp);
    if (!$check['ok']) {
        return $check;
    }
    $v = $check['values'];
    db()->prepare('UPDATE ellsms_sms_gateways SET part_fa_single = ?, part_fa_multi = ?, part_en_single = ?, part_en_multi = ? WHERE id = ?')
        ->execute([$v['fa_single'], $v['fa_multi'], $v['en_single'], $v['en_multi'], $gatewayId]);
    gateway_bump_version($gatewayId, 'part_limits', (int)$me['id'], json_encode($v, JSON_UNESCAPED_UNICODE) ?: '');
    sms_part_limits_cache_reset();
    return ['ok' => true];
}
