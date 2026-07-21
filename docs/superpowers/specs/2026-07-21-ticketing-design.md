# ELLSMS ticketing system

Status: approved, ready for implementation planning
Date: 2026-07-21

## Context

ELLSMS already has a public, unauthenticated "تماس با ما" contact form
(`public/contact.php`) that relays submissions straight to a Telegram
bot/chat via `app/telegram.php`'s `telegram_send_message()` — there is no
ticket table, no persistence, no admin list; Telegram itself is the inbox.
That page stays exactly as-is.

This spec adds a **separate, new, authenticated** support-ticket system
inside the panel: logged-in panel users open tickets, admins see and reply
to all of them, users see and reply to their own, and Telegram is notified
on ticket creation and on user replies. The two systems (public contact
form and in-panel tickets) are independent — they don't share tables or
code beyond the existing Telegram relay helper.

## Data model

Two new tables in `db/ellsms_extra.sql`, following this file's existing
`ellsms_*` conventions (guarded, safe-to-rerun `CREATE TABLE IF NOT
EXISTS`, `InnoDB`, `utf8mb4`):

```sql
CREATE TABLE IF NOT EXISTS ellsms_tickets (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    BIGINT NOT NULL,               -- ticket owner (= user_.id)
  subject    VARCHAR(160) NOT NULL,
  status     ENUM('open','answered','closed') NOT NULL DEFAULT 'open',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY (user_id), KEY (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_ticket_replies (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id      INT UNSIGNED NOT NULL,
  user_id        BIGINT NOT NULL,            -- author (owner or admin — both are user_ rows)
  is_admin_reply TINYINT(1) NOT NULL DEFAULT 0, -- snapshot at post time, not a live role join
  body           TEXT NOT NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (ticket_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

The ticket's opening message is **not** a column on `ellsms_tickets` — it's
simply the first row in `ellsms_ticket_replies` (`is_admin_reply = 0`),
inserted in the same request as the `ellsms_tickets` row. Rendering a
thread is always "every reply for this `ticket_id`, oldest first" — no
special-casing the first message anywhere in the code.

## Pages and navigation

One file, `public/tickets.php`, following the exact role-adapts pattern
`public/reports.php` already uses (`is_admin() ? all rows : own rows
only`) rather than splitting into separate user/admin files:

- **No `id` query param** — list view + "new ticket" form.
  - Admin: every ticket, newest-`updated_at`-first, filterable by status
    (tabs or a dropdown: all/open/answered/closed), paginated the same
    way `reports.php`/`inbox.php` are (`$per = 50`).
  - Regular user: only their own tickets, same sort, no admin-only filter
    controls.
  - "New ticket" form: `subject` + `message` (the opening reply). On
    submit: insert `ellsms_tickets` (status `open`) + the first
    `ellsms_ticket_replies` row in one request, fire the Telegram
    notification (see below), redirect to `?id={newId}`.
- **`?id=N`** — thread view + reply box.
  - Regular user may only open a ticket they own (404/redirect otherwise,
    matching the access-check style `public/kyc-photo.php` already uses
    for "does this belong to the viewer or are they admin").
  - Admin may open any ticket.
  - Reply box: posts a new `ellsms_ticket_replies` row as the current
    user, `is_admin_reply` set from `is_admin()`, bumps `ellsms_tickets`.
  - Admin-only: an explicit status control (buttons/dropdown for
    open/answered/closed) — a separate action from replying, per the
    lifecycle rules below.

Navigation: one new entry in the base `$nav` array in
`app/views/header.php` (visible to every logged-in user, matching how
`reports`/`inbox` already work — not admin-only):

```php
'tickets' => ['/tickets.php', 'پشتیبانی', '🎫'],
```

## Status lifecycle

Fully automatic except for admin-initiated closing — no manual state
machine for either side to learn:

- New ticket → `open`
- A **user** reply → `open` (this is also how a `closed` ticket gets
  reopened — the user simply replies again)
- An **admin** reply → `answered`
- `closed` is set **only** by an explicit admin action (the status
  control), never as a side effect of replying

## Telegram notifications

Reuses the existing `telegram_send_message()` / `telegram_bot_token()` /
`telegram_chat_id()` from `app/telegram.php` — same bot/chat configured in
Settings → تماس با ما, no new settings, no new `.env` keys. Two trigger
points, both in `public/tickets.php`:

- **Ticket created**: `"🎫 تیکت جدید #{id} از {username}\nموضوع:
  {subject}\n{body}"` (truncate `body` the same way `analytics.php`
  truncates long strings if it's long, e.g. `mb_strimwidth`).
- **User reply added** (not admin replies — admins already know, they're
  the ones reading the panel): `"💬 پاسخ جدید روی تیکت #{id} از
  {username}:\n{body}"`.

Telegram delivery failure must **not** block the ticket/reply from being
saved — the DB write is the source of truth; the notification is
best-effort, same spirit as `contact.php`'s existing failure handling
(there, Telegram failure blocks the form since Telegram IS the only
storage — here it must NOT block, since the ticket persists regardless).
If `telegram_send_message()` returns `false`, log via `error_log()` and
continue; don't surface a Telegram error to the ticket submitter.

## Access control

- Any panel-access user (the same population that can log into ELLSMS at
  all) can create tickets and can view/reply to their own.
- Only admins (`is_admin()`) can view/reply to tickets owned by other
  users, and only admins can change ticket status.
- CSRF protection (`csrf_check()`/`csrf_field()`) on both the
  ticket-creation form and the reply form, matching every other form in
  this codebase.

## Out of scope (not requested, not included)

- File attachments on tickets/replies.
- Read/unread state or "seen" indicators.
- Ticket categories, priority levels, or assignment to a specific admin.
- Any change to the existing public `contact.php`/`app/telegram.php`
  contact-form flow — it is untouched by this spec.

## Testing

No automated test framework exists in this repo (intentional, matches
project convention) — verification is manual against a real deployment:

- Create a ticket as a regular user; confirm the Telegram message arrives
  and the ticket appears in that user's own list with status `open`.
- Confirm the SAME ticket appears in the admin's list (and does NOT
  appear in a *different* regular user's list).
- Reply as the admin; confirm status flips to `answered`, and confirm NO
  Telegram message fires for this admin reply.
- Reply as the owning user; confirm status flips back to `open` and a
  Telegram message fires.
- As admin, explicitly close the ticket; confirm status is `closed` and
  stays `closed` until the user replies again.
- Attempt to open another user's ticket by guessing its `?id=` as a
  non-admin; confirm access is denied.
- Submit a ticket/reply with Telegram deliberately misconfigured (bad
  bot token); confirm the ticket/reply still saves successfully and no
  error is shown to the user beyond what the DB write itself would show.
