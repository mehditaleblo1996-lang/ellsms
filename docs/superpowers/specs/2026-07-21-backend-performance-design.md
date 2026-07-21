# ELLSMS backend & performance improvements

Status: approved, ready for implementation planning
Date: 2026-07-21

## Context

ELLSMS is a dependency-free PHP 8.2 / Apache panel sharing a MySQL database
with a connected backend SMS platform. There's no specific performance
incident driving this — it's proactive work ahead of growing panel usage
and larger bulk campaigns (پیامک هوشمند / نظیر به نظیر). This spec covers
six independent, low-risk improvements. A separate RabbitMQ queue-monitoring
admin dashboard was scoped out as its own follow-up spec (different
subsystem: read-only integration with the backend platform's own RabbitMQ,
not an ELLSMS-internals change).

## A. OPcache

Enable and tune OPcache in `docker/Dockerfile`, applied identically to the
`app` and `worker` images (they build from the same Dockerfile):

```
opcache.enable=1
opcache.enable_cli=1
opcache.memory_consumption=128
opcache.max_accelerated_files=4000
opcache.validate_timestamps=0
```

`docker-compose.yml` bind-mounts the whole repo into both containers
(`./:/var/www/html`), and `deploy.sh` always rebuilds + restarts containers
on deploy, which flushes OPcache's in-memory cache. So
`validate_timestamps=0` (skip the filesystem `stat()` per file per request)
is safe — there is no real deploy path where stale bytecode could survive a
restart. Zero application code changes.

`opcache.enable_cli=1` matters because the worker container runs
`php cron/worker.php` as a long-lived CLI process, not per-request — still
worth enabling since it also benefits any one-off `--once` CLI invocations.

## B. Bulk-send concurrency

**Current state:** `run_bulk_send_pass()` (`app/backend.php:574`) processes
up to 20 unthrottled rows per 8-second worker tick (plus any due throttled
jobs), calling `bulk_send_one_item()` → `dispatch_message()` →
`backend_api_send()` once per row, fully sequential — one blocking
`curl_exec` at a time.

**Change:** split each tick's batch processing into three phases:

1. **Fan-out (unchanged logic):** for each item in the batch, run the
   existing owner/credit check (`bulk_send_one_item`'s current lookup),
   and build the backend API request payload instead of firing it inline.
2. **Concurrent execute:** fire the built requests via `curl_multi_*` with
   bounded concurrency, controlled by a new env var `BULK_SEND_CONCURRENCY`
   (default `5`). No `.env`-with-Settings-override needed here — it's an
   ops tuning knob against an unknown backend rate limit, not a business
   setting, so a plain env var (documented in `.env.example`) is
   appropriate.
3. **Sequential finish:** as each response completes, run the exact same
   post-processing that happens today — credit deduction, `sent_rows` /
   `failed_rows` counters, `ellsms_bulk_items.status` — sequentially, since
   DB writes are cheap relative to network wait and don't need to overlap.

`dispatch_message()` itself (used directly by `send.php`, `new-send.php`
direct mode, `url_send.html`, schedules, autoreply) is **not** changed —
this only touches the bulk worker's internal batch loop. Both the
unthrottled batch and each throttled job's batch go through the same
concurrent-fan-out helper.

## C. Persistent DB connections

Enable `PDO::ATTR_PERSISTENT => true` in `db()` (`app/bootstrap.php:25`).

**Risk:** two call sites use explicit transactions —
`app/backend.php:508` (bulk job creation) and
`public/number-categories.php:33` (category creation) — both already wrap
in try/catch with `rollBack()` on `Throwable`, which covers normal PHP
exceptions. The edge case a persistent connection adds: a hard crash
(OOM-killed process, segfault) mid-transaction could leave that persistent
connection with an open transaction for the *next* request on that
Apache child process to inherit.

**Mitigation:** immediately after acquiring the persistent connection in
`db()`, check `if ($pdo->inTransaction()) $pdo->rollBack();` before
returning it — cheap insurance, no measurable cost on the common path.

## D. Settings caching (APCu)

`setting()` (`app/bootstrap.php:44`) already caches all of
`ellsms_settings` in a per-request static array (one query per request,
not N+1). This adds a shared APCu layer so most requests skip that query
entirely:

- `setting()`: check APCu first; on miss, run the existing full-table
  query, populate both the static array and APCu (short TTL, e.g. 60s, as
  a safety net in case invalidation is ever missed).
- `set_setting()`: after the `INSERT ... ON DUPLICATE KEY UPDATE`, delete
  the APCu key so the next read repopulates it.

Requires enabling the `apcu` extension in `docker/Dockerfile` (same
pattern as the existing `pdo_mysql`/`zip` installs). This extension is
also used by section E.

## E. Per-user send rate limit

**Scope:** interactive/API sends only — the three places a live HTTP
request triggers an immediate `dispatch_message()` call:
`public/send.php:67`, `public/new-send.php:64` (direct mode only —
recurring/gradual there create a schedule or bulk job instead, which stay
governed purely by the worker's own pacing), and
`public/sms/url_send.html:75`. Worker-driven sends (schedules, autoreply,
bulk jobs from section B) are **not** subject to this limit — they're
already paced by the worker's tick interval, each job's own
`throttle_count`/`throttle_minutes`, and `BULK_SEND_CONCURRENCY`. Applying
the same per-second cap there would fight that pacing rather than
complement it (a bulk job's rows all share one `user_id`, so concurrent
worker-driven requests for that job would otherwise collide with the same
limit meant for interactive abuse).

**Mechanism:** new `rate_limit_check(int $userId): bool` helper in
`app/bootstrap.php`, using APCu (section D) — a per-second counter keyed
`rl:{userId}:{unixSecond}`, incremented on each check, short TTL (~2s).
Single `app` container per `docker-compose.yml`, so APCu (in-process /
per-container state) is sufficient. Note for the spec/README: this counter
resets per container, so if the `app` service is ever horizontally scaled
behind a load balancer, this would need to move to a shared store — not a
concern at current scale.

**Config:** new `ellsms_settings` key `send_rate_limit_per_sec`,
admin-editable from the Settings page, same `.env`-with-Settings-override
pattern as ZarinPal/Telegram (`SEND_RATE_LIMIT_PER_SEC` in `.env.example`).
Default `5`. `0` or empty disables the check entirely.

**Behavior on exceeding the limit:**
- `send.php` / `new-send.php`: same flash-error UX pattern already used
  for other validation failures — "تعداد درخواست‌ها زیاد است — چند لحظه
  صبر کنید." — request is not sent, no credit charged.
- `url_send.html`: new `error_code: -7` in the existing JSON error shape
  (`{"status":"not ok","reference_id":null,"error_code":-7}`). Update the
  README's documented error-code table (currently `-1` through `-6`) to
  add it.

## F. Indexes on high-traffic tables

Dashboard (`public/index.php`), reports (`public/reports.php`), analytics
(`public/analytics.php`), and inbox (`public/inbox.php`) all scan
`outbound_message` / `inbound_message` by date range, `sender_user_id`,
and/or `destination`. These two tables are backend-platform-owned (per the
README: ELLSMS "does NOT own or migrate the platform's own tables") — the
team decided to add the indexes anyway via `db/ellsms_extra.sql`, since
`CREATE INDEX` is additive and non-destructive (no data or column changes,
just a lookup structure), using the same guarded
`information_schema`-check + `PREPARE`/`EXECUTE` pattern already used
there for the `uniq_inbound` and `twofa_enabled` migrations (safe to
re-run, skips if the index already exists). The migration's SQL comment
will flag explicitly that this touches backend-owned tables, so it's
visible and intentional rather than silent — same spirit as the
coordinated-change language the README already uses for password hashing.

**`outbound_message`:**
- `idx_outbound_sent_at (sent_at)` — admin-wide date-range scans
  (dashboard today/7-day, admin reports, analytics)
- `idx_outbound_user_sent (sender_user_id, sent_at)` — per-user
  date-range scans (non-admin dashboard/reports, `users.php`'s sent-count
  subquery at `public/users.php:182`)
- `idx_outbound_user_dest (sender_user_id, destination, id)` — the
  `url_send.html:81` dedup lookup
  (`sender_user_id = ? AND destination = ? ORDER BY id DESC LIMIT 1`),
  currently unindexed

**`inbound_message`:**
- `idx_inbound_received_at (received_at)` — admin-wide inbox/date-range
  scans
- `idx_inbound_dest_received (destination, received_at)` — non-admin
  per-line inbox view

**Not in scope:** `content`/`destination` `LIKE '%...%'` filters in
reports/inbox search — leading-wildcard LIKE can't use a btree index
regardless of what's added here; that would need FULLTEXT, a separate,
bigger decision.

**Query fix bundled with this section:** `public/index.php`'s dashboard
queries use `DATE(sent_at)=CURDATE()` (lines 16-17), which wraps the
column in a function and makes that comparison non-sargable — the new
`sent_at` index won't be used there as written. Rewrite to
`sent_at >= CURDATE() AND sent_at < CURDATE() + INTERVAL 1 DAY`
(functionally identical, index-friendly). Included here because it's a
one-line change needed for section F to actually pay off on the dashboard
page.

## Out of scope (deferred)

- RabbitMQ queue-monitoring admin dashboard — separate spec, read-only
  integration with the backend platform's own RabbitMQ management API.
- FULLTEXT search on `outbound_message.content` / `inbound_message.content`
  for report/inbox search filters.
- Horizontal scaling of the `app` container (would require moving APCu
  state in sections D/E to a shared store).

## Testing

- A/B request timing (with/without OPcache) on a few representative pages.
- Bulk-send: queue a test job large enough to span several ticks, confirm
  total wall-clock time drops with concurrency enabled, and that
  `sent_rows`/`failed_rows` counts still reconcile exactly against
  `total_rows`.
- Persistent PDO: kill `-9` the Apache process mid-transaction in a test
  environment (e.g. during category creation), confirm the next request on
  that connection doesn't inherit an open transaction.
- Rate limit: script rapid-fire requests to `url_send.html` as one user,
  confirm `error_code: -7` appears once the configured limit is exceeded,
  and that a second user's requests are unaffected.
- Indexes: `EXPLAIN` the dashboard/reports/analytics/inbox queries before
  and after, confirm the new indexes are actually chosen (not just
  present) — including re-verifying the dashboard's `DATE()` rewrite is
  needed for `idx_outbound_sent_at` to be used there.
