# «پیام اول»: the AI secretary writes the first message (phase 5)

The secretary ([docs/ai-secretary.md](ai-secretary.md)) can also start a conversation. A campaign
takes the rows of one of the customer's tables that has a phone column ([docs/ai-data.md](ai-data.md)),
optionally only those matching a search. For each number, the AI writes one personalized message
from that number's row, following an instruction, and sends it from the line:

> table «سفارش‌ها», rows containing «ارسال شده», instruction «به مشتری خبر بده سفارشش ارسال شد و
> کد رهگیری پستی را بده» → «سارا خانم سلام، سفارش ۴۵۱۲ شما امروز ارسال شد. کد رهگیری: RT998877»

| Where | What |
|---|---|
| «منشی هوشمند» → a line → «پیام اول (ارسال با هوش مصنوعی)» (`/messages/ai-secretary?line=…#outreach`) | Table, search filter, channel, message type, instruction; «پیش‌نمایش دو پیام اول»; «شروع ارسال»; progress, cost and cancel per campaign |
| `app/Ai/AiOutreach.php` | Campaigns, preview, writing, sending |
| `cron/ai-worker.php` | Sends them, like answers (job kind `outreach`) |
| `db/migrations/2026_11_07_ai_phase5.sql` | `ellsms_ai_outreach`, plus `kind` / `outreach_id` / `context` on `ellsms_ai_jobs` |

## How it works

1. **Preview** writes the first two messages without sending anything. For a customer, the AI part of
   each preview is charged like a test. Admin previews are free.
2. **Start** (after ticking the confirmation) creates one job per number. If several rows share a
   number, only the first gets a message. A campaign can have at most 5,000 numbers.
3. The `ai-worker` writes each message with the line's assistant: its model, instructions and
   business information, plus the row and the instruction. It then sends:
   - **SMS** from the line (`dispatch_message_retryable`, wallet reference `ai_outreach`), with the
     chosen message type: «اطلاع‌رسانی» (`transactional`) or «تبلیغاتی» (`promotional`).
   - **Bale**, or **Bale then SMS** for whoever Bale did not take (`dispatch_with_channel`, only
     when the Bale channel is configured; [docs/bale-channel.md](bale-channel.md)).
4. Guards: only mobile numbers; numbers that opted out of the line are skipped; the line's daily cap
   counts these messages too; an account that cannot pay for one answer is skipped
   (`insufficient_credit`).
5. The AI part of each sent message is charged like an answer ([docs/ai-billing.md](ai-billing.md)).
6. **Replies** arrive as ordinary secretary conversations. The AI sees its own first message in the
   memory, so «کی می‌رسه؟» is understood.
7. **Cancel** skips every message not yet sent (`outreach_cancelled`). A campaign becomes «تمام شد»
   when nothing is left to do.

Bale's business API cannot receive messages, so a reply to a Bale message does not reach the
secretary. Replies to SMS do.
