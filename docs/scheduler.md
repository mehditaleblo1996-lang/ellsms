# Scheduler — periodic jobs

The `scheduler` container (`cron/scheduler.php`, `app/Scheduler.php`) runs every maintenance job
that used to run only when someone typed its `make` target. Each job runs as its own
`php cron/<script>.php` process, so a job that fails cannot take the scheduler down, and each
script keeps its own locks: running the same job by hand alongside the scheduler is as safe as
before.

| Job | When | What |
|---|---|---|
| `payments-reconcile` | every 10 min | re-verifies payments whose callback never arrived or whose verify failed, and credits the ones ZarinPal completed |
| `invoices-expire` | every 15 min | marks issued invoices past their `due_at` expired (an invoice without one never expires; a late payment still marks it paid) |
| `delivery-refunds` | every 5 min | refunds undelivered messages of delivery-based-billing users that the status path itself missed (`docs/delivery-based-billing.md`); a no-op until an admin switches the service on for someone |
| `subscription-lifecycle` | every 15 min | trial and grace expiry, period rollover, stale usage reservations (skips itself while `BILLING_ENABLED=0`) |
| `bulk-archive` | every minute | advances approved bulk-archive runs one bounded pass |
| `message-parts` | every minute | fills the SMS part count (`parts`) into message rows written before it was stored — a bounded slice per run (at most 50 s); once caught up, only the rows added since the last run (`docs/message-parts.md`) |
| `voice-worker` | every minute | sends due scheduled voice messages, polls ZarinCall for answered / not answered calls and settles their cost (`docs/voice-messages.md`) |
| `lba-worker` | every minute | polls LBA audiences the provider is still building, follows one-off LBA sends and charges / settles periodic ones (`docs/lba.md`) |
| `shared-service` | every minute | sends approved and promotional-window messages of the shared sending service and expires held messages nobody reviewed (`docs/shared-service.md`) |
| `ai-balance` | every 15 min | reads each AI provider's credit (OpenRouter's API, or the estimate from recorded top-ups minus spend), the days it lasts at the last 7 days' spend, and raises / resolves the `ai_balance:<code>` alert (`docs/ai-engines.md` §Provider balance and alerts); a no-op until `2026_11_09_ai_provider_balance.sql` is applied |
| `backup` | daily `BACKUP_DAILY_AT` (03:30) | backup without message rows → retention prune → off-server copy (`docs/backup-and-disaster-recovery.md` §1, §17) |
| `usage-reconcile` | daily 04:10 | rebuilds usage counters' `reserved` from the live reservations |
| `idempotency-prune` | daily 04:20 | deletes completed Idempotency-Key records past their TTL |
| `webhook-prune` | daily 04:30 | deletes finished webhook deliveries/events past retention (never dead letters) |
| `db-cleanup` | daily 04:40 | deletes expired 2FA codes and stale rate-limit rows |
| `wallet-audit` | daily 05:00 | fails if `user_.currentcredit` no longer matches a wallet — i.e. something besides ELLSMS changed a balance |

Times are server local time (Asia/Tehran in the image). The last start of every job is kept in
`ellsms_scheduler_runs`: a restart neither runs everything again nor skips a daily job whose time
passed while the scheduler was down (it runs as soon as the scheduler is back). On the very first
start every job runs once.

**Failures.** A job fails when it exits non-zero or runs past its timeout (it is then killed). The
failure is logged (`scheduler.job.failed`, with the end of its output), counted
(`scheduler.job.runs{result="failed"}`) and raised as a warning alert (Telegram/email, see the
alerting settings); the job's next success resolves the alert. `every` jobs simply retry at their
next turn; a failed daily job waits for its next day, or `make scheduler-run JOB=<name>`.

**Not scheduler jobs, but periodic too:** the `worker` container runs `tg_settle_pass()` on every loop
(charges, finishes and releases Telegram campaigns/lookups, emits `telegram.message.*` webhooks, fails
stuck or undeliverable Telegram messages), and the separate `tg-worker` container polls its queue every
second (`docs/telegram-user-service.md`). The `ai-worker` container answers AI secretary jobs every
`AI_WORKER_POLL_INTERVAL_SECONDS` (default 2) and prunes finished jobs past their retention about once
an hour (`docs/ai-secretary.md`); the `worker` container's auto-reply pass enqueues the SMS jobs. Each
`ai-worker` pass also turns Telegram messages the `tg-worker` stored into jobs, sends «پیام اول»
campaigns (`docs/ai-outreach.md`) and marks finished campaigns done.

**Maintenance mode** pauses the scheduler entirely, like the workers.

**Only one scheduler runs jobs** (MySQL named lock `ellsms_scheduler`); never scale the service.

```
make scheduler-status            # every job: cadence, last run, result, output of a failure
make scheduler-run JOB=backup    # run one job now, due or not
make scheduler-logs
```

`SCHEDULER_DISABLED_JOBS` (comma-separated job names) switches jobs off; `SCHEDULER_TICK_SECONDS`
(default 30) is how often it looks for due jobs.
