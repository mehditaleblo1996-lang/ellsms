<?php
/**
 * ELLSMS — the central entitlement & quota decision service (Phase 13, STEP 10).
 *
 * THE one place that answers "may this organization do this, and does it have room?" — for the web
 * UI, the public API, and background workers alike (Invariant M: all three use these same
 * functions, never their own copy of the rule). Controllers never read a plan table directly.
 *
 * Fail-closed philosophy, matching app/authorization.php (Phase 2) / app/tenant.php (Phase 6) /
 * app/rbac.php (Phase 7): an unknown entitlement key, an unknown limit key, or a subscription in a
 * non-serviceable state all resolve to "no", never to "allow". The ONE deliberate exception is
 * spelled out in entitlement_context()'s own docblock and is required by Invariant L.
 *
 * Race safety (Invariant E/F) is achieved two different ways depending on what is being limited,
 * and both are genuine database-level guarantees, not application conventions:
 *
 *   USAGE METERS (messages, API requests) — a single atomic conditional UPDATE:
 *       UPDATE ... SET reserved = reserved + N WHERE ... AND (used + reserved + N) <= limit
 *     MySQL evaluates the predicate and applies the write under one row lock, so two concurrent
 *     reservations for the last slot cannot both succeed; rowCount() tells the caller which one
 *     won. This is the same "conditional UPDATE as the claim primitive" pattern Phase 4 proved for
 *     bulk-item claiming, applied to quota.
 *
 *   RESOURCE COUNTS (API keys, contacts, members, webhooks, ...) — entitlement_with_resource_slot()
 *     runs the count AND the caller's INSERT inside one transaction that first takes a row lock on
 *     the organization, so concurrent creates for the same tenant serialize. Counting live from the
 *     owning table means the number can never drift from reality (unlike a maintained counter), and
 *     holding the lock across the count->insert window is what closes the read-then-write race
 *     STEP 16 explicitly rejects.
 */

declare(strict_types=1);

/* ==========================================================================
   Decision context
   ========================================================================== */

/**
 * Resolves everything needed to decide entitlement/limit questions for one organization:
 *   ['mode', 'serviceable', 'status', 'plan_id', 'plan_code', 'entitlements', 'limits', 'subscription']
 *
 * `mode` is one of:
 *   'billing_disabled'  — BILLING_ENABLED=0. Everything allowed, everything unlimited. This is the
 *                         shipped default, so an install that never opts into billing behaves
 *                         EXACTLY as it did before Phase 13 (Invariant L, STEP 59).
 *   'grandfathered'     — billing is on, but this organization has no subscription row (it predates
 *                         the backfill, or the backfill hasn't run). Treated as unlimited rather
 *                         than locked out — Invariant L is explicit that migration must never lock
 *                         out an existing customer. cron/subscription-integrity-check.php REPORTS
 *                         every organization in this state, so the gap is visible, never silent.
 *   'plan'              — a real subscription governs this organization.
 *
 * Deliberately uncached: a suspension, plan change, or lifecycle transition must take effect on the
 * very next check, including inside a long-running worker process — the same "no stale
 * authorization cache" rule Phase 12's API-key lookup follows.
 */
function entitlement_context(int $organizationId): array {
    if (!billing_enabled()) {
        return [
            'mode' => 'billing_disabled', 'serviceable' => true, 'status' => null,
            'plan_id' => null, 'plan_code' => null, 'entitlements' => [], 'limits' => [], 'subscription' => null,
        ];
    }

    $subscription = subscription_for_organization($organizationId);
    if ($subscription === null) {
        // CRITICAL distinction (see subscription_latest_for_organization()'s docblock): an
        // organization that has NEVER had a subscription is grandfathered/unlimited, but one whose
        // subscription LAPSED (suspended/cancelled/expired — all of which clear the effective
        // lookup) must fail closed. Collapsing these two cases would mean suspending an
        // organization made it MORE permissive, which is precisely inverted from Invariant K.
        $lapsed = subscription_latest_for_organization($organizationId);
        if ($lapsed === null) {
            return [
                'mode' => 'grandfathered', 'serviceable' => true, 'status' => null,
                'plan_id' => null, 'plan_code' => null, 'entitlements' => [], 'limits' => [], 'subscription' => null,
            ];
        }
        $lapsedPlanId = (int)$lapsed['plan_id'];
        return [
            'mode'         => 'plan',
            'serviceable'  => false, // never true here — every status reaching this branch is terminal
            'status'       => $lapsed['status'],
            'plan_id'      => $lapsedPlanId,
            'plan_code'    => $lapsed['plan_code'],
            // The lapsed plan's limits are still reported so the billing UI and usage-status can show
            // the customer what they had; organization_has_entitlement() gates on `serviceable`
            // before ever consulting this map, so nothing is actually granted by its presence.
            'entitlements' => billing_plan_entitlements($lapsedPlanId),
            'limits'       => billing_plan_limits($lapsedPlanId),
            'subscription' => $lapsed,
        ];
    }

    $planId = (int)$subscription['plan_id'];
    return [
        'mode'         => 'plan',
        'serviceable'  => in_array($subscription['status'], BILLING_SERVICEABLE_STATUSES, true),
        'status'       => $subscription['status'],
        'plan_id'      => $planId,
        'plan_code'    => $subscription['plan_code'],
        'entitlements' => billing_plan_entitlements($planId),
        'limits'       => billing_plan_limits($planId),
        'subscription' => $subscription,
    ];
}

/**
 * True if $organizationId's subscription currently includes $entitlementKey.
 *
 * Fail-closed on every ambiguous input: an unknown/uncataloged key is always false regardless of
 * plan (so a typo in a controller denies access rather than granting it), and a non-serviceable
 * subscription (suspended/cancelled/expired) is always false for every entitlement — STEP 12's
 * "expired/suspended subscriptions fail predictably" (Invariant K).
 */
function organization_has_entitlement(int $organizationId, string $entitlementKey): bool {
    if (!Entitlements::isValid($entitlementKey)) {
        Logger::warning('billing.entitlement.unknown_key', ['entitlement_key' => $entitlementKey, 'organization_id' => $organizationId]);
        return false;
    }
    $ctx = entitlement_context($organizationId);
    if ($ctx['mode'] !== 'plan') {
        return true; // billing_disabled / grandfathered — see entitlement_context()'s docblock
    }
    if (!$ctx['serviceable']) {
        return false;
    }
    // A plan that simply doesn't mention an entitlement does NOT get it — absence is denial, so
    // adding a new entitlement to the catalog never silently grants it to every existing plan.
    return $ctx['entitlements'][$entitlementKey] ?? false;
}

/**
 * The organization's numeric limit for $limitKey, or null for UNLIMITED. An unknown key returns 0
 * (deny) rather than null (unlimited) — the fail-closed reading.
 */
function organization_limit(int $organizationId, string $limitKey): ?int {
    if (!Limits::isValid($limitKey)) {
        Logger::warning('billing.limit.unknown_key', ['limit_key' => $limitKey, 'organization_id' => $organizationId]);
        return 0;
    }
    $ctx = entitlement_context($organizationId);
    if ($ctx['mode'] !== 'plan') {
        return null; // unlimited
    }
    if (!array_key_exists($limitKey, $ctx['limits'])) {
        return null; // a plan that doesn't constrain this resource leaves it unlimited
    }
    return $ctx['limits'][$limitKey]['value'];
}

/** 'hard' (block) or 'soft' (allow + warn). Unknown/unconstrained defaults to 'hard' — the stricter reading. */
function organization_limit_enforcement(int $organizationId, string $limitKey): string {
    $ctx = entitlement_context($organizationId);
    return $ctx['limits'][$limitKey]['enforcement'] ?? 'hard';
}

/* ==========================================================================
   Usage periods (STEP 17) — ALWAYS UTC, never server-local time
   ========================================================================== */

/**
 * [periodStart, periodEnd] as UTC 'Y-m-d H:i:s' strings for $limitKey's reset period.
 * A non-meter key has no period; callers must not ask (guarded by Limits::isMeter()).
 */
function usage_period_bounds(string $limitKey, ?int $now = null): array {
    $now = $now ?? time();
    return match (Limits::resetPeriod($limitKey)) {
        'daily'   => [gmdate('Y-m-d 00:00:00', $now), gmdate('Y-m-d 00:00:00', $now + 86400)],
        'monthly' => [
            gmdate('Y-m-01 00:00:00', $now),
            gmdate('Y-m-01 00:00:00', billing_add_months($now, 1)),
        ],
        default   => [gmdate('Y-m-d 00:00:00', $now), gmdate('Y-m-d 00:00:00', $now)],
    };
}

/**
 * Current ['used','reserved','limit','remaining'] for a metered limit. `limit`/`remaining` are null
 * when unlimited. Read-only — never creates a counter row (a report must not have write side
 * effects).
 */
function organization_usage(int $organizationId, string $limitKey): array {
    $limit = organization_limit($organizationId, $limitKey);
    if (!Limits::isMeter($limitKey)) {
        return ['used' => 0, 'reserved' => 0, 'limit' => $limit, 'remaining' => null];
    }
    [$periodStart] = usage_period_bounds($limitKey);
    $st = db()->prepare('SELECT used, reserved FROM ellsms_usage_counters WHERE organization_id = ? AND metric_key = ? AND period_start = ?');
    $st->execute([$organizationId, $limitKey, $periodStart]);
    $row = $st->fetch();
    $used = (int)($row['used'] ?? 0);
    $reserved = (int)($row['reserved'] ?? 0);
    return [
        'used' => $used,
        'reserved' => $reserved,
        'limit' => $limit,
        'remaining' => $limit === null ? null : max(0, $limit - $used - $reserved),
        'period_start' => $periodStart,
    ];
}

function organization_remaining_quota(int $organizationId, string $limitKey): ?int {
    return organization_usage($organizationId, $limitKey)['remaining'];
}

/** Ensures the (organization, metric, period) counter row exists. The ON DUPLICATE branch is a deliberate no-op self-assignment so this never disturbs live counters. */
function usage_ensure_counter_row(PDO $db, int $organizationId, string $limitKey): string {
    [$periodStart, $periodEnd] = usage_period_bounds($limitKey);
    $db->prepare(
        'INSERT INTO ellsms_usage_counters (organization_id, metric_key, period_start, period_end, used, reserved)
         VALUES (?,?,?,?,0,0) ON DUPLICATE KEY UPDATE id = id'
    )->execute([$organizationId, $limitKey, $periodStart, $periodEnd]);
    return $periodStart;
}

/* ==========================================================================
   Usage reservation (STEP 19) — reserve on accept, commit on send, release on failure
   ========================================================================== */

function usage_reservation_ttl_minutes(): int {
    return max(1, (int)(env('USAGE_RESERVATION_TTL_MINUTES', '60') ?? '60'));
}

/**
 * Atomically reserves $amount of $limitKey for $organizationId, tied to ($referenceType,
 * $referenceId) so a retry of the SAME business operation replays instead of consuming twice.
 *
 * Returns ['ok'=>bool, 'reason'=>string, 'replayed'=>bool, 'remaining'=>?int].
 * reason 'quota_exceeded' means the request genuinely didn't fit.
 *
 * The conditional UPDATE below is the entire race-safety story — see this file's header. There is
 * deliberately no SELECT-then-compare anywhere in this function.
 */
function usage_reserve(int $organizationId, string $limitKey, int $amount, string $referenceType, string $referenceId): array {
    // BILLING_ENABLED=0 makes the entire quota subsystem a true no-op — no counter rows, no
    // reservation rows, not one extra write on the send path. An install that never opts into
    // billing therefore behaves byte-for-byte as it did before Phase 13, which is exactly what
    // STEP 59's "default must preserve existing deployments safely" requires. Usage IS tracked once
    // billing is enabled, even on unlimited plans, because the usage dashboard and reconciliation
    // tooling need real numbers to report.
    if (!billing_enabled()) {
        return ['ok' => true, 'reason' => 'billing_disabled', 'replayed' => false, 'remaining' => null];
    }
    if ($amount <= 0) {
        return ['ok' => true, 'reason' => 'nothing_to_reserve', 'replayed' => false, 'remaining' => null];
    }
    if (!Limits::isMeter($limitKey)) {
        return ['ok' => false, 'reason' => 'not_a_meter', 'replayed' => false, 'remaining' => null];
    }

    $limit = organization_limit($organizationId, $limitKey);
    if ($limit === null) {
        // Unlimited: still record the reservation so commit/release and reconciliation behave
        // identically on every plan (one code path, not two), but no cap needs enforcing.
        return usage_reserve_unlimited($organizationId, $limitKey, $amount, $referenceType, $referenceId);
    }

    return db_transaction(function (PDO $db) use ($organizationId, $limitKey, $amount, $referenceType, $referenceId, $limit): array {
        // A prior reservation for this exact operation means this is a retry — replay it rather than
        // reserving a second time (the invariant that makes worker retries quota-safe).
        $existing = $db->prepare('SELECT id, status, amount FROM ellsms_usage_reservations WHERE reference_type = ? AND reference_id = ? AND metric_key = ?');
        $existing->execute([$referenceType, $referenceId, $limitKey]);
        if ($row = $existing->fetch()) {
            return ['ok' => true, 'reason' => 'replayed', 'replayed' => true, 'status' => $row['status'], 'remaining' => null];
        }

        $periodStart = usage_ensure_counter_row($db, $organizationId, $limitKey);

        $claim = $db->prepare(
            'UPDATE ellsms_usage_counters SET reserved = reserved + ?
             WHERE organization_id = ? AND metric_key = ? AND period_start = ?
               AND (used + reserved + ?) <= ?'
        );
        $claim->execute([$amount, $organizationId, $limitKey, $periodStart, $amount, $limit]);
        if ($claim->rowCount() !== 1) {
            Logger::warning('billing.quota.exceeded', ['organization_id' => $organizationId, 'metric_key' => $limitKey, 'requested' => $amount, 'limit' => $limit]);
            Metrics::increment('billing.quota.exceeded', 1, ['metric_key' => $limitKey]);
            return ['ok' => false, 'reason' => 'quota_exceeded', 'replayed' => false, 'remaining' => organization_remaining_quota($organizationId, $limitKey)];
        }

        $db->prepare(
            'INSERT INTO ellsms_usage_reservations (organization_id, metric_key, period_start, amount, status, reference_type, reference_id, expires_at)
             VALUES (?,?,?,?,\'active\',?,?,?)'
        )->execute([$organizationId, $limitKey, $periodStart, $amount, $referenceType, $referenceId, gmdate('Y-m-d H:i:s', time() + usage_reservation_ttl_minutes() * 60)]);

        Metrics::increment('billing.quota.reserved', $amount, ['metric_key' => $limitKey]);
        return ['ok' => true, 'reason' => 'reserved', 'replayed' => false, 'remaining' => null];
    });
}

/** Unlimited-plan path — same bookkeeping, no cap check. Kept separate so the capped path above stays a single unbroken atomic statement. */
function usage_reserve_unlimited(int $organizationId, string $limitKey, int $amount, string $referenceType, string $referenceId): array {
    return db_transaction(function (PDO $db) use ($organizationId, $limitKey, $amount, $referenceType, $referenceId): array {
        $existing = $db->prepare('SELECT id, status FROM ellsms_usage_reservations WHERE reference_type = ? AND reference_id = ? AND metric_key = ?');
        $existing->execute([$referenceType, $referenceId, $limitKey]);
        if ($row = $existing->fetch()) {
            return ['ok' => true, 'reason' => 'replayed', 'replayed' => true, 'status' => $row['status'], 'remaining' => null];
        }
        $periodStart = usage_ensure_counter_row($db, $organizationId, $limitKey);
        $db->prepare('UPDATE ellsms_usage_counters SET reserved = reserved + ? WHERE organization_id = ? AND metric_key = ? AND period_start = ?')
           ->execute([$amount, $organizationId, $limitKey, $periodStart]);
        $db->prepare(
            'INSERT INTO ellsms_usage_reservations (organization_id, metric_key, period_start, amount, status, reference_type, reference_id, expires_at)
             VALUES (?,?,?,?,\'active\',?,?,?)'
        )->execute([$organizationId, $limitKey, $periodStart, $amount, $referenceType, $referenceId, gmdate('Y-m-d H:i:s', time() + usage_reservation_ttl_minutes() * 60)]);
        return ['ok' => true, 'reason' => 'reserved', 'replayed' => false, 'remaining' => null];
    });
}

/**
 * Converts an active reservation into consumed usage (reserved -> used). Idempotent: committing an
 * already-committed reservation is a safe no-op, so a worker that crashes after committing and
 * retries the same job cannot double-count (the core "retries do not double-count" guarantee).
 *
 * $actualAmount lets a caller commit LESS than reserved (e.g. only 3 of 5 destinations actually
 * sent); the unused remainder is released back automatically.
 */
function usage_commit(string $referenceType, string $referenceId, string $limitKey, ?int $actualAmount = null): array {
    if (!billing_enabled()) {
        return ['ok' => true, 'reason' => 'billing_disabled', 'committed' => 0];
    }
    return db_transaction(function (PDO $db) use ($referenceType, $referenceId, $limitKey, $actualAmount): array {
        $st = $db->prepare('SELECT * FROM ellsms_usage_reservations WHERE reference_type = ? AND reference_id = ? AND metric_key = ? FOR UPDATE');
        $st->execute([$referenceType, $referenceId, $limitKey]);
        $res = $st->fetch();
        if (!$res) {
            return ['ok' => true, 'reason' => 'no_reservation', 'committed' => 0];
        }
        if ($res['status'] !== 'active') {
            return ['ok' => true, 'reason' => 'already_finalized', 'committed' => 0];
        }

        $reserved = (int)$res['amount'];
        $commit = $actualAmount === null ? $reserved : max(0, min($actualAmount, $reserved));
        $release = $reserved - $commit;

        // reserved always drops by the FULL reserved amount; used rises only by what was actually
        // consumed — so the released remainder returns to available quota in the same statement.
        $db->prepare('UPDATE ellsms_usage_counters SET reserved = GREATEST(0, reserved - ?), used = used + ? WHERE organization_id = ? AND metric_key = ? AND period_start = ?')
           ->execute([$reserved, $commit, (int)$res['organization_id'], $limitKey, $res['period_start']]);
        $db->prepare("UPDATE ellsms_usage_reservations SET status = 'committed', amount = ?, finalized_at = UTC_TIMESTAMP() WHERE id = ?")
           ->execute([$commit, $res['id']]);

        Metrics::increment('billing.quota.committed', $commit, ['metric_key' => $limitKey]);
        return ['ok' => true, 'reason' => 'committed', 'committed' => $commit, 'released' => $release];
    });
}

/**
 * Returns an active reservation's quota unconsumed (validation failure, enqueue failure, cancelled
 * job). Idempotent and deliberately refuses to touch an already-COMMITTED reservation — STEP 49's
 * "do not release quota for a message already accepted/sent".
 */
function usage_release(string $referenceType, string $referenceId, string $limitKey): array {
    if (!billing_enabled()) {
        return ['ok' => true, 'reason' => 'billing_disabled', 'released' => 0];
    }
    return db_transaction(function (PDO $db) use ($referenceType, $referenceId, $limitKey): array {
        $st = $db->prepare('SELECT * FROM ellsms_usage_reservations WHERE reference_type = ? AND reference_id = ? AND metric_key = ? FOR UPDATE');
        $st->execute([$referenceType, $referenceId, $limitKey]);
        $res = $st->fetch();
        if (!$res) {
            return ['ok' => true, 'reason' => 'no_reservation', 'released' => 0];
        }
        if ($res['status'] !== 'active') {
            return ['ok' => true, 'reason' => 'already_finalized', 'released' => 0];
        }
        $amount = (int)$res['amount'];
        $db->prepare('UPDATE ellsms_usage_counters SET reserved = GREATEST(0, reserved - ?) WHERE organization_id = ? AND metric_key = ? AND period_start = ?')
           ->execute([$amount, (int)$res['organization_id'], $limitKey, $res['period_start']]);
        $db->prepare("UPDATE ellsms_usage_reservations SET status = 'released', finalized_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$res['id']]);

        Metrics::increment('billing.quota.released', $amount, ['metric_key' => $limitKey]);
        return ['ok' => true, 'reason' => 'released', 'released' => $amount];
    });
}

/**
 * Convenience wrapper for the common message-send shape: reserves against BOTH the monthly and the
 * daily message meter, rolling the monthly reservation back if the daily one doesn't fit, so a
 * caller never ends up half-reserved. Returns the same shape as usage_reserve().
 */
function usage_reserve_messages(int $organizationId, int $count, string $referenceType, string $referenceId): array {
    $monthly = usage_reserve($organizationId, Limits::MONTHLY_MESSAGES, $count, $referenceType, $referenceId);
    if (!$monthly['ok']) {
        return $monthly + ['metric' => Limits::MONTHLY_MESSAGES];
    }
    $daily = usage_reserve($organizationId, Limits::DAILY_MESSAGES, $count, $referenceType, $referenceId);
    if (!$daily['ok']) {
        usage_release($referenceType, $referenceId, Limits::MONTHLY_MESSAGES);
        return $daily + ['metric' => Limits::DAILY_MESSAGES];
    }
    return ['ok' => true, 'reason' => $monthly['replayed'] ? 'replayed' : 'reserved', 'replayed' => $monthly['replayed']];
}

function usage_commit_messages(string $referenceType, string $referenceId, ?int $actualCount = null): void {
    usage_commit($referenceType, $referenceId, Limits::MONTHLY_MESSAGES, $actualCount);
    usage_commit($referenceType, $referenceId, Limits::DAILY_MESSAGES, $actualCount);
}

function usage_release_messages(string $referenceType, string $referenceId): void {
    usage_release($referenceType, $referenceId, Limits::MONTHLY_MESSAGES);
    usage_release($referenceType, $referenceId, Limits::DAILY_MESSAGES);
}

/* ==========================================================================
   Resource-count limits (STEP 15/16)
   ========================================================================== */

/** Live COUNT(*) for a resource-count limit, from its owning table (Limits::resourceSource()). Never drifts, because nothing caches it. */
function entitlement_current_resource_count(int $organizationId, string $limitKey, ?PDO $db = null): int {
    $source = Limits::resourceSource($limitKey);
    if ($source === null) {
        return 0;
    }
    [$table, $column, $extraWhere] = $source;
    $db = $db ?? db();
    // $table/$column/$extraWhere are hard-coded constants from Limits::resourceSource(), never
    // request input — there is no interpolation of caller-supplied data here.
    $sql = "SELECT COUNT(*) c FROM {$table} WHERE {$column} = ?" . ($extraWhere !== null ? " AND {$extraWhere}" : '');
    $st = $db->prepare($sql);
    $st->execute([$organizationId]);
    return (int)$st->fetch()['c'];
}

/**
 * THE race-safe resource-creation guard (STEP 16). Runs $create() only if the organization has room
 * for one more $limitKey, with the count and the creation inside ONE transaction holding a row lock
 * on the organization — so two concurrent "create the last API key" requests genuinely serialize
 * and exactly one succeeds.
 *
 * $create receives the open PDO and must perform its INSERT on it (not via a fresh db() call that
 * would escape the transaction — db() returns the same singleton, so this is naturally satisfied).
 *
 * Returns ['ok'=>true, 'result'=>mixed] or ['ok'=>false, 'reason'=>'resource_limit_reached',
 * 'limit'=>int, 'current'=>int].
 */
function entitlement_with_resource_slot(int $organizationId, string $limitKey, callable $create): array {
    $limit = organization_limit($organizationId, $limitKey);
    if ($limit === null) {
        return ['ok' => true, 'result' => $create(db()), 'limit' => null];
    }

    return db_transaction(function (PDO $db) use ($organizationId, $limitKey, $limit, $create): array {
        // The serialization point. ellsms_organizations always has exactly one row per tenant, so
        // this is a guaranteed-present, naturally per-tenant lock — concurrent creates for OTHER
        // organizations are entirely unaffected.
        $db->prepare('SELECT id FROM ellsms_organizations WHERE id = ? FOR UPDATE')->execute([$organizationId]);

        $current = entitlement_current_resource_count($organizationId, $limitKey, $db);
        if ($current >= $limit) {
            Logger::warning('billing.resource_limit.reached', ['organization_id' => $organizationId, 'limit_key' => $limitKey, 'current' => $current, 'limit' => $limit]);
            Metrics::increment('billing.resource_limit.rejected', 1, ['limit_key' => $limitKey]);
            return ['ok' => false, 'reason' => 'resource_limit_reached', 'limit' => $limit, 'current' => $current];
        }

        return ['ok' => true, 'result' => $create($db), 'limit' => $limit, 'current' => $current];
    });
}

/** Read-only capacity check for UI/preflight. NEVER a substitute for entitlement_with_resource_slot() at the actual creation point (Invariant D: UI visibility is not authorization). */
function organization_has_resource_capacity(int $organizationId, string $limitKey): bool {
    $limit = organization_limit($organizationId, $limitKey);
    return $limit === null || entitlement_current_resource_count($organizationId, $limitKey) < $limit;
}

/* ==========================================================================
   Web-context gates (mirror require_permission()'s shape in app/rbac.php)
   ========================================================================== */

/**
 * Web gate: the organization's plan must include $entitlementKey, else 403 + exit. Deliberately
 * separate from require_permission() and always used ALONGSIDE it, never instead of it (STEP 11's
 * enforcement order: subscription state -> plan entitlement -> RBAC -> quota).
 */
function require_entitlement(int $organizationId, string $entitlementKey): void {
    if (organization_has_entitlement($organizationId, $entitlementKey)) {
        return;
    }
    http_response_code(403);
    Logger::warning('billing.entitlement.denied', ['organization_id' => $organizationId, 'entitlement_key' => $entitlementKey]);
    Metrics::increment('billing.entitlement.denied', 1, ['entitlement_key' => $entitlementKey]);
    $ctx = entitlement_context($organizationId);
    echo !$ctx['serviceable']
        ? 'اشتراک سازمان شما فعال نیست. برای ادامه‌ی استفاده، وضعیت اشتراک را بررسی کنید.'
        : 'این قابلیت در پلن فعلی سازمان شما موجود نیست. برای استفاده، پلن خود را ارتقا دهید.';
    exit;
}

/** True if paid capabilities currently work for this organization (STEP 12) — false for suspended/cancelled/expired. */
function organization_subscription_serviceable(int $organizationId): bool {
    return entitlement_context($organizationId)['serviceable'];
}

/**
 * The effective per-request cap for a bounded value: the smallest of the system maximum and the
 * plan's own limit (STEP 23/24 — a plan can only ever LOWER a system safety cap, never raise it).
 */
function entitlement_effective_cap(int $organizationId, string $limitKey, int $systemMaximum): int {
    $planLimit = organization_limit($organizationId, $limitKey);
    return $planLimit === null ? $systemMaximum : max(1, min($systemMaximum, $planLimit));
}

/* ==========================================================================
   Reporting
   ========================================================================== */

/**
 * The complete plan/subscription/usage picture for one organization, in one shape, used by BOTH
 * the customer-facing page (public/billing.php) and the operational CLI (cron/usage-status.php) —
 * deliberately one function rather than two, so what an operator sees while debugging is exactly
 * what the customer sees, derived from the same central decisions the actual gates use.
 *
 * Read-only; never creates a usage counter row.
 */
function usage_status_for(int $organizationId): array {
    $orgSt = db()->prepare('SELECT id, name, status FROM ellsms_organizations WHERE id = ?');
    $orgSt->execute([$organizationId]);
    $org = $orgSt->fetch();
    if (!$org) {
        return ['error' => 'organization_not_found', 'organization_id' => $organizationId];
    }

    $ctx = entitlement_context($organizationId);
    $subscription = $ctx['subscription'];

    $entitlements = [];
    foreach (Entitlements::all() as $key) {
        $entitlements[$key] = organization_has_entitlement($organizationId, $key);
    }

    $limits = [];
    $overLimit = [];
    foreach (Limits::all() as $key) {
        $limit = organization_limit($organizationId, $key);
        if (Limits::isMeter($key)) {
            $usage = organization_usage($organizationId, $key);
            $limits[$key] = [
                'limit' => $limit, 'used' => $usage['used'], 'reserved' => $usage['reserved'],
                'remaining' => $usage['remaining'], 'period_start' => $usage['period_start'] ?? null,
                'kind' => 'meter',
            ];
        } elseif (Limits::resourceSource($key) !== null) {
            $current = entitlement_current_resource_count($organizationId, $key);
            $limits[$key] = ['limit' => $limit, 'used' => $current, 'reserved' => 0, 'remaining' => $limit === null ? null : max(0, $limit - $current), 'kind' => 'resource'];
            if ($limit !== null && $current > $limit) {
                // Legitimately reachable after a downgrade — nothing is ever deleted to force
                // compliance (Invariant J), so this is reported, never auto-corrected.
                $overLimit[] = ['limit_key' => $key, 'current' => $current, 'limit' => $limit];
            }
        } else {
            $limits[$key] = ['limit' => $limit, 'kind' => 'per_request'];
        }
    }

    return [
        'organization_id'      => (int)$org['id'],
        'organization_name'    => $org['name'],
        'organization_status'  => $org['status'],
        'billing_enabled'      => billing_enabled(),
        'mode'                 => $ctx['mode'],
        'plan_id'              => $ctx['plan_id'],
        'plan_code'            => $ctx['plan_code'],
        'subscription_status'  => $ctx['status'],
        'serviceable'          => $ctx['serviceable'],
        'current_period_start' => $subscription['current_period_start'] ?? null,
        'current_period_end'   => $subscription['current_period_end'] ?? null,
        'trial_ends_at'        => $subscription['trial_ends_at'] ?? null,
        'grace_ends_at'        => $subscription['grace_ends_at'] ?? null,
        'cancel_at_period_end' => isset($subscription['cancel_at_period_end']) ? (bool)$subscription['cancel_at_period_end'] : false,
        'pending_plan_id'      => $subscription['pending_plan_id'] ?? null,
        'entitlements'         => $entitlements,
        'limits'               => $limits,
        'over_limit'           => $overLimit,
    ];
}
