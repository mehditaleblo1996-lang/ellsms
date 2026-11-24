# LBA — location-based sending (ارسال پیام منطقه‌ای)

Send to the people who are, or were, in an area: a polygon on the map, a list of cell towers, a city, a
province or an airport, filtered by age, gender, device and residence. The audience comes from the
operators, through a provider's "smart SMS" service. ELLSMS never sees a subscriber number. The only
provider today is PishgamRayan (`https://smsapi.pishgamrayan.com`), built to its guide «راهنمای مرجع و جامع
وب‌سرویس ارسال پیامک هوشمند», revision 2.8.

Code:

- `app/Lba.php`: the domain logic.
- `app/Lba/PishgamSmartSms.php`: the provider client and its status codes.
- `public/lba.php`: the customer page, at `/messages/lba`.
- `public/lba-gateways.php`: the admin page, at `/admin/lba-gateways`.
- `public/assets/js/lba-map.js`: the map.
- `cron/lba-worker.php`: the worker.

Schema: `db/migrations/2026_10_27_lba.sql`, plus `db/migrations/2026_11_27_lba_call_log.sql` for
«جزئیات فنی» (see [Technical details](#technical-details-admins)).

## LBA gateways are their own kind

An LBA gateway is **not** one of the SMS gateways on «درگاه‌های پیامک», and it carries no ordinary
messages. Each number gets an LBA gateway of its own, separately from its ordinary gateway:

- Set it on **درگاه‌های LBA → شماره‌ها**, or in the «درگاه LBA» column of **شماره‌ها**.
- The column is `ellsms_numbers.lba_gateway_id`.
- A number with no LBA gateway does not appear on the LBA page.
- The number's ordinary sends are unaffected.

## Setup (platform admin)

1. **At PishgamRayan:**
   - Register this server's public IP in their panel: کاربران → آی‌پی‌های امن, with «استفاده برای ساخت
     توکن» ticked.
   - Wait for their admin to approve it (031-34049). Without that, every call fails with code 35.
   - Create a token with «ارسال پیام» and have it approved as well (codes 34 and 62 otherwise).
   - Foreign and private IPs are not accepted.
2. **`SMS_GATEWAY_MASTER_KEY`** (at least 32 characters) must be set. The token, and the optional
   panel username and password, are stored with AES-256-GCM, under a key derived from it with its own
   purpose label (`ellsms_lba_gateway_secrets`).
   - Nothing secret is ever shown again, logged or audited.
   - A blank field on edit keeps the stored value.
   - The username and password are only kept for reference: the API uses the token alone.
3. **Apply the migration** (`make db-migrations-apply`).
4. **On /admin/lba-gateways**, add a gateway:
   - name and code
   - address (the default is PishgamRayan's)
   - token
   - **tariff per part**, one price for Persian (Unicode) and one for English
   - **فعال** (enabled)

   Then use «بررسی اتصال», which reads the province list. A gateway can be used only once it is
   enabled, has a token and has both prices.
5. **Pin the gateway to the numbers** that should send LBA.
6. KYC gate `lba` is **required by default**, like regional bulk. Change it on the KYC gates page.

Customers see «ارسال پیام منطقه‌ای (LBA)» once one of the lines they can send from has a usable LBA
gateway. Admins always see it. Menu roles can close it (`lba`).

## The flow (as the provider defines it)

| Step | ELLSMS | Provider method |
|---|---|---|
| Gray lists | create, delete, readiness per operator | `CreateSmartSmsCampaign`, `DeleteSmartSmsCampaign`, `GetSmartSmsCampaignCreateStatus` |
| Clubs | create, delete, add numbers (a pasted list or a .txt/.csv file, sent 100 at a time) | `CreateSmartSmsClub`, `DeleteSmartSmsClub`, `AddNumbersToSmartSmsClub` |
| Audience | one of eight kinds, below | `Create*SmartSmsRequest` |
| Building | the worker polls every minute until the status is 0 (completed). It then stores the per-operator segments (id, count, provider prices, coordinates). It gives up after 2 days. | `RefreshSmartSmsRequest`, `GetSmartSmsRequestById` |
| One-off send | operator, segments with start index and count, text, optional time | `SendSimpleSmartSms` |
| Periodic send | percent or count per period, cap, daily cap, hours of day, continue on operator error | `Send{Percentage,Count}PeriodicSmartSms` |
| Edit periodic | everything but the start and the operators; percent ↔ count | `Update{Percentage,Count}PeriodicSmartSms` |
| On / off | | `UpdatePeriodicSmartSmsRequestActivation` |
| Delete | settles a periodic send | `DeleteSmartSmsRequest` |
| Statistics | periodic, daily (Jalali day range, operator), financial, per send (delivery) | `GetPeriodicSmartSmsStatistics`, `GetSmartSmsRequestDailyStatistics`, `GetSmartSmsFinancialStatistics`, `GetSmartOutboxMessageAggregatedStatistics` |
| Data | provinces, cities, airports, device brands and types (cached a day); polygon coverage check | `GetProvinces`, `GetCities`, `GetAirports`, `GetDeviceBrands`, `GetDeviceTypes`, `CheckPolygonCells` |

### Audience kinds

| Kind | Method | Targets | Extra |
|---|---|---|---|
| ساده | `CreateSimpleSmartSmsRequest` | polygons + filters | start/end at most **11 hours** apart |
| خوشامدگویی | `CreateWelcomeSmartSmsRequest` | polygons + filters | gray list + hours in it |
| خوشامدگویی با سلول | `CreateWelcomeSmartSmsRequestWithCellList` | `cellList` instead of polygons | gray list |
| باشگاه | `CreateClubSmartSmsRequest` | polygons + filters | club, members only / everyone but members, gray list |
| شهر / استان / فرودگاه | `CreateWelcome{City,Province,Airport}SmartSmsRequest` | cities / provinces / one airport | gray list, residence province and state |
| دوره‌ای | `CreatePeriodicSmartSmsRequest` | polygons + filters | no time range: it is set at send time |

**Filters** (the base model):

- `fromAge` (at least 18) and `toAge`
- `gender` (0 male, 1 female)
- `deviceType` and `deviceBrand`
- `residenceProvinces` and `residenceState` (0 resident, 1 guest, 2 all)
- `employmentStatus` and `passengerState`
- `numberprefixes`: up to 3, each 4–8 digits starting with 98. `0912` is read as `98912`.

**Checked here before the provider sees them:**

- Operators are 1 MCI, 2 Irancell, 3 Rightel.
- Dates are Jalali, `YYYY/MM/DD HH:MM:SS` in Tehran time. Persian digits are accepted.
- Polygons:
  - Points are `[longitude, latitude]`, inside Iran (this also catches a swapped pair).
  - Each polygon has at least 3 distinct points and is closed. A point clicked twice in a row is dropped.
  - Its edges must not cross or touch (refused with «خطوط محدوده‌ی … یکدیگر را قطع می‌کنند»).
  - It is sent **clockwise**, as in every example of the guide (pages 21–22); a counter-clockwise
    drawing is reversed. The guide does not say whether direction matters. Sending one direction
    keeps every request identical in shape to the documented example.
  - The guide sets no area limit, but `CheckPolygonCells` «validates the polygon's area» and the
    guide's examples are blocks of about 100 × 150 m (≈ 9,000 m²). The map shows the drawn area
    («مساحت تقریبی») so an unusually large one (a whole city district) is visible before sending.
  - At most 20 polygons, of at most 500 points each.
- A gray list or club must belong to the customer and to the same gateway as the line.
- A periodic period is 10 minutes to 11 hours.

The provider requires unique titles in its account, and every customer of a gateway shares that
account. So each title is sent as `<title> #<local id>`. When an answer is lost, the id the provider
created is recovered by finding that title in the `GetCurrentOrganization*` list.

## Map

The polygon editor is self-contained JS (`lba-map.js`, no library) over OpenStreetMap tiles.

- **Drawing:** click to add points, drag to pan, scroll to zoom. «محدوده‌ی بعدی» starts another
  polygon. Points can go either way round; the server sends them clockwise. The bar under the map
  shows the number of polygons and points and the approximate area in m² / km².
- **Typed coordinates:** JSON or GeoJSON can be pasted instead. «درج محدوده‌ی نمونه» puts the guide's own
  example area (by Milad Tower, ≈ 9,000 m²) on the map, to tell a problem with the area from a problem with the account.
- **Zoom:** the wheel, or the + / − buttons on the map's corner.
- **Coverage:** «بررسی پوشش اپراتورها» calls `CheckPolygonCells` and shows one badge per operator
  («پوشش دارد» / «پوشش ندارد»). On a failure an admin also sees the raw request and answer of every
  attempt under the button.
  - **Two shapes of `polygon`.** The guide types `polygon` as "array of polygon" but has no request
    example for this method. The live service answered that shape with a bare `statusCode 25`, even for
    the guide's own example area, while `CreateSimpleSmartSmsRequest` accepted the same area.
  - `lba_check_polygons()` therefore tries the documented shape (`polygon` = the list of polygons). On a
    25 it tries one call per polygon (`polygon` = that polygon's points), and the reverse when that order
    is the remembered one.
  - The shape that worked is remembered per gateway (setting `lba_check_shape.<gateway id>`, `list` or
    `ring`) and tried first next time. Any other refusal is not retried.
  - With several polygons, an operator «پوشش دارد» only if it covers every one of them.
  - The check is optional: a request can be created without it.
- **Tile server:** setting `lba_map_tile_url`, https only. Only `/messages/lba` adds the tile server's
  origin to its Content-Security-Policy `img-src` (`send_security_headers()` takes extra image origins).

## The provider's name stays hidden from customers

Customers see the service only as «ارسال پیام منطقه‌ای (LBA)». Nothing a customer can see on
`/messages/lba` names PishgamRayan, including the page source:

- **Messages:** errors, flash messages, hints and confirmations say «سرویس ارسال منطقه‌ای» or nothing.
- **Filter:** `lba_public_text()` replaces the name in every error shown on the page. This covers errors
  stored before this rule and the provider's own `message`. `lba_call_error()` applies it.
- **Admins only:**
  - the gateway-name chip (an admin may name a gateway after the provider; the default name is now `LBA`);
  - the provider's per-part base prices in «بخش‌های مخاطبان»;
  - «آمار مالی سرویس‌دهنده» (the provider account's cost and refunds), also refused on the server for customers;
  - «جزئیات فنی».
- **Where the name stays:** the admin page `/admin/lba-gateways`, which only admins reach.
- **Test:** `tests/Unit/LbaPublicTextTest.php`.

## Page layout

`/messages/lba` (`public/lba.php`, styles in `public/assets/css/lba.css`) uses the panel's tokens:

- **Header card:** the sender line, chips for the gateway and its per-part tariff, and the tabs
  «درخواست‌ها و ارسال‌ها», «درخواست جدید», «لیست‌های خاکستری», «باشگاه‌ها».
- **«درخواست جدید»:** numbered sections («مشخصات درخواست», «محدوده‌ی جغرافیایی», «فیلترهای مخاطب»,
  «محل سکونت», …). Only the sections of the chosen request kind are shown, and numbered.
  Operators and gender are toggle chips. «ثبت درخواست» sits in a bar that stays at the bottom of the screen.
- **A request:** key facts as tiles (kind, line, audience size, created), a status badge, the
  criteria, the map, the audience segments, then the send forms, the sends and the statistics.
- **Lists:** requests, gray lists and clubs show their status as coloured badges.

## Technical details (admins)

PishgamRayan often answers a failure with a bare `statusCode 25` («ناموفق.») and a one-word message
(«خطا»). Its support needs the exact request to say why. So every provider call that fails is kept, for
platform admins only:

- **Table:** `ellsms_lba_call_log`, from `db/migrations/2026_11_27_lba_call_log.sql`. Written by
  `lba_debug_record()` from `lba_pishgam_call()`. Each row holds the method, the query and JSON body as
  sent, the HTTP status, the `statusCode`, the error and the raw answer.
- **The token is never stored.** It travels in a header that is not logged, and the request text shows
  `Authorization: <token>` instead.
- **Retention:** the newest 50 rows per gateway (`LBA_DEBUG_KEEP`). Older rows are deleted as new ones are
  written, so no cleanup job is needed.
- **Successful calls are not logged.**
- **No migration, no log:** without the table nothing is logged, and LBA keeps working.
- **Where admins see it:**
  - The «جزئیات فنی» card at the bottom of `/messages/lba` (requests, new-request and request pages) lists
    the line gateway's latest failures.
  - Each failure shows «درخواست ارسالی» and «پاسخ پیشگام» side by side, with «کپی» buttons to paste the
    text to PishgamRayan's support.
  - A failed «بررسی پوشش اپراتورها» shows the same right under the button.
  - Any other failed action's message ends with «… در «جزئیات فنی» پایین صفحه است».

## Money

Prices are in Rial, from the customer's wallet. Platform admins are not charged.

- **Price of a send:** recipients × parts × the gateway's tariff in force at that moment. The tariff
  is `price_fa_part` for a Unicode message and `price_en_part` otherwise.
- **Every message sent is charged**, delivered or not. The provider's own refunds for undelivered
  messages (`refundedPrice`) stay with the platform.
- **One-off send:**
  - The cost is reserved, then committed once the provider accepts the send.
  - A refusal releases it.
  - An answer that never arrives leaves the send **نامشخص**, with the money still reserved. A platform
    admin settles it on /admin/lba-gateways: «ارسال شده» commits the money, «ارسال نشده» refunds it.
- **Periodic send:**
  - `maxSendCount` is optional at the provider but **required here**, because
    `maxSendCount × parts × tariff` is what gets reserved.
  - Every 5 minutes the worker commits what `GetPeriodicSmartSmsStatistics` reports as sent: the sum of
    `partsCount` × tariff, capped by the reservation.
  - Once the period has been over for a day, or the request is deleted, it settles and releases the
    rest.
  - Raising the cap reserves the difference first.
  - A lost answer to the start call is treated as running, because only what the statistics report is
    ever charged.

## Provider status codes

`LBA_STATUS_MESSAGES` in `app/Lba/PishgamSmartSms.php` holds the guide's whole table, 0–69, in Persian.
Requests also carry the provider's own `message`, and its `validationErrors` (61) when it sends them.

- Request states: 0 completed, 1 pending, 2 failed, 3 not allowed, 4 expired, 5 active, 6 inactive,
  7 partly completed (still polled, like 1), 8 finished (a periodic request whose campaign is over).
- Delivery states: 1 delivered, 2 not delivered, 3 sent to the operator, 4 not sent to the operator,
  5 blacklisted, 6 invalid line.

## Not covered

`GetSmartSmsPrivateNumbersByOperator` is named in the guide's change log but not documented, so it is
not used. The `CreateWelcomePeriodicSmartSmsRequest` and `CreateClubPeriodicSmartSmsRequest` variants
are not offered either: the guide only says they combine the periodic and welcome/club models, and
defers to its Swagger file.

## Tests

- `tests/Integration/LbaTest.php` runs against `tests/fixtures/fake_pishgam_smartsms_server.php`. It
  covers:
  - Secrets, and the raw token in `Authorization`.
  - Gateway, number and line availability.
  - Gray lists and clubs, including ownership, the 100-number batches and lost-answer recovery.
  - Every request payload and validation rule.
  - Audience building.
  - One-off send money, and the unknown-outcome settlement.
  - Periodic reserve, charge, edit, on/off and settlement.
  - Deletion.
  - Local checks: KYC, the line, the gateway, credit and tenancy.
  - Statistics, lookups and Jalali parsing.
  - The failed-call log: no token, successes not kept, 50 rows per gateway.
  - The coverage check falling back to one ring per polygon (fake server's `check_ring_only`) and remembering it.
- `tests/Unit/LbaPolygonTest.php` covers ring direction, crossing edges and double clicks.
- `tests/Integration/LbaHttpTest.php` covers the pages over HTTP.
