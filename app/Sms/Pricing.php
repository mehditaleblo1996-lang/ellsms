<?php
/**
 * ELLSMS — the ONE SMS pricing engine: operator resolution, route selection, price resolution,
 * cost arithmetic, and immutable price snapshots (docs/sms-pricing.md).
 *
 * Before this file existed, the price of an SMS was a literal `sms_parts($content) * $count`
 * expression repeated in dispatch_message(), dispatch_message_retryable(), bulk_queue_job() and the
 * cost estimator — four copies of "1 credit per segment" with no way for an operator to change it.
 * This file replaces all four with one resolution pipeline:
 *
 *     phone -> normalize -> operator (longest configured prefix)
 *                                 \
 *     sender + message_type -> route (explicit assignment, never "cheapest")
 *                                 \
 *                                  -> effective-dated price -> unit price -> cost
 *
 * INVARIANTS THIS FILE IS RESPONSIBLE FOR
 *
 *  - Route selection is EXPLICIT AND DETERMINISTIC. There is no smart routing, no price comparison,
 *    no failover, and no health-based switching anywhere in this file, by design (STEP 15/J). The
 *    route for a send is whatever the admin assigned to that sender/message type, or the single
 *    configured default — and "the single default" is a DATABASE guarantee (the generated
 *    `default_slot` UNIQUE index on ellsms_sms_routes), not a convention.
 *  - PRICING FAILS CLOSED. A recipient whose price cannot be resolved is never silently charged a
 *    guessed rate; the send is refused with a machine-readable reason. The ONE exception is the
 *    explicit, admin-visible, admin-disableable legacy fallback below.
 *  - MONEY IS INTEGER MILLICREDITS. 1 credit = SMS_PRICE_SCALE (1000) millicredits. No float ever
 *    participates in a cost computation. The wallet ledger stays denominated in whole CREDITS, so
 *    the millicredit price is converted to credits exactly once, per message, by
 *    sms_pricing_cost_for_segments() — see its docblock for why rounding happens there and nowhere
 *    else.
 *  - THE CLIENT NEVER SUPPLIES A PRICE. Every function here derives everything from server state;
 *    none of them accepts a caller-supplied unit price, route, provider or operator.
 *
 * OPERATOR DETECTION IS A CONFIGURED CLASSIFICATION, NOT A CARRIER LOOKUP. Iranian numbers are
 * portable: a 0912 number can genuinely be served by a different carrier today. Everything here
 * therefore reports `operator_source = 'prefix'` and the schema/docs deliberately avoid claiming a
 * verified current carrier. A real HLR/portability lookup would add a second operator_source value;
 * this task does not implement one.
 */

declare(strict_types=1);

/** Millicredits per credit. Prices are stored and computed as integers in this unit. */
const SMS_PRICE_SCALE = 1000;

/** The wallet/ledger unit every cost in this file is ultimately denominated in. */
const SMS_PRICING_CURRENCY = 'credit';

/**
 * Message types a route (and therefore a price) can be scoped to. 'default' is the catch-all a
 * route uses when it serves every type — it is a real configured value, not a null.
 */
const SMS_MESSAGE_TYPES = ['promotional', 'transactional', 'otp', 'default'];

/* ==========================================================================
   Configuration
   ========================================================================== */

/**
 * The type assigned to a send whose caller did not state one (the ordinary web/API send paths).
 * Deliberately a SETTING, not an env var — it is ordinary pricing configuration and belongs with the
 * rest of it (STEP 59).
 */
function sms_pricing_default_message_type(): string {
    $t = (string)sms_pricing_cached('default_message_type', static function (): string {
        try {
            $st = db()->prepare("SELECT svalue FROM ellsms_settings WHERE skey = 'sms_pricing_default_message_type'");
            $st->execute();
            return (string)($st->fetchColumn() ?: 'promotional');
        } catch (Throwable) {
            return 'promotional';
        }
    });
    return in_array($t, SMS_MESSAGE_TYPES, true) ? $t : 'promotional';
}

/** Normalizes any caller-supplied/stored message type onto the supported set, never trusting it blindly. */
function sms_pricing_normalize_message_type(?string $type): string {
    if ($type === null || $type === '') {
        return sms_pricing_default_message_type();
    }
    return in_array($type, SMS_MESSAGE_TYPES, true) ? $type : sms_pricing_default_message_type();
}

/**
 * The explicit global legacy fallback (STEP 50). When enabled (the default, so that applying this
 * feature's migration to an existing install can never cause an outage), a send that resolves to no
 * configured price is priced at exactly 1 credit per segment — verbatim the rate this product
 * charged before route pricing existed.
 *
 * It is NOT a hidden fallback: `make sms-pricing-status` prints whether it is on, the integrity
 * check reports every route that depends on it, and every snapshot/preview that used it carries
 * price_source='legacy_fallback'. An operator who has finished configuring real pricing turns it off
 * (ellsms_settings `sms_pricing_legacy_fallback` = 0) and the engine then fails closed instead.
 */
function sms_pricing_legacy_fallback_enabled(): bool {
    // Read directly (through this file's own short-TTL cache) rather than via setting(), which
    // memoizes the WHOLE settings table on its first call anywhere in the process and never
    // refreshes. That is right for request-lifetime config, but wrong for a switch a long-running
    // worker must notice an admin flipping — and it would make the switch untestable in-process.
    return (bool)sms_pricing_cached('legacy_fallback', static function (): bool {
        try {
            $st = db()->prepare("SELECT svalue FROM ellsms_settings WHERE skey = 'sms_pricing_legacy_fallback'");
            $st->execute();
            $value = $st->fetchColumn();
            return $value === false || (string)$value !== '0';
        } catch (Throwable) {
            // Settings unreachable is exactly the situation in which falling back to the pre-existing
            // behavior is safest — refusing every send because a settings row could not be read would
            // turn a degraded dependency into an outage.
            return true;
        }
    });
}

/**
 * How long resolved catalog data may be reused inside one process. Web requests never live long
 * enough for this to matter; the long-running worker (cron/worker.php) does, and must pick up an
 * admin's price change without a restart — hence a short TTL rather than a process-lifetime static
 * (STEP 17: "avoid stale long-lived cache").
 */
function sms_pricing_cache_ttl_seconds(): int {
    return max(0, (int)(env('SMS_PRICING_CACHE_TTL_SECONDS', '30') ?? '30'));
}

/** The UTC instant a pricing decision is resolved against. UTC always — never server-local (STEP 46). */
function sms_pricing_now(): string {
    return gmdate('Y-m-d H:i:s');
}

/* ==========================================================================
   Catalog access (cached, TTL-bounded)
   ========================================================================== */

/** @var array<string, array{at:int, value:mixed}> */
$GLOBALS['__sms_pricing_cache'] = [];

/** Test/CLI hook — drops every cached catalog read so the next call re-queries. */
function sms_pricing_cache_reset(): void {
    $GLOBALS['__sms_pricing_cache'] = [];
}

/** @return mixed */
function sms_pricing_cached(string $key, callable $load) {
    $ttl = sms_pricing_cache_ttl_seconds();
    $now = time();
    $entry = $GLOBALS['__sms_pricing_cache'][$key] ?? null;
    if ($entry !== null && $ttl > 0 && ($now - $entry['at']) < $ttl) {
        return $entry['value'];
    }
    $value = $load();
    $GLOBALS['__sms_pricing_cache'][$key] = ['at' => $now, 'value' => $value];
    return $value;
}

/**
 * True once the pricing tables actually exist. An install that has this code but has not yet run
 * `make db-migrations-apply` must keep sending at the old rate rather than failing every send —
 * the same "new code, old schema" window every prior phase had to survive.
 */
function sms_pricing_catalog_available(): bool {
    return (bool)sms_pricing_cached('catalog_available', static function (): bool {
        try {
            db()->query('SELECT 1 FROM ellsms_sms_routes LIMIT 1');
            return true;
        } catch (Throwable) {
            return false;
        }
    });
}

/**
 * Every ACTIVE prefix rule belonging to an ACTIVE operator, ordered longest-prefix-first.
 * Loaded ONCE and matched in memory (STEP 18) — a 50,000-recipient campaign performs exactly one
 * query for operator resolution, not 50,000.
 *
 * @return list<array{id:int,normalized_prefix:string,prefix_length:int,priority:int,operator_id:int,operator_code:string,operator_name:string}>
 */
function sms_pricing_prefix_rules(): array {
    return (array)sms_pricing_cached('prefix_rules', static function (): array {
        if (!sms_pricing_catalog_available()) {
            return [];
        }
        try {
            $rows = db()->query(
                "SELECT p.id, p.normalized_prefix, p.prefix_length, p.priority,
                        o.id AS operator_id, o.code AS operator_code, o.name AS operator_name
                 FROM ellsms_sms_operator_prefixes p
                 JOIN ellsms_sms_operators o ON o.id = p.operator_id
                 WHERE p.status = 'active' AND o.status = 'active'
                 ORDER BY p.prefix_length DESC, p.priority DESC, p.id ASC"
            )->fetchAll();
        } catch (Throwable $t) {
            Logger::error('sms_pricing.prefix_rules.failed', ['exception' => $t]);
            return [];
        }
        return array_map(static fn(array $r): array => [
            'id'                => (int)$r['id'],
            'normalized_prefix' => (string)$r['normalized_prefix'],
            'prefix_length'     => (int)$r['prefix_length'],
            'priority'          => (int)$r['priority'],
            'operator_id'       => (int)$r['operator_id'],
            'operator_code'     => (string)$r['operator_code'],
            'operator_name'     => (string)$r['operator_name'],
        ], $rows);
    });
}

/* ==========================================================================
   Prefix normalization + longest-prefix matching (STEP 4/5/17)
   ========================================================================== */

/**
 * Canonicalizes an admin-entered prefix into the same international form normalize_msisdn()
 * produces, so matching is a plain string comparison against a normalized number rather than a
 * per-format special case at match time.
 *
 *   '0912'    -> '98912'      (leading national 0 replaced by the country code)
 *   '98912'   -> '98912'      (already international)
 *   '+98 912' -> '98912'
 *   '912'     -> '98912'      (bare mobile block, the form the old OPERATOR_PREFIX_MAP used)
 *
 * Returns null for anything that is not a usable prefix — empty, non-digit, or longer than a full
 * MSISDN. Deliberately NOT a regex/wildcard language (STEP 31): prefixes only.
 */
function sms_pricing_normalize_prefix(string $raw): ?string {
    $digits = preg_replace('/[^\d+]/', '', trim($raw)) ?? '';
    if (str_starts_with($digits, '+'))  $digits = substr($digits, 1);
    if (str_starts_with($digits, '00')) $digits = substr($digits, 2);
    $digits = preg_replace('/\D/', '', $digits) ?? '';
    if ($digits === '' || strlen($digits) > 15) {
        return null;
    }
    if (str_starts_with($digits, '98')) {
        return $digits;
    }
    if (str_starts_with($digits, '0')) {
        return '98' . substr($digits, 1);
    }
    if (str_starts_with($digits, '9')) {
        return '98' . $digits;
    }
    // A non-Iranian prefix is stored as-is; matching still works, it just never collides with the
    // 98… space. (Country configuration beyond IR is admin data, not code — see ellsms_sms_operators.country_code.)
    return $digits;
}

/**
 * Deterministic LONGEST-PREFIX match (STEP 5). Pure — takes the rule set as an argument so the
 * matching rule itself is unit-testable without a database.
 *
 * Ordering is longest prefix first, then higher priority, then lowest id. That last tiebreak is
 * deliberately a stable, boring rule rather than "whichever the database returned": two ACTIVE
 * rules can never share a normalized prefix (the uniq_active_prefix index makes that impossible),
 * so reaching the id tiebreak at all means the configuration is malformed, and
 * cron/sms-pricing-integrity-check.php reports it rather than the engine silently picking at random.
 *
 * @param list<array{normalized_prefix:string,prefix_length:int,priority:int,operator_id:int,operator_code:string,operator_name?:string,id?:int}> $rules
 */
function sms_pricing_match_prefix(array $rules, string $normalizedMsisdn): ?array {
    if ($normalizedMsisdn === '') {
        return null;
    }
    $best = null;
    foreach ($rules as $rule) {
        $prefix = (string)$rule['normalized_prefix'];
        if ($prefix === '' || !str_starts_with($normalizedMsisdn, $prefix)) {
            continue;
        }
        if ($best === null) {
            $best = $rule;
            continue;
        }
        $cmp = [strlen($prefix), (int)$rule['priority'], -(int)($rule['id'] ?? PHP_INT_MAX)]
           <=> [strlen((string)$best['normalized_prefix']), (int)$best['priority'], -(int)($best['id'] ?? PHP_INT_MAX)];
        if ($cmp > 0) {
            $best = $rule;
        }
    }
    return $best;
}

/**
 * Resolve the configured operator classification for an already-normalized MSISDN.
 *
 * Returns operator_id = null / operator_code = 'unknown' when nothing matches — never a guessed
 * carrier (STEP 22). `operator_source` is always 'prefix': this is what the admin configured, not a
 * verified live carrier (STEP 6).
 */
function sms_resolve_operator(string $normalizedMsisdn): array {
    $rule = sms_pricing_match_prefix(sms_pricing_prefix_rules(), $normalizedMsisdn);
    if ($rule === null) {
        return [
            'operator_id'     => null,
            'operator_code'   => 'unknown',
            'operator_name'   => 'نامشخص',
            'operator_source' => 'prefix',
            'matched_prefix'  => null,
        ];
    }
    return [
        'operator_id'     => (int)$rule['operator_id'],
        'operator_code'   => (string)$rule['operator_code'],
        'operator_name'   => (string)($rule['operator_name'] ?? $rule['operator_code']),
        'operator_source' => 'prefix',
        'matched_prefix'  => (string)$rule['normalized_prefix'],
    ];
}

/* ==========================================================================
   Route selection (STEP 8/9/15)
   ========================================================================== */

/**
 * The route a send uses, resolved in a fixed precedence order. NOTHING here compares prices or
 * provider health — each step is a lookup that either yields exactly one row or moves on:
 *
 *   1. explicit sender assignment for this exact message type
 *   2. explicit sender assignment for message type 'default'
 *   3. the configured default route for this exact message type
 *   4. the configured default route for message type 'default'
 *
 * Every step additionally requires the owning provider to be ACTIVE — an archived provider makes all
 * of its routes unusable for NEW price resolution (STEP 8), which is why the provider join carries a
 * status filter rather than being decorative. Uniqueness at steps 1/2 (uniq_active_sender_route) and
 * 3/4 (uniq_default_route_per_type) is enforced by the schema, so "which one?" can never arise.
 */
function sms_pricing_route_for_sender(string $sender, string $messageType): ?array {
    $sender = (string)(normalize_originator($sender) ?? '');
    $messageType = sms_pricing_normalize_message_type($messageType);
    $key = 'route:' . $sender . ':' . $messageType;

    $route = sms_pricing_cached($key, static function () use ($sender, $messageType): ?array {
        if (!sms_pricing_catalog_available()) {
            return null;
        }
        try {
            $db = db();
            // r.gateway_id rides along on the SAME cached lookup the price resolution already does:
            // which gateway carries a route is route configuration, and fetching it separately would
            // add a per-send query to a path that deliberately has none.
            $select = "SELECT r.id AS route_id, r.code AS route_code, r.name AS route_name, r.message_type,
                              r.gateway_id,
                              p.id AS provider_id, p.code AS provider_code, p.name AS provider_name
                       FROM ellsms_sms_routes r
                       JOIN ellsms_sms_providers p ON p.id = r.provider_id AND p.status = 'active'";

            if ($sender !== '') {
                $st = $db->prepare(
                    "{$select}
                     JOIN ellsms_sender_routes sr ON sr.route_id = r.id AND sr.status = 'active'
                     WHERE r.status = 'active' AND sr.sender = ? AND sr.message_type IN (?, 'default')
                     ORDER BY (sr.message_type = ?) DESC, sr.priority DESC, sr.id ASC
                     LIMIT 1"
                );
                $st->execute([$sender, $messageType, $messageType]);
                $row = $st->fetch();
                if ($row) {
                    $row['selection'] = 'sender_assignment';
                    return $row;
                }
            }

            $st = $db->prepare(
                "{$select}
                 WHERE r.status = 'active' AND r.is_default = 1 AND r.message_type IN (?, 'default')
                 ORDER BY (r.message_type = ?) DESC, r.priority DESC, r.id ASC
                 LIMIT 1"
            );
            $st->execute([$messageType, $messageType]);
            $row = $st->fetch();
            if ($row) {
                $row['selection'] = 'default_route';
                return $row;
            }
            return null;
        } catch (Throwable $t) {
            Logger::error('sms_pricing.route_lookup.failed', ['sender' => $sender, 'message_type' => $messageType, 'exception' => $t]);
            return null;
        }
    });

    return is_array($route) ? $route : null;
}

/* ==========================================================================
   Price resolution (STEP 10/11)
   ========================================================================== */

/**
 * Every ACTIVE price row for one route, loaded once so a batch of recipients spanning several
 * operators costs ONE query, not one per operator.
 *
 * @return list<array<string,mixed>>
 */
function sms_pricing_prices_for_route(int $routeId): array {
    return (array)sms_pricing_cached('prices:' . $routeId, static function () use ($routeId): array {
        try {
            $st = db()->prepare(
                "SELECT id, route_id, operator_id, price_per_segment_millicredits, currency,
                        effective_from, effective_to
                 FROM ellsms_sms_route_prices
                 WHERE route_id = ? AND status = 'active'
                 ORDER BY effective_from DESC, id DESC"
            );
            $st->execute([$routeId]);
            return $st->fetchAll();
        } catch (Throwable $t) {
            Logger::error('sms_pricing.price_lookup.failed', ['route_id' => $routeId, 'exception' => $t]);
            return [];
        }
    });
}

/**
 * Picks the price in effect at $atUtc for (route, operator), following the documented precedence:
 * an operator-specific price wins over the route default. Pure — the candidate rows are passed in,
 * so the effective-dating rule itself is unit-testable without a database (STEP 46).
 *
 * The period is half-open [effective_from, effective_to): a row whose effective_to equals the
 * pricing instant has already ended, which is what makes "close the old period at T, open the new
 * one at T" produce exactly one answer at T rather than two (STEP 47).
 *
 * @param list<array<string,mixed>> $prices
 */
function sms_pricing_select_price(array $prices, ?int $operatorId, string $atUtc): ?array {
    $best = null;
    foreach ($prices as $price) {
        $priceOperator = $price['operator_id'] === null ? null : (int)$price['operator_id'];
        if ($priceOperator !== null && $priceOperator !== $operatorId) {
            continue;
        }
        if ((string)$price['effective_from'] > $atUtc) {
            continue;
        }
        if ($price['effective_to'] !== null && (string)$price['effective_to'] <= $atUtc) {
            continue;
        }
        if ($best === null) {
            $best = $price;
            continue;
        }
        // Operator-specific always beats the route default; among equals the later period wins
        // (an overlap is a configuration error the integrity check reports — this only guarantees
        // the engine still answers deterministically while it exists).
        $bestOperator = $best['operator_id'] === null ? null : (int)$best['operator_id'];
        $cmp = [$priceOperator !== null ? 1 : 0, (string)$price['effective_from'], (int)$price['id']]
           <=> [$bestOperator !== null ? 1 : 0, (string)$best['effective_from'], (int)$best['id']];
        if ($cmp > 0) {
            $best = $price;
        }
    }
    return $best;
}

/* ==========================================================================
   The resolution object (STEP 14)
   ========================================================================== */

/**
 * Full, explainable pricing decision for ONE recipient — never a bare number, so every price can be
 * traced back to the exact rule that produced it (STEP 14) and stored verbatim as a snapshot.
 */
function sms_pricing_resolution(array $route, array $operator, string $messageType, ?array $price, string $atUtc): array {
    if ($price !== null) {
        $source = $price['operator_id'] === null ? 'route_default' : 'route_operator';
        $unit   = (int)$price['price_per_segment_millicredits'];
        $ruleId = (int)$price['id'];
        $currency = (string)$price['currency'];
    } else {
        $source = 'legacy_fallback';
        $unit   = SMS_PRICE_SCALE;
        $ruleId = null;
        $currency = SMS_PRICING_CURRENCY;
    }

    return [
        'ok'              => true,
        'operator_id'     => $operator['operator_id'],
        'operator_code'   => $operator['operator_code'],
        'operator_name'   => $operator['operator_name'],
        'operator_source' => $operator['operator_source'],
        'provider_id'     => isset($route['provider_id']) ? (int)$route['provider_id'] : null,
        'provider_code'   => (string)($route['provider_code'] ?? ''),
        'route_id'        => isset($route['route_id']) ? (int)$route['route_id'] : null,
        'route_code'      => (string)($route['route_code'] ?? ''),
        'gateway_id'      => isset($route['gateway_id']) ? (int)$route['gateway_id'] : null,
        'message_type'    => $messageType,
        'unit_price'      => $unit,
        'currency'        => $currency,
        'price_source'    => $source,
        'pricing_rule_id' => $ruleId,
        'effective_at'    => $atUtc,
    ];
}

/** A refusal, in the same shape as a resolution so callers branch on one field. */
function sms_pricing_failure(string $reason, array $operator, ?array $route, string $messageType, string $atUtc): array {
    return [
        'ok'              => false,
        'reason'          => $reason,
        'operator_id'     => $operator['operator_id'],
        'operator_code'   => $operator['operator_code'],
        'operator_name'   => $operator['operator_name'],
        'operator_source' => $operator['operator_source'],
        'provider_id'     => isset($route['provider_id']) ? (int)$route['provider_id'] : null,
        'provider_code'   => (string)($route['provider_code'] ?? ''),
        'route_id'        => isset($route['route_id']) ? (int)$route['route_id'] : null,
        'route_code'      => (string)($route['route_code'] ?? ''),
        'gateway_id'      => isset($route['gateway_id']) ? (int)$route['gateway_id'] : null,
        'message_type'    => $messageType,
        'unit_price'      => 0,
        'currency'        => SMS_PRICING_CURRENCY,
        'price_source'    => 'none',
        'pricing_rule_id' => null,
        'effective_at'    => $atUtc,
    ];
}

/**
 * Resolve one recipient. Thin wrapper over the batch resolver so there is exactly one implementation
 * of the precedence rules.
 */
function sms_pricing_resolve(string $sender, string $normalizedPhone, ?string $messageType = null, ?string $atUtc = null): array {
    $batch = sms_pricing_resolve_batch($sender, [$normalizedPhone], $messageType, $atUtc);
    return $batch['by_phone'][$normalizedPhone]
        ?? sms_pricing_failure('pricing_unavailable', sms_resolve_operator($normalizedPhone), null, sms_pricing_normalize_message_type($messageType), $batch['priced_at']);
}

/**
 * Resolve a whole recipient list against ONE pricing instant (STEP 48): the route is looked up once,
 * its price rows once, the prefix table once — then every recipient is resolved in memory. A bulk
 * acceptance therefore cannot straddle a price change halfway through, and cannot issue N queries
 * for N numbers (STEP 18's explicit no-N+1 requirement).
 *
 * @param list<string> $normalizedPhones
 */
function sms_pricing_resolve_batch(string $sender, array $normalizedPhones, ?string $messageType = null, ?string $atUtc = null): array {
    $messageType = sms_pricing_normalize_message_type($messageType);
    $atUtc       = $atUtc ?? sms_pricing_now();
    $route       = sms_pricing_route_for_sender($sender, $messageType);
    $fallbackOk  = sms_pricing_legacy_fallback_enabled();
    $prices      = $route !== null ? sms_pricing_prices_for_route((int)$route['route_id']) : [];

    $byPhone = [];
    $operatorCache = [];
    foreach ($normalizedPhones as $phone) {
        $phone = (string)$phone;
        $operator = $operatorCache[$phone] ??= sms_resolve_operator($phone);

        if ($route === null) {
            // No usable route at all (nothing configured yet, or every candidate's provider is
            // archived). The legacy fallback is the only thing standing between this and a refusal.
            $byPhone[$phone] = $fallbackOk
                ? sms_pricing_resolution([], $operator, $messageType, null, $atUtc)
                : sms_pricing_failure('route_unavailable', $operator, null, $messageType, $atUtc);
            continue;
        }

        $price = sms_pricing_select_price($prices, $operator['operator_id'], $atUtc);
        if ($price !== null) {
            $byPhone[$phone] = sms_pricing_resolution($route, $operator, $messageType, $price, $atUtc);
            continue;
        }
        if ($fallbackOk) {
            $byPhone[$phone] = sms_pricing_resolution($route, $operator, $messageType, null, $atUtc);
            continue;
        }
        // Fail closed, with a reason precise enough to act on (STEP 44).
        $reason = $operator['operator_id'] === null ? 'operator_unknown_no_default_price' : 'route_price_missing';
        $byPhone[$phone] = sms_pricing_failure($reason, $operator, $route, $messageType, $atUtc);
    }

    return [
        'route'        => $route,
        'message_type' => $messageType,
        'priced_at'    => $atUtc,
        'by_phone'     => $byPhone,
    ];
}

/* ==========================================================================
   Cost arithmetic
   ========================================================================== */

/**
 * Cost of ONE message, in whole credits. This is the ONLY place millicredits become credits, and it
 * happens per MESSAGE — deliberately not per batch.
 *
 * Rounding at the message level (rather than summing millicredits across a job and rounding once at
 * the end) is what makes a price reproducible: the cost of a recipient is a property of that
 * recipient's own segments and rate, so splitting a job, retrying one row, or reporting on a single
 * message all produce the same number as the original acceptance. Rounding at the aggregate would
 * make a row's cost depend on which other rows it happened to be batched with, which no per-item
 * reconciliation could ever reproduce.
 *
 * Rounds UP (ceil): a fractional credit is not spendable, and rounding down would let a configured
 * rate be undercharged systematically.
 */
function sms_pricing_cost_for_segments(int $segments, int $unitPriceMillicredits): int {
    if ($segments <= 0 || $unitPriceMillicredits <= 0) {
        return 0;
    }
    return intdiv($segments * $unitPriceMillicredits + SMS_PRICE_SCALE - 1, SMS_PRICE_SCALE);
}

/** Human-facing credit figure for a millicredit price. Display only — never fed back into arithmetic. */
function sms_pricing_millicredits_to_credits(int $millicredits): float {
    return $millicredits / SMS_PRICE_SCALE;
}

/** Parses an admin-entered credit price ("1.25") into integer millicredits, or null if unusable. */
function sms_pricing_credits_to_millicredits(string $raw): ?int {
    $raw = trim(from_persian_digits($raw));
    if ($raw === '' || !preg_match('/^\d+(\.\d{1,3})?$/', $raw)) {
        return null;
    }
    [$whole, $frac] = array_pad(explode('.', $raw, 2), 2, '');
    $frac = str_pad($frac, 3, '0');
    return (int)$whole * SMS_PRICE_SCALE + (int)$frac;
}

/**
 * The instant a NEW price period may start for (route, operator), given the instant the admin
 * intended.
 *
 * `effective_from` has one-second granularity and (route, operator_slot, effective_from) is UNIQUE,
 * so replacing a rate created within the SAME second would collide and roll the whole change back —
 * and an admin correcting a rate immediately after entering it is completely ordinary. This returns
 * the first free instant at or after $preferred instead.
 *
 * Advancing the START (rather than rewriting the existing row) is what keeps the two periods
 * strictly non-overlapping AND gapless: the previous period is closed at exactly this instant, and
 * half-open comparison means the old rate stays in effect right up to it. No send can ever land in a
 * window with no price.
 *
 * Bounded so a pathological catalog cannot hang a request; a caller that exhausts the bound simply
 * gets the last instant tried and the unique index still refuses a genuine duplicate.
 */
function sms_pricing_next_effective_from(int $routeId, ?int $operatorId, string $preferred): string {
    try {
        $st = db()->prepare(
            'SELECT COUNT(*) FROM ellsms_sms_route_prices
             WHERE route_id = ? AND operator_slot = ? AND effective_from = ?'
        );
    } catch (Throwable) {
        return $preferred;
    }
    $candidate = $preferred;
    for ($attempt = 0; $attempt < 60; $attempt++) {
        $st->execute([$routeId, $operatorId ?? 0, $candidate]);
        if ((int)$st->fetchColumn() === 0) {
            return $candidate;
        }
        $candidate = gmdate('Y-m-d H:i:s', strtotime($candidate . ' UTC') + 1);
    }
    return $candidate;
}

/**
 * Stable identity for one (route, operator, unit price) pricing group. Every message sharing this
 * key was priced by exactly the same decision, which is what lets a snapshot aggregate them without
 * losing any reconciliation precision (STEP 24).
 */
function sms_pricing_group_key(array $resolution): string {
    return hash('sha256', implode('|', [
        (string)($resolution['route_id'] ?? ''),
        (string)($resolution['operator_id'] ?? ''),
        (string)$resolution['unit_price'],
        (string)$resolution['message_type'],
        (string)$resolution['price_source'],
    ]));
}

/* ==========================================================================
   The pricing pass a send actually calls
   ========================================================================== */

/**
 * Price a concrete set of messages. THE entry point for both preview and real sends — Invariant E
 * ("Cost Preview and actual send use the same pricing engine") is true because both call this exact
 * function, not because two implementations were kept in step by hand.
 *
 * $messages is [['mobile' => normalizedMsisdn, 'segments' => int], ...] — segments always come from
 * sms_parts() upstream, never recomputed here, so pricing and segmentation can never disagree.
 *
 * $exempt mirrors the long-standing platform-admin exemption (dispatch_message() charges an admin
 * nothing). An exempt pass still RESOLVES everything (so the snapshot records what the send would
 * have cost) but never refuses: an unpriceable recipient costs an exempt sender zero rather than
 * blocking a send that was always free.
 *
 * Returns ok=false when any recipient could not be priced and the sender is not exempt — fail
 * closed (Invariant H). The refusal still carries the full per-recipient reason map so the UI can
 * show "10,000 input / 9,850 priced / 150 unpriced" (STEP 44).
 */
function sms_pricing_price_messages(
    array $messages,
    string $sender,
    ?string $messageType = null,
    ?string $atUtc = null,
    bool $exempt = false
): array {
    $messageType = sms_pricing_normalize_message_type($messageType);
    $atUtc       = $atUtc ?? sms_pricing_now();

    $phones = [];
    foreach ($messages as $m) {
        $phones[(string)$m['mobile']] = true;
    }
    $batch = sms_pricing_resolve_batch($sender, array_keys($phones), $messageType, $atUtc);

    $perMobile   = [];
    $perIndex    = [];
    $groups      = [];
    $unpriced    = [];
    $totalCost   = 0;
    $totalSegs   = 0;
    $fallbackUsed = false;

    foreach ($messages as $index => $m) {
        $mobile   = (string)$m['mobile'];
        $segments = (int)$m['segments'];
        $totalSegs += $segments;

        $resolution = $batch['by_phone'][$mobile] ?? null;
        if ($resolution === null || !$resolution['ok']) {
            $reason = $resolution['reason'] ?? 'pricing_unavailable';
            $unpriced[$mobile] = $reason;
            Metrics::increment('sms_pricing.resolution_failure', 1, ['reason' => (string)$reason]);
            if ($reason === 'operator_unknown_no_default_price') {
                Metrics::increment('sms_pricing.unknown_operator', 1);
            } elseif ($reason === 'route_price_missing') {
                Metrics::increment('sms_pricing.missing_price', 1);
            }
            if ($exempt) {
                $entry = ['mobile' => $mobile, 'segments' => $segments, 'cost' => 0, 'group_key' => null] + ($resolution ?? []);
                $perMobile[$mobile] = $entry;
                $perIndex[$index]   = $entry;
            }
            continue;
        }

        if ($resolution['price_source'] === 'legacy_fallback') {
            $fallbackUsed = true;
        }
        $cost = $exempt ? 0 : sms_pricing_cost_for_segments($segments, (int)$resolution['unit_price']);
        $totalCost += $cost;

        $key = sms_pricing_group_key($resolution);
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'group_key'       => $key,
                'operator_id'     => $resolution['operator_id'],
                'operator_code'   => $resolution['operator_code'],
                'operator_name'   => $resolution['operator_name'],
                'operator_source' => $resolution['operator_source'],
                'provider_id'     => $resolution['provider_id'],
                'provider_code'   => $resolution['provider_code'],
                'route_id'        => $resolution['route_id'],
                'route_code'      => $resolution['route_code'],
                'message_type'    => $resolution['message_type'],
                'unit_price'      => (int)$resolution['unit_price'],
                'unit_price_credits' => sms_pricing_millicredits_to_credits((int)$resolution['unit_price']),
                'currency'        => $resolution['currency'],
                'price_source'    => $resolution['price_source'],
                'pricing_rule_id' => $resolution['pricing_rule_id'],
                'recipients'      => 0,
                'segments'        => 0,
                'cost'            => 0,
            ];
        }
        if ($exempt) {
            // The resolution stays intact (the snapshot still records which rule WOULD have
            // applied), but the group is labelled for what it is: a send this principal is never
            // charged for, matching dispatch_message()'s own long-standing admin exemption.
            $groups[$key]['price_source'] = 'admin_exempt';
        }
        $groups[$key]['recipients']++;
        $groups[$key]['segments'] += $segments;
        $groups[$key]['cost']     += $cost;

        $entry = $resolution + ['mobile' => $mobile, 'segments' => $segments, 'cost' => $cost, 'group_key' => $key];
        // Keyed BOTH ways deliberately. per_mobile is what settlement needs (the gateway answers
        // with destinations, not indexes) and is safe there because a dispatch's destination list is
        // already deduplicated. per_index is what a bulk job needs: a file may legitimately contain
        // the same number twice with DIFFERENT personalized bodies, so those two rows genuinely have
        // two different segment counts and two different costs, and collapsing them by mobile would
        // charge one row the other's price.
        $perMobile[$mobile] = $entry;
        $perIndex[$index]   = $entry;
        Metrics::increment('sms_pricing.resolution_total', 1, ['source' => (string)$resolution['price_source']]);
    }

    $ok = $exempt || $unpriced === [];

    return [
        'ok'              => $ok,
        'reason'          => $ok ? null : 'pricing_unavailable',
        'priced_at'       => $atUtc,
        'message_type'    => $messageType,
        'currency'        => SMS_PRICING_CURRENCY,
        'exempt'          => $exempt,
        'recipient_count' => count($messages),
        'priced_count'    => count($messages) - count($unpriced),
        'unpriced_count'  => count($unpriced),
        'total_segments'  => $totalSegs,
        'total_cost'      => $totalCost,
        'per_mobile'      => $perMobile,
        'per_index'       => $perIndex,
        'groups'          => array_values($groups),
        'unpriced'        => $unpriced,
        'legacy_fallback_used' => $fallbackUsed,
        'route'           => $batch['route'],
    ];
}

/**
 * Convenience wrapper for the overwhelmingly common "one body, many recipients" send. Segments are
 * computed once from the shared content — the identical sms_parts() value dispatch_message_raw()
 * will report back.
 *
 * @param list<string> $destinations already normalized
 */
function sms_pricing_price_single_content(array $destinations, string $content, string $sender, ?string $messageType = null, ?string $atUtc = null, bool $exempt = false): array {
    $segments = sms_parts($content);
    $messages = array_map(static fn($d) => ['mobile' => (string)$d, 'segments' => $segments], $destinations);
    $priced = sms_pricing_price_messages($messages, $sender, $messageType, $atUtc, $exempt);
    $priced['segments_per_message'] = $segments;
    return $priced;
}

/**
 * Price a single-content send that may be a RETRY of an operation already accepted at a price.
 *
 * A retryable send (a scheduled occurrence, an auto-reply) can dispatch several times against ONE
 * wallet reservation taken at the first attempt. Re-resolving the current rate on attempt 3 would
 * be wrong twice over: the settled amount could exceed the reservation the first attempt took, and
 * the customer would be charged a rate that was not in effect when their send was accepted
 * (STEP 24: "retry must reuse the accepted price snapshot").
 *
 * So when a snapshot already exists for this operation, this re-resolves against the ORIGINAL
 * pricing instant (which the effective-dating model already guarantees returns the original rate)
 * and then, belt-and-braces, overrides any unit price the snapshot recorded differently — covering
 * the case where an admin archived or rewrote the very rule the original decision came from.
 */
function sms_pricing_price_single_content_for_reference(
    array $destinations,
    string $content,
    string $sender,
    ?string $messageType,
    bool $exempt,
    string $referenceType,
    string $referenceId
): array {
    $snapshot = sms_price_snapshot_for($referenceType, $referenceId);
    if ($snapshot === []) {
        return sms_pricing_price_single_content($destinations, $content, $sender, $messageType, null, $exempt);
    }

    $originalInstant = (string)$snapshot[0]['priced_at'];
    $originalType    = (string)$snapshot[0]['message_type'];
    $priced = sms_pricing_price_single_content($destinations, $content, $sender, $originalType, $originalInstant, $exempt);
    return sms_pricing_apply_snapshot_prices($priced, $snapshot);
}

/**
 * Forces an already-resolved pricing pass onto the unit prices a snapshot recorded, keyed by
 * (route, operator) — the pair that identifies "the same pricing decision" across attempts. Group
 * and per-recipient totals are recomputed from the overridden unit price, never patched, so the
 * arithmetic stays internally consistent.
 */
function sms_pricing_apply_snapshot_prices(array $priced, array $snapshotRows): array {
    $accepted = [];
    foreach ($snapshotRows as $row) {
        $accepted[(string)($row['route_id'] ?? '') . ':' . (string)($row['operator_id'] ?? '')] = (int)$row['unit_price_millicredits'];
    }

    $groups = [];
    $totalCost = 0;
    foreach ($priced['per_mobile'] as $mobile => $entry) {
        if (!($entry['ok'] ?? false)) {
            continue;
        }
        $key = (string)($entry['route_id'] ?? '') . ':' . (string)($entry['operator_id'] ?? '');
        if (isset($accepted[$key]) && $accepted[$key] !== (int)$entry['unit_price']) {
            Logger::info('sms_pricing.retry_used_accepted_price', [
                'route_id' => $entry['route_id'], 'operator_id' => $entry['operator_id'],
                'current' => $entry['unit_price'], 'accepted' => $accepted[$key],
            ]);
            $entry['unit_price'] = $accepted[$key];
        }
        $entry['cost'] = ($priced['exempt'] ?? false) ? 0 : sms_pricing_cost_for_segments((int)$entry['segments'], (int)$entry['unit_price']);
        $entry['group_key'] = sms_pricing_group_key($entry);
        $totalCost += (int)$entry['cost'];

        $gk = $entry['group_key'];
        $groups[$gk] ??= [
            'group_key' => $gk,
            'operator_id' => $entry['operator_id'], 'operator_code' => $entry['operator_code'],
            'operator_name' => $entry['operator_name'], 'operator_source' => $entry['operator_source'],
            'provider_id' => $entry['provider_id'], 'provider_code' => $entry['provider_code'],
            'route_id' => $entry['route_id'], 'route_code' => $entry['route_code'],
            'message_type' => $entry['message_type'],
            'unit_price' => (int)$entry['unit_price'],
            'unit_price_credits' => sms_pricing_millicredits_to_credits((int)$entry['unit_price']),
            'currency' => $entry['currency'],
            'price_source' => ($priced['exempt'] ?? false) ? 'admin_exempt' : $entry['price_source'],
            'pricing_rule_id' => $entry['pricing_rule_id'],
            'recipients' => 0, 'segments' => 0, 'cost' => 0,
        ];
        $groups[$gk]['recipients']++;
        $groups[$gk]['segments'] += (int)$entry['segments'];
        $groups[$gk]['cost']     += (int)$entry['cost'];

        $priced['per_mobile'][$mobile] = $entry;
    }

    $priced['groups']     = array_values($groups);
    $priced['total_cost'] = $totalCost;
    return $priced;
}

/**
 * Settlement amount for the destinations the gateway actually accepted. Never re-resolves a price:
 * it reads the ALREADY-ACCEPTED per-recipient costs, so a price change between acceptance and the
 * gateway response cannot alter what this send settles at (Invariant G).
 *
 * The degenerate branch exists because dispatch_message_raw()'s destination list comes from the
 * backend's response: if a destination comes back in a form we cannot map (or does not come back at
 * all while the count says something did send), we settle at the LOWEST accepted per-recipient
 * costs rather than guessing upward — under-settling is recoverable and customer-safe, and it can
 * never exceed the reservation. It is logged, not silently absorbed.
 *
 * @param list<string> $sentDestinations
 * @return array{cost:int, by_group:array<string,int>}
 */
function sms_pricing_settlement(array $priced, array $sentDestinations, int $sentCount): array {
    $perMobile = $priced['per_mobile'] ?? [];
    $cost = 0;
    $byGroup = [];
    $matched = 0;

    foreach ($sentDestinations as $destination) {
        $key = (string)$destination;
        $entry = $perMobile[$key] ?? $perMobile[(string)(normalize_msisdn($key) ?? '')] ?? null;
        if ($entry === null) {
            continue;
        }
        $matched++;
        $cost += (int)$entry['cost'];
        if ($entry['group_key'] !== null) {
            $byGroup[$entry['group_key']] = ($byGroup[$entry['group_key']] ?? 0) + (int)$entry['cost'];
        }
    }

    if ($matched === $sentCount) {
        return ['cost' => $cost, 'by_group' => $byGroup];
    }

    Logger::warning('sms_pricing.settlement_destination_mismatch', [
        'sent_count' => $sentCount, 'matched' => $matched, 'priced' => count($perMobile),
    ]);

    $costs = [];
    foreach ($perMobile as $entry) {
        $costs[] = ['cost' => (int)$entry['cost'], 'group_key' => $entry['group_key']];
    }
    usort($costs, static fn(array $a, array $b): int => $a['cost'] <=> $b['cost']);
    $cost = 0;
    $byGroup = [];
    foreach (array_slice($costs, 0, max(0, $sentCount)) as $entry) {
        $cost += $entry['cost'];
        if ($entry['group_key'] !== null) {
            $byGroup[$entry['group_key']] = ($byGroup[$entry['group_key']] ?? 0) + $entry['cost'];
        }
    }
    return ['cost' => $cost, 'by_group' => $byGroup];
}

/* ==========================================================================
   Price snapshots (STEP 23/24/45)
   ========================================================================== */

/**
 * Persist the authoritative pricing decision for an accepted send, one row per pricing group.
 *
 * Replay-safe by construction: UNIQUE (reference_type, reference_id, group_key) plus an explicitly
 * no-op ON DUPLICATE KEY clause means a worker retry writing the same snapshot changes nothing —
 * the FIRST acceptance's price is the one that survives, which is exactly Invariant G.
 *
 * Never throws into a send path: a snapshot is an accounting record, and failing to write one must
 * not roll back a send whose money movement already succeeded. It is logged loudly instead.
 */
function sms_price_snapshot_record(array $priced, ?int $organizationId, ?int $userId, string $referenceType, string $referenceId): void {
    if (($priced['groups'] ?? []) === []) {
        return;
    }
    try {
        $st = db()->prepare(
            "INSERT INTO ellsms_sms_price_snapshots
               (organization_id, user_id, reference_type, reference_id, group_key,
                operator_id, operator_code, operator_source, provider_id, provider_code,
                route_id, route_code, message_type, unit_price_millicredits, currency,
                price_source, pricing_rule_id, recipient_count, segment_count,
                total_cost_credits, committed_cost_credits, status, priced_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,'accepted',?)
             ON DUPLICATE KEY UPDATE id = id"
        );
        foreach ($priced['groups'] as $group) {
            $st->execute([
                $organizationId ?: null,
                $userId ?: null,
                $referenceType,
                $referenceId,
                $group['group_key'],
                $group['operator_id'],
                $group['operator_code'],
                $group['operator_source'],
                $group['provider_id'],
                $group['provider_code'],
                $group['route_id'],
                $group['route_code'],
                $group['message_type'],
                $group['unit_price'],
                $group['currency'],
                $group['price_source'],
                $group['pricing_rule_id'],
                $group['recipients'],
                $group['segments'],
                $group['cost'],
                $priced['priced_at'],
            ]);
            Metrics::gauge('sms_pricing.cost_by_provider', (float)$group['cost'], ['provider' => (string)$group['provider_code']]);
        }
    } catch (Throwable $t) {
        Logger::error('sms_pricing.snapshot_write_failed', [
            'reference_type' => $referenceType, 'reference_id' => $referenceId, 'exception' => $t,
        ]);
    }
}

/**
 * Record what an accepted pricing decision actually settled at. Touches ONLY the settlement columns
 * — the price, rule id, operator, route and priced_at written at acceptance are never updated, so a
 * historical report reads the same rate forever no matter what an admin changes later.
 */
function sms_price_snapshot_settle(string $referenceType, string $referenceId, array $committedByGroup): void {
    try {
        $st = db()->prepare(
            "UPDATE ellsms_sms_price_snapshots
             SET committed_cost_credits = ?, status = 'settled'
             WHERE reference_type = ? AND reference_id = ? AND group_key = ?"
        );
        foreach ($committedByGroup as $groupKey => $amount) {
            $st->execute([max(0, (int)$amount), $referenceType, $referenceId, (string)$groupKey]);
        }
    } catch (Throwable $t) {
        Logger::error('sms_pricing.snapshot_settle_failed', [
            'reference_type' => $referenceType, 'reference_id' => $referenceId, 'exception' => $t,
        ]);
    }
}

/**
 * Adds an amount to a snapshot group's settled total — the bulk case, where items settle one at a
 * time across many worker passes rather than all at once. Additive rather than absolute for exactly
 * that reason.
 */
function sms_price_snapshot_add_settlement(string $referenceType, string $referenceId, string $groupKey, int $amount): void {
    if ($amount <= 0) {
        return;
    }
    try {
        db()->prepare(
            "UPDATE ellsms_sms_price_snapshots
             SET committed_cost_credits = committed_cost_credits + ?, status = 'settled'
             WHERE reference_type = ? AND reference_id = ? AND group_key = ?"
        )->execute([$amount, $referenceType, $referenceId, $groupKey]);
    } catch (Throwable $t) {
        Logger::error('sms_pricing.snapshot_settle_failed', [
            'reference_type' => $referenceType, 'reference_id' => $referenceId, 'exception' => $t,
        ]);
    }
}

/** Every snapshot group for one operation — what historical cost reporting reads (STEP 45). */
function sms_price_snapshot_for(string $referenceType, string $referenceId): array {
    try {
        $st = db()->prepare(
            'SELECT * FROM ellsms_sms_price_snapshots WHERE reference_type = ? AND reference_id = ? ORDER BY id'
        );
        $st->execute([$referenceType, $referenceId]);
        return $st->fetchAll();
    } catch (Throwable) {
        return [];
    }
}
