<?php
/**
 * Report-summary adapter.
 *
 * Public report pages must not correlate the entire outbound history against delivery attempts on
 * every HTTP request. The materialized cache worker owns that cost boundary; this adapter keeps the
 * existing report_canonical_status_totals() call site stable while redirecting summary-card reads to
 * the bounded daily cache.
 */

declare(strict_types=1);

require_once __DIR__ . '/report_summary_cache.php';

/**
 * Cached report-card totals.
 *
 * Row-level delivery status is still provider-canonical on the paged list. Summary cards are the
 * cached backend transport aggregate, refreshed every minute and periodically reconciled, so opening
 * reports.php is independent of total message-history size.
 *
 * @return array{total:int,ok:int,delivered:int,failed:int,pending:int,updated_at:?string,cached:bool}
 */
function backend_outbound_canonical_summary(
    string $whereSql,
    array $params,
    ?int $organizationId,
    ?int $userId
): array {
    // organizationId/userId are already represented by reports.php's authorized sender_user_id
    // predicate. Keeping them in this signature preserves the reporting API without a second tenant
    // resolution path inside the cache layer.
    return report_summary_cache_read($whereSql, $params);
}
