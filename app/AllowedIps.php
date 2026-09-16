<?php
/**
 * ELLSMS — organization-level API IP/CIDR allowlist (issue #24).
 *
 * Entries are stored in ellsms_organization_allowed_ips (introduced by the KYC phase). Enforcement
 * is a separate, explicit opt-in flag stored in ellsms_settings under
 * `api_ip_allowlist_enabled.<organization_id>`. Keeping the flag separate is intentional: installs
 * may already contain "active" IP rows from the earlier management-only feature, and deploying this
 * code must NOT suddenly block those tenants. No flag (the migration/default state) means disabled.
 *
 * Enforcement applies to the public /api/v1/* front controller only. Web-panel login stays
 * reachable so an owner/admin can always recover from a bad API allowlist configuration.
 */

declare(strict_types=1);

/** Raised for any allowed-IP validation failure; ->getMessage() is safe to show (AppException semantics). */
class AllowedIpException extends AppException {}

/** Stable per-organization opt-in key; well below ellsms_settings.skey VARCHAR(80). */
function allowed_ip_enforcement_setting_key(int $organizationId): string {
    return 'api_ip_allowlist_enabled.' . $organizationId;
}

/** Returns packed IP bytes with host bits after $prefix cleared. */
function allowed_ip_mask_network(string $packed, int $prefix): string {
    $bits = strlen($packed) * 8;
    if ($prefix < 0 || $prefix > $bits) {
        return '';
    }

    $bytes = str_split($packed);
    $fullBytes = intdiv($prefix, 8);
    $remainder = $prefix % 8;

    if ($remainder !== 0 && $fullBytes < count($bytes)) {
        $mask = (0xFF << (8 - $remainder)) & 0xFF;
        $bytes[$fullBytes] = chr(ord($bytes[$fullBytes]) & $mask);
        $fullBytes++;
    }
    for ($i = $fullBytes; $i < count($bytes); $i++) {
        $bytes[$i] = "\0";
    }
    return implode('', $bytes);
}

/**
 * Validates and canonicalizes a plain IPv4/IPv6 address or CIDR. CIDRs are stored as their network
 * address (e.g. 192.0.2.44/24 -> 192.0.2.0/24), preventing logically identical ranges from being
 * entered under different host-bit spellings. IPv6 is normalized with inet_ntop().
 */
function allowed_ip_normalize(string $raw): ?string {
    $raw = strtolower(trim(from_persian_digits($raw)));
    if ($raw === '') {
        return null;
    }

    $address = $raw;
    $prefix = null;
    if (str_contains($raw, '/')) {
        $parts = explode('/', $raw);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '' || !ctype_digit($parts[1])) {
            return null;
        }
        [$address, $prefixRaw] = $parts;
        $prefix = (int)$prefixRaw;
    }

    $packed = @inet_pton($address);
    if ($packed === false) {
        return null;
    }
    $maxBits = strlen($packed) * 8; // 32 for IPv4, 128 for IPv6.
    if ($prefix === null) {
        $canonical = @inet_ntop($packed);
        return $canonical === false ? null : strtolower($canonical);
    }
    if ($prefix < 0 || $prefix > $maxBits) {
        return null;
    }

    $network = allowed_ip_mask_network($packed, $prefix);
    if ($network === '') {
        return null;
    }
    $canonical = @inet_ntop($network);
    return $canonical === false ? null : strtolower($canonical) . '/' . $prefix;
}

/** Pure IPv4/IPv6 matcher; equivalent textual IPv6 spellings compare by packed bytes, not strings. */
function allowed_ip_matches(string $sourceIp, string $ipOrCidr): bool {
    $sourcePacked = @inet_pton(trim($sourceIp));
    if ($sourcePacked === false) {
        return false;
    }

    $rule = trim(strtolower($ipOrCidr));
    if (!str_contains($rule, '/')) {
        $rulePacked = @inet_pton($rule);
        return $rulePacked !== false
            && strlen($rulePacked) === strlen($sourcePacked)
            && hash_equals($rulePacked, $sourcePacked);
    }

    $parts = explode('/', $rule);
    if (count($parts) !== 2 || !ctype_digit($parts[1])) {
        return false;
    }
    $networkPacked = @inet_pton($parts[0]);
    if ($networkPacked === false || strlen($networkPacked) !== strlen($sourcePacked)) {
        return false; // also rejects IPv4-vs-IPv6 family mismatch.
    }
    $prefix = (int)$parts[1];
    $maxBits = strlen($sourcePacked) * 8;
    if ($prefix < 0 || $prefix > $maxBits) {
        return false;
    }

    return hash_equals(
        allowed_ip_mask_network($networkPacked, $prefix),
        allowed_ip_mask_network($sourcePacked, $prefix)
    );
}

/** @return list<array<string,mixed>> newest first */
function allowed_ip_list(int $organizationId): array {
    if ($organizationId <= 0) {
        return [];
    }
    $st = db()->prepare('SELECT * FROM ellsms_organization_allowed_ips WHERE organization_id = ? ORDER BY id DESC');
    $st->execute([$organizationId]);
    return $st->fetchAll();
}

/** Reads the opt-in flag directly (not through setting()'s request-lifetime cache). */
function allowed_ip_enforcement_enabled(int $organizationId): bool {
    if ($organizationId <= 0) {
        return false;
    }
    $st = db()->prepare('SELECT svalue FROM ellsms_settings WHERE skey = ? LIMIT 1');
    $st->execute([allowed_ip_enforcement_setting_key($organizationId)]);
    $row = $st->fetch();
    return $row !== false && (string)$row['svalue'] === '1';
}

/** Number of currently-active entries for one organization. */
function allowed_ip_active_count(int $organizationId): int {
    if ($organizationId <= 0) {
        return 0;
    }
    $st = db()->prepare("SELECT COUNT(*) c FROM ellsms_organization_allowed_ips WHERE organization_id = ? AND status = 'active'");
    $st->execute([$organizationId]);
    return (int)($st->fetch()['c'] ?? 0);
}

/**
 * Explicit opt-in/out. Enabling is refused with an empty active list so a typo/click cannot turn the
 * API into a deny-all endpoint. Disabling is always available from the web UI and never restricts
 * panel login, providing a recovery path even after an API-side mistake.
 */
function allowed_ip_set_enforcement(int $organizationId, bool $enabled, int $actorUserId): array {
    if ($organizationId <= 0) {
        return ['ok' => false, 'reason' => 'invalid_organization'];
    }

    $result = db_transaction(function (PDO $db) use ($organizationId, $enabled): array {
        // Serialize policy changes with entry mutations. The indexed organization range is locked so
        // two concurrent "disable last entry" / "enable policy" operations cannot cross each other.
        $st = $db->prepare('SELECT id, status FROM ellsms_organization_allowed_ips WHERE organization_id = ? FOR UPDATE');
        $st->execute([$organizationId]);
        $rows = $st->fetchAll();
        $activeCount = count(array_filter($rows, static fn(array $row): bool => $row['status'] === 'active'));
        if ($enabled && $activeCount === 0) {
            return ['ok' => false, 'reason' => 'empty_allowlist'];
        }

        $key = allowed_ip_enforcement_setting_key($organizationId);
        $db->prepare(
            'INSERT INTO ellsms_settings (skey, svalue) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)'
        )->execute([$key, $enabled ? '1' : '0']);
        return ['ok' => true, 'enabled' => $enabled];
    });

    if ($result['ok']) {
        $action = $enabled ? 'allowed_ip.enforcement_enabled' : 'allowed_ip.enforcement_disabled';
        audit($actorUserId, $action, "org={$organizationId}");
        Logger::info($action, ['organization_id' => $organizationId, 'actor_user_id' => $actorUserId]);
    }
    return $result;
}

function allowed_ip_create(int $organizationId, string $ipOrCidr, string $label, int $actorUserId): array {
    if ($organizationId <= 0) {
        return ['ok' => false, 'reason' => 'invalid_organization'];
    }
    $normalized = allowed_ip_normalize($ipOrCidr);
    if ($normalized === null) {
        return ['ok' => false, 'reason' => 'invalid_ip'];
    }
    $label = profile_clean_text($label, 120);

    try {
        db()->prepare(
            'INSERT INTO ellsms_organization_allowed_ips (organization_id, ip_or_cidr, label, created_by_user_id)
             VALUES (?,?,?,?)'
        )->execute([$organizationId, $normalized, $label, $actorUserId]);
    } catch (PDOException $e) {
        // Only an integrity violation is a user-facing duplicate. Infrastructure errors must not be
        // disguised as "already exists" because that makes a real outage much harder to diagnose.
        if ((string)$e->getCode() === '23000' || (int)($e->errorInfo[1] ?? 0) === 1062) {
            return ['ok' => false, 'reason' => 'duplicate'];
        }
        throw $e;
    }

    $id = (int)db()->lastInsertId();
    audit($actorUserId, 'allowed_ip.created', "org={$organizationId} id={$id} ip={$normalized}");
    Logger::info('allowed_ip.created', ['organization_id' => $organizationId, 'id' => $id, 'actor_user_id' => $actorUserId]);
    return ['ok' => true, 'id' => $id, 'ip_or_cidr' => $normalized];
}

/** Internal helper: lock every entry in this org and return them, serializing safety-sensitive edits. */
function allowed_ip_locked_rows(PDO $db, int $organizationId): array {
    $st = $db->prepare('SELECT id, status FROM ellsms_organization_allowed_ips WHERE organization_id = ? FOR UPDATE');
    $st->execute([$organizationId]);
    return $st->fetchAll();
}

function allowed_ip_delete(int $organizationId, int $id, int $actorUserId): array {
    if ($organizationId <= 0 || $id <= 0) {
        return ['ok' => false, 'reason' => 'not_found'];
    }

    $result = db_transaction(function (PDO $db) use ($organizationId, $id): array {
        $rows = allowed_ip_locked_rows($db, $organizationId);
        $target = null;
        foreach ($rows as $row) {
            if ((int)$row['id'] === $id) {
                $target = $row;
                break;
            }
        }
        if ($target === null) {
            return ['ok' => false, 'reason' => 'not_found'];
        }

        if ($target['status'] === 'active' && allowed_ip_enforcement_enabled($organizationId)) {
            $activeCount = count(array_filter($rows, static fn(array $row): bool => $row['status'] === 'active'));
            if ($activeCount <= 1) {
                return ['ok' => false, 'reason' => 'last_active_required'];
            }
        }

        $db->prepare('DELETE FROM ellsms_organization_allowed_ips WHERE id = ? AND organization_id = ?')
            ->execute([$id, $organizationId]);
        return ['ok' => true];
    });

    if ($result['ok']) {
        audit($actorUserId, 'allowed_ip.deleted', "org={$organizationId} id={$id}");
        Logger::info('allowed_ip.deleted', ['organization_id' => $organizationId, 'id' => $id, 'actor_user_id' => $actorUserId]);
    }
    return $result;
}

function allowed_ip_toggle(int $organizationId, int $id, int $actorUserId): array {
    if ($organizationId <= 0 || $id <= 0) {
        return ['ok' => false, 'reason' => 'not_found'];
    }

    $result = db_transaction(function (PDO $db) use ($organizationId, $id): array {
        $rows = allowed_ip_locked_rows($db, $organizationId);
        $target = null;
        foreach ($rows as $row) {
            if ((int)$row['id'] === $id) {
                $target = $row;
                break;
            }
        }
        if ($target === null) {
            return ['ok' => false, 'reason' => 'not_found'];
        }

        $newStatus = $target['status'] === 'active' ? 'disabled' : 'active';
        if ($newStatus === 'disabled' && allowed_ip_enforcement_enabled($organizationId)) {
            $activeCount = count(array_filter($rows, static fn(array $row): bool => $row['status'] === 'active'));
            if ($activeCount <= 1) {
                return ['ok' => false, 'reason' => 'last_active_required'];
            }
        }

        $db->prepare('UPDATE ellsms_organization_allowed_ips SET status = ? WHERE id = ? AND organization_id = ?')
            ->execute([$newStatus, $id, $organizationId]);
        return ['ok' => true, 'status' => $newStatus];
    });

    if ($result['ok']) {
        audit($actorUserId, 'allowed_ip.updated', "org={$organizationId} id={$id} status={$result['status']}");
        Logger::info('allowed_ip.updated', [
            'organization_id' => $organizationId,
            'id' => $id,
            'status' => $result['status'],
            'actor_user_id' => $actorUserId,
        ]);
    }
    return $result;
}

/**
 * One-query hot-path decision for /api/v1/*. Missing policy row means disabled/open (backward
 * compatible). Once explicitly enabled, failure to read/match the configured list fails CLOSED.
 *
 * @return array{allowed:bool,enabled:bool,reason:string,source_ip:string}
 */
function allowed_ip_access_decision(int $organizationId, ?string $sourceIp = null): array {
    $sourceIp = trim($sourceIp ?? client_ip());
    if ($organizationId <= 0) {
        return ['allowed' => false, 'enabled' => true, 'reason' => 'invalid_organization', 'source_ip' => $sourceIp];
    }

    try {
        $key = allowed_ip_enforcement_setting_key($organizationId);
        $st = db()->prepare(
            "SELECT s.svalue AS enabled, a.ip_or_cidr
               FROM ellsms_settings s
               LEFT JOIN ellsms_organization_allowed_ips a
                 ON a.organization_id = ? AND a.status = 'active'
              WHERE s.skey = ?"
        );
        $st->execute([$organizationId, $key]);
        $rows = $st->fetchAll();

        // No explicit setting row, or explicitly set to 0: preserve pre-issue-24 behavior exactly.
        if ($rows === [] || (string)$rows[0]['enabled'] !== '1') {
            return ['allowed' => true, 'enabled' => false, 'reason' => 'disabled', 'source_ip' => $sourceIp];
        }

        if (@inet_pton($sourceIp) === false) {
            return ['allowed' => false, 'enabled' => true, 'reason' => 'invalid_source_ip', 'source_ip' => $sourceIp];
        }

        $hasActive = false;
        foreach ($rows as $row) {
            $rule = $row['ip_or_cidr'] ?? null;
            if (!is_string($rule) || $rule === '') {
                continue;
            }
            $hasActive = true;
            if (allowed_ip_matches($sourceIp, $rule)) {
                return ['allowed' => true, 'enabled' => true, 'reason' => 'matched', 'source_ip' => $sourceIp];
            }
        }

        return [
            'allowed' => false,
            'enabled' => true,
            'reason' => $hasActive ? 'not_allowed' : 'empty_allowlist',
            'source_ip' => $sourceIp,
        ];
    } catch (Throwable $e) {
        Logger::critical('api.ip_allowlist_check_failed', [
            'organization_id' => $organizationId,
            'exception' => $e,
        ]);
        // The caller is already authenticated at this point. An explicitly configured security
        // control that cannot be evaluated must not silently become "allow all".
        return ['allowed' => false, 'enabled' => true, 'reason' => 'check_failed', 'source_ip' => $sourceIp];
    }
}

/** Log + append an audit event for a denied authenticated API request, never the bearer secret. */
function allowed_ip_record_api_denial(array $principal, array $decision): void {
    $reason = (string)($decision['reason'] ?? 'not_allowed');
    $boundedReasons = ['not_allowed', 'invalid_source_ip', 'empty_allowlist', 'check_failed', 'invalid_organization'];
    if (!in_array($reason, $boundedReasons, true)) {
        $reason = 'not_allowed';
    }
    $sourceHash = hash('sha256', (string)($decision['source_ip'] ?? 'unknown'));
    $organizationId = (int)($principal['organization_id'] ?? 0);
    $apiKeyId = (int)($principal['api_key_id'] ?? 0);
    $actorUserId = (int)($principal['created_by_user_id'] ?? 0);

    Logger::warning('api.ip_allowlist_denied', [
        'organization_id' => $organizationId,
        'api_key_id' => $apiKeyId,
        'key_prefix' => (string)($principal['key_prefix'] ?? ''), // prefix is intentionally non-secret.
        'reason' => $reason,
        'source_ip_hash' => $sourceHash,
    ]);
    Metrics::increment('api.ip_allowlist.denied', 1, ['reason' => $reason]);

    try {
        audit(
            $actorUserId,
            'api.ip_allowlist_denied',
            "org={$organizationId} api_key_id={$apiKeyId} reason={$reason} source_ip_hash={$sourceHash}"
        );
    } catch (Throwable $e) {
        // Logging/audit observability must not turn a deliberate 403 into a 500.
        Logger::error('api.ip_allowlist_denial_audit_failed', [
            'organization_id' => $organizationId,
            'api_key_id' => $apiKeyId,
            'exception' => $e,
        ]);
    }
}

function allowed_ip_error_message(string $reason): string {
    return [
        'invalid_organization'  => 'سازمان فعالی برای این عملیات وجود ندارد.',
        'invalid_ip'            => 'آدرس IP یا محدوده‌ی CIDR معتبر نیست.',
        'duplicate'             => 'این آدرس قبلاً ثبت شده است.',
        'not_found'             => 'مورد یافت نشد.',
        'empty_allowlist'       => 'برای فعال‌سازی محدودیت، حداقل یک IP یا CIDR فعال ثبت کنید.',
        'last_active_required'  => 'تا وقتی محدودیت IP فعال است، حداقل یک آدرس فعال باید باقی بماند. ابتدا محدودیت را غیرفعال کنید.',
    ][$reason] ?? 'ذخیره‌سازی ممکن نشد.';
}
