# AI SMS secretary (منشی هوشمند): phase 2

The AI secretary answers SMS that arrive on a customer's line. It uses the AI model the account was
given in phase 1 ([docs/ai-engines.md](ai-engines.md)) and the business information the customer
writes. The answer is sent from the same line, like an auto-reply.

| Where | What |
|---|---|
| `/messages/ai-secretary` (`public/ai-secretary.php`) | The customer page: per-line settings, a test box, and the conversation log. Admins also see every line and the global switches. |
| `app/Ai/AiSecretary.php` | Assistants, the enqueue hook, guards, prompt, part fitting, and the worker pass |
| `cron/ai-worker.php` / `ai-worker` compose service | Answers the jobs. Runs in its own container. |
| `db/migrations/2026_11_04_ai_secretary.sql` | `ellsms_ai_assistants` (one per line) and `ellsms_ai_jobs` (queue + memory + log) |
| `tests/Unit/AiSecretaryTest.php` | Guards, part fitting, prompt, form |
| `tests/Integration/AiSecretaryFlowTest.php` | Inbound → job → fake model → recording gateway, end to end |
| `tests/Integration/AiSecretaryHttpTest.php` | The page: own line only, no model means no menu |

## Setup

1. `make db-migrations-apply`
2. Start the worker: `docker compose up -d ai-worker`. It uses the worker's environment because it
   sends through the same path. `AI_WORKER_PROCESSES` sets the number of parallel answerers
   (default 2).
3. Phase 1 must be done: a model is enabled and the account has access to it. The menu entry
   «منشی هوشمند» appears only for accounts that have a usable model and a line.

On staging, `docker-compose.stage.yml` also gives `ai-worker` `host.docker.internal`, so it can
reach a Hermes server running on the host.

## How a message flows

```
inbound SMS on line L
  └─ run_autoreply_pass()                   (worker container, unchanged order)
       1. «۱۱/۱۲» opt-out keywords          → app/LineOptouts.php (the AI never answers these)
       2. keyword auto-reply rules          → a matching rule answers; the secretary stays out
       3. ai_secretary_consider()           → if L has a switched-on assistant: a job (pending)
  └─ cron/ai-worker.php                     (ai-worker container)
       claim (one job per conversation at a time)
       → owner still active / plan has auto-reply / line still theirs
       → guards (below)
       → model: the line's choice if still allowed, else the account's default
       → prompt: rules + instructions + business info + the last N exchanges with this number
       → answer cut to the line's SMS-part limit
       → dispatch_message_retryable(..., 'ai_secretary', <job id>, ..., 'transactional')
```

Each inbound message becomes at most one job (`UNIQUE inbound_key`). A send retry reuses the stored
answer and never calls the model again.

## Guards

A job is **skipped**, and the log shows why, when:

| Reason | Rule |
|---|---|
| `not_mobile` | The sender is not an Iranian mobile number, for example a short code or a service line. Most bot-to-bot loops start from such numbers. |
| `own_line` | The sender is one of the platform's own lines. |
| `opted_out` | The sender opted out of this line. |
| `too_old` | The message waited longer than `ai_secretary_max_age_minutes` (default 30). A backlog after an outage is not answered hours late. |
| `loop_suspected` | More than 5 messages from the number in 2 minutes. |
| `repeated_message` | The same text arrived 3 times in 10 minutes. |
| `sender_limit` | The per-number hourly cap for the line was reached (0 = no cap). |
| `line_limit` | The line's daily cap was reached (0 = no cap). |
| `no_model` | The account has no usable model. |
| `human_handoff` | The conversation was handed to a human and is paused ([docs/ai-data.md](ai-data.md)). |
| `insufficient_credit` | The account's balance cannot cover the AI price of one answer ([docs/ai-billing.md](ai-billing.md)). |
| `optout_keyword`, `empty` | Recorded when the message is enqueued. The model is never asked. |

The inbound text is untrusted. The system prompt tells the model never to follow instructions inside
the message, never to change its role, and never to reveal its instructions. Nothing the model
returns is executed: it is only sent as SMS text, after markdown is stripped and the text is cut to
the part limit.

## Assistant settings (per line)

| Setting | Meaning |
|---|---|
| **Mode** | «منشی کسب‌وکار» answers only from the business information. If the answer is not there, it says a colleague will follow up and does not invent prices or promises. «دستیار عمومی» answers any question. |
| **Template** | shop / clinic / services / general. Fills sample instructions and information that the customer then edits. |
| **Instructions** | Up to 4,000 characters. |
| **Business information** | Up to 20,000 characters. Sent with every request. For large data, see phase 4. |
| **Model** | The account default, or one of the account's allowed models. |
| **Max reply length** | 1–5 SMS parts. The answer is cut at a sentence or word boundary, with «…» if cut. |
| **Memory** | The last N exchanges (default 6) with the same number within H hours (default 24). |
| **Caps** | Replies per number per hour, and replies per line per day. |
| **Data tables / lookup mode** | The tables the assistant may look into and how (automatic search or tool calls). See [docs/ai-data.md](ai-data.md). |
| **Hand-off** | «ارجاع به اپراتور» and how long the AI stays out of a handed-over conversation. See [docs/ai-data.md](ai-data.md). |
| **Fallback text** | Sent when the model is still unreachable after `ai_secretary_max_attempts` tries. Empty means stay silent. |

«آزمایش منشی» shows the answer the saved settings would give, including that number's real
conversation memory, without sending an SMS or creating a job.

## Billing

- The **SMS** of each answer is charged like an auto-reply, with reference type `ai_secretary` in
  the wallet ledger and the financial log.
- The **AI part** is priced by the AI tariff and charged as `ai_debit` once the SMS has gone out.
  An account that cannot pay for one answer is skipped as `insufficient_credit` before the model is
  asked. A fallback text costs no AI. A customer's «آزمایش منشی» is charged like an answer, with no
  SMS. See [docs/ai-billing.md](ai-billing.md). The log shows each answer's AI charge.
- In the dashboard and the sent report, the answers are counted with the auto-replies. The transport
  record (`ellsms_message_attempts`) is written as `direct_send`, exactly as for keyword
  auto-replies. This is existing behavior of the send path and was not changed.

## Admin settings

On the same page, platform admins only:

| Setting | Default |
|---|---|
| Master switch: no new jobs are enqueued and the worker idles | on |
| Max message age | 30 minutes |
| Model attempts per message | 3 |
| Log retention | 90 days |

Old finished jobs are pruned by the worker about once an hour.

## Telegram (phase 5)

A customer who connected its own Telegram account in «ارسال با تلگرام»
([docs/telegram-user-service.md](telegram-user-service.md)) sees that account as a line on this
page, labelled «تلگرام @username» (internally `tg:<account id>`). With an assistant switched on
there:

- the `tg-worker` keeps the account connected and stores every **private text message from a
  person** it receives in `ellsms_tg_inbound`. Bots, groups, channels and media-only messages are
  ignored;
- the `ai-worker` turns each message into a job (kind `telegram`, sender `tgu:<Telegram user id>`)
  and answers it like an SMS: same instructions, tables, memory, hand-off, caps and burst/repeat
  guards. The SMS-only guards (mobile number, own line, «۱۱» opt-out) do not apply;
- the answer is queued as a one-recipient Telegram message on the same account, addressed to the
  stored peer (Telegram usually hides the sender's phone). It is priced and charged by the Telegram
  service; the AI part is charged by the AI tariff;
- tables restricted to the sender's number answer only when Telegram shared the sender's phone.

Schema: `db/migrations/2026_11_07_ai_phase5.sql`.

## «پیام اول»

The secretary can also send the first message itself, one personalized message per row of a table.
See [docs/ai-outreach.md](ai-outreach.md).
