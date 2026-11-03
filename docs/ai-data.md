# AI secretary data tables, lookup and hand-off (phase 4)

The AI secretary ([docs/ai-secretary.md](ai-secretary.md)) can answer questions such as «سفارش ۴۵۱۲
کجاست؟» or «کیف چرمی موجوده؟» from the customer's own records. It can also hand a conversation to a
human, who replies from the panel.

| Where | What |
|---|---|
| «منشی هوشمند» → «مدیریت جدول‌ها» (`/messages/ai-data`, `public/ai-data.php`) | Tables: create from a template, from Excel/CSV or blank; columns and defaults; key and phone columns; import; browse, search, add, edit and delete rows |
| «منشی هوشمند» (`/messages/ai-secretary`) | Per line: which tables the assistant may use, the lookup mode, hand-off settings, and the «گفتگوهای ارجاع‌شده به اپراتور» card with manual reply and «ادامه با منشی هوشمند» |
| Platform Admin → موتورهای هوش مصنوعی → model «ابزار» | Whether a model answers tool calls |
| `app/Ai/AiData.php` | Tables, rows, import, normalization, search, the `lookup_records` tool |
| `app/Ai/AiSecretary.php` | Lookup inside an answer, the hand-off marker, pause/resume, manual reply |
| `app/Ai/AiAdapters.php` | Tool calls on the wire for the OpenAI-style, Ollama and Anthropic protocols |
| `db/migrations/2026_11_06_ai_datasets.sql` | `ellsms_ai_datasets`, `ellsms_ai_dataset_rows`, `ellsms_ai_assistant_datasets`, `ellsms_ai_conversations`, plus new columns on assistants, models and jobs |
| `tests/Unit/AiDataTest.php`, `tests/Integration/AiSecretaryFlowTest.php`, `tests/Integration/AiSecretaryHttpTest.php` | Tests |

Access is the same as «منشی هوشمند»: menu key `ai_secretary`, `AUTOREPLY_VIEW` / `AUTOREPLY_MANAGE`,
and the `AUTOREPLY` plan entitlement. A customer sees only its own tables. A platform admin sees
every table and can create one for an owner.

## Tables

- **Columns** have a name, a type (text, number, date, mobile number) and an optional default that
  fills empty cells. A table can have up to 30 columns and 50,000 rows.
- **Key column**: identifies a row. An import in «به‌روزرسانی» mode updates the row with the same
  key. Keys are unique per table.
- **Phone column**: when set, **a sender only ever sees rows whose phone is its own number**. Nobody
  can read someone else's order by guessing its code. The restriction is applied in SQL, before
  search, in both lookup modes. A message with no usable search terms, or whose terms match none of
  the sender's rows («سفارشم کجاست؟», «نوبت من کیه؟»), returns the sender's latest rows (up to 3).
- **Templates**: shop products, shop orders (by phone), clinic appointments (by phone), clinic
  services, service requests (by phone).
- **Import** (`.xlsx` / `.csv`, up to 10 MB): the first row holds the column names, matched to the
  table's columns after normalization; other columns in the file are ignored. Modes: append, update
  by key (and add the rest), or replace everything. A table can also be created straight from a file;
  its header row becomes the columns.

## Lookup

Values and queries are normalized the same way. Arabic ي/ك become ی/ک, Persian and Arabic digits
become Latin, ZWNJ becomes a space, and diacritics and punctuation are dropped. So «۴۵۱۲» finds 4512
and «كيف» finds «کیف». Rows are scored as follows: an exact key match weighs most, then whole words,
then substrings, and numbers count double. The model sees up to 8 rows.

The assistant's «روش جست‌وجو» (`lookup_mode`):

| Mode | How |
|---|---|
| `auto` (default) | Tool calls if the model is flagged «ابزار», otherwise automatic search |
| `search` | **Automatic search.** The customer's message plus the previous one is searched, and the matching rows travel with the message as «Records found». This works with any model, including Hermes or Ollama without a tool parser. |
| `tools` | **Tool calls.** The model gets a `lookup_records(table, query)` tool and calls it as needed, up to 3 rounds, before answering. Token usage of every round is summed for billing ([docs/ai-billing.md](ai-billing.md)). |

Records go into the customer's turn, never into the system prompt, so the system prompt stays the
same from message to message and a provider can cache it (phase 5 prompt caching).

Discovery marks OpenAI, Anthropic and OpenRouter models as supporting tools. For a self-hosted
server (Hermes on vLLM with a tool parser, Ollama), tick «ابزار» on the model after testing it.

## Hand-off to a human

With «ارجاع به اپراتور» on, the model starts its answer with the marker `[HUMAN]` when the customer
asks for a person, complains, or cannot be helped from the information. The marker is never sent.
Then:

1. The answer goes out as usual (for example «همکاران ما به‌زودی تماس می‌گیرند»), and the job is
   flagged `handoff`.
2. The conversation (line + number) is paused for `handoff_pause_hours` (default 24; 0 = keep
   answering, only notify). Messages from that number in the meantime are skipped as
   `human_handoff`.
3. The line's owner gets a panel notification (event `ai.handoff`, «ارجاع گفتگوی منشی هوشمند به
   اپراتور»; the other channels follow the notification settings).
4. In «گفتگوهای ارجاع‌شده به اپراتور» the operator reads the last messages, replies, and presses
   «ادامه با منشی هوشمند». A reply is sent from the line as an ordinary SMS of the owner (wallet
   reference `ai_manual`), only to a number that already has a conversation on that line. It is
   kept as a `manual` job, so the AI sees it in the conversation memory afterwards.

## Documents (phase 5)

Long text, such as warranty terms, a manual, policies or a long FAQ, can be added as a **document**
under «جدول جدید» → «سند». Paste the text, or upload a `.txt`, `.md`, `.csv`, `.docx` or `.pdf` file
(up to 20 MB). It is split into pieces of about 700 characters, on paragraph, then sentence, then
word boundaries, and stored as a table with one column «متن» (template `document`). The same lookup
then finds only the pieces relevant to the customer's message, and those go to the model, so the
whole document never has to fit in a prompt. A document can have up to 2,000,000 characters.
«جایگزینی متن سند» replaces the whole text.

PDF text comes from `pdftotext` (the `poppler-utils` package, now in the app image). An image-only
or encrypted PDF has no text to read. Old Persian `.txt` files in Windows-1256 are converted to UTF-8.
