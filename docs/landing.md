# Public landing page (`/landing.php`)

The marketing home page that visitors who are not logged in see at the site root. A reseller's own
host has no landing page: it opens on the login (`reseller_brand_for_host()`).

| File | What |
|---|---|
| `public/landing.php` | The page: banner, hero, sections, and the small inline scripts that drive the sliders |
| `app/views/public_header.php` / `public_footer.php` | Shared public chrome: nav (with the «درباره ما» submenu, see [about.md](about.md)), footer, scroll progress, `.lp-reveal` fade-in. The header takes an optional `$extraStyles` list of extra stylesheets for one page and an optional `$lpNavCurrent` (`'about'` / `'contact'`) that marks that nav link `aria-current="page"`. The footer's trust badges (ساماندهی، اینماد and the ecunion.ir «عضو اتحادیه کشوری کسب و کارهای مجازی» badge, which opens ecunion.ir's verification popup) come from `legal_trust_badges_html()` in `app/Legal.php` and are shared with the panel footer. The اینماد badge is the site's real eNamad seal (id `8308816`, constants `LEGAL_ENAMAD_ID` / `LEGAL_ENAMAD_CODE`): an image from `trustseal.enamad.ir` linked to its verification page, which is why the Content-Security-Policy `img-src` allows `https://trustseal.enamad.ir`. |
| `public/assets/css/style.css` | Base styles of the public pages, the banner and the other sections |
| `public/assets/css/landing-ai.css` | The «منشی هوشمند» chat of banner slide 7 (`.lp-banner-ai`, `.lp-ai-*`). Loaded only by `landing.php`. |
| `public/assets/css/landing-products.css` | Banner slide 1 «یک مجموعه، پنج خدمت» (`.lp-suite-*`) and the «محصولات و خدمات» section (`#products`): rows, quick-nav pills and the `.ps-*` scene animations. Loaded only by `landing.php`. |
| `app/views/landing_product_scenes/*.php` | One animated SVG scene per product line (`sms`, `cloud`, `ai`, `it`, `voip`), included by the `#products` section |
| `public/assets/img/landing/` | The two stills used by the banner and hero (WebP + JPEG fallback) |
| `public/assets/js/landing-customers.js` | Drives the `#customers` logo rail (scroll speed-up, hover glide, drag, centre focus) |
| `public/assets/img/customers/` | Customer logos for `#customers`, one file per customer named `<slug>.svg/.webp/.png/.jpg` |

There is no database table behind the banner or the sections: they are fixed HTML. The page reads
only the registration setting (how the FAQ says an account is obtained). No migration is needed.
The SMS packages managed at `/pricing.php` (`ellsms_pricing_packages`) are not shown on this page
since the pricing section was removed. Admin-managed slides from «اسلایدر صفحه‌ی اصلی» (`/slides.php`)
are not shown on this page.

## Colours (public site and sign-in pages)

The public pages (landing, «درباره ما», «تماس با ما», «راهنما», the legal pages) and the sign-in pages
(login, «ثبت‌نام», «تأیید دومرحله‌ای», «خروج») use a **white / charcoal / turquoise** palette in a
60 / 30 / 10 split. The logged-in panel keeps its own indigo colours: every value below is scoped to
`.lp-body`, `.auth` or `.login-body`, never to `:root`.

| Token | Value | Use |
|---|---|---|
| `--canvas` / `--card` | `#f7f8f8` / `#ffffff` | Page background and cards (the 60%) |
| `--charcoal` / `--charcoal-deep` | `#25292d` / `#1b1e21` | Text, and the dark bands: nav, top banner, «آماده‌اید شروع کنید؟» CTA, footer, the product scene panels, the about hero, section bar, statement and mission/vision cards (the 30%) |
| `--t-500` | `#12a4a6` | Brand turquoise: accents, large shapes, buttons on charcoal (with charcoal text, 4.8:1) |
| `--t-ink` | `#0b7a7c` | Turquoise that carries white text (buttons, links on white; 5.1:1). Buttons are flat, not gradients. |
| `--t-300` / `--t-50` | `#5fd4d6` / `#e8f6f6` | Turquoise on charcoal / pale turquoise tint |

- **Where:** the `.lp-body` token block in `public/assets/css/style.css`; the charcoal bands
  re-declare `--ink`, `--muted`, `--line`, `--card` light-on-dark in the rule just below it (add
  `.lp-dark` to any element to make it a charcoal band). The sign-in page's own tokens are the
  `.auth` block of `public/assets/css/login.css` (slider: `.ls` in `login-slider.css`); the pages
  around sign-in get theirs from `.login-body` in `style.css`.
- **Product lines** in `#products` are all turquoise except «تلفن و ارتباطات سازمانی», which keeps its
  amber (`--pa` readable tone, `--pb` bright tone in `landing-products.css`).
- **Images:** `public/assets/img/landing/hero-scene.*` and `banner-phone.*` were recoloured from blue
  to turquoise/charcoal (hue shift), so they match the palette.
- **Reseller hosts:** `brand_color_style()` (`app/ResellerPanel.php`) also sets `--login-brand`, so a
  reseller's own host shows its colour on the sign-in page instead of the turquoise.
- Animations are unchanged; only their colours follow the palette. No migration or setting.

## Sections, top to bottom

| Anchor | Section |
|---|---|
| — | **Top banner**: seven animated slides («یک مجموعه، پنج خدمت» — all five product lines, bulk SMS, smart template, live report, voice, Bale, «منشی هوشمند») |
| — | Hero with the 3D phone scene |
| `#products` | **«محصولات و خدمات»**: the five product lines of the company, described below |
| `#customers` | «مشتریان و همکاران»: one scrolling rail of monochrome customer logos (see below) |
| `#trust`, `#faq` | Trust points, FAQ (includes «منشی هوشمند چیست و چه هزینه‌ای دارد؟») |

The nav button reads «ورود به پنل پیامک» (the site sells more than the SMS panel; «بازگشت به داشبورد»
when logged in; the same label is used by the hero and the SMS product row). The nav links are «محصولات»
(`/landing.php#products`), «سوالات» (`/landing.php#faq`), «راهنمای استفاده», «درباره ما» (`/about`,
with a submenu of that page's sections, see [about.md](about.md)) and «تماس با ما».

The sections «از فایل مخاطبین تا گوشی مشتری» (`#flow`), «هر روش ارسالی که نیاز دارید» (`#features`),
the «منشی هوشمند» seven-step showcase (`#ai-secretary`), «چرا به‌جای ارسال دستی؟» (`#compare`),
«بسته‌های پیامک» (`#pricing`), «سه قدم تا اولین ارسال» (`#how`) and «برای چه کسب‌وکارهایی مناسب
است؟» (`#use-cases`) were removed; «مشتریان و همکاران» now follows «محصولات و خدمات». Links that used
to point at them now go to `#products` («مشاهده‌ی محصولات» in the hero and banner) or to
`/guide.php#guide-ai-secretary` (banner slide 7 and the FAQ answer about «منشی هوشمند»).

## «مشتریان و همکاران» (`#customers`)

One tile per customer from the `$customers` list at the top of `landing.php`: the banks اقتصاد نوین,
سرمایه, کارآفرین, سینا, ملی ایران, پارسیان, گردشگری, and «وزارت آموزش و پرورش» (`education`) 
«وزارت ارتباطات و فناوری اطلاعات» (`ict`), and the companies تپسی (`tapsi`), اسنپ (`snapp`),
دیجی‌کالا (`digikala`), دیوار (`divar`) and جابینجا (`jobinja`). Each entry has a `slug`, the Persian `name`, a one-letter
`mono` and a brand `color`.

The logo is the first of `public/assets/img/customers/<slug>.svg`, `.webp`, `.png`, `.jpg` that exists,
shown **monochrome** by default: the inline SVG filter `#lp-logo-mono` (in the section markup) paints
every pixel charcoal and turns its darkness into opacity, so the file's white square disappears and the
logo stays as a grey mark on the white page (opacity .7; .85 on touch screens, which have no hover).
On hover or keyboard focus the tile lifts and the logo shows in its own colours on a white plate, with
the name brightening. With no file the tile shows a monogram badge instead (grey by default, filled with
the customer's `color` on hover), so a missing logo never breaks the layout. The logo files themselves
stay full-colour on white; no separate monochrome copy is needed. To add or
replace a logo, drop the file in that folder (a square, trimmed image, e.g. 240×240 WebP); to add a
customer, add a row to `$customers`.

The tiles run as one edge-to-edge **rail** (`.lp-marquee`) moving to the right, with the ends faded out
by a mask. The `.lp-marquee-track` holds the list twice, so the loop is seamless. Only the first copy is
exposed to screen readers (the second is `aria-hidden`).

Motion is driven by `public/assets/js/landing-customers.js` (loaded right after the section, adds
`.is-live`):

| Behaviour | Detail |
|---|---|
| Base speed | 38 px/s to the right |
| Scroll | scrolling down pushes the rail faster, scrolling up runs it backwards; the boost fades out in about a second |
| Hover / focus | the rail glides to a stop under the pointer and starts again smoothly |
| Drag | mouse or touch drags the rail sideways and the throw keeps its momentum (`touch-action: pan-y`, so a vertical swipe still scrolls the page) |
| Centre focus | tiles near the middle are full size; towards the ends they shrink (×0.86) and fade (`--s`, `--o`) |
| Tile effects (CSS) | tiles have no plate or border at rest; the hovered/focused tile gets a card background, lifts 4 px, and its logo turns from monochrome to full colour on a white plate |

The script runs only while the section is on screen and the tab is visible. Without it the rail runs on
a plain CSS animation (70 s per loop) that pauses on hover. With `prefers-reduced-motion` the script
does nothing and the rail is replaced by one still, wrapped list of every customer. Styles:
`.lp-marquee-*` and `.lp-bank-*` in `style.css`.

## Top banner

Slides are `<article class="lp-banner-slide">` inside `[data-lp-banner]`, with one dot per slide in
`.lp-banner-dots`. Slide 7 (`#lpSlide7`, `.lp-banner-ai`) shows a chat in which a customer asks about
a product and the secretary answers from a «محصولات» table; its button opens the customer guide's
«منشی هوشمند» section (`/guide.php#guide-ai-secretary`).

### Slide 1 — «یک مجموعه، پنج خدمت» (`#lpSlide1`, `[data-lp-suite]`, `.lp-suite-*`)

The first thing a visitor sees: the five product lines (`#products`) on a tilted 3D orbit around the
DATIS mark. The orbit turns each product to the front in turn and explains it.

**Content.** The slide's `$suite` array (one entry per line: tone, short name, title, one-paragraph
description, three main services, icon) feeds everything: the planets, the glass cards, the copy
panel and the chips.

**Visual (left).** A holographic floor (concentric ellipses and spokes that fade out, with ripples
running across it), a soft nebula, three ellipses (the middle one is the orbit, with a gradient and a
glow; its front half is drawn again above the core so the orbit really passes in front of it), the
core (an energy sphere with the DATIS mark, a turning light sweep, a colour aura in the five product
colours and two tilted gyroscope rings), five glossy gem-like planets with a glow pool under each,
name labels, a beam from the core to each planet, and one holographic card per product. Planets
behind the core are smaller, darker, less saturated and drawn under it; the front one is the
biggest. The script places the planets every frame from one angle, in container units of the stage
(`100cqw` square; core at 50/38, orbit rx 36, ry 17 — the same numbers are in the SVG, the CSS and
the script).

**Copy (right).** The fixed headline «یک مجموعه، پنج خدمت؛ همه‌ی فناوری کسب‌وکار شما», a panel with
the focused product («۰۲ / ۰۵», title, description; it switches with a blur-and-rise) and a progress
bar to the next product, five chip buttons (one per product) and the buttons «مشاهده‌ی محصولات»
(`#products`) and «درخواست مشاوره» (`/contact.php`).

**Timeline.**

| Time | What happens |
|---|---|
| 0–0.7 s | Warp: 18 light streaks rush into the centre |
| 0.62 s | White flash from the centre |
| 0.7 s | The core ignites (scales up with an overshoot); then it breathes |
| 0.8–1.4 s | The floor grid, aura, gyroscope rings and ellipses fade in; the orbit draws itself; two shockwaves spread flat along the plane |
| 0.85 s + k·0.12 s | Product k swirls out of the core onto the orbit (the whole ring spins 160° into place) |
| 2.1 s | Opening done: product 1 (پیامک) is in front |
| on every arrival | Burst at the front product: a flat ring along the floor and ten particles; a spinning Saturn ring appears around it, its halo pulses, its beam turns hot with two sparks running to it; its card materialises like a hologram (wipe from the top with a flicker, a scan line, icon pops, the three services slide in one by one) |
| every 2.6 s | The orbit turns (1.1 s, eased) to bring the next product to the front, coming from the right; the copy panel, progress bar and chip switch with it |
| always | Floor ripples, comet on the orbit, dotted ellipses running, aura and gyroscope rings turning, planets bobbing, dust twinkling, nebula drifting, the whole scene swaying slowly |
| 15.3 s | The banner moves to slide 2 (`data-duration="15300"`: the opening plus all five products) |

**Interaction.** Clicking a chip turns the orbit the short way to that product and holds it for 6 s
before stepping on (focus inside the banner also stops the banner moving to the next slide). On a
desktop pointer the stage tilts towards the mouse (parallax), the dust layer and card move against it.

**Pauses and accessibility.** The script runs only while the slide is active; its clock stops while
the banner is off screen, the tab is hidden, or the visitor pressed «توقف اسلایدها». With reduced
motion there is no opening, no auto-step and no tween: the complete still frame (product 1 in front,
its card open) is shown, and the chips switch products instantly. The visual is `aria-hidden`; the
same information is in the copy panel and the chips, which are real buttons with `aria-pressed`.

Behaviour: auto-advance every 7 s (a slide can stay longer with `data-duration` in ms; slide 1 uses 15300); pauses on hover, keyboard focus, off-screen and hidden tab;
«توقف اسلایدها» stops it for good (WCAG 2.2.2). The dots are an ARIA tablist (arrows, Home, End),
swipe works on touch screens, and with `prefers-reduced-motion` the banner stays on slide 1 with no
motion.

To add a slide: add the `<article>` (ids `lpSlideN`, `aria-label="N از M"` on every slide), add its
dot button, and update the `M` in every label.

## «محصولات و خدمات» (`#products`)

Right after the hero, the page lists every product line of Datis Rayan, not only the SMS panel. A
row of quick-nav pills links to each line; each line is an `<article id="product-…">` with a number,
a tag, a title, a full paragraph, four sub-services (icon, title, one sentence) and two buttons, next
to an animated SVG scene. Rows alternate sides (`.is-flip`) and stack on screens under 960px.

| Anchor | Product line | Sub-services | Scene | Buttons |
|---|---|---|---|---|
| `#product-sms` | سامانه پیامکی و بازاریابی | ارسال انبوه و هدفمند، پیامک هوشمند با قالب پویا، زمان‌بندی و ارسال دوره‌ای، منشی هوشمند و وب‌سرویس | Phone sending to recipients, delivery ticks, rising chart | Panel login |
| `#product-cloud` | خدمات ابری و زیرساختی | سرور مجازی (VPS)، هاست و دامنه، فضای ذخیره‌سازی ابری، پایش و پشتیبان‌گیری | Cloud over blinking racks, two-way data, spinning globe, filling storage | `/contact.php` |
| `#product-ai` | هوش مصنوعی و اتوماسیون | تولید محتوا با هوش مصنوعی، اتوماسیون فرآیندهای کسب‌وکار، دستیار و چت‌بات هوشمند، گزارش و تحلیل خودکار | AI core with a neural net, self-writing document, workflow with a gear | `/contact.php` |
| `#product-it` | خدمات فناوری اطلاعات | پشتیبانی شبکه، نصب و راه‌اندازی سرور، نگهداری و پشتیبانی نرم‌افزار، مشاوره‌ی امنیت زیرساخت | Network topology with traffic, scanned server, shield drawing its check, gears | `/contact.php` |
| `#product-voip` | تلفن و ارتباطات سازمانی | سانترال تحت شبکه (VoIP)، منشی تلفنی و صف تماس، اتصال شعب و دورکاری، ضبط و گزارش مکالمات | Ringing VoIP core linking two offices and a mobile, voice equaliser, IVR menu | `/contact.php` |

Only the SMS panel is sold inside the panel; the other four lines are services ordered through
«تماس با ما», so the section names no prices. The content is the `$productLines` array at the top of
the section in `landing.php`: edit a line's text or sub-services there. To add a line, add an entry,
a scene file `app/views/landing_product_scenes/<scene>.php`, a tone block (`.is-<tone>`) in
`landing-products.css`, and a nav-pill icon in `$prodNavIcon`. Scenes are included through a closure,
so their loop variables cannot leak into the page.

Motion: the scenes are pure CSS animations on SVG (`.ps-flow`, `.ps-blink`, `.ps-pop`, …). A small
script toggles `.is-live` with an IntersectionObserver, and off-screen scenes have no animation at
all (they show their fully drawn still frame). With `prefers-reduced-motion` every animation in the
section is off. The section itself is not `.lp-reveal` (it is too tall to reach the reveal threshold
on a phone); its head, pills and each row reveal separately.
