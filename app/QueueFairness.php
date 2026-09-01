<?php
/**
 * ELLSMS — per-tick priority quota allocation across message classes (issue #3).
 *
 * Pure, side-effect-free arithmetic: given how many claimable rows are currently pending per
 * class and a total per-tick claim budget, decide how many rows of EACH class this tick is
 * allowed to claim. Kept separate from MessageClass.php (the ordering vocabulary) and from
 * backend.php (the DB claim queries) so the allocation policy itself is unit-testable without a
 * database — see tests/Unit/QueueFairnessTest.php.
 *
 * Strict priority order alone ("always drain OTP before touching Advertising") is exactly what
 * the acceptance criteria warns against in the other direction: it would let a sustained
 * high-priority backlog starve a lower class indefinitely. This allocator instead reserves each
 * non-empty class a guaranteed minimum floor of the tick's budget FIRST (so a class with any
 * backlog always makes some progress every tick), then hands out whatever budget is left over in
 * strict priority order. Under light load nothing changes in practice (everything fits); under
 * sustained overload, no class is ever starved to zero.
 */

declare(strict_types=1);

/**
 * Guaranteed floor share of the total budget for each class, applied only while that class has a
 * nonzero backlog. Advertising's floor is deliberately the smallest nonzero value — it must still
 * make SOME progress under a flood of higher-priority work, just not much. Scheduled/Notification/
 * Transactional/OTP don't currently contend for this specific budget (see MessageClass.php) but
 * get sane floors too so this function stays correct if a future class starts sharing it.
 *
 * Runtime-configurable (issue #3 re-audit): each floor reads QUEUE_CLASS_MIN_SHARE_<CLASS> (e.g.
 * QUEUE_CLASS_MIN_SHARE_ADVERTISING=0.10), so an operator can retune fairness for a specific
 * deployment's traffic mix by restarting the bulk worker with a changed environment -- no PHP edit,
 * no deploy. A value that fails to parse as a finite float in [0, 1] is logged and the built-in
 * default is used for that one class instead of failing the whole allocation -- one bad
 * environment variable must never crash queue claiming or silently zero out a class's floor.
 */
function queue_class_min_share(): array {
    $defaults = [
        MESSAGE_CLASS_OTP => 0.30,
        MESSAGE_CLASS_TRANSACTIONAL => 0.20,
        MESSAGE_CLASS_NOTIFICATION => 0.15,
        MESSAGE_CLASS_SCHEDULED => 0.15,
        MESSAGE_CLASS_BULK_CAMPAIGN => 0.15,
        MESSAGE_CLASS_ADVERTISING => 0.05,
    ];

    $shares = [];
    foreach ($defaults as $class => $default) {
        $shares[$class] = queue_class_min_share_from_env($class, $default);
    }
    return $shares;
}

/** @internal exposed only for QueueFairnessTest; not part of the public allocation API. */
function queue_class_min_share_from_env(string $class, float $default): float {
    $envKey = 'QUEUE_CLASS_MIN_SHARE_' . strtoupper($class);
    $raw = env($envKey, null);
    if ($raw === null || trim((string)$raw) === '') {
        return $default;
    }
    if (!is_numeric($raw)) {
        Logger::warning('queue.fairness.invalid_min_share', ['class' => $class, 'env_key' => $envKey, 'raw' => (string)$raw, 'fallback' => $default]);
        return $default;
    }
    $value = (float)$raw;
    if (!is_finite($value) || $value < 0.0 || $value > 1.0) {
        Logger::warning('queue.fairness.invalid_min_share', ['class' => $class, 'env_key' => $envKey, 'raw' => (string)$raw, 'fallback' => $default]);
        return $default;
    }
    return $value;
}

/**
 * @param array<string,int> $depthByClass  pending-row count per class right now (missing/zero
 *                                          entries are simply skipped — nothing to allocate).
 * @param int $totalBudget                 total rows claimable this tick across every class.
 * @return array<string,int>               rows each class may claim this tick. Every key present
 *                                          in $depthByClass with depth > 0 is present in the
 *                                          result (0 is a valid, explicit answer, never absent).
 *                                          Sum of values never exceeds $totalBudget and never
 *                                          exceeds a class's own depth.
 */
function allocate_priority_quota(array $depthByClass, int $totalBudget): array {
    $totalBudget = max(0, $totalBudget);
    $classes = array_keys($depthByClass);
    $quota = array_fill_keys($classes, 0);
    if ($totalBudget === 0 || $classes === []) {
        return $quota;
    }

    $minShare = queue_class_min_share();
    $remaining = $totalBudget;

    // Pass 1: guaranteed floor per class with backlog, priority order first so that if the floors
    // themselves can't all fit (pathological: budget smaller than the number of contending
    // classes), the highest-priority classes are the ones that keep their floor.
    foreach (sort_message_classes($classes) as $class) {
        $depth = max(0, (int)$depthByClass[$class]);
        if ($depth === 0 || $remaining <= 0) {
            continue;
        }
        $floor = (int)ceil($totalBudget * ($minShare[$class] ?? 0.0));
        $grant = min($floor, $depth, $remaining);
        $quota[$class] += $grant;
        $remaining -= $grant;
    }

    // Pass 2: hand out whatever is left, strict priority order, capped by each class's remaining
    // (unmet) depth.
    foreach (sort_message_classes($classes) as $class) {
        if ($remaining <= 0) {
            break;
        }
        $depth = max(0, (int)$depthByClass[$class]);
        $unmet = $depth - $quota[$class];
        if ($unmet <= 0) {
            continue;
        }
        $grant = min($unmet, $remaining);
        $quota[$class] += $grant;
        $remaining -= $grant;
    }

    return $quota;
}
