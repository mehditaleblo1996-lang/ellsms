<?php
/**
 * ELLSMS — the ONE pre-send cost estimator (Cost Preview feature).
 *
 * Answers "what will this send cost, and can it go through?" for the web UI and the public API
 * alike. Deliberately the only place that composes that answer, so a preview can never disagree
 * with what the real send will actually do.
 *
 * READ-ONLY BY CONSTRUCTION (Invariant A/B). Nothing in this file writes to any table. It calls
 * only:
 *   - sms_parts()            (app/bootstrap.php)  — the EXISTING segmentation source of truth
 *   - normalize_msisdn()     (app/bootstrap.php)  — the EXISTING number normalization
 *   - filter_blacklist()     (app/bootstrap.php)  — read-only blacklist lookup (SELECT only)
 *   - can_use_originator()   (app/authorization.php) — the EXISTING sender authorization rule
 *   - wallet_balance()       (app/wallet.php)     — read-only, never currentcredit
 *   - organization_usage()   (app/Entitlements.php) — read-only quota
 *
 * Personalized bodies are rendered by the CALLER (via the same render_bulk_template() the send page
 * already uses) and handed to estimate_bulk_cost() already-rendered — the identical
 * [['mobile'=>..,'content'=>..]] shape bulk_queue_job() receives. That is deliberate: the estimator
 * prices the exact strings that will be queued, rather than re-rendering them through a second code
 * path that could drift from the first.
 * Every one of those is the same function the real send path uses, which is what makes
 * Invariant C/D (identical pricing and segmentation) true by construction rather than by
 * convention. There is no second formula anywhere.
 *
 * PRICING SOURCE OF TRUTH — app/Sms/Pricing.php, and nothing else.
 *
 * This file originally documented (and implemented) a hard-coded `cost = sms_parts(content) *
 * recipient_count` — one credit per segment, with an explicit note that no per-operator or
 * per-route pricing existed anywhere in the product. That is no longer true: pricing is now
 * admin-configured (operators, prefixes, providers, routes, effective-dated route/operator rates),
 * and this estimator resolves it through sms_pricing_price_messages() — THE SAME FUNCTION
 * dispatch_message(), dispatch_message_retryable() and bulk_queue_job() call before they reserve
 * from the wallet. Invariant E (preview and send price identically) is therefore true by
 * construction again, exactly as it was when both sides shared one literal expression.
 *
 * The wallet ledger stays denominated in whole CREDITS; a configured rate lives in millicredits and
 * becomes credits once per message inside sms_pricing_cost_for_segments(). `rial_per_credit`
 * (ellsms_settings, managed in Settings) remains the Rial value of one credit — used ONLY to render
 * a human-readable Rial figure alongside the credit figure; it is never part of wallet arithmetic.
 *
 * FAIL CLOSED: a recipient whose price cannot be resolved does not get a guessed rate. The estimate
 * refuses with `reason = 'pricing_unavailable'` and reports exactly how many recipients were
 * priced vs. unpriced, and why (STEP 44), so no confirmation can proceed on an ambiguous cost.
 *
 * ADMIN EXEMPTION: dispatch_message() charges a platform admin nothing (`$isAdmin ? 0 : ...`, a
 * long-standing documented product rule). The estimator mirrors that exactly rather than showing a
 * cost that would never be debited — and, like the send path, an exempt estimate never fails closed
 * on an unpriceable recipient, because the answer is zero either way.
 */

declare(strict_types=1);

/** Bumped whenever the estimate's shape or arithmetic changes, so a stored/echoed preview can be recognized as stale. ('2' = route/operator-aware pricing.) */
const COST_PREVIEW_ESTIMATOR_VERSION = '2';

/** Above this many resolved recipients, a personalized bulk preview switches from exact per-recipient counting to a sampled estimate (STEP 28) and says so. */
function cost_preview_exact_recipient_ceiling(): int {
    return max(100, (int)(env('COST_PREVIEW_EXACT_RECIPIENT_LIMIT', '20000') ?? '20000'));
}

/** How long a UI preview stays valid before the confirm step insists on a fresh one (STEP 22). */
function cost_preview_ttl_seconds(): int {
    return max(30, (int)(env('COST_PREVIEW_TTL_SECONDS', '300') ?? '300'));
}

/** Cost change (percent) between preview and confirmation that forces re-confirmation instead of silently proceeding (STEP 21/22). */
function cost_preview_reconfirm_percent(): float {
    return max(0.0, (float)(env('COST_PREVIEW_RECONFIRM_PERCENT', '5') ?? '5'));
}

/* ==========================================================================
   Segmentation analysis (STEP 3/4)
   ========================================================================== */

/**
 * Per-message segment analysis. Segment COUNT comes from sms_parts() — never recomputed here, so
 * the preview and the charge can never disagree (Invariant D). This function only adds the
 * descriptive fields a preview needs around that number (encoding label, character count,
 * remaining room in the current segment).
 *
 * The encoding test mirrors sms_parts()'s own: anything outside printable ASCII + CR/LF forces
 * UCS-2. That means a Persian message is ALWAYS unicode (70/67 char segments), which is exactly
 * why "one recipient = one SMS" is a wrong assumption this preview exists to correct.
 */
function cost_estimate_segments(string $content): array {
    $isUnicode = (bool)preg_match('/[^\x20-\x7E\r\n]/u', $content);
    $length    = mb_strlen($content, 'UTF-8');
    $segments  = sms_parts($content);

    $singleLimit = $isUnicode ? 70 : 160;
    $multiLimit  = $isUnicode ? 67 : 153;

    if ($segments <= 1) {
        // Room left before this message would need a second segment at all.
        $remaining = max(0, $singleLimit - $length);
    } else {
        // Room left in the final concatenated segment.
        $used = $length - ($multiLimit * ($segments - 1));
        $remaining = max(0, $multiLimit - $used);
    }

    return [
        'encoding'                    => $isUnicode ? 'unicode' : 'gsm7',
        'characters'                  => $length,
        'segments'                     => $segments,
        'concatenated'                => $segments > 1,
        'single_segment_limit'        => $singleLimit,
        'concatenated_segment_limit'  => $multiLimit,
        'characters_remaining_in_segment' => $remaining,
    ];
}

/* ==========================================================================
   Pricing (STEP 6/7)
   ========================================================================== */

/** The Rial value of one credit — display only, never part of wallet arithmetic. */
function cost_rial_per_credit(): int {
    // Deliberately best-effort: if ellsms_settings is unreachable, the estimate still returns a
    // correct CREDIT cost and simply omits the Rial figure (0 = "unknown", which every rendering
    // surface already guards on). A display nicety must never be able to fail a preview whose
    // actual answer — the credit cost — is fully computable without it.
    try {
        return (int)(setting('rial_per_credit', '1000') ?? '1000');
    } catch (Throwable) {
        return 0;
    }
}

/**
 * Renders a completed sms_pricing_price_messages() pass into the estimate's `pricing` block.
 *
 * `credits_per_segment` survives from the pre-route-pricing shape because it is the single number
 * most surfaces (and the public API) actually display — but it is now only populated when EVERY
 * priced recipient shares one unit price, which is the common case (one route, one rate) and always
 * true for a legacy-parity install. When rates genuinely differ across operators it is null and the
 * min/max pair plus `groups` carry the truth instead: showing one averaged "unit price" over a
 * mixed-rate send would be a number the customer is never actually charged (STEP 21).
 *
 * $isAdmin mirrors dispatch_message()'s own exemption so a preview shown to a platform admin
 * reports the zero cost that will genuinely be debited.
 */
function cost_pricing_block(array $priced, bool $isAdmin = false): array {
    $groups = $priced['groups'] ?? [];
    $unitPrices = array_values(array_unique(array_map(static fn(array $g): int => (int)$g['unit_price'], $groups)));
    $uniform = count($unitPrices) === 1;

    $sources = array_values(array_unique(array_map(static fn(array $g): string => (string)$g['price_source'], $groups)));
    $priceSource = $isAdmin ? 'admin_exempt' : (count($sources) === 1 ? $sources[0] : ($sources === [] ? 'none' : 'mixed'));

    return [
        'unit'                => 'credit_per_segment',
        // The unit `estimated_cost` and every wallet figure are denominated in: CREDITS. (The
        // previous shape reported 'IRR' here, which described `rial_per_credit`, not the cost —
        // now reported separately as rial_currency so neither number is ambiguous.)
        'currency'            => SMS_PRICING_CURRENCY,
        'credits_per_segment' => $isAdmin ? 0 : ($uniform ? sms_pricing_millicredits_to_credits($unitPrices[0]) : null),
        'unit_price_millicredits'     => $isAdmin ? 0 : ($uniform ? $unitPrices[0] : null),
        'unit_price_min_millicredits' => $unitPrices === [] ? null : min($unitPrices),
        'unit_price_max_millicredits' => $unitPrices === [] ? null : max($unitPrices),
        'price_source'        => $priceSource,
        'message_type'        => $priced['message_type'] ?? sms_pricing_default_message_type(),
        'legacy_fallback_used' => (bool)($priced['legacy_fallback_used'] ?? false),
        'groups'              => cost_pricing_public_groups($groups),
        'rial_per_credit'     => cost_rial_per_credit(),
        'rial_currency'       => 'IRR',
        'estimator_version'   => COST_PREVIEW_ESTIMATOR_VERSION,
        'priced_at'           => $priced['priced_at'] ?? gmdate('Y-m-d H:i:s'),
    ];
}

/**
 * The operator/provider/route breakdown a preview may safely expose (STEP 20/21). Deliberately
 * carries no provider configuration beyond its display code/name — never credentials, never
 * endpoint/transport detail, which live entirely outside the pricing catalog anyway.
 */
function cost_pricing_public_groups(array $groups): array {
    return array_map(static fn(array $g): array => [
        'operator'      => $g['operator_code'],
        'operator_name' => $g['operator_name'],
        'provider'      => $g['provider_code'],
        'route'         => $g['route_code'],
        'message_type'  => $g['message_type'],
        'recipients'    => $g['recipients'],
        'segments'      => $g['segments'],
        'unit_price'    => $g['unit_price_credits'],
        'unit_price_millicredits' => $g['unit_price'],
        'price_source'  => $g['price_source'],
        'cost'          => $g['cost'],
    ], array_values($groups));
}

/**
 * Turns a failed pricing pass into the estimate's refusal, carrying the counts STEP 44's UX needs
 * ("10,000 input / 9,850 priced / 150 unpriced") and the machine-readable reason per bucket.
 */
function cost_pricing_failure(array $priced, array $recipients): array {
    $reasons = [];
    foreach ($priced['unpriced'] as $reason) {
        $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
    }
    arsort($reasons);
    return [
        'ok'      => false,
        'reason'  => 'pricing_unavailable',
        'recipients' => $recipients,
        'pricing_failure' => [
            'priced_count'   => $priced['priced_count'],
            'unpriced_count' => $priced['unpriced_count'],
            'reasons'        => $reasons,
        ],
    ];
}

/* ==========================================================================
   Recipient eligibility (STEP 8)
   ========================================================================== */

/**
 * Runs the raw recipient input through the EXACT pipeline a real send uses — normalize, dedupe,
 * blacklist — and reports what each stage removed. Read-only: filter_blacklist() only SELECTs, and
 * nothing here touches contact or suppression state (STEP 8's explicit "do not permanently mutate
 * contacts or suppression data during preview").
 *
 * $rawDestinations may be a raw textarea string (parsed exactly as the send form parses it) or an
 * already-split array (the API shape).
 */
function cost_analyze_recipients(int $userId, string|array $rawDestinations, bool $applyBlacklist = true): array {
    if (is_string($rawDestinations)) {
        $rawTokens = preg_split('/[\s,;،]+/u', $rawDestinations, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    } else {
        $rawTokens = array_values(array_filter(array_map(static fn($d) => is_scalar($d) ? trim((string)$d) : '', $rawDestinations), static fn($d) => $d !== ''));
    }
    $inputCount = count($rawTokens);

    // Normalize each token individually so "invalid" and "duplicate" can be reported separately —
    // parse_destinations() collapses both into one result, which is right for sending but loses the
    // distinction a preview needs to explain itself.
    $normalized = [];
    $invalid = 0;
    foreach ($rawTokens as $token) {
        $n = normalize_msisdn($token);
        if ($n === null) {
            $invalid++;
            continue;
        }
        $normalized[] = $n;
    }
    $unique = array_values(array_unique($normalized));
    $duplicates = count($normalized) - count($unique);

    $blacklisted = 0;
    $eligible = $unique;
    if ($applyBlacklist && $unique !== []) {
        [$eligible, $blacklisted] = filter_blacklist($userId, $unique);
    }

    return [
        'input_count'      => $inputCount,
        'invalid_count'    => $invalid,
        'duplicate_count'  => $duplicates,
        'blacklisted_count' => $blacklisted,
        'eligible_count'   => count($eligible),
        'eligible'         => $eligible,
    ];
}

/* ==========================================================================
   Wallet & quota preview (STEP 10/11)
   ========================================================================== */

/**
 * Read-only wallet view. Uses wallet_balance() (the Phase 3 ledger) as the source of truth, never
 * user_.currentcredit — that column is a compatibility projection, not authoritative (STEP 10).
 *
 * `sufficient` is a point-in-time observation, explicitly NOT a guarantee: another operation can
 * spend the balance between this call and confirmation. The real send re-checks atomically under a
 * row lock, and that is what actually protects the balance (Invariant G/H).
 */
function cost_wallet_preview(int $userId, int $estimatedCredits): array {
    $balance = wallet_balance($userId);
    return [
        'balance'             => $balance['available'],
        'reserved'            => $balance['reserved'],
        'estimated_cost'      => $estimatedCredits,
        'estimated_remaining' => $balance['available'] - $estimatedCredits,
        'sufficient'          => $balance['available'] >= $estimatedCredits,
    ];
}

/**
 * Read-only quota view against Phase 13's meters. Consumes nothing (Invariant A) — the real send
 * reserves atomically via usage_reserve_messages().
 *
 * Quota is counted in MESSAGES (one per recipient), deliberately not segments: that is exactly how
 * usage_reserve_messages() is called from the send paths, so the preview matches what will actually
 * be reserved. Cost is counted in segments. The two units genuinely differ, and conflating them
 * would make one of the two previews wrong.
 */
function cost_quota_preview(int $organizationId, int $recipientCount): array {
    if ($organizationId <= 0 || !billing_enabled()) {
        return [
            'enforced'          => false,
            'estimated_usage'   => $recipientCount,
            'sufficient'        => true,
        ];
    }

    $monthly = organization_usage($organizationId, Limits::MONTHLY_MESSAGES);
    $daily   = organization_usage($organizationId, Limits::DAILY_MESSAGES);

    $monthlyOk = $monthly['remaining'] === null || $monthly['remaining'] >= $recipientCount;
    $dailyOk   = $daily['remaining'] === null || $daily['remaining'] >= $recipientCount;

    return [
        'enforced'        => true,
        'estimated_usage' => $recipientCount,
        'monthly' => [
            'limit' => $monthly['limit'], 'used' => $monthly['used'],
            'reserved' => $monthly['reserved'], 'remaining' => $monthly['remaining'],
        ],
        'daily' => [
            'limit' => $daily['limit'], 'used' => $daily['used'],
            'reserved' => $daily['reserved'], 'remaining' => $daily['remaining'],
        ],
        'sufficient' => $monthlyOk && $dailyOk,
    ];
}

/* ==========================================================================
   Sender validation (STEP 12)
   ========================================================================== */

/**
 * Reuses can_use_originator() — the same choke-point rule dispatch_message_raw() enforces right
 * before dispatch — so a preview can never green-light a sender the send would then reject, and a
 * foreign organization's sender id can never be priced (Invariant E, STEP 31).
 *
 * Returns ['ok'=>true,'originator'=>normalized] or ['ok'=>false,'reason'=>code].
 */
function cost_validate_sender(array $user, string $originator): array {
    $normalized = normalize_originator($originator);
    if ($normalized === null) {
        return ['ok' => false, 'reason' => 'sender_missing_or_invalid'];
    }
    if (!can_use_originator($user, $normalized)) {
        return ['ok' => false, 'reason' => 'sender_not_allowed'];
    }
    return ['ok' => true, 'originator' => $normalized];
}

/* ==========================================================================
   Estimates
   ========================================================================== */

/**
 * Single-content send (direct send, API POST /messages, one scheduled occurrence): every recipient
 * receives the identical body, so one segment count applies to all of them.
 *
 * Returns ['ok'=>false,'reason'=>code] for anything that cannot be priced, or a full estimate.
 */
function estimate_message_cost(array $user, string $originator, string|array $destinations, string $content, bool $applyBlacklist = true, ?string $messageType = null): array {
    $userId         = (int)($user['id'] ?? 0);
    $organizationId = (int)($user['organization_id'] ?? 0);
    $isAdmin        = ($user['role'] ?? null) === 'admin';

    $sender = cost_validate_sender($user, $originator);
    if (!$sender['ok']) {
        return $sender;
    }
    if (trim($content) === '') {
        return ['ok' => false, 'reason' => 'content_empty'];
    }

    $recipients = cost_analyze_recipients($userId, $destinations, $applyBlacklist);
    if ($recipients['eligible_count'] === 0) {
        return ['ok' => false, 'reason' => 'no_eligible_recipients', 'recipients' => $recipients];
    }

    $segmentInfo = cost_estimate_segments($content);
    $recipientSummary = [
        'input_count'       => $recipients['input_count'],
        'invalid_count'     => $recipients['invalid_count'],
        'duplicate_count'   => $recipients['duplicate_count'],
        'blacklisted_count' => $recipients['blacklisted_count'],
        'eligible_count'    => $recipients['eligible_count'],
    ];

    // ONE pricing instant for the whole estimate (STEP 48) — the same discipline the real send uses,
    // so a rate change mid-estimate cannot split one preview across two price periods.
    $priced = sms_pricing_price_single_content(
        $recipients['eligible'], $content, $sender['originator'], $messageType, null, $isAdmin
    );
    if (!$priced['ok']) {
        return cost_pricing_failure($priced, $recipientSummary);
    }

    $totalSegments = $segmentInfo['segments'] * $recipients['eligible_count'];
    $estimatedCost = $priced['total_cost'];

    return [
        'ok'         => true,
        'kind'       => 'message',
        'originator' => $sender['originator'],
        'recipients' => $recipientSummary,
        'message' => $segmentInfo,
        'segments' => [
            'per_recipient' => $segmentInfo['segments'],
            'total'         => $totalSegments,
            'distribution'  => [(string)$segmentInfo['segments'] => $recipients['eligible_count']],
            'exact'         => true,
        ],
        'pricing' => cost_pricing_block($priced, $isAdmin) + ['estimated_cost' => $estimatedCost],
        'wallet'  => cost_wallet_preview($userId, $estimatedCost),
        'quota'   => cost_quota_preview($organizationId, $recipients['eligible_count']),
        'notes'   => cost_preview_notes(),
    ];
}

/**
 * Personalized/bulk send (p2p, smart, gradual, API POST /bulk-jobs): each item has its OWN rendered
 * body, so segment counts genuinely differ per recipient and the total is a SUM, not a
 * multiplication (STEP 5 — "do not assume one recipient = one SMS").
 *
 * $items is the same shape bulk_queue_job() accepts: [['mobile'=>..., 'content'=>...], ...] —
 * already rendered by the caller, exactly as the real send path receives it, so the preview prices
 * the identical strings that will be queued.
 *
 * Beyond cost_preview_exact_recipient_ceiling() resolved items, counting every one becomes
 * genuinely expensive; the estimate then samples and is labelled `exact => false` so the UI/API can
 * say so rather than presenting an approximation as fact (STEP 28).
 */
function estimate_bulk_cost(array $user, string $originator, array $items, bool $applyBlacklist = true, ?string $messageType = null): array {
    $userId         = (int)($user['id'] ?? 0);
    $organizationId = (int)($user['organization_id'] ?? 0);
    $isAdmin        = ($user['role'] ?? null) === 'admin';

    $sender = cost_validate_sender($user, $originator);
    if (!$sender['ok']) {
        return $sender;
    }
    if ($items === []) {
        return ['ok' => false, 'reason' => 'no_items'];
    }

    // Same normalize/dedupe/blacklist pipeline, but keyed back to each item's own content so the
    // per-recipient body survives the filtering.
    $seen = [];
    $invalid = 0;
    $duplicates = 0;
    $emptyContent = 0;
    $candidates = [];
    foreach ($items as $item) {
        $mobile = normalize_msisdn((string)($item['mobile'] ?? ''));
        if ($mobile === null) {
            $invalid++;
            continue;
        }
        if (isset($seen[$mobile])) {
            $duplicates++;
            continue;
        }
        $body = (string)($item['content'] ?? '');
        if (trim($body) === '') {
            $emptyContent++;
            continue;
        }
        $seen[$mobile] = true;
        $candidates[] = ['mobile' => $mobile, 'content' => $body];
    }

    $blacklisted = 0;
    if ($applyBlacklist && $candidates !== []) {
        [$keptMobiles, $blacklisted] = filter_blacklist($userId, array_column($candidates, 'mobile'));
        if ($blacklisted > 0) {
            $keep = array_flip($keptMobiles);
            $candidates = array_values(array_filter($candidates, static fn($c) => isset($keep[$c['mobile']])));
        }
    }

    $eligibleCount = count($candidates);
    if ($eligibleCount === 0) {
        return [
            'ok' => false, 'reason' => 'no_eligible_recipients',
            'recipients' => [
                'input_count' => count($items), 'invalid_count' => $invalid,
                'duplicate_count' => $duplicates, 'blacklisted_count' => $blacklisted,
                'empty_content_count' => $emptyContent, 'eligible_count' => 0,
            ],
        ];
    }

    $ceiling = cost_preview_exact_recipient_ceiling();
    $exact = $eligibleCount <= $ceiling;

    $distribution = [];
    $messages = [];
    if ($exact) {
        $totalSegments = 0;
        foreach ($candidates as $candidate) {
            $segments = sms_parts($candidate['content']);
            $totalSegments += $segments;
            $distribution[(string)$segments] = ($distribution[(string)$segments] ?? 0) + 1;
            $messages[] = ['mobile' => $candidate['mobile'], 'segments' => $segments];
        }
    } else {
        // Segment counting (not pricing) is what becomes expensive at this scale, so the first
        // $ceiling items are counted exactly and the remainder assumed to be the sample's mean —
        // rounded UP, matching sms_pricing_cost_for_segments()'s own direction so the estimate is
        // never optimistically low. Operator/route resolution still covers EVERY recipient (it is
        // an in-memory prefix match against one preloaded table, not a per-number query), so the
        // operator breakdown stays complete even when the segment total is sampled. Reported with
        // exact=false so nothing presents this as a precise figure.
        $sample = array_slice($candidates, 0, $ceiling);
        $sampleSegments = 0;
        foreach ($sample as $candidate) {
            $segments = sms_parts($candidate['content']);
            $sampleSegments += $segments;
            $distribution[(string)$segments] = ($distribution[(string)$segments] ?? 0) + 1;
            $messages[] = ['mobile' => $candidate['mobile'], 'segments' => $segments];
        }
        $meanSegments = (int)ceil($sampleSegments / max(1, count($sample)));
        foreach (array_slice($candidates, $ceiling) as $candidate) {
            $messages[] = ['mobile' => $candidate['mobile'], 'segments' => $meanSegments];
        }
        $totalSegments = $sampleSegments + $meanSegments * ($eligibleCount - count($sample));
    }
    ksort($distribution, SORT_NUMERIC);

    $priced = sms_pricing_price_messages($messages, $sender['originator'], $messageType, null, $isAdmin);
    if (!$priced['ok']) {
        return cost_pricing_failure($priced, [
            'input_count'         => count($items),
            'invalid_count'       => $invalid,
            'duplicate_count'     => $duplicates,
            'blacklisted_count'   => $blacklisted,
            'empty_content_count' => $emptyContent,
            'eligible_count'      => $eligibleCount,
        ]);
    }
    $estimatedCost = $priced['total_cost'];

    // Encoding/character stats describe the LONGEST body in the set — the most useful single
    // representative for a personalized batch, and honest about being one example rather than a
    // property of every message.
    $longest = '';
    foreach ($candidates as $candidate) {
        if (mb_strlen($candidate['content'], 'UTF-8') > mb_strlen($longest, 'UTF-8')) {
            $longest = $candidate['content'];
        }
    }

    return [
        'ok'         => true,
        'kind'       => 'bulk',
        'originator' => $sender['originator'],
        'recipients' => [
            'input_count'         => count($items),
            'invalid_count'       => $invalid,
            'duplicate_count'     => $duplicates,
            'blacklisted_count'   => $blacklisted,
            'empty_content_count' => $emptyContent,
            'eligible_count'      => $eligibleCount,
        ],
        'message'  => cost_estimate_segments($longest) + ['represents' => 'longest_item'],
        'segments' => [
            'total'        => $totalSegments,
            'distribution' => $distribution,
            'exact'        => $exact,
            'sampled_from' => $exact ? null : $ceiling,
        ],
        'pricing' => cost_pricing_block($priced, $isAdmin) + ['estimated_cost' => $estimatedCost],
        'wallet'  => cost_wallet_preview($userId, $estimatedCost),
        'quota'   => cost_quota_preview($organizationId, $eligibleCount),
        'notes'   => cost_preview_notes(),
    ];
}

/**
 * Campaign preview (STEP 16). A saved campaign in this product is a stored sender+body template
 * (ellsms_campaigns) with no audience of its own — audiences come from the contact list at send
 * time — so a campaign estimate is a single-content estimate against the caller's chosen audience,
 * priced from the campaign's own stored body/originator. Deliberately does NOT invent a
 * campaign-audience concept the schema doesn't have.
 *
 * Never creates a campaign execution (STEP 16).
 */
function estimate_campaign_cost(array $user, int $campaignId, string|array $destinations, bool $applyBlacklist = true, ?string $messageType = null): array {
    $organizationId = (int)($user['organization_id'] ?? 0);

    // Tenant-scoped lookup with the same legacy fallback public/new-send.php uses for campaign
    // ownership — a foreign campaign id must never resolve (STEP 31).
    $st = db()->prepare(
        'SELECT * FROM ellsms_campaigns WHERE id = ? AND (organization_id = ? OR (organization_id IS NULL AND user_id = ?))'
    );
    $st->execute([$campaignId, $organizationId > 0 ? $organizationId : null, (int)($user['id'] ?? 0)]);
    $campaign = $st->fetch();
    if (!$campaign) {
        return ['ok' => false, 'reason' => 'campaign_not_found'];
    }

    $estimate = estimate_message_cost($user, (string)$campaign['originator'], $destinations, (string)$campaign['content'], $applyBlacklist, $messageType);
    if ($estimate['ok']) {
        $estimate['kind'] = 'campaign';
        $estimate['campaign'] = ['id' => (int)$campaign['id'], 'name' => $campaign['name']];
    }
    return $estimate;
}

/* ==========================================================================
   Preview integrity (STEP 20/21/22)
   ========================================================================== */

/**
 * Server-generated fingerprint over the priced inputs. Purely a CHANGE DETECTOR so the confirm step
 * can notice the content/recipients/sender were edited after the preview was shown.
 *
 * Explicitly NOT authorization and NOT a financial commitment (STEP 20's own warning): the real
 * send recomputes everything server-side regardless of what fingerprint arrives, so a forged one
 * buys nothing. It is never accepted in place of a server-side check.
 */
function cost_preview_fingerprint(int $organizationId, string $originator, string $contentOrItemsHash, int $recipientCount): string {
    return hash('sha256', implode('|', [
        COST_PREVIEW_ESTIMATOR_VERSION, $organizationId, $originator, $contentOrItemsHash, $recipientCount,
    ]));
}

/**
 * Decides whether a confirmation may proceed on a previously-shown estimate. `changed_materially`
 * is true when the authoritative cost now differs from what the user was shown by more than
 * COST_PREVIEW_RECONFIRM_PERCENT, or the preview is older than its TTL — in which case the UI
 * re-asks rather than silently charging a different amount (STEP 21/22).
 *
 * The API deliberately does not use this: it always proceeds on current authoritative values and
 * returns the accepted cost, which is the documented, simpler contract for a machine caller.
 */
function cost_preview_confirmation_check(int $previewedCost, int $currentCost, ?int $previewedAtUnix): array {
    $expired = $previewedAtUnix !== null && (time() - $previewedAtUnix) > cost_preview_ttl_seconds();

    $threshold = cost_preview_reconfirm_percent();
    $changedMaterially = false;
    if ($previewedCost > 0) {
        $deltaPercent = abs($currentCost - $previewedCost) / $previewedCost * 100;
        $changedMaterially = $deltaPercent > $threshold;
    } elseif ($currentCost > 0) {
        $changedMaterially = true; // free -> not free is always material
    }

    return [
        'expired'            => $expired,
        'changed_materially' => $changedMaterially,
        'previewed_cost'     => $previewedCost,
        'current_cost'       => $currentCost,
        'require_reconfirm'  => $expired || $changedMaterially,
    ];
}

/**
 * The honest caveats every preview surface must carry (Invariant G/H).
 *
 * `price_mode` is 'estimated', never 'locked', and that is a statement about how this product
 * actually behaves rather than a placeholder: no send path in ELLSMS reserves money at preview time.
 * Every one of them (direct, bulk, scheduled) re-resolves the price server-side at ACCEPTANCE and
 * snapshots it there — so a preview is a current estimate that can legitimately differ from the
 * accepted price if an admin changes a rate in between, which is exactly what
 * cost_preview_confirmation_check() exists to catch (STEP 25/49).
 */
function cost_preview_notes(): array {
    return [
        'estimate_only'    => true,
        'revalidated_at_send' => true,
        'price_mode'       => 'estimated',
        'ttl_seconds'      => cost_preview_ttl_seconds(),
    ];
}

/**
 * Operational audit for a preview (STEP 27). Metadata only — never message content, never the
 * recipient list. Creates NO financial ledger entry, by construction: this only calls Logger and
 * Metrics, never audit() and never any wallet/usage function.
 */
function cost_preview_record(array $estimate, ?int $organizationId, ?int $actorUserId, string $surface): void {
    $ok = (bool)($estimate['ok'] ?? false);
    Logger::info('cost_preview.completed', [
        'surface'         => $surface,
        'organization_id' => $organizationId,
        'actor_user_id'   => $actorUserId,
        'result'          => $ok ? 'ok' : ($estimate['reason'] ?? 'failed'),
        'recipient_count' => $estimate['recipients']['eligible_count'] ?? 0,
        'segment_count'   => $estimate['segments']['total'] ?? 0,
        'estimated_cost'  => $estimate['pricing']['estimated_cost'] ?? 0,
        'originator'      => $estimate['originator'] ?? null,
        'exact'           => $estimate['segments']['exact'] ?? null,
    ]);
    // Low-cardinality labels only — surface + result, never an organization name or a cost value
    // (STEP 32).
    Metrics::increment('cost_preview.requests', 1, ['surface' => $surface, 'result' => $ok ? 'ok' : 'failed']);
    if (!$ok) {
        Metrics::increment('cost_preview.failures', 1, ['surface' => $surface, 'reason' => (string)($estimate['reason'] ?? 'unknown')]);
        return;
    }
    Metrics::gauge('cost_preview.recipient_count', (float)($estimate['recipients']['eligible_count'] ?? 0));
    Metrics::gauge('cost_preview.segments', (float)($estimate['segments']['total'] ?? 0));
    Metrics::gauge('cost_preview.estimated_cost', (float)($estimate['pricing']['estimated_cost'] ?? 0));
}

/** Machine-readable reason -> Persian message, for the web surfaces (STEP 30). */
function cost_preview_reason_message(string $reason): string {
    return [
        'sender_missing_or_invalid' => 'خط ارسال‌کننده خالی یا غیرعددی است.',
        'sender_not_allowed'        => 'استفاده از این خط ارسال برای شما مجاز نیست.',
        'content_empty'             => 'متن پیام خالی است.',
        'no_items'                  => 'هیچ ردیفی برای ارسال وجود ندارد.',
        'no_eligible_recipients'    => 'هیچ گیرنده‌ی قابل ارسالی باقی نماند (شماره‌ی نامعتبر، تکراری، یا در لیست سیاه).',
        'campaign_not_found'        => 'کمپین پیدا نشد.',
        'pricing_unavailable'       => 'برای بخشی از گیرندگان تعرفه‌ای تعریف نشده است؛ تا مشخص‌شدن هزینه، ارسال انجام نمی‌شود. با مدیر سامانه تماس بگیرید.',
    ][$reason] ?? 'محاسبه‌ی هزینه ممکن نشد.';
}

/** Machine-readable per-recipient pricing failure -> Persian explanation, for the STEP 44 breakdown. */
function cost_pricing_reason_message(string $reason): string {
    return [
        'operator_unknown_no_default_price' => 'اپراتور شماره تشخیص داده نشد و برای این مسیر تعرفه‌ی پیش‌فرض تعریف نشده است.',
        'route_price_missing'               => 'برای این مسیر و اپراتور تعرفه‌ای تعریف نشده است.',
        'route_unavailable'                 => 'برای این خط ارسال، مسیر ارسال فعالی تعریف نشده است.',
        'pricing_unavailable'               => 'تعرفه‌ی این ارسال قابل تعیین نیست.',
    ][$reason] ?? 'تعرفه‌ی این ارسال قابل تعیین نیست.';
}
