# AI secretary roadmap: phases 6–12 (specs for implementing agents)

Phases 1–5 of the AI secretary are built and on `staging` and `main`:

- [docs/ai-engines.md](ai-engines.md): engines, models, access, proxy/relay, OpenRouter, GapGPT.
- [docs/ai-secretary.md](ai-secretary.md): answers to inbound SMS and Telegram, hand-off.
- [docs/ai-billing.md](ai-billing.md): the AI tariff.
- [docs/ai-data.md](ai-data.md): tables, documents, record lookup.
- [docs/ai-outreach.md](ai-outreach.md): «پیام اول».

This page specifies the next seven phases. **Each phase is written so one agent can build and test it
on its own.** Read §0 first, then only your phase.

| Phase | Name | Doc page to create | Migration (reserved name) | Can run in parallel with |
|---|---|---|---|---|
| 6 | Secretary actions: write records, book slots | `docs/ai-actions.md` | `2026_11_10_ai_actions.sql` | 7, 8, 9, 10 |
| 7 | Reminders and follow-ups | `docs/ai-reminders.md` | `2026_11_11_ai_reminders.sql` | 6, 8, 9, 10 |
| 8 | «بنویس برام»: AI writing in the send pages | `docs/ai-compose.md` | `2026_11_12_ai_compose.sql` (only if needed) | 6, 7, 9, 10, 11 |
| 9 | Bale and Telegram **bots** as secretary channels | `docs/ai-bots.md` | `2026_11_13_ai_bots.sql` | 6, 7, 8, 10 |
| 10 | Insights: daily digest, tags, leads | `docs/ai-insights.md` | `2026_11_14_ai_insights.sql` | 6, 7, 8, 9 |
| 11 | Public API and webhooks for the secretary | sections in `docs/public-api.md` and `docs/ai-secretary.md` | `2026_11_15_ai_api.sql` | after 6 (or in parallel if it only uses existing data functions) |
| 12 | AI packages, fallback model, model routing | `docs/ai-packages.md` | `2026_11_16_ai_packages.sql` | **last**: touches billing and model resolution that every phase uses |

Status: **none started.** When you start a phase, change its row in this table to «in progress
(branch …)» in your first commit. When you finish, change it to «done (commit …)».

---

## 0. Rules for every agent

### 0.1 Branch and delivery
- Start from the latest `origin/staging`. Work on a branch `ai/phase-<n>-<short-name>`, for example
  `ai/phase-6-actions`.
- When the phase is done and every test in §0.4 passes, merge `origin/staging` into your branch,
  re-run the tests, then merge into `staging` and push. Staging deploys itself within a minute
  ([docs/staging.md](staging.md)).
- Do not push to `main`. The owner merges `staging` into `main` after testing on staging.
- Commit messages: one line describing the feature, then the details (see `CLAUDE.md`).

### 0.2 The codebase in one minute
- **Code:** plain PHP 8.5 (production image `php:8.5.12-apache-bookworm`), MySQL, no runtime Composer
  dependencies.
  - AI code lives in `app/Ai/`. Each file is loaded from `app/bootstrap.php`.
  - Pages are `public/*.php`, with clean URLs in `docker/apache-clean-urls.conf`.
  - Menu entries are in `app/views/header.php`; access keys are in `app/MenuAccess.php`.
- **AI files to reuse; do not duplicate them:**
  - `AiAdapters.php`: wire protocols. `ai_chat()` returns `text`, `tool_calls`, tokens and `cost`.
  - `AiEngines.php`: providers, models, access (`ai_access_resolve()`).
  - `AiBilling.php`: `ai_can_afford()`, `ai_charge($referenceId, $userId, $model, $usage)`. Charging
    is idempotent per reference.
  - `AiSecretary.php`: jobs, guards, prompt, `ai_secretary_generate()`, hand-off, `ai_secretary_run_pass()`.
  - `AiData.php`: tables, `ai_data_search()`, the `lookup_records` tool, `ai_data_row_save()`.
  - `AiOutreach.php`: «پیام اول» (writing one message per row, campaigns).
  - `AiTelegram.php`: the secretary on a customer's Telegram account.
- **Worker:** `cron/ai-worker.php` (the `ai-worker` service) calls `ai_secretary_run_pass()` in a
  loop. Add new periodic work **inside that pass**, cheap when there is nothing to do. Do not add a
  new container unless the phase says so.
- **Money:** customers pay in Rial from the wallet. AI charges are `ai_debit` debits with a
  `reference_id` per usage. Prices are in millicredits (`ai_price_resolve()`), and the reseller chain
  is handled by `customer_chain_prices()`. Every new AI usage MUST go through `ai_charge()` with a new
  reference prefix, and the prefix must get a label in `app/FinancialLog.php`.
- **Sending SMS:** `dispatch_message_retryable()` (see `ai_secretary_process_job()`). For SMS or Bale
  it is `dispatch_with_channel()` (`app/BaleChannel.php`).
- **Notifications:** `app/NotificationCenter.php`. Add an event key next to `'ai.handoff'`.
- **Webhooks:** `app/MessageWebhooks.php` (`webhook_org_subscribes()` and its emitters).
- **Content policy:** `content_policy_violation()` (`app/ContentPolicy.php`).
- **Jalali dates:** `jalali_to_gregorian()` and `gregorian_to_jalali()` (`app/bootstrap.php`),
  `lba_parse_jalali()` (`app/Lba.php`).

### 0.3 Shared schema values (prevents migration conflicts)
Several phases add values to the same ENUM columns. If two branches each `MODIFY` an ENUM with only
their own new value, the later migration silently drops the other's value. So **every migration
that changes one of these columns sets the FULL list below**, guarded by "my value is not there yet":

- `ellsms_ai_jobs.kind` uses this full list:
  `ENUM('inbound','manual','outreach','telegram','action','reminder','followup','bot','api')`.
- `ellsms_ai_providers.type` stays as it is unless a phase says otherwise:
  `ENUM('openai_compatible','openai','anthropic','openrouter','ollama','gapgpt')`.

The guard pattern is the one in `db/migrations/2026_11_07_ai_phase5.sql`. It uses
`information_schema`, then `PREPARE`/`EXECUTE`, and re-running the file must change nothing.
Migrations are applied in filename order by `cron/db-migrate.php --apply` during `./deploy.sh`, so
use only the file name reserved for your phase.

### 0.4 Tests (required before merging)
- **Unit tests:** put pure logic in pure functions (no DB, no network) and test them in
  `tests/Unit/Ai<Phase>Test.php`.
- **Integration tests:** test the database and HTTP flows in `tests/Integration/Ai<Phase>…Test.php`.
  - Extend `IntegrationTestCase`. It applies every `db/migrations/*.sql`.
  - For a model, use the fake server `tests/fixtures/fake-openai-server.php`. It answers chat,
    tool calls and `[HUMAN]`. Extend it for your phase; do not call a real provider in tests.
  - For SMS, use the recording gateway already used by `tests/Integration/AiSecretaryFlowTest.php`.
    Copy its setUp pattern.
  - Only read your own test's rows. Shared test databases can hold other tests' rows.
- **Commands:**
  ```bash
  vendor/bin/phpunit --filter Ai tests/Unit
  ```
  ```bash
  ELLSMS_TEST_DB_HOST=127.0.0.1 vendor/bin/phpunit -c phpunit.integration.xml --filter Ai
  ```
  The Makefile comment above `test-integration` explains the test DB and the `BACKEND_DB_*` variables
  the HTTP tests need.
- **Regression:** every existing AI test must stay green, which means:
  - the unit tests (`AiAdaptersTest`, `AiSecretaryTest`, `AiBillingTest`, `AiDataTest`, `AiPhase5Test`);
  - the integration tests (`AiEnginesHttpTest`, `AiSecretaryFlowTest`, `AiSecretaryHttpTest`,
    `AiBillingChainTest`).
- **Known failures that are not yours:** these tests already fail upstream, and you must not make
  them worse.
  - Unit: `PaymentGatewayTest`, `CleanUrlRoutingTest`, `DirectSendQueueTest`, `InvoiceAdminControlsTest`.
  - Integration: `SubaccountsHttpTest`, `DashboardTest`, `OperatorReportHttpTest`,
    `AccountTypeUserManagementHttpTest`.
- **PHP 8.5:** do not call `curl_close()`, `curl_share_close()`, or other functions deprecated in
  8.5. Staging shows notices in the page and breaks redirects. Lint every file you touch with `php -l`.
- **Screenshot every new page or card:** see the `run` skill, or Playwright, which is pre-installed.
  Attach the screenshots to your report.

### 0.5 Documentation (same commit, per `CLAUDE.md`)
1. Create your phase's doc page (table above). Write it in English, with the Persian UI names in «…».
   It must cover:
   - every page and URL;
   - the table and the migration;
   - the settings;
   - the guards;
   - what it costs.
2. `README.md`: a short entry in the AI section, and a link in the «Documentation index».
3. If you add an API route: `docs/openapi-v1.yaml`, `docs/public-api.md`, and
   `integrations/api-guide/body.html`. Then rebuild the PDF with
   `NODE_PATH=$(npm root -g) python3 integrations/api-guide/build.py` and commit
   `integrations/DatisRayan-API-Guide.pdf`.
4. If you add periodic work: `docs/scheduler.md`.
5. If you add an env var: `.env.example` and the README «Configuration» table.
6. If a customer sees it: its section in `public/guide.php` (Persian; what it is, where the menu is,
   how to use it, what it costs).
7. Update this file's status table.

### 0.6 Definition of done
- [ ] Every acceptance item of the phase is checked, with the test that proves it named in the report.
- [ ] Unit and integration tests are added; all AI tests pass; there are no new failures elsewhere.
- [ ] Every migration is guarded and re-runnable (apply it twice on the test DB).
- [ ] Docs are updated per §0.5, and the doc page names its migration.
- [ ] There are screenshots of every new page or card, in Persian, RTL, and not broken at phone width.
- [ ] The branch is merged into `staging` and pushed. The report gives the commit, what to test on
      staging, and the limitations.

---

## Phase 6: Secretary actions (write records, book slots)

**Goal:** the secretary does things instead of only reading:
- it saves a lead or an order;
- it books a free slot;
- it updates the sender's own record.

> «فردا ساعت ۵ وقت دارید؟» → finds a free slot, books it for this number → «نوبت شما فردا ساعت ۱۷
> ثبت شد.»

**Scope**
1. **Per-table write permission.** Add to `ellsms_ai_assistant_datasets` a column `access`:
   - `read` (the default, as today);
   - `append`: the secretary may add rows (leads, orders, complaints);
   - `book`: the secretary may reserve a row.

   A table used for `book` must have a status column and a «free» value. Add to `ellsms_ai_datasets`
   the columns `status_column` and `free_value`, plus `taken_value` (default «رزرو شده»).
2. **Two new tools**, offered only when the assistant has such a table and the model has «ابزار»:
   - `save_record(table, fields)` appends a row to an `append` table.
     - The sender's number is always written into the table's phone column. The model cannot set it.
     - Unknown fields are ignored. Values are cut to the column size.
   - `book_record(table, key)` takes the row with that key only if it is still free.
     - It is one conditional `UPDATE … WHERE id = ? AND JSON_UNQUOTE(...status...) = free_value`; if
       0 rows are affected, the slot was taken.
     - On success it writes `taken_value`, plus the sender's number and name if the table has those
       columns.
     - A row can be booked by one number only. A second number gets «این وقت گرفته شده».
   - Both tools return a short text result to the model, as `lookup_records` does.
3. **Limits:**
   - at most `ai_actions_per_sender_day` writes per sender per line per day (setting, default 5);
   - `book` never takes more than one slot per sender per table unless the assistant allows it
     (`max_bookings_per_sender`, default 1).
4. **Log and undo.** New table `ellsms_ai_actions`:
   `id, job_id, assistant_id, user_id, dataset_id, row_id, action ENUM('save','book'), sender, before_json NULL, after_json, undone_at NULL, undone_by NULL, created_at`.
   - Show it in «منشی هوشمند» → the line → new card «کارهای انجام‌شده توسط منشی», with «لغو».
   - Undo deletes the appended row, or restores `before_json` for a booking.
5. **Owner notification:** new event `ai.action` («منشی یک … ثبت کرد»), sent through NotificationCenter.
6. **UI:** in the assistant form, next to each linked table, show a select «فقط خواندن / افزودن ردیف /
   رزرو». In «جدول‌های داده‌ی منشی», add a «رزرو» option on a table, which asks for its status
   column and its free and taken values. Add a template «نوبت‌دهی» with the columns date, time,
   status, name, phone.
7. **Prompt:** tell the model which tables it can write to and what each tool does. Say that it must
   confirm in its answer what it saved or booked, and that it must not claim a booking the tool
   refused.

**Out of scope:** deleting rows, editing rows other than through booking, and models without tool
support. For those, the «افزودن/رزرو» options are disabled with an explanation.

**Billing:** no extra price. Each tool round's tokens are already counted in the answer (see
`ai_secretary_generate()`).

**Tests**
- Unit:
  - the tool schemas built from the tables;
  - the field filtering and phone forcing of `save_record`;
  - the parsing of the `book` arguments;
  - the undo payloads.
- Integration (extend the fake server so it returns a `save_record` or `book_record` call when the
  message contains a marker):
  - an append row is saved, with the sender's number;
  - two different senders race for one slot, and only one wins (run the two bookings back to back
    and assert that the second gets «گرفته شده»);
  - the daily limit;
  - undo of each action;
  - a table with `read` access is never written;
  - a restricted (phone) table cannot be booked for another number;
  - notification `ai.action` is created.

**Acceptance**
- [ ] On staging, a «نوبت‌دهی» table with three free slots: an SMS «یه وقت برای فردا عصر» books one, and
      the table shows it as taken with the number.
- [ ] A second number asking for the same slot is told it is taken and offered another.
- [ ] «لغو» in the card frees the slot again.

---

## Phase 7: Reminders and follow-ups

**Goal:** messages the secretary sends on its own, at the right time:
- a reminder a day before an appointment;
- an instalment due date;
- a subscription renewal;
- a follow-up to someone who did not answer.

**Scope**
1. **Date-based reminders.** New table `ellsms_ai_reminders`:
   `id, assistant_id, user_id, dataset_id, title, date_column, time_column NULL, offset_minutes INT (negative = before), instruction TEXT, channel ('sms','bale','bale_sms'), message_type, filter_query, active, last_run_at, created_by, created_at`.
   - Dates in the table may be Jalali (`1405/08/20`, `۱۴۰۵-۰۸-۲۰`) or Gregorian. Write a pure parser
     `ai_reminder_parse_date(string $date, ?string $time): ?DateTimeImmutable` in Asia/Tehran,
     using `jalali_to_gregorian()`.
   - Each run finds rows whose `date + time + offset` is due now (within the last
     `ai_reminder_window_minutes`, default 60). It then creates jobs of kind `reminder`, reusing the
     «پیام اول» writing (`ai_outreach_write()`) with the reminder's instruction.
   - **Idempotency:** a table `ellsms_ai_reminder_sends` with `(reminder_id, row_id, due_at)` UNIQUE.
     A row is reminded once per due time, even when the worker restarts.
2. **Follow-ups.** These are per-assistant settings:
   - `followup_after_hours` (0 = off, at most 72);
   - `followup_instruction`.

   When the secretary's last message in a conversation (an answer or a «پیام اول») got no reply after
   that many hours, one job of kind `followup` writes a short follow-up from the conversation memory.
   This happens at most once per conversation per outbound message, and never after an opt-out or
   during a hand-off.
3. **Quiet hours.** These apply to everything this phase sends:
   - settings `ai_quiet_from` and `ai_quiet_to` (Tehran time, default 21:00–08:00);
   - a message due in quiet hours waits until they end.

   They are not applied to replies to the customer's own messages.
4. **UI:**
   - in «منشی هوشمند» → the line, a card «یادآوری‌ها»: create, edit, pause, delete, and a «پیش‌نمایش»
     of the next three rows due with the message the AI would write (charged like a test);
   - follow-up fields in the assistant form;
   - a log of reminder and follow-up jobs in the existing log, with labels.
5. **Guards:**
   - everything in the existing guards (opt-out, mobile only, daily cap of the line, credit);
   - a reminder is skipped when its row has no phone.

**Billing:** like «پیام اول». The AI part of each sent message goes through `ai_charge('job:<id>')`,
and the SMS or Bale part is billed as usual.

**Tests**
- Unit:
  - the date parser (Jalali, Gregorian, Persian digits, invalid input);
  - due computation with offsets across midnight;
  - quiet-hour shifting;
  - the follow-up eligibility function (pure, given the conversation's last events).
- Integration:
  - a reminder for a row due in 10 minutes is sent once even if the pass runs three times;
  - quiet hours delay it;
  - a follow-up is sent after N hours of silence (fake the time by back-dating rows);
  - no follow-up after a reply;
  - none to an opted-out number.

**Acceptance**
- [ ] On staging, a table with a row due in 30 minutes and a reminder «۳۰ دقیقه قبل» sends one SMS,
      once.
- [ ] Follow-up: with `followup_after_hours = 1` and back-dated data, the follow-up is sent once.

---

## Phase 8: «بنویس برام» (AI writing in the send pages)

**Goal:** any customer with an AI model can have the AI write the text in the ordinary send pages,
without having a secretary.

**Scope**
1. **Button** «بنویس برام (هوش مصنوعی)» next to the message text in `public/new-send.php` (the current
   «ارسال پیامک» panel), and in the bulk and smart send pages that share its editor.
2. **Dialog:**
   - a brief («چی می‌خوای بگی؟»);
   - a tone (رسمی / دوستانه / تبلیغاتی);
   - a type: «اطلاع‌رسانی» or «تبلیغاتی»;
   - max parts (1–3).

   It returns **three variants**, each with:
   - its part count and characters (`sms_parts()`);
   - a content-policy warning when `content_policy_violation()` matches;
   - a «استفاده» button that puts it into the editor.
3. **Endpoint:** `POST /messages/ai-compose` (JSON, CSRF, logged-in). It uses the account's default
   AI model (`ai_access_resolve()`) and one model call that asks for a JSON array of three texts.
   Write a pure parser that tolerates code fences and an invalid answer (in that case, return the raw
   text as one variant).
4. **Limits:**
   - `ai_compose_per_user_hour` (setting, default 20);
   - the brief is capped at 500 chars.
5. **Visibility:** the button shows only if the account has at least one allowed model and the
   `ai_compose_enabled` setting is on (admin «تنظیمات منشی» card).

**Billing:** `ai_charge('compose:<random token>')` with the account's model price. The reference
label is «نوشتن متن با هوش مصنوعی» in `app/FinancialLog.php`. If the account cannot afford it, the
endpoint answers with a clear error and makes no model call.

**Migration:** none expected. If you need a log table, use the reserved name.

**Tests**
- Unit:
  - the JSON-variants parser (clean JSON, fenced JSON, a broken answer);
  - the prompt contains the type and part limit;
  - the part counts.
- Integration (HTTP):
  - an allowed user gets three variants and is charged once;
  - a user without models gets 403 or a hidden button;
  - the hourly limit;
  - insufficient credit;
  - a prohibited word is flagged.

**Acceptance**
- [ ] On staging, «ارسال پیامک» → «بنویس برام» → three Persian variants with part counts, and the
      wallet shows one «نوشتن متن با هوش مصنوعی» charge.

---

## Phase 9: Bale and Telegram bots as secretary channels

**Goal:** a customer connects **their own bot** (a Bale bot or a Telegram bot) and the secretary
answers its private messages. This fixes the gap that Bale's business API cannot receive messages,
and avoids the ban risk of a personal Telegram account.

**Verify first:** Bale's bot API. It is expected to be Telegram-Bot-API compatible at
`https://tapi.bale.ai/bot<token>/` (`getMe`, `getUpdates`, `sendMessage`, a contact-request keyboard).
Confirm it from Bale's official docs **before** coding, and record the confirmed URLs and fields in
the doc page. Telegram's bot API (`https://api.telegram.org/bot<token>/`) is blocked from Iran: reuse
the AI proxy setting pattern (a proxy URL in the vault) for Telegram bots.

**Scope**
1. **Table `ellsms_ai_bots`:**
   `id, user_id, platform ENUM('bale','telegram'), bot_username, token_ciphertext, token_nonce, token_tag (encrypted with `ai_secret_key()` + `ai_secret_encrypt()`, like provider keys), status ENUM('active','disabled','error'), last_update_id BIGINT, last_error, created_at`.
   - Its secretary «line» is `bot:<id>`, and senders are `botu:<chat id>`.
2. **Connecting:** in «منشی هوشمند», a new card «ربات بله / تلگرام» where the customer pastes the token.
   - `getMe` validates it and stores the username.
   - The bot then appears as a line in the line list, like Telegram accounts do (see
     `ai_tg_lines_for()`).
3. **Receiving:** inside `ai_secretary_run_pass()`, poll `getUpdates` for each active bot with
   `offset = last_update_id + 1` and a short timeout (no long polling inside the pass).
   - Store private text messages in `ellsms_ai_bot_inbound`
     (`bot_id, chat_id, message_id, sender_name, sender_phone NULL, text, received_at, job_id NULL`),
     then turn them into jobs of kind `bot`, the way `ai_tg_enqueue_pass()` does.
   - The `/start` command answers with the assistant's greeting and a «اشتراک شماره» button
     (request_contact). A shared contact fills `sender_phone`, which phone-restricted tables use.
4. **Answering:** `sendMessage` to the chat.
   - SMS-only guards do not apply (as in `ai_tg_guard_facts()`). The hand-off, memory, tables,
     actions (phase 6, when present) and limits do apply.
   - Answers are not cut to SMS parts. Use a separate cap, `ai_bot_max_chars` (default 1500).
5. **Errors:**
   - token revoked: `status = 'error'`, the owner is notified, and polling stops;
   - rate limited: back off.

**Billing:** only the AI part (`ai_charge('job:<id>')`); a bot message costs nothing to send. An
optional per-message platform fee is set by `ai_bot_message_price` (Rial, default 0). It is debited
like other channel fees, with its own reference label.

**Tests**
- Unit:
  - the update parser (text, `/start`, contact, group message ignored, edited message ignored);
  - the line/sender naming;
  - token masking in errors.
- Integration: a fake bot API fixture (a new `tests/fixtures/fake-bot-api.php`) that serves
  `getMe`, `getUpdates` and `sendMessage` and records calls. Cases:
  - a message is answered once;
  - the offset advances, so there are no duplicates after a restart;
  - `/start` sends the contact button;
  - a shared contact is used for a restricted table;
  - a revoked token marks the bot as an error.

**Acceptance**
- [ ] On staging, a real Bale bot: a message to the bot is answered by the secretary within a few
      seconds, and the log shows a `bot` job with its AI charge.

---

## Phase 10: Insights (daily digest, tags, leads)

**Goal:** the business owner sees what the secretary did and who to follow up with, without reading
every conversation.

**Scope**
1. **Daily digest per assistant** at an hour the owner picks (`digest_hour`, Tehran time; off by
   default).
   - One model call summarizes the day's conversations: count, common questions, unanswered or
     handed-off ones, and people who want to buy or book.
   - The transcripts are capped at `ai_digest_max_chars` (default 30,000). When the day is larger,
     the newest conversations are kept.
   - It is delivered as a NotificationCenter event `ai.digest`, and optionally by SMS to the owner's
     mobile (`digest_sms`, billed as a normal SMS).
2. **Tags per conversation,** computed in the same digest call:
   - categories: `question`, `order`, `booking`, `complaint`, `lead`, `other`;
   - a one-line note.

   Store them in `ellsms_ai_conversation_insights`
   (`line, sender, day, tags_json, note, created_at`, UNIQUE line+sender+day).
3. **Dashboard card** in «منشی هوشمند» → the line, «گزارش منشی»:
   - for today, 7 days and 30 days: answers, skipped by reason, hand-offs, AI cost (from
     `ellsms_ai_charges`), and the busiest hours;
   - a list of tagged conversations with a filter by tag;
   - «خروجی CSV».
4. **Leads:** a button «ذخیره در جدول» copies the `lead`-tagged numbers and notes into a chosen data
   table, which can then feed «پیام اول».

**Billing:** the digest call goes through `ai_charge('digest:<assistant>:<day>')`, so a digest is
charged once per assistant per day.

**Tests**
- Unit:
  - the transcript builder and its cap;
  - the parser of the model's JSON (tolerant);
  - the stats aggregation from fixture rows.
- Integration:
  - the digest runs once at the chosen hour, even if the pass runs repeatedly;
  - insights are stored;
  - the notification is created;
  - the charge is idempotent;
  - the CSV export;
  - leads are copied into a table.

**Acceptance**
- [ ] On staging, with a back-dated day of conversations and `digest_hour` set to now, one digest
      notification arrives with tags, and the card shows the numbers.

---

## Phase 11: Public API and webhooks for the secretary

**Goal:** a customer's website or CRM can ask the secretary, read and answer conversations, and keep
the data tables in sync.

**Scope**
1. **Routes:** add them to the API router (`app/Api/Router.php`, handlers in
   `app/Api/Handlers/Ai.php`) with new scopes in `app/Support/ApiScopes.php`:

   | Method and route | Scope | What it does |
   |---|---|---|
   | `POST /api/v1/ai/ask` `{line or assistant_id, sender?, message}` | `ai.ask` | Synchronous answer (timeout `ai_api_timeout_seconds`, default 30). Job kind `api`, same memory and tables, charged per answer. |
   | `GET /api/v1/ai/conversations?line=&handed_over=1` | `ai.conversations.read` | List conversations |
   | `GET /api/v1/ai/conversations/{line}/{sender}` | `ai.conversations.read` | Messages of one conversation |
   | `POST /api/v1/ai/conversations/{line}/{sender}/reply` | `ai.conversations.write` | Manual reply (`ai_conversation_manual_reply()`) |
   | `POST /api/v1/ai/conversations/{line}/{sender}/resume` | `ai.conversations.write` | Give the conversation back to the secretary |
   | `GET`, `POST`, `PUT`, `DELETE` on `/api/v1/ai/datasets/{id}/rows[/{rowId}]` | `ai.data.read` / `ai.data.write` | Row CRUD through `ai_data_row_save()` / `ai_data_row_delete()`; bulk upsert by key column |

   - Every route checks that the line or dataset belongs to the key's organization.
   - Use the API's existing rate-limit and idempotency handling (`app/Idempotency.php`).
2. **Webhook events** through `app/MessageWebhooks.php`:
   - `ai.answered` (job id, line, sender, reply, charge);
   - `ai.handoff`;
   - `ai.action` (from phase 6, if present).

   Customers subscribe in the existing webhooks page.
3. **Docs** are mandatory here:
   - `docs/openapi-v1.yaml` and `docs/public-api.md`, with the scopes;
   - `integrations/api-guide/body.html` plus a PDF rebuild;
   - a section in `docs/ai-secretary.md`.

**Billing:** `ask` is charged like an answer (`ai_charge('job:<id>')`). Data and conversation routes
are free.

**Tests**
- Unit: request validation and the response shapes.
- Integration (HTTP, as `PublicApiHttpTest` does):
  - each route with the right scope, the wrong scope and another organization's resource;
  - `ask` returns the fake model's answer and charges once;
  - an idempotent retry does not charge twice;
  - row upsert by key;
  - the webhook is enqueued for `ai.answered`.

**Acceptance**
- [ ] On staging, `curl` with a key that has `ai.ask` gets an answer, and the wallet shows the charge.
- [ ] A row added by API is used in the next SMS answer.

---

## Phase 12: AI packages, fallback model, model routing (do last)

**Goal:** easier selling (monthly bundles), fewer failed answers (a fallback model), and lower cost
(cheap model for easy messages).

**Scope**
1. **Packages.**
   - `ellsms_ai_packages` (admin-defined): `id, name, answers INT, price_rial, valid_days, model_ids_json NULL, active`.
   - `ellsms_ai_package_purchases`: `id, user_id, package_id, answers_left, expires_at, wallet_tx_id, created_at`.
   - Customers buy from the wallet in «منشی هوشمند» → «بسته‌ها».
   - `ai_charge()` first consumes one answer from the oldest valid purchase that covers the model, and
     records the charge as `status = 'package'` and `amount = 0`. Only without a package does it debit
     the wallet as today.
   - Resellers can sell packages to their customers, following `app/ResellerPackages.php`.
   - The profit run must ignore `package` charges and count the purchase itself.
2. **Fallback model.**
   - The assistant gets a `fallback_model_id`.
   - On a retryable provider error (`AI_SECRETARY_RETRYABLE_ERRORS`) on the last attempt,
     `ai_secretary_generate()` is tried once with the fallback before the fallback **text** is used.
   - The job records which model answered, and the charge uses that model's price.
3. **Routing.**
   - The assistant gets an optional `cheap_model_id`.
   - A pure function `ai_route_model(array $assistant, string $content, int $recordsFound, array $history): 'cheap'|'main'`
     sends short, simple messages with no table hit and no tool need to the cheap model.
   - Thresholds are settings.
   - The log shows which route was taken.

**Billing:** described above. Every change to `ai_charge()` must keep it idempotent per reference.
The existing billing tests must all still pass.

**Tests**
- Unit:
  - the package consumption order (oldest valid, model coverage, expired skipped);
  - `ai_route_model()` cases;
  - fallback selection.
- Integration:
  - a purchase debits the wallet once, and answers consume it to zero, then the wallet is charged;
  - an expired package is ignored;
  - the fake model returns 503, so the fallback model answers and is charged;
  - the cheap route is used for «سلام»;
  - the reseller chain and profit with packages.

**Acceptance**
- [ ] On staging, buy a 10-answer package: ten answers show «از بسته» and the eleventh is charged
      from the wallet.
- [ ] Stopping the main model's server makes the fallback model answer.
