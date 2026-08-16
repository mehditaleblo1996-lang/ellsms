<?php
/**
 * ELLSMS — message delivery lifecycle for the reporting UI.
 *
 * WHAT THIS IS. A read-only resolver that assembles everything known about one sent message from the
 * records that ALREADY exist: the transport attempt (ellsms_message_attempts / ellsms_bulk_items),
 * the immutable pricing snapshot (ellsms_sms_price_snapshots), and the gateway/route/operator names
 * the attempt itself recorded. It computes nothing that was decided at send time.
 *
 * THREE RULES THIS FILE EXISTS TO ENFORCE.
 *
 * 1. HISTORICAL FACTS COME FROM THE SEND, NOT FROM TODAY'S CONFIGURATION. An attempt row records the
 *    gateway_id, gateway_config_version, route_id and operator_id that were ACTUALLY used. A sender's
 *    preferred route may have been re-pointed since — reading it now would report a route the message
 *    never travelled. Every lookup here is keyed on what the row stored.
 *
 * 2. PRICES ARE READ, NEVER RECOMPUTED. The cost shown is the accepted/settled snapshot from
 *    ellsms_sms_price_snapshots. Re-pricing a historical send against today's tariff would silently
 *    disagree with what the customer was actually charged.
 *
 * 3. SEGMENT COUNT HAS ONE SOURCE. The part count comes from the pricing snapshot's stored
 *    segment_count where one exists, and otherwise from sms_parts() — the SAME function pricing and
 *    cost preview use. There is deliberately no second length algorithm in this file; a UI that
 *    hardcoded 70/67/160/153 would drift from billing the moment either changed.
 *
 * PROVIDER MESSAGE IDs ARE STRINGS, ALWAYS. A 19-digit provider reference exceeds PHP's and
 * JavaScript's exact-integer range, so it is carried and rendered as a string end to end. Casting it
 * to a number anywhere turns 4473621976262727360 into 4.4736219762627E+18 — a value that matches
 * nothing when an operator searches for it.
 */

declare(strict_types=1);

/** Canonical delivery states → Persian display labels. The DB enum values themselves never change. */
function report_delivery_status_label(?string $status): string {
    return match ($status) {
        'accepted'  => 'پذیرفته شده',
        'queued'    => 'در صف',
        'sent'      => 'ارسال شده',
        'delivered' => 'تحویل شده',
        'failed'    => 'ناموفق',
        'rejected'  => 'رد شده',
        'expired'   => 'منقضی',
        'unknown'   => 'نامشخص',
        default     => 'نامشخص',
    };
}

/** A CSS badge class per canonical state, so failure reads as failure at a glance. */
function report_delivery_status_class(?string $status): string {
    return match ($status) {
        'delivered'                      => 'delivered',
        'failed', 'rejected', 'expired'  => 'failed',
        'sent', 'accepted', 'queued'     => 'sent',
        default                          => 'pending',
    };
}

/** Internal reference types → Persian labels for "نوع درخواست". */
function report_reference_type_label(?string $type): string {
    return match ($type) {
        'direct_send' => 'ارسال مستقیم',
        'schedule'    => 'زمان‌بندی‌شده',
        'bulk_job'    => 'ارسال انبوه',
        'autoreply'   => 'پاسخ خودکار',
        'api'         => 'API',
        default       => (string)($type ?? '—'),
    };
}

/**
 * The message encoding, derived with the SAME rule sms_parts() uses to choose its segment sizes.
 *
 * Deliberately derived from the content rather than stored: it is a pure function of the text, and a
 * stored copy could disagree with the segment count computed beside it.
 */
function report_message_encoding(string $content): array {
    $isUnicode = (bool)preg_match('/[^\x20-\x7E\r\n]/u', $content);
    return [
        'unicode' => $isUnicode,
        'label'   => $isUnicode ? 'فارسی / Unicode (UCS-2)' : 'لاتین / GSM-7',
        'limits'  => $isUnicode ? '۷۰ / ۶۷' : '۱۶۰ / ۱۵۳',
    ];
}

/**
 * Segment count for a reported message.
 *
 * Prefers the count FROZEN at acceptance (the pricing snapshot), because that is the number the
 * customer was billed on and it must not move when this code changes. Falls back to sms_parts() —
 * the same engine — for rows sent before snapshots existed, which is a derivation, not an invention:
 * the input text is the same one that was priced.
 *
 * @return array{parts:int, source:string}
 */
function report_segment_count(?array $snapshot, ?string $content): array {
    if ($snapshot !== null && (int)($snapshot['segment_count'] ?? 0) > 0) {
        return ['parts' => (int)$snapshot['segment_count'], 'source' => 'snapshot'];
    }
    if ($content === null || $content === '') {
        return ['parts' => 0, 'source' => 'unavailable'];
    }
    return ['parts' => sms_parts($content), 'source' => 'derived'];
}

/**
 * Names for the gateway/route/operator ids an attempt recorded, fetched in ONE query per kind.
 *
 * Bounded by construction: the caller passes every id on the page at once, so a 50-row recipient
 * table costs three queries rather than 150 (B20 — no N+1).
 *
 * An id with no matching row keeps its numeric form rather than disappearing: an operator or route
 * deleted since the send is a fact worth showing, and silently blanking it would look like the send
 * had no route at all.
 *
 * @param list<int> $gatewayIds
 * @param list<int> $routeIds
 * @param list<int> $operatorIds
 */
function report_resolve_names(array $gatewayIds, array $routeIds, array $operatorIds): array {
    $names = ['gateways' => [], 'routes' => [], 'operators' => []];

    $fetch = static function (string $table, array $ids, string $labelExpr): array {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        try {
            $st = db()->prepare("SELECT id, {$labelExpr} AS label FROM {$table} WHERE id IN ({$placeholders})");
            $st->execute($ids);
            $out = [];
            foreach ($st->fetchAll() as $row) {
                $out[(int)$row['id']] = (string)$row['label'];
            }
            return $out;
        } catch (Throwable) {
            // A reporting page must still render if a lookup table is missing on an older install.
            return [];
        }
    };

    $names['gateways']  = $fetch('ellsms_sms_gateways', $gatewayIds, 'code');
    $names['routes']    = $fetch('ellsms_sms_routes', $routeIds, 'code');
    $names['operators'] = $fetch('ellsms_sms_operators', $operatorIds, 'name');
    return $names;
}

/**
 * The delivery lifecycle of one attempt row, as an ordered list of real events.
 *
 * ONLY timestamps that actually exist become steps. A missing delivery time yields no "تحویل" step
 * rather than a step with an invented time — a fabricated lifecycle is worse than a short one,
 * because it is indistinguishable from a real one.
 *
 * `delivery_checked_at` is "when we last ASKED", never "when it was delivered". They are separate
 * facts and conflating them would make a still-undelivered message look delivered at poll time.
 */
function report_build_timeline(array $row): array {
    $steps = [];

    $requested = $row['created_at'] ?? $row['attempted_at'] ?? null;
    if ($requested) {
        $steps[] = ['label' => 'ثبت درخواست', 'at' => (string)$requested, 'state' => 'done'];
    }

    $attempted = $row['attempted_at'] ?? null;
    if ($attempted && $attempted !== $requested) {
        $steps[] = ['label' => 'تلاش ارسال', 'at' => (string)$attempted, 'state' => 'done'];
    }

    $status = $row['delivery_status'] ?? null;
    if ($status !== null && $status !== '') {
        $steps[] = [
            'label' => 'ارسال به درگاه',
            'at'    => (string)($attempted ?? $requested ?? ''),
            'state' => 'done',
        ];
    }

    if (!empty($row['delivery_checked_at'])) {
        $steps[] = [
            'label' => 'آخرین استعلام وضعیت',
            'at'    => (string)$row['delivery_checked_at'],
            'state' => 'done',
        ];
    }

    if (!empty($row['delivered_at'])) {
        $steps[] = ['label' => 'تحویل', 'at' => (string)$row['delivered_at'], 'state' => 'delivered'];
    } elseif (in_array($status, ['failed', 'rejected', 'expired'], true)) {
        // A terminal failure genuinely ends the lifecycle, but the provider gives no timestamp for
        // it — so the step is shown WITHOUT a time rather than borrowing the poll time.
        $steps[] = ['label' => report_delivery_status_label($status), 'at' => null, 'state' => 'failed'];
    } else {
        $steps[] = ['label' => 'هنوز تحویل نشده', 'at' => null, 'state' => 'pending'];
    }

    return $steps;
}

/**
 * Every transport attempt belonging to one reference, newest first.
 *
 * Scoped by organization at the SQL level rather than filtered afterwards: a report page must not be
 * able to load another tenant's rows and then decide not to show them (B18). A NULL organization_id
 * row (pre-tenant-backfill) is reachable only by a platform admin, who passes null here.
 */
function report_attempts_for_reference(string $referenceType, string $referenceId, ?int $organizationId): array {
    $sql = 'SELECT * FROM ellsms_message_attempts WHERE reference_type = ? AND reference_id = ?';
    $params = [$referenceType, $referenceId];
    if ($organizationId !== null) {
        $sql .= ' AND organization_id = ?';
        $params[] = $organizationId;
    }
    $sql .= ' ORDER BY id DESC';

    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/**
 * One attempt by primary key, tenant-scoped.
 *
 * Returns null rather than throwing when the row belongs to another organization, so the caller
 * renders an ordinary "not found" — an authorization failure that says "exists, but not yours" is
 * itself a cross-tenant disclosure (it confirms the id is real).
 */
function report_attempt_by_id(int $attemptId, ?int $organizationId): ?array {
    $sql = 'SELECT * FROM ellsms_message_attempts WHERE id = ?';
    $params = [$attemptId];
    if ($organizationId !== null) {
        $sql .= ' AND organization_id = ?';
        $params[] = $organizationId;
    }
    $st = db()->prepare($sql);
    $st->execute($params);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

/**
 * Recipient rows of one bulk job, tenant-scoped through the job that owns them.
 *
 * The organization check is on the JOB, not the item, because ellsms_bulk_items has no
 * organization_id of its own — going through the owning job is what keeps a crafted item id from
 * reaching another tenant's recipients.
 */
function report_bulk_items(int $jobId, ?int $organizationId, int $limit = 200, int $offset = 0): array {
    $sql = 'SELECT bi.* FROM ellsms_bulk_items bi JOIN ellsms_bulk_jobs bj ON bj.id = bi.job_id WHERE bi.job_id = ?';
    $params = [$jobId];
    if ($organizationId !== null) {
        $sql .= ' AND bj.organization_id = ?';
        $params[] = $organizationId;
    }
    $sql .= ' ORDER BY bi.id ASC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset);

    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/**
 * The historical billing view of one operation, straight from the immutable snapshot.
 *
 * `committed` is what was actually settled; `accepted` is what was reserved at acceptance. They
 * differ legitimately (a bulk job where some recipients failed settles below its reservation), and
 * showing both is what makes that difference explainable instead of looking like a billing error.
 */
function report_pricing_for(string $referenceType, string $referenceId): array {
    $groups = sms_price_snapshot_for($referenceType, $referenceId);
    if ($groups === []) {
        return ['available' => false, 'groups' => [], 'accepted' => 0, 'committed' => 0, 'currency' => 'credit'];
    }

    $accepted = 0;
    $committed = 0;
    foreach ($groups as $g) {
        $accepted  += (int)$g['total_cost_credits'];
        $committed += (int)$g['committed_cost_credits'];
    }

    return [
        'available' => true,
        'groups'    => $groups,
        'accepted'  => $accepted,
        'committed' => $committed,
        'settled'   => ($groups[0]['status'] ?? '') === 'settled',
        'currency'  => (string)($groups[0]['currency'] ?? 'credit'),
    ];
}

/**
 * Formats a unit price held in integer millicredits for display.
 *
 * Integer arithmetic throughout — 1 credit is 1000 millicredits, and the fractional part is rendered
 * by string assembly rather than by dividing into a float, so a price never drifts by a rounding
 * error on its way to the screen.
 */
function report_format_millicredits(int $millicredits): string {
    $whole = intdiv($millicredits, 1000);
    $fraction = $millicredits % 1000;
    if ($fraction === 0) {
        return number_format($whole);
    }
    return number_format($whole) . '.' . rtrim(str_pad((string)$fraction, 3, '0', STR_PAD_LEFT), '0');
}
