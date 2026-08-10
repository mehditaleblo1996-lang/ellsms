# Admin support impersonation — final report

**Status: PASS**

A platform administrator can open a customer's panel to reproduce a support issue, without knowing,
resetting, or touching that customer's password or 2FA — with the customer's own permissions, a
persistent banner, sensitive actions blocked server-side, full dual-identity audit, and a safe exit.

Feature guide: [`docs/admin-impersonation.md`](admin-impersonation.md)

---

## 1. The design decision everything else follows from

**While impersonating, `$_SESSION['uid']` is the TARGET's id.**

`current_user()`, `current_organization()`, `has_permission()`, `can_use_originator()` and every other
authorization primitive therefore resolve exactly as they would in the customer's own session,
because as far as they are concerned it *is* the customer's session. There is no "platform admin +
customer" hybrid identity for an admin bypass to leak through, so the hardest requirement — RBAC
isolation — is true by construction rather than by remembering to check something in every file.

The real actor is preserved beside it and used for exactly three things: the banner, the audit trail,
and the exit control. No authorization decision anywhere consults it.

A direct consequence, and a deliberate one: `is_admin()` returns **false** during impersonation, so
the entire platform-admin area returns 403 until the operator exits.

## 2. Validation

### PHP lint
`make lint` — **233 files, 0 parse errors.**

### Unit tests
`vendor/bin/phpunit` — **300 tests, 730 assertions, 0 failures/errors/skipped.**

### Integration tests (real MySQL 8, clean database)
`vendor/bin/phpunit -c phpunit.integration.xml` — **422 tests, 2282 assertions, 0 failures/errors/
skipped** (372 before this work; +50).

- `tests/Integration/ImpersonationTest.php` — 35 tests, 265 assertions (service layer)
- `tests/Integration/ImpersonationHttpTest.php` — 15 tests, 175 assertions (real server, real sessions)

### Impersonation results

| Area | Result |
|---|---|
| Admin start | PASS — service and HTTP; POST-only, CSRF-checked, reason required |
| Non-admin denial | PASS — organization owner denied 403 on GET and POST, zero session mutation; the service refuses independently of the endpoint guard |
| Target validation | PASS — another platform admin, self, deleted, non-managed and crafted ids all refused with distinct reasons |
| Nested impersonation | PASS — refused by the service *and* by `require_admin()`; the original session is untouched |
| Session regeneration | PASS — a new session cookie on start **and** on exit; the previous session id no longer exists server-side (observed over real HTTP) |
| Real-actor preservation | PASS — `real_actor_user_id()` stays the admin throughout |
| Effective user | PASS — `current_user()` is the customer, carrying the customer's role |
| Organization context | PASS — the admin's selection is dropped on entry and restored on exit; the target's own organization resolves through the ordinary resolver |
| RBAC isolation | PASS — see §3 |
| Platform-admin bypass prevention | PASS — `is_admin()` false; `/sms-pricing.php`, `/settings.php`, `/numbers.php`, `/number-categories.php` all 403; admin sidebar not rendered |
| Support-mode banner | PASS — present on every authenticated page, names the organization/account, carries the exit control, shows time remaining |
| Send blocking | PASS — direct, bulk and scheduled sends refused at the dispatch choke points; **zero** jobs, items, reservations or ledger rows created |
| Password / 2FA blocking | PASS — refused server-side and audited; a static test asserts the feature's source contains no credential primitive at all |
| API key / webhook secret blocking | PASS — create, rotate, revoke and endpoint writes all blocked; the webhook `test` ping stays allowed as a diagnostic |
| Billing / wallet blocking | PASS — subscription change, cancellation and payment creation blocked; the pages stay readable |
| Audit attribution | PASS — see §4 |
| Exit / restore | PASS — admin identity, platform capability and organization restored; no impersonation residue |
| Logout | PASS — ends the **whole** session, does not hand the panel back |
| Target disable/delete | PASS — exit still works; the operator is never trapped |
| Admin revocation | PASS — the session is destroyed on the next request, not degraded into a customer session |
| Cross-tenant | PASS — a crafted `organization_id` resolves to nothing, exactly as for the customer alone |
| Rate limiting | PASS — repeated starts are refused and the refusal is audited |
| Crafted session | PASS — seven malformed shapes rejected; over HTTP the session is destroyed, not honoured |

### Regressions
Tenant isolation, RBAC, billing/quota, API/webhooks, wallet/payment, Cost Preview, SMS pricing,
backup/restore and the security suite are all inside the 422-test clean-state run above — **0
failures**.

### Live smoke
Real MySQL, real PHP server, real `public/` — the exact operator flow: admin opens the customer
detail page → **ورود به پنل مشتری** → confirmation → target dashboard → banner → admin area denied →
send refused → password change refused → audit verified → back to admin → admin access restored.
**34/34 checks pass.**

## 3. RBAC isolation — and one deviation worth stating

A platform admin impersonating a **member** is denied `members.manage`, `api_keys.manage`,
`settings.manage`, `billing.manage` and `wallet.adjust`, and the real actor being a platform admin
does not change any of those answers.

**Deviation from the brief:** STEP 38 names `campaigns.manage` as the permission to assert denial on.
In this codebase `member` *legitimately holds* `campaigns.manage` (see `role_permissions()` in
`app/rbac.php`), so asserting its denial would assert the wrong thing — it would pass only if
impersonation had broken the role model. The test therefore asserts denial on permissions a member
genuinely lacks, **and additionally asserts that `campaigns.manage` and `messages.send` are still
granted**. That is the stronger claim: role *fidelity*, not a blanket denial that merely looks safe.

## 4. Audit

`ellsms_audit_log` gains one additive nullable column, `impersonator_user_id`. `audit()` fills it
automatically from the session, so **no call site changed and none can forget**.

| Event | `user_id` | `impersonator_user_id` |
|---|---|---|
| `impersonation.started` / `.ended` / `.start_refused` | admin | — (the admin's own administrative act) |
| any action performed inside the session | **customer** | **admin** |
| `impersonation.blocked_sensitive_action` | customer | admin |

`user_id` keeps its existing meaning ("whose account did this happen to"), so no historical row or
report changes; the real human is recorded *beside* it. A test reconstructs a complete support
session from the trail alone — start (with reason), a blocked attempt, an allowed action, exit.

## 5. Blocked actions

One catalog, `impersonation_blocked_actions()`, is the whole policy — a deny-list, so a typo can
never silently disable an ordinary page. Blocked: all sending (direct/bulk/campaign/schedule/
auto-reply), password, 2FA, API key create/rotate/revoke, webhook write/rotate, subscription,
payment, wallet adjust, org members/owner-transfer/delete, contact and blacklist deletion.

Still allowed, because they are the point of a support session: all reading and navigation, **cost
preview**, viewing campaigns/reports/billing/usage, adding and importing contacts, and the webhook
`test` ping.

Sending is enforced at `dispatch_message()`, `dispatch_message_retryable()` and `bulk_queue_job()` —
the choke points every send funnels through — so a send path added later is covered by default.
Those guards are inert for workers, cron and the public API, none of which has a `$_SESSION`.

## 6. Deliverables

### New files (6)

| Path | Purpose |
|---|---|
| `app/impersonation.php` | the whole service: state, validation, start/exit, policy catalog, guards |
| `public/impersonate.php` | confirmation page + POST start + POST exit |
| `app/views/impersonation_notice.php` | inline "disabled in support mode" notice |
| `db/migrations/2026_08_11_audit_impersonator.sql` | additive `impersonator_user_id` column |
| `tests/Integration/ImpersonationTest.php` | 35 service-layer tests |
| `tests/Integration/ImpersonationHttpTest.php` | 15 real-HTTP tests |

Plus `docs/admin-impersonation.md` and this report.

### Modified

`app/bootstrap.php` (loader, `require_login()` enforcement hook, `require_admin()` denial, `audit()`),
`app/backend.php` (three send choke points), `app/views/header.php` (banner),
`public/users.php` (the ورود به پنل مشتری action), `public/logout.php` (explicit semantics + audit),
and one guard each in `public/{profile,api-keys,webhooks,billing,buy-credit,contacts,blacklist,autoreply,send,new-send,p2p-send,smart-send}.php`.

Docs updated: `security-review.md` (§16), `architecture.md`, `rbac-architecture.md` (§12b),
`production-runbook.md`.

### Migrations
One: `db/migrations/2026_08_11_audit_impersonator.sql`. Additive, guarded, rerun-safe. No existing row
is modified; historical rows keep `NULL`, which reads correctly as "not performed through an
impersonation".

### New environment variables
**None required.** Two optional knobs reuse the existing rate-limit convention and have working
defaults: `RATE_LIMIT_IMPERSONATE_MAX` (10) and `RATE_LIMIT_IMPERSONATE_WINDOW_SECONDS` (300). The
60-minute support bound is a constant, deliberately not an environment variable — it is a security
policy, not deployment configuration.

### Breaking changes
**None.** No API, no schema rewrite, no lifecycle change. Two behaviours are newly *constrained*, both
intentionally and only while a support session is active: the platform-admin area returns 403, and
the blocked actions above are refused. Ordinary sessions are completely unaffected — a test asserts
every catalogued action remains allowed outside an impersonation.

## 7. Residual risk

An operator with this capability can **see** customer data — message bodies, contacts, reports —
exactly as the customer sees them. There is no per-field redaction, and support mode is read-*mostly*
rather than read-only (non-destructive writes an ordinary user may perform are allowed; the blocked
list is the exhaustive statement of what is not).

The control is therefore accountability rather than prevention: access is bounded to 60 minutes,
requires a written reason, and is attributed to a named administrator in an append-only trail.
Deployments where that is insufficient should restrict who holds `ellsms_meta.is_admin`.

## 8. Acceptance criteria

- [x] platform admin can enter target customer panel
- [x] no password/2FA manipulation is used
- [x] original admin identity is preserved
- [x] effective user becomes target
- [x] target RBAC applies exactly
- [x] platform-admin privileges do NOT leak into target panel
- [x] tenant isolation remains intact
- [x] nested impersonation is blocked
- [x] session ID regenerates on start and exit
- [x] persistent impersonation banner exists
- [x] safe exit restores original admin
- [x] support-mode sensitive actions are server-side blocked
- [x] real message sending is blocked in support mode
- [x] password/2FA changes are blocked
- [x] API key/billing sensitive mutations are blocked
- [x] audit records identify both admin and target
- [x] normal logout terminates entire session
- [x] cross-tenant tests pass
- [x] full existing regression suite remains green
