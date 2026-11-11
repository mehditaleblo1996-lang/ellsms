# راهنمای افزونه‌ی ووکامرس و SDKهای داتیس رایان گستر ایرانیان

این راهنما دو ابزار را توضیح می‌دهد:

- **افزونه‌ی ووکامرس:** فروشگاه وردپرسی شما با تغییر وضعیت هر سفارش، خودکار برای مشتری پیامک می‌فرستد.
- **SDK برای PHP، جاوااسکریپت (Node.js) و پایتون:** برنامه‌نویس‌ها با چند خط کد از داخل برنامه‌ی خودشان پیامک می‌فرستند.

هر دو از **API عمومی پنل** استفاده می‌کنند و برای کار به یک **کلید API** نیاز دارند.

## قدم اول: ساخت کلید API (برای هر دو ابزار لازم است)

1. وارد پنل شوید و از منوی کناری به **یکپارچه‌سازی ← کلیدهای API** بروید.
2. روی «ساخت کلید» بزنید. یک نام انتخاب کنید (مثلاً «فروشگاه من») و محیط را **live** بگذارید.
3. دسترسی‌ها را انتخاب کنید:
   - برای افزونه‌ی ووکامرس: `messages:send` و `balance:read`
   - برای SDK: هر دسترسی‌ای که برنامه‌تان لازم دارد (جدول پایین)
4. کلید **فقط همین یک بار** نمایش داده می‌شود. آن را کپی کنید و جای امنی نگه دارید. کلید با `datis_live_` شروع می‌شود.

| دسترسی | کاربرد |
|---|---|
| `messages:send` | ارسال پیامک و پیش‌نمایش هزینه |
| `messages:read` | خواندن وضعیت ارسال و وضعیت تحویل پیامک (message state) |
| `bulk:write` / `bulk:read` | ساخت و پیگیری ارسال گروهی و وضعیت تحویل آن |
| `otp:send` | ارسال کد تایید با خط اشتراکی و وضعیت تحویل آن |
| `contacts:read` / `contacts:write` | خواندن و مدیریت مخاطبین |
| `balance:read` | دیدن اعتبار |
| `webhooks:read` / `webhooks:write` | مدیریت وب‌هوک‌ها |

> کلید API مثل رمز عبور است. آن را در کد منبعی که منتشر می‌کنید، یا در مرورگر (جاوااسکریپت سمت کاربر) قرار ندهید. اگر لو رفت، از همان صفحه **«باطل کردن»** یا **«چرخش»** را بزنید.

**آدرس پنل** همان آدرسی است که با آن وارد پنل می‌شوید، مثلاً `https://datisrayan.com`. بالای همین صفحه هم نمایش داده شده است.

## افزونه‌ی ووکامرس

### نصب

1. بالای همین صفحه روی **«افزونه‌ی ووکامرس»** بزنید تا فایل `ellsms-woocommerce.zip` دانلود شود.
2. در پیشخوان وردپرس: **افزونه‌ها ← افزودن ← بارگذاری افزونه**. فایل zip را انتخاب کنید، «هم‌اکنون نصب کن» و بعد «فعال‌سازی» را بزنید.
3. در منوی **ووکامرس ← پیامک ELLSMS**:
   - **آدرس پنل** و **کلید API** را وارد کنید.
   - **خط ارسال** اختیاری است. اگر خالی بماند، خط پیش‌فرض حساب شما استفاده می‌شود.
   - «ذخیره‌ی تنظیمات» را بزنید.
4. دکمه‌ی **«آزمایش اتصال و نمایش اعتبار»** را بزنید. باید پیام «اتصال برقرار است» و مقدار اعتبار شما را ببینید.
5. در کادر «ارسال پیامک آزمایشی» شماره‌ی خودتان را بزنید تا مطمئن شوید پیامک می‌رسد.

**نیازمندی‌ها:** وردپرس ۵٫۸ به بالا، ووکامرس ۶ به بالا و PHP ۷٫۴ به بالا. افزونه با ذخیره‌سازی جدید سفارش‌های ووکامرس (HPOS) هم سازگار است.

### تنظیم پیامک هر وضعیت

در جدول «پیامک هر وضعیت سفارش»، برای هر وضعیتی که می‌خواهید پیامک بفرستد تیک **«فعال»** را بزنید و متن را ویرایش کنید. وضعیت‌های سفارشی که افزونه‌های دیگر اضافه کرده‌اند هم در جدول هستند.

متغیرهایی که در متن می‌توانید بگذارید:

| متغیر | مقدار |
|---|---|
| `{first_name}` / `{last_name}` / `{full_name}` | نام مشتری (از آدرس صورتحساب) |
| `{order_number}` | شماره‌ی سفارش |
| `{order_id}` | شناسه‌ی سفارش |
| `{total}` و `{currency}` | مبلغ کل و واحد پول (تومان، ریال، …) |
| `{status}` | نام وضعیت جدید |
| `{items}` | نام محصولات (حداکثر ۶۰ حرف) |
| `{payment_method}` | روش پرداخت |
| `{date}` | تاریخ ثبت سفارش |
| `{site_name}` | نام فروشگاه |

مثال:

```
{first_name} عزیز، سفارش {order_number} تکمیل و ارسال شد.
مبلغ: {total} {currency}
{site_name}
```

### چطور کار می‌کند

- **مقصد پیامک:** شماره‌ی **تلفن صورتحساب** مشتری. همه‌ی شکل‌های رایج پذیرفته می‌شوند: `09121234567`، `+989121234567`، `9121234567`، و ارقام فارسی.
- **ارسال در پس‌زمینه:** پیامک با زمان‌بند داخلی ووکامرس (Action Scheduler) ارسال می‌شود، پس ثبت سفارش هیچ‌وقت منتظر پیامک نمی‌ماند.
- **حداکثر یک بار برای هر وضعیت:** پیامک هر وضعیت برای هر سفارش حداکثر یک بار ارسال می‌شود. اگر سفارشی دوباره به همان وضعیت برگردد، پیامک تکراری نمی‌رود.
- **وضعیت عوض‌شده:** اگر وضعیت سفارش قبل از ارسال پیامک دوباره عوض شود، پیامک وضعیت قدیمی ارسال نمی‌شود.
- **ثبت نتیجه:** نتیجه‌ی هر ارسال در **یادداشت‌های سفارش** ثبت می‌شود. مثلاً «پیامک «تکمیل شده» برای 98912… ارسال شد» یا دلیل ارسال‌نشدن.

### ارسال دوباره

در صفحه‌ی سفارش، از کادر **«عملیات سفارش»** گزینه‌ی **«ارسال دوباره‌ی پیامک وضعیت فعلی (ELLSMS)»** را انتخاب و دکمه را بزنید. این کار حتی برای وضعیتی که تیک «فعال» ندارد، به شرط داشتن متن، انجام می‌شود.

### رفع اشکال

| یادداشت سفارش / پیام | علت و راه‌حل |
|---|---|
| شماره‌ی موبایل مشتری معتبر نیست | تلفن صورتحساب موبایل ایران نیست. آن را در سفارش اصلاح کنید و «ارسال دوباره» را بزنید. |
| کلید API نادرست، منقضی یا باطل شده است | کلید را در پنل بررسی کنید، یک کلید جدید بسازید و در تنظیمات افزونه وارد کنید. |
| کلید API دسترسی «messages:send» ندارد | کلید جدید با این دسترسی بسازید. |
| اعتبار کافی نیست | اعتبار پنل را شارژ کنید، سپس «ارسال دوباره» را بزنید. |
| خط ارسال انتخاب‌شده برای این کلید مجاز نیست | خط ارسال را خالی بگذارید، یا یکی از خط‌های حساب خودتان را وارد کنید. |
| متن پیامک پذیرفته نشد | متن شامل عبارتی است که در پنل مجاز نیست. متن را اصلاح کنید. |
| اتصال به پنل برقرار نشد / API در دسترس نیست | آدرس پنل را بررسی کنید. خطاهای موقت خودکار دوباره تلاش می‌شوند؛ جزئیات آن‌ها در **ووکامرس ← وضعیت ← کارهای زمان‌بندی‌شده** (گروه `ellsms`) است. |

هر خطا یک **کد پیگیری** دارد. برای پیگیری، آن را به پشتیبانی بدهید.

**حذف افزونه** تنظیمات و کلید ذخیره‌شده را هم پاک می‌کند، ولی یادداشت‌های سفارش‌ها می‌مانند.

## SDKها (برای برنامه‌نویس‌ها)

هر سه SDK یک امکانات دارند:

- ارسال پیامک، ارسال گروهی، پیش‌نمایش هزینه، مخاطبین، اعتبار، وب‌هوک‌ها
- وضعیت تحویل پیام (message state) بدون وب‌هوک
- بررسی امضای وب‌هوک
- تلاش دوباره‌ی **امن** برای خطاهای موقت: درخواستی که ممکن است پیامک را دو بار بفرستد، هیچ‌وقت خودکار تکرار نمی‌شود.

همه‌ی خطاها به یک نوع خطا تبدیل می‌شوند که این اطلاعات را دارد:

- `status`: کد HTTP
- `code`: مثل `validation_failed`، `unauthenticated` یا `rate_limited`
- `fields`: فیلدهای نامعتبر
- `requestId`: کد پیگیری

### PHP (نسخه‌ی ۷٫۴ به بالا، بدون وابستگی)

فایل «SDK زبان PHP» را از بالای صفحه دانلود و در پروژه باز کنید. اگر از Composer استفاده می‌کنید، مسیر پوشه را به‌صورت یک مخزن `path` معرفی کنید. در غیر این صورت سه فایل پوشه‌ی `src` را `require` کنید.

```php
<?php
require 'ellsms-php/src/EllsmsException.php';
require 'ellsms-php/src/Webhook.php';
require 'ellsms-php/src/Client.php';

$sms = new Ellsms\Client(getenv('ELLSMS_API_KEY'), 'https://datisrayan.com');

try {
    // ارسال به حداکثر ۱۰۰ شماره در یک درخواست
    $result = $sms->sendMessage(['09121234567'], 'کد تأیید شما: 4821', [
        'client_message_id' => 'otp-user-42-' . date('YmdHi'), // تکرار با همین شناسه = بدون ارسال دوباره
    ]);
    echo $result['status']; // sent | partially_sent | failed

    echo $sms->balance()['available'];

    // ارسال گروهی (در صف؛ برای هزاران گیرنده)
    $job = $sms->createBulkJob([
        'type' => 'p2p', 'title' => 'کمپین مهر',
        'items' => [['mobile' => '09121234567', 'content' => 'سلام علی، تخفیف شما آماده است']],
    ]);
    echo $sms->getBulkJob($job['id'])['status'];

    // همه‌ی مخاطبین، صفحه‌به‌صفحه
    foreach ($sms->eachContact() as $contact) {
        echo $contact['mobile'], PHP_EOL;
    }
} catch (Ellsms\EllsmsException $e) {
    echo $e->getStatus(), ' ', $e->getErrorCode(), ' ', $e->getMessage(), ' ', $e->getRequestId();
}
```

### جاوااسکریپت / Node.js (نسخه‌ی ۱۸ به بالا، بدون وابستگی، همراه TypeScript)

فایل «SDK جاوااسکریپت» را دانلود و باز کنید. سپس در پروژه‌ی خودتان:

```
npm install ./ellsms-js
```

```js
const { EllsmsClient, EllsmsError } = require('ellsms'); // یا: import { EllsmsClient } from 'ellsms';

const sms = new EllsmsClient({ apiKey: process.env.ELLSMS_API_KEY, baseUrl: 'https://datisrayan.com' });

try {
  const result = await sms.sendMessage(['09121234567'], 'سفارش شما ارسال شد', { client_message_id: 'order-1001-shipped' });
  console.log(result.status);

  for await (const contact of sms.eachContact()) console.log(contact.mobile);
} catch (e) {
  if (e instanceof EllsmsError) console.error(e.status, e.code, e.message, e.requestId);
}
```

این SDK فقط برای **سرور** (Node.js) است. کلید API را هرگز در کد مرورگر نگذارید.

### پایتون (نسخه‌ی ۳٫۸ به بالا، فقط کتابخانه‌ی استاندارد)

فایل «SDK پایتون» را دانلود و باز کنید. سپس:

```
pip install ./ellsms-python
```

```python
import os
from ellsms import Client, EllsmsError

sms = Client(os.environ["ELLSMS_API_KEY"], "https://datisrayan.com")
try:
    result = sms.send_message(["09121234567"], "نوبت شما فردا ساعت ۱۰ است", client_message_id="visit-77-reminder")
    print(result["status"])
    for contact in sms.each_contact():
        print(contact["mobile"])
except EllsmsError as e:
    print(e.status, e.code, e.message, e.request_id)
```

### ارسال بدون تکرار (مهم)

- **`client_message_id`:** در ارسال تکی، یک شناسه‌ی یکتا از سمت خودتان بفرستید (مثلاً شماره‌ی سفارش + رویداد). اگر درخواست با همان شناسه و همان متن تا ۲۴ ساعت دوباره برسد، پیامک دوباره ارسال نمی‌شود و همان جواب قبلی برمی‌گردد. با این شناسه، SDK بعد از قطعی شبکه خودش با خیال راحت دوباره تلاش می‌کند.
- **بدون شناسه:** هر درخواست یک پیامک جدید است و SDK آن را بعد از خطای شبکه **تکرار نمی‌کند**، چون ممکن است پیامک قبلاً رفته باشد.
- **ارسال گروهی:** همیشه یک کلید یکتا (Idempotency-Key) دارد. اگر خودتان کلیدی ندهید، SDK آن را می‌سازد. اگر می‌خواهید بعد از ری‌استارت برنامه‌تان هم تکرار امن بماند، کلید را خودتان بسازید و نگه دارید.
- **خطای ۴۲۹ (درخواست زیاد):** SDK خودکار صبر می‌کند و دوباره تلاش می‌کند.

### بررسی امضای وب‌هوک

اگر در پنل وب‌هوک تعریف کرده‌اید (مثلاً برای خبر «پیامک تحویل شد»)، قبل از اعتماد به هر درخواست، امضای آن را بررسی کنید. همیشه **بدنه‌ی خام** درخواست را بدهید، نه JSON پردازش‌شده.

```php
$event = Ellsms\Webhook::fromGlobals(getenv('ELLSMS_WEBHOOK_SECRET'));
if ($event === null) { http_response_code(401); exit; }
```

```js
const ok = verifyWebhook(secret, req.headers['x-datis-timestamp'], rawBody, req.headers['x-datis-signature']);
```

```python
ok = verify_webhook(secret, headers["X-Datis-Timestamp"], raw_body, headers["X-Datis-Signature"])
```

### وضعیت تحویل پیام (message state)

پاسخ ارسال فقط می‌گوید پیامک به اپراتور سپرده شد. برای این‌که بدانید به گوشی گیرنده رسیده یا نه، وضعیت تحویل را با شناسه‌ی همان ارسال بپرسید. این روش به وب‌هوک و آدرس HTTPS عمومی نیاز ندارد (روی localhost هم کار می‌کند) و هزینه‌ای ندارد.

| مسیر | دسترسی | برای |
|---|---|---|
| `GET /api/v1/messages/{id}/state` | `messages:read` | ارسال تکی و گروهی، همه‌ی گیرنده‌ها |
| `GET /api/v1/otp/{id}/state` | `otp:send` | کد تایید خط اشتراکی |
| `GET /api/v1/bulk-jobs/{id}/state` | `bulk:read` | ارسال انبوه: تعداد هر وضعیت و گیرنده‌ها صفحه به صفحه |

`{id}` همان شناسه‌ای است که هنگام ارسال برگشته است. هر سه مسیر با `POST` هم دقیقاً همین پاسخ را می‌دهند (بدنه لازم نیست؛ در ارسال انبوه می‌توانید `limit`، `after` و `state` را در بدنه‌ی JSON بفرستید).

**شناسه‌ها — پنل و API یک شناسه دارند:**

| شناسه | شکل | یعنی | کجا دیده می‌شود |
|---|---|---|---|
| شناسه‌ی پیام | ۱۵ رقم، مثل `222092113875936` | یک پیام به یک گیرنده | ستون «شناسه» گزارش پنل، `recipients[].id` در API، `public_id` در وب‌هوک تحویل |
| شناسه‌ی ارسال | ۱۶ رقم، مثل `4821730095512264` | کل یک ارسال API با همه‌ی گیرنده‌هایش | `id` پاسخ `POST /api/v1/messages`؛ در پنل با نام «شناسه‌ی API» زیر شناسه‌ی ردیف |

- هر دو تصادفی‌اند و به‌صورت رشته برمی‌گردند. هر دو را می‌توانید به‌جای `{id}` در `/api/v1/messages/{id}/state` بگذارید.
- **هر پیامی:** با شناسه‌ی پیام، وضعیت تحویل پیامی را هم که با API نفرستاده‌اید (از خود پنل، زمان‌بندی، منشی یا ارسال حجیم) می‌توانید بپرسید. پاسخ همان شکل را دارد، با یک گیرنده.
- شناسه‌های قبلی همچنان کار می‌کنند: `D34` و `B12` گزارش پنل، و شماره‌های کوتاه ارسال‌های قدیمی (مثل `482`).
- «شناسه اپراتور» (عددی حدوداً ۱۹ رقمی) در API پذیرفته نمی‌شود و خطای `404` می‌گیرید.

```
curl https://datisrayan.com/api/v1/messages/4821730095512264/state -H "Authorization: Bearer $DATIS_API_KEY"
curl -X POST https://datisrayan.com/api/v1/messages/4821730095512264/state -H "Authorization: Bearer $DATIS_API_KEY"
curl https://datisrayan.com/api/v1/messages/222092113875936/state -H "Authorization: Bearer $DATIS_API_KEY"

curl https://datisrayan.com/api/v1/otp/57/state -H "Authorization: Bearer $DATIS_API_KEY"

curl "https://datisrayan.com/api/v1/bulk-jobs/91/state?limit=2" -H "Authorization: Bearer $DATIS_API_KEY"
curl "https://datisrayan.com/api/v1/bulk-jobs/91/state?limit=2&after=NEXT_CURSOR" -H "Authorization: Bearer $DATIS_API_KEY"
curl "https://datisrayan.com/api/v1/bulk-jobs/91/state?state=delivered" -H "Authorization: Bearer $DATIS_API_KEY"
```

```
{ "data": {
  "id": "4821730095512264", "send_status": "sent", "state": "partially_delivered", "final": true,
  "summary": { "delivered": 1, "undelivered": 1, "sent": 0, "failed": 0,
               "unknown": 0, "not_available": 0, "queued": 0, "cancelled": 0 },
  "recipients": [
    { "id": "222092113875936", "destination": "989121234567", "state": "delivered",
      "delivered_at": "2026-11-10 11:00:12", "checked_at": "2026-11-10 11:01:00" },
    { "id": "150114141852808", "destination": "989351112233", "state": "undelivered",
      "delivered_at": null, "checked_at": "2026-11-10 11:01:00" }
  ],
  "created_at": "2026-11-10 11:00:05" } }
```

مقدارهای `state` هر گیرنده:

| state | معنی |
|---|---|
| `delivered` | اپراتور تحویل به گوشی گیرنده را تأیید کرد |
| `undelivered` | به گوشی نرسید (رد شد، ناموفق بود یا مهلتش تمام شد) |
| `sent` | به اپراتور سپرده شده و گزارش نهایی هنوز نیامده |
| `failed` | اصلاً به اپراتور سپرده نشد |
| `queued` | هنوز ارسال نشده (ردیف ارسال انبوه در صف، یا کد تایید در حال ارسال) |
| `cancelled` | ردیف ارسال انبوه پیش از ارسال لغو شد |
| `unknown` | اپراتور وضعیت را «نامشخص» اعلام کرد |
| `not_available` | گزارش تحویلی وجود ندارد (ارسال با بله، خط بدون گزارش تحویل، یا سابقه‌ی ارسال پیدا نشد) |

مقدارهای `state` کل ارسال:

| state | معنی |
|---|---|
| `delivered` | به همه‌ی گیرنده‌ها رسید |
| `partially_delivered` | به بخشی از گیرنده‌ها رسید |
| `sent` | دست‌کم یک گیرنده هنوز منتظر گزارش اپراتور است |
| `undelivered` | به هیچ گیرنده‌ای نرسید |
| `sending` | فقط ارسال انبوه: ارسال هنوز ادامه دارد |
| `cancelled` | فقط ارسال انبوه: همه‌ی ردیف‌ها لغو شدند |
| `unknown` | اپراتور وضعیت را «نامشخص» اعلام کرد |
| `not_available` | برای این ارسال گزارش تحویلی وجود ندارد |

- هر چند دقیقه یک بار بپرسید، نه هر ثانیه. وقتی `final` برابر `true` شد، وضعیت دیگر تغییر نمی‌کند.
- `delivered_at` و `checked_at` به وقت UTC هستند. فقط شماره و وضعیت برمی‌گردد، نه متن پیام.
- در ارسال انبوه `limit` بین ۱ تا ۱۰۰۰ است (پیش‌فرض ۱۰۰)؛ مقدار `next_cursor` را در `after` بگذارید تا وقتی `null` شود. `summary` همیشه کل ارسال را می‌شمارد.

با SDK (نسخه‌ی ۱٫۱٫۰ به بالا) همین کار یک خط است:

| کار | PHP | جاوااسکریپت | پایتون |
|---|---|---|---|
| وضعیت تحویل یک ارسال | `getMessageState($id)` | `getMessageState(id)` | `get_message_state(id)` |
| وضعیت تحویل کد تایید | `getOtpState($id)` | `getOtpState(id)` | `get_otp_state(id)` |
| یک صفحه از ارسال انبوه | `getBulkJobState($id, $options)` | `getBulkJobState(id, options)` | `get_bulk_job_state(id, limit, after, state)` |
| همه‌ی گیرنده‌های ارسال انبوه | `eachBulkJobRecipient($id, $state)` | `eachBulkJobRecipient(id, { state })` | `each_bulk_job_recipient(id, state)` |

```php
$state = $sms->getMessageState($result['id']);
if ($state['final']) {
    foreach ($state['recipients'] as $r) echo $r['destination'], ' ', $r['state'], PHP_EOL;
}
// ارسال انبوه: فقط گیرنده‌هایی که پیام به آن‌ها نرسیده
foreach ($sms->eachBulkJobRecipient($job['id'], 'undelivered') as $r) echo $r['destination'], PHP_EOL;
```

```js
const state = await sms.getMessageState(result.id);
if (state.final) for (const r of state.recipients) console.log(r.destination, r.state);
for await (const r of sms.eachBulkJobRecipient(job.id, { state: 'undelivered' })) console.log(r.destination);
```

```python
state = sms.get_message_state(result["id"])
if state["final"]:
    for r in state["recipients"]:
        print(r["destination"], r["state"])
for r in sms.each_bulk_job_recipient(job["id"], state="undelivered"):
    print(r["destination"])
```

اگر SDK قدیمی‌تر (۱٫۰٫۰) دارید، فایل SDK را دوباره از بالای همین صفحه دانلود کنید.

### کدهای خطای رایج

| کد HTTP | `code` | معنی |
|---|---|---|
| 401 | `unauthenticated` | کلید نادرست، باطل یا منقضی |
| 402 | `invalid_request` | ارسال انجام نشد (مثلاً اعتبار کافی نیست). متن خطا دلیل را می‌گوید. |
| 403 | `forbidden` | کلید این دسترسی را ندارد |
| 404 | `not_found` | مورد وجود ندارد یا مال سازمان دیگری است |
| 409 | `conflict` | مخاطب تکراری، یا شناسه‌ی تکراری با متن متفاوت |
| 422 | `validation_failed` | ورودی نامعتبر. جزئیات در `fields` است. |
| 429 | `rate_limited` / `quota_exceeded` | درخواست زیاد (SDK خودش صبر می‌کند)، یا سقف پلن تمام شده |
| 503 | `service_unavailable` | API پنل موقتاً در دسترس نیست |

مرجع کامل API برای برنامه‌نویس‌ها: فایل `docs/public-api.md` و `docs/openapi-v1.yaml` در مخزن پروژه.
