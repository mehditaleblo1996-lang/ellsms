# SMS gateway connectors

How ELLSMS talks to an SMS provider, and how an administrator configures a new one without a code
change or a deploy.

Before this existed there was exactly one way to send an SMS: a hard-coded POST to the backend
platform's `/api/messages/send`, with a hard-coded body shape, hard-coded timeouts, and hard-coded
error handling (`app/Backend/ApiClient.php`). Adding a second provider meant writing a second client.
This feature replaces that with a configured **connector**: an endpoint, a set of parameters, an
authentication scheme, and a mapping that says how to read the provider's answer.

The legacy client is still there and still the default. Nothing routes through a gateway until an
operator turns it on.

---

## The two properties everything else follows from

**1. Configuration is data, never code.** There is no expression language, no scripting hook, no
`eval()`, no shell. A parameter value is one of seven bounded kinds — a literal, an allowlisted
variable name, a `{{variable}}` template, a secret reference, an allowlisted environment variable, a
timestamp, or a UUID. An administrator who pastes PHP into a parameter gets a string containing PHP,
or a validation failure. `make sms-gateway-integrity-check` tokenises the engine files and fails if a
dynamic-execution construct ever appears in one.

**2. Everything expensive happens once.** Reading five configuration tables, decrypting secrets,
validating placeholders, compiling paths and mappings — all of it happens once per
`(gateway_id, config_version)` per process. Sending a message picks precompiled parameters out of
memory, substitutes context values, and writes a socket. The performance test asserts this with
counters: 1000 sends produce exactly **1** compile, **1** config load, **1** secret decrypt.

---

## Model

| Table | Holds |
|---|---|
| `ellsms_sms_gateways` | the gateway itself: code, status, send mode, `config_version`, default flag |
| `ellsms_sms_gateway_send_connectors` | endpoint, method, timeouts, auth, success rule, response/error/batch mappings |
| `ellsms_sms_gateway_status_connectors` | the optional delivery-status API and its polling limits |
| `ellsms_sms_gateway_parameters` | headers/query/body parameters, scoped to gateway, route, or operator |
| `ellsms_sms_gateway_secrets` | AES-256-GCM ciphertext; never a plaintext credential |
| `ellsms_sms_gateway_operators` | which operators a gateway carries (many-to-many) |
| `ellsms_sms_gateway_config_audit` | append-only history of runtime-relevant changes |

`ellsms_sms_routes.gateway_id` is what connects the two halves: pricing already decides which
**route** a sender uses, and the route now also names which gateway carries it. There is deliberately
no automatic selection — no cheapest-gateway, no health-based switching, no failover. Route selection
is explicit and so is gateway selection.

**No generated columns anywhere.** `default_slot` and `active_slot` are ordinary application-maintained
columns behind a UNIQUE index, for the reason documented in
[td-070-restore-safety-closure.md](td-070-restore-safety-closure.md): mariadb's `mysqldump` writes
generated columns out as data, and the resulting dump cannot be restored.

### Resolution, end to end

```
sender + message type ─► route (pricing, explicit assignment)
                             │
                             └─► gateway_id  (or the configured default)
                                     │
recipient ─► operator (prefix) ──────┼─► compiled connector (cached by config_version)
                                     │
                                     └─► parameters: gateway < route < operator
                                             │
                                             └─► request ─► provider
```

Parameter precedence is fixed and total. A later scope replaces an earlier one for the same
location+key, so an operator-scoped value is always the final word. That is the whole rule; there is
no inheritance tree to reason about.

---

## Send modes

**`per_message`** — one request per destination. A failure on one destination does not abandon the
rest; the result reports which destinations were accepted.

**`batch`** — destinations travel together in one request, and the provider answers with one row per
destination. `batch_mapping_json` says where the rows are and which keys identify the destination,
its outcome, and its provider message id:

```json
{"rows_path": "", "destination_key": "destination", "status_key": "status",
 "success_values": ["sent"], "message_id_key": "id"}
```

Batch mode exists because the existing integration is a batch gateway. A per-message-only model would
have silently misread its response and reported five sends where the provider accepted three.

### Per-recipient operator context, and how a batch is partitioned

**Every recipient gets its own operator context.** The operator is resolved per destination and
selects that destination's precompiled override set; nothing about recipient #1 can reach recipient
#2.

A batch is therefore partitioned before it is sent. The grouping key is derived from what actually
changes the request:

| | |
|---|---|
| gateway | different gateway, different request |
| `config_version` | a configuration change invalidates the grouping with the connector |
| route | route-scoped overrides |
| **effective parameter signature** | a hash of the *merged, resolved-scope* parameter descriptors — key, location, value type, literal value, data type |
| sender, message type | both materially alter the request |
| the destination itself | only when a parameter reads a per-recipient variable (see below) |

**Grouping is by effective configuration, not by operator identity.** This distinction is the whole
design. A gateway with no operator-specific overrides produces the *same* signature for MCI, MTN and
Rightel, so a mixed batch stays one request — which is what keeps the migrated legacy gateway's
byte-level parity intact. A gateway that *does* have operator overrides is split into one request per
distinct configuration:

```
100 recipients          60 MCI, 30 MTN, 10 Rightel
  with operator overrides   ->  3 requests   (one per configuration)
  without them              ->  1 request    (one configuration)
```

Secrets never enter the key: a secret contributes its *key name*, never its value, so a grouping key
is safe to log.

**Per-recipient variables force a split.** `recipient` and `operator_code` have one value per
recipient, and a single request carries one value per parameter — so a parameter that reads either of
them cannot be batched at all, and each destination becomes its own request. Detected from the
compiled parameter set, not guessed.

**The partitioning costs no extra configuration work.** Merged parameter sets are memoized per
(gateway, config_version, connector, route, operator), so a 1000-recipient send spanning three
operators performs three merges and still exactly one config load, one compile, and one secret
decrypt. Operator resolution is an in-memory longest-prefix match over the pricing engine's TTL-cached
prefix table — no query per recipient.

**A destination whose operator this gateway does not carry is refused individually**, not by failing
the whole send: the other destinations are perfectly deliverable, and partial success is already a
modelled outcome everywhere else in this system.

---

## Reading the provider's answer

**Success rule** — a bounded, declarative rule, not an interpreter. Three operators only: `equals`,
`in`, `exists`.

```json
{"http": {"min": 200, "max": 299}, "require_json": true,
 "rules": [{"source": "body", "path": "status", "operator": "in", "values": ["OK", "SUCCESS"]}]}
```

**Response mapping** — restricted dot-paths (`data.messageId`, `result.0.id`) into the decoded body.
Dotted keys and numeric indices, nothing else: no wildcards, no filters, no recursion. Those are
where JSONPath implementations grow evaluation engines.

**Error mapping** — a provider error code to one of the **finite** internal error classes
(`BackendUnavailable`, `BackendTimeout`, `BackendUnauthorized`, `BackendRejected`, `BackendConflict`,
`BackendInvalidResponse`, `BackendPermanentFailure`). Configuration can select from that set and
cannot extend it, because that set is what decides whether the worker retries — and "retryable"
written into a config field is how a permanent auth failure becomes an infinite retry storm.

Anything unmapped falls back to the same status-code rules `backend_api_request()` has always used,
which is why the migrated legacy gateway classifies failures identically.

---

## Delivery status

Optional. A gateway with no status connector simply never has its messages polled, and their delivery
state stays whatever the send established.

`cron/sms-status-poll.php` runs one bounded pass: it claims due rows, groups them by gateway (so each
connector compiles at most once for the pass), and asks each gateway about its own messages.

**Two sources, one poller.** A bulk send carries its provider id on its own `ellsms_bulk_items` row; a
direct send, schedule or auto-reply carries it on an `ellsms_message_attempts` row (below). Both are
polled by the same code, so the two cannot drift into different delivery-tracking behaviours.

**Gateway affinity is a hard rule.** The connector comes from the `gateway_id` recorded on the row —
never from re-resolving the route, never from the default gateway. A provider message id is meaningful
only to the provider that issued it, so asking a different gateway would at best return nothing and at
worst match somebody else's message. This holds even when the route has since been re-pointed at
another gateway, which is exactly when it matters.

### Direct sends: where the provider id lives

`ellsms_message_attempts` was already the ELLSMS-owned record of what happened to a send at the
transport level (Phase 8, Invariant E: `outbound_message` is backend-owned and ELLSMS must never
fabricate rows in it). It recorded only failures, because a success was always a real
`outbound_message` row written by the backend. With a configured gateway that is no longer true — the
gateway answers ELLSMS directly, and nothing else holds the resulting provider id.

So accepted sends are now recorded there too, **one row per destination**, carrying `gateway_id`,
`gateway_config_version`, `route_id`, `operator_id`, `destination`, `provider_message_id`,
`request_id`, and the delivery-status columns. No column was added to any backend-owned table, and no
second message-history system exists.

Bounded by construction:

- written **only** when the send went through a gateway *and* the provider returned an id — a row with
  no provider id can never be polled, so writing one would add volume and answer nothing;
- the legacy transport writes nothing here, so its behaviour is completely unchanged;
- `(gateway_id, provider_message_id)` is unique via an application-maintained slot column, so a
  retried worker pass cannot create a second delivery record for one message;
- bulk items are excluded — they already have their own durable row, and a second record would make
  the poller track one message twice.


- **Claiming** uses `delivery_checked_at` as its own lease — a row is claimable only if it has not
  been checked within the poll interval, and the claim restates that condition in its `WHERE`, making
  it a genuine compare-and-swap. Two workers cannot poll the same row.
- **Monotonic by construction.** A terminal state (`delivered`, `failed`, `rejected`, `expired`) is
  never overwritten. Providers re-report and polls arrive out of order; a `delivered` row silently
  reverting to `sent` would make the delivery report untrustworthy in a way nobody could detect. The
  guard is enforced twice — in PHP and again in the `UPDATE`'s `WHERE`.
- **An unmapped provider status becomes `unknown`, never `delivered`.** Reporting an undelivered
  message as delivered is the one error here with real-world consequences.
- Three limits stop a provider that never returns a terminal state from being polled forever:
  `poll_initial_delay_seconds`, `poll_max_attempts`, `poll_max_age_seconds`.

Polling was chosen over a provider webhook because a callback would need a public authenticated
endpoint per gateway plus a story for replay, ordering and spoofing. A webhook receiver can be added
later and would write through the same `gateway_status_record()`, inheriting the monotonicity
guarantee.

---

## Secrets

A gateway credential can send messages at the customer's expense, so:

- **AES-256-GCM**, authenticated — tampering with a stored ciphertext is detected, not silently
  decrypted to garbage.
- The key is derived with HKDF and a purpose label from `SMS_GATEWAY_MASTER_KEY` (minimum 32
  characters; a shorter one is refused loudly rather than silently weakening the key).
- **The master key is never stored in the database**, so it is never in a backup. Restoring a
  database onto a host without the same key yields secrets that cannot be decrypted. That is the
  intended behaviour — see [backup-and-disaster-recovery.md](backup-and-disaster-recovery.md).
- Each ciphertext carries a short **key fingerprint**. After a restore onto a host with the wrong
  key, "this row was encrypted with a different key" is a far more useful message than "decryption
  failed", and the fingerprint reveals nothing about the key.
- A **separate vault** from the API-key hashes, webhook secrets and backend HMAC secret. They have
  different lifecycles and different blast radii; sharing one key would mean rotating a webhook
  secret caused a gateway outage.
- Secrets are decrypted **once per compilation**, never per message.
- A stored value is never rendered back into a form, never printed by any operational command, never
  logged, and appears in a dry-run preview as a **fixed-width** mask (fixed-width so it does not leak
  the credential's length).

For migrating the existing gateway there is an **allowlisted** environment-secret reference
(`BACKEND_SERVICE_ID`, `BACKEND_SERVICE_SECRET`), so applying a schema change does not have the side
effect of relocating live production credentials into a backed-up table. The allowlist is what stops
an admin naming an arbitrary environment variable and reading it back through a preview.

---

## Endpoint safety (TD-072, closed 2026-08-11)

Before every outbound request, the endpoint is resolved, the resolved addresses are validated, and the
connection is **pinned** to the address that was validated (`CURLOPT_RESOLVE`).

The pin is the point. Validating an address and then letting curl resolve the name again leaves a
time-of-check/time-of-use gap, which is exactly how a DNS-rebinding host answers the check with a
public address and the connection with `169.254.169.254`. With the pin there is no second resolution
to disagree with the first.

Resolution results are cached per process for `SMS_GATEWAY_DNS_CACHE_SECONDS` (default 30), because
the check runs before *every* request and an uncached hostname endpoint would cost a resolver round
trip per send. Caching does not weaken the protection: what is cached is the address set that already
passed validation, and the connection is pinned to it, so a mid-window DNS change cannot redirect
anything. The only cost runs the other way — a legitimate address change takes up to that long to be
noticed, the same shape as the config-version window.

Rules in production:

- **HTTPS only.**
- **No loopback, link-local, unique-local, private or reserved destination.** IPv4 and IPv6 both,
  including IPv4-mapped forms like `::ffff:127.0.0.1` — a loopback address in an IPv6 costume is still
  loopback. `169.254.169.254`, the cloud instance-metadata address, falls under the link-local rule.
- **Every resolved address must pass**, not merely the first. A name answering with one public and one
  loopback address is a rebinding attempt, and picking the good one would be the wrong reading.
- **An unresolvable host is refused**, never attempted — the one case where the check learned nothing
  must not also be the case it permits.
- **Only `http` and `https`.** `file://`, `gopher://`, `dict://` and friends are the classic SSRF
  escalation schemes.
- **Redirects are never followed.** A provider bouncing a request to another host would carry the
  `Authorization` header there, and would also escape the pin.

**TLS is unaffected.** Pinning changes only *where* the socket connects; certificate verification still
runs against the configured **hostname**, because `CURLOPT_RESOLVE` is a name-to-address override
rather than a URL rewrite. Connecting to an IP in the URL and disabling hostname verification would
have been the easy version of this, and a far worse trade. Verification is not offered as a knob
anywhere.

Outside production the *address* rules relax so local development and the test fixtures can use
`http://127.0.0.1`. Scheme checking, resolution and pinning still run, so the code under test is the
code that ships; `SMS_GATEWAY_ENFORCE_ADDRESS_RULES=1` turns the production decision path on outside
production, which is how the tests exercise the real rules rather than a re-implementation of them.

### Internal gateways

`SMS_GATEWAY_INTERNAL_HOSTS` is a comma-separated list of **exact hostnames** — never a wildcard,
never a substring, and never a blanket "allow private addresses" switch. An allowlisted host is exempt
from the address rules and from pinning, because the operator has stated that this exact name is an
intended internal destination, which is precisely a declaration that it may resolve privately and that
its address is theirs to change. Allowlisting one host does not permit any other private destination.

`evil-sms-gw.internal` and `sms-gw.internal.evil.example` do not satisfy an entry for
`sms-gw.internal`; that is asserted in `tests/Integration/GatewayEndpointSafetyTest.php`.

**Residual limitation, stated honestly.** The pin closes the window between validating an address and
opening the socket, but it is only as good as the resolution that produced it: a name whose resolved
set is entirely prohibited is rejected, while a name that legitimately resolves to a public address the
attacker also controls is — correctly — allowed, because that is not rebinding, it is just a public
host. Gateway endpoints remain platform-admin configured, which is the primary control.

---

## Migrating the existing gateway

`make sms-gateway-backfill` registers the current REST integration as the `legacy_rest` gateway. It
**invents nothing** — every value is transcribed from `app/Backend/ApiClient.php` and
`app/backend.php`:

| | |
|---|---|
| endpoint | `{api_base_url}/api/messages/send` |
| method | POST, `application/json` |
| body | `{"sender_user_id":int, "originator":int\|string, "destinations":[string], "content":string}`, in that key order |
| auth | `ellsms_hmac`, active only when `BACKEND_SERVICE_ID` and `BACKEND_SERVICE_SECRET` are both set |
| timeouts | connect 5s, request 30s |
| success | HTTP 2xx **and** a parseable JSON body |
| errors | left unmapped — the transport's built-in defaults already match |
| status API | **none** — the current integration has no delivery-status endpoint, and inventing one would be fabrication |

`originator` is sent as a JSON **number** when the line is all digits and a string otherwise, which is
what `data_type: numeric` reproduces; `destinations` is a real JSON array (`string_list`). Those two
types exist for exactly this parity.

### The parity claim, and how it is checked

`tests/Integration/GatewayParityTest.php` sends the legacy request **and** the gateway request through
a real socket to a receiver that records exactly what arrived, then compares the recordings byte for
byte — including JSON key order, numeric typing, and unescaped Persian content. Comparing built
structures instead would only prove that two functions in this repo agree with each other.

The HMAC timestamp and request id legitimately differ between two calls, so they are excluded from the
byte comparison and verified separately: each signature is re-derived from what that request actually
carried and must match. That is a stronger check than equality.

An operator can run the same comparison:

```
make sms-gateway-simulate TO=989121234567 SENDER=5000435800 COMPARE=1
```

It prints both requests, sends neither, and exits non-zero if the bodies differ.

---

## Rollout

The transport is **off by default**. Re-pointing the live SMS path is the one change here that can
stop a production system from sending, so it is a deliberate operator action, not something a
migration switches on.

1. `make db-migrations-apply`
2. `make sms-gateway-backfill` — registers `legacy_rest`. Routes nothing.
3. `make sms-gateway-integrity-check` — must be clean.
4. `make sms-gateway-simulate TO=… COMPARE=1` — must report IDENTICAL.
5. Assign the gateway to a route (admin UI → درگاه‌های پیامک, or set `ellsms_sms_routes.gateway_id`).
6. Set `SMS_GATEWAY_TRANSPORT=1` and restart the workers.
7. Watch `make sms-gateway-status` and the `gateway_send_*` metrics.

**Rollback is one variable.** Setting `SMS_GATEWAY_TRANSPORT=0` returns every send to the legacy
client immediately; no configuration needs to be undone.

A route with no gateway falls back to the legacy client and logs
`gateway.dispatch.falling_back_to_legacy` every time. That is deliberate — mid-rollout a route may
legitimately not have a gateway yet, and refusing to send would turn incomplete configuration into an
outage. The integrity check reports how many routes are still in that state, so "quietly still on the
old path" is visible rather than assumed.

---

## Configuration propagation

Every admin mutation increments `ellsms_sms_gateways.config_version`. A worker re-reads the tiny
version list at most once per `SMS_GATEWAY_VERSION_CHECK_SECONDS` (default 30) — never per message —
and drops any compiled connector whose version moved.

**Maximum propagation delay is therefore that interval.** It is documented rather than described as
instant.

---

## Environment

| Variable | Default | Meaning |
|---|---|---|
| `SMS_GATEWAY_TRANSPORT` | `0` | `1` routes sends through configured gateways |
| `SMS_GATEWAY_MASTER_KEY` | *(unset)* | HKDF root for the secret vault; min 32 chars. Never back this up with the database. |
| `SMS_GATEWAY_VERSION_CHECK_SECONDS` | `30` | how often a worker re-checks config versions |
| `SMS_GATEWAY_STATUS_BATCH` | `100` | rows claimed per delivery-status pass |
| `SMS_GATEWAY_INTERNAL_HOSTS` | *(empty)* | comma-separated **exact hostnames** exempt from the address rules |
| `SMS_GATEWAY_ENFORCE_ADDRESS_RULES` | `0` | `1` applies the production address rules outside production |
| `SMS_GATEWAY_DNS_CACHE_SECONDS` | `30` | how long a VALIDATED resolution is reused within a process |

---

## Operational commands

| Command | |
|---|---|
| `make sms-gateway-backfill-dry-run` | what registering the legacy integration would create |
| `make sms-gateway-backfill` | registers it (idempotent; copies no credential into the database) |
| `make sms-gateway-integrity-check` | audit; exits non-zero on critical findings; never auto-fixes |
| `make sms-gateway-status [GATEWAY=]` | the configuration in effect; never prints a secret value |
| `make sms-gateway-simulate TO=… [COMPARE=1]` | the exact request, not transmitted |
| `make sms-status-poll` | one delivery-status polling pass |

---

## Admin UI

**درگاه‌های پیامک** (`/sms-gateways.php`), platform admin only via `require_admin()` — deliberately not
an organization permission, because a gateway decides where *every* tenant's messages go.

Tabs: connectors, parameters, operators, secrets, cURL import, request preview.

The **cURL import assistant** parses a pasted provider example into a draft. It is never executed —
not by `shell_exec`, not by `proc_open`, not by a "safe" wrapper. Executing a command pasted into a
web form would be remote code execution wearing a helpful hat. Credentials found in the pasted command
are deliberately *not* carried into the draft; the admin is told to store them as secrets instead.

The **request preview** builds the real request with the real builder and does not send it. A
separately-written preview could disagree with what the send path actually produces, which would make
the whole feature untrustworthy at exactly the moment it is being verified.

---

## What this feature deliberately does not do

- No smart routing, cheapest-route selection, provider health checks, or automatic failover.
- No gateway choice exposed to the customer API — a customer cannot pick or influence a gateway.
- No plaintext secret storage, no per-message config query, no per-message decrypt, no per-message
  mapping compilation, and no per-recipient configuration lookup.
- No Redis or external cache introduced for configuration — the versioned in-process cache is enough,
  and adding an infrastructure dependency for it would be a much larger operational change than the
  problem warrants.
