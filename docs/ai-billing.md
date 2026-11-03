# AI pricing and charging (تعرفه‌ی هوش مصنوعی): phase 3

Every AI answer of the secretary ([docs/ai-secretary.md](ai-secretary.md)) costs two things: the
SMS that carries it, priced like any SMS, and **the AI part**, priced by the AI tariff described
here. The AI tariff works like the SMS tariff. It has a platform list price, an account's own price
or tariff percentage, and resellers' named tariffs that never go below the reseller's own price.
Reseller profit is paid by the existing daily profit run.

| Where | What |
|---|---|
| Platform Admin → موتورهای هوش مصنوعی → «تعرفه‌ی هوش مصنوعی» (`/admin/ai/engines?view=pricing`) | Billing switch, list prices, per-account prices, purchase-cost and income columns |
| Platform Admin → موتورهای هوش مصنوعی → «مصرف و سود» (`?view=usage`) | Answers, tokens, income, unpaid charges, purchase cost and approximate margin per model |
| Reseller panel → تعرفه‌ها → a tariff → «تعرفه‌ی هوش مصنوعی» (`/reseller/tariffs?edit=<id>#ai`) | The reseller's AI prices for customers on that tariff |
| «منشی هوشمند» (`/messages/ai-secretary`) | The customer sees the AI price of one answer, and the charge of each answer in the log |
| `app/Ai/AiBilling.php` | Prices, resolution down the chain, charging, profit snapshot, reports |
| `db/migrations/2026_11_05_ai_pricing.sql` | `ellsms_ai_prices`, `ellsms_ai_charges`, plus `tokens_estimated` / `ai_charge` on `ellsms_ai_jobs` |
| `tests/Unit/AiBillingTest.php`, `tests/Integration/AiBillingChainTest.php`, `tests/Integration/AiSecretaryFlowTest.php` | Pricing math, chain and profit, charging inside the secretary flow |

## What one answer costs

A price has three parts:

- **«قیمت هر پاسخ»**: the price of one answer.
- **«توکن رایگان هر پاسخ»**: the tokens one answer includes, input and output together.
- **«هر ۱۰۰۰ توکن اضافه»**: the price of every 1,000 tokens beyond the included amount.

```
cost (millicredits) = answer + ceil(max(0, tokens - included) × overage / 1000)
charge (Rial)       = ceil(cost / 1000)
```

Prices are stored in millicredits (thousandths of a Rial), like the SMS price matrix. Forms take
Rial with up to three decimals. The tokens are the provider's own usage figures. When the provider
reports none, they are estimated at one token per 3 characters (rounded up) and flagged as estimated.
Longer business information means more input tokens on every answer, so it can cost more.

Each price table has a **«همه‌ی مدل‌ها»** row (model 0). It applies to every model without a row of
its own, and to each part (answer or overage) left empty in a model's row. «توکن رایگان» is taken
from the global rows only: the model's row, then «همه‌ی مدل‌ها», then the setting
`ai_default_included_tokens` (default 2000).

## Who pays what

This is the SMS chain, unchanged ([docs/reseller-panel.md](reseller-panel.md)). It is computed by
the same `customer_chain_prices()` function: each model is a «column», and the answer price and
overage price are its two «encodings».

1. **Account without a parent:** its own exact price, set by the admin under «قیمت اختصاصی یک حساب».
   Otherwise the list price × its tariff percentage. A sub-organization pays its parent
   organization's price.
2. **Account under a reseller:** the top of the chain is priced as in 1. Every account below is
   priced by its reseller's named tariff (the AI section of that tariff). Otherwise the list price ×
   its own percentage. Never below what the reseller directly above it pays for the same answer.
3. A reseller cannot save an AI tariff price below its own price for that model. For the
   «همه‌ی مدل‌ها» row, the floor is the highest price it pays for any model.

## When it is charged

- **Before the model is asked:** if the account's balance cannot cover one answer price, the message
  is skipped as `insufficient_credit` and the model is never called.
- **After the SMS has been sent:** the AI part is charged once, as wallet type **`ai_debit`** with
  reference `ai_usage` / `job:<id>`. A send that fails never costs the AI part. A retried send is not
  charged twice: both the wallet idempotency key and `ellsms_ai_charges.reference_id` are unique.
- **A fallback text costs no AI**, because no model answered. An unpriced model, or the billing
  switch turned off, records the charge as `free`.
- If the balance runs out between the check and the charge, the answer has already gone out. The
  charge is recorded as `unpaid` (no debit) and is counted in «مصرف و سود».
- **«آزمایش منشی»** by a customer is a real model call and is charged like an answer, with reference
  `test:<token>` and no SMS. A platform admin's tests (including the admin playground) are free.

The financial log («تاریخچه مالی») and the reseller pages show `ai_debit` as «هزینه‌ی هوش مصنوعی».

## Reseller profit

`customer_profit_run()` — the daily reseller-profit pass the `worker` container runs (also `cron/reseller-profit.php`) — reads `sms_debit` **and
`ai_debit`** charges. For an AI charge, each account's price for that exact answer is stored in
`ellsms_ai_charges.chain_prices` (`[[user_id, type, millicredits], …]`, spender first). The reseller
is paid the difference between what its customer paid and what it pays itself, exactly as for an SMS
priced down the chain.

## Admin settings («تعرفه‌ی هوش مصنوعی»)

| Setting | Default | Meaning |
|---|---|---|
| `ai_billing_enabled` | on | Charge the AI part at all |
| `ai_default_included_tokens` | 2000 | Tokens per answer when no price row says otherwise |
| `ai_usd_rial_rate` | 0 | Rial per USD. Used only to show purchase cost and margin in Rial. |

The pricing view also shows, per model, the average tokens per answer over the last 30 days, the
approximate purchase cost of one such answer, and the list-price income from it. The purchase cost
uses the model's purchase prices and assumes 80% input / 20% output tokens.
