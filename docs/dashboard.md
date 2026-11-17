# Dashboard («داشبورد»)

The panel's home page (`/index.php`, `public/index.php`) for a logged-in admin or customer. Its data
comes from ELLSMS's own send records — bulk items (`ellsms_bulk_items`, by `claimed_at`) and gateway
direct / scheduled / auto-reply / secretary sends (`ellsms_message_attempts`) — through
`app/Dashboard.php`; the legacy backend's `outbound_message` is not read. An admin sees every account,
a customer their own sends (or their organization's), minus anything an admin hid from the customer's
panel (`app/HiddenMessages.php`).

From top to bottom: shortcuts (ارسال سریع، افزودن مخاطب، خرید اعتبار، گزارش ارسال — each only when
its menu is open for the account), reseller announcements, the account-setup card (onboarding), today's
cards, the 7-day chart, and two tabs «آخرین پیامک‌ها» / «ارسال‌های حجیم اخیر». The cards, the chart and
the two lists are lazily loaded parts (`app/LazyParts.php`): the page answers at once and they fill in.

## Today's cards

Each count is for today in Tehran time. The bold number is SMS parts («پارت»), the small line the
message count («N پیام») — see [message-parts](message-parts.md). The comparison chip («↑ ۱۲٪ نسبت به
دیروز») compares with yesterday **up to this same time of day** (`dashboard_yesterday_counts_so_far()`);
for «ناموفق» a rise shows red.

What the cards show depends on whether the viewer sees delivery reports (`delivery_status_visible()`:
admins and customers on [delivery-based billing](delivery-based-billing.md)).

**With delivery reports — five cards**

| Card | Counts | Field |
|---|---|---|
| «کل پیامک‌های امروز» | every message tried today: accepted by the provider + refused at send time | `total` |
| «ارسال‌شده امروز» | accepted by the provider and not reported delivered: still waiting for a report, or reported undelivered (failed / rejected / expired) | `not_delivered` |
| «تحویل‌شده امروز» | the delivery report says delivered; also «نرخ تحویل» = delivered ÷ accepted | `delivered` |
| «ناموفق امروز» | refused at send time (never accepted by the provider) | `send_failed` |
| «در صف ارسال» | recipients still waiting in running bulk sends (not tried yet, so not in «کل»), plus the number of active schedules | `dashboard_queued_count()` |

So «ارسال‌شده» + «تحویل‌شده» + «ناموفق» = «کل پیامک‌های امروز». A message moves from «ارسال‌شده» to
«تحویل‌شده» when a delivered report arrives; one reported undelivered stays in «ارسال‌شده» (the operator
did accept it). «ارسال‌شده» has no comparison chip, since yesterday's messages have mostly had their
reports by now. Note the 7-day chart splits differently: its «ناموفق» also holds messages reported
undelivered, and its «در انتظار گزارش» only the ones still waiting.

**Without delivery reports — four cards**: «کل پیامک‌های امروز» (accepted + refused), «ارسال‌شده امروز»
(everything the provider accepted), «ناموفق امروز» (refused at send time only) and «در صف ارسال»;
«ارسال‌شده» + «ناموفق» = «کل».

`dashboard_counts_between()` returns `sent`, `delivered`, `failed`, `send_failed` (and their `*_parts`);
`dashboard_with_totals()` adds `total` = sent + send_failed and `not_delivered` = sent − delivered (never
below 0), for counts and parts alike.

**Layout.** Four cards sit in one row. Five cards: on a wide screen «کل پیامک‌های امروز» stands tall
beside a 2×2 of the others; at 980 px and below it takes a full row above the 2×2 (`.dash-stats`,
`.has-5` in `public/assets/css/panel.css`).

## 7-day chart

«پیامک‌های ۷ روز اخیر» — one stacked bar a day (`dashboard_daily_breakdown()`): «تحویل‌شده», «در انتظار
گزارش» and «ناموفق», with a tooltip of the day's figures. Without delivery reports a bar is «ارسال‌شده»
and «ناموفق».

## Lists

«آخرین پیامک‌ها» — the latest 10 sent or failed messages (`dashboard_recent_messages()`); «ارسال‌های حجیم
اخیر» — the latest 5 bulk sends with progress (`dashboard_recent_jobs()`); while one is sending the tab
refreshes every 30 seconds.

No public API route, periodic job, setting or migration.
