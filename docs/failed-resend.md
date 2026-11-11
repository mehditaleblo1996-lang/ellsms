# Resend failed rows (ارسال مجدد ناموفق‌ها)

On a finished bulk job's page (`/messages/bulk-jobs?id=…`, «جزئیات» from «ارسال نظیر به نظیر») the
button «ارسال مجدد ناموفق‌ها (N پیامک)» queues that job's failed rows again as a **new p2p job**
(«نظیر به نظیر»). Each row keeps its own number and its own text; the new job uses the same sender
line and message class as the original. The original job and its rows are never changed.

Code: `app/FailedResend.php` (logic), `public/bulk-job.php` (button, POST `do=resend_failed`,
history). Test: `tests/Integration/FailedResendTest.php`.

## Which rows

The same rows the page counts as «ناموفق», **except cancelled rows** (`status='cancelled'` — the
user stopped those on purpose):

- `ellsms_bulk_items.status = 'failed'` (the send itself failed), and
- for a viewer who sees delivery states (admins, customers on delivery-based billing —
  `delivery_status_visible()`): rows whose `delivery_status` is `failed`, `rejected` or `expired`.
  For any other customer those rows read «ارسال‌شده» and are **not** resent.

A row whose delivery is `delivered` is never resent.

## When it is offered

- The job type is `p2p`, `gradual` or `smart` (plain number + text rows). Aimed sends with masked
  recipients (`recipients_masked`, «ارسال هدفمند») are never offered: copying them into an unmasked job
  would reveal the platform's numbers.
- The job is finished: status `done`, `cancelled` or `failed` (not `staged`, `pending`, `processing`).
- At least one row qualifies.
- The viewer may send bulk: an admin, or a member with `messages.send` whose plan has `bulk_send`.

## Who pays

The new job always belongs to the **original job's owner** and is queued with `bulk_queue_job()`, so
it is priced, quota-checked and wallet-reserved exactly like a p2p upload, in one transaction — or
refused (insufficient credit, quota, prohibited words, line no longer allowed) with nothing queued.

- A customer or an organization colleague resends on their own account (same as uploading a file).
- A **platform admin** resending a customer's job queues it **on the customer's account**: the
  customer is charged and sees the job in their own panel. The owner's account, organization status,
  subscription and `bulk_send` entitlement are re-checked first. The admin is recorded as the actor.

The sender line must still be usable by the account (`can_use_originator()`).

## Limits and safety

- `FAILED_RESEND_MAX_ITEMS` (default 10000): a job with more qualifying rows is refused with a
  message — the rows are priced and inserted in one web request. Export them from the bulk report and
  upload them as a file instead.
- Two clicks or two tabs cannot queue the same failures twice: a MySQL named lock per source job, and
  a second resend of the same job within 60 seconds is refused. The confirm dialog warns when the job's
  failures were already resent before.

## Log

- `ellsms_failed_resends` (migration **`db/migrations/2026_11_15_failed_resends.sql`**): one row per
  resend — `source_job_id`, `new_job_id`, `actor_user_id`, `owner_user_id`, `item_count`,
  `created_at`. The source job's page lists its resends with links; the new job's page links back
  («این ارسال، ارسال مجدد ناموفق‌های Job #… است»). Without the migration the resend still works;
  only this history and the 60-second guard are missing.
- Audit log action `bulk.failed_resend` (`job #A -> job #B: N rows`, plus `(as user #X)` when an admin
  resent on a customer's account), and the structured log event `bulk.failed_resend`.
- The new job is titled `ارسال مجدد ناموفق‌های #<source id> - <source title>` and appears in the p2p
  list and every report like any other p2p job.

No API route, scheduler job or worker is added: the new job is sent by the existing bulk worker.
