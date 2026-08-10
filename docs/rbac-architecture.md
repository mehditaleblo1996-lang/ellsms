# ELLSMS — RBAC / Fine-Grained Authorization Architecture (Phase 7)

**Date:** 2026-07-31
**Scope:** Replace coarse `role === 'admin'`-style organization checks with an explicit, tenant-aware
permission model on top of Phase 6's organization/membership foundation. Not a rewrite of Phase 6
(tenant isolation is unchanged and re-verified green), not full custom roles, not a new schema for
roles/permissions. Full outcomes and test evidence: `docs/phase-7-final-report.md`.

## 1. Design choice: fixed roles, code-mapped permissions (Option A)

Two designs were available: (A) keep the three fixed built-in roles
(`ellsms_organization_memberships.role` ENUM `owner`/`admin`/`member`, unchanged from Phase 6) with
the role → permission mapping living in PHP code, or (B) a database-backed
`ellsms_roles`/`ellsms_permissions`/`ellsms_role_permissions` schema supporting custom, per-organization
roles. This phase chose **A**: no current product requirement justifies B's extra schema/query
surface (custom roles per organization), and A is trivially simpler to review, test, and reason about
under concurrency. **No ENUM change was needed** — the three roles Phase 6 already had are exactly the
three this phase maps. See section 9 for the one real migration this phase did need (unrelated to the
role model itself).

## 2. Invariants (hard acceptance criteria)

| # | Invariant | Where enforced |
|---|---|---|
| A | Permissions are evaluated within an organization context | `has_permission(userId, organizationId, permission)` / `require_permission()` always take or resolve one specific organization |
| B | A permission in Org A grants nothing in Org B | `organization_membership()` re-resolved per (user, org) pair, never cached across organizations |
| C | UI visibility is NOT authorization | Every mutating `public/*.php` action calls `require_permission()`/`membership_has_permission()` server-side; UI hiding is a convenience layered on top, never the only gate |
| D | A numeric ID never bypasses permission checks | `has_permission()`/`organization_change_member_role()`/`organization_remove_member()` all re-validate real membership from the database, never trust a caller-supplied id; the latter two additionally self-check `$actorMembership['organization_id'] === $organizationId` (defense in depth) |
| E | Owner has full organization-level permissions | `role_permissions('owner')` = every catalog permission except the two KYC ones |
| F | Admin has broad but not irreversible owner-level authority | `role_permissions('admin')` = everything owner has except `wallet.adjust`; the owner-tier escalation guard (`can_assign_role()`) additionally blocks admin from granting/revoking the `owner` role itself |
| G | Member has least privilege by default | `role_permissions('member')` excludes every administrative/financial permission; see section 4 for why it does NOT exclude messaging/contacts/campaigns (pre-existing universal access, not silently downgraded) |
| H | No user may grant permissions they do not possess | `can_assign_role()` — only an `owner` may touch the `owner` tier; `admin` may only move a target within/below the `admin` tier |
| I | The last owner cannot be removed/demoted accidentally | `organization_change_member_role()`/`organization_remove_member()` — transaction-safe via `SELECT ... FOR UPDATE`, proven under real concurrency (section 8) |
| J | Platform admin stays distinct from organization RBAC | `app/rbac.php` never reads `$user['role']` (the platform `is_admin` flag); every platform-admin-gated page keeps its existing `require_admin()`/`is_admin()` bypass, completely separate code path |

## 3. Permission catalog

Centralized in `app/Support/Permissions.php` (a constants-only class, same pattern as
`app/Support/Logger.php` — no autoloader, `require_once` from `app/bootstrap.php`). Every permission
string used anywhere in this codebase is one of these constants; nothing is a free-form literal.

Not every constant gates a real feature today. Five are **reserved**: catalog-complete and present in
the role matrix, ready for the day a real organization-scoped feature needs them, but nothing checks
them yet because the underlying feature is deliberately still platform-admin-only (building a new
feature just to give a permission something to gate would be scope creep this phase rejected). Each
one's own docblock in `Permissions.php` explains exactly why:

| Permission | Why reserved | Today's actual access rule |
|---|---|---|
| `sender.manage` | Assigning a number to a user (`public/numbers.php`) is a shared install-wide resource, not per-org self-service | Platform admin only (`require_admin()`) |
| `wallet.adjust` | Manual credit adjustment (`app/wallet.php`'s `wallet_manual_adjustment()`) is the strictest possible default — stricter than owner-only | Platform admin only, via `public/users.php` |
| `kyc.view` / `kyc.manage` | STEP 21: identity documents must not broaden to org admins merely because RBAC exists | Platform admin, or the document's own subject, only |
| `audit.view` | `ellsms_audit_log` has no `organization_id` column and no viewer UI exists | No UI exists to view it at all yet |

## 4. Role matrix (generated by `php cron/rbac-status.php` — regenerate from there if this ever drifts)

| permission | owner | admin | member |
|---|---|---|---|
| members.view | yes | yes | yes |
| members.manage | yes | yes | — |
| sender.view | yes | yes | yes |
| sender.manage (reserved) | yes | yes | — |
| contacts.view | yes | yes | yes |
| contacts.manage | yes | yes | yes |
| campaigns.view | yes | yes | yes |
| campaigns.manage | yes | yes | yes |
| campaigns.send | yes | yes | yes |
| messages.send | yes | yes | yes |
| schedules.view | yes | yes | yes |
| schedules.manage | yes | yes | yes |
| autoreply.view | yes | yes | yes |
| autoreply.manage | yes | yes | yes |
| wallet.view | yes | yes | yes |
| wallet.adjust (reserved) | yes | — | — |
| payments.view | yes | yes | yes |
| reports.view | yes | yes | yes |
| settings.manage | yes | yes | — |
| kyc.view (reserved) | — | — | — |
| kyc.manage (reserved) | — | — | — |
| audit.view (reserved) | yes | yes | — |

**Why `member` keeps so much:** none of contacts/campaigns/messaging/schedules/autoreply/reports/
wallet-view/payments-view were role-gated at all before this phase — any logged-in organization
member could already do all of it. Stripping them now would be an undocumented downgrade this
phase's own instructions explicitly forbid ("do not silently downgrade... without documenting").
What member correctly lacks is exactly the administrative/financial/identity tier: membership
management, organization settings, manual wallet adjustment, audit visibility, and KYC.

## 5. Central authorization API (`app/rbac.php`)

- `membership_has_permission(array $membership, string $permission): bool` — pure, no DB, unit-testable with a plain fixture array.
- `has_permission(int $userId, int $organizationId, string $permission): bool` — DB-backed, fail-closed, re-resolves membership fresh every call. The one background-job/worker-safe entry point (never touches `$_SESSION`).
- `require_permission(string $permission): array` / `require_any_permission(array $permissions): array` / `require_all_permissions(array $permissions): array` — web-context helpers. Each calls `require_organization()` first (Phase 6's own fail-closed 403 for "no active organization"), then checks the permission; 403 + `rbac.permission_denied` log line on denial. Deliberately does **not** also enforce non-suspended organization status — that stays `require_active_organization()`'s separate job (Phase 6), called alongside where needed, so the two concerns never conflate.
- `can_assign_role(string $actorRole, string $currentRole, string $newRole): bool` — pure escalation-tier decision (Invariant H), unit-testable in isolation.
- `organization_change_member_role()` / `organization_remove_member()` — the only two places `ellsms_organization_memberships.role`/`status` are mutated by user-facing code; see section 8.

No permission caching exists anywhere in this file, deliberately (see section 7).

## 6. Organization scope & platform-admin separation

Every permission decision takes an explicit `(user, organization)` pair — there is no "does this user
have permission X anywhere" query, satisfying Invariant A by construction. `app/rbac.php` never reads
`$user['role']` (`ellsms_meta.is_admin`, Phase 2's platform-admin flag); every already-`require_admin()`-
gated page (`public/users.php`, `public/settings.php`, `public/numbers.php`, `public/analytics.php`)
keeps its pre-existing unrestricted bypass, completely untouched by this phase. An organization's
`owner` is never, anywhere, treated as a platform admin, and a platform admin is never, anywhere,
treated as automatically having every organization's `owner` permissions — the two systems share zero
code paths (Invariant J).

## 7. Cache policy

`has_permission()`/`membership_has_permission()` carry **no cache** — every call re-resolves the
membership row from the database. This was a deliberate choice after this same session found and
fixed a real bug in `current_user()` (`app/bootstrap.php`): a plain "resolve once, cache forever"
static cache got permanently poisoned to `null` the first time `Logger::info()` (which reads
`current_user()` to attach an actor id to log lines) fired before any session existed — see
`docs/phase-6-final-report.md` section 28.7 for the full incident. `current_organization()`
(`app/tenant.php`) already avoided this trap via a cache keyed on `(userId, selectedId)`; `app/rbac.php`
sidesteps the whole class of bug by not caching at all, since a fresh `SELECT` against an indexed
`(user_id, organization_id)` unique key is cheap enough that the correctness risk of ANY caching
scheme wasn't worth it for this phase. Role changes therefore take effect on the very next call, same
request, with zero propagation delay (STEP 29; proven by
`RbacTest::testRoleChangeTakesEffectImmediatelyWithNoStalePermissionCache`).

## 8. Owner protection & transfer

`organization_change_member_role()` and `organization_remove_member()` both:
1. Reject unless the actor's own membership carries `MEMBERS_MANAGE`.
2. Self-check the actor's membership actually belongs to the target `$organizationId` (defense in depth beyond what today's one call site already guarantees).
3. Inside a `db_transaction()`, run `SELECT user_id, role FROM ellsms_organization_memberships WHERE organization_id = ? AND status = 'active' FOR UPDATE` — locking every active membership row of that organization before deciding anything.
4. Apply `can_assign_role()` for the escalation-tier check.
5. If the target is currently the `owner` and the new state would remove that, recompute the owner count from the **locked** read and reject with `last_owner` if it would hit zero.

The `FOR UPDATE` lock is what makes this safe under real concurrency, not just sequentially: a second
transaction attempting to demote a different owner of the same organization blocks on the lock until
the first commits, then re-reads the **already-updated** state — proven by
`tests/Integration/RbacConcurrencyTest.php` (two genuinely separate OS processes, same pattern as
Phase 3's `WalletConcurrencyTest`), not just asserted by inspection.

**Owner transfer** (STEP 30) needs no dedicated UI: an existing owner promotes a target to `owner`
through the same "add/change member role" form `public/organizations.php` already had (the `owner`
option only renders when the acting user is already an owner); the *new* owner can then freely demote
the *old* one through the same mechanism, since two owners existing means demoting one no longer trips
the last-owner rule. Never an intermediate zero-owner state at any point.

## 9. Database migration

**`db/migrations/2026_07_31_rbac_owner_protection_index.sql`** — the only schema change this phase
made. Adds `idx_org_role_status (organization_id, role, status)` to
`ellsms_organization_memberships`, purely so the `FOR UPDATE` locking query in section 8 stays an
efficient index range scan under concurrent load on an organization with many members — an
operational improvement, not a correctness fix (the transaction/locking logic is correct with or
without the index). Guarded via `information_schema` existence check, same pattern every migration
since Phase 5 uses. No role/permission table exists; migration preflight for this phase reduces to
"does the index already exist," since `ADD INDEX` cannot violate data the way `UNIQUE`/`FK` can.

## 10. RBAC integrity tool

`cron/rbac-integrity-check.php` (`make rbac-integrity-check`) — read-only, mirrors Phase 5/6's
integrity-tool design (migration preflight + ongoing monitor, exits non-zero only on real critical
findings, never auto-fixes):
- Organizations with zero active owners — the **correct** `LEFT JOIN`/`NOT EXISTS` version of a check
  Phase 6's own `tenant-integrity-check.php` also has, but that one is a `GROUP BY ... HAVING COUNT(*)
  = 0` over existing rows, which can structurally never detect a group with zero rows at all (a latent,
  pre-existing bug in Phase 6's own file — documented as TD-038, deliberately left unfixed there per
  this phase's own "don't redo Phase 6" scope boundary; the correct check lives here instead).
- Membership rows with a role value outside `owner`/`admin`/`member` (defensive — the ENUM column
  normally prevents this).
- A code-level self-consistency check: every permission string `role_permissions()` grants for each
  role actually exists in `Permissions::all()` (catches a typo'd permission constant at
  operator-run-time).

`make rbac-status` (`cron/rbac-status.php`) prints the role matrix straight from
`role_permissions()`/`Permissions::all()` — the table in section 4 is a snapshot of this command's
real output, not hand-maintained prose that could drift from the code.

## 11. Permission denial semantics

`require_permission()`/`require_any_permission()`/`require_all_permissions()` return HTTP 403 with a
generic Persian message ("شما اجازه‌ی انجام این عملیات را ندارید") and a `rbac.permission_denied`
structured log line (actor id, organization id, permission(s) attempted) — never a stack trace or
internal detail. This matches Phase 6's own `require_organization()` denial style exactly (same 403 +
plain-text pattern), so a caller can't distinguish "no organization" from "wrong permission" from the
response body alone, avoiding unnecessary information disclosure about why access was denied.

## 12. Background job / worker policy (STEP 36)

Permission checks happen **only at job-creation time** (the web page handler — e.g.
`require_permission(Permissions::SCHEDULES_MANAGE)` before inserting an `ellsms_schedule` row).
Nothing was added to `run_due_schedules()`, `bulk_send_one_item()`, or `autoreply_process_one()`
(`app/backend.php`) to re-check the CREATOR's current permission at dispatch time — those functions
already, correctly, revalidate the job's persisted `organization_id` against `organization_status()`
(Phase 6), which is what determines whether a queued job still runs. A member who created a scheduled
send and is later demoted to a role without `schedules.manage` does **not** get their already-queued
job silently cancelled — the job belongs to the organization, and organization-level authorization
(not the original creator's now-possibly-different personal role) governs whether it still executes.
This matches the phase's own recommended policy and is a deliberate, disclosed choice, not an
oversight: re-checking live actor permission inside a worker would require attaching a live user
identity to background execution, which Phase 6 explicitly rejected for the same reason sessions don't
exist in a worker process.

## 12b. Platform-admin support impersonation

`docs/admin-impersonation.md` adds a way for a platform administrator to open a customer's panel. It
is worth stating here what it does **not** do to RBAC: nothing.

While impersonating, `$_SESSION['uid']` is the TARGET's id, so every function in this document —
`current_organization()`, `membership_has_permission()`, `has_permission()`, `require_permission()` —
resolves the target's own membership and nothing else. There is no platform-admin bypass to leak,
because the admin role is simply not present in the effective session: `is_admin()` returns **false**
and the whole platform-admin area returns 403 until the operator exits.

The real actor is preserved separately, for the banner, the audit trail and the exit control only.
No permission decision in this document consults it. `tests/Integration/ImpersonationTest.php`
asserts a member-level target is denied `members.manage`, `api_keys.manage`, `settings.manage`,
`billing.manage` and `wallet.adjust` while a platform admin is behind the session — and still
*granted* `campaigns.manage`, so the isolation is role fidelity rather than blanket denial.

## 12c. Customer / organization profile

`docs/customer-profile.md` adds a company/legal profile, address, alert settings and documents. It
introduces **no new permissions**, deliberately.

| Action | Gate |
|---|---|
| Edit own personal profile / personal documents | the user themselves — no permission involved |
| View organization profile, address, alerts, documents | active membership |
| Edit organization profile, address, alerts, documents | `settings.manage` (owner, admin — not member) |
| Edit any customer's profile | platform admin, through `users.php` |

`settings.manage` was chosen over a new `profile.manage` because it is already the
organization-configuration permission, already held by exactly the roles that should hold this, and
already in the role matrix — a new permission would have been granted to the same roles and added a
second thing to keep in sync. The role matrix below is therefore unchanged.

## 13. Ticket / KYC policy — unchanged, explicitly

Neither is touched by RBAC. Tickets remain strictly user-private (Phase 6's own explicit policy,
reaffirmed here, not re-litigated — see `app/tickets.php`'s docblock); KYC remains platform-admin +
self-view only (section 3's reserved-permission table). Introducing an organization permission for
either would be a real product/privacy regression, not a Phase 7 deliverable.
