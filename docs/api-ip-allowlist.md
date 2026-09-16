# Public API IP/CIDR allowlist (issue #24)

## Scope

The allowlist protects only authenticated public API requests under `/api/v1/*`. It does **not**
restrict web-panel login or normal panel pages. Keeping the panel reachable is intentional: an
owner/admin can recover from an incorrect API allowlist without database/operator intervention.

This document supersedes the older enforcement-status note in `docs/profile-kyc.md`, where allowed
IPs were management-only. The existing `ellsms_organization_allowed_ips` table and profile tooling
remain the source of the entries; issue #24 adds explicit API enforcement and exposes the same
controls next to API keys.

## Backward-compatible opt-in

An allowlist row being `active` does **not** enable enforcement by itself. Some installations may
already have active rows created by the earlier management-only KYC/profile feature, so treating
those rows as an automatic policy would be a breaking deployment change.

Enforcement is controlled per organization by this `ellsms_settings` key:

```
api_ip_allowlist_enabled.<organization_id> = 1 | 0
```

A missing key is the default and means **disabled/open**, preserving the pre-issue-24 API behavior.
Enabling is refused unless the organization already has at least one active IP/CIDR entry.

## Request path

The public API front controller evaluates security in this order:

1. route match and API-key authentication;
2. existing API rate limit;
3. organization IP/CIDR allowlist when explicitly enabled;
4. API-key scope and plan/entitlement checks;
5. request parsing and handler execution.

The tenant can only be selected after successful authentication, so unauthenticated clients cannot
choose an organization whose policy they want evaluated. Keeping rate limiting before the allowlist
also bounds repeated denied attempts.

A denied authenticated request receives HTTP `403` with the normal generic API error envelope. The
response does not echo the bearer token, allowlist contents, or the source IP.

## Source IP and proxies

Enforcement calls the existing `client_ip()` helper. Its trust model is unchanged:

- without a trusted reverse proxy, `REMOTE_ADDR` is authoritative and `X-Forwarded-For` is ignored;
- `X-Forwarded-For` is read only if the direct peer is inside `TRUSTED_PROXY_IPS`;
- for a trusted proxy, the rightmost forwarded address is used, matching the project's existing
  anti-spoofing rule;
- a malformed resolved source is denied whenever enforcement is enabled.

Do not add public load balancer/proxy addresses to `TRUSTED_PROXY_IPS` unless that peer is actually
under deployment control and is configured to append/replace forwarding metadata safely.

## Entry behavior

`app/AllowedIps.php` canonicalizes new values before storing them:

- IPv4 and IPv6 exact addresses are supported;
- IPv4/IPv6 CIDRs are supported;
- CIDR host bits are cleared (`192.0.2.44/24` becomes `192.0.2.0/24`);
- equivalent IPv6 textual forms are compared using packed address bytes;
- malformed addresses/prefixes are rejected before a write.

The existing `(organization_id, ip_or_cidr)` unique constraint prevents duplicate stored canonical
entries. While enforcement is enabled, the final active entry cannot be disabled or deleted.
Disable the policy first if the intended result is an empty list. This avoids an accidental deny-all
state from an ordinary UI click.

## Management and authorization

The API Keys page (`/api-keys.php`) now provides:

- current policy state and detected source IP;
- enable/disable control;
- add, enable/disable, and delete controls for multiple IP/CIDR entries.

The same `Permissions::API_KEYS_MANAGE` RBAC gate used for API-key mutation protects every policy
mutation. CSRF and support-impersonation write guards apply as they do to API-key changes. The
older profile-page entry management remains compatible with the same underlying functions and now
inherits the last-active-entry safety rule.

## Observability and audit

Every denied authenticated request emits:

- `api.ip_allowlist_denied` warning log with organization id, API key id, non-secret key prefix,
  bounded reason, and SHA-256 source-IP hash;
- `metric.api.ip_allowlist.denied` through the standard Metrics abstraction;
- `api.ip_allowlist_denied` audit event containing organization id, API key id, bounded reason and
  the hashed source IP.

The bearer token/API secret is never logged or stored in the audit event. A failure to append the
audit row is logged separately but does not turn the intended 403 into a 500.

Policy enable/disable and entry create/update/delete operations are also audited.

## Verification

Automated coverage includes:

- disabled-by-default compatibility even when old active rows already exist;
- exact IPv4, IPv4 CIDR, exact IPv6 and IPv6 CIDR matching;
- malformed CIDR rejection and canonical duplicate handling;
- tenant isolation;
- last-active-entry safety while enforcement is enabled;
- trusted-vs-untrusted proxy forwarding behavior;
- denial audit data with a hashed, not plaintext, source IP.

Integration tests require the project's disposable MySQL test environment (`ELLSMS_TEST_DB_*`), as
with the rest of `tests/Integration`.
