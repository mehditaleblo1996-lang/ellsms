# ELLSMS — Phase 7 Final Report: RBAC, Permission Model & Fine-Grained Authorization

**Date:** 2026-07-31
**Scope:** Replace coarse organization-role-only checks with explicit, tenant-aware, fine-grained
permissions on top of Phase 6's organization/membership model. No SaaS plans, no API keys/webhooks,
no Redis, no shared-DB rewrite, no Campaign Engine v2, no UI redesign. Full architectural detail:
`docs/rbac-architecture.md`; this report covers outcomes, real test evidence, and an honest
accounting of what changed vs. what stayed deliberately untouched.

---

## 1. Executive Summary

ELLSMS now has a real permission layer (`app/rbac.php` + `app/Support/Permissions.php`) sitting
directly on Phase 6's `ellsms_organization_memberships.role` (`owner`/`admin`/`member` — unchanged,
no schema migration to the role model itself). Every organization-scoped mutating action across
membership management, contacts, campaigns, messaging, schedules, auto-reply, and organization
settings now goes through an explicit `require_permission()`/`membership_has_permission()` check
instead of a bare role comparison, and every one of those checks fails closed.

The single most consequential correctness fix this phase produced was not a permission check at all:
while writing the payment-organization-switch test in the prior phase's closure work, this phase
discovered `current_user()` (`app/bootstrap.php`) used a plain "resolve once, cache forever" static
cache that `Logger::info()` silently poisoned to `null` on the first log call in any PHP process that
happened before a session existed — a real cross-test-process staleness bug. Fixed by keying the
cache on `$_SESSION['uid']` itself, mirroring the pattern `current_organization()` already used.
`app/rbac.php` itself deliberately caches nothing at all, closing the same class of bug at the root
for permission checks specifically (section 7 below).

**Honest scope accounting:** five catalog permissions (`sender.manage`, `wallet.adjust`, `kyc.view`,
`kyc.manage`, `audit.view`) are defined, tested for correct role-matrix membership, and ready — but
gate no real feature yet, because the underlying actions (assigning sender numbers, manually
adjusting a wallet, viewing/editing KYC documents, browsing the audit log) are deliberately still
platform-admin-only or nonexistent as a UI, and building new organization-scoped features for them
was explicitly out of this phase's scope. Documented in `docs/rbac-architecture.md` section 3 and
tracked as TD-037, not silently omitted.

## 2. RBAC Invariants

See `docs/rbac-architecture.md` section 2 for the full table (Invariants A–J, each with where it's
enforced). All ten hold, verified by `tests/Integration/RbacTest.php` (13 tests) and
`tests/Integration/RbacConcurrencyTest.php` (1 real-MySQL concurrency test).

## 3. Permission Catalog

`app/Support/Permissions.php` — 21 constants across members/sender/contacts/campaigns/messages/
schedules/autoreply/wallet/payments/reports/settings/kyc/audit. `Permissions::all()` is the single
source of truth every integrity/status tool iterates over — never hand-duplicated. Full catalog with
each reserved permission's exact justification: `docs/rbac-architecture.md` section 3.

## 4. Role Matrix

Owner = every permission except the two KYC ones (Invariant E). Admin = everything owner has except
`wallet.adjust` (Invariant F) — the `owner` role tier itself (granting/revoking it) is additionally
blocked for admin by a separate escalation guard, not by a missing permission string. Member = least
privilege for anything administrative/financial, but retains every capability that was universally
available to any logged-in organization member before this phase (contacts, campaigns, messaging,
schedules, auto-reply, viewing reports/wallet/payments) — not a silent downgrade. Full table:
`docs/rbac-architecture.md` section 4, generated directly from `php cron/rbac-status.php`.

## 5. Authorization API

`app/rbac.php`: `membership_has_permission()` (pure), `has_permission()` (DB-backed, worker-safe),
`require_permission()`/`require_any_permission()`/`require_all_permissions()` (web-context, fail
closed via Phase 6's own `require_organization()`), `can_assign_role()` (pure escalation-tier
decision), `organization_change_member_role()`/`organization_remove_member()` (the only two places
role/status are mutated, transaction-safe). Details: `docs/rbac-architecture.md` section 5.

## 6. Organization Scope

Every decision is an explicit `(user, organization)` pair — proven directly by
`RbacTest::testCrossTenantPermissionIsolationForAMultiOrganizationUser` (a user who is `admin` in Org
A and `member` in Org B gets `members.manage` in A and is denied it in B, same user, same call in the
same test) and `testCraftedOrganizationIdNeverGrantsPermission` (a non-member, and a wholly
nonexistent organization id, both resolve to zero permissions).

## 7. Platform Admin Separation

`app/rbac.php` never reads the platform `is_admin` flag (`$user['role']`, Phase 2). Every page already
gated by `require_admin()` (`public/users.php`, `public/settings.php`, `public/numbers.php`,
`public/analytics.php`) keeps that exact pre-existing bypass, untouched. Pages this phase newly
permission-gated (`contacts.php`, `autoreply.php`, `schedules.php`, `reports.php`, `send.php`,
`new-send.php`, `p2p-send.php`, `smart-send.php`, `buy-credit.php`) all preserve their existing
`is_admin()` bypass FIRST, applying the new organization-permission check only to non-platform-admin
callers — an organization owner never becomes a platform admin, and a platform admin's existing
unrestricted access is never narrowed by this phase. Verified by inspection of every touched file
(no test framework in this codebase does HTTP-level request simulation — see section 19 for why
function-level integration tests are this project's established proof style, same as Phases 2–6).

## 8. Membership Management

`public/organizations.php` rewritten to route every add/change-role/remove action through
`organization_change_member_role()`/`organization_remove_member()` instead of the bare
`is_organization_manager()` role check Phase 6 shipped. The 'owner' role option only renders in the UI
when the acting user is already an owner (Invariant C: convenience, not the real gate — the server
independently re-validates via `can_assign_role()` regardless of what's posted). A new "rename
organization" action, gated by `SETTINGS_MANAGE`, gives that permission a genuine target without
inventing a new page (Invariant C, STEP 20). Tests: `testMemberCannotChangeAnyMembersRole`,
`testAdminCannotGrantOwnerRole`, `testAdminCannotDemoteOrRemoveTheOwner`,
`testActorFromAnotherOrganizationCannotActAcrossTenants`.

## 9. Owner Protection

Transaction-safe via `SELECT ... FOR UPDATE` locking every active membership row of an organization
before any role-change/removal decision (STEP 8) — not a plain read-then-update. Proven under real
concurrency, not just sequentially: `RbacConcurrencyTest::testConcurrentLastOwnerDemotionsCannotBothSucceed`
spawns two genuinely separate OS processes (same `proc_open()` pattern as Phase 3's
`WalletConcurrencyTest`) racing to demote the two owners of a two-owner organization simultaneously —
exactly one succeeds, the other is rejected specifically with `last_owner`, and the organization ends
with exactly one owner, never zero. Sequential last-owner protection additionally proven by
`testLastOwnerCanNeverBeDemotedOrRemoved`. Owner transfer (promote-then-demote, STEP 30) proven by
`testOwnerTransferPromoteThenDemoteFlow` — no intermediate zero-owner state at any point.

## 10. Privilege Escalation Defenses

`can_assign_role()` (Invariant H): only an `owner` may touch the `owner` tier (grant or revoke it); an
`admin` may move a target within/below the `admin` tier only. Additionally, both membership-mutating
functions self-check the actor's own membership genuinely belongs to the target organization
(defense in depth beyond today's one call site's own discipline) — proven by
`testActorFromAnotherOrganizationCannotActAcrossTenants`, which deliberately passes a real membership
from Org A against Org B's organization id and confirms it's rejected as `forbidden`.

## 11. Messaging Permissions

`messages.send` gates `send.php`/`new-send.php`/`p2p-send.php`/`smart-send.php`'s dispatch action
(all four, consistently). `campaigns.manage` (distinct from `messages.send`, STEP 14) additionally
gates saving a new campaign template in `new-send.php` — a role that may dispatch is not automatically
allowed to persist a reusable template; if the permission is missing, only the save is skipped, not
the send, since sending is the user's primary intent. `schedules.manage` additionally gates the
recurring-send branch in both `send.php` and `new-send.php`. Platform admins keep their existing
unrestricted bypass on every one of these pages (section 7). Background dispatch
(`dispatch_message_raw()`/`run_due_schedules()`/etc.) is unchanged — see section 15.

## 12. Wallet Permissions

`wallet.view` gates `buy-credit.php` (a user's own balance — wallet itself remains strictly
`user_id`-keyed, Phase 3, unchanged by this phase or Phase 6). `wallet.adjust` is deliberately
**reserved and unwired** — manual credit adjustment (`app/wallet.php`'s `wallet_manual_adjustment()`)
remains callable only from `public/users.php`, platform-admin-only, stricter than any organization
role including owner (STEP 17's "high privilege" requirement satisfied by the strictest possible
existing gate, not a new one). Purchasing credit (spending your own money to buy your own credit) is
NOT gated behind `wallet.adjust` — that would conflate two unrelated actions; it remains available to
any logged-in user exactly as before, matching this codebase's existing behavior.

## 13. Contact/Campaign/Settings Permissions

`contacts.view`/`contacts.manage` gate `public/contacts.php` (view vs. add/import/delete, STEP 13).
`campaigns.view`/`campaigns.manage`/`campaigns.send` gate `public/new-send.php`'s campaign-template
list/save/dispatch (STEP 14). `settings.manage` gates the new organization-rename action in
`public/organizations.php` (section 8) — global platform settings (`public/settings.php`) remain
completely separate and platform-admin-only, per STEP 20's explicit "do not confuse the two."

## 14. KYC/Ticket Policy

Both deliberately untouched by RBAC, per STEP 21/22. KYC: `kyc.view`/`kyc.manage` are reserved,
granted to **no** organization role (not even owner) — the actual product rule (platform admin, or
the document's own subject, only) stays exactly as Phase 2 built it. Tickets: still strictly
user-private (Phase 6's own explicit policy) — `public/tickets.php` now carries an explicit comment
reaffirming no `Permissions::*` constant governs anything in that file, so a future edit doesn't
accidentally "fix" this into a privacy regression.

## 15. Background Job Policy

Permission checks happen only at job-**creation** time (the web handler), never re-checked against
the original creator's live role at dispatch time. `run_due_schedules()`/`bulk_send_one_item()`/
`autoreply_process_one()` (`app/backend.php`) are **unmodified** by this phase — they already,
correctly, revalidate the job's persisted `organization_id` against `organization_status()` (Phase 6),
which is what continues to govern whether a queued job still executes, not the creator's
possibly-since-changed personal role. A demoted member's already-queued organization-owned job is not
silently cancelled. Full reasoning: `docs/rbac-architecture.md` section 12.

## 16. Database Migration

**One migration**, `db/migrations/2026_07_31_rbac_owner_protection_index.sql`: adds
`idx_org_role_status (organization_id, role, status)` to `ellsms_organization_memberships`, purely to
keep the owner-protection `FOR UPDATE` locking query (section 9) an efficient index scan under
concurrent load — an operational improvement, not a correctness dependency (the locking logic is
correct with or without the index). No role/permission schema exists — Option A (section 1 of
`docs/rbac-architecture.md`) means permissions are compile-time PHP constants, not database rows.
Guarded via `information_schema` existence check, applied cleanly alongside all 9 prior migrations in
order (verified — section 19).

## 17. RBAC Integrity Tool

`cron/rbac-integrity-check.php` (`make rbac-integrity-check`): zero-active-owner organizations
(correct `LEFT JOIN`/`NOT EXISTS` form — see section 26 for the related Phase 6 bug this discovered),
invalid membership role values, and a code-level check that `role_permissions()` never grants a
permission string absent from `Permissions::all()`. Read-only, exits non-zero only on real findings,
never auto-fixes. `cron/rbac-status.php` (`make rbac-status`) prints the live role matrix with **zero
database connection** — pure reflection of `role_permissions()`, so `docs/rbac-architecture.md`'s
table can never silently drift from what the code enforces without a human noticing the diff.

## 18. Security Test Results

- **Owner permissions:** full catalog except KYC — `testOwnerHasFullOrganizationLevelPermissions` PASS.
- **Admin permissions:** broad, excludes `wallet.adjust` and KYC — `testAdminHasBroadButNotIrreversibleOwnerLevelAuthority` PASS.
- **Member permissions:** least privilege for admin/financial, retains pre-existing operational access — `testMemberHasLeastPrivilegeButRetainsPreExistingCapabilities` PASS.
- **Cross-tenant isolation:** `testCrossTenantPermissionIsolationForAMultiOrganizationUser`, `testCraftedOrganizationIdNeverGrantsPermission` PASS.
- **Privilege escalation:** `testMemberCannotChangeAnyMembersRole`, `testAdminCannotGrantOwnerRole`, `testAdminCannotDemoteOrRemoveTheOwner`, `testActorFromAnotherOrganizationCannotActAcrossTenants` PASS — all four scenarios denied server-side, zero mutation.
- **Last-owner protection (sequential):** `testLastOwnerCanNeverBeDemotedOrRemoved` PASS.
- **Last-owner protection (real concurrency, two OS processes):** `RbacConcurrencyTest::testConcurrentLastOwnerDemotionsCannotBothSucceed` PASS — exactly one of two simultaneous demotions succeeds, final owner count = 1.
- **Owner transfer:** `testOwnerTransferPromoteThenDemoteFlow` PASS.
- **Role-change immediacy:** `testRoleChangeTakesEffectImmediatelyWithNoStalePermissionCache` PASS — no propagation delay, no stale cache.
- **Revoked membership:** `testRevokedMembershipLosesEveryPermissionImmediately` PASS.

## 19. Full Test Results (exact numbers, executed 2026-07-31)

- **PHP lint:** **101/101 files parse cleanly** (was 94 before this phase — `app/rbac.php`,
  `app/Support/Permissions.php`, `cron/rbac-integrity-check.php`, `cron/rbac-status.php`,
  `tests/Integration/RbacTest.php`, `tests/Integration/RbacConcurrencyTest.php`,
  `tests/fixtures/rbac_concurrent_demote_worker.php` — 7 new files).
- **Unit suite:** **97 tests, 167 assertions, OK** — unchanged (this phase's new behavior is
  inherently database-dependent, no new pure-function unit coverage needed beyond what
  `RbacTest`'s function-level integration tests already exercise directly).
- **Integration suite:** **126 tests, 449 assertions, OK, 0 failures, 0 errors, 0 skipped** (112
  pre-existing Phase 2–6 + 13 new `RbacTest` + 1 new `RbacConcurrencyTest`). Rerun twice consecutively
  from a fully fresh, dropped-and-recreated database — stable both times (same counts both runs).

## 20. Phase 6 Regression

Green — `TenantIsolationTest` (22 tests, 88 assertions) reran unchanged within the 126 total.
`cron/tenant-integrity-check.php` reran separately: zero violations, exit code 0. No file this phase
touched altered any tenant-scoping SQL query — every edit added a permission check ADJACENT to
existing organization-scoping logic, never replaced it.

## 21. Phase 3 Regression

Green — `WalletIntegrationTest`, `WalletConcurrencyTest`, `PaymentIntegrationTest` all pass unchanged
within the 126 total. `app/wallet.php` and `app/zarinpal.php` were not modified.

## 22. Phase 4 Regression

Green — `BulkJobQueueTest`, `BulkItemConcurrencyTest`, `ScheduleQueueTest`, `AutoreplyQueueTest` all
pass unchanged. `run_due_schedules()`/`bulk_send_one_item()`/`autoreply_process_one()` were not
modified (section 15).

## 23. Phase 5 Regression

Green — `cron/db-migrate.php --status` shows all 10 migrations applied in correct order (the new
`2026_07_31_rbac_owner_protection_index.sql` last); `DatabaseIntegrityTest` and
`DatabaseOperationalScriptsTest` both pass unchanged.

## 24. Files Created

- `app/Support/Permissions.php`
- `app/rbac.php`
- `db/migrations/2026_07_31_rbac_owner_protection_index.sql`
- `cron/rbac-integrity-check.php`, `cron/rbac-status.php`
- `tests/Integration/RbacTest.php` (13 tests), `tests/Integration/RbacConcurrencyTest.php` (1 test)
- `tests/fixtures/rbac_concurrent_demote_worker.php`
- `docs/rbac-architecture.md`, `docs/phase-7-final-report.md` (this file)

## 25. Files Modified

- `app/bootstrap.php` — requires `rbac.php`; `current_user()` cache keyed on session uid (Executive Summary)
- `public/organizations.php` — membership management routed through `app/rbac.php`; owner-transfer-capable role select; new rename action
- `public/contacts.php`, `public/autoreply.php`, `public/schedules.php`, `public/reports.php` — explicit view/manage permission gates (admin bypass preserved)
- `public/send.php`, `public/new-send.php`, `public/p2p-send.php`, `public/smart-send.php` — `messages.send` gate on dispatch; `schedules.manage`/`campaigns.manage` gates on their respective sub-actions
- `public/buy-credit.php` — `wallet.view`/`payments.view` gates
- `public/tickets.php` — clarifying comment only, no logic change
- `Makefile` — `rbac-integrity-check`, `rbac-status` targets
- `docs/technical-debt.md` — TD-037 (reserved permissions), TD-038 (Phase 6's zero-owner check bug, found not fixed there), clarifying note on the pre-existing unrelated "Phase 7" roadmap section
- `docs/multi-tenancy-architecture.md` — role/RBAC cross-references updated; stale Phase-6-closure-superseded number-category note corrected

## 26. Breaking Changes

- **None to any existing role's capability set for the built-in owner/admin/member roles** — every
  permission this phase's default matrix grants to `member` was already universally accessible before
  this phase (section 4); nothing that worked yesterday stops working today for any of the three
  built-in roles.
- **Genuinely new, disclosed capability, not a regression:** an organization's `owner` can now rename
  the organization (new, minimal, `settings.manage`-gated action) and formally promote a second member
  to `owner` (previously the role dropdown only offered admin/member).
- **No breaking change to background job execution** — section 15.

## 27. Phase 8 Readiness

This phase did not implement, design in detail, or begin any Phase 8 work. The permission catalog and
role matrix are stable and centralized (`app/Support/Permissions.php`/`app/rbac.php`) for Phase 8 to
build on directly if it needs to (e.g., wiring a reserved permission to a real new feature would need
zero changes to the permission model itself, only a `require_permission()` call at the new call site).
One item worth Phase 8's attention, not a blocker: `cron/tenant-integrity-check.php`'s own
zero-active-owner check (Phase 6's file) has the structural bug documented as TD-038 — the correct
version lives in this phase's `cron/rbac-integrity-check.php` instead, so operational coverage is not
actually missing, but the original tool's own copy stays silently wrong until someone fixes it
directly.
