# Forwarding MO and delivery reports to a customer URL (per line)

Each line can call a customer's own `http://` or `https://` URL:

- **MO**: once for every message **received** on the line.
- **DLR**: once for the **final delivery state** of every message **sent** from the line.

This is the same feature as Vesal's per-number `receiveUrl` / `deliveryUrl` (`MOForwardJob`,
`DeliveryForwardJob`). The templates use the same `${...}` placeholders, so a URL already set on a
Vesal number works here unchanged.

Page: **یکپارچه‌سازی → ارسال دریافتی و تحویل به URL** (`/number-forwards.php`, clean URL
`/developers/line-forwarding`). The admin **Numbers** page has a column with each line's status and
a link to this page.

## Who can set what

| Who | Can do |
|---|---|
| Customer | Set the MO and DLR URLs of the lines they **own**: assigned to them, to their organization, or their legacy line. This needs **«کاربران آدرس خطوط خود را تنظیم کنند»** to be on. They can also read the log for their own lines and re-send failed rows. A line that is only **shared** with them is send-only (`docs/shared-numbers.md`), so its forwarding is not theirs to set. |
| Platform admin | Set the URLs of **any** line, including a line typed in by hand (for example a legacy line). See every line's log. Change the service settings below. |

## Service settings (admin, «تنظیمات سرویس» tab)

These are stored in `ellsms_settings`. A running worker picks up a change within about 5 seconds.

| Setting | Default | Meaning |
|---|---|---|
| `number_forward_enabled` | on | Master switch. When off, nothing new is queued and the worker idles. The queue is kept and continues when the switch is turned back on. |
| `number_forward_user_edit` | on | Customers may set the URLs of their own lines. |
| `number_forward_allow_http` | on | Plain `http://` is accepted (Vesal allowed it). When turned off, saved `http://` URLs are no longer called. Their rows are recorded as failed with `https_required`. |
| `number_forward_timeout_seconds` | 10 | Time limit for one call (1–60). |
| `number_forward_max_attempts` | 5 | Attempts for a retryable failure before the row is marked `dead` (1–20). The default for every line; a line can set its own number (below). |
| `number_forward_retry_base_seconds` | 30 | Wait before the second attempt (5–3600). The wait doubles after each further attempt. |
| `number_forward_retry_max_seconds` | 1800 | The longest wait between two attempts (10–86400). |
| `number_forward_workers` | 4 | Worker processes running in parallel (1–32). The supervisor adds or stops processes within seconds of a change. |
| `number_forward_concurrency` | 20 | HTTP calls each worker process keeps in flight at once (1–100). |

Total capacity is `workers × concurrency` simultaneous calls (80 with the defaults). The settings tab
shows the resulting retry schedule, for example «تلاش ۲: پس از ۳۰ ثانیه، تلاش ۳: پس از ۱ دقیقه …».

**Per-line settings** (the line's edit form): the MO and DLR URLs and their switches, GET/POST and
the POST body format, strict certificate checking, and **«تعداد تلاش برای این خط»** — empty or 0
uses the service default, 1–20 overrides it for that line only (`ellsms_number_forwards.max_attempts`,
migration `2026_10_20_number_forward_line_attempts.sql`).
| `number_forward_retention_days` | 30 | Finished rows older than this are deleted from the log. |

## Placeholders

Values are URL-encoded the way Java's `URLEncoder` does it (a space becomes `+`), as in Vesal.

**MO**: `${originator}` (the sender, i.e. the customer's customer), `${destination}` (the line),
`${content}`, `${udh}`, `${sourcePort}`, `${destinationPort}`, `${id}` (the inbound message id),
`${received_at}`.

```
https://example.com/sms/receive?from=${originator}&to=${destination}&text=${content}
```

**DLR**:

| Placeholder | Value |
|---|---|
| `${id}` | Numeric id of the sent message |
| `${message_id}` | `attempt:N` or `bulk_item:N`, the same id the webhooks use |
| `${reference_id}` | The send's reference: the `client_message_id` you passed to `POST /api/v1/messages`, or the bulk job id for a bulk send |
| `${status}` | Vesal `DestinationStatus` ordinal: `2` delivered, `3` not delivered, `7` expired, `8` rejected, `4` unknown |
| `${state}` | `delivered` / `failed` / `expired` / `rejected` |
| `${dl_time}` | Delivery time in epoch milliseconds, as in Vesal |
| `${delivered_at}` | Delivery time as `YYYY-MM-DD HH:MM:SS` |
| `${part}` | Always `1`: ELLSMS keeps one final state per message |
| `${originator}`, `${destination}` | The sending line and the recipient |
| `${provider_message_id}` | The provider's id for the message |

```
https://example.com/sms/delivery?id=${id}&status=${status}&dl_time=${dl_time}
```

Vesal always appended `&dl_time=${dl_time}` to a DLR URL. ELLSMS does not do this, so put
`${dl_time}` in the URL if the receiving script needs it.

**Rules for templates.** A placeholder the kind does not have is refused when the URL is saved. The
host must be written out literally, not built from a placeholder. A `GET` template with **no**
placeholders gets every value appended as query parameters. With `POST` the URL is rendered the same
way, and every value is also sent in the body, as a form or as JSON.

Every call carries these headers:

- `X-ELLSMS-Forward-Kind: mo|dlr`
- `X-ELLSMS-Forward-Id: <queue id>`. The id stays the same across retries, so it can be used to
  drop duplicates.

The calls are not signed. To check that a call came from ELLSMS, put a secret token in the URL.

## Delivery semantics

```
inbound scan (run_autoreply_pass)        ─┐
final status write (gateway_status_record) ─┤→ ellsms_number_forward_queue → number-forward-worker → customer URL
```

### Queueing

- **Exactly once.** `UNIQUE(kind, source_key)` on the queue makes each message's row unique:
  - The MO row is `inbound:<id>`. It is safe even though the inbound scan is at-least-once.
  - The DLR row is queued only by the one status `UPDATE` that moves the message into a final state.
    This is the same place the `message.delivered` / `message.undelivered` webhooks fire.
- **Delivery reports from every source.** DLRs from status polling and from SMPP `deliver_sm` both go
  through `gateway_status_record()`, so both are covered. DLRs exist only in gateway mode
  (`SMS_GATEWAY_TRANSPORT=1`).
- **MO from both inbound stores.** MO forwarding covers both stores: the backend's `inbound_message`
  and ELLSMS's own receive connectors.
- **No per-message cost when unused.** When no line forwards a kind, queueing it costs no query. The
  list of active lines is cached for 30 seconds.

### Results

| Response | Result |
|---|---|
| `2xx` | Success |
| Timeout, connection failure, `5xx`, `408`, `425`, `429` | Retried with the admin's backoff (30 s, 1 m, 2 m … capped at the maximum wait, with the defaults) up to the line's attempt count, then marked **dead** |
| Any other `4xx` | Permanent failure (**failed**) |

- **URL changes.** The URL is rendered from the line's **current** template at delivery time, so
  fixing a broken URL also fixes the rows still waiting to be retried.
- **Forwarding turned off.** If a line's forwarding is switched off or deleted while rows are still
  queued, those rows become **cancelled** and are not called.
- **Re-sending.** Failed, dead and cancelled rows can be re-sent from the log, one row at a time or
  all rows of a line.

### Certificates

By default the destination's https certificate is **not** verified, as in Vesal (which trusted every
certificate). A self-signed, expired or wrong-host certificate does not stop the forward; the
connection is still encrypted. A line can turn on strict verification with **«بررسی گواهی SSL مقصد»**
(`ellsms_number_forwards.tls_verify`, migration `2026_10_19_number_forward_tls_verify.sql`). With it
on, a bad certificate is recorded as `tls_error` and retried like a connection failure. The address
check below does not depend on the certificate, so it applies either way.

### Security

The rules are the same as for webhooks (`docs/webhooks.md`), with one difference: the address that
is checked is pinned for the connection with `CURLOPT_RESOLVE`, so DNS rebinding cannot change it
between the check and the call.

- Private, loopback, link-local, metadata and reserved addresses are refused.
- Redirects are never followed.
- Credentials written in the URL are refused.
- At most 4 KB of the response is read, and 1 KB is kept.

## Operations

- **Service.** `number-forward-worker` in `docker-compose.yml` runs `php cron/number-forward-worker.php`.
  It runs in its own container, so a slow customer URL can never delay sends, auto-replies or status
  polls.
- **Parallelism.** The process is a supervisor. It keeps `number_forward_workers` child processes
  running (`pcntl_fork`), restarts a child that dies, and adds or stops children within about 5 seconds
  of a settings change. Each child keeps up to `number_forward_concurrency` calls in flight in a
  **sliding window**: the moment one call finishes the next starts, so a slow URL holds one slot for at
  most the timeout and never stalls the calls queued behind it. Rows are claimed atomically, so
  children (and extra copies of the service) never send the same row twice; a dead child's lease
  expires and its rows are picked up again. The supervisor closes its database connection before every
  fork, so no connection is shared between processes. Without pcntl, or with `--once`, it runs as a
  single worker.
- **One pass.** `make number-forward-worker-once`, or `php cron/number-forward-worker.php --once`.
- **Env.**
  - `NUMBER_FORWARD_POLL_INTERVAL_SECONDS` (3): how long an idle worker sleeps. A pass that comes
    back with a full batch runs again at once.
  - `NUMBER_FORWARD_BATCH_SIZE` (100): rows claimed per pass.
- **Pruning.** The worker deletes old finished rows about once an hour.
- **Logs and metrics.**
  - Log events: `number_forward.delivered|pending|failed|dead|cancelled`.
  - Metrics: `number_forward.enqueued{kind}`, `number_forward.attempt{kind,status}`.
- **Schema.** `db/migrations/2026_10_18_number_forwarding.sql` creates two tables:
  - `ellsms_number_forwards`: one row per line.
  - `ellsms_number_forward_queue`: the outbox and its log.

  Until the migration is applied, the feature stays silent and the page says so.

## Code

| Part | Location |
|---|---|
| Library | `app/NumberForwarding.php` (settings, per-line config, templates, address check, queueing, HTTP, worker pass) |
| Hooks | `run_autoreply_pass()` in `app/backend.php` and `gateway_status_record()` in `app/Sms/GatewayStatus.php` |
| Page | `public/number-forwards.php` |
| Tests | `tests/Unit/NumberForwardingTemplateTest.php`, `tests/Integration/NumberForwardingTest.php` (real worker against a real receiver), `tests/Integration/NumberForwardsHttpTest.php` (page permissions) |
