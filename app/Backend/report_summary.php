<?php
/**
 * Fast aggregate reporting over backend-owned outbound_message.
 *
 * This file is an explicit backend-boundary adapter: callers pass the already-authorized outbound
 * WHERE clause and receive one aggregate row. It exists so dashboard/report summary cards never
 * stream hundreds of thousands of outbound rows into PHP merely to count statuses.
 */

declare(strict_types=1);

/**
 * Canonical status totals using the same destination/latest-accepted-attempt precedence as the
 * row-level reporting layer, but entirely in SQL.
 *
 * @return array{total:int,ok:int,delivered:int,failed:int,pending:int}
 */
function backend_outbound_canonical_summary(
    string $whereSql,
    array $params,
    ?int $organizationId,
    ?int $userId
): array {
    $attemptWhere = "status = 'accepted'";
    $attemptParams = [];
    if ($organizationId !== null && $organizationId > 0) {
        $attemptWhere .= ' AND organization_id = ?';
        $attemptParams[] = $organizationId;
    } elseif ($userId !== null && $userId > 0) {
        $attemptWhere .= ' AND user_id = ?';
        $attemptParams[] = $userId;
    }

    $sql = "
        SELECT
            COUNT(*) AS total,
            COALESCE(SUM(canonical_status IN ('delivered','sent','accepted','queued')), 0) AS ok,
            COALESCE(SUM(canonical_status = 'delivered'), 0) AS delivered,
            COALESCE(SUM(canonical_status IN ('failed','rejected','expired')), 0) AS failed,
            COALESCE(SUM(canonical_status = 'pending'), 0) AS pending
        FROM (
            SELECT
                CASE
                    WHEN NULLIF(TRIM(ma.delivery_status), '') IS NOT NULL
                        THEN LOWER(TRIM(ma.delivery_status))
                    WHEN m.status = 'delivered' THEN 'delivered'
                    WHEN m.status = 'sent' THEN 'sent'
                    WHEN m.status IN ('failed','send_failed') THEN 'failed'
                    WHEN m.status = 'pending' THEN 'pending'
                    ELSE 'unknown'
                END AS canonical_status
            FROM outbound_message m
            LEFT JOIN (
                SELECT a.destination, a.delivery_status
                FROM ellsms_message_attempts a
                INNER JOIN (
                    SELECT destination, MAX(id) AS id
                    FROM ellsms_message_attempts
                    WHERE {$attemptWhere}
                    GROUP BY destination
                ) newest ON newest.id = a.id
            ) ma
              ON ma.destination COLLATE utf8mb4_0900_ai_ci = m.destination COLLATE utf8mb4_0900_ai_ci
            WHERE {$whereSql}
        ) canonical_rows";

    $st = db()->prepare($sql);
    // Placeholders in the derived attempt table occur before the outbound WHERE placeholders.
    $st->execute(array_merge($attemptParams, $params));
    $row = $st->fetch() ?: [];

    return [
        'total'     => (int)($row['total'] ?? 0),
        'ok'        => (int)($row['ok'] ?? 0),
        'delivered' => (int)($row['delivered'] ?? 0),
        'failed'    => (int)($row['failed'] ?? 0),
        'pending'   => (int)($row['pending'] ?? 0),
    ];
}
