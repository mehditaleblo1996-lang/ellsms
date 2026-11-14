# Public landing page (`/landing.php`)

The marketing home page that visitors who are not logged in see at the site root. A reseller's own
host has no landing page: it opens on the login (`reseller_brand_for_host()`).

| File | What |
|---|---|
| `public/landing.php` | The page: banner, hero, sections, and the small inline scripts that drive the sliders |
| `app/views/public_header.php` / `public_footer.php` | Shared public chrome: nav, footer, scroll progress, `.lp-reveal` fade-in. The header takes an optional `$extraStyles` list of extra stylesheets for one page. |
| `public/assets/css/style.css` | Base styles of the public pages, the banner, the bento grid and the other sections |
| `public/assets/css/landing-ai.css` | Everything for «منشی هوشمند» on this page: banner slide 6, the feature tile and the seven-step showcase. Loaded only by `landing.php`. |
| `public/assets/img/landing/` | The two stills used by the banner and hero (WebP + JPEG fallback) |
| `public/assets/js/landing-customers.js` | Drives the `#customers` logo rails (scroll speed-up, hover glide, drag, centre focus) |
| `public/assets/img/customers/` | Customer logos for `#customers`, one file per customer named `<slug>.svg/.webp/.png/.jpg` |

There is no database table behind the banner or the showcase: they are fixed HTML. The page reads
only `ellsms_pricing_packages` (pricing cards) and the registration setting (how an account is
obtained). No migration is needed. Admin-managed slides from «اسلایدر صفحه‌ی اصلی» (`/slides.php`)
are not shown on this page.

## Sections, top to bottom

| Anchor | Section |
|---|---|
| — | **Top banner**: six animated slides (bulk SMS, smart template, live report, voice, Bale, «منشی هوشمند») |
| — | Hero with the 3D phone scene |
| `#flow` | «از فایل مخاطبین تا گوشی مشتری»: a contacts file + template becoming personal SMS |
| `#features` | Bento grid of services. The last, full-width tile is «منشی هوشمند با هوش مصنوعی» and links to `#ai-secretary`. |
| `#ai-secretary` | **«منشی هوشمند» showcase**: the seven-step slider described below |
| `#compare` | Manual sending vs. the panel |
| `#pricing` | Pricing cards (only when packages exist) |
| `#how` | Three steps to the first send |
| `#use-cases` | Use cases |
| `#customers` | «مشتریان و همکاران»: two scrolling rails of customer logos (see below) |
| `#trust`, `#faq` | Trust points, FAQ (includes «منشی هوشمند چیست و چه هزینه‌ای دارد؟») |

The nav has a «منشی هوشمند» link to `/landing.php#ai-secretary`.

## «مشتریان و همکاران» (`#customers`)

One tile per customer from the `$customers` list at the top of `landing.php`: the banks اقتصاد نوین,
سرمایه, کارآفرین, سینا, ملی ایران, پارسیان, گردشگری, and «وزارت آموزش و پرورش» (`education`) 
«وزارت ارتباطات و فناوری اطلاعات» (`ict`), and the companies تپسی (`tapsi`), اسنپ (`snapp`),
دیجی‌کالا (`digikala`), دیوار (`divar`) and جابینجا (`jobinja`). Each entry has a `slug`, the Persian `name`, a one-letter
`mono` and a brand `color`.

The logo is the first of `public/assets/img/customers/<slug>.svg`, `.webp`, `.png`, `.jpg` that exists,
shown on a white plate so the customer's own colours read correctly on the dark page. With no file the
tile shows a monogram badge in `color` instead, so a missing logo never breaks the layout. To add or
replace a logo, drop the file in that folder (a square, trimmed image, e.g. 240×240 WebP); to add a
customer, add a row to `$customers`.

The tiles run as two edge-to-edge **rails** (`.lp-marquee`) moving in opposite directions — the top
one to the right, the bottom one (in reverse order) to the left — with the ends faded out by a mask.
Each `.lp-marquee-track` holds the list twice, so the loop is seamless. Only the first copy of the top
rail is exposed to screen readers (the rest is `aria-hidden`).

Motion is driven by `public/assets/js/landing-customers.js` (loaded right after the section, adds
`.is-live`):

| Behaviour | Detail |
|---|---|
| Base speed | 38 px/s right (top), 32 px/s left (bottom) |
| Scroll | scrolling down pushes each rail faster its own way, scrolling up runs it backwards; the boost fades out in about a second |
| Hover / focus | the rail under the pointer glides to a stop and starts again smoothly; the other rail keeps going |
| Drag | mouse or touch drags a rail sideways and the throw keeps its momentum (`touch-action: pan-y`, so a vertical swipe still scrolls the page) |
| Centre focus | tiles near the middle are full size; towards the ends they shrink (×0.86) and fade (`--s`, `--o`) |
| Tile effects (CSS) | a gleam sweeps across each tile every 7 s (staggered), a spotlight follows the pointer, the hovered tile lifts and glows and its logo tilts |

The script runs only while the section is on screen and the tab is visible. Without it the rails run on
a plain CSS animation (70 s / 80 s per loop) that pauses on hover. With `prefers-reduced-motion` the
script does nothing and the rails are replaced by one still, wrapped list of every customer. Styles:
`.lp-marquee-*` and `.lp-bank-*` in `style.css`.

## Top banner

Slides are `<article class="lp-banner-slide">` inside `[data-lp-banner]`, with one dot per slide in
`.lp-banner-dots`. Slide 6 (`#lpSlide6`, `.lp-banner-ai`) shows a chat in which a customer asks about
a product and the secretary answers from a «محصولات» table; its button scrolls to `#ai-secretary`.

Behaviour: auto-advance every 7 s; pauses on hover, keyboard focus, off-screen and hidden tab;
«توقف اسلایدها» stops it for good (WCAG 2.2.2). The dots are an ARIA tablist (arrows, Home, End),
swipe works on touch screens, and with `prefers-reduced-motion` the banner stays on slide 1 with no
motion.

To add a slide: add the `<article>` (ids `lpSlideN`, `aria-label="N از M"` on every slide), add its
dot button, and update the `M` in every label.

## «منشی هوشمند» showcase (`#ai-secretary`)

A seven-step slider that explains the AI SMS secretary ([ai-secretary.md](ai-secretary.md)) to
visitors. Each step has a text side (title, paragraph, four points) and an animated scene drawn in
HTML/CSS/SVG (no images, no library):

| Step | Title | Scene | Feature doc |
|---|---|---|---|
| 1 | پاسخ خودکار، شبانه‌روزی | Phone conversation: question, typing dots, answer, booking confirmed; «۲۴/۷» and SMS/Telegram badges | [ai-secretary.md](ai-secretary.md) |
| 2 | راه‌اندازی در چند دقیقه | Settings card: template picked, mode «منشی کسب‌وکار», business info typed line by line, switch turns on | [ai-secretary.md](ai-secretary.md#assistant-settings-per-line) |
| 3 | مسیر هر پیام | Vertical pipeline lit in order: inbound → «۱۱/۱۲» → keyword rules → AI → answer from the same line | [ai-secretary.md](ai-secretary.md#how-a-message-flows) |
| 4 | جواب از جدول‌های شما | «سفارش‌ها» table scanned, the sender's row highlighted, other rows blurred («فقط شماره‌ی خود مشتری»), answer with tracking code | [ai-data.md](ai-data.md) |
| 5 | ارجاع به اپراتور | Customer asks for a person → hand-off notice with a ringing bell → operator reply → «ادامه با منشی هوشمند» | [ai-data.md](ai-data.md) |
| 6 | پیام اول با هوش مصنوعی | Table rows + instruction → personalized messages tagged پیامک / بله | [ai-outreach.md](ai-outreach.md) |
| 7 | امن، با هزینه‌ی شفاف | Shield with the guards ticked, and a cost card: SMS of the answer + AI by tariff, charged after a successful send | [ai-secretary.md](ai-secretary.md#guards), [ai-billing.md](ai-billing.md) |

Under the slider a note says the service is enabled per account by support, with «درخواست فعال‌سازی»
(`/contact.php`) and «راهنمای منشی هوشمند» (`/guide.php#guide-ai-secretary`, which opens that guide
section).

Keep the text in step with the feature: when the secretary's behaviour, guards or billing change,
update the matching step here (and `public/guide.php`). The sample prices and names in the scenes
are illustrations, not tariffs.

### How it works

- Markup: `[data-lp-ai]` holds `.lp-ai-tabs` (an ARIA tablist, one `.lp-ai-tab` per step, generated
  from `$aiSteps` in `landing.php`) and `.lp-ai-panels` (one `.lp-ai-slide` tabpanel per step; the
  inactive ones carry `hidden`).
- Scene animation: an element with `.lp-ai-pop` is hidden and rises in when its slide gets
  `.is-active`, after its own `--t` delay. Re-activating a slide replays its scene.
- Timer: the active tab's progress bar (`.lp-ai-tab-bar i`, 9 s) is the clock; its `animationend`
  moves to the next step. The bar is paused (`.is-holding`) while the pointer is over the slider,
  while something inside has focus, while less than about a third of it is on screen, while the
  browser tab is hidden, or after «توقف». When the slider comes back into view the current scene
  replays from its start.
- Navigation: click a tab, «مرحله‌ی قبل/بعد», arrow keys on the tabs (up/down when the tabs are a
  column, left/right when they are a row, RTL-aware), Home/End, or swipe.
- Layout: tabs in a column beside the panel on wide screens; under 1080 px they become a sideways
  scrolling strip above it (the active tab is scrolled into the middle), under 860 px the scene sits
  above the text.
- `prefers-reduced-motion`: no auto-advance, no animation, every scene is shown complete.

To add a step: add a row to `$aiSteps`, add the matching `<article class="lp-ai-slide" id="lpAiStepN">`
(with `hidden`), update «مرحله‌ی N از M» on every step and the «/ M» counter, and add the scene's
styles to `landing-ai.css`.
