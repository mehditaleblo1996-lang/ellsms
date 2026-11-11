# P2P send waiting for confirmation — support alert and «ارسال خودکار نظیر به نظیر»

A P2P file («ارسال نظیر به نظیر», `/messages/p2p`) with more rows than `SMS_SYNC_MAX_RECIPIENTS`
(default 100) goes through the asynchronous import pipeline (`docs/large-import-architecture.md`):
it is analyzed, priced and loaded, and then waits at **«منتظر تأیید»** (`ready_for_confirmation`)
until the user presses **«ادامه و تأیید ارسال»** on `/import.php?id=…`. Two things happen at that
moment, once per import:

1. **Support-group alert.** If nothing confirms it automatically, the support group is told that a
   P2P send is waiting for confirmation, so support can follow up with the customer.
2. **«ارسال خودکار نظیر به نظیر» (per user).** A platform admin can turn this on for a user. That
   user's large P2P files are then confirmed automatically as soon as they are loaded and start
   sending without the confirmation step.

Smaller P2P files (up to `SMS_SYNC_MAX_RECIPIENTS` rows) never wait for confirmation and are not
affected.

| | |
|---|---|
| Where to turn it on | Platform Admin → «کاربران» → a user → the «ارسال خودکار نظیر به نظیر» box in the header card («بدون تأیید»), then «ثبت» |
| Who | platform admins only (`public/users.php` is admin-only) |
| Customer sees | a note above the upload form on `/messages/p2p` when it is on |
| Code | `app/P2pAutoSend.php`, called from `import_job_check_insert_completion()` in `app/import_worker.php` |
| Migration | `db/migrations/2026_11_15_p2p_auto_send.sql` |
| Tests | `tests/Unit/P2pAutoSendTest.php` |
| API | none — `/api/v1/bulk-jobs` sends never wait for a confirmation |

## The support group

The alert goes to the same Telegram chat that receives the «تماس با ما» tickets: the bot and chat of
the `telegram_bot_token` / `telegram_chat_id` settings (Settings in the panel, or `TELEGRAM_BOT_TOKEN`
/ `TELEGRAM_CHAT_ID`). Nothing new to configure. It reads:

```
⏳ ارسال نظیر به نظیر منتظر تأیید است
کاربر: <username>
عنوان: <title or file name>
خط ارسال: <originator>
تعداد پیام: <rows ready to send>
هزینه‌ی تخمینی: <estimated cost> ریال
واردسازی #<id>: <APP_URL>/import.php?id=<id>
```

The link is added when `APP_URL` is set. A failed Telegram call is logged
(`p2p.ready.alert_failed`) and never stops the import; a sent one logs `p2p.ready.alert_sent`.

## Auto-send

When the owner has «ارسال خودکار نظیر به نظیر», the worker calls `import_confirm_job()` on the owner's
behalf — exactly what «ادامه و تأیید ارسال» does: the cost is reserved from the wallet and the
organization quota, the staged bulk job becomes `pending` and the send worker starts it. The
confirmation is audited (`p2p.auto_confirmed`, `import_job=… bulk_job=…`) and no alert is sent.

If the confirmation fails (not enough wallet balance or quota), the import stays «منتظر تأیید» — the
user can still top up and confirm by hand — and the support group gets the alert with the heading
«⚠️ ارسال خودکار نظیر به نظیر انجام نشد — منتظر تأیید» and a «علت: …» line with the reason
(`p2p.auto_confirm_failed` is logged).

## Storage

`ellsms_meta.p2p_auto_send` (0/1), with `p2p_auto_send_set_at` (UTC) and `p2p_auto_send_set_by` (the
admin), shown under the box as «از … — admin». Every change is audited
(`user.p2p_auto_send_on` / `user.p2p_auto_send_off`, `user_id=…`). The flag is per user; it only
affects imports that become ready after it is changed. Before the migration nobody has auto-send and
the box is not shown; the support alert works without the migration.

## Exactly once

`import_job_check_insert_completion()` moves the import to `ready_for_confirmation` with
`… WHERE id = ? AND status = 'analyzing'` and runs the follow-up only when that UPDATE changed the row.
Two insert chunks finishing at the same moment therefore alert (or auto-confirm) once, and an import
cancelled while its last chunk was still running stays cancelled instead of being promoted.
