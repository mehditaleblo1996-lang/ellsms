# AI engines (موتورهای هوش مصنوعی): phase 1

Phase 1 of the AI SMS secretary (منشی پیامکی هوشمند). A platform admin connects AI providers the same
way SMS gateways are connected, finds and enables their models, gives accounts the models they may
use, and tests everything from the panel. **Nothing in phase 1 sends or answers an SMS and nothing is
charged.** Phase 2 connects the secretary to inbound messages. Phase 3 adds tariffs and wallet charges.

Page: **Platform Admin → هوش مصنوعی → موتورهای هوش مصنوعی** (`/admin/ai/engines`, `public/ai-engines.php`).
Platform admins only.

| File | What it holds |
|---|---|
| `db/migrations/2026_11_03_ai_engines.sql` | providers, their encrypted keys, models, test runs, per-account access |
| `app/Ai/AiSecrets.php` | the AI key vault (AES-256-GCM) |
| `app/Ai/AiAdapters.php` | the wire protocol of each provider type (pure functions) and the one network entry point |
| `app/Ai/AiEngines.php` | everything that touches the database, including model-access resolution |
| `tests/Unit/AiAdaptersTest.php` | adapters, policy, vault and access rules, without DB or network |
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
| `openrouter` | `GET {base}/models` (also fills context window and per-million prices) | `POST {base}/chat/completions` | OpenRouter |
| `anthropic` | `GET {base}/models` (paginated) | `POST {base}/messages` | Claude. Uses `x-api-key` and `anthropic-version: 2026-10-21`. No `temperature` is sent, because that API version rejects it. |

Authentication: `Authorization: Bearer <key>`, a custom header holding the raw key, or none.
Anthropic always uses `x-api-key`. Extra headers can be added, for example OpenRouter's
`HTTP-Referer`. Headers the adapter owns (`Authorization`, `Content-Type`, `Host`, and so on) are
refused.

Reasoning that a model wraps in `<think>…</think>` is removed from the reply.

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

## Test history

`ellsms_ai_test_runs` keeps one row per connection test, discovery, model test or playground
message: outcome, latency, HTTP status, error, and token counts. **No prompt or reply text is
stored.**

## Next phases

- **Phase 2:** the secretary page for each line. It hooks into the auto-reply pass
  (opt-out → keyword rules → AI), runs in its own `ai-worker`, keeps conversation memory, and has
  guards against loops and floods.
- **Phase 3:** AI tariffs through the existing pricing chain, and wallet charges.
- **Phase 4:** customer data tables with tool calling.
- **Phase 5:** relay, RAG, and the Bale/Telegram channels.
