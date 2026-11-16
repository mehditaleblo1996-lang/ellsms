# Price changes: preview, scheduling and one-click revert (پیش‌نمایش، زمان‌بندی و بازگشت تغییر تعرفه)

Every save of a price matrix — the global **«تعرفه‌ی درگاه‌ها»** (Platform Admin → تعرفه‌ی پیامک → تعرفه‌ی
درگاه‌ها, `public/sms-pricing.php?tab=matrix`) and each account's **«تعرفه‌ی اختصاصی»** (Users → user →
تعرفه‌ی اختصاصی, `public/users.php`) — now goes through three steps:

1. **Preview.** The form's button («پیش‌نمایش اثر و ذخیره‌ی …») saves nothing. It shows, under the
   matrix, how the change would have priced the last 30 days of real traffic: the cells that change
   (current → new), how many customers it touches out of how many sent, the messages and parts
   affected per month, their monthly revenue at today's and at the new prices, the shift in Rial and as
   a percentage of all monthly revenue, the customers most affected, and a warning when some traffic
   would no longer have a price (and be refused). The preview waits in the admin's session for an
   hour.
2. **Apply** — «اعمال از همین حالا», or «اعمال در زمان» with a Jalali date and time (Tehran). A
   scheduled change waits as «زمان‌بندی‌شده»; until then prices stay as they are. «انصراف» drops the
   preview.
3. **Revert.** «تاریخچه‌ی تغییرات تعرفه», under the matrix, lists the last 15 changes (who, when, the
   cells with old → new values, the estimate saved with each, the status). A scheduled change can be
   **cancelled** («لغو»); an applied one **reverted** («بازگشت») in one click.

Code: `app/PriceChanges.php`, view `app/views/price_changes.php`, `cron/price-changes.php`,
`cron/price-usage-profile.php`. Engine hooks in `app/GatewayPrices.php` (`gateway_prices_with_override()`,
`gateway_prices_validate()`, `before`/`after` in `gateway_prices_save()`'s result) and
`Metrics::muted()`. **Migration `db/migrations/2026_11_20_price_changes.sql`** (tables
`ellsms_price_changes`, `ellsms_price_usage_profile`). Without it the matrices save directly, as before.
No API route.

## How the estimate is made

`ellsms_price_usage_profile` holds the last 30 Tehran days of messages the provider took — bulk rows
(`status = 'sent'`), direct/API/scheduled/auto-reply attempts (`status = 'accepted'`) and legacy
backend sends — grouped by account, sending line, destination operator (or the number's first digits
when none was recorded) and encoding, with one real recipient of each group, the message and part
counts and what they were charged. Platform admins are left out: they are never charged. The
scheduler job **`price-usage-profile`** rebuilds it daily at 04:50; the first preview builds it if it
was never built.

`price_change_impact()` prices every group twice through **the same engine every send uses**
(`sms_pricing_price_messages()`): once as things are, once inside `gateway_prices_with_override()`, which
makes the matrices read as if the change were saved. Tariff percentages, own prices, sub-organizations
paying their parent's prices and reseller chains are therefore all applied exactly as at send time,
without a second implementation. A group's monthly cost is its parts × the unit price. Per-message
rounding is not reproduced, so the figures are an estimate, and next month's traffic may differ.

- For the global matrix every account in the profile is priced; for an account's own matrix, that
  account and every account below it (`price_change_accounts_under()`).
- The preview writes nothing and emits no per-message metrics (`Metrics::muted()`); matrix reads are
  cached for the length of the preview.
- One preview spends at most 20 seconds pricing (busiest traffic first); past that it stops and says
  what share of the traffic it covered.

## Applying, scheduling, reverting

- **Now:** `price_change_apply_now()` saves the cells (`gateway_prices_save()`, a new effective-dated
  period per changed cell — history is never rewritten) and writes the `ellsms_price_changes` row
  (`applied`, the values it replaced in `before_json`) in one transaction.
- **Later:** `price_change_schedule()` validates the cells with the same rules as a save and stores
  them as `scheduled` with `effective_at` (UTC). The scheduler job **`price-changes`** runs every minute
  and applies each due change in its own locked transaction (`price_changes_apply_due()`), so it takes
  effect within about a minute of its time, exactly once. A change that no longer validates when its
  time comes (a gateway or operator archived since, an account moved under a reseller) is marked
  `failed` with the reason, and the job exits 1 so the scheduler raises an alert. The values it
  replaced are read when it applies.
- **Cancel:** only while `scheduled` (`price_change_cancel()`).
- **Revert:** `price_change_revert()` writes a *new* change that puts back, for every cell the original
  changed, the value it replaced — **only in cells that still hold the value the original set**. A cell
  changed again since is left alone and the revert says how many were skipped. The original becomes
  `reverted` and points to the change that undid it; the new one carries `reverts_change_id`. A revert
  can itself be reverted.

Statuses: `scheduled` («زمان‌بندی‌شده»), `applied` («اعمال‌شده»), `cancelled` («لغوشده»), `reverted`
(«برگشت‌خورده»), `failed` («ناموفق»). Audit actions: `price_change.applied`, `price_change.scheduled`,
`price_change.cancelled`, `price_change.reverted` (plus the matrix's own `gateway_prices.saved`).

Sends already made keep the price they were charged (price snapshots and the price frozen on each bulk
row); a bulk job queued before a change keeps its accepted price even if it sends after it.

## Commands

```bash
php cron/price-changes.php --status    # changes still waiting for their time
php cron/price-changes.php             # apply what is due (what the scheduler runs)
php cron/price-usage-profile.php       # rebuild the 30-day traffic profile now
```

Tests: `tests/Integration/PriceChangesTest.php`, `tests/Integration/GatewayPricesHttpTest.php`.
