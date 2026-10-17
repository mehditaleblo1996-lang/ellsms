# Panel UI refresh — staging review

The customer panel, administration panel and sign-in page use a light interface while retaining the existing brand hues (`#4a32e8`, `#2e63f6`) and self-hosted Vazirmatn. The public marketing pages retain their existing stylesheet and markup.

## Changes

- Sticky, independently scrolling navigation with local SVG icons, visible active states, remembered groups and Persian menu search (including Arabic ی/ک normalization).
- Mobile drawer at 1100px and below, with a 32px dismissal margin, focus containment, Escape/backdrop dismissal and focus restoration. Hidden mobile navigation and the background of an open drawer are inert.
- Measured touch targets, clearer typography, consistent surfaces, controls, tables and spacing. Narrow tables scroll inside their card and have a visible scrolling hint and keyboard access.
- Short transform/opacity transitions, with reduced-motion support. The sign-in page removes continuously animated background fields and expensive backdrop blur; password-manager autocomplete is enabled.
- Responsive fixes for the gateway clone source selector and forwarding-line grid at 320px.
- Local, deferred navigation JavaScript; no new production dependency or remote asset service. CSS is scoped to the authenticated `.panel` body. Existing send-confirmation behavior remains in the shared footer.

## Validation

The shared sizing scale is explicit: desktop sidebar 272px; mobile drawer at most 304px with at least 32px outside it; menu rows and group headings at least 46px; action buttons at least 44px; text controls at least 46px; form text 16px; table text 14px. Drawer motion is 220ms and group/content entry is 180ms, disabled for reduced motion.

Chromium 154 / Playwright 1.62.1, against the actual PHP application and disposable MySQL fixtures, using a platform admin and an organization owner. Every visible menu destination was requested at **320, 360, 390, 768, 1024 and 1440px**, height 900px: **51 admin + 24 customer destinations = 450 checks**. All returned HTTP 200 with the panel rendered. No page-level horizontal overflow, uncontained content or browser runtime errors remained. Wide data tables intentionally scroll within their wrappers.

Mobile interaction checks at 390×844px passed: login/password visibility, drawer size, search/empty results/reset, all 65 admin navigation links and group headings at least 44px tall, keyboard containment, Escape/focus return, backdrop dismissal, resize to desktop, persistent groups and reduced motion. Sign-in screenshots were inspected at desktop and mobile sizes. Dashboard, send, contacts, users and settings screenshots were inspected as representative dense/form/list surfaces.

- PHP lint: 523 files passed.
- Integration suite selection: 73 tests, 400 assertions passed (authorization, wallet, API rate limits, impersonation HTTP, account creation HTTP and line-forwarding HTTP). Run these in a fresh disposable test database: schema fixtures recreate backend tables and must not share a database with browser-review accounts.
- Unit suite: 679 tests, 3463 assertions; two failures and two skips, matching the staging baseline before these changes. Existing failures: `DirectSendQueueTest::testBrowserConfirmationRoutesImmediateSendsToQueue` expects an old literal in the footer; `InvoiceAdminControlsTest::testInvoiceAdminControlsAndVatWiringExist` expects a quoted VAT value in Compose.

These are UI/layout and representative interaction checks with fixtures. They do not establish production load performance, full accessibility compliance, real SMS delivery or the behavior of every populated business workflow.

## Repeating browser checks

The optional runner `tests/browser/panel-ui.cjs` needs Playwright (tested with 1.62.1) and Chromium. It reads a test account from environment variables, performs no sends/settings writes, exits nonzero on failure, and saves measurements and screenshots outside the checkout.

Install browser tooling separately if necessary:

```bash
npm install --prefix /tmp/ellsms-browser-tools --no-save --package-lock=false playwright@1.62.1
```

Set `ELLSMS_UI_BASE_URL`, `ELLSMS_UI_USERNAME` and `ELLSMS_UI_PASSWORD` securely for a disposable/staging account. Use an admin and an organization-owner account in separate runs for full coverage. Set `ELLSMS_UI_CHROMIUM` to the installed Chromium executable, or install Playwright's Chromium. Then:

```bash
NODE_PATH=/tmp/ellsms-browser-tools/node_modules ELLSMS_UI_FULL=1 node tests/browser/panel-ui.cjs
```

Omit `ELLSMS_UI_FULL=1` for interaction checks only. `ELLSMS_UI_OUTPUT` optionally selects the output directory; the default is a new `/tmp/ellsms-ui-*` directory.

## Destination-by-destination layout record

Each row passed all six viewport widths. Customer-only onboarding is listed separately. Conditional integrations were exercised where the review account had access; permission logic itself is unchanged.

| Menu | Path | Review roles | Widths / result |
| --- | --- | --- | --- |
| داشبورد | `/index.php` | admin, customer | 320–1440px: pass |
| اعلان‌ها | `/notifications.php` | admin, customer | 320–1440px: pass |
| راهنمای کاربران | `/guide.php` | admin, customer | 320–1440px: pass |
| پنل جدید ارسال | `/new-send.php` | admin, customer | 320–1440px: pass |
| ارسال پیامک | `/send.php` | admin, customer | 320–1440px: pass |
| نظیر به نظیر | `/p2p-send.php` | admin, customer | 320–1440px: pass |
| پیامک هوشمند | `/smart-send.php` | admin, customer | 320–1440px: pass |
| ارسال منطقه‌ای | `/regional-bulk.php` | admin | 320–1440px: pass |
| زمان‌بندی‌شده | `/schedules.php` | admin, customer | 320–1440px: pass |
| منشی پیامک | `/autoreply.php` | admin, customer | 320–1440px: pass |
| گزارش ارسال | `/reports.php` | admin, customer | 320–1440px: pass |
| گزارش ارسال حجیم | `/reports-bulk.php` | admin, customer | 320–1440px: pass |
| صندوق دریافت | `/inbox.php` | admin, customer | 320–1440px: pass |
| پیام‌رسان بله | `/bale-messages.php` | admin | 320–1440px: pass |
| مخاطبین | `/contacts.php` | admin, customer | 320–1440px: pass |
| لیست سیاه | `/blacklist.php` | admin, customer | 320–1440px: pass |
| لغو عضویت (۱۱) | `/line-optouts.php` | admin, customer | 320–1440px: pass |
| خرید اعتبار | `/buy-credit.php` | admin, customer | 320–1440px: pass |
| فاکتورها | `/invoices.php` | admin, customer | 320–1440px: pass |
| اشتراک و مصرف | `/billing.php` | admin, customer | 320–1440px: pass |
| کلیدهای API | `/api-keys.php` | admin, customer | 320–1440px: pass |
| افزونه ووکامرس و SDK | `/integrations.php` | admin, customer | 320–1440px: pass |
| وب‌هوک‌ها | `/webhooks.php` | admin, customer | 320–1440px: pass |
| فوروارد پیامک به URL | `/number-forwards.php` | admin, customer | 320–1440px: pass |
| پشتیبانی | `/tickets.php` | admin, customer | 320–1440px: pass |
| کاربران | `/users.php` | admin | 320–1440px: pass |
| درخواست‌های ثبت‌نام | `/registration-requests.php` | admin | 320–1440px: pass |
| نقش‌های دسترسی منو | `/menu-roles.php` | admin | 320–1440px: pass |
| سود نمایندگان | `/reseller-profits.php` | admin | 320–1440px: pass |
| محدودیت ارسال کاربران | `/user-send-policies.php` | admin | 320–1440px: pass |
| بررسی احراز هویت | `/kyc-review.php` | admin | 320–1440px: pass |
| محدودیت‌های KYC | `/kyc-gates.php` | admin | 320–1440px: pass |
| شماره‌ها | `/numbers.php` | admin | 320–1440px: pass |
| دسته‌های شماره | `/number-categories.php` | admin | 320–1440px: pass |
| درگاه‌های پیامک | `/sms-gateways.php` | admin | 320–1440px: pass |
| کپی کامل درگاه | `/sms-gateway-clone.php` | admin | 320–1440px: pass |
| اتصال به دیتابیس مشتری | `/remote-db.php` | admin | 320–1440px: pass |
| بسته‌های قیمتی | `/pricing.php` | admin | 320–1440px: pass |
| تعرفه‌ی پیامک | `/sms-pricing.php` | admin | 320–1440px: pass |
| فاکتور / لیست قیمت | `/price-quote.php` | admin | 320–1440px: pass |
| مدیریت اشتراک‌ها | `/billing-admin.php` | admin | 320–1440px: pass |
| گزارش مالی | `/financial-admin.php` | admin | 320–1440px: pass |
| کلمات ممنوع | `/prohibited-words.php` | admin | 320–1440px: pass |
| لغو صف ارسال | `/admin/queue/cancellation` | admin | 320–1440px: pass |
| آرشیو شش‌ماهه پیام‌ها | `/admin/bulk-archive` | admin | 320–1440px: pass |
| آمار تفصیلی | `/analytics.php` | admin | 320–1440px: pass |
| لاگ فعالیت‌ها | `/logs.php` | admin | 320–1440px: pass |
| هشدارها | `/admin/alerts` | admin | 320–1440px: pass |
| ویدیوهای صفحه‌ی اصلی | `/landing-videos.php` | admin | 320–1440px: pass |
| راهنمای استفاده | `/guide-admin.php` | admin | 320–1440px: pass |
| تنظیمات | `/settings.php` | admin | 320–1440px: pass |
| شروع کار | `/onboarding.php` | customer | 320–1440px: pass |
