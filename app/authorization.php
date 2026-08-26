<?php
/**
 * ELLSMS — centralized authorization helpers (Phase 2).
 *
 * Every decision function here is fail-closed: an empty/missing/legacy-
 * broken input always resolves to "no access," never to "show
 * everything." This file exists specifically because
 * PATHFINDER-2026-07-26 / docs/security-review.md found the opposite
 * pattern (an empty legacy field silently meaning "unrestricted") to be
 * the root cause of the CRITICAL inbox.php cross-tenant leak.
 *
 * Split deliberately into:
 *  - thin DB-read functions (user_assigned_numbers, resolve_ellsms_managed_user)
 *    whose only job is fetching the row(s) a decision needs, and
 *  - pure decision functions (can_view_inbound_message, can_use_originator,
 *    is_backend_account_active, has_panel_access, can_demote_or_revoke)
 *    that take already-fetched arrays and return a bool — these are
 *    unit-testable with plain fixture arrays, no database required.
 *
 * Required from app/bootstrap.php so every page already gets these for
 * free, the same way current_user()/require_login()/is_admin() work.
 */

declare(strict_types=1);

/**
 * $user's assigned sender-line rows (number + label), for populating a
 * dropdown — the single source of truth this project previously
 * duplicated verbatim across send.php, new-send.php, p2p-send.php,
 * smart-send.php, and autoreply.php (see
 * PATHFINDER-2026-07-26/02-duplication-report.md #1). Admins are never
 * scoped to a numbers pool, so this deliberately returns [] for them —
 * every existing call site already only calls this for non-admins.
 */
function user_assigned_numbers(array $user): array {
    if (($user['role'] ?? null) === 'admin') {
        return [];
    }
    $st = db()->prepare('SELECT number, label FROM ellsms_numbers WHERE assigned_user_id = ? ORDER BY number');
    $st->execute([(int)($user['id'] ?? 0)]);
    return $st->fetchAll();
}

/**
 * The sender lines (originators) $user is currently allowed to use.
 * Admins get the sentinel ['*'] (unrestricted, matching every existing
 * admin code path). A non-admin gets their assigned numbers pool if
 * non-empty, otherwise falls back to their own legacy
 * ellsms_meta.originator value if set — and otherwise an EMPTY array,
 * never "everyone"/"everything". This is the fix for the inbox.php
 * finding: the old code's `$me['originator'] !== ''` check meant "empty
 * legacy field -> no filter at all -> see every user's messages"; here
 * an empty result means the opposite; zero permitted originators.
 *
 * Phase 6: if $user['organization_id'] is set, every number OWNED BY that organization is also
 * included — the multi-tenant upgrade to sender ownership (STEP 7): any active member of an
 * organization may use any of that organization's sender lines, not just the one individual a
 * number happened to be assigned to historically. Deliberately reads ONLY $user['organization_id']
 * (a value the caller must have already resolved and attached — require_login() does this for web
 * requests via the session-bound current_organization(); background jobs attach it from their own
 * PERSISTED organization_id column, never from a session that doesn't exist in that context — see
 * docs/multi-tenancy-architecture.md's Worker Tenant Context section) — never unioning across
 * every organization a user might belong to, which would violate Invariant C (Org A leaking into
 * Org B) for a genuinely multi-org member. A call site that hasn't been updated to attach
 * organization_id yet simply gets the exact pre-Phase-6 behavior, unchanged — this is additive,
 * not a replacement.
 */
function allowed_originators(array $user): array {
    if (($user['role'] ?? null) === 'admin') {
        return ['*'];
    }
    $numbers = array_column(user_assigned_numbers($user), 'number');
    $organizationId = (int)($user['organization_id'] ?? 0);
    if ($organizationId > 0) {
        $orgNumbers = array_column(organization_assigned_numbers($organizationId), 'number');
        $numbers = array_values(array_unique(array_merge($numbers, $orgNumbers)));
    }
    if ($numbers) {
        return $numbers;
    }
    $legacy = normalize_originator((string)($user['originator'] ?? ''));
    return $legacy ? [$legacy] : [];
}

/** Sender numbers owned by $organizationId — any active member of that organization may use any of them (Phase 6, STEP 7). */
function organization_assigned_numbers(int $organizationId): array {
    if ($organizationId <= 0) {
        return [];
    }
    $st = db()->prepare('SELECT number, label FROM ellsms_numbers WHERE organization_id = ? ORDER BY number');
    $st->execute([$organizationId]);
    return $st->fetchAll();
}

/**
 * True if $user may view/receive inbound messages that arrived on
 * $destination. Fails closed: a user with zero allowed originators can
 * never see any inbound message, regardless of $destination's value.
 */
function can_view_inbound_message(array $user, string $destination): bool {
    $allowed = allowed_originators($user);
    if (in_array('*', $allowed, true)) {
        return true;
    }
    if (!$allowed) {
        return false;
    }
    $dest = normalize_originator($destination);
    return $dest !== null && in_array($dest, $allowed, true);
}

/* ========================================================================
 * Per-user send policy: request-rate limit + source IP allowlist.
 *
 * This is intentionally checked only for real HTTP-originated send requests.
 * CLI workers do not have a trustworthy client IP and must be able to execute
 * schedules/jobs that were already accepted. The request-rate cap therefore
 * governs user/API submission rate (RPS/RPM), while scheduled worker execution
 * keeps its existing queue semantics.
 * ====================================================================== */

function user_send_policy_get(int $userId): array {
    $default = [
        'user_id' => $userId,
        'rate_limit_enabled' => 0,
        'rate_limit_count' => 0,
        'rate_limit_window_seconds' => 60,
        'ip_restriction_enabled' => 0,
    ];
    if ($userId <= 0) return $default;
    try {
        $st = db()->prepare('SELECT * FROM ellsms_user_send_policies WHERE user_id=? LIMIT 1');
        $st->execute([$userId]);
        $row = $st->fetch();
        return $row ? array_merge($default, $row) : $default;
    } catch (Throwable $e) {
        Logger::error('send_policy.read_failed', ['user_id' => $userId, 'exception' => $e]);
        return $default;
    }
}

/** @return list<string> */
function user_send_policy_allowed_ips(int $userId): array {
    if ($userId <= 0) return [];
    try {
        $st = db()->prepare('SELECT ip_or_cidr FROM ellsms_user_send_allowed_ips WHERE user_id=? ORDER BY id');
        $st->execute([$userId]);
        return array_values(array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN)));
    } catch (Throwable $e) {
        Logger::error('send_policy.allowed_ips_read_failed', ['user_id' => $userId, 'exception' => $e]);
        return [];
    }
}

function user_send_policy_save(int $userId, array $input, int $actorUserId): array {
    if ($userId <= 0) return ['ok' => false, 'error' => 'کاربر معتبر نیست.'];

    $rateEnabled = !empty($input['rate_limit_enabled']) ? 1 : 0;
    $rateCount = max(0, min(100000, (int)from_persian_digits((string)($input['rate_limit_count'] ?? 0))));
    $window = (int)($input['rate_limit_window_seconds'] ?? 60);
    if (!in_array($window, [1, 60], true)) $window = 60;
    if ($rateEnabled && $rateCount < 1) return ['ok' => false, 'error' => 'برای محدودیت ارسال، تعداد مجاز باید حداقل ۱ باشد.'];

    $ipEnabled = !empty($input['ip_restriction_enabled']) ? 1 : 0;
    $rawIps = preg_split('/[\r\n,;]+/u', (string)($input['allowed_ips'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $ips = [];
    foreach ($rawIps as $raw) {
        $normalized = strtolower(trim(from_persian_digits((string)$raw)));
        if ($normalized === '') continue;
        if (str_contains($normalized, '/')) {
            [$addr, $prefixRaw] = array_pad(explode('/', $normalized, 2), 2, '');
            if (!ctype_digit($prefixRaw)) return ['ok' => false, 'error' => 'یکی از IP/CIDRها معتبر نیست: ' . $normalized];
            $prefix = (int)$prefixRaw;
            if (filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                if ($prefix < 0 || $prefix > 32) return ['ok' => false, 'error' => 'CIDR IPv4 نامعتبر است: ' . $normalized];
            } elseif (filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
                if ($prefix < 0 || $prefix > 128) return ['ok' => false, 'error' => 'CIDR IPv6 نامعتبر است: ' . $normalized];
            } else {
                return ['ok' => false, 'error' => 'IP معتبر نیست: ' . $normalized];
            }
        } elseif (filter_var($normalized, FILTER_VALIDATE_IP) === false) {
            return ['ok' => false, 'error' => 'IP معتبر نیست: ' . $normalized];
        }
        $ips[] = $normalized;
    }
    $ips = array_values(array_unique($ips));
    if (count($ips) > 100) return ['ok' => false, 'error' => 'حداکثر ۱۰۰ IP/CIDR برای هر کاربر مجاز است.'];
    if ($ipEnabled && $ips === []) return ['ok' => false, 'error' => 'برای فعال‌کردن محدودیت IP حداقل یک IP یا CIDR وارد کنید.'];

    db_transaction(function (PDO $db) use ($userId, $actorUserId, $rateEnabled, $rateCount, $window, $ipEnabled, $ips): void {
        $db->prepare(
            'INSERT INTO ellsms_user_send_policies
                (user_id,rate_limit_enabled,rate_limit_count,rate_limit_window_seconds,ip_restriction_enabled,updated_by_user_id)
             VALUES (?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE rate_limit_enabled=VALUES(rate_limit_enabled), rate_limit_count=VALUES(rate_limit_count),
               rate_limit_window_seconds=VALUES(rate_limit_window_seconds), ip_restriction_enabled=VALUES(ip_restriction_enabled),
               updated_by_user_id=VALUES(updated_by_user_id)'
        )->execute([$userId, $rateEnabled, $rateCount, $window, $ipEnabled, $actorUserId]);

        $db->prepare('DELETE FROM ellsms_user_send_allowed_ips WHERE user_id=?')->execute([$userId]);
        if ($ips) {
            $ins = $db->prepare('INSERT INTO ellsms_user_send_allowed_ips (user_id,ip_or_cidr,created_by_user_id) VALUES (?,?,?)');
            foreach ($ips as $ip) $ins->execute([$userId, $ip, $actorUserId]);
        }
    });

    audit($actorUserId, 'user.send_policy_updated', "user=#{$userId} rate={$rateEnabled}:{$rateCount}/{$window}s ip={$ipEnabled} ips=" . count($ips));
    Logger::info('user.send_policy_updated', [
        'actor_id' => $actorUserId,
        'target_id' => $userId,
        'rate_enabled' => (bool)$rateEnabled,
        'rate_count' => $rateCount,
        'rate_window_seconds' => $window,
        'ip_restriction_enabled' => (bool)$ipEnabled,
        'allowed_ip_count' => count($ips),
    ]);
    return ['ok' => true];
}

function user_send_policy_ip_allowed(int $userId, ?string $clientIp = null): bool {
    $policy = user_send_policy_get($userId);
    if (empty($policy['ip_restriction_enabled'])) return true;
    if (PHP_SAPI === 'cli') return true;

    $clientIp = $clientIp ?? (function_exists('client_ip') ? client_ip() : (string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($clientIp === '' || filter_var($clientIp, FILTER_VALIDATE_IP) === false) return false;
    foreach (user_send_policy_allowed_ips($userId) as $allowed) {
        if (function_exists('ip_in_cidr') && ip_in_cidr($clientIp, $allowed)) return true;
        if (!str_contains($allowed, '/') && $clientIp === $allowed) return true;
    }
    return false;
}

/** Whether this HTTP request is an actual send/queue submission rather than a preview/read. */
function user_send_policy_is_send_request(): bool {
    if (PHP_SAPI === 'cli') return false;
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $path = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: (string)($_SERVER['SCRIPT_NAME'] ?? '');

    if ($path === '/sms/url_send.html') return in_array($method, ['GET','POST'], true);
    if ($method !== 'POST') return false;
    if (in_array((string)($_POST['do'] ?? ''), ['preview','estimate'], true)) return false;

    if (in_array($path, ['/send.php','/new-send.php','/p2p-send.php','/smart-send.php'], true)) return true;
    if (preg_match('#^/api/v1/(messages|bulk-jobs)(?:/|$)#', $path)) return true;
    return false;
}

/**
 * Returns true when this user may submit the current send request. Uses the existing DB-backed
 * sliding-window limiter, but caches the decision for this PHP request so the central originator
 * check can be invoked more than once without double-counting one request.
 */
function user_send_policy_request_allowed(int $userId): bool {
    if ($userId <= 0 || PHP_SAPI === 'cli') return true;
    static $decision = [];
    if (array_key_exists($userId, $decision)) return $decision[$userId];

    $policy = user_send_policy_get($userId);
    if (!user_send_policy_ip_allowed($userId)) {
        $decision[$userId] = false;
        Logger::warning('sms.send.rejected_ip_policy', ['user_id' => $userId, 'ip' => function_exists('client_ip') ? client_ip() : null]);
        return false;
    }

    if (!empty($policy['rate_limit_enabled']) && user_send_policy_is_send_request()) {
        $max = max(1, (int)$policy['rate_limit_count']);
        $window = in_array((int)$policy['rate_limit_window_seconds'], [1,60], true) ? (int)$policy['rate_limit_window_seconds'] : 60;
        $bucket = rate_limit_bucket('user_send_policy', 'user', (string)$userId);
        if (!rate_limit_hit($bucket, $max, $window)) {
            $decision[$userId] = false;
            Logger::warning('sms.send.rejected_user_rate_limit', ['user_id' => $userId, 'max' => $max, 'window_seconds' => $window]);
            return false;
        }
    }

    $decision[$userId] = true;
    return true;
}

/**
 * True if $user may send FROM $originator right now. Permitted set:
 * admins (unrestricted sender-line choice), the user's own
 * allowed_originators(), or the admin-configured system default_originator.
 * Per-user send policy (IP allowlist / RPS-RPM cap) is checked first for
 * HTTP-originated submissions. Workers are intentionally not IP-gated.
 */
function can_use_originator(array $user, string $originator): bool {
    $normalized = normalize_originator($originator);
    if ($normalized === null) {
        return false;
    }
    $userId = (int)($user['id'] ?? 0);
    if ($userId > 0 && !user_send_policy_request_allowed($userId)) {
        return false;
    }
    if (($user['role'] ?? null) === 'admin') {
        return true;
    }
    if (in_array($normalized, allowed_originators($user), true)) {
        return true;
    }
    $default = normalize_originator((string)(setting('default_originator', '') ?? ''));
    return $default !== null && $normalized === $default;
}

/** True if a `user_` row (or null) represents a currently usable backend account. */
function is_backend_account_active(?array $backendUserRow): bool {
    return (bool)$backendUserRow && (bool)$backendUserRow['active'] && !$backendUserRow['deleted'];
}

/** True if an `ellsms_meta` row (or null) currently grants ELLSMS panel access. */
function has_panel_access(?array $metaRow): bool {
    return (bool)$metaRow && (bool)$metaRow['panel_access'];
}

/**
 * Resolve a target user for an ELLSMS-admin management action (edit
 * view, credit change, password reset, KYC edit, role/2FA toggle, ...).
 * Returns null — fail closed — unless the target both exists as a
 * backend account AND already has an `ellsms_meta` row with
 * `panel_access = 1`. An ELLSMS admin is not automatically a global
 * administrator of the backend platform; this is the one gate every
 * such action in users.php goes through.
 *
 * Deliberately NOT used by the "grant" or "create_account" actions —
 * those two are the intentional, narrowly-scoped exception whose entire
 * purpose is bringing a not-yet-managed backend account into ELLSMS for
 * the first time, so requiring pre-existing panel_access would make
 * them impossible to use.
 */
function resolve_ellsms_managed_user(int $targetId): ?array {
    if ($targetId <= 0) {
        return null;
    }
    // Phase 8 (Invariant B): reads through backend_find_user_by_id() (app/Backend/identity.php) —
    // the same superset row current_user() uses, filtered down to this function's own
    // panel_access gate, unchanged.
    $row = backend_find_user_by_id($targetId);
    if (!$row || !$row['panel_access']) {
        return null;
    }
    return $row;
}

/** Prevent an admin from revoking their own access or demoting themselves (self-lockout). */
function can_demote_or_revoke(array $actor, int $targetId): bool {
    return $targetId !== (int)($actor['id'] ?? 0);
}
