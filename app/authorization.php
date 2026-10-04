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
 *  - thin DB-read functions (user_assigned_numbers, user_shared_numbers,
 *    user_sendable_numbers, resolve_ellsms_managed_user) whose only job is
 *    fetching the row(s) a decision needs, and
 *  - pure decision functions (can_view_inbound_message, can_use_originator,
 *    is_backend_account_active, has_panel_access, can_demote_or_revoke)
 *    that take already-fetched arrays and return a bool — these are
 *    unit-testable with plain fixture arrays, no database required.
 *
 * Required from app/bootstrap.php so every page already gets these for
 * free, the same way current_user()/require_login()/is_admin() work.
 */

declare(strict_types=1);

function user_assigned_numbers(array $user): array {
    if (($user['role'] ?? null) === 'admin') return [];
    $st = db()->prepare('SELECT number, label FROM ellsms_numbers WHERE assigned_user_id = ? ORDER BY number');
    $st->execute([(int)($user['id'] ?? 0)]);
    return $st->fetchAll();
}

function allowed_originators(array $user): array {
    if (($user['role'] ?? null) === 'admin') return ['*'];
    $numbers = array_column(user_assigned_numbers($user), 'number');
    $organizationId = (int)($user['organization_id'] ?? 0);
    if ($organizationId > 0) {
        $orgNumbers = array_column(organization_assigned_numbers($organizationId), 'number');
        $numbers = array_values(array_unique(array_merge($numbers, $orgNumbers)));
    }
    if ($numbers) return $numbers;
    $legacy = normalize_originator((string)($user['originator'] ?? ''));
    return $legacy ? [$legacy] : [];
}

function organization_assigned_numbers(int $organizationId): array {
    if ($organizationId <= 0) return [];
    $st = db()->prepare('SELECT number, label FROM ellsms_numbers WHERE organization_id = ? ORDER BY number');
    $st->execute([$organizationId]);
    return $st->fetchAll();
}

/**
 * Lines a platform admin has SHARED with this user (ellsms_number_shares, /admin/numbers) — a third
 * way to reach a line, alongside owning it outright (assigned_user_id) and being in the
 * organization it belongs to (organization_id).
 *
 * A share is deliberately send-only, so this feeds sendable_originators() and NOT
 * allowed_originators(): see that pair below and docs/shared-numbers.md.
 */
function user_shared_numbers(array $user): array {
    if (($user['role'] ?? null) === 'admin') return [];
    $userId = (int)($user['id'] ?? 0);
    if ($userId <= 0) return [];
    $st = db()->prepare(
        'SELECT n.number, n.label
           FROM ellsms_number_shares s
           JOIN ellsms_numbers n ON n.id = s.number_id
          WHERE s.user_id = ?
          ORDER BY n.number'
    );
    $st->execute([$userId]);
    return $st->fetchAll();
}

/**
 * Every line this user may send from, as rows for a picker: their own, their organization's, and
 * every one shared with them — de-duplicated by number, ordered by number.
 *
 * This, not user_assigned_numbers(), is what a send page's "ارسال‌کننده" dropdown must read. The two
 * had already drifted before shares existed: allowed_originators() has permitted the organization's
 * numbers since Phase 5, while the dropdowns only ever listed assigned_user_id ones, so a member
 * could be entitled to a line the UI never offered. Both halves now derive from one function, so
 * the list a user sees and the list can_use_originator() accepts cannot diverge again.
 */
function user_sendable_numbers(array $user): array {
    if (($user['role'] ?? null) === 'admin') return [];
    $owned  = array_merge(
        user_assigned_numbers($user),
        organization_assigned_numbers((int)($user['organization_id'] ?? 0))
    );
    $shared = user_shared_numbers($user);

    // A user with no line of their own still sends today: allowed_originators() falls back to their
    // legacy ellsms_meta.originator, and can_use_originator() accepts the panel's default_originator
    // on top of that — which is why the send pages show a free-text field when this returns nothing.
    // A share would otherwise turn that field into a dropdown holding ONLY the shared line, quietly
    // taking their usual sender away, so it is carried into the list it is about to be replaced by.
    if (!$owned && $shared) {
        $fallback = normalize_originator((string)($user['originator'] ?? ''))
            ?? normalize_originator((string)(setting('default_originator', '') ?? ''));
        if ($fallback !== null) $owned[] = ['number' => $fallback, 'label' => 'پیش‌فرض'];
    }

    $rows = array_merge($owned, $shared);
    $seen = [];
    $out  = [];
    foreach ($rows as $row) {
        $number = (string)$row['number'];
        // First writer wins: an owned/organization row keeps its own label over a shared duplicate.
        if (isset($seen[$number])) continue;
        $seen[$number] = true;
        $out[] = $row;
    }
    // Sorted on the value, not on an array key: PHP silently casts a numeric-string key to int, and
    // an originator is a string ('09…' and '9…' must not order by numeric value).
    usort($out, static fn(array $a, array $b): int => strcmp((string)$a['number'], (string)$b['number']));
    return $out;
}

/**
 * The send-side counterpart of allowed_originators(): the same lines PLUS the shared ones.
 *
 * can_use_originator() checks this one. The inbox (can_view_inbound_message(), inbox.php),
 * auto-reply rules and the per-line opt-out list keep checking allowed_originators() — that
 * separation IS the "send only" part of a share, not an oversight.
 */
function sendable_originators(array $user): array {
    $allowed = allowed_originators($user);
    if (in_array('*', $allowed, true)) return $allowed;
    $shared = array_column(user_shared_numbers($user), 'number');
    if (!$shared) return $allowed;
    return array_values(array_unique(array_merge($allowed, $shared)));
}

function can_view_inbound_message(array $user, string $destination): bool {
    $allowed = allowed_originators($user);
    if (in_array('*', $allowed, true)) return true;
    if (!$allowed) return false;
    $dest = normalize_originator($destination);
    return $dest !== null && in_array($dest, $allowed, true);
}

/* Per-user send policy: request-rate limit + source IP allowlist. */
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

function user_send_policy_is_send_request(): bool {
    if (PHP_SAPI === 'cli') return false;
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $path = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: (string)($_SERVER['SCRIPT_NAME'] ?? '');
    if ($path === '/sms/url_send.html') return in_array($method, ['GET','POST'], true);
    if ($method !== 'POST') return false;
    if (in_array((string)($_POST['do'] ?? ''), ['preview','estimate'], true)) return false;
    if (in_array($path, ['/send.php','/new-send.php','/p2p-send.php','/smart-send.php'], true)) return true;
    return (bool)preg_match('#^/api/v1/(messages|bulk-jobs)(?:/|$)#', $path);
}

function user_send_policy_request_allowed(int $userId): bool {
    if ($userId <= 0 || PHP_SAPI === 'cli' || !user_send_policy_is_send_request()) return true;
    static $decision = [];
    if (array_key_exists($userId, $decision)) return $decision[$userId];

    $policy = user_send_policy_get($userId);
    if (!user_send_policy_ip_allowed($userId)) {
        $decision[$userId] = false;
        Logger::warning('sms.send.rejected_ip_policy', ['user_id' => $userId, 'ip' => function_exists('client_ip') ? client_ip() : null]);
        return false;
    }

    if (!empty($policy['rate_limit_enabled'])) {
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

function can_use_originator(array $user, string $originator): bool {
    $normalized = normalize_originator($originator);
    if ($normalized === null) return false;
    $userId = (int)($user['id'] ?? 0);
    if ($userId > 0 && !user_send_policy_request_allowed($userId)) return false;
    if (($user['role'] ?? null) === 'admin') return true;
    // sendable_originators(), not allowed_originators(): a line shared with this user may be sent
    // from, while its inbox/auto-reply/opt-out list stay with its owner (docs/shared-numbers.md).
    if (in_array($normalized, sendable_originators($user), true)) return true;
    $default = normalize_originator((string)(setting('default_originator', '') ?? ''));
    return $default !== null && $normalized === $default;
}

function is_backend_account_active(?array $backendUserRow): bool {
    return (bool)$backendUserRow && (bool)$backendUserRow['active'] && !$backendUserRow['deleted'];
}

function has_panel_access(?array $metaRow): bool {
    return (bool)$metaRow && (bool)$metaRow['panel_access'];
}

function resolve_ellsms_managed_user(int $targetId): ?array {
    if ($targetId <= 0) return null;
    $row = backend_find_user_by_id($targetId);
    if (!$row || !$row['panel_access']) return null;
    return $row;
}

function can_demote_or_revoke(array $actor, int $targetId): bool {
    return $targetId !== (int)($actor['id'] ?? 0);
}
