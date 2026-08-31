<?php
/**
 * ELLSMS — sends SMS by calling the backend platform's REST API
 * (POST {API_BASE_URL}/api/messages/send), the same endpoint the very
 * first curl example for this project used. The API itself performs the
 * gateway call and writes the resulting rows into the shared
 * `outbound_message` table — ELLSMS reads those rows back from the
 * response instead of writing them itself, so there's a single source
 * of truth for what was actually sent.
 *
 * Request:
 *   { "sender_user_id": 1, "originator": 5000435800,
 *     "destinations": ["989..."], "content": "..." }
 * Response: a JSON array, one object per destination —
 *   { "id", "sender_user_id", "originator", "destination", "content",
 *     "reference_id", "status", "error_code", "sent_at",
 *     "delivered_at", "delivery_status_code" }
 *   status is "sent" on success or "send_failed" on failure.
 *
 * Phase 8: if the API itself can't be reached at all (network down, wrong URL, timeout), ELLSMS no
 * longer writes fallback rows into outbound_message (a backend-owned table) — see Invariant E in
 * docs/service-boundaries.md. The attempt is instead recorded in ELLSMS's own
 * ellsms_message_attempts table (app/Backend/messages.php's backend_record_message_attempt_failure())
 * so it stays visible for local audit/reconciliation without fabricating backend history.
 *
 * Delivery reports and inbound (MO) messages are received by the
 * backend platform's own /delivery and /mo endpoints straight into the
 * shared outbound_message/inbound_message tables — ELLSMS only reads
 * them, it does not need its own webhook for those.
 */

require_once __DIR__ . '/bootstrap.php';

/**
 * Phase 8: transport (HMAC signing, timeouts, request-id, error classification) now lives in
 * app/Backend/ApiClient.php's backend_api_request() — the ONE authenticated internal client every
 * backend HTTP call funnels through (STEP 5). backend_service_auth_headers() and
 * describe_api_error() moved there unchanged in behavior; this function is now a thin wrapper
 * translating ApiClient's normalized result into this function's own pre-existing tuple shape, so
 * every caller of backend_api_send() (dispatch_message_raw() below) needed zero changes.
 *
 * Returns [ok, httpCode, decodedBodyOrNull, rawError] — unchanged contract.
 */
function backend_api_send(int $senderUserId, string $originator, array $destinations, string $content): array {
    $result = backend_api_request('POST', '/api/messages/send', [
        'sender_user_id' => $senderUserId,
        'originator'     => ctype_digit($originator) ? (int)$originator : $originator,
        'destinations'   => array_values(array_map('strval', $destinations)),
        'content'        => $content,
    ]);
    return [$result['ok'], $result['http'], $result['data'], $result['error']];
}

/**
 * Deterministic dedup key for a direct/API send with no natural durable
 * reference id — see dispatch_message()'s docblock for the exact
 * tradeoff this makes (10-second window, content-based).
 */
function dispatch_direct_send_dedup_key(int $userId, string $originator, array $destinations, string $content): string {
    sort($destinations);
    return hash('sha256', $userId . '|' . $originator . '|' . implode(',', $destinations) . '|' . $content . '|' . (int)floor(time() / 10));
}

/**
 * Low-level send: validates destination/content, revalidates originator
 * authorization, calls the backend API (or writes fallback failed rows if
 * it's unreachable at all), and reports exactly how many destinations
 * actually sent. Deliberately does NOT touch credit/wallet at all —
 * callers own reserving/committing/releasing the cost around this call
 * (see dispatch_message() below for the common case, and
 * bulk_send_one_item() for the reservation-commit variant bulk jobs use
 * instead of dispatch_message()'s own reserve cycle, since a bulk job's
 * total worst-case cost is already reserved once at bulk_queue_job()
 * time — STEP 9).
 *
 * Returns [ok, infoMessage, sentCount, totalCount, partsPerMessage, retryable, sentDestinations].
 *
 * sentDestinations (added with route/operator pricing) is the list of destinations the backend
 * reported as actually 'sent'. It exists because a send's cost is no longer uniform: with
 * per-operator rates, "3 of 5 sent" no longer determines the amount to settle — WHICH three does.
 * Every failure branch returns an empty list, and $ok=true with a partial send returns exactly the
 * accepted subset. Purely additive: every pre-existing call site destructures at most the first six
 * elements and PHP list-assignment ignores the rest.
 *
 * retryable (Phase 4, STEP 16) classifies a false $ok for callers that retry (run_due_schedules(),
 * bulk_send_one_item() via run_bulk_send_pass()) — true only when the failure was on ELLSMS's own
 * side reaching the gateway (network/timeout/non-2xx — see backend_api_send()), never for a
 * validation/authorization failure (empty destination/content, invalid or unauthorized originator)
 * or a gateway response that was reached but rejected every destination (treated as permanent: the
 * gateway had a real opportunity to accept and explicitly didn't, most often an invalid destination
 * — retrying identical input against the same gateway is expected to fail identically). $ok=true is
 * always retryable=false (nothing to retry).
 */
/**
 * Translates a gateway_send() result into dispatch_message_raw()'s tuple.
 *
 * Deliberately mirrors the legacy branches one for one — same Persian messages, same retryable
 * classification, same ellsms_message_attempts row on an unreachable gateway. A caller must not be
 * able to tell which transport ran, because every one of them (schedules, bulk jobs, auto-reply, the
 * legacy URL API) already depends on this exact shape.
 *
 * Returns one element MORE than the legacy branch: gateway metadata at index 7. Purely additive —
 * PHP list-assignment ignores trailing elements, so no existing call site changes.
 *
 * $recordTransport controls whether each accepted destination gets an ellsms_message_attempts row
 * carrying its provider message id. True for direct sends and schedules, which have no other durable
 * ELLSMS-owned row to hold it; false for bulk items, which record the same identity on their own row
 * (bulk_send_one_item()) and would otherwise be tracked twice by the status poller.
 */
function dispatch_gateway_result(array $user, string $originator, array $destinations, string $content, ?int $scheduleId, array $result, int $parts, int $total, bool $recordTransport = true): array {
    $meta = [
        'gateway_id'             => $result['gateway_id'] ?? null,
        'gateway_config_version' => $result['gateway_config_version'] ?? null,
        'provider_message_ids'   => $result['message_ids'] ?? [],
        // Route/operator as ACTUALLY resolved for this send. Bulk items persist these onto their own
        // row (bulk_send_one_item()) so a later report can describe what happened rather than
        // re-resolving today's sender configuration, which may have changed since.
        'route_id'               => $result['route_id'] ?? null,
        'operators'              => $result['operators'] ?? [],
    ];

    if (!$result['ok'] && $result['sent'] === [] && $result['http'] === 0) {
        // Never reached the gateway at all — recorded in ELLSMS's own attempts table, exactly as the
        // legacy branch does (Invariant E: no fabricated rows in backend-owned tables).
        Logger::error('sms.send.gateway_unreachable', [
            'user_id' => $user['id'] ?? null, 'gateway_id' => $meta['gateway_id'],
            'error_class' => $result['error_class'], 'destination_count' => $total,
        ]);
        $referenceType = $scheduleId !== null ? 'schedule' : 'direct_send';
        $referenceId   = $scheduleId !== null ? (string)$scheduleId : dispatch_direct_send_dedup_key((int)($user['id'] ?? 0), $originator, $destinations, $content);
        backend_record_message_attempt_failure(
            isset($user['organization_id']) ? (int)$user['organization_id'] : null,
            (int)($user['id'] ?? 0),
            $referenceType,
            $referenceId,
            null,
            Logger::currentRequestId(),
            (string)$result['error_class'],
            (string)$result['error']
        );
        return [false, describe_api_error(0, (string)$result['error']) . ' جزئیات در گزارش موجود است.', 0, $total, $parts, (bool)$result['retryable'], [], $meta];
    }

    $sentCount = count($result['sent']);
    Logger::log($sentCount === $total ? 'info' : ($sentCount > 0 ? 'warning' : 'error'), 'sms.send.completed', [
        'user_id' => $user['id'] ?? null, 'sent' => $sentCount, 'total' => $total,
        'gateway_id' => $meta['gateway_id'], 'gateway_config_version' => $meta['gateway_config_version'],
        'request_groups' => $result['groups'] ?? null,
    ]);

    // Record each accepted destination so its delivery status can be polled later. bulk_send_one_item()
    // has its own durable row and records the same identity there instead, so a bulk item is never
    // written twice — see the $recordTransport guard.
    if ($recordTransport) {
        $referenceType = $scheduleId !== null ? 'schedule' : 'direct_send';
        $referenceId   = $scheduleId !== null ? (string)$scheduleId : dispatch_direct_send_dedup_key((int)($user['id'] ?? 0), $originator, $destinations, $content);
        foreach ($result['sent'] as $destination) {
            backend_record_gateway_send(
                isset($user['organization_id']) ? (int)$user['organization_id'] : null,
                (int)($user['id'] ?? 0),
                $referenceType,
                $referenceId,
                $destination,
                [
                    'provider_message_id'    => $result['message_ids'][$destination] ?? '',
                    'gateway_id'             => $meta['gateway_id'],
                    'gateway_config_version' => $meta['gateway_config_version'],
                    'route_id'               => $result['route_id'] ?? null,
                    'operator_id'            => $result['operators'][$destination] ?? null,
                    'request_id'             => Logger::currentRequestId(),
                ]
            );
        }
    }

    if ($sentCount === $total) {
        return [true, 'به ' . to_persian_digits((string)$total) . " شماره ارسال شد — {$parts} بخش برای هرکدام، مجموعاً " . to_persian_digits((string)($parts * $sentCount)) . ' بخش.', $sentCount, $total, $parts, false, $result['sent'], $meta];
    }
    if ($sentCount > 0) {
        return [true, 'به ' . to_persian_digits((string)$sentCount) . ' از ' . to_persian_digits((string)$total) . ' شماره ارسال شد — برای مشاهده‌ی موارد ناموفق به گزارش مراجعه کنید.', $sentCount, $total, $parts, false, $result['sent'], $meta];
    }
    // Reached and rejected everything, or a non-transport failure: retryable comes from the classified
    // error rather than being hardcoded, matching the legacy path's Phase 8 behaviour.
    return [false, 'گیت‌وی همه‌ی مقصدها را رد کرد. جزئیات در گزارش موجود است.', 0, $total, $parts, (bool)$result['retryable'], [], $meta];
}

/**
 * $perDestinationContent (Phase 9C, optional): a destination-KEYED map of real per-recipient text.
 * $content stays required and remains the fallback for any destination absent from that map, the
 * value used for the legacy (non-gateway) path — which has no per-row content notion — and what
 * every existing caller still passes alone. Only the caller (bulk_send_group()) that has verified the
 * resolved connector actually consumes per-recipient text passes this; everyone else is byte-for-byte
 * unaffected. See gateway_send_context() for how it becomes messages_array.
 *
 * $perDestinationIdempotencyKeys (Phase 9C.10, optional): a destination-KEYED map of deterministic
 * per-message tokens, forwarded unchanged to gateway_send_for_dispatch(). See its docblock and
 * gateway_send_context()'s for why these must be caller-supplied rather than generated per attempt.
 */
function dispatch_message_raw(array $user, string $originator, array $destinations, string $content, ?int $scheduleId = null, bool $recordTransport = true, ?array $perDestinationContent = null, ?array $perDestinationIdempotencyKeys = null): array {
    Logger::info('sms.send.requested', [
        'user_id'       => $user['id'] ?? null,
        'message_count' => count($destinations),
        'schedule_id'   => $scheduleId,
    ]);

    if (!$destinations)          return [false, 'شماره مقصد معتبری وارد نشده است.', 0, 0, 0, false, []];
    if (trim($content) === '')   return [false, 'متن پیام خالی است.', 0, 0, 0, false, []];

    $originator = normalize_originator($originator);
    if ($originator === null) return [false, 'خط ارسال‌کننده خالی یا غیرعددی است — آن را بالا یا در تنظیمات وارد کنید.', 0, count($destinations), 0, false, []];

    // Revalidated here, not just at the point a page/schedule/job was
    // created — this is the single choke point every send path (direct,
    // scheduled, bulk, auto-reply, the legacy URL API) funnels through,
    // so it's also where permissions that changed AFTER creation (e.g. a
    // number reassigned, panel access revoked) are caught before a
    // background job silently keeps sending on stale authorization.
    if (!can_use_originator($user, $originator)) {
        Logger::warning('sms.send.rejected_unauthorized_originator', [
            'user_id'    => $user['id'] ?? null,
            'originator' => $originator,
        ]);
        return [false, 'استفاده از این خط ارسال برای شما مجاز نیست — از خطوط تخصیص‌یافته به خودتان استفاده کنید.', 0, count($destinations), 0, false, []];
    }

    $parts = sms_parts($content);
    $total = count($destinations);

    // The configured-gateway path (docs/sms-gateway-connectors.md). Returns null when the gateway
    // transport is switched off or no gateway is configured for this route, in which case the legacy
    // client below runs exactly as it always has — this seam is additive, and the default is legacy.
    $gatewayResult = gateway_send_for_dispatch($user, $originator, $destinations, $content, null, $perDestinationContent, $perDestinationIdempotencyKeys);
    if ($gatewayResult !== null) {
        return dispatch_gateway_result($user, $originator, $destinations, $content, $scheduleId, $gatewayResult, $parts, $total, $recordTransport);
    }

    $apiResult = backend_api_request('POST', '/api/messages/send', [
        'sender_user_id' => (int)$user['id'],
        'originator'     => ctype_digit($originator) ? (int)$originator : $originator,
        'destinations'   => array_values(array_map('strval', $destinations)),
        'content'        => $content,
    ]);
    $reached = $apiResult['ok'];
    $http    = $apiResult['http'];
    $rows    = $apiResult['data'];
    $err     = $apiResult['error'];

    if (!$reached) {
        // Phase 8 (Invariant E, STEP 16): no longer writes fallback rows into outbound_message (a
        // backend-owned table) — recorded in ELLSMS's own ellsms_message_attempts instead, so the
        // attempt stays locally visible (cron/jobs-status.php) without fabricating backend history.
        Logger::error('sms.send.api_unreachable', [
            'user_id'          => $user['id'] ?? null,
            'http'             => $http,
            'error'            => $err,
            'error_class'      => $apiResult['error_class'],
            'destination_count' => $total,
        ]);
        $referenceType = $scheduleId !== null ? 'schedule' : 'direct_send';
        $referenceId   = $scheduleId !== null ? (string)$scheduleId : dispatch_direct_send_dedup_key((int)($user['id'] ?? 0), $originator, $destinations, $content);
        backend_record_message_attempt_failure(
            isset($user['organization_id']) ? (int)$user['organization_id'] : null,
            (int)($user['id'] ?? 0),
            $referenceType,
            $referenceId,
            null,
            $apiResult['request_id'],
            $apiResult['error_class'],
            (string)$err
        );
        // Phase 8 (STEP 18/STEP 8 acceptance): retryable is derived from the classified error, not
        // hardcoded — a real connection failure (UNAVAILABLE/TIMEOUT) is retryable, but a response
        // the backend actually sent and explicitly rejected (401/403 UNAUTHORIZED, 409 CONFLICT,
        // 400/404/422 REJECTED) is not: identical input replayed against the same gateway/signing
        // config is expected to fail identically, so treating it as transient would only burn
        // through the worker's retry/backoff budget before landing on the same permanent outcome
        // (Invariant I still holds for the genuinely-retryable classes).
        $retryable = BackendError::isRetryable((string)$apiResult['error_class']);
        return [false, describe_api_error($http, $err) . ' جزئیات در گزارش موجود است.', 0, $total, $parts, $retryable, []];
    }

    $sentCount = 0;
    $sentDestinations = [];
    foreach ($rows as $r) {
        if (($r['status'] ?? '') === 'sent') {
            $sentCount++;
            $sentDestinations[] = (string)($r['destination'] ?? '');
        }
    }
    $allOk = $sentCount === $total;

    Logger::log($allOk ? 'info' : ($sentCount > 0 ? 'warning' : 'error'), 'sms.send.completed', [
        'user_id' => $user['id'] ?? null,
        'sent'    => $sentCount,
        'total'   => $total,
    ]);

    if ($allOk) {
        // Reports SEGMENTS, not credits. It used to say "N واحد اعتبار" derived from
        // `$parts * $sentCount`, which was correct only while every segment cost exactly one credit
        // — with admin-configured per-operator rates that arithmetic is no longer the price, and
        // this function deliberately knows nothing about pricing (its caller owns the money).
        return [true, 'به ' . to_persian_digits((string)$total) . " شماره ارسال شد — {$parts} بخش برای هرکدام، مجموعاً " . to_persian_digits((string)($parts * $sentCount)) . ' بخش.', $sentCount, $total, $parts, false, $sentDestinations];
    }
    if ($sentCount > 0) {
        return [true, 'به ' . to_persian_digits((string)$sentCount) . ' از ' . to_persian_digits((string)$total) . ' شماره ارسال شد — برای مشاهده‌ی موارد ناموفق به گزارش مراجعه کنید.', $sentCount, $total, $parts, false, $sentDestinations];
    }
    // Gateway was reached and had a real opportunity to accept every
    // destination, and rejected all of them — treated as permanent, not
    // retryable: identical input replayed against the same gateway is
    // expected to be rejected identically (most commonly an invalid
    // destination), unlike the network/HTTP-unreachable branch above.
    return [false, 'گیت‌وی همه‌ی مقصدها را رد کرد. جزئیات در گزارش موجود است.', 0, $total, $parts, false, []];
}

/**
 * The common send path used by direct send, scheduled send, auto-reply,
 * and 2FA — everything except bulk (see dispatch_message_raw()'s
 * docblock for why bulk is different). Phase 3 (STEP 11): replaces the
 * old "read credit -> compare -> send -> UPDATE currentcredit" sequence
 * (which had a real check-then-deduct race under concurrency, see
 * docs/flows/credit.md) with reserve -> dispatch -> commit-actual /
 * release-unused, so a concurrent duplicate/parallel send against the
 * same account can no longer both pass the credit check and both spend.
 *
 * $walletRefType/$walletRefId identify the underlying business operation
 * for idempotency (Invariant C/F). Callers with a natural durable id
 * (a schedule occurrence, an auto-reply log row) should pass it so a
 * worker retry after a crash can't double-charge — see run_due_schedules()
 * and autoreply_process_one() for the exact refs used. Direct/API sends
 * have no such id, so one is derived deterministically from the request
 * itself (user + originator + destinations + content) within a 10-second
 * bucket — this absorbs the realistic accidental-duplicate case (a
 * double-click, or a browser "back" + resubmit of the exact same form)
 * without a larger UI change (e.g. a client-side idempotency token on
 * every send form, judged out of proportion for a CSRF-protected,
 * interactively-submitted form). It deliberately does NOT protect against
 * a deliberate identical resend a minute later, which must succeed as a
 * new, distinct send — see docs/wallet-architecture.md for this exact
 * tradeoff.
 *
 * Returns [ok, infoMessage, retryable, sentCount, totalCount, partsPerMessage] (Phase 4, STEP 16/17;
 * the trailing three added in Phase 12 for the API's message-status resource — see
 * app/Api/Handlers/Messages.php — purely additive: every pre-existing call site destructures only
 * the first two elements via `[$ok, $info] = dispatch_message(...)`, and PHP list-assignment silently
 * ignores extra array elements, so none of them needed to change). retryable mirrors
 * dispatch_message_raw()'s classification for a failed send; an insufficient-credit rejection or an
 * already-finalized-reservation replay are both treated as permanent (false) — retrying either
 * without a balance/state change would fail identically.
 */
function dispatch_message(array $user, string $originator, array $destinations, string $content, ?int $scheduleId = null, ?string $walletRefType = null, ?string $walletRefId = null, ?string $messageType = null): array {
    $userId  = (int)($user['id'] ?? 0);
    $isAdmin = ($user['role'] ?? null) === 'admin';
    $total   = count($destinations);

    $refType = $walletRefType ?? ($scheduleId !== null ? 'schedule' : 'direct_send');
    $refId   = $walletRefId   ?? ($scheduleId !== null ? (string)$scheduleId : dispatch_direct_send_dedup_key($userId, $originator, $destinations, $content));

    // Support impersonation: nothing may leave the gateway on a customer's behalf
    // (docs/admin-impersonation.md, STEP 8). Enforced HERE — the one function every non-bulk send
    // funnels through — rather than on each send page, so a path anyone adds later is covered by
    // default. Inert outside a browser session: workers, cron and the public API have no
    // $_SESSION, so is_impersonating() is false for all of them and this costs them nothing.
    // Checked before the quota reservation so a refused send consumes no allowance.
    if (!impersonation_action_allowed('send.direct')) {
        impersonation_record_block('send.direct');
        return [false, impersonation_block_message('send.direct'), false, 0, $total, 0];
    }

    // Phase 13 (STEP 20): the message quota is reserved BEFORE the wallet, deliberately — a
    // quota rejection then costs nothing to unwind, whereas checking money first would mean
    // releasing a wallet reservation on every over-quota send. Uses the SAME refType/refId the
    // wallet reservation below uses, so a retry of this exact operation replays BOTH reservations
    // identically and can never double-consume either (Invariant: retries don't double-count).
    // Quota is per-ORGANIZATION; a send with no organization context (a platform admin's own send,
    // or a pre-tenant-backfill legacy user) has no quota to consume, exactly as it has no tenant.
    $organizationId = (int)($user['organization_id'] ?? 0);
    if ($organizationId > 0) {
        $quota = usage_reserve_messages($organizationId, $total, $refType, $refId);
        if (!$quota['ok']) {
            Logger::warning('sms.send.rejected_quota_exceeded', ['organization_id' => $organizationId, 'user_id' => $userId, 'requested' => $total, 'metric' => $quota['metric'] ?? null]);
            return [false, 'سقف ارسال پیامک پلن سازمان شما در این دوره تکمیل شده است. برای ادامه، پلن خود را ارتقا دهید یا تا شروع دوره‌ی بعد صبر کنید.', false, 0, $total, 0];
        }
    }

    // THE authoritative price for this send, resolved server-side from the admin-configured
    // operator/route/rate catalog — the same sms_pricing_price_single_content() the Cost Preview
    // calls, which is what makes preview and send agree by construction rather than by convention
    // (docs/sms-pricing.md). Nothing about the price comes from the caller.
    //
    // Admins are never credit-gated (by design: "Admins send without a credit check," per README),
    // so an exempt pass costs zero and — crucially — never fails closed: a pricing gap must not
    // block a send that was always going to be free.
    $priced = sms_pricing_price_single_content_for_reference(
        $destinations, $content, $originator, $messageType, $isAdmin, $refType, $refId
    );
    if (!$priced['ok']) {
        Logger::warning('sms.send.rejected_pricing_unavailable', [
            'user_id' => $userId, 'unpriced' => $priced['unpriced_count'], 'ref_type' => $refType, 'ref_id' => $refId,
        ]);
        if ($organizationId > 0) {
            usage_release_messages($refType, $refId);
        }
        return [false, cost_preview_reason_message('pricing_unavailable'), false, 0, $total, 0];
    }
    $worstCaseCost = $priced['total_cost'];

    if ($worstCaseCost > 0) {
        $reservation = wallet_reserve($userId, $worstCaseCost, $refType, $refId, "reserve:{$refType}:{$refId}");
        if (!$reservation['ok']) {
            $bal = wallet_balance($userId)['available'];
            Logger::warning('sms.send.rejected_insufficient_credit', ['user_id' => $userId, 'cost' => $worstCaseCost, 'credit' => $bal]);
            // Give the quota back — this send is not happening, so it must not count against the
            // organization's allowance (STEP 19: release on validation/enqueue failure).
            if ($organizationId > 0) {
                usage_release_messages($refType, $refId);
            }
            return [false, "اعتبار کافی نیست: این ارسال به {$worstCaseCost} واحد اعتبار نیاز دارد، اعتبار فعلی شما " . (int)$bal . ' است.', false, 0, $total, 0];
        }
        if (($reservation['status'] ?? 'active') !== 'active') {
            // This exact operation (same refType/refId) was already fully
            // committed or released by a prior attempt — a worker retry
            // after a crash, most likely. Don't dispatch to the gateway
            // again; the financial side is already settled either way.
            Logger::info('sms.send.reservation_already_finalized', ['ref_type' => $refType, 'ref_id' => $refId]);
            return [true, 'این ارسال قبلاً پردازش شده است.', false, 0, $total, 0];
        }
    }

    // The immutable record of the decision, written at ACCEPTANCE (money held, about to dispatch) —
    // not after the gateway answers, so a crash mid-dispatch still leaves the price that was
    // charged fully auditable. Replay-safe: a second write for the same operation is a no-op, so
    // the FIRST acceptance's price is the one history keeps (Invariant G).
    sms_price_snapshot_record($priced, $organizationId ?: null, $userId, $refType, $refId);

    // SLI (issue #5): this is the "accept -> provider" round trip the OTP/Transactional/
    // Notification latency targets are defined against (docs/slo-latency-targets.md). $messageType
    // is the SMS_MESSAGE_TYPES pricing vocabulary, translated to a queue message class purely for
    // this measurement's tagging — dispatch_message() never queues.
    $dispatchStartedAt = microtime(true);
    [$ok, $info, $sentCount, , $parts, $retryable, $sentDestinations] = dispatch_message_raw($user, $originator, $destinations, $content, $scheduleId);
    sli_record_dispatch_latency(
        'dispatch.accept_to_provider_seconds',
        message_class_from_pricing_type($messageType),
        microtime(true) - $dispatchStartedAt
    );

    // Settlement reads the ALREADY-ACCEPTED per-recipient prices; it never re-resolves a rate, so an
    // admin price change between acceptance and the gateway's reply cannot alter what this send
    // costs. With per-operator rates, "3 of 5 sent" no longer determines the amount — which three
    // does, which is why dispatch_message_raw() now reports the accepted destinations.
    $settlement = sms_pricing_settlement($priced, $sentDestinations, $sentCount);
    if ($worstCaseCost > 0) {
        $actualCost = $settlement['cost'];
        if ($actualCost > 0) {
            wallet_commit_reservation($refType, $refId, $actualCost, "commit:{$refType}:{$refId}", null, ['sent' => $sentCount]);
        }
        // Release whatever wasn't actually spent — the full reservation
        // on total failure, just the unsent remainder on partial success.
        // This reservation is single-shot for this one call, so it's
        // always fully finalized right here, never left dangling for
        // something else to clean up later.
        wallet_release_reservation($refType, $refId);
    }
    if ($settlement['by_group'] !== []) {
        sms_price_snapshot_settle($refType, $refId, $settlement['by_group']);
    }

    // Quota consumes the ACTUAL number of messages the gateway accepted, never the requested count
    // — a wholly-failed send returns the full reservation, a partial success keeps only what landed
    // (usage_commit() releases the unused remainder in the same statement). Single-shot, exactly
    // like the wallet finalization above, so nothing is ever left dangling.
    if ($organizationId > 0) {
        usage_commit_messages($refType, $refId, $sentCount);
    }

    return [$ok, $info, $ok ? false : $retryable, $sentCount, $total, $parts];
}

/**
 * Like dispatch_message(), but for callers that may genuinely RETRY the same logical operation on
 * a transient failure — run_due_schedules(), autoreply_process_one() (Phase 4, STEP 11/16/17).
 *
 * dispatch_message() finalizes its reservation (commits the actual cost, releases the remainder) on
 * EVERY call — correct for a one-shot send, but wrong for a retry: a second call with the same
 * refType/refId would find the reservation already finalized by the first attempt and short-circuit
 * as "already processed" WITHOUT ever calling the gateway again, silently reporting success for a
 * message that was never actually sent. This was a real bug, caught by
 * tests/Integration/AutoreplyQueueTest.php's second-attempt assertions, not a hypothetical.
 *
 * This function only finalizes the reservation once the occurrence reaches a genuinely TERMINAL
 * outcome (success, a permanent failure, or attempts exhausted) — $attemptCount (the claim's own
 * post-increment count) decides that here, the same threshold run_due_schedules()/
 * autoreply_process_one() use to decide whether to schedule another retry at all, so the two stay
 * in sync by construction. A retryable failure with attempts remaining leaves the reservation
 * ACTIVE (worst-case cost stays held, not returned to available balance) so the next attempt can
 * genuinely dispatch again instead of instantly replaying a stale "already handled" result.
 *
 * Returns [ok, infoMessage, retryable].
 */
function dispatch_message_retryable(array $user, string $originator, array $destinations, string $content, string $refType, string $refId, int $attemptCount, ?int $scheduleId = null, ?string $messageType = null): array {
    $userId  = (int)($user['id'] ?? 0);
    $isAdmin = ($user['role'] ?? null) === 'admin';
    $total   = count($destinations);

    // Same support-impersonation block as dispatch_message(). In practice this path is reached only
    // by the worker (scheduled occurrences, auto-replies), where impersonation can never be active —
    // it is here so the rule holds for the FUNCTION rather than for the callers it happens to have.
    if (!impersonation_action_allowed('send.schedule')) {
        impersonation_record_block('send.schedule');
        return [false, impersonation_block_message('send.schedule'), false];
    }

    // Phase 13 (STEP 20/21/22): identical quota handling to dispatch_message(), with one crucial
    // difference mirroring how the wallet reservation already behaves here — the quota is finalized
    // ONLY at a terminal outcome. A retryable failure with attempts remaining leaves the quota
    // reserved (still held against the organization's allowance) so the next attempt genuinely
    // dispatches rather than replaying a stale "already handled" result, and so a retry storm can
    // never consume the allowance more than once. This covers scheduled sends AND auto-replies,
    // both of which route through this function (STEP 22: system-triggered messages are not free).
    $organizationId = (int)($user['organization_id'] ?? 0);
    if ($organizationId > 0) {
        $quota = usage_reserve_messages($organizationId, $total, $refType, $refId);
        if (!$quota['ok']) {
            Logger::warning('sms.send.rejected_quota_exceeded', ['organization_id' => $organizationId, 'user_id' => $userId, 'requested' => $total, 'ref_type' => $refType, 'ref_id' => $refId]);
            // Permanent, not retryable: retrying without a plan/period change fails identically,
            // exactly like the insufficient-credit rejection below.
            return [false, 'سقف ارسال پیامک پلن سازمان شما در این دوره تکمیل شده است.', false];
        }
    }

    // Retry-aware pricing: once this operation has a price snapshot, every later attempt is priced
    // from THAT decision rather than from whatever the catalog says now — otherwise attempt #3 could
    // settle at a rate the customer never accepted, and could exceed the reservation attempt #1
    // took (STEP 24). See sms_pricing_price_single_content_for_reference().
    $priced = sms_pricing_price_single_content_for_reference(
        $destinations, $content, $originator, $messageType, $isAdmin, $refType, $refId
    );
    if (!$priced['ok']) {
        Logger::warning('sms.send.rejected_pricing_unavailable', [
            'user_id' => $userId, 'unpriced' => $priced['unpriced_count'], 'ref_type' => $refType, 'ref_id' => $refId,
        ]);
        if ($organizationId > 0) {
            usage_release_messages($refType, $refId);
        }
        // Permanent, not retryable: a missing tariff is an admin configuration decision, not a
        // transient condition a backoff window would resolve.
        return [false, cost_preview_reason_message('pricing_unavailable'), false];
    }
    $worstCaseCost = $priced['total_cost'];

    if ($worstCaseCost > 0) {
        $reservation = wallet_reserve($userId, $worstCaseCost, $refType, $refId, "reserve:{$refType}:{$refId}");
        if (!$reservation['ok']) {
            $bal = wallet_balance($userId)['available'];
            Logger::warning('sms.send.rejected_insufficient_credit', ['user_id' => $userId, 'cost' => $worstCaseCost, 'credit' => $bal]);
            if ($organizationId > 0) {
                usage_release_messages($refType, $refId);
            }
            return [false, "اعتبار کافی نیست: این ارسال به {$worstCaseCost} واحد اعتبار نیاز دارد، اعتبار فعلی شما " . (int)$bal . ' است.', false];
        }
        if (($reservation['status'] ?? 'active') !== 'active') {
            Logger::info('sms.send.reservation_already_finalized', ['ref_type' => $refType, 'ref_id' => $refId]);
            return [true, 'این ارسال قبلاً پردازش شده است.', false];
        }
    }

    sms_price_snapshot_record($priced, $organizationId ?: null, $userId, $refType, $refId);

    [$ok, $info, $sentCount, , $parts, $retryable, $sentDestinations] = dispatch_message_raw($user, $originator, $destinations, $content, $scheduleId);

    $isTerminal = $ok || !$retryable || $attemptCount >= job_max_attempts();
    $settlement = sms_pricing_settlement($priced, $sentDestinations, $sentCount);
    if ($worstCaseCost > 0 && $isTerminal) {
        $actualCost = $settlement['cost'];
        if ($actualCost > 0) {
            wallet_commit_reservation($refType, $refId, $actualCost, "commit:{$refType}:{$refId}", null, ['sent' => $sentCount]);
        }
        wallet_release_reservation($refType, $refId);
    }
    if ($isTerminal && $settlement['by_group'] !== []) {
        sms_price_snapshot_settle($refType, $refId, $settlement['by_group']);
    }
    if ($organizationId > 0 && $isTerminal) {
        usage_commit_messages($refType, $refId, $sentCount);
    }

    return [$ok, $info, $ok ? false : $retryable];
}

/** Process due scheduled messages. Returns number processed. Used by the worker. */
/**
 * Phase 4: atomic lease-based claim (Invariants A/D/E), a fresh cancellation re-check right before
 * dispatch and a cancellation-safe finalize (Invariant F, STEP 13), and retryable/permanent-failure
 * classification with bounded backoff (Invariants H/I, STEP 16/17) — see
 * docs/job-queue-architecture.md for the full lifecycle. Recurring-occurrence safety (Invariant on
 * "advance next run exactly once") was already correct before this phase: only one worker can ever
 * hold the 'processing' claim on a row at a time, and the next occurrence's run_at is computed and
 * persisted in the SAME statement as the status transition, never as a separate step two workers
 * could race on independently.
 */
/**
 * The "is this schedule row due right now" condition, shared verbatim by run_due_schedules()'s
 * SELECT and its own atomic claim UPDATE so the two can never silently diverge.
 *
 * Phase 9, STEP 20: rewritten from `COALESCE(next_attempt_at, run_at) <= NOW()` to the
 * logically-equivalent explicit-branch form below — COALESCE() over two columns is not sargable,
 * so despite `idx_due (status, next_attempt_at, run_at)` existing since Phase 4, MySQL could never
 * use it for this condition and fell back to a full table scan every tick (confirmed via EXPLAIN
 * against 20,000 seeded rows: `type: ALL`, 20000 rows examined, no key used). This form lets MySQL
 * use `idx_due` as a genuine range scan (confirmed: `type: range`, `key: idx_due`, 2002 rows
 * examined for the same data/due-count — see docs/observability-and-performance.md §14 for the
 * full before/after EXPLAIN output). Behavior is unchanged: a row is due exactly when its
 * next_attempt_at is due, or (having none) when its run_at is due — the same truth table COALESCE
 * expressed, just not hidden from the query planner.
 */
function schedule_due_condition_sql(string $alias = ''): string {
    $col = $alias !== '' ? $alias . '.' : '';
    return "({$col}status = 'active' AND (
               ({$col}next_attempt_at IS NOT NULL AND {$col}next_attempt_at <= NOW())
            OR ({$col}next_attempt_at IS NULL AND {$col}run_at <= NOW())
           ))
           OR ({$col}status = 'processing' AND {$col}lease_expires_at IS NOT NULL AND {$col}lease_expires_at < NOW())";
}

function run_due_schedules(): int {
    $db = db();
    $leaseSeconds = job_lease_seconds();
    $workerId = worker_id();

    // Due rows: normal due-time active rows (using next_attempt_at as an override when a retry is
    // pending, never touching the user-visible run_at for that), PLUS 'processing' rows whose lease
    // has expired — a crashed worker's claim becomes reclaimable here automatically, no separate
    // recovery step required for the common case (Invariant D).
    $claimStartedAt = microtime(true);
    // due_delay_seconds computed in SQL (TIMESTAMPDIFF against the DB's own NOW()), not in PHP via
    // strtotime() — matching cron/jobs-status.php's established oldest-pending-age pattern, and
    // avoiding any PHP/MySQL session timezone mismatch a client-side date parse would risk.
    $due = $db->query('SELECT *, TIMESTAMPDIFF(SECOND, COALESCE(next_attempt_at, run_at), NOW()) AS due_delay_seconds
                       FROM ellsms_schedule WHERE ' . schedule_due_condition_sql() . '
                       ORDER BY run_at ASC LIMIT 20')->fetchAll();
    Metrics::timing('queue.claim.schedule_lookup', (microtime(true) - $claimStartedAt) * 1000, ['found' => count($due)]);
    $n = 0;
    foreach ($due as $s) {
        $wasReclaim = $s['status'] === 'processing';
        try {
            // Atomic claim: the WHERE clause re-states the exact same due/expired-lease condition
            // as the SELECT above, so this is a genuine compare-and-swap — if another worker won
            // the race between our SELECT and this UPDATE, rowCount() is 0 and we move on
            // (Invariant E).
            $claim = $db->prepare(
                'UPDATE ellsms_schedule
                 SET status=\'processing\', claimed_by=?, claimed_at=NOW(),
                     lease_expires_at=DATE_ADD(NOW(), INTERVAL ? SECOND), attempt_count=attempt_count+1
                 WHERE id=? AND (' . schedule_due_condition_sql() . ')'
            );
            $claim->execute([$workerId, $leaseSeconds, $s['id']]);
            if ($claim->rowCount() === 0) continue;

            $attemptCount = (int)$s['attempt_count'] + 1;
            Logger::info($wasReclaim ? 'job.reclaimed' : 'job.claimed', [
                'job_type' => 'schedule', 'schedule_id' => $s['id'], 'worker_id' => $workerId, 'attempt' => $attemptCount,
            ]);

            // schedules.php's own cancel action can target a row that's
            // already 'processing' (a worker claimed it, the user cancels
            // moments later) — re-check right after claiming, before doing
            // any real work, so a cancellation that lands in that window
            // is honored instead of silently overwritten (STEP 13).
            $cancelCheck = $db->prepare('SELECT status FROM ellsms_schedule WHERE id = ?');
            $cancelCheck->execute([$s['id']]);
            if (($cancelCheck->fetch()['status'] ?? '') === 'cancelled') {
                Logger::info('job.cancelled', ['job_type' => 'schedule', 'schedule_id' => $s['id'], 'worker_id' => $workerId, 'stage' => 'before_dispatch']);
                continue; // nothing dispatched, nothing reserved — status stays exactly 'cancelled'
            }

            // Phase 8 (STEP 11): user-state revalidation through the identity provider.
            $row = backend_find_user_by_id((int)$s['user_id']);

            $dests = json_decode($s['destinations'], true) ?: [];
            $retryable = false;
            // Phase 6, STEP 26/27: revalidated from the schedule row's own PERSISTED
            // organization_id, never a session (workers have none) — a NULL organization_id means
            // this row predates tenant-backfill and is left to the legacy per-user checks below,
            // unchanged; a resolved organization_id that's since gone suspended/disabled blocks
            // dispatch just like a revoked panel_access does.
            $scheduleOrgId = isset($s['organization_id']) ? (int)$s['organization_id'] : null;
            $orgStatus = organization_status($scheduleOrgId);
            $orgUsable = $orgStatus === null || !in_array($orgStatus, ['disabled', 'suspended'], true);
            // Phase 13 (STEP 21): the subscription is re-checked at EXECUTION time too — a schedule
            // created while the plan allowed it must not keep firing after that subscription is
            // suspended/expired, and a downgrade between creation and execution takes effect here
            // (Invariant M). Folded into the same $orgUsable gate the tenant check already uses, so
            // the existing "not usable -> don't dispatch" handling below applies unchanged.
            if ($orgUsable && $scheduleOrgId !== null && $scheduleOrgId > 0) {
                $orgUsable = organization_subscription_serviceable($scheduleOrgId)
                    && organization_has_entitlement($scheduleOrgId, Entitlements::SCHEDULES);
                if (!$orgUsable) {
                    Metrics::increment('billing.worker.blocked', 1, ['job_type' => 'schedule']);
                }
            }
            // Re-checked at execution time, not just when the schedule was
            // created — panel_access in particular can have been revoked
            // since (STEP 6: a revoked user must not keep sending via a
            // schedule created before revocation).
            if (is_backend_account_active($row) && has_panel_access($row) && $orgUsable) {
                $user = [
                    'id'         => $row['id'],
                    'role'       => $row['is_admin'] ? 'admin' : 'user',
                    'credit'     => $row['credit'],
                    'originator' => $row['originator'],
                    'organization_id' => isset($s['organization_id']) ? (int)$s['organization_id'] : null,
                ];
                // The wallet reference includes run_count, not just the
                // schedule row's own id — a recurring schedule reuses the
                // same row for every occurrence, so the id alone would
                // make every future occurrence collide with the wallet
                // reservation from the FIRST one (Phase 3, STEP 10).
                //
                // dispatch_message_retryable(), NOT dispatch_message() — a schedule occurrence can
                // genuinely retry (STEP 17), and dispatch_message()'s reservation finalizes on
                // every call, which would make a second real attempt just replay "already
                // processed" instead of actually dispatching again (Phase 4 fix, see that
                // function's own docblock).
                // Message type is left at the configured default (promotional): a scheduled send
                // is an ordinary user-composed message, and no schedule field states otherwise.
                // Scheduled occurrences are priced at EXECUTION, not at scheduling — the pre-existing
                // behavior, retained deliberately and documented in docs/sms-pricing.md §Scheduled.
                // SLI (issue #5): Scheduled's target is queueing delay (how long past the due
                // moment the worker actually got to this occurrence), NOT the API round-trip
                // dispatch_message_retryable() itself takes — that's the same magnitude as
                // Notification's target and would conflate two different things. due_delay_seconds
                // came from the SELECT above (TIMESTAMPDIFF against COALESCE(next_attempt_at,
                // run_at)), matching schedule_due_condition_sql()'s own definition of "due" exactly.
                sli_record_dispatch_latency(
                    'schedule.dispatch_delay_seconds',
                    MESSAGE_CLASS_SCHEDULED,
                    max(0.0, (float)$s['due_delay_seconds'])
                );

                [$ok, $info, $retryable] = dispatch_message_retryable(
                    $user, $s['originator'], $dests, $s['content'],
                    'schedule', $s['id'] . ':' . $s['run_count'], $attemptCount, (int)$s['id'], null
                );
            } else {
                [$ok, $info] = [false, $orgUsable
                    ? 'حساب کاربری وجود ندارد، غیرفعال است، یا دیگر دسترسی پنل ندارد.'
                    : 'سازمان مربوط به این زمان‌بندی معلق یا غیرفعال شده است.'];
            }

            if (!$ok && $retryable && $attemptCount < job_max_attempts()) {
                $delay = job_retry_backoff_seconds($attemptCount);
                Logger::warning('job.retry_scheduled', [
                    'job_type' => 'schedule', 'schedule_id' => $s['id'], 'worker_id' => $workerId,
                    'attempt' => $attemptCount, 'delay_seconds' => $delay,
                ]);
                // status stays 'cancelled' if the user cancelled during
                // dispatch (the CASE guard), otherwise goes back to
                // 'active' to await next_attempt_at — never touches run_at
                // or run_count, since this occurrence hasn't finished yet.
                $db->prepare(
                    "UPDATE ellsms_schedule SET
                       status = CASE WHEN status='cancelled' THEN 'cancelled' ELSE 'active' END,
                       next_attempt_at = DATE_ADD(NOW(), INTERVAL ? SECOND),
                       last_result = ?, claimed_by = NULL, lease_expires_at = NULL
                     WHERE id = ?"
                )->execute([$delay, 'در حال تلاش مجدد: ' . $info, $s['id']]);
                $n++;
                continue;
            }

            if (!$ok) {
                Logger::warning('job.failed_permanent', [
                    'job_type' => 'schedule', 'schedule_id' => $s['id'], 'worker_id' => $workerId,
                    'attempt' => $attemptCount, 'reason' => $retryable ? 'max_attempts_reached' : 'permanent',
                ]);
            } else {
                Logger::info('job.completed', ['job_type' => 'schedule', 'schedule_id' => $s['id'], 'worker_id' => $workerId]);
            }

            $next = null;
            if ($s['repeat_type'] !== 'none') {
                $iv = ['daily' => '+1 day', 'weekly' => '+1 week', 'monthly' => '+1 month'][$s['repeat_type']];
                $t  = strtotime($s['run_at']);
                while ($t <= time()) $t = strtotime($iv, $t);
                $next = date('Y-m-d H:i:s', $t);
            }

            // status stays 'cancelled' (never reverted to active/done) if
            // the user cancelled between our claim and now — the send
            // itself already happened by this point (or was correctly
            // skipped above), so this only affects which status is
            // recorded next, never whether we dispatched (STEP 13: "if
            // cancellation occurs after dispatch, record truthfully rather
            // than pretending it never ran").
            $db->prepare("UPDATE ellsms_schedule SET
                            status = CASE WHEN status='cancelled' THEN 'cancelled' ELSE ? END,
                            run_at=COALESCE(?, run_at), last_run_at=NOW(), last_result=?, run_count=run_count+1,
                            claimed_by=NULL, lease_expires_at=NULL, next_attempt_at=NULL
                          WHERE id=?")
               ->execute([$next ? 'active' : 'done', $next, ($ok ? 'موفق: ' : 'ناموفق: ') . $info, $s['id']]);
            $n++;
        } catch (Throwable $t) {
            // Isolate one bad schedule row the same way run_autoreply_pass()
            // and run_bulk_send_pass() already isolate their own rows/items —
            // previously an exception here aborted the rest of this tick's
            // whole batch of up to 20 due schedules, silently deferring all
            // of them (not just the failing one) to the next tick.
            Logger::error('schedule.row.failed', ['schedule_id' => $s['id'] ?? null, 'exception' => $t]);
            // Best-effort: don't leave the row stuck in 'processing'
            // forever after an unexpected failure — put it back so the
            // next tick (or another worker) can pick it up again
            // immediately, rather than waiting out the full lease.
            try {
                $db->prepare("UPDATE ellsms_schedule SET status='active', claimed_by=NULL, lease_expires_at=NULL WHERE id=? AND status='processing'")
                   ->execute([$s['id']]);
            } catch (Throwable $recoveryFailure) {
                Logger::error('schedule.row.recovery_failed', ['schedule_id' => $s['id'] ?? null, 'exception' => $recoveryFailure]);
            }
        }
    }
    return $n;
}

/**
 * منشی پیامک — SMS auto-responder.
 *
 * Scans `inbound_message` for rows newer than a saved cursor
 * (ellsms_settings.autoreply_last_inbound_id), matches each one against
 * active ellsms_autoreply_rules for the line it arrived on, and sends a
 * templated reply through dispatch_message() for the first rule that
 * matches. The cursor always advances past every row seen, matched or
 * not, so nothing is ever reprocessed even if it triggered no rule.
 *
 * Two safeguards against duplicate replies:
 *  - Each inbound_message_id is claimed with an INSERT protected by a
 *    UNIQUE key before sending — if two worker passes ever raced on the
 *    same row (e.g. a slow pass overlapping the next tick, or more than
 *    one worker container running), only the first claim wins.
 *  - A short per-(rule, sender) cooldown skips sending again if this
 *    rule already replied to the same number very recently — this is
 *    what actually protects against a gateway delivering the same
 *    physical SMS more than once (each delivery becomes its own,
 *    distinct inbound_message row with its own id, so the claim above
 *    can't catch it; the cooldown can).
 *
 * Returns the number of replies actually sent.
 */
const AUTOREPLY_COOLDOWN_SECONDS = 120;

function run_autoreply_pass(): int {
    $db = db();
    $lastId = (int)setting('autoreply_last_inbound_id', '0');
    $sent  = 0;

    // Phase 4: rows whose claim is stuck 'processing' past their lease —
    // either a crashed worker's abandoned claim (Invariant D) or a
    // retryable failure's own backoff window (Invariant I). Handled
    // SEPARATELY from the cursor-based new-row scan below: once the
    // cursor moves past a row's id (which happens unconditionally at the
    // end of a normal pass, success or not), the main query's
    // `id > lastId` would never fetch it again, so a scheduled retry for
    // an already-seen row would otherwise silently never happen.
    $retryClaimStartedAt = microtime(true);
    $retryDue = backend_scan_autoreply_retry_due_inbound(50);
    Metrics::timing('queue.claim.autoreply_retry', (microtime(true) - $retryClaimStartedAt) * 1000, ['found' => count($retryDue)]);
    foreach ($retryDue as $msg) {
        try {
            autoreply_process_one($db, $msg, $sent);
        } catch (Throwable $t) {
            Logger::error('autoreply.row.failed', ['inbound_message_id' => $msg['id'] ?? null, 'exception' => $t]);
        }
    }

    $scanStartedAt = microtime(true);
    $rows = backend_scan_new_inbound_messages($lastId, 100);
    Metrics::timing('queue.claim.autoreply_scan', (microtime(true) - $scanStartedAt) * 1000, ['found' => count($rows)]);
    if (!$rows) return $sent;

    $maxId = $lastId;

    foreach ($rows as $msg) {
        $maxId = max($maxId, (int)$msg['id']);
        try {
            autoreply_process_one($db, $msg, $sent);
        } catch (Throwable $t) {
            // Never let one bad row block the cursor from moving past it —
            // that was the actual root cause of duplicate replies before
            // this fix: an exception here left the cursor stuck, so the
            // same row kept getting re-fetched and re-sent every tick.
            Logger::error('autoreply.row.failed', [
                'inbound_message_id' => $msg['id'] ?? null,
                'exception'          => $t,
            ]);
        }
    }

    set_setting('autoreply_last_inbound_id', (string)$maxId);
    return $sent;
}

/**
 * Process a single inbound_message row: match, claim (Phase 4: lease-based reclaim on a crashed
 * worker's abandoned claim, Invariant D), cooldown-check, send, classify the result for retry
 * (Invariant H/I). Throws on real errors — caller isolates them per-row.
 */
function autoreply_process_one(PDO $db, array $msg, int &$sent): void {
    $line   = normalize_originator((string)$msg['destination']); // our line that received it
    $sender = normalize_originator((string)$msg['originator']);  // the customer's number
    $content = trim((string)$msg['content']);
    if (!$line || !$sender || $line === $sender) return; // malformed row / self-loop

    $rst = $db->prepare(
        "SELECT * FROM ellsms_autoreply_rules
         WHERE originator = ? AND is_active = 1
         ORDER BY FIELD(match_type,'exact','starts_with','contains'), id
         LIMIT 20"
    );
    $rst->execute([$line]);
    $rule = null;
    foreach ($rst->fetchAll() as $candidate) {
        if (autoreply_matches($content, $candidate['keyword'], $candidate['match_type'])) {
            $rule = $candidate;
            break;
        }
    }
    if (!$rule) return;

    $workerId = worker_id();
    $leaseSeconds = job_lease_seconds();

    // Claim this specific inbound row first via the pre-existing
    // UNIQUE(inbound_message_id) constraint. On a duplicate key, try an
    // atomic UPDATE-based reclaim instead of just giving up — this is a
    // single conditional UPDATE, deliberately NOT preceded by its own
    // SELECT ... FOR UPDATE: locking a row before an INSERT that might
    // still need to happen on a genuine first claim is exactly the
    // pattern that caused a real deadlock in Phase 3's wallet code under
    // concurrency (two transactions both taking a lock via a duplicate-key
    // check, then both trying to upgrade it — see app/wallet.php's
    // wallet_lock_account()). A plain conditional UPDATE has no such
    // two-statement lock-then-lock window.
    try {
        $claim = $db->prepare(
            "INSERT INTO ellsms_autoreply_log (rule_id, inbound_message_id, sender, originator, reply_content, ok, info, status, claimed_by, lease_expires_at, attempt_count)
             VALUES (?,?,?,?,?,0,?,'processing',?,DATE_ADD(NOW(), INTERVAL ? SECOND),1)"
        );
        $claim->execute([$rule['id'], $msg['id'], $sender, $line, '', 'در حال پردازش', $workerId, $leaseSeconds]);
        $logId = (int)$db->lastInsertId();
        Logger::info('job.claimed', ['job_type' => 'autoreply', 'inbound_message_id' => $msg['id'], 'worker_id' => $workerId, 'attempt' => 1]);
    } catch (PDOException $e) {
        $reclaim = $db->prepare(
            "UPDATE ellsms_autoreply_log
             SET claimed_by=?, lease_expires_at=DATE_ADD(NOW(), INTERVAL ? SECOND), attempt_count=attempt_count+1, info=?
             WHERE inbound_message_id = ? AND status='processing' AND lease_expires_at IS NOT NULL AND lease_expires_at < NOW()"
        );
        $reclaim->execute([$workerId, $leaseSeconds, 'در حال تلاش مجدد', $msg['id']]);
        if ($reclaim->rowCount() === 0) {
            return; // already sent, already permanently failed, or still actively owned by a live worker
        }
        $idSt = $db->prepare('SELECT id, attempt_count FROM ellsms_autoreply_log WHERE inbound_message_id = ?');
        $idSt->execute([$msg['id']]);
        $found = $idSt->fetch();
        $logId = (int)$found['id'];
        Logger::info('job.reclaimed', ['job_type' => 'autoreply', 'inbound_message_id' => $msg['id'], 'worker_id' => $workerId, 'attempt' => (int)$found['attempt_count']]);
    }

    // Cooldown — this same rule already replied to this same number very
    // recently, so treat this as a duplicate delivery / repeat send
    // rather than firing again. Terminal: never retried (a real repeat
    // send is the correct outcome once the cooldown window passes on its
    // own, not something an attempt-based retry should force sooner).
    $cd = $db->prepare(
        "SELECT COUNT(*) c FROM ellsms_autoreply_log
         WHERE rule_id = ? AND sender = ? AND ok = 1
           AND created_at >= (NOW() - INTERVAL ? SECOND) AND id <> ?"
    );
    $cd->execute([$rule['id'], $sender, AUTOREPLY_COOLDOWN_SECONDS, $logId]);
    if ((int)$cd->fetch()['c'] > 0) {
        $db->prepare("UPDATE ellsms_autoreply_log SET info = ?, status = 'failed_permanent', claimed_by = NULL, lease_expires_at = NULL WHERE id = ?")
           ->execute(['رد شد: به‌تازگی برای همین شماره ارسال شده بود', $logId]);
        return;
    }

    // Phase 8 (STEP 11): user-state revalidation through the identity provider.
    $owner = backend_find_user_by_id((int)$rule['user_id']);
    // Re-checked at execution time: panel_access in particular can have
    // been revoked since the rule was created (STEP 6) — previously only
    // active/deleted were checked here, so a revoked user's rules kept
    // firing (and charging their credit) indefinitely.
    if (!is_backend_account_active($owner) || !has_panel_access($owner)) {
        $db->prepare("UPDATE ellsms_autoreply_log SET info = ?, status = 'failed_permanent', claimed_by = NULL, lease_expires_at = NULL WHERE id = ?")
           ->execute(['رد شد: حساب مالک قانون غیرفعال است یا دیگر دسترسی پنل ندارد', $logId]);
        return;
    }
    // Phase 6, STEP 14/26/27: revalidated from the rule's own PERSISTED organization_id, not a
    // session (no worker has one) — a NULL organization_id means this rule predates
    // tenant-backfill and is left to the legacy per-user checks above, unchanged.
    $ruleOrgId = isset($rule['organization_id']) ? (int)$rule['organization_id'] : null;
    $ruleOrgStatus = organization_status($ruleOrgId);
    if ($ruleOrgStatus !== null && in_array($ruleOrgStatus, ['disabled', 'suspended'], true)) {
        $db->prepare("UPDATE ellsms_autoreply_log SET info = ?, status = 'failed_permanent', claimed_by = NULL, lease_expires_at = NULL WHERE id = ?")
           ->execute(['رد شد: سازمان مربوط به این قانون معلق یا غیرفعال شده است', $logId]);
        return;
    }
    // Phase 13 (STEP 22): an auto-reply is a real, billable outbound message — a rule created while
    // the plan included auto-reply must stop firing once that subscription lapses or the plan no
    // longer includes it. The actual quota consumption happens inside dispatch_message_retryable()
    // below, using this same rule's reference, so an auto-reply counts against the organization's
    // allowance exactly like any other send (STEP 22: system-triggered messages are not free).
    if ($ruleOrgId !== null && $ruleOrgId > 0
        && (!organization_subscription_serviceable($ruleOrgId) || !organization_has_entitlement($ruleOrgId, Entitlements::AUTOREPLY))) {
        $db->prepare("UPDATE ellsms_autoreply_log SET info = ?, status = 'failed_permanent', claimed_by = NULL, lease_expires_at = NULL WHERE id = ?")
           ->execute(['رد شد: اشتراک سازمان فعال نیست یا منشی پیامک در پلن فعلی موجود نیست', $logId]);
        Metrics::increment('billing.worker.blocked', 1, ['job_type' => 'autoreply']);
        return;
    }

    $rendered = autoreply_render($rule['reply_content'], (int)$rule['user_id'], $sender, $line, $content, $rule['keyword']);
    $user = [
        'id'         => $owner['id'],
        'role'       => $owner['is_admin'] ? 'admin' : 'user',
        'credit'     => $owner['credit'],
        'originator' => $owner['originator'],
        'organization_id' => $ruleOrgId,
    ];
    $attemptSt = $db->prepare('SELECT attempt_count FROM ellsms_autoreply_log WHERE id = ?');
    $attemptSt->execute([$logId]);
    $attemptCount = (int)$attemptSt->fetch()['attempt_count'];

    // $logId is the row this function already claimed via a UNIQUE key on
    // inbound_message_id earlier — reusing it as the wallet reference
    // means a retry of this exact inbound row (e.g. an exception here that
    // this function's own caller isolates and logs) can't double-charge.
    // dispatch_message_retryable(), NOT dispatch_message() — this row can genuinely retry (STEP
    // 17), and dispatch_message()'s reservation finalizes on every call, which would make a second
    // real attempt just replay "already processed" instead of actually dispatching again (Phase 4
    // fix, see that function's own docblock).
    // System-triggered reply -> 'transactional'. Determined SERVER-SIDE from the send context, never
    // from anything the incoming message could influence (STEP 16).
    [$ok, $info, $retryable] = dispatch_message_retryable($user, $line, [$sender], $rendered, 'autoreply', (string)$logId, $attemptCount, null, 'transactional');

    $db->prepare('UPDATE ellsms_autoreply_rules SET hit_count = hit_count + 1 WHERE id = ?')->execute([$rule['id']]);

    if ($ok) {
        $db->prepare("UPDATE ellsms_autoreply_log SET reply_content=?, ok=1, info=?, status='sent', claimed_by=NULL, lease_expires_at=NULL WHERE id=?")
           ->execute([$rendered, $info, $logId]);
        Logger::info('job.completed', ['job_type' => 'autoreply', 'inbound_message_id' => $msg['id'], 'worker_id' => $workerId]);
        $sent++;
        return;
    }

    if ($retryable && $attemptCount < job_max_attempts()) {
        // Stays status='processing' — lease_expires_at now doubles as
        // "not eligible for reclaim until this backoff window passes"
        // rather than introducing a separate next_attempt_at column, the
        // same reclaim WHERE clause used for a crashed worker's abandoned
        // claim naturally also gates a scheduled retry.
        $delay = job_retry_backoff_seconds($attemptCount);
        $db->prepare("UPDATE ellsms_autoreply_log SET reply_content=?, ok=0, info=?, lease_expires_at=DATE_ADD(NOW(), INTERVAL ? SECOND), claimed_by=NULL WHERE id=?")
           ->execute([$rendered, 'در حال تلاش مجدد: ' . $info, $delay, $logId]);
        Logger::warning('job.retry_scheduled', ['job_type' => 'autoreply', 'inbound_message_id' => $msg['id'], 'worker_id' => $workerId, 'attempt' => $attemptCount, 'delay_seconds' => $delay]);
        return;
    }

    $db->prepare("UPDATE ellsms_autoreply_log SET reply_content=?, ok=0, info=?, status='failed_permanent', claimed_by=NULL, lease_expires_at=NULL WHERE id=?")
       ->execute([$rendered, $info, $logId]);
    Logger::warning('job.failed_permanent', ['job_type' => 'autoreply', 'inbound_message_id' => $msg['id'], 'worker_id' => $workerId, 'attempt' => $attemptCount, 'reason' => $retryable ? 'max_attempts_reached' : 'permanent']);
}

function autoreply_matches(string $content, string $keyword, string $matchType): bool {
    $c = mb_strtolower(trim(from_persian_digits($content)));
    $k = mb_strtolower(trim(from_persian_digits($keyword)));
    if ($k === '') return false;
    return match ($matchType) {
        'starts_with' => str_starts_with($c, $k),
        'contains'    => str_contains($c, $k),
        default       => $c === $k, // 'exact'
    };
}

/** Substitute {sender} {originator} {name} {date} {time} {keyword} + per-user custom {vars}. */
function autoreply_render(string $template, int $ownerUserId, string $sender, string $originator, string $incomingContent, string $keyword): string {
    $now = date('Y-m-d H:i:s');
    $vars = [
        'sender'     => $sender,
        'originator' => $originator,
        'date'       => jdate($now, false),
        'time'       => to_persian_digits(date('H:i')),
        'keyword'    => $keyword,
        'message'    => $incomingContent,
    ];

    $cst = db()->prepare('SELECT name FROM ellsms_contacts WHERE user_id = ? AND mobile = ? LIMIT 1');
    $cst->execute([$ownerUserId, $sender]);
    $vars['name'] = (string)($cst->fetchColumn() ?: '');

    $vst = db()->prepare('SELECT var_name, var_value FROM ellsms_autoreply_variables WHERE user_id = ?');
    $vst->execute([$ownerUserId]);
    foreach ($vst->fetchAll() as $v) {
        $vars[$v['var_name']] = $v['var_value'];
    }

    $out = $template;
    foreach ($vars as $k => $v) {
        $out = str_replace('{' . $k . '}', $v, $out);
    }
    return $out;
}

/* ==========================================================================
   SMS-based two-factor login
   ========================================================================== */

/** Maximum wrong-guess attempts against one issued challenge before it's permanently dead (STEP 10). */
const TWOFA_MAX_ATTEMPTS = 5;

/**
 * Generate a fresh 6-digit code for $userId, store its hash, and text
 * the raw code to $mobile. Returns [ok, info]. Never logs or returns the
 * raw code anywhere except the outgoing SMS text itself.
 */
function send_2fa_code(int $userId, string $mobile): array {
    $mobile = normalize_msisdn($mobile) ?? '';
    if ($mobile === '') {
        return [false, 'شماره موبایل معتبری برای این حساب ثبت نشده — از مدیر بخواهید آن را اصلاح کند.'];
    }

    // Supersede any still-active prior challenges for this user first —
    // at most one code is ever valid at a time, so repeatedly hitting
    // "resend" can't leave several concurrently-guessable codes live.
    db()->prepare('UPDATE ellsms_2fa_codes SET superseded_at = NOW() WHERE user_id = ? AND consumed = 0 AND superseded_at IS NULL')
       ->execute([$userId]);

    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    db()->prepare('INSERT INTO ellsms_2fa_codes (user_id, code_hash, expires_at) VALUES (?,?, DATE_ADD(NOW(), INTERVAL ? SECOND))')
       ->execute([$userId, hash('sha256', $code), TWOFA_CODE_TTL_SECONDS]);

    $originator = normalize_originator(setting('default_originator', '') ?? '') ?? '';
    if ($originator === '') {
        return [false, 'خط ارسال پیش‌فرض تنظیم نشده — از مدیر بخواهید آن را در تنظیمات مشخص کند.'];
    }
    $text = "کد ورود شما به ELLSMS: {$code}\nاین کد تا ۵ دقیقه معتبر است.";

    // System message — sent under the target user's own id but with role
    // forced to 'admin' here only to bypass dispatch_message()'s credit
    // check, since logging in shouldn't cost the user SMS credit.
    // 'otp' as the message type — again server-determined from the call site, and never selectable
    // by a client (a cheaper OTP tariff must not be reachable by simply claiming the type, STEP 16).
    [$ok, $info] = dispatch_message(['id' => $userId, 'role' => 'admin', 'credit' => 0], $originator, [$mobile], $text, null, null, null, 'otp');
    return [$ok, $ok ? 'کد ارسال شد.' : $info];
}

/**
 * Verify a submitted 2FA code for $userId against the single active
 * (unconsumed, not superseded, unexpired) challenge for that user. Marks
 * it consumed on success (replay-proof — a consumed row can never match
 * again). Wrong guesses increment a durable, per-challenge attempts
 * counter; once TWOFA_MAX_ATTEMPTS is reached that specific challenge is
 * permanently dead regardless of what's submitted next, even the
 * correct code — the user must request a new one. This counter cannot
 * be reset by restarting the browser flow or the session, since it
 * lives on the database row, not in $_SESSION.
 */
function verify_2fa_code(int $userId, string $code): bool {
    $code = from_persian_digits(trim($code));
    if ($code === '' || !ctype_digit($code)) {
        return false;
    }

    $st = db()->prepare(
        "SELECT id, code_hash, attempts FROM ellsms_2fa_codes
         WHERE user_id = ? AND consumed = 0 AND superseded_at IS NULL AND expires_at > NOW()
         ORDER BY id DESC LIMIT 1"
    );
    $st->execute([$userId]);
    $row = $st->fetch();
    if (!$row) {
        return false; // no active challenge for this user
    }
    if ((int)$row['attempts'] >= TWOFA_MAX_ATTEMPTS) {
        return false; // this challenge is permanently exhausted
    }

    if (!hash_equals((string)$row['code_hash'], hash('sha256', $code))) {
        $newAttempts = (int)$row['attempts'] + 1;
        db()->prepare('UPDATE ellsms_2fa_codes SET attempts = attempts + 1 WHERE id = ?')->execute([$row['id']]);
        if ($newAttempts >= TWOFA_MAX_ATTEMPTS) {
            // Distinct, auditable event for the moment a challenge
            // actually becomes exhausted — not just "another wrong
            // guess" — never includes the code itself either way.
            Logger::warning('auth.2fa.lockout', ['user_id' => $userId]);
        }
        return false;
    }

    db()->prepare('UPDATE ellsms_2fa_codes SET consumed = 1 WHERE id = ?')->execute([$row['id']]);
    return true;
}

/* ==========================================================================
   Real account creation
   Reuses the backend's own POST /api/users/ endpoint (rest_api/routers/
   users.py) instead of ELLSMS writing directly into user_ — that endpoint
   already knows the exact required columns, applies the same password
   hashing every other login on the platform expects, and lets the real
   UNIQUE constraints (username/email/code) produce a clean 409 on
   conflict instead of ELLSMS having to guess at that logic itself.
   ========================================================================== */

/**
 * Create a real backend account. $data must match CreateUserRequest in
 * rest_api/routers/users.py: username, password, first_name, last_name,
 * email, mobile (int), national_id, domain_id (int), gender
 * ('MALE'|'FEMALE'), code, daily_limit, min_credit_notify,
 * limit_time_from, limit_time_to.
 * Returns [ok, message, createdUserOrNull].
 */
function backend_create_account(array $data): array {
    // Phase 8: transport delegated to backend_api_request() (app/Backend/ApiClient.php) — 20s
    // request timeout preserved exactly. Success still requires HTTP 201 specifically (not any 2xx),
    // matching this function's original, stricter contract with POST /api/users/.
    $result = backend_api_request('POST', '/api/users/', $data, 5, 20);

    if ($result['ok'] && $result['http'] === 201) {
        return [true, 'حساب ساخته شد.', $result['data']];
    }
    if ($result['http'] === 0) {
        return [false, $result['error'] ?: 'اتصال به API برقرار نشد.', null];
    }
    return [false, describe_api_error($result['http'], is_string($result['error']) ? $result['error'] : null), null];
}

/* ==========================================================================
   Bulk personalized sending
   Shared engine behind ارسال نظیر به نظیر and پیامک هوشمند — see the
   schema comment in db/ellsms_extra.sql for the full rationale. Both
   upload pages resolve their spreadsheet into a plain list of
   ['mobile' => ..., 'content' => ...] before calling bulk_queue_job();
   from that point on neither page is involved again, the worker just
   sends what's in ellsms_bulk_items via the same dispatch_message() path
   as everything else.
   ========================================================================== */

/** Substitute {column_name} placeholders. Unmatched placeholders are left as literal text. */
function render_bulk_template(string $template, array $vars): string {
    $out = $template;
    foreach ($vars as $k => $v) {
        $out = str_replace('{' . $k . '}', $v, $out);
    }
    return $out;
}

/**
 * Queue a bulk-send job. $items is [['mobile'=>string,'content'=>string], ...],
 * already normalized/rendered by the caller.
 *
 * Phase 3 (STEP 9): the job's full worst-case cost is now reserved
 * atomically in the SAME transaction as creating the job + item rows —
 * either both happen or neither does, so a job that looks "funded and
 * queued" can never actually exist without its credit genuinely held
 * (closing the old TOCTOU gap where this was a read-only estimate check
 * against an already-stale snapshot, re-validated only much later when
 * each row actually sent — see docs/flows/credit.md).
 *
 * $throttleCount/$throttleMinutes are optional — set both to pace this
 * job as "send $throttleCount rows every $throttleMinutes minutes"
 * (ارسال تدریجی). Leave both null for the original as-fast-as-the-worker-
 * can behavior (p2p, smart).
 *
 * Returns [ok, message, jobId|null, reasonCode|null] — the trailing reasonCode added in Phase 13
 * ('insufficient_credit' / 'quota_exceeded' / 'internal_error', null on success) so an API caller
 * can branch on a stable machine-readable value instead of parsing the Persian message. Purely
 * additive: every pre-existing call site destructures only the first three elements, and PHP
 * list-assignment ignores extras (the same additive technique dispatch_message() already uses).
 */
function bulk_queue_job(
    array $user, string $type, string $title, string $originator, ?string $template, array $items,
    ?int $throttleCount = null, ?int $throttleMinutes = null, ?string $messageType = null,
    ?string $messageClass = null
): array {
    $messageClass = normalize_bulk_message_class($messageClass);
    if (!$items) return [false, 'هیچ ردیف معتبری در فایل پیدا نشد.', null];

    // Support impersonation: a queued bulk job is a send that happens LATER, which makes it exactly
    // the thing a support session must not be able to leave behind (STEP 8).
    if (!impersonation_action_allowed('send.bulk')) {
        impersonation_record_block('send.bulk');
        return [false, impersonation_block_message('send.bulk'), null, 'impersonation_blocked'];
    }

    $isAdmin = ($user['role'] ?? null) === 'admin';
    $userId  = (int)($user['id'] ?? 0);

    // ONE pricing instant for the whole job (STEP 48). Every row is resolved against the same
    // timestamp, so a rate change while a 50,000-row file is being priced can never split the job
    // across two price periods — and the per-row price is then FROZEN onto the item itself below,
    // so the worker settles at the accepted rate no matter how long the queue takes or how many
    // times a row is retried (STEP 24).
    $pricedMessages = array_map(
        static fn(array $it): array => ['mobile' => (string)$it['mobile'], 'segments' => sms_parts((string)$it['content'])],
        $items
    );
    $priced = sms_pricing_price_messages($pricedMessages, $originator, $messageType, null, $isAdmin);
    if (!$priced['ok']) {
        Logger::warning('bulk.queue_job.rejected_pricing_unavailable', [
            'user_id' => $userId, 'type' => $type, 'unpriced' => $priced['unpriced_count'],
        ]);
        return [false, cost_preview_reason_message('pricing_unavailable'), null, 'pricing_unavailable'];
    }
    $totalCost = $priced['total_cost'];

    try {
        $jobId = db_transaction(function (PDO $db) use ($user, $userId, $isAdmin, $type, $title, $originator, $template, $throttleCount, $throttleMinutes, $items, $totalCost, $priced, $messageClass): int {
            // Phase 6: organization_id comes only from $user['organization_id'] (server-resolved by
            // require_login()/current_organization() for the caller — never trusted from request
            // input) — NULL for an install that hasn't run tenant-backfill yet, which is fine, the
            // job just behaves exactly like a pre-Phase-6 job until it does.
            $organizationId = isset($user['organization_id']) ? (int)$user['organization_id'] : null;
            $db->prepare("INSERT INTO ellsms_bulk_jobs (user_id, organization_id, type, message_class, title, originator, template, throttle_count, throttle_minutes, status, total_rows)
                          VALUES (?,?,?,?,?,?,?,?,?,'pending',?)")
               ->execute([$user['id'], $organizationId, $type, $messageClass, $title, $originator, $template, $throttleCount, $throttleMinutes, count($items)]);
            $jobId = (int)$db->lastInsertId();

            // Phase 13 (STEP 20): a bulk job consumes quota at ACCEPTANCE — the moment it enters the
            // reliable send pipeline — not per transport attempt, so the worker's retries can never
            // re-consume it. Reserved and committed together here because acceptance IS the terminal
            // quota decision for a bulk job: from this point the queue guarantees delivery attempts
            // with bounded retries (Phase 4), so the allowance is genuinely spent. A permanently
            // failing row is NOT refunded — documented in docs/plans-and-entitlements.md.
            // Inside this same transaction, so an over-quota job rolls back whole, exactly like an
            // unfunded one.
            if ($organizationId !== null && $organizationId > 0) {
                $quota = usage_reserve_messages($organizationId, count($items), 'bulk_job', (string)$jobId);
                if (!$quota['ok']) {
                    throw new QuotaExceededException();
                }
                usage_commit_messages('bulk_job', (string)$jobId, count($items));
            }

            if (!$isAdmin && $totalCost > 0) {
                $reservation = wallet_reserve($userId, $totalCost, 'bulk_job', (string)$jobId, "reserve:bulk_job:{$jobId}");
                if (!$reservation['ok']) {
                    // Forces this whole transaction (job row + items,
                    // neither committed yet) to roll back — an unfunded
                    // job must never be left queued.
                    throw new WalletInsufficientBalanceException();
                }
            }

            // The accepted per-row price travels WITH the row. bulk_send_one_item() commits exactly
            // this number and never re-prices, which is what makes a retry (or a row that sends
            // three days later on a throttled job) cost what the customer was quoted at acceptance.
            $ins = $db->prepare(
                'INSERT INTO ellsms_bulk_items
                   (job_id, mobile, content, unit_price_millicredits, price_cost_credits, price_operator_code, price_route_id, price_group_key)
                 VALUES (?,?,?,?,?,?,?,?)'
            );
            foreach ($items as $index => $it) {
                // per_index, not per_mobile: a personalized file may contain the same number twice
                // with different bodies, and those two rows genuinely have different segment counts.
                $p = $priced['per_index'][$index] ?? null;
                $ins->execute([
                    $jobId, $it['mobile'], $it['content'],
                    $p !== null ? (int)$p['unit_price'] : null,
                    $p !== null ? (int)$p['cost'] : null,
                    $p !== null ? (string)$p['operator_code'] : null,
                    $p !== null ? $p['route_id'] : null,
                    $p !== null ? $p['group_key'] : null,
                ]);
            }

            sms_price_snapshot_record($priced, $organizationId ?: null, $userId, 'bulk_job', (string)$jobId);

            return $jobId;
        });
    } catch (WalletInsufficientBalanceException) {
        $bal = wallet_balance($userId)['available'];
        return [false, "اعتبار کافی نیست: این ارسال به {$totalCost} واحد اعتبار نیاز دارد، اعتبار فعلی شما " . (int)$bal . ' است.', null, 'insufficient_credit'];
    } catch (QuotaExceededException) {
        return [false, 'سقف ارسال پیامک پلن سازمان شما در این دوره تکمیل شده است. برای ادامه، پلن خود را ارتقا دهید یا تا شروع دوره‌ی بعد صبر کنید.', null, 'quota_exceeded'];
    } catch (Throwable $t) {
        Logger::error('bulk.queue_job.failed', ['user_id' => $user['id'] ?? null, 'type' => $type, 'exception' => $t]);
        return [false, 'خطا در ذخیره‌سازی. لطفاً دوباره تلاش کنید.', null, 'internal_error'];
    }

    return [true, to_persian_digits((string)count($items)) . ' ردیف در صف ارسال قرار گرفت.', $jobId, null];
}

/**
 * Atomically claims up to $limit bulk items belonging to jobs matching $jobFilterSql (a WHERE
 * fragment over a `j`-aliased ellsms_bulk_jobs subquery — e.g. restricting to one throttled job, or
 * to every unthrottled 'processing' job) — Invariant A/B/E, STEP 5.
 *
 * Uses plain `UPDATE ... ORDER BY id LIMIT n` as the claim primitive, NOT
 * `SELECT ... FOR UPDATE SKIP LOCKED` — that was tried first and is the textbook pattern for this
 * kind of claim, but tests/Integration/BulkItemConcurrencyTest.php caught it silently returning
 * FEWER rows than were actually free under genuine concurrent load from two separate connections
 * (confirmed via EXPLAIN and repeated real-subprocess runs, independent of isolation level — not a
 * one-off flake). A single UPDATE statement doesn't have that failure mode: MySQL locks and updates
 * matching rows in id order up to LIMIT as one atomic operation, and a second, truly concurrent
 * UPDATE with the same WHERE either blocks briefly on a row the first is touching and then
 * re-evaluates its own WHERE against what's actually still there once unblocked, or simply finds
 * fewer matching rows to begin with — never "gives up early" the way the SKIP LOCKED attempt did.
 * PDO's rowCount() after an UPDATE tells us how many were claimed; a random per-call $claimToken
 * (not just worker_id(), which is stable across every call this same process makes) lets the
 * follow-up SELECT identify exactly which rows THIS call claimed, since UPDATE has no RETURNING.
 *
 * Two passes for the same indexing reason as the abandoned SELECT version: fresh-due pending rows
 * first (an `ref`-type lookup on the existing (job_id, status) index), then expired-lease
 * reclaimable rows only if capacity remains.
 *
 * Returns the claimed rows already joined with their owning job's user_id/originator (bulk_items
 * itself stores neither). Does NOT return job status — bulk_item_preflight() re-reads that fresh,
 * deliberately not from this claim-time snapshot, right before dispatch (see its own docblock).
 *
 * A note on why claim ordering is NOT joined onto this UPDATE (issue #3): an earlier version of
 * this function joined ellsms_bulk_jobs directly onto the claim UPDATE (`UPDATE ellsms_bulk_items i
 * JOIN ellsms_bulk_jobs bj ...`) so a claim spanning multiple message classes could ORDER BY
 * priority. A real load test (cron/load-test.php, 4 concurrent worker processes, 5000 items) caught
 * it leaving ~3% of items permanently stuck in 'processing' with a valid lease — comparing against
 * the pre-change commit under the IDENTICAL scenario confirmed zero stuck items there, isolating the
 * join as the cause: it pulls ellsms_bulk_jobs into the claim's lock scope, creating new lock
 * contention with whatever else concurrently touches that table (job-activation, wallet
 * reservations) that the original single-table UPDATE never had. Priority isolation between classes
 * is achieved a different way instead — see bulk_claim_unthrottled_items_by_class() below, which
 * calls this function once per class (each call already filtered to one class via $jobFilterSql),
 * so no single call ever needs to order a mixed-class claim.
 */
function bulk_claim_items(PDO $db, string $jobFilterSql, array $jobFilterParams, int $limit): array {
    $workerId = worker_id();
    $leaseSeconds = job_lease_seconds();
    $claimToken = $workerId . ':' . bin2hex(random_bytes(4));
    $claimStartedAt = microtime(true);

    // Deliberately a single-table UPDATE (subquery in WHERE, no JOIN on the target table) — see
    // "A note on why claim ordering is NOT joined onto this UPDATE" below. Priority isolation
    // between message classes is achieved entirely by the CALLER issuing separate, already
    // single-class-filtered calls to this function (bulk_claim_unthrottled_items_by_class()'s
    // per-class quota loop) rather than by ordering a mixed-class claim here.
    db_transaction(function (PDO $db) use ($jobFilterSql, $jobFilterParams, $limit, $claimToken, $leaseSeconds): void {
        $jobIdsSubquery = "SELECT j.id FROM ellsms_bulk_jobs j WHERE {$jobFilterSql}";

        $duePending = $db->prepare(
            "UPDATE ellsms_bulk_items
             SET status='processing', claimed_by=?, claimed_at=NOW(),
                 lease_expires_at=DATE_ADD(NOW(), INTERVAL ? SECOND), attempt_count=attempt_count+1, next_attempt_at=NULL
             WHERE job_id IN ({$jobIdsSubquery})
               AND status='pending' AND (next_attempt_at IS NULL OR next_attempt_at <= NOW())
             ORDER BY id
             LIMIT {$limit}"
        );
        $duePending->execute(array_merge([$claimToken, $leaseSeconds], $jobFilterParams));
        $remaining = $limit - $duePending->rowCount();

        if ($remaining > 0) {
            $expiredLease = $db->prepare(
                "UPDATE ellsms_bulk_items
                 SET claimed_by=?, claimed_at=NOW(),
                     lease_expires_at=DATE_ADD(NOW(), INTERVAL ? SECOND), attempt_count=attempt_count+1
                 WHERE job_id IN ({$jobIdsSubquery})
                   AND status='processing' AND lease_expires_at IS NOT NULL AND lease_expires_at < NOW()
                 ORDER BY id
                 LIMIT {$remaining}"
            );
            $expiredLease->execute(array_merge([$claimToken, $leaseSeconds], $jobFilterParams));
        }
    });

    $sel = $db->prepare(
        "SELECT i.*, j.user_id AS user_id, j.originator AS originator
         FROM ellsms_bulk_items i JOIN ellsms_bulk_jobs j ON j.id = i.job_id
         WHERE i.claimed_by = ?
         ORDER BY i.id"
    );
    $sel->execute([$claimToken]);
    $rows = $sel->fetchAll();

    Metrics::timing('queue.claim.bulk_items', (microtime(true) - $claimStartedAt) * 1000, ['requested' => $limit, 'claimed' => count($rows)]);
    Metrics::gauge('queue.claim.bulk_items.batch_size', count($rows));

    foreach ($rows as $row) {
        $reclaimed = (int)$row['attempt_count'] > 1;
        Logger::info($reclaimed ? 'job.reclaimed' : 'job.claimed', [
            'job_type' => 'bulk_item', 'bulk_item_id' => $row['id'], 'job_id' => $row['job_id'],
            'worker_id' => $workerId, 'attempt' => (int)$row['attempt_count'],
        ]);
        if ($reclaimed) {
            Metrics::increment('queue.lease_reclaimed', 1, ['job_type' => 'bulk_item']);
        }
    }

    return $rows;
}

/**
 * Unthrottled-bulk-item claiming for one worker tick, split fairly across message classes
 * (issue #3) instead of one flat `ORDER BY id LIMIT worker_bulk_batch_size()` across every
 * processing job regardless of class. Without this, a single huge Advertising job created before
 * a smaller Bulk Campaign job would keep winning every tick's entire budget under plain FIFO —
 * not because it's higher priority (it isn't; it's the lowest), but purely because it happened to
 * be older. allocate_priority_quota() reserves each class with backlog a guaranteed floor of the
 * tick's budget first, so neither class can be starved to zero by the other regardless of age.
 *
 * Also emits the per-class depth/oldest-pending-age gauges the acceptance criteria for #3 asks
 * for ("metrics expose depth, throughput, lag and oldest-message-age per class") — throughput/lag
 * are already covered per-call by queue.claim.bulk_items inside bulk_claim_items() above, tagged
 * per class here via the job_filter it's called with.
 */
function bulk_claim_unthrottled_items_by_class(PDO $db, int $totalBudget): array {
    $classes = [MESSAGE_CLASS_BULK_CAMPAIGN, MESSAGE_CLASS_ADVERTISING];

    // oldest_age_seconds computed in SQL (TIMESTAMPDIFF against the DB's own NOW()), not in PHP via
    // strtotime() — matching cron/jobs-status.php's established oldest-pending-age pattern, and
    // avoiding any PHP/MySQL session timezone mismatch a client-side date parse would risk.
    $depthStmt = $db->prepare(
        "SELECT bj.message_class, COUNT(*) AS depth,
                TIMESTAMPDIFF(SECOND, MIN(i.created_at), NOW()) AS oldest_age_seconds
         FROM ellsms_bulk_items i
         JOIN ellsms_bulk_jobs bj ON bj.id = i.job_id
         WHERE bj.status = 'processing' AND bj.throttle_count IS NULL
           AND i.status = 'pending' AND (i.next_attempt_at IS NULL OR i.next_attempt_at <= NOW())
         GROUP BY bj.message_class"
    );
    $depthStmt->execute();
    $depthByClass = array_fill_keys($classes, 0);
    $oldestAgeByClass = [];
    foreach ($depthStmt->fetchAll() as $row) {
        $class = normalize_bulk_message_class($row['message_class']);
        $depthByClass[$class] = (int)$row['depth'];
        $oldestAgeByClass[$class] = (int)$row['oldest_age_seconds'];
    }

    foreach ($classes as $class) {
        Metrics::gauge('queue.bulk.depth', $depthByClass[$class], ['message_class' => $class]);
        Metrics::gauge('queue.bulk.oldest_age_seconds', $oldestAgeByClass[$class] ?? 0, ['message_class' => $class]);
    }

    $quota = allocate_priority_quota($depthByClass, $totalBudget);

    $items = [];
    foreach ($classes as $class) {
        $share = $quota[$class] ?? 0;
        if ($share <= 0) {
            continue;
        }
        $claimed = bulk_claim_items(
            $db,
            "j.status = 'processing' AND j.throttle_count IS NULL AND j.message_class = ?",
            [$class],
            $share
        );
        if ($claimed) {
            $items = array_merge($items, $claimed);
        }
    }

    return $items;
}

/**
 * How many recipients may share ONE provider request.
 *
 * Deliberately distinct from the three sizes it is easy to confuse it with:
 *   - worker_bulk_batch_size()  how many DB rows one worker pass CLAIMS
 *   - IMPORT_CHUNK_SIZE         how many source rows one import chunk analyzes
 *   - throttle_count            how many rows a gradual job may send per window
 *
 * A pass may claim 500 rows and, at a provider batch size of 200, turn them into 200 + 200 + 100.
 * Capped at 1000: a request carrying more than that starts to risk provider-side body limits and
 * makes one timeout cost an unreasonable number of recipients.
 */
function sms_provider_batch_size(): int {
    $configured = (int)(env('SMS_PROVIDER_BATCH_SIZE', '200') ?? '200');
    return max(1, min(1000, $configured));
}

/**
 * Authorization/state preflight for one claimed bulk item.
 *
 * Extracted from bulk_send_one_item() so the BATCHED path applies exactly the same checks, in the
 * same order, per item — batching must never become a way to skip a check that the per-item path
 * performs. Every one of these is re-evaluated at execution time on purpose: a bulk job can sit in
 * the queue long after it was accepted, and a cancellation, an organization suspension, a lapsed
 * subscription or a revoked account must all take effect before the next dispatch, not after the
 * job finishes.
 *
 * Terminal outcomes are written here (cancelled/failed) exactly as before.
 *
 * @return array{ok:bool, user?:array, organization_id?:?int}
 */
function bulk_item_preflight(PDO $db, array $item): array {
    $workerId = worker_id();

    $jobStatusSt = $db->prepare('SELECT status, organization_id FROM ellsms_bulk_jobs WHERE id = ?');
    $jobStatusSt->execute([$item['job_id']]);
    $jobRow = $jobStatusSt->fetch();
    if (($jobRow['status'] ?? '') !== 'processing') {
        $db->prepare("UPDATE ellsms_bulk_items SET status='cancelled', claimed_by=NULL, lease_expires_at=NULL WHERE id=?")
           ->execute([$item['id']]);
        Logger::info('job.cancelled', ['job_type' => 'bulk_item', 'bulk_item_id' => $item['id'], 'job_id' => $item['job_id'], 'worker_id' => $workerId, 'stage' => 'before_dispatch']);
        return ['ok' => false];
    }

    $organizationId = isset($jobRow['organization_id']) ? (int)$jobRow['organization_id'] : null;
    $orgStatus = organization_status($organizationId);
    if ($orgStatus !== null && in_array($orgStatus, ['disabled', 'suspended'], true)) {
        $db->prepare("UPDATE ellsms_bulk_items SET status='failed', error=?, claimed_by=NULL, lease_expires_at=NULL WHERE id=?")
           ->execute(['سازمان مربوط به این ارسال معلق یا غیرفعال شده است.', $item['id']]);
        $db->prepare('UPDATE ellsms_bulk_jobs SET failed_rows = failed_rows + 1 WHERE id=?')->execute([$item['job_id']]);
        Logger::warning('job.failed_permanent', ['job_type' => 'bulk_item', 'bulk_item_id' => $item['id'], 'job_id' => $item['job_id'], 'worker_id' => $workerId, 'reason' => 'organization_' . $orgStatus]);
        return ['ok' => false];
    }

    if ($organizationId !== null && $organizationId > 0 && !organization_subscription_serviceable($organizationId)) {
        $db->prepare("UPDATE ellsms_bulk_items SET status='failed', error=?, claimed_by=NULL, lease_expires_at=NULL WHERE id=?")
           ->execute(['اشتراک سازمان مربوط به این ارسال فعال نیست.', $item['id']]);
        $db->prepare('UPDATE ellsms_bulk_jobs SET failed_rows = failed_rows + 1 WHERE id=?')->execute([$item['job_id']]);
        Logger::warning('job.failed_permanent', ['job_type' => 'bulk_item', 'bulk_item_id' => $item['id'], 'job_id' => $item['job_id'], 'worker_id' => $workerId, 'reason' => 'subscription_not_serviceable']);
        Metrics::increment('billing.worker.blocked', 1, ['job_type' => 'bulk_item']);
        return ['ok' => false];
    }

    $owner = backend_find_user_by_id((int)$item['user_id']);
    if (!is_backend_account_active($owner) || !has_panel_access($owner)) {
        $db->prepare("UPDATE ellsms_bulk_items SET status='failed', error=?, claimed_by=NULL, lease_expires_at=NULL WHERE id=?")
           ->execute(['حساب مالک ارسال غیرفعال است یا دیگر دسترسی پنل ندارد.', $item['id']]);
        $db->prepare('UPDATE ellsms_bulk_jobs SET failed_rows = failed_rows + 1 WHERE id=?')->execute([$item['job_id']]);
        Logger::warning('job.failed_permanent', ['job_type' => 'bulk_item', 'bulk_item_id' => $item['id'], 'job_id' => $item['job_id'], 'worker_id' => $workerId, 'reason' => 'owner_unauthorized']);
        return ['ok' => false];
    }

    $isAdmin = (bool)$owner['is_admin'];
    return [
        'ok' => true,
        'organization_id' => $organizationId,
        'is_admin' => $isAdmin,
        'user' => [
            'id'              => $owner['id'],
            'role'            => $isAdmin ? 'admin' : 'user',
            'originator'      => $owner['originator'],
            'organization_id' => $organizationId,
        ],
    ];
}

/**
 * Settle ONE bulk item against the outcome of a dispatch, batched or not.
 *
 * Money and terminal state stay strictly PER ITEM even when many items shared one provider request.
 * That is deliberate: the wallet commit is keyed by this item's own id
 * ('commit:bulk_item:{id}'), which is what makes a replay after a crash a no-op. One aggregated
 * debit for a whole batch would forfeit that idempotency and make a partial failure unsettleable.
 *
 * $accepted is the set of destinations the provider actually accepted. An item whose destination is
 * missing from it is treated exactly as a single-item failure would be — retried or failed by the
 * existing policy — so one bad recipient in a batch never marks its neighbours sent, and never
 * causes an accepted neighbour to be resent.
 */
function bulk_finalize_item(PDO $db, array $item, array $ctx, bool $groupOk, string $info, int $parts, bool $retryable, ?array $gatewayMeta, array $accepted): bool {
    $workerId = worker_id();
    $destination = (string)$item['mobile'];
    $itemSent = $groupOk && in_array($destination, $accepted, true);
    $sentCount = $itemSent ? 1 : 0;

    if (empty($ctx['is_admin'])) {
        // The price FROZEN onto this row at acceptance (bulk_queue_job()) — never re-resolved here.
        $unitCost   = $item['price_cost_credits'] !== null ? (int)$item['price_cost_credits'] : $parts;
        $actualCost = $sentCount > 0 ? $unitCost : 0;
        if ($actualCost > 0) {
            $commit = wallet_commit_reservation('bulk_job', (string)$item['job_id'], $actualCost, 'commit:bulk_item:' . $item['id']);
            if (($commit['ok'] ?? false) && !($commit['replayed'] ?? false) && !empty($item['price_group_key'])) {
                sms_price_snapshot_add_settlement('bulk_job', (string)$item['job_id'], (string)$item['price_group_key'], $actualCost);
            }
        }
    }

    $attemptCount = (int)$item['attempt_count']; // already incremented by the claim

    if ($itemSent) {
        $db->prepare(
            "UPDATE ellsms_bulk_items
             SET status='sent', error=NULL, claimed_by=NULL, lease_expires_at=NULL, next_attempt_at=NULL,
                 gateway_id = ?, gateway_config_version = ?, provider_message_id = ?,
                 route_id = ?, operator_id = ?,
                 delivery_status = IF(? IS NULL, delivery_status, 'sent')
             WHERE id=?"
        )->execute([
            $gatewayMeta['gateway_id'] ?? null,
            $gatewayMeta['gateway_config_version'] ?? null,
            // Keyed by THIS item's destination. gateway_send() returns a destination-keyed map, so a
            // batched send still gives every row its own provider reference — never the first
            // recipient's, and never one id shared across rows.
            $gatewayMeta['provider_message_ids'][$destination] ?? null,
            $gatewayMeta['route_id'] ?? null,
            $gatewayMeta['operators'][$destination] ?? null,
            $gatewayMeta['gateway_id'] ?? null,
            $item['id'],
        ]);
        $db->prepare('UPDATE ellsms_bulk_jobs SET sent_rows = sent_rows + 1 WHERE id=?')->execute([$item['job_id']]);
        Logger::info('job.completed', ['job_type' => 'bulk_item', 'bulk_item_id' => $item['id'], 'job_id' => $item['job_id'], 'worker_id' => $workerId]);
        return true;
    }

    if ($retryable && $attemptCount < job_max_attempts()) {
        $delay = job_retry_backoff_seconds($attemptCount);
        $db->prepare("UPDATE ellsms_bulk_items SET status='pending', error=?, claimed_by=NULL, lease_expires_at=NULL, next_attempt_at=DATE_ADD(NOW(), INTERVAL ? SECOND) WHERE id=?")
           ->execute(['در حال تلاش مجدد: ' . $info, $delay, $item['id']]);
        Logger::warning('job.retry_scheduled', ['job_type' => 'bulk_item', 'bulk_item_id' => $item['id'], 'job_id' => $item['job_id'], 'worker_id' => $workerId, 'attempt' => $attemptCount, 'delay_seconds' => $delay]);
        return false;
    }

    $db->prepare("UPDATE ellsms_bulk_items SET status='failed', error=?, claimed_by=NULL, lease_expires_at=NULL, next_attempt_at=NULL WHERE id=?")
       ->execute([$info, $item['id']]);
    $db->prepare('UPDATE ellsms_bulk_jobs SET failed_rows = failed_rows + 1 WHERE id=?')->execute([$item['job_id']]);
    Logger::warning('job.failed_permanent', [
        'job_type' => 'bulk_item', 'bulk_item_id' => $item['id'], 'job_id' => $item['job_id'], 'worker_id' => $workerId,
        'attempt' => $attemptCount, 'reason' => $retryable ? 'max_attempts_reached' : 'permanent',
    ]);
    return false;
}

/**
 * Send a set of claimed items that may legally share provider requests, and settle each one.
 *
 * WHAT THIS FUNCTION DOES NOT DO: it does not implement batching. gateway_send() already resolves an
 * operator per destination, groups by effective configuration, and emits one request per group when
 * the gateway's send_mode is 'batch' — and it already returns provider ids and operators keyed BY
 * DESTINATION. The whole defect was that the bulk worker called dispatch_message_raw() with a
 * one-element array, so that machinery never received more than one recipient and a 1M-row job
 * became 1M provider requests. This function's only job is to hand it the whole compatible set.
 *
 * Consequently a per_message gateway keeps sending one request per recipient — gateway_send() makes
 * that decision from the connector, not this worker, so nothing here needs to know about it.
 *
 * $items must already be compatible: same job, same owner, same originator, same content. See
 * bulk_group_key().
 *
 * @return int how many items actually sent
 */
function bulk_send_group(PDO $db, array $items, array $ctx): int {
    if ($items === []) {
        return 0;
    }

    $destinations = array_values(array_map(static fn(array $i): string => (string)$i['mobile'], $items));
    $content      = (string)$items[0]['content'];
    $originator   = (string)$items[0]['originator'];

    // Phase 9C: a group may now contain rows whose content genuinely differs (see bulk_group_key()) —
    // keyed by destination, not position, so it survives gateway_send()'s internal grouping/splitting
    // untouched. Built unconditionally and cheaply (it is only ever READ by gateway_send_context() —
    // see its own docblock — when the resolved connector's compiled parameters actually reference
    // messages_array; a plain connector never looks at this map and gets $content exactly as before).
    // Two mobiles resolving to the same destination string keep the LAST row's text, matching how
    // $accepted / provider ids below already collapse by destination.
    $perDestinationContent = [];
    $perDestinationIdempotencyKeys = [];
    foreach ($items as $item) {
        $destination = (string)$item['mobile'];
        $perDestinationContent[$destination] = (string)$item['content'];
        // Phase 9C.10 — deterministic per bulk_item.id, NOT a fresh random value: a lease-expiry
        // reclaim and retry re-claims the SAME row (bulk_claim_items() UPDATEs it in place, see its
        // own docblock), so this key is identical on every attempt for this exact recipient. A
        // provider whose configured connector references idempotency_keys_array can therefore
        // recognize "I already accepted bulk_item #N" even if the worker crashed and retried — the
        // generic mitigation for the residual at-least-once window documented in
        // docs/at-least-once-delivery.md. Namespaced so two installations, or a stray collision with
        // some other reference an operator's provider account might see, can never coincide.
        $perDestinationIdempotencyKeys[$destination] = 'ellsms:bulk_item:' . $item['id'];
    }

    Metrics::increment('bulk.provider_batch.request', 1);
    Metrics::gauge('bulk.provider_batch.size', count($destinations));

    // $recordTransport = false: each bulk row IS the durable record and stores its own provider
    // message id below. A second ellsms_message_attempts row would make the status poller track the
    // same message twice.
    [$ok, $info, , , $parts, $retryable, $sentDestinations, $gatewayMeta] = array_pad(
        dispatch_message_raw($ctx['user'], $originator, $destinations, $content, null, false, $perDestinationContent, $perDestinationIdempotencyKeys),
        8,
        null
    );

    $accepted = is_array($sentDestinations) ? array_map('strval', $sentDestinations) : [];

    $sent = 0;
    foreach ($items as $item) {
        // Segments for THIS item's own text, not the group's representative $parts — correctness
        // matters here even though it is a fallback: bulk_finalize_item() only falls back to it when
        // the row's frozen price is NULL (pre-migration rows), but a wrong count in that fallback
        // would misprice exactly the rows Phase 9C makes it possible to co-batch with different text.
        $itemParts = sms_parts((string)$item['content']);
        if (bulk_finalize_item($db, $item, $ctx, (bool)$ok, (string)$info, $itemParts, (bool)$retryable, $gatewayMeta, $accepted)) {
            $sent++;
        }
    }

    Metrics::increment($sent > 0 ? 'bulk.provider_batch.success' : 'bulk.provider_batch.failure', 1);
    Metrics::increment('bulk.provider_batch.items', count($items));

    return $sent;
}

/**
 * The fields that MUST match before two claimed items may share a provider request.
 *
 * Only what the WORKER owns is keyed here. gateway_send() applies its own finer grouping on top
 * (operator, effective parameter signature, per-recipient template variables), so duplicating that
 * logic would be both redundant and a second place to get it wrong.
 *
 *  - job_id         job status, organization and subscription are re-checked per job, and
 *                   sent_rows/failed_rows are per job.
 *  - user_id        authorization (can_use_originator) and the owner-account check are per user.
 *  - organization_id  tenant isolation and the wallet reservation identity.
 *  - originator     changes the request and the authorization decision.
 *  - content        Rows whose text differs (p2p/smart) group separately UNLESS the resolved
 *                   connector actually consumes per-recipient text (Phase 9C) — see
 *                   bulk_connector_supports_per_recipient_content() below. dispatch_message_raw()
 *                   carries one $content string as its REQUIRED fallback either way; content only
 *                   drops out of this key when a real per-destination map will travel alongside it
 *                   (built in bulk_send_group()), so a plain connector is never handed the wrong
 *                   text and correctness never depends on guessing a capability.
 *
 * Hashed because the content can be long and this value is only ever compared, never read back.
 */
function bulk_group_key(array $item, array $ctx): string {
    $fields = [
        (string)$item['job_id'],
        (string)$item['user_id'],
        (string)($ctx['organization_id'] ?? ''),
        (string)$item['originator'],
    ];
    if (!bulk_connector_supports_per_recipient_content((string)$item['originator'])) {
        $fields[] = (string)$item['content'];
    }
    return hash('xxh128', implode("\0", $fields));
}

/**
 * Not memoized here on purpose — gateway_connector_capability_for_sender() already resolves through
 * sms_pricing_route_for_sender() (TTL-cached, sms_pricing_cache_reset()) and gateway_compiled()
 * (cached by config_version, gateway_cache_reset()), so a 100,000-row claim from one sender still
 * costs one real lookup, not one per row — an EARLIER version of this function added a second, static
 * memo keyed only by originator on top of those, and that layer had no invalidation of its own: an
 * admin changing a gateway's parameters mid-run (or, concretely, two tests in the same PHP process
 * reusing one sender against two different gateways) would keep returning the FIRST answer forever.
 * Removed rather than fixed with a version key, because the caches this already goes through are the
 * correct place for that invalidation to live — this function has no business duplicating it.
 *
 * Fails toward FALSE (keep grouping by content) on any resolution problem — a capability that cannot
 * be confirmed must never be assumed, because assuming it wrongly would silently send the wrong text
 * to every recipient after the first in a merged group.
 */
function bulk_connector_supports_per_recipient_content(string $originator): bool {
    try {
        $capability = gateway_connector_capability_for_sender($originator, null);
        return ($capability['ok'] ?? false) && ($capability['per_recipient_content'] ?? false);
    } catch (Throwable) {
        return false;
    }
}

/**
 * Send claimed items, batching compatible ones into bounded provider requests.
 *
 * Ordering note: the caller has ALREADY claimed these rows, and the claim is what enforces the
 * gradual-send throttle (a throttled job claims at most throttle_count rows per window). Grouping
 * happens strictly after that, so batching can only ever reshape requests for rows that were
 * already eligible — it can never cause more messages to be sent in a window than the throttle
 * allows.
 *
 * @return int how many items actually sent
 */
function bulk_send_claimed_items(PDO $db, array $items): int {
    $batchSize = sms_provider_batch_size();
    $sent = 0;

    // Preflight first, per item, so a cancelled/unauthorized row is settled and removed from the set
    // before it can influence any grouping decision.
    $groups = [];
    foreach ($items as $item) {
        try {
            $ctx = bulk_item_preflight($db, $item);
            if (!($ctx['ok'] ?? false)) {
                continue;
            }
            $key = bulk_group_key($item, $ctx);
            $groups[$key] ??= ['ctx' => $ctx, 'items' => []];
            $groups[$key]['items'][] = $item;
        } catch (Throwable $t) {
            Logger::error('bulk.item.failed', [
                'bulk_item_id' => $item['id'] ?? null,
                'job_id'       => $item['job_id'] ?? null,
                'exception'    => $t,
            ]);
        }
    }

    foreach ($groups as $group) {
        foreach (array_chunk($group['items'], $batchSize) as $chunk) {
            try {
                $sent += bulk_send_group($db, $chunk, $group['ctx']);
            } catch (Throwable $t) {
                // One failed provider request costs its own chunk and nothing else: the remaining
                // chunks still go out, and these rows keep their lease so they are reclaimed and
                // retried rather than silently abandoned.
                Logger::error('bulk.batch.failed', [
                    'job_id'     => $chunk[0]['job_id'] ?? null,
                    'item_count' => count($chunk),
                    'exception'  => $t,
                ]);
            }
        }
    }

    return $sent;
}

/**
 * Send one claimed bulk item and record the result.
 *
 * Retained as the single-item entry point (and for the tests that exercise one row at a time). The
 * batched path in run_bulk_send_pass() goes through bulk_send_claimed_items(); both share the same
 * preflight, dispatch and settlement code, so there is exactly one implementation of each rule.
 */
function bulk_send_one_item(PDO $db, array $item): bool {
    $ctx = bulk_item_preflight($db, $item);
    if (!($ctx['ok'] ?? false)) {
        return false;
    }
    return bulk_send_group($db, [$item], $ctx) > 0;
}

/**
 * Worker pass. Two kinds of job coexist in the same table:
 *
 *  - Unthrottled (p2p, smart, or gradual-without-a-rate): the original
 *    behavior — up to 20 pending rows total per tick, across all such
 *    jobs, first-come-first-served.
 *  - Throttled (گرادوال ارسال تدریجی — throttle_count/throttle_minutes
 *    set): each such job is paced independently. A job only gets a
 *    batch when enough time has passed since its last batch
 *    (last_throttle_at), and that batch is capped at throttle_count
 *    rows — "send N every M minutes," not a global rate.
 *
 * Marks a job done once nothing pending or in-flight remains for it either way.
 * Returns how many rows actually sent this pass.
 *
 * Phase 4: item selection now goes through bulk_claim_items() — an atomic SELECT ... FOR UPDATE
 * SKIP LOCKED claim, not a plain SELECT — so two worker processes calling run_bulk_send_pass()
 * concurrently each get a disjoint set of items instead of racing to send the same rows twice
 * (Invariant B). The claim transaction itself is short (STEP 29); the dispatch call and the finalize
 * UPDATEs run entirely outside it.
 *
 * Phase 9A: claimed rows now go to bulk_send_claimed_items(), which groups compatible ones into
 * bounded provider requests instead of issuing one request per row. The claim is unchanged and
 * still runs FIRST, which is what keeps the gradual-send throttle authoritative — batching only
 * reshapes how already-eligible rows reach the provider.
 */
function run_bulk_send_pass(): int {
    $db = db();
    $db->exec("UPDATE ellsms_bulk_jobs SET status='processing' WHERE status='pending' ORDER BY id LIMIT 1");

    $sent = 0;

    $throttled = $db->query(
        "SELECT * FROM ellsms_bulk_jobs
         WHERE status = 'processing' AND throttle_count IS NOT NULL AND throttle_minutes IS NOT NULL
           AND (last_throttle_at IS NULL OR last_throttle_at <= DATE_SUB(NOW(), INTERVAL throttle_minutes MINUTE))"
    )->fetchAll();

    foreach ($throttled as $job) {
        $limit = max(1, (int)$job['throttle_count']);
        $jobId = (int)$job['id'];
        // The CLAIM is what enforces the gradual rate: at most throttle_count rows leave the queue
        // per window. Batching happens strictly afterwards and only reshapes how those already
        // eligible rows are handed to the provider, so it can never send more than the window allows.
        $items = bulk_claim_items($db, 'j.id = ?', [$jobId], $limit);
        if (!$items) continue;

        $sent += bulk_send_claimed_items($db, $items);
        $db->prepare('UPDATE ellsms_bulk_jobs SET last_throttle_at = NOW() WHERE id = ?')->execute([$jobId]);
    }

    $unthrottledItems = bulk_claim_items($db, "j.status = 'processing' AND j.throttle_count IS NULL", [], worker_bulk_batch_size());
    $sent += bulk_send_claimed_items($db, $unthrottledItems);

    $doneIds = $db->query(
        "SELECT j.id FROM ellsms_bulk_jobs j
         WHERE j.status='processing' AND NOT EXISTS (
           SELECT 1 FROM ellsms_bulk_items i WHERE i.job_id = j.id AND i.status IN ('pending','processing')
         )"
    )->fetchAll(PDO::FETCH_COLUMN);

    if ($doneIds) {
        $placeholders = implode(',', array_fill(0, count($doneIds), '?'));
        $db->prepare("UPDATE ellsms_bulk_jobs SET status='done' WHERE id IN ({$placeholders})")->execute($doneIds);
        $doneRows = $db->prepare("SELECT id, organization_id, title, sent_rows, failed_rows, total_rows FROM ellsms_bulk_jobs WHERE id IN ({$placeholders})");
        $doneRows->execute($doneIds);
        $doneJobs = $doneRows->fetchAll();
        foreach ($doneIds as $jobId) {
            // Every item already committed its own actual cost as it sent
            // (bulk_send_one_item()); this just returns whatever's left of
            // the job's original worst-case reservation (failed rows,
            // partial-success remainder) back to available_balance
            // instead of leaving it stranded in "reserved" forever
            // (Phase 3, STEP 9 — "do not silently strand reserved funds").
            wallet_release_reservation('bulk_job', (string)$jobId);
        }
        // Phase 12 (STEP 27/28): fired AFTER the status='done' UPDATE and the reservation releases
        // above have both happened — never from inside a still-open transaction — for every finished
        // job that belongs to an organization (a legacy, pre-tenant-backfill job has nowhere to fan
        // out to and is skipped). bulk.failed only for a TOTAL failure (nothing at all sent);
        // partial success is reported as bulk.completed with the failed_rows count still visible in
        // the payload, matching STEP 28's "do not emit dozens of speculative events" — two event
        // types are enough to distinguish the cases integrators actually branch on.
        foreach ($doneJobs as $job) {
            $organizationId = $job['organization_id'] !== null ? (int)$job['organization_id'] : null;
            if ($organizationId === null) {
                continue;
            }
            try {
                $sentRows = (int)$job['sent_rows'];
                $failedRows = (int)$job['failed_rows'];
                $eventType = ($sentRows === 0 && $failedRows > 0) ? WebhookEvents::BULK_FAILED : WebhookEvents::BULK_COMPLETED;
                webhook_event_emit($organizationId, $eventType, 'bulk_job', (string)$job['id'], [
                    'bulk_job_id' => (int)$job['id'],
                    'title'       => $job['title'],
                    'sent_rows'   => $sentRows,
                    'failed_rows' => $failedRows,
                    'total_rows'  => (int)$job['total_rows'],
                ]);
            } catch (Throwable $t) {
                // A webhook outbox failure must never affect the underlying bulk job, which has
                // already been finalized and paid out above — isolated exactly like every other
                // per-item exception in this pass.
                Logger::error('webhook.event.emit_failed', ['bulk_job_id' => $job['id'] ?? null, 'exception' => $t]);
            }
        }
    }

    return $sent;
}
