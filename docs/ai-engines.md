# AI engines (موتورهای هوش مصنوعی): phase 1

Phase 1 of the AI SMS secretary (منشی پیامکی هوشمند). A platform admin connects AI providers the same
way SMS gateways are connected, finds and enables their models, gives accounts the models they may
use, and tests everything from the panel. Phase 1 itself sends no SMS and charges nothing. Phase 2, the secretary that answers inbound
messages, is described in [docs/ai-secretary.md](ai-secretary.md). Phase 3 adds tariffs and wallet
charges for the AI part ([docs/ai-billing.md](ai-billing.md)). The same admin page has the
«تعرفه‌ی هوش مصنوعی» and «مصرف و سود» views.

Page: **Platform Admin → هوش مصنوعی → موتورهای هوش مصنوعی** (`/admin/ai/engines`, `public/ai-engines.php`).
Platform admins only.

| File | What it holds |
|---|---|
| `db/migrations/2026_11_03_ai_engines.sql` | providers, their encrypted keys, models, test runs, per-account access |
| `app/Ai/AiSecrets.php` | the AI key vault (AES-256-GCM) |
| `app/Ai/AiAdapters.php` | the wire protocol of each provider type (pure functions) and the one network entry point |
| `app/Ai/AiEngines.php` | everything that touches the database, including model-access resolution |
| `db/migrations/2026_11_09_ai_provider_balance.sql` | provider credit balance, recorded top-ups, alert thresholds ([below](#provider-balance-and-alerts)) |
| `app/Ai/AiBalance.php` | credit left per provider, days left, low-balance alerts |
| `cron/ai-balance-check.php` | the scheduler job `ai-balance` (every 15 minutes) |
| `tests/Unit/AiAdaptersTest.php` | adapters, policy, vault and access rules, without DB or network |
| `tests/Unit/AiBalanceTest.php` | out-of-credit errors, OpenRouter credit, days left, alert levels |
| `tests/Integration/AiEnginesHttpTest.php` | the whole admin flow over HTTP against a fake Hermes (`tests/fixtures/fake-openai-server.php`) |

## Setup

1. Apply the migration: `make db-migrations-apply`. The menu entry stays hidden until it is applied.
2. To store API keys, either `AI_MASTER_KEY` (32 characters or more, `openssl rand -base64 48`) or the
   existing `SMS_GATEWAY_MASTER_KEY` must be set. A provider without a key, such as an internal
   Hermes, works without either.

## Provider types

| Type | Models | Chat | Use it for |
|---|---|---|---|
| `openai_compatible` | `GET {base}/models` | `POST {base}/chat/completions` | **Hermes**, vLLM, LM Studio, DeepSeek, any OpenAI-compatible server |
| `ollama` | `GET {base}/api/tags` | `POST {base}/api/chat` | Ollama |
| `openai` | `GET {base}/models` | `POST {base}/chat/completions` (`max_completion_tokens`) | OpenAI |
| `openrouter` | `GET {base}/models` (also fills context window, per-million prices and tool support) | `POST {base}/chat/completions` (asks for `usage.cost`) | OpenRouter: one key for hundreds of models ([below](#openrouter)) |
| `gapgpt` | `GET {base}/models` | `POST {base}/chat/completions` | GapGPT, an Iranian gateway to GPT, Claude, Gemini and others, with no proxy needed ([below](#gapgpt)) |
| `anthropic` | `GET {base}/models` (paginated) | `POST {base}/messages` | Claude. Uses `x-api-key` and `anthropic-version: 2026-10-21`. No `temperature` is sent, because that API version rejects it. |

Authentication: `Authorization: Bearer <key>`, a custom header holding the raw key, or none.
Anthropic always uses `x-api-key`. Extra headers can be added, for example OpenRouter's
`HTTP-Referer`. Headers the adapter owns (`Authorization`, `Content-Type`, `Host`, and so on) are
refused.

Reasoning that a model wraps in `<think>…</think>` is removed from the reply.

## OpenRouter

One OpenRouter key reaches Claude, GPT, Gemini, DeepSeek, Qwen, Hermes and hundreds of other models.
Add a provider of type «OpenRouter» (base URL `https://openrouter.ai/api/v1`, key from
openrouter.ai → Keys), then «تشخیص مدل‌ها». OpenRouter refuses Iranian addresses, so use the proxy or
the relay ([below](#proxy-and-relay-phase-5)).

- **Discovery** fills each new model's name, context window, max output, purchase prices (USD per
  million tokens) and the «ابزار» flag from OpenRouter's `supported_parameters`. Models still arrive
  disabled; turn on only the ones you will sell.
- **Actual cost:** every chat request sends `"usage": {"include": true}`. OpenRouter then reports
  what the call really cost in USD (`usage.cost`, including caching discounts and the upstream
  provider's price). For a model priced in USD, that figure is recorded as the purchase cost, for
  example in the playground, on secretary jobs and on AI charges, instead of the estimate from
  per-million prices. Other providers, and models priced in another currency, keep the estimate.
- **App name:** requests carry `X-Title: ELLSMS`, so they are grouped under that name in
  OpenRouter's activity page. An extra header `X-Title` overrides it.
- **Key credit:** «تست اتصال» also reads `GET {base}/key` and shows the key's usage, and either its
  remaining limit or that it has no limit and spends the account's credit. A free-tier key is
  flagged. If this call fails, the connection test still passes. The balance check reads the same
  endpoint and `GET {base}/credits` every 15 minutes ([below](#provider-balance-and-alerts)).

## GapGPT

[GapGPT](https://gapgpt.app/) is an Iranian gateway that resells access to OpenAI, Anthropic,
Google and other models through an OpenAI-compatible API. It is reachable from Iranian servers, so
**no proxy or relay is needed**.

1. Get an API key from the GapGPT panel.
2. In «ارائه‌دهنده‌ی جدید», choose type «GapGPT». The base URL `https://api.gapgpt.app/v1` is filled
   in. Enter the key, then «ذخیره».
3. «تست اتصال», then «تشخیص مدل‌ها». Models arrive disabled. Switch on the ones you will sell, and
   test each with «تست».
4. Enter each model's purchase prices by hand, in GapGPT's billing currency. Set «ارز» to `IRR` if
   it bills in Rial; the margin views use it as is. GapGPT does not report prices or tool support
   in its model list. Tick «ابزار» for models that answer tool calls (GPT, Claude and Gemini do).

The type needs `db/migrations/2026_11_08_ai_gapgpt.sql`, which adds `gapgpt` to
`ellsms_ai_providers.type`. Deploys apply it.

## Other Iranian gateways (هوشا and similar)

Any other Iranian gateway that resells model access is added as «سازگار با OpenAI» if its API is
OpenAI-compatible: base URL ending in `/v1`, a key, `Authorization: Bearer`. Discovery and chat work
unchanged, and no proxy is needed. Enter the purchase prices by hand, in the gateway's billing
currency (for example `IRR`). A gateway with its own non-OpenAI API needs a new adapter type.
Hoosha (هوشا) publishes no API reference, so it has no type of its own.

## Connecting Hermes on staging

1. Run Hermes on the staging host with its OpenAI-compatible API server enabled. The default port
   is `8642`.
2. `docker-compose.stage.yml` gives the `app` container `host.docker.internal → host-gateway`, so
   the panel can reach the host.
3. In the panel, add a provider with these settings:
   - **نوع:** «سازگار با OpenAI»
   - **آدرس پایه:** `http://host.docker.internal:8642/v1`
   - **احراز هویت:** «بدون کلید». Use Bearer with the key if `API_SERVER_KEY` is set on Hermes.
   - **«اجازه‌ی آدرس داخلی»:** on
4. Press **تست اتصال**, then **تشخیص مدل‌ها**. Each model starts disabled. If the server has no
   `/models` endpoint, the test still reports the connection as up, and the model can be added by
   hand under «افزودن دستی مدل».
5. Press **تست** on a model, then **فعال‌سازی**.
6. Open **دسترسی کاربران** to give the model to accounts, then use **چت آزمایشی** to talk to it,
   either directly or as an account.

## Outbound policy

Every provider call goes through `ai_http_send()`:

- only `http`/`https`, no redirects followed, a 4 MB response cap, and TLS verification always on;
- **without** «اجازه‌ی آدرس داخلی», the URL must be `https` and resolve to public addresses only. The
  connection is pinned to the address that was checked. This is the same check that number
  forwarding and webhooks use;
- **with** it, `http` and private addresses are allowed. Only a platform admin can set this.

## Keys

- Stored encrypted in `ellsms_ai_provider_secrets`. The key is derived with HKDF from `AI_MASTER_KEY`
  (or `SMS_GATEWAY_MASTER_KEY`) under the purpose label `ellsms.ai_provider.secret.v1`, so it is
  never the gateway vault's key.
- A key is write-only: it is never rendered back. Leaving the field blank keeps the stored key.
  Provider error text is scrubbed of the key before it is shown or stored.
- Like the gateway key, the master key is not in the database and must not be backed up with it.

## Models

- **تشخیص مدل‌ها** upserts what the provider lists. New models arrive **disabled**. An existing
  model keeps its status, name and prices; OpenRouter's reported values only fill empty fields. A
  model the provider stops listing is marked «دیگر فهرست نمی‌شود» and is not deleted.
- Per model, you can set the display name, max output tokens (the cap sent with every request),
  context window, and purchase price per million input/output tokens (USD by default). The price is
  the base for the phase-3 tariff, and the playground uses it to show an approximate cost.
- **تست** sends a one-line prompt with a 16-token cap.
- **«ابزار»** (`supports_tools`) marks a model that answers tool calls. The secretary's record lookup
  uses it ([docs/ai-data.md](ai-data.md)). Discovery sets it for OpenAI and Anthropic, and for
  OpenRouter from each model's `supported_parameters`. Tick it yourself for a self-hosted server that has a tool parser.

### Thinking models and the output-token cap

Thinking/reasoning models (e.g. `gapgpt-qwen-3.5-thinking`, OpenAI's o-series) count their hidden
reasoning as output tokens, and spend it before they write the first word of the answer. With a
small cap the reply comes back cut off mid-sentence with finish reason `length` (`max_tokens` on
Anthropic). `ai_parse_chat()` returns `truncated` for that case and `reasoning_tokens` when the
provider reports it (`usage.completion_tokens_details.reasoning_tokens`). In **چت آزمایشی** the
default «حداکثر توکن خروجی» is 1500, a cut-off answer carries a «پاسخ ناقص است» warning, and the
reasoning tokens are shown next to the output tokens. The secretary and outreach budget is
`ai_secretary_max_tokens()` = `max(1500, parts × 300)` (still capped by the model's max output
tokens); a model is billed only for the tokens it actually writes, and the answer is cut to the SMS
part limit afterwards either way. Leave a thinking model's «حداکثر خروجی» at least that high
(8000 is a good value).

When a reply is cut off before the model wrote any answer, `ai_parse_chat()` throws
`reasoning_exhausted` («مدل همه‌ی توکن‌های خروجی را صرف استدلال کرد…») instead of the generic
`empty_response`, and an unclosed `<think>` is dropped so reasoning never reaches a customer. The
secretary and outreach (`ai_secretary_chat()`) then retry once with 4× the budget, up to the model's
«حداکثر خروجی» (8000 when it is empty); if that is already reached, the error stays.

## Which models an account may use

Resolution happens in `ai_access_resolve()`, which is pure and unit-tested:

1. An account with **custom** models uses those, intersected with its parent's effective set. A
   reseller's customer or a sub-organization never gets more than its parent.
2. An account set to **inherit** gets its parent's effective set.
3. A top-level account with no setting gets the **platform default model**, but only if
   «همه‌ی کاربرانِ بدون تنظیم اختصاصی، مدل پیش‌فرض را داشته باشند» is on. Otherwise it gets nothing.

Only enabled models on enabled providers are ever effective. The default model is the nearest one in
the chain that is still allowed, then the platform default if it is allowed, then the first allowed
model.

## Provider balance and alerts

AI providers sell prepaid credit (USD, or Rial on an Iranian gateway), not tokens. Each provider has
a **«موجودی و هشدار»** tab (provider → «موجودی و هشدار») and a «موجودی» column in the provider list
that show the credit left, the average daily spend over the last 7 days, and how many days the
credit lasts at that rate. When it runs low, an alert goes out through the unified alert subsystem
([docs/alerting.md](alerting.md)): Telegram and email, repeated until someone acknowledges it in
«هشدارها» (`/admin/alerts`), sent again if it gets worse, and a «برطرف شد» notice once the provider
is topped up. The alert key is `ai_balance:<provider code>`.

Needs `db/migrations/2026_11_09_ai_provider_balance.sql` (tables `ellsms_ai_provider_balance` and
`ellsms_ai_provider_topups`). Until it is applied, the tab and the column are hidden and the job does
nothing.

### Where the balance comes from

| Provider | Source | Badge |
|---|---|---|
| OpenRouter | read from the API: `GET {base}/key` (the key's remaining limit) and `GET {base}/credits` (the account's credit). The smaller of the two counts. | «زنده از API» |
| GapGPT, OpenAI, Anthropic, OpenAI-compatible, Ollama | estimated: the top-ups the admin records, minus the purchase cost of every answer and test since | «تخمینی» |

For an estimated balance, record what you do in the provider's panel:

- **«شارژ کردم»** adds the amount to the balance;
- **«موجودی واقعی الان»** replaces the estimate with the exact figure from the provider's panel. Do
  this now and then; the estimate restarts from it.

Spend is the `cost_estimate` of secretary, Telegram and «پیام اول» jobs (`ellsms_ai_jobs`; OpenRouter's
real cost when it reports one) plus model tests, playground messages and «آزمایش منشی» priced at the
model's per-million purchase prices (`ellsms_ai_test_runs`). Only models whose «ارز» matches the
provider's balance currency are counted, so enter purchase prices for every model you use. Calls
that leave no record (the «پیش‌نمایش» of «پیام اول», a failed attempt the provider still billed) are
not counted, which is another reason to record the real balance now and then. A new
GapGPT provider starts in `IRR`, every other type in `USD`; change it under «آستانه‌های هشدار».
OpenRouter is always USD.

### Levels and thresholds

| Level | When (defaults) | Alert severity |
|---|---|---|
| «کافی» | none of the below | none (an open alert is resolved) |
| «رو به اتمام» | 7 days or less left (or below «هشدار: موجودی کمتر از», empty by default) | `warning` |
| «بحرانی» | 2 days or less left, 1 USD or less (`critical_amount`, set by default only for USD providers), or zero | `critical` |
| «تمام شده» | the provider refused a call for lack of credit | `emergency` |
| «نامشخص» | no balance known yet (nothing recorded, API not answering) | none |

All four thresholds and «هشدار فعال باشد» are per provider; an empty field is not checked. «Days
left» needs spend in the last 7 days; with none, only the amount thresholds apply. For a Rial
provider, set the amount thresholds in Rial yourself.

### "Out of credit" from the provider

Whatever the estimate says, `ai_http_send()` recognises a provider that refuses a call for lack of
credit (`ai_error_is_out_of_credit()`): HTTP 402 (OpenRouter), an error code such as
`insufficient_quota` (OpenAI, sent with 429), or a message such as «credit balance is too low»
(Anthropic) or a Persian «موجودی ... کافی نیست». The call fails with the error code
`provider_out_of_credit` («اعتبار حساب ارائه‌دهنده تمام شده است…») instead of `rate_limited` /
`bad_request`, so the secretary does not retry it, and `ai_balance_on_out_of_credit()` marks the
provider exhausted and raises the `emergency` alert at once, without waiting for the next check. The
flag clears when a top-up is recorded, OpenRouter reports a positive balance, or a model answers
successfully afterwards (a model «تست», the playground, or a secretary answer) and the next check runs
(«بررسی الان» runs it immediately).

### The job

`ai-balance` in the scheduler (`cron/ai-balance-check.php`, every 15 minutes,
[docs/scheduler.md](scheduler.md)) re-checks every provider. A disabled provider, or one with alerts
off, only has its open alert resolved. Run it by hand with `php cron/scheduler.php --run=ai-balance`.

## Test history

`ellsms_ai_test_runs` keeps one row per connection test, discovery, model test or playground
message: outcome, latency, HTTP status, error, and token counts. **No prompt or reply text is
stored.**

## Phases

- **Phase 2 (done):** the AI SMS secretary. See [docs/ai-secretary.md](ai-secretary.md).
- **Phase 3 (done):** AI tariffs through the existing pricing chain, and wallet charges. See
  [docs/ai-billing.md](ai-billing.md).
- **Phase 4 (done):** customer data tables, record lookup (tool calls or automatic search), hand-off
  to a human. See [docs/ai-data.md](ai-data.md).
- **Phase 5 (done):** «پیام اول» by SMS/Bale ([docs/ai-outreach.md](ai-outreach.md)), the
  secretary on Telegram ([docs/ai-secretary.md](ai-secretary.md) §Telegram), documents
  ([docs/ai-data.md](ai-data.md) §Documents), proxy/relay and prompt caching (below).

## Proxy and relay (phase 5)

OpenAI, Anthropic and OpenRouter refuse Iranian addresses. There are two ways around this, set per
provider:

- **«پراکسی / relay خارج از کشور»** (provider → «اتصال و کلید»): an `http(s)://` or
  `socks5://user:pass@host:port` proxy. Its URL is kept in the AI vault (`proxy_url`) and never
  shown. Every request of that provider goes through it. `socks5` is sent as `socks5h`, so the proxy
  resolves the provider's name. The proxy overrides any `NO_PROXY` in the container. With a proxy
  there is no local address to check or pin; the https-only rule still applies unless
  «اجازه‌ی آدرس داخلی» is on.
- **A relay** on a server abroad: `ops/ai-relay/` (nginx + docker compose, protected by a shared
  `X-Relay-Key` header). Set the provider's base URL to the relay, for example
  `https://relay.example.com/openai/v1`, and add the header as an extra header. See
  [ops/ai-relay/README.md](../ops/ai-relay/README.md).

## Prompt caching

For Anthropic, a system prompt of 4,000 characters or more (long business information) is sent as a
cacheable block (`cache_control: ephemeral`). Cached and cache-write tokens are counted as input
tokens in usage and billing. OpenAI caches long prompts automatically. Found records travel with the
customer's message and never change the system prompt, so the cache keeps hitting
([docs/ai-data.md](ai-data.md)).
