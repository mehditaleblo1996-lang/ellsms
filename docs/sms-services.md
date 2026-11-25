# «خدمات پیامک» — SMS services page (`/sms-services`)

The public page that presents the SMS services in depth: what each kind of message is for, how a
message travels through the infrastructure, and where its delivery report comes back. It belongs to
the public marketing site and uses its white + graphite + turquoise identity, nav and footer
([landing.md](landing.md#colours-public-site-and-sign-in-pages)). The home page's `#product-sms`
showcase stays a short summary and links here.

| File | What |
|---|---|
| `public/sms-services.php` | The page: every section below; its content is the arrays at the top (`$ssLife`, `$ssServices`, `$ssScenarios`, `$ssSimStages`, `$ssStory`, `$ssIndustries`) and the flow drawings are built by `$ssFlowSvg` |
| `public/assets/css/sms-services.css` | Everything for this page (prefix `.ss-`). Loaded only by this page through the public header's `$extraStyles`. |
| `public/assets/js/sms-services.js` | The page's behaviour: packets along the drawings, the explorer tabs, the simulator, the scroll story, the industries rail. Plain JS, no library (the CSP allows only same-origin scripts). |
| `app/views/public_header.php` | The nav link «خدمات پیامک» (marked current when `$lpNavCurrent === 'sms'`) and the optional `$lpBodyClass` (this page sets `ss-body`, which hides the shared wave background) |

The text is fixed HTML; there is no table, setting, admin page or migration behind it. To change the
wording, edit the arrays at the top of `sms-services.php`.

**Nothing on the page sends anything.** The diagrams, the explorer's flows and the simulator are
illustrative: no request is made, no API is called, no figures are live. The page says so next to
each of them. No customer names, partnerships, testimonials or performance figures are shown; the
industry scenarios are labelled as samples.

## URL and routing

- Clean URL `/sms-services` → `sms-services.php` (`docker/apache-clean-urls.conf`); a GET of
  `/sms-services.php` is redirected (301) to `/sms-services`, and `app/Support/AuditPrepend.php`
  maps it in `Location` headers. `tests/Unit/CleanUrlRoutingTest.php` checks all three.
- On a reseller's own branded host the page redirects to `/login`, like the landing page.

## Where it is linked

- **Site nav** (every public page): «خدمات پیامک», after «محصولات».
- **Home page** `#product-sms`: the button «همه‌ی خدمات پیامک» (the former «راهنمای استفاده» button
  is now a text link under it).

## Consultation links

«دریافت مشاوره تخصصی» and «طراحی راهکار پیامکی سازمان شما» open the existing «تماس با ما» form at
`/contact?subject=…` with the subject «مشاوره راهکار پیامکی سازمانی» filled in. `contact.php` reads
`?subject=` only to prefill that field (trimmed to its 190-character limit); a posted value still
wins. The form and its Telegram relay are unchanged.

## Sections, top to bottom

| Anchor | Section |
|---|---|
| — | **Hero** (asymmetric): the page's `<h1>` «هر پیام، یک ارتباط مؤثر.», the line «زیرساخت یکپارچه ارسال، مدیریت و رهگیری پیامک‌های سازمانی، خدماتی و تبلیغاتی.», «دریافت مشاوره تخصصی» and «کشف قابلیت‌ها» (→ `#explorer`), a three-part spec line (اتصال، انواع پیام، رهگیری). Beside it the **life-of-a-message drawing**: six numbered stations on a closed loop — نرم‌افزار کسب‌وکار → درگاه داتیس رایان → پردازش پیام → ارسال به اپراتور → گیرنده → گزارش تحویل, which climbs back to the application. Packets travel the loop (after the recipient the packet turns white: it is the delivery report); the station the lead packet is at is highlighted, named in the centre readout and in the numbered legend under the drawing (the legend is the accessible version of the drawing). |
| `#explorer` | **۰۱ قابلیت‌ها** — «پنج نوع پیام، پنج مسیر متفاوت». A vertical numbered list (ARIA tabs, arrow keys / Home / End) of احراز هویت و کد تأیید، پیامک خدماتی، پیامک تبلیغاتی، پیامک سیستمی و خودکار، گزارش‌گیری و رهگیری. The panel shows the service's lead, its flow drawing (each kind has its own geometry: the OTP code loops back for verification; customer fields merge into the template; a campaign fans out to many recipients; a monitor wakes up periodically and the acknowledgement closes the cycle; the report returns on a lower lane and lights «در صف / ارسال‌شده / تحویل‌شده»), a sample message (labelled «نمایشی»), four use cases and where it lives in the panel. Lines draw in and a packet runs when a service is chosen. |
| `#simulator` | **۰۲ شبیه‌ساز** — choose a scenario (کد تأیید ورود، تأیید سفارش، یادآوری نوبت، اطلاع تراکنش، کمپین تبلیغاتی); the message appears with its character count and SMS parts (computed in the browser: 70/67 characters per part for Persian, 160/153 for Latin), then a packet moves through دریافت درخواست → پردازش پیام → ارسال به اپراتور → تحویل پیام → گزارش وضعیت and each stage writes a line to the log (`aria-live`). Runs once when it comes into view; «اجرای دوباره» and a new scenario replay it. |
| `#how` | **۰۳ سازوکار** — scroll story. Five stages (اتصال کسب‌وکار، دریافت و پردازش درخواست، مسیریابی و ارسال پیام، رهگیری وضعیت تحویل، گزارش‌گیری و تحلیل) scroll normally beside a sticky drawing that adds one layer per stage (systems and API → gateway checks → operators and recipients → DLR return → reports and webhook). Earlier layers stay, dimmed; the current one carries the packets. No scroll-jacking. |
| `#industries` | **۰۴ کاربردها** — a horizontal, scroll-snapped rail of five scenarios (بانک و خدمات مالی، فروشگاه اینترنتی، سلامت و درمان، سازمان‌های بزرگ، پلتفرم‌های دیجیتال), each with a custom line glyph, «مسئله», «راهکار پیامکی» and a sample line. Swipe, scroll, arrow keys (the rail is focusable) or the two buttons. |
| — | **Closing band** (graphite): «زیرساخت ارتباطی کسب‌وکار شما، از همین‌جا شروع می‌شود.», «طراحی راهکار پیامکی سازمان شما» (consultation) and the panel login. |

## Design notes

- Colours are only the `.lp-body` tokens (`--primary`, `--primary-hover`, `--primary-ink`,
  `--primary-light`, `--foreground`, `--muted-foreground`, `--border`, `--dark-surface`); the logo is
  untouched. The page's own look is an engineering drawing: hairlines, sharp corners, large outlined
  section numbers, numbered stations; the gateway is the one graphite box in each drawing.
- Type is the site's Estedad; Latin technical labels (GATEWAY, DLR …) use `--font-mono` and are
  set left-to-right inside the RTL drawings.
- **Responsive**: under 1080 px the hero, explorer and simulator stack and the explorer's list becomes
  a swipeable row; under 860 px the story drawing sticks under the nav above the text; under 640 px
  the explorer's flow becomes a vertical numbered rail (the SVG is hidden), the scenario picker is a
  swipeable row above the console and the simulator's pipe turns vertical. No hover-only behaviour.
- **Motion**: packets are moved with `getPointAtLength` in `requestAnimationFrame`, paused off screen
  and in a hidden tab. With `prefers-reduced-motion` no packet is created, every line is drawn, the
  simulator shows all stages complete, and transitions are off.
