# Inbox/reports/analytics/autoreply UI + tickets + public site + Telegram relay

## inbox.php — confirmed IDOR / cross-tenant leak

```mermaid
flowchart TD
  A["/inbox.php"] --> B["require_login()"]
  B --> C["parse filters"]
  C --> G{"is_admin()?"}
  G -->|no AND me.originator != ''| H["where += destination = me.originator"]
  G -->|no AND me.originator == '' (the common case on installs using ellsms_numbers)| I["*** NO OWNERSHIP FILTER — sees ALL inbound rows for every user/line, incl. content ***"]
  H --> J["SELECT ... LIMIT 50"]
  I --> J
```
Root cause: `ellsms_meta.originator` is a legacy single-line fallback (schema comment, db/ellsms_extra.sql:148-150); the real per-user scope lives in `ellsms_numbers`, which `inbox.php` never queries — unlike `autoreply.php` (queries ellsms_numbers correctly) and `reports.php` (scopes by `sender_user_id` FK correctly).

## autoreply.php admin UI

```mermaid
flowchart TD
  A["/autoreply.php"] --> B["require_login()"]
  B --> C["derive myAllowedOriginators from ellsms_numbers + legacy fallback (correct pattern)"]
  C --> D{"POST?"}
  D --> E["csrf_check()"]
  E --> F{"do=?"}
  F -->|create_rule| G["INSERT ellsms_autoreply_rules"]
  F -->|toggle_rule / delete_rule| H["*** raw db()-\>exec() string interpolation, ints cast but inconsistent with rest of file's prepared statements ***"]
  F -->|add_var/delete_var| I["prepared statements, correctly scoped"]
  G --> J["redirect"]
  H --> J
  I --> J
```
Note: worker (`autoreply_process_one`, backend.php:264) only evaluates the first 20 active rules per line — a 21st rule is silently dead with no UI warning.

## reports.php / analytics.php (read-only)

- `reports.php` scopes correctly by `sender_user_id` (FK, correct pattern).
- `analytics.php` (admin-only) pulls up to 300,001 full rows including `content` TEXT into PHP memory, then aggregates in PHP instead of SQL `GROUP BY` — real perf/memory cost per page load on a large shared table.

## Ticket thread / reply

```mermaid
flowchart TD
    A["GET/POST /tickets.php?id=N"] --> C["csrf_check()"]
    C --> D{"do=='reply'?"}
    D -->|yes| F{"ticket exists AND (admin OR owner)?"}
    F -->|yes| H["ticket_add_reply(): INSERT reply + UPDATE status,updated_at=NOW (fixed by commit 5c166d7)"]
    D -->|no| O{"do=='status'?"}
    O -->|yes| P{"is_admin()? (server-side gate, not just hidden UI)"}
    P -->|yes| Qs["ticket_set_status() *** no updated_at bump on repeat-same-status (residual gap, same class of bug as the one already fixed for replies) ***"]
```
Everything else here is solid: IDOR checks present on every mutation, CSRF present, all output escaped via `e()`/`nl2br(e())`.

## Contact form -> Telegram relay

```mermaid
flowchart TD
    A["Unauthenticated GET /contact.php"] --> C["POST, csrf_check()"]
    C --> H["telegram_send_message() -> curl POST api.telegram.org/bot{token}/sendMessage"]
    H --> K{"ok?"}
    K -->|no| L["error surfaced to user, token itself never leaked"]
    K -->|yes| M["success, no persistence anywhere (by design)"]
```
Gap: no rate limiting/CAPTCHA — a scripted client that fetches a CSRF token first can spam the configured Telegram chat freely.

## Public marketing pages (landing/pricing/guide/slides)

Admin CRUD (`pricing.php`, `guide-admin.php`, `slides.php`) each hand-roll a near-identical save/delete/toggle dispatch + list-table pattern — see duplication report. `slides.php`'s upload validator lacks the extension-fallback that `kyc_store_upload()` has, an inconsistency between two structurally-identical helpers.
