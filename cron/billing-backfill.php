<?php
/**
 * ELLSMS — plan seeding + legacy organization backfill (Phase 13, STEP 8/9).
 *
 * Two jobs, both fully idempotent, both explicit operator actions (never automatic, never run by
 * container startup or by any application request path — the same standing rule
 * cron/wallet-backfill.php and cron/tenant-backfill.php already follow):
 *
 *   1. Seed the built-in plan catalog (legacy/free/starter/business) if it isn't there yet.
 *   2. Assign every organization that has NO effective subscription to the grandfathered `legacy`
 *      plan — unlimited everything, exactly preserving what those customers already had
 *      (Invariant L: existing customers are never locked out or silently downgraded).
 *
 * SAFETY: this NEVER downgrades an organization, never overwrites an existing subscription, and
 * never guesses. An organization already holding an effective subscription is skipped and reported.
 *
 * PRICES ARE PLACEHOLDERS. The repository has no source of truth for this product's real
 * commercial pricing, so the paid plans below ship with round, obviously-placeholder Rial amounts
 * that an operator MUST review before charging anyone (`make billing-plans` shows them; they are
 * editable in the platform-admin billing page). Seeding a technically-functional catalog with
 * documented placeholders is STEP 9's own stated preference over inventing marketing pricing.
 *
 * Usage:
 *   php cron/billing-backfill.php --dry-run
 *   php cron/billing-backfill.php
 *   php cron/billing-backfill.php --plans-only
 */
require_once __DIR__ . '/../app/bootstrap.php';

$dryRun    = in_array('--dry-run', $argv ?? [], true);
$plansOnly = in_array('--plans-only', $argv ?? [], true);
$json      = in_array('--json', $argv ?? [], true);
if ($json) {
    Logger::setCliMirror(false);
}

/**
 * The built-in catalog. `limits` uses null for UNLIMITED. Any key here is validated against the
 * central catalogs before insertion, so a typo fails loudly at seed time rather than silently
 * creating a plan row nothing can ever satisfy.
 */
$PLAN_DEFINITIONS = [
    [
        'code' => 'legacy', 'name' => 'پلن موروثی', 'is_default' => 0, 'is_public' => 0,
        'billing_period' => 'none', 'price_amount' => 0, 'trial_days' => 0, 'sort_order' => 100,
        'description' => 'پلن سازمان‌های موجود پیش از راه‌اندازی سیستم اشتراک — بدون محدودیت، برای حفظ دقیق دسترسی‌های قبلی.',
        // Grandfathered: EVERY entitlement, EVERY limit unlimited. This is what makes the backfill
        // a genuine no-op for existing customers rather than a silent restriction.
        'entitlements' => 'all',
        'limits' => 'unlimited',
    ],
    [
        'code' => 'free', 'name' => 'رایگان', 'is_default' => 1, 'is_public' => 1,
        'billing_period' => 'none', 'price_amount' => 0, 'trial_days' => 0, 'sort_order' => 10,
        'description' => 'برای شروع و آزمایش سامانه.',
        'entitlements' => [
            Entitlements::CAMPAIGNS => false, Entitlements::SCHEDULES => false,
            Entitlements::AUTOREPLY => false, Entitlements::BULK_SEND => false,
            Entitlements::PUBLIC_API => false, Entitlements::WEBHOOKS => false,
            Entitlements::REPORTS_ADVANCED => false,
        ],
        'limits' => [
            Limits::MEMBERS => 2, Limits::CONTACTS => 200, Limits::API_KEYS => 0,
            Limits::WEBHOOK_ENDPOINTS => 0, Limits::ACTIVE_SCHEDULES => 0, Limits::CAMPAIGNS => 0,
            Limits::MONTHLY_MESSAGES => 500, Limits::DAILY_MESSAGES => 100,
            Limits::BULK_ITEMS_PER_JOB => 0, Limits::API_REQUESTS_PER_MINUTE => 0,
        ],
    ],
    [
        'code' => 'starter', 'name' => 'استارتر', 'is_default' => 0, 'is_public' => 1,
        'billing_period' => 'monthly', 'price_amount' => 5000000, 'trial_days' => 14, 'sort_order' => 20,
        'description' => 'برای کسب‌وکارهای کوچک — قیمت نمونه، پیش از فروش بازبینی شود.',
        'entitlements' => [
            Entitlements::CAMPAIGNS => true, Entitlements::SCHEDULES => true,
            Entitlements::AUTOREPLY => true, Entitlements::BULK_SEND => true,
            Entitlements::PUBLIC_API => true, Entitlements::WEBHOOKS => true,
            Entitlements::REPORTS_ADVANCED => false,
        ],
        'limits' => [
            Limits::MEMBERS => 5, Limits::CONTACTS => 5000, Limits::API_KEYS => 3,
            Limits::WEBHOOK_ENDPOINTS => 3, Limits::ACTIVE_SCHEDULES => 25, Limits::CAMPAIGNS => 25,
            Limits::MONTHLY_MESSAGES => 25000, Limits::DAILY_MESSAGES => 2500,
            Limits::BULK_ITEMS_PER_JOB => 2000, Limits::API_REQUESTS_PER_MINUTE => 60,
        ],
    ],
    [
        'code' => 'business', 'name' => 'بیزینس', 'is_default' => 0, 'is_public' => 1,
        'billing_period' => 'monthly', 'price_amount' => 20000000, 'trial_days' => 14, 'sort_order' => 30,
        'description' => 'برای سازمان‌های بزرگ‌تر — قیمت نمونه، پیش از فروش بازبینی شود.',
        'entitlements' => 'all',
        'limits' => [
            Limits::MEMBERS => 50, Limits::CONTACTS => 100000, Limits::API_KEYS => 20,
            Limits::WEBHOOK_ENDPOINTS => 20, Limits::ACTIVE_SCHEDULES => 500, Limits::CAMPAIGNS => 500,
            Limits::MONTHLY_MESSAGES => 500000, Limits::DAILY_MESSAGES => 50000,
            Limits::BULK_ITEMS_PER_JOB => null, Limits::API_REQUESTS_PER_MINUTE => 300,
        ],
    ],
];

$plansCreated = 0;
$plansSkipped = 0;
$errors = [];

foreach ($PLAN_DEFINITIONS as $def) {
    $existing = billing_plan_by_code($def['code']);
    if ($existing) {
        $plansSkipped++;
        continue;
    }
    if ($dryRun) {
        $plansCreated++;
        continue;
    }

    // Resolve the two shorthand forms into explicit maps, validating every key against the central
    // catalogs — an unknown key aborts this plan rather than creating a half-valid row.
    $entitlements = $def['entitlements'] === 'all'
        ? array_fill_keys(Entitlements::all(), true)
        : $def['entitlements'];
    $limits = $def['limits'] === 'unlimited'
        ? array_fill_keys(Limits::all(), null)
        : $def['limits'];

    foreach (array_keys($entitlements) as $key) {
        if (!Entitlements::isValid($key)) {
            $errors[] = "plan {$def['code']}: unknown entitlement key '{$key}'";
        }
    }
    foreach (array_keys($limits) as $key) {
        if (!Limits::isValid($key)) {
            $errors[] = "plan {$def['code']}: unknown limit key '{$key}'";
        }
    }
    if ($errors) {
        continue;
    }

    db_transaction(function (PDO $db) use ($def, $entitlements, $limits): void {
        $db->prepare(
            'INSERT INTO ellsms_plans (code, name, description, status, is_default, is_public, billing_period, price_amount, currency, trial_days, sort_order)
             VALUES (?,?,?,\'active\',?,?,?,?,?,?,?)'
        )->execute([
            $def['code'], $def['name'], $def['description'], $def['is_default'], $def['is_public'],
            $def['billing_period'], $def['price_amount'], billing_currency(), $def['trial_days'], $def['sort_order'],
        ]);
        $planId = (int)$db->lastInsertId();

        $entIns = $db->prepare('INSERT INTO ellsms_plan_entitlements (plan_id, entitlement_key, enabled) VALUES (?,?,?)');
        foreach ($entitlements as $key => $enabled) {
            $entIns->execute([$planId, $key, $enabled ? 1 : 0]);
        }
        $limIns = $db->prepare('INSERT INTO ellsms_plan_limits (plan_id, limit_key, limit_value, reset_period, enforcement) VALUES (?,?,?,?,\'hard\')');
        foreach ($limits as $key => $value) {
            $limIns->execute([$planId, $key, $value, Limits::resetPeriod($key)]);
        }
    });
    $plansCreated++;
    Logger::info('billing.plan.seeded', ['plan_code' => $def['code']]);
}

if ($errors) {
    fwrite(STDERR, "Plan seeding aborted — catalog validation errors:\n");
    foreach ($errors as $e) {
        fwrite(STDERR, "  - {$e}\n");
    }
    exit(1);
}

/* ---------- Organization backfill ---------- */

$orgsAssigned = 0;
$orgsSkipped = 0;
$orgsReported = [];

if (!$plansOnly) {
    $legacyPlan = billing_plan_by_code(BILLING_LEGACY_PLAN_CODE);
    if (!$legacyPlan && !$dryRun) {
        fwrite(STDERR, "Legacy plan '" . BILLING_LEGACY_PLAN_CODE . "' not found — cannot backfill. Run with --plans-only first.\n");
        exit(1);
    }

    $orgs = db()->query('SELECT id, name, status FROM ellsms_organizations ORDER BY id')->fetchAll();
    foreach ($orgs as $org) {
        $organizationId = (int)$org['id'];
        if (subscription_for_organization($organizationId) !== null) {
            $orgsSkipped++;
            continue; // already subscribed — NEVER overwritten
        }
        if ($dryRun) {
            $orgsAssigned++;
            $orgsReported[] = ['organization_id' => $organizationId, 'name' => $org['name'], 'action' => 'would_assign_legacy'];
            continue;
        }

        // Idempotent by construction: subscription_create() itself re-checks under a row lock and
        // returns 'already_subscribed' rather than creating a second effective subscription, so
        // re-running this script (or running two copies concurrently) cannot double-assign.
        $result = subscription_create($organizationId, (int)$legacyPlan['id'], 'active', null, 'backfill', 0, 'backfill:org:' . $organizationId);
        if ($result['ok']) {
            $orgsAssigned++;
            $orgsReported[] = ['organization_id' => $organizationId, 'name' => $org['name'], 'action' => 'assigned_legacy'];
        } else {
            $orgsSkipped++;
            $orgsReported[] = ['organization_id' => $organizationId, 'name' => $org['name'], 'action' => 'skipped:' . $result['reason']];
        }
    }
}

$summary = [
    'dry_run'         => $dryRun,
    'plans_created'   => $plansCreated,
    'plans_skipped'   => $plansSkipped,
    'orgs_assigned'   => $orgsAssigned,
    'orgs_skipped'    => $orgsSkipped,
    'organizations'   => $orgsReported,
];

if (!$dryRun) {
    Logger::info('billing.backfill.completed', ['plans_created' => $plansCreated, 'orgs_assigned' => $orgsAssigned, 'orgs_skipped' => $orgsSkipped]);
}

if ($json) {
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
} else {
    echo ($dryRun ? "[dry-run] " : '') . "ELLSMS billing backfill\n\n";
    echo "  Plans created:       {$plansCreated}\n";
    echo "  Plans already there: {$plansSkipped}\n";
    if (!$plansOnly) {
        echo "  Organizations assigned to '" . BILLING_LEGACY_PLAN_CODE . "': {$orgsAssigned}\n";
        echo "  Organizations skipped (already subscribed): {$orgsSkipped}\n";
    }
    echo "\n";
    if ($plansCreated > 0 && !$dryRun) {
        echo "  NOTE: paid plan prices are PLACEHOLDERS — review them before charging anyone.\n";
        echo "        See docs/billing-operations.md.\n";
    }
}
exit(0);
