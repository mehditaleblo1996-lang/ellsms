<?php
/**
 * ELLSMS — live cost estimate for the send composer (public/send-estimate.php,
 * public/assets/js/sms-composer.js).
 *
 * The send pages price a message only once their form is posted with do=preview. The composer asks
 * this endpoint while the user types instead, and the answer comes from the SAME
 * estimate_message_cost() the preview and the send use (app/Cost/MessageCostEstimator.php), so the
 * live figure cannot disagree with either. Read-only like the estimator: nothing is recorded,
 * reserved, queued or sent, and the confirm step still re-prices everything server-side.
 */

declare(strict_types=1);

/** Above this many entered recipients the composer stops pricing live; «محاسبه‌ی هزینه» still prices exactly. */
function live_cost_max_recipients(): int {
    return max(100, (int)(env('LIVE_COST_MAX_RECIPIENTS', '5000') ?? '5000'));
}

/** Live estimates one user may ask for per minute (the composer debounces, so typing stays far below it). */
function live_cost_rate_limit_per_minute(): int {
    return max(10, (int)(env('LIVE_COST_RATE_LIMIT_PER_MINUTE', '90') ?? '90'));
}

/**
 * Raw recipient tokens of a send form: the typed list plus the picked contact group and number
 * category, expanded with the same ownership scoping public/send.php and public/new-send.php use.
 *
 * @return list<string>
 */
function live_cost_form_recipients(array $me, array $post): array {
    $tokens = preg_split('/[\s,;،]+/u', (string)($post['destinations'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $orgId = $me['organization_id'] ?? null;

    $group = trim((string)($post['group'] ?? ''));
    if ($group !== '') {
        $st = db()->prepare('SELECT mobile FROM ellsms_contacts WHERE (organization_id = ? OR (organization_id IS NULL AND user_id = ?)) AND group_name = ?');
        $st->execute([$orgId, $me['id'], $group]);
        foreach ($st->fetchAll() as $row) $tokens[] = (string)$row['mobile'];
    }

    $category = (int)($post['category'] ?? 0);
    if ($category > 0) {
        $st = db()->prepare(
            'SELECT i.mobile FROM ellsms_number_category_items i
             JOIN ellsms_number_categories c ON c.id = i.category_id
             WHERE i.category_id = ? AND (c.organization_id = ? OR (c.organization_id IS NULL AND ? IS NULL))'
        );
        $st->execute([$category, $orgId, $orgId]);
        foreach ($st->fetchAll() as $row) $tokens[] = (string)$row['mobile'];
    }

    return $tokens;
}

/**
 * Pure: an estimate_message_cost() result -> the compact JSON the composer renders. Only figures the
 * cost preview card already shows the same user; no pricing-catalog or route detail.
 */
function live_cost_payload(array $estimate): array {
    $r = $estimate['recipients'] ?? [];
    $recipients = [
        'input'       => (int)($r['input_count'] ?? 0),
        'invalid'     => (int)($r['invalid_count'] ?? 0),
        'duplicate'   => (int)($r['duplicate_count'] ?? 0),
        'blacklisted' => (int)($r['blacklisted_count'] ?? 0),
        'eligible'    => (int)($r['eligible_count'] ?? 0),
    ];

    if (empty($estimate['ok'])) {
        $reason = (string)($estimate['reason'] ?? 'unknown');
        $out = ['ok' => false, 'reason' => $reason, 'message' => cost_preview_reason_message($reason)];
        if (isset($estimate['recipients'])) $out['recipients'] = $recipients;
        if ($reason === 'pricing_unavailable') {
            $out['unpriced'] = (int)($estimate['pricing_failure']['unpriced_count'] ?? 0);
        }
        return $out;
    }

    $wallet = $estimate['wallet'] ?? [];
    return [
        'ok'         => true,
        'recipients' => $recipients,
        'segments'   => [
            'per_recipient' => (int)($estimate['segments']['per_recipient'] ?? 0),
            'total'         => (int)($estimate['segments']['total'] ?? 0),
        ],
        'cost'   => (int)($estimate['pricing']['estimated_cost'] ?? 0),
        'exempt' => ($estimate['pricing']['price_source'] ?? '') === 'admin_exempt',
        'wallet' => [
            'balance'    => (int)($wallet['balance'] ?? 0),
            'remaining'  => (int)($wallet['estimated_remaining'] ?? 0),
            'sufficient' => (bool)($wallet['sufficient'] ?? true),
        ],
        'quota_sufficient' => (bool)($estimate['quota']['sufficient'] ?? true),
    ];
}
