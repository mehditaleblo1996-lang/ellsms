# Admin support impersonation ("ورود به پنل مشتری")

A platform administrator can open a customer's panel to reproduce a support issue, without knowing,
resetting, or touching that customer's password or 2FA.

Code: `app/impersonation.php` · Endpoint: `public/impersonate.php` · Entry point: **مدیریت →
کاربران → <customer> → ورود به پنل مشتری**

---

## 1. The design, in one sentence

**While impersonating, `$_SESSION['uid']` IS the target user's id.**

Not the admin's, not a pair, not a flag every page has to remember to consult. `current_user()`,
`current_organization()`, `has_permission()`, `can_use_originator()` and every other authorization
primitive therefore resolve **exactly** as they would in the target's own session — because as far
as they are concerned, it *is* the target's session.

That is what makes the central security property true by construction rather than by vigilance:
there is no "platform admin + target" hybrid identity that could leak an admin bypass into a
customer page, because no such identity exists at any moment.

The real actor lives beside it, in `$_SESSION['impersonation']`, and is consulted for exactly three
things: **the banner, the audit trail, and the exit control.** Nothing in the codebase uses it to
make an authorization decision.

```
$_SESSION = [
  'uid' => <target user id>,          // the EFFECTIVE user — everything resolves from this
  'impersonation' => [
    'actor_user_id'  => <platform admin>,
    'target_user_id' => <customer>,   // must equal uid, or the state is invalid
    'started_at'     => <unix UTC>,
    'reason'         => '<plain text, ≤200 chars>',
    'mode'           => 'support',
    'original_organization_id' => <admin's selection, restored on exit>,
    'return_to'      => '/users.php?edit=<id>',
  ],
]
```

## 2. Threat model

| Concern | How it is addressed |
|---|---|
| Password theft / account takeover | No password is read, verified, set or reset anywhere. A test asserts the source contains no credential primitive at all. |
| Privilege escalation into the customer's org | The effective user IS the customer; the admin role is simply absent from the session. `is_admin()` returns **false** while impersonating. |
| Session fixation | `session_regenerate_id(true)` on both start and exit; asserted over real HTTP by observing the cookie change. |
| Forged session | `impersonation_state()` requires the recorded target to *be* the session's effective user, plus a distinct, existing, still-privileged actor. Anything else destroys the session. |
| Nesting | Refused twice: the service checks, and `require_admin()` denies during an impersonation. |
| Cross-tenant access | The target's own memberships resolve the organization; a crafted `organization_id` fails exactly as it would for the customer alone. |
| Silent support activity | Start, exit, every blocked attempt, and every action inside the session are audited with **both** identities. |
| Stale privilege | The actor's platform-admin status is re-read from the database on every request, never trusted from the session. |
| Enumeration of user ids | Starts are rate-limited per actor and per IP. |
| Unbounded access | A support session expires after 60 minutes and returns the operator to the admin panel. |

## 3. Real actor vs. effective user

| Helper | Meaning |
|---|---|
| `current_user()` | the **effective** user — the customer while impersonating |
| `real_actor_user_id()` / `real_actor_user()` | the **real** human — the admin while impersonating, otherwise the logged-in user |
| `is_impersonating()` | whether a valid support session is active |
| `impersonated_user_id()` | the target's id, or null |

Security and audit code asks these helpers; nothing reads `$_SESSION['impersonation']` directly.

## 4. Support mode: what is blocked

One catalog, `impersonation_blocked_actions()`, is the whole policy. It is a **deny-list**: unknown
actions are allowed, so a typo can never silently disable an ordinary page, and blocking is always
the result of an explicit entry.

| Group | Blocked actions |
|---|---|
| Sending | `send.direct`, `send.bulk`, `send.campaign`, `send.schedule`, `send.autoreply` |
| Credentials | `account.password`, `account.twofa` |
| Integration secrets | `apikey.create`, `apikey.rotate`, `apikey.revoke`, `webhook.write`, `webhook.rotate` |
| Money & plan | `billing.subscription`, `billing.payment`, `wallet.adjust` |
| Organization | `org.members`, `org.transfer_owner`, `org.delete` |
| Destructive data | `contacts.delete`, `blacklist.delete` |

**Explicitly still allowed**, because they are the point of a support session: reading everything,
navigating, **cost preview** (read-only), viewing campaigns/reports/billing/usage, adding and
importing contacts, and the webhook `test` ping (a diagnostic).

**Enforcement is server-side.** Pages call `impersonation_guard_post($action)` right after
`csrf_check()`; the dispatch functions call `impersonation_action_allowed()` directly. Hiding a
button (`app/views/impersonation_notice.php`) is a courtesy so an operator learns before filling in
a form — never the enforcement.

Sending is blocked at the choke points every send funnels through — `dispatch_message()`,
`dispatch_message_retryable()` and `bulk_queue_job()` — so a send path added later is covered by
default. Those guards are inert outside a browser session: **workers, cron and the public API have
no `$_SESSION`**, so they are never impersonating and pay nothing.

## 5. Session lifecycle

```
admin session ──POST /impersonate.php (do=start)──► regenerate id
                                                   uid := target
                                                   drop organization selection
                                                   record actor + reason
                                                   audit impersonation.started
                                                        │
                                    every request: impersonation_enforce()
                                                        │
       ┌────────────────────────────────────────────────┼───────────────────────────────┐
       ▼                                                ▼                               ▼
 POST do=exit                                    window elapsed (60m)            invalid / actor
 regenerate id                                   auto-return to admin            no longer admin
 uid := admin                                    audit session_expired           DESTROY SESSION
 restore organization                                                            → /login.php
 audit impersonation.ended
```

**Logout is not exit.** Clicking «خروج» during a support session terminates the **whole** session —
it does not quietly hand the panel back to the administrator. Two controls, two meanings:
«بازگشت به پنل مدیریت» in the banner exits the impersonation, «خروج» logs out. Anything else would
make "log out" ambiguous at exactly the moment an operator wants it to be unambiguous.

The ordinary idle and absolute session timeouts remain in force and are unchanged; the 60-minute
support bound is *additional* and shorter, never an extension.

## 6. Target policy

A target may be impersonated only if it is an ELLSMS-managed account
(`resolve_ellsms_managed_user()` — the same gate every other admin action on a user uses). Refused:

| Reason | Why |
|---|---|
| `target_is_platform_admin` | Admin-to-admin switching is the most dangerous shape this feature could take, and support never needs it. |
| `target_is_self` | Does nothing but confuse the audit trail. |
| `target_deleted` | Nothing to support. |
| `target_not_found` / `target_has_no_panel_access` | Not an ELLSMS-managed account. |

An **inactive but present** account *is* impersonable, deliberately: "the customer cannot log in" is
a primary support case, and refusing it would remove the feature's main use. Nothing is reactivated
— the account state is read, never written, and every sensitive action stays blocked.

## 7. RBAC and tenancy

The effective session is the target's, so:

- an admin impersonating a **member** gets exactly a member's permissions — including being denied
  `members.manage`, `api_keys.manage`, `settings.manage`, `billing.manage` and `wallet.adjust`;
- and *keeping* the permissions a member genuinely has (`campaigns.manage`, `messages.send`), so this
  is role fidelity, not a blanket denial that merely looks safe;
- a crafted `organization_id` for an organization the target does not belong to resolves to nothing,
  exactly as it would for the customer alone;
- **the entire platform-admin area returns 403** until the operator exits, and the admin sidebar is
  not rendered.

## 8. Audit

`ellsms_audit_log` gains one additive nullable column, `impersonator_user_id`
(`db/migrations/2026_08_11_audit_impersonator.sql`). `audit()` fills it automatically from the
session, so no call site changed and none can forget.

| Event | `user_id` | `impersonator_user_id` |
|---|---|---|
| `impersonation.started` | admin | — (the admin's own administrative act) |
| `impersonation.start_refused` | admin | — |
| any action inside the session | **customer** | **admin** |
| `impersonation.blocked_sensitive_action` | customer | admin |
| `impersonation.session_expired` | customer | admin |
| `impersonation.ended` | admin | — |

`user_id` keeps its existing meaning ("whose account did this happen to"), which is why no historical
row or report changes; the real human is recorded *beside* it. **The real actor never disappears
from the trail.**

Structured logs carry `impersonator_user_id`, `effective_user_id`, `organization_id` and the request
id. No password, session id, CSRF token, 2FA code, API key or message body is ever logged.

## 9. Public API and workers

Impersonation is a **browser-session feature only**. It lives entirely in `$_SESSION`, which the
public API (Bearer-authenticated) and the workers do not have. A `/api/v1/*` request can never be
impersonated, no API key is ever issued for an impersonated user, and no background job inherits any
of this.

## 10. Operational use

1. مدیریت → کاربران → open the customer → **ورود به پنل مشتری**.
2. Confirm the username, organization and account status on the confirmation page.
3. Enter a **reason** — required. It is stored in the audit trail; write the ticket number.
4. Work in the customer's panel. The red banner names the account and shows the time remaining.
5. **بازگشت به پنل مدیریت** returns you to the customer's admin page.

To review support access afterwards:

```sql
SELECT created_at, action, user_id AS customer, impersonator_user_id AS admin, details
FROM ellsms_audit_log
WHERE impersonator_user_id IS NOT NULL OR action LIKE 'impersonation.%'
ORDER BY id DESC;
```

## 11. Known limitations

- **Support mode is the only mode.** There is no arbitrary per-session permission set, by design.
- **Read-mostly, not read-only.** Non-destructive writes an ordinary user may perform (adding a
  contact, saving a campaign template, sending a webhook test ping) are allowed. The blocked list is
  the exhaustive statement of what is not.
- **The banner depends on the shared header.** A page that renders its own layout without
  `app/views/header.php` would not show it. Every authenticated page currently uses the shared header.
- **An operator can still see customer data.** That is the feature. The control is that access is
  bounded, reasoned, audited, and attributed to a named administrator — not that it is prevented.
- **No per-field redaction.** Message bodies and contact lists are visible exactly as the customer
  sees them.

## 12. Configuration

No new environment variable is required. Two optional knobs reuse the existing rate-limit
convention, and both have working defaults:

| Variable | Default | Meaning |
|---|---|---|
| `RATE_LIMIT_IMPERSONATE_MAX` | `10` | Impersonation starts allowed per window, per actor and per IP |
| `RATE_LIMIT_IMPERSONATE_WINDOW_SECONDS` | `300` | That window |

The 60-minute support bound is the constant `IMPERSONATION_MAX_SECONDS` in `app/impersonation.php`,
deliberately not an environment variable: it is a security policy, not deployment configuration.
