<?php
/**
 * ELLSMS — message delivery lifecycle helpers for reporting surfaces.
 *
 * Historical delivery state comes from ELLSMS-owned transport attempts; raw outbound_message status
 * is only a fallback. Provider references are always strings because real providers can return ids
 * larger than PHP/JavaScript's exact integer range.
 */
declare(strict_types=1);

require_once __DIR__ . '/../Backend/report_summary.php';

function report_delivery_status_label(?string $status): string {
    return match ($status) {
        'accepted'  => 'پذیرفته شده',
        'queued'    => 'در صف',
        'sent'      => 'ارسال شده',
        'delivered' => 'تحویل شده',
        'pending'   => 'در انتظار',
        'failed'    => 'ناموفق',
        'rejected'  => 'رد شده',
        'expired'   => 'منقضی',
        'unknown'   => 'نامشخص',
        default     => 'نامشخص',
    };
}

function report_delivery_status_class(?string $status): string {
    return match ($status) {
        'delivered'                     => 'delivered',
        'failed', 'rejected', 'expired' => 'failed',
        'sent', 'accepted', 'queued'    => 'sent',
        'pending'                       => 'pending',
        default                         => 'unknown',
    };
}

/**
 * Single canonical UI-facing status. Provider-confirmed lifecycle wins; otherwise the backend
 * transport status is shown without inventing a delivery result.
 *
 * @return array{status:string,label:string,class:string}
 */
function report_canonical_status(?string $deliveryStatus, ?string $fallbackSendStatus = null): array {
    $status = ($deliveryStatus !== null && $deliveryStatus !== '') ? $deliveryStatus : null;
    if ($status === null) {
        $status = match ($fallbackSendStatus) {
            'delivered'             => 'delivered',
            'sent'                  => 'sent',
            'failed', 'send_failed' => 'failed',
            'pending'               => 'pending',
            default                 => 'unknown',
        };
    }
    return [
        'status' => $status,
        'label'  => report_delivery_status_label($status),
        'class'  => report_delivery_status_class($status),
    ];
}

/**
 * Bounded delivery enrichment for a page/export chunk, keyed by destination for compatibility with
 * existing callers.
 *
 * IMPORTANT: outbound_message and ellsms_message_attempts do not share a foreign key. The old code
 * simply chose the newest attempt for a destination. If three historical outbound rows on the page
 * had the same mobile, ONE delivered attempt therefore made all three rows look delivered. That is
 * worse than showing the backend's conservative `sent` state.
 *
 * We now enrich only when the destination occurs exactly once in this bounded result set, and only
 * when the attempt timestamp is close to that outbound row's sent_at. Ambiguous repeated recipients
 * deliberately receive no enrichment and fall back to outbound_message.status. This never fabricates
 * a delivery state and fixes the dashboard/report disagreement without an N+1 query.
 *
 * @param list<array<string,mixed>> $outboundRows
 * @return array<string,array<string,mixed>> destination => attempt row
 */
function report_delivery_lookup_by_destination(array $outboundRows, ?int $organizationId, ?int $userId): array {
    if ($outboundRows === []) {
        return [];
    }

    $counts = [];
    $rowByDestination = [];
    foreach ($outboundRows as $row) {
        $destination = (string)($row['destination'] ?? '');
        if ($destination === '') {
            continue;
        }
        $counts[$destination] = ($counts[$destination] ?? 0) + 1;
        $rowByDestination[$destination] = $row;
    }

    // A destination represented by more than one outbound row cannot be correlated safely with the
    // current schema. Do not let one attempt leak its status onto every historical message.
    $dests = [];
    foreach ($counts as $destination => $count) {
        if ($count === 1) {
            $dests[] = $destination;
        }
    }
    if ($dests === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($dests), '?'));
    $params = $dests;
    $scopeSql = '';
    if ($organizationId !== null && $organizationId > 0) {
        $scopeSql = ' AND ma.organization_id = ?';
        $params[] = $organizationId;
    } elseif ($userId !== null && $userId > 0) {
        $scopeSql = ' AND ma.user_id = ?';
        $params[] = $userId;
    }

    $out = [];
    try {
        $st = db()->prepare(
            "SELECT ma.id, ma.destination, ma.provider_message_id, ma.provider_status, ma.delivery_status,
                    ma.delivery_attempts, ma.delivery_checked_at, ma.delivered_at, ma.operator_id,
                    ma.reference_type, ma.attempted_at
             FROM ellsms_message_attempts ma
             WHERE ma.destination IN ({$placeholders}){$scopeSql}
               AND ma.status = 'accepted'
             ORDER BY ma.id DESC"
        );
        $st->execute($params);
        while ($a = $st->fetch()) {
            $destination = (string)$a['destination'];
            if (isset($out[$destination])) {
                continue;
            }
            $outbound = $rowByDestination[$destination] ?? null;
            if ($outbound === null) {
                continue;
            }
            $sentAt = strtotime((string)($outbound['sent_at'] ?? ''));
            $attemptedAt = strtotime((string)($a['attempted_at'] ?? ''));
            if ($sentAt === false || $attemptedAt === false) {
                continue;
            }
            // Backend and ELLSMS write around the same dispatch. Ten minutes is deliberately wide
            // enough for queue/API delay, but narrow enough not to attach an old delivery to a new SMS.
            if (abs($attemptedAt - $sentAt) > 600) {
                continue;
            }
            $out[$destination] = $a;
        }
    } catch (Throwable $t) {
        Logger::warning('reports.delivery_enrichment_failed', ['exception' => $t]);
    }
    return $out;
}

/** @return array{total:int,ok:int,delivered:int,failed:int,pending:int} */
function report_canonical_status_totals(string $outboundWhereSql, array $params, ?int $organizationId, ?int $userId): array {
    return backend_outbound_canonical_summary($outboundWhereSql, $params, $organizationId, $userId);
}

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

function report_message_encoding(string $content): array {
    $isUnicode = (bool)preg_match('/[^\x20-\x7E\r\n]/u', $content);
    return [
        'unicode' => $isUnicode,
        'label'   => $isUnicode ? 'فارسی / Unicode (UCS-2)' : 'لاتین / GSM-7',
        'limits'  => $isUnicode ? '۷۰ / ۶۷' : '۱۶۰ / ۱۵۳',
    ];
}

function report_segment_count(?array $snapshot, ?string $content): array {
    if ($snapshot !== null && (int)($snapshot['segment_count'] ?? 0) > 0) {
        return ['parts' => (int)$snapshot['segment_count'], 'source' => 'snapshot'];
    }
    if ($content === null || $content === '') {
        return ['parts' => 0, 'source' => 'unavailable'];
    }
    return ['parts' => sms_parts($content), 'source' => 'derived'];
}

/** Resolve display names in at most one query per catalog kind. */
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
            while ($row = $st->fetch()) {
                $out[(int)$row['id']] = (string)$row['label'];
            }
            return $out;
        } catch (Throwable) {
            return [];
        }
    };
    $names['gateways']  = $fetch('ellsms_sms_gateways', $gatewayIds, 'code');
    $names['routes']    = $fetch('ellsms_sms_routes', $routeIds, 'code');
    $names['operators'] = $fetch('ellsms_sms_operators', $operatorIds, 'name');
    return $names;
}

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
        $steps[] = ['label' => 'ارسال به درگاه', 'at' => (string)($attempted ?? $requested ?? ''), 'state' => 'done'];
    }
    if (!empty($row['delivery_checked_at'])) {
        $steps[] = ['label' => 'آخرین استعلام وضعیت', 'at' => (string)$row['delivery_checked_at'], 'state' => 'done'];
    }
    if (!empty($row['delivered_at'])) {
        $steps[] = ['label' => 'تحویل', 'at' => (string)$row['delivered_at'], 'state' => 'delivered'];
    } elseif (in_array($status, ['failed', 'rejected', 'expired'], true)) {
        $steps[] = ['label' => report_delivery_status_label($status), 'at' => null, 'state' => 'failed'];
    } else {
        $steps[] = ['label' => 'هنوز تحویل نشده', 'at' => null, 'state' => 'pending'];
    }
    return $steps;
}

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

function report_bulk_items(int $jobId, ?int $organizationId, int $limit = 200, int $offset = 0): array {
    $sql = 'SELECT bi.* FROM ellsms_bulk_items bi JOIN ellsms_bulk_jobs bj ON bj.id = bi.job_id WHERE bi.job_id = ?';
    $params = [$jobId];
    if ($organizationId !== null) {
        $sql .= ' AND bj.organization_id = ?';
        $params[] = $organizationId;
    }
    $sql .= ' ORDER BY bi.id ASC LIMIT ' . max(1, min(500, $limit)) . ' OFFSET ' . max(0, $offset);
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

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

function report_format_millicredits(int $millicredits): string {
    $whole = intdiv($millicredits, 1000);
    $fraction = $millicredits % 1000;
    if ($fraction === 0) {
        return number_format($whole);
    }
    return number_format($whole) . '.' . rtrim(str_pad((string)$fraction, 3, '0', STR_PAD_LEFT), '0');
}
