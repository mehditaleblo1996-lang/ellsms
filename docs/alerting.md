# Unified alert/incident subsystem (issue #15)

## One subsystem, not parallel logic

`app/Alerting/AlertManager.php` is the single place any part of this codebase raises an alert.
Issue #10/#16 originally gave `app/Sms/ProviderHealth.php` its own minimal, direct Telegram call on
a DOWN transition (`provider_health_alert()`); this issue upgrades that call site **in place** to
route through the shared incident model instead of duplicating it — it still reuses the exact same,
already-tested Telegram sender (`app/telegram.php`'s `telegram_send_message()`), and reuses the
existing email sender (`app/NotificationCenter.php`'s `notification_send_email()`), rather than
adding a third implementation of either.

## Model

`ellsms_alert_incidents` — one row per incident. `alert_key` is a bounded, code-defined identifier
(e.g. `provider_down:legacy_backend`) — see `docs/observability-cardinality.md`'s same rule, since
this key also becomes a Prometheus label if a future metric ever needs it.

- **Fire** (`AlertManager::fire($alertKey, $severity, $title, $message, $context)`): if no
  open/acknowledged incident exists for this key, creates one and dispatches immediately. If one
  already exists, updates it (message, fire_count, last_fired_at) and re-dispatches only if the
  incident is **not** acknowledged and its repeat interval has elapsed — this is both the dedup and
  the repeat mechanism in one place.
- **Acknowledge** (`AlertManager::acknowledge($incidentId, $actorUserId)`): stops repeats. The
  incident stays `open`→`acknowledged`, not resolved — an admin who has seen and is working on a
  problem should stop being paged, but the incident must not silently disappear from the active
  list either. Audited (`acknowledged_by`, `acknowledged_at`, and an `alert.acknowledged` log line).
- **Recover** (`AlertManager::recover($alertKey, $message)`): resolves the incident and sends one
  recovery notification. A no-op if nothing is open/acknowledged for that key. A new `fire()` for
  the same key after this creates a brand-new incident row — history is never overwritten.

## Severities and repeat intervals

`warning` (default 1800s), `critical` (default 300s), `emergency` (default 120s). All three are
**admin-configurable from `/admin/alerts`** (`ellsms_settings` rows
`ALERT_REPEAT_SECONDS_WARNING`/`_CRITICAL`/`_EMERGENCY`, editable via a form on that page), never
hardcoded — the same `setting(key, env(key, default))` precedence `app/telegram.php`'s own
`telegram_bot_token()`/`telegram_chat_id()` already use, so the env var still works as a
per-deployment default wherever no admin setting has been saved yet.
`tests/Integration/AlertManagerTest::testDbConfiguredRepeatIntervalTakesEffectInAFreshProcess`
proves a value saved through `set_setting()` (what the admin form calls) is actually read back by a
separate process, not just by the same request that saved it.

(2026-09-01 note, superseded above: an earlier version of this file kept these env-only, reasoning
that `setting()`'s process-wide cache would make a DB-stored value both untestable and, in a
long-running worker, unable to take effect without a restart. That reasoning conflated a testing
inconvenience with a real production limitation — this is the exact same caching behavior every
other `setting()`-backed value in this codebase already accepts, and the 2026-09-02 final audit
found "env-only" does not actually satisfy the issue's own "admin configurable" acceptance
criterion. Fixed by switching precedence and adding the subprocess test above, rather than leaving
this open as a documented gap.)

## Channels

Both are attempted independently on every dispatch — one failing must never suppress the other:

- **Telegram**: `telegram_send_message()` (`app/telegram.php`), configured via the same
  `telegram_bot_token`/`telegram_chat_id` settings the contact-form relay already uses.
- **Email**: `notification_send_email()` (`app/NotificationCenter.php`), sent to
  `alert_email_recipient` (env `ALERT_EMAIL_RECIPIENT` or the `ellsms_settings` row of the same
  name — an address, unlike a repeat-interval tuning knob, that only needs to be right once per
  admin session where it's read, not toggled mid-test).

Every attempt — success or failure — is recorded in `ellsms_alert_dispatch_log` (`incident_id`,
`channel`, `outcome`, a short `detail` string) so "did the alert actually reach anyone" is always
answerable, and a failed channel is visible (`Logger::warning('alert.dispatch_failed', ...)`) rather
than silently swallowed.

## Admin UI

`/admin/alerts` (`public/alerts.php`) — active incidents (severity, title, status, first/last fired,
fire count, an "acknowledge" button for open ones), recent resolved history, and a settings form to
change the three repeat intervals and the alert email recipient without a redeploy. Platform-admin
only (`require_admin()`), same guard every other global-configuration page uses.

## Metrics

`app/Observability/PrometheusExporter.php` exports, all with bounded labels only (severity: 3
values; channel/outcome: 2 values each, both DB `ENUM` columns):

- `ellsms_alert_incidents_active{severity}` — gauge, currently open/acknowledged
- `ellsms_alert_incidents_total{severity}` — counter, cumulative ever raised
- `ellsms_alert_incidents_recovered_total{severity}` — counter, cumulative resolved
- `ellsms_alert_incidents_acknowledged_total{severity}` — counter, cumulative ever acknowledged
- `ellsms_alert_dispatch_total{channel,outcome}` — counter, cumulative dispatch attempts

## Tests

`tests/Integration/AlertManagerTest.php` covers the full required matrix: first fire, dedup (a
second fire within the repeat interval doesn't re-dispatch), repeat (once the interval elapses),
acknowledge (stops repeats), no-repeat-after-ack (holds even past the interval), recovery
(resolves + one recovery notice, no-op if nothing was open), a resolved incident's key being
reusable for a new occurrence, Telegram failure not suppressing email, email failure not
suppressing Telegram, both channels failing without throwing, admin-configurable repeat intervals
(env-based, including the `0 = repeat every fire` edge case and negative-value fallback), active-
incident severity ordering, and rejecting an unknown severity. `tests/Integration/ProviderHealthTest.php`
and `ProviderHealthActiveCheckTest.php` continue to pass unchanged, proving the in-place upgrade of
`provider_health_alert()` didn't disturb issue #16's own acceptance criteria.

Test-only sender overrides (`AlertManager::setTelegramSenderForTesting()` /
`setEmailSenderForTesting()`) exist solely so success and mixed-failure scenarios are testable
without a real Telegram bot or working MTA in the test environment; production code never touches
them.
