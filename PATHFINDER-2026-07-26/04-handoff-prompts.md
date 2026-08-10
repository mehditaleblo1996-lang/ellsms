# Handoff prompts — for a LATER `/make-plan` invocation, not now

Each block below is copy-pasteable into `/make-plan` once the user decides to start a refactor
phase. None of these should be run as a side effect of the current stabilization work.

## 1. Fix the inbox.php IDOR + unify originator scoping (highest priority — data leak)

```
Fix the confirmed cross-tenant data leak in public/inbox.php: when a non-admin user's
ellsms_meta.originator is empty (the normal case on any install using the newer ellsms_numbers
per-user assignment model — see db/ellsms_extra.sql:148-150), inbox.php:17 applies no ownership
filter at all, so that user sees every inbound message for every line and every user, including
message content. Fix by introducing allowed_originators(array $user): array in app/bootstrap.php
(logic already correct in public/autoreply.php:9-17 — reuse it, don't reimplement), and use its
result to build inbox.php's WHERE clause (IN (...) over the allowed originator list, falling back
to "no rows" rather than "no filter" when the list is empty). Also update public/send.php,
public/new-send.php, public/p2p-send.php, public/smart-send.php to call the same function instead
of their own copy of `SELECT number,label FROM ellsms_numbers WHERE assigned_user_id=?` (5 verbatim
copies currently — see PATHFINDER-2026-07-26/02-duplication-report.md #1).
Reference flowchart: PATHFINDER-2026-07-26/01-flowcharts/reporting-autoreply-tickets-public.md
Guard against: don't build a generic "originator resolver service" with pluggable strategies —
this is one function with one behavior, reused, not a framework.
```

## 2. Close the users.php authorization boundary + centralize target-user resolution

```
public/users.php has 6 id-scoped admin actions (revoke, toggle_admin, toggle_2fa, originator,
credit, password, kyc_save) plus a GET ?edit= view. Only 2 of the 6 mutating actions self-check
`id !== me['id']`, and NONE of them (nor the GET edit view, users.php:163-170) filter by
ellsms_meta.panel_access — meaning an ELLSMS admin can currently read/reset the password/change
credit of ANY row in the shared user_ table, not just accounts ELLSMS has granted access to. Add
one `resolve_target_user(int $id): array` gate (404 if no ellsms_meta row / not panel_access) that
every id-scoped branch calls before acting, replacing the current per-branch ad hoc checks. Also
fix public/logout.php (currently a bare GET with no csrf_check() — the only state-changing action
in the whole app without one).
Reference flowchart: PATHFINDER-2026-07-26/01-flowcharts/auth-2fa-admin.md
Guard against: don't turn this into a generic ACL/permissions framework — a single guard function
matching the existing require_login()/require_admin() style is enough.
```

## 3. Make the payment credit grant transactional + add reconciliation

```
public/zarinpal-callback.php:40-44 performs the ellsms_payments claim UPDATE and the
user_.currentcredit increment as two independent autocommitted statements with no
beginTransaction()/commit(). A crash between them leaves a payment permanently marked 'paid' with
the customer's credit never applied, and there is no reconciliation job (cron/worker.php has zero
payment-related code) to catch it. Wrap both writes in one transaction. Separately, add a
reconciliation pass (can run inside the existing 8-second worker loop or as its own --once cron
entry) that re-verifies any ellsms_payments row still 'pending' after N minutes against ZarinPal's
verify endpoint. Also add a maximum-purchase bound to public/buy-credit.php (currently only a
minimum is enforced).
Reference flowchart: PATHFINDER-2026-07-26/01-flowcharts/payments-zarinpal.md
Guard against: don't touch the double-credit guard itself (the atomic UPDATE...WHERE id=? AND
status='pending' in zarinpal-callback.php:40 is already correct) — only add the missing
transaction boundary and the missing reconciliation job.
```

## 4. Give run_bulk_send_pass() the same atomic claim the other two worker passes have

```
run_due_schedules() (app/backend.php:163-165) and run_autoreply_pass() (app/backend.php:276-287)
each protect against double-processing a row with an atomic claim before sending. run_bulk_send_pass()
(app/backend.php:574-627) has no equivalent claim — it SELECTs pending ellsms_bulk_items and sends
them directly, a latent duplicate-send race if the worker ever overlaps itself (overlapping cron
--once + persistent loop, or a scaled worker replica). Add `UPDATE ellsms_bulk_items SET
status='claimed' WHERE id=? AND status='pending'`, checked via rowCount(), immediately before
bulk_send_one_item() is called for each row, mirroring run_due_schedules()'s pattern exactly.
While in this area: also delete bulk_queue_job()'s duplicate, staler credit pre-check
(app/backend.php:503-505) since it gives false assurance and the real gate already exists deeper
in dispatch_message() — do NOT remove the real gate itself, only the redundant early copy.
Reference flowchart: PATHFINDER-2026-07-26/01-flowcharts/send-bulk-messaging.md
Guard against: don't introduce a generic "job queue" abstraction — one UPDATE statement, matching
the existing pattern, is the entire fix.
```

## 5. Fix the schedules.php cancel / worker finalize lost-update race

```
public/schedules.php:12's cancel handler matches status IN ('active','processing') — including
rows the worker has already claimed and is mid-send on. run_due_schedules()'s final write
(app/backend.php:190-192) has no `WHERE status='processing'` guard, so it unconditionally
overwrites the row back to 'done'/'active' even if the user cancelled it in the meantime, silently
undoing the cancellation (and the message was already sent/charged regardless). Add
`AND status='processing'` to the final UPDATE in run_due_schedules(), and have it check rowCount()
before deciding whether the "done" transition actually applied — if a cancel won the race, the
worker's write becomes a no-op instead of clobbering it.
Reference flowchart: PATHFINDER-2026-07-26/01-flowcharts/contacts-numbers-schedules.md
Guard against: don't add a general optimistic-locking/versioning column — the same
claim-by-status-transition pattern used elsewhere in this file is sufficient.
```

## 6. Consolidate p2p-send.php / smart-send.php page wiring + fix the orphaned "gradual" job type

```
p2p-send.php and smart-send.php duplicate ~90% of their code (numbers setup, cancel handler,
originator resolution, catch block, results table — see
PATHFINDER-2026-07-26/02-duplication-report.md #3); only the row-parsing body genuinely differs.
Separately, new-send.php's gradual mode (new-send.php:87-93) queues jobs with type='gradual' via
the same bulk_queue_job()/ellsms_bulk_items engine, then redirects to /p2p-send.php — but
p2p-send.php's listing and cancel queries are hardcoded to type='p2p' (p2p-send.php:76-80,22), so
a gradual job is invisible and uncancellable anywhere in the UI once queued. Fix by extracting the
shared wiring into one function/page parameterized by type, and make the job-listing/cancel
queries type-agnostic (or add a dedicated gradual-jobs view) so gradual jobs are actually visible
and manageable.
Reference flowchart: PATHFINDER-2026-07-26/01-flowcharts/send-bulk-messaging.md
Guard against: don't merge p2p and smart's row-parsing logic — that's genuine specialization
(plain text vs. template+placeholders) and should stay separate.
```

## 7. Unify the admin-CRUD marketing pages

```
public/pricing.php, public/guide-admin.php, public/slides.php each hand-roll an identical
save/delete/toggle POST dispatch and list-table rendering pattern (see
PATHFINDER-2026-07-26/02-duplication-report.md #7). Extract one render_admin_crud_page() helper
in app/bootstrap.php or a new app/admin_crud.php, parameterized by table name, editable columns,
and an optional upload hook (needed only by slides.php). While in this area, fix slides.php's
upload validator to have the same extension-fallback behavior as kyc_store_upload() when
mime_content_type() is unavailable (app/bootstrap.php:433-441 vs public/slides.php:24).
Reference flowchart: PATHFINDER-2026-07-26/01-flowcharts/reporting-autoreply-tickets-public.md
Guard against: don't build a generic form-builder/admin-panel-generator — 3 call sites need a
config array and one render function, nothing more general than that.
```
