<?php
/**
 * ELLSMS — organization-level allowed-IP management (§20 of the KYC phase brief, docs/profile-kyc.md).
 *
 * MANAGEMENT ONLY. This file lets an organization record which IPs/CIDR ranges it expects its own
 * traffic to come from, and audits every change. It deliberately does NOT enforce anything against
 * login or API requests — the phase brief is explicit that global enforcement is added only "if the
 * project already has a clear safe hook," and ELLSMS's login path (app/authorization.php,
 * app/rate_limit.php) has no such hook today. Wiring enforcement in blind would risk locking
 * administrators out mid-migration, which the brief explicitly forbids. See docs/profile-kyc.md
 * §Allowed IP management for the exact, honest statement of what is and is not enforced.
 */

declare(strict_types=1);

/** Raised for any allowed-IP validation failure; ->getMessage() is safe to show (AppException semantics). */
class AllowedIpException extends AppException {}

/**
 * Validates a plain IPv4/IPv6 address or an address with a /prefix (CIDR). Returns the normalized
 * string (lowercase, no surrounding whitespace) or null if the input is not a valid address/CIDR.
 */
function allowed_ip_normalize(string $raw): ?string {
    $raw = strtolower(trim(from_persian_digits($raw)));
    if ($raw === '') {
        return null;
    }
    if (!str_contains($raw, '/')) {
        return filter_var($raw, FILTER_VALIDATE_IP) !== false ? $raw : null;
    }

    [$address, $prefix] = array_pad(explode('/', $raw, 2), 2, '');
    if (!ctype_digit($prefix)) {
        return null;
    }
    $prefix = (int)$prefix;
    if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
        return $prefix >= 0 && $prefix <= 32 ? "{$address}/{$prefix}" : null;
    }
    if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
        return $prefix >= 0 && $prefix <= 128 ? "{$address}/{$prefix}" : null;
    }
    return null;
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
        // uniq_org_ip — the same address already exists for this organization.
        return ['ok' => false, 'reason' => 'duplicate'];
    }

    $id = (int)db()->lastInsertId();
    audit($actorUserId, 'allowed_ip.created', "org={$organizationId} id={$id} ip={$normalized}");
    Logger::info('allowed_ip.created', ['organization_id' => $organizationId, 'id' => $id]);
    return ['ok' => true, 'id' => $id];
}

function allowed_ip_delete(int $organizationId, int $id, int $actorUserId): array {
    if ($organizationId <= 0 || $id <= 0) {
        return ['ok' => false, 'reason' => 'not_found'];
    }
    $st = db()->prepare('SELECT id FROM ellsms_organization_allowed_ips WHERE id = ? AND organization_id = ?');
    $st->execute([$id, $organizationId]);
    if (!$st->fetch()) {
        return ['ok' => false, 'reason' => 'not_found'];
    }
    db()->prepare('DELETE FROM ellsms_organization_allowed_ips WHERE id = ? AND organization_id = ?')
        ->execute([$id, $organizationId]);

    audit($actorUserId, 'allowed_ip.deleted', "org={$organizationId} id={$id}");
    Logger::info('allowed_ip.deleted', ['organization_id' => $organizationId, 'id' => $id]);
    return ['ok' => true];
}

function allowed_ip_toggle(int $organizationId, int $id, int $actorUserId): array {
    if ($organizationId <= 0 || $id <= 0) {
        return ['ok' => false, 'reason' => 'not_found'];
    }
    $st = db()->prepare('SELECT status FROM ellsms_organization_allowed_ips WHERE id = ? AND organization_id = ?');
    $st->execute([$id, $organizationId]);
    $row = $st->fetch();
    if (!$row) {
        return ['ok' => false, 'reason' => 'not_found'];
    }
    $newStatus = $row['status'] === 'active' ? 'disabled' : 'active';
    db()->prepare('UPDATE ellsms_organization_allowed_ips SET status = ? WHERE id = ? AND organization_id = ?')
        ->execute([$newStatus, $id, $organizationId]);
    audit($actorUserId, 'allowed_ip.updated', "org={$organizationId} id={$id} status={$newStatus}");
    return ['ok' => true, 'status' => $newStatus];
}

function allowed_ip_error_message(string $reason): string {
    return [
        'invalid_organization' => 'سازمان فعالی برای این عملیات وجود ندارد.',
        'invalid_ip'            => 'آدرس IP یا محدوده‌ی CIDR معتبر نیست.',
        'duplicate'             => 'این آدرس قبلاً ثبت شده است.',
        'not_found'             => 'مورد یافت نشد.',
    ][$reason] ?? 'ذخیره‌سازی ممکن نشد.';
}
