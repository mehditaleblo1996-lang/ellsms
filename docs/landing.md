# Public home page (`/landing.php`)

The marketing home page that visitors who are not logged in see at the site root. A reseller's own
host has no landing page: it opens on the login (`reseller_brand_for_host()`).

It is built as a short guided story rather than a catalogue: a brand statement (the page's h1), the cinematic hero core with a click-to-explain card per service line, three showcases (SMS, cloud, AI), the IT and
telephony panels, customers, FAQ and a closing call to action.

| File | What |
|---|---|
| `public/landing.php` | The page: every section below, the `$customers` list, and two small inline scripts (on-screen animation trigger; service selection) |
| `app/views/public_header.php` / `public_footer.php` | Shared public chrome: the floating nav (with the «درباره ما» submenu, see [about.md](about.md)), footer, scroll progress, `.lp-reveal` fade-in, and `--lp-nav-h` (the nav's measured height, used for anchor offsets). The header preloads the two main Estedad weights, takes an optional `$extraStyles` list of extra stylesheets for one page and an optional `$lpNavCurrent` (`'about'` / `'contact'`) that marks that nav link `aria-current="page"`. The footer's trust badges (ساماندهی، اینماد، the ecunion.ir «عضو اتحادیه کشوری کسب و کارهای مجازی» badge, which opens ecunion.ir's verification popup, and the «سازمان نظام صنفی رایانه‌ای کشور — استان تهران» badge, which for now links to the site's home page (`/`) — the company's member page on tehran.irannsr.org is kept commented out in `legal_nsr_badge_html()` to restore later; its green emblem and calligraphic caption are `public/assets/img/nsr-tehran-emblem.png` / `nsr-tehran-text.png`, the caption drawn as an SVG mask in the footer's text colour) come from `legal_trust_badges_html()` in `app/Legal.php` and are shared with the panel footer. The اینماد badge is the site's real eNamad seal (id `8308816`, constants `LEGAL_ENAMAD_ID` / `LEGAL_ENAMAD_CODE`): an image from `trustseal.enamad.ir` linked to its verification page, which is why the Content-Security-Policy `img-src` allows `https://trustseal.enamad.ir`. |
| `public/assets/css/style.css` | Design tokens of the public pages (`.lp-body`), the floating nav, footer, FAQ list, customer rail, `.lp-reveal` |
| `public/assets/css/home.css` | Everything specific to this page (prefix `.dx-`): intro, hero constellation and its explanation cards, showcases, IT/telephony panels, FAQ layout, CTA. Loaded only by `landing.php`. |
| `public/assets/css/landing-ai.css` | The «منشی هوشمند» chat in the AI showcase (`.lp-banner-ai`, `.lp-ai-*`). Loaded only by `landing.php`. |
| `public/assets/css/landing-products.css` | The `.ps-*` primitives of the product scenes, and the IT / telephony carousels (`.pc-*`: carousel chrome and each slide's story). Loaded only by `landing.php`. |
| `app/views/landing_product_scenes/cloud.php`, `it-{1,2,3}.php`, `voip-{1,2,3}.php` | Animated SVG scenes: the cloud showcase, and the three slides of each of the IT / telephony carousels |
| `public/assets/fonts/estedad/` | Estedad (SIL OFL 1.1, `OFL.txt`), Arabic + Latin subsets, weights 400/500/700/800; `@font-face` rules in `fonts.css` |
| `public/assets/js/landing-customers.js` | Drives the `#customers` logo rail (scroll speed-up, hover glide, drag, centre focus) |
| `public/assets/img/customers/` | Customer logos for `#customers`, one file per customer named `<slug>.svg/.webp/.png/.jpg` |

There is no database table behind the page: it is fixed HTML. It reads only the registration setting
(how the FAQ says an account is obtained). No migration or setting is needed. The SMS packages managed
at `/pricing.php` (`ellsms_pricing_packages`) and the admin slides of «اسلایدر صفحه‌ی اصلی»
(`/slides.php`) are not shown on this page.

## Colours (public site and sign-in pages)

The public pages (home, «درباره ما», «تماس با ما», «راهنما», the legal pages) and the sign-in pages
(login, «ثبت‌نام», «تأیید دومرحله‌ای», «خروج») use a **white + graphite + turquoise** identity in a
60 / 30 / 10 split; the turquoise is the cyan-turquoise of the triangle in the DATIS logo. There is no
blue and no green in the UI; status colours (success / warning / error) keep their own meaning. The
logged-in panel keeps its own indigo colours: every value below is scoped to `.lp-body`, `.auth` or
`.login-body`, never to `:root`.

| Token | Value | Use |
|---|---|---|
| `--background` / `--surface` | `#F8FAFA` / `#FFFFFF` | Page background and cards (the 60%) |
| `--foreground` / `--muted-foreground` | `#25292B` / `#697477` | Headings and text / secondary text (4.6:1 on the background) |
| `--dark-surface` | `#25292B` | The graphite bands: the CTA, the footer, the scene panels, the about hero, section bar, statement and mission/vision cards (the 30% with the type) |
| `--primary` | `#11B8C5` | Primary buttons (with graphite text, 6.1:1), selected states, icons, highlights |
| `--primary-hover` | `#087F8C` | Hover of primary buttons (white text, 4.75:1), turquoise text on white |
| `--primary-ink` | `#076F7A` | Small turquoise text on the pale surface |
| `--primary-light` / `--primary-border` | `#E7F8FA` / `#B8E8EC` | Pale turquoise surfaces and their borders |
| `--border` | `#E4EAEB` | Hairlines and card borders |

- **Where:** the `.lp-body` token block in `public/assets/css/style.css` (it also maps the older
  names `--ink`, `--muted`, `--line`, `--card`, `--t-*`, `--charcoal` onto these, and holds the
  fluid type scale, radii, shadows and the `--ease` timing). The graphite bands re-declare `--ink`,
  `--muted`, `--line`, `--card` light-on-dark in the rule just below it (the `.lp-dark` utility class
  was removed with the last graphite band on the home page). The sign-in page's tokens are the `.auth` block of
  `public/assets/css/login.css` (slider: `.ls` in `login-slider.css`); the pages around sign-in get
  theirs from `.login-body` in `style.css`.
- **Type:** Estedad (self-hosted, OFL) on the public and sign-in pages, with Vazirmatn as fallback;
  headings use `clamp()` sizes. The panel still uses Vazirmatn ([font-integration.md](font-integration.md)).
- **Logo:** the official DATIS logo in its own colours (`assets/img/brand/logo.svg` in the nav and on
  a white plate in the footer) — it is never recoloured.
- **Scenes:** the SVG scenes and the AI chat were recoloured to the turquoise hue; their motion is
  unchanged. «تلفن و ارتباطات سازمانی» keeps its amber.
- **Reseller hosts:** `brand_color_style()` (`app/ResellerPanel.php`) also sets `--login-brand`,
  `--login-on-brand` and `--login-brand-hover`, so a reseller's own host shows its colour on the
  sign-in page instead of the turquoise.

## Sections, top to bottom

| Anchor | Section |
|---|---|
| — | **Intro** (first): the page's `<h1>` «یک شریک فناوری برای همه‌ی نیازهای کسب‌وکار شما», a short paragraph and «آشنایی با داتیس رایان» (→ `/about`) |
| — | **Hero**: an animated constellation of the five services and a detail card. Choosing a service highlights its route and opens its card. |
| `#product-sms` | Showcase: the SMS platform — four features, its channels (پیامک، پیام صوتی، پیام در بله), and a mock-up of «قالب ← پیام شخصی هر ردیف ← گزارش تحویل» |
| `#product-cloud` | Showcase: cloud and infrastructure, with the animated rack scene |
| `#product-ai` | Showcase on a light full-width band (page background with a faint turquoise tint, hairline borders): AI and automation, with the «منشی هوشمند» chat as a light chat window (white card, grey customer bubble, pale-turquoise reply bubble, white chips; colours from the `.lp-body` tokens in `landing-ai.css`) (its button opens `/guide.php#guide-ai-secretary`) |
| `#product-it`, `#product-voip` | Two companion panels: IT services and enterprise telephony, each with a three-slide animated carousel (below) and four sub-services |
| `#customers` | «مشتریان و همکاران»: one scrolling rail of monochrome customer logos (see below) |
| `#faq` | Questions (includes «منشی هوشمند چیست و چه هزینه‌ای دارد؟») |
| — | Call to action: «مشاوره با کارشناسان» and the panel login |

The nav is a light bar floating over the page (frosted white, hairline border, a soft shadow once the
page scrolls): the DATIS logo on the right, «محصولات» (`/landing.php#product-sms`, the first showcase), «سوالات»
(`/landing.php#faq`), «راهنمای استفاده», «درباره ما» (`/about`, with a submenu of that page's sections,
see [about.md](about.md)) and «تماس با ما» in the middle, and «ورود به پنل پیامک» («بازگشت به داشبورد»
when logged in) on the left. Under 980px the links fold into a card opened by the menu button.

The content of the old seven-slide banner lives on in the showcases: bulk SMS, the smart template and
the live report in `#product-sms` (with voice and Bale as channels), «منشی هوشمند» in `#product-ai`.
The products' texts are those of the former product rows, unchanged. No figures, clients or claims are
invented: the one number on the page (۱۲٬۴۸۰ in the report mock-up) is labelled «نمونه».

### Service constellation

The SVG map and the five HTML buttons share the same coordinate space (`$dxConstellation` in
`landing.php`). The buttons stay in fixed positions while light travels along the routes and the
hub pulses. SVG product icons keep their dimensions and strokes when selected.

A click or tap lights the selected route, shows its icon in the hub, and opens a card next to the map
(or below it on small screens). Cards link to the corresponding `#product-*` showcases. Horizontal
swipes cycle through the services. The same product button, the hub, the close button, and Escape
all clear the selection. Closing with the button or Escape returns focus to the product button.
Offscreen map animations pause, and the map is static with reduced motion enabled.

### IT and telephony carousels

Each of the two panels opens with a three-slide carousel (`$renderProdCarousel()` in `landing.php`; slides
`app/views/landing_product_scenes/it-1..3.php` and `voip-1..3.php`, 440×250 SVGs on the graphite scene
surface; IT in turquoise, telephony in its amber). Every slide is a short story on a 7 s clock (one-shot
keyframes with fill `both`, plus a few loops); only the active slide animates, so its story replays from
the start each time it comes on.

| Slide | Story |
|---|---|
| IT 1 «پشتیبانی و پایش شبکه» | Core switch with live traffic (packets both ways) to the office, laptops, Wi-Fi and a branch; at 2 s the branch link fails (amber dashes, «!»), a wrench works on it, at 4.6 s it is back with a check; «پایش شبانه‌روزی» chip |
| IT 2 «سرور و مجازی‌سازی» | Rack with blinking LEDs, activity bars and a scan line; three VMs (Windows, Linux, Mail) rise onto the virtualisation layer one by one, fill their boot bars and get a check; CPU and RAM gauges fill |
| IT 3 «امنیت و نگهداری» | Threats fly in and burst on a turning firewall ring; the shield draws its check; the «نگهداری و به‌روزرسانی» checklist (وصله‌های امنیتی، پشتیبان‌گیری، بررسی آسیب‌پذیری) ticks off while the update bar fills |
| Telephony 1 «سانترال تحت شبکه» | An incoming call rings («تماس ورودی…»), the IP PBX routes it (scans past ۱۰۱, lights ۱۰۲), ۱۰۲ rings, then «در حال مکالمه» with a live voice wave |
| Telephony 2 «منشی گویا و صف تماس» | «سلام، به داتیس خوش آمدید» types out; the IVR menu highlights ۱، ۳ and settles on «۲ پشتیبانی», «انتقال به پشتیبانی ←»; callers move up the queue to the agents |
| Telephony 3 «شعب، دورکاری و ضبط مکالمات» | Head office, branch and a remote colleague connected through the VoIP hub with calls flowing on the arcs; a blinking REC badge over a waveform; the «گزارش تماس‌ها» bars grow |

**Controls.** A caption (the slide title, e.g. «پشتیبانی و پایش شبکه»; no number) and three dots; the active dot is a pill whose fill is
the 7 s clock, and its `animationend` advances to the next slide, so pausing that one animation pauses the
carousel: on hover/focus (`.is-hold`) and while the panel is off screen (`.is-live` off). Dots jump to a
slide; a horizontal swipe steps (right = next, RTL). Accessibility: `role="group"` +
`aria-roledescription="carousel"/"slide"`, inactive slides `aria-hidden`, the caption is
`aria-live="polite"`, dots are buttons with `aria-label` and `aria-current`. Reduced motion: no clock (no
autoplay), each slide shows its finished state, dots still switch slides. No new table, setting or
migration.

### Animated background

Every public page (home, «درباره ما», «تماس با ما», «راهنما», the legal pages: all that use
`app/views/public_header.php`) has one fixed, decorative layer behind the content, `.lp-bg`, matched to
the light identity: two soft turquoise glows that float slowly, two layers of thin wave lines that drift
sideways at different speeds (120s / 180s, opposite directions) — the front layer stroked with a
repeating turquoise gradient (`#lpBgShine`, period 1600 like the waves) so each line brightens and fades
along its length, the back layer a faint graphite — and ten faint light streaks (2px, soft turquoise
tail and a pale head; every second one thinner). All of it is kept faint so it never competes with the content that glide right to left (six on phones). The wave paths are generated in the
header (`$lpBgWave`): each strip is 3200 units wide and repeats every 1600, so sliding it by −50% loops
with no seam; on narrow screens the strip is at least 2000px wide so the waves stay gentle. Only
transform/opacity animate (compositor only), the layer is `aria-hidden` with no pointer events, it stands
still without streaks for `prefers-reduced-motion`, and it is hidden for `prefers-contrast: more`. Styles:
the «Animated page background» block in `style.css`. Full-width bands let it show through: the cloud
showcase's band is 72% white and the AI band is a transparent turquoise tint.

### Motion

Reveals use `.lp-reveal` (`public_footer.php`). Showcase mock-ups
(`[data-dx-live]`) build once when they come on screen; the product scenes (`[data-lp-prod-scene]`)
animate only while on screen. Everything uses transform/opacity and stops for `prefers-reduced-motion`.

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

## Products (`#product-*`)

| Anchor | Product line | Sub-services | Visual | Buttons |
|---|---|---|---|---|
| `#product-sms` | سامانه پیامکی و بازاریابی | ارسال انبوه و هدفمند، پیامک هوشمند با قالب پویا، زمان‌بندی و ارسال دوره‌ای، منشی هوشمند و وب‌سرویس | Template → personal messages → delivery report mock-up | Panel login, «راهنمای استفاده» |
| `#product-cloud` | خدمات ابری و زیرساختی | سرور مجازی (VPS)، هاست و دامنه، فضای ذخیره‌سازی ابری، پایش و پشتیبان‌گیری | Cloud over blinking racks, globe, storage (`cloud.php`) | `/contact.php` |
| `#product-ai` | هوش مصنوعی و اتوماسیون | تولید محتوا با هوش مصنوعی، اتوماسیون فرآیندهای کسب‌وکار، دستیار و چت‌بات هوشمند، گزارش و تحلیل خودکار | «منشی هوشمند» chat | `/guide.php#guide-ai-secretary`, `/contact.php` |
| `#product-it` | خدمات فناوری اطلاعات | پشتیبانی شبکه، نصب و راه‌اندازی سرور، نگهداری و پشتیبانی نرم‌افزار، مشاوره‌ی امنیت زیرساخت | Network, shield, gears (`it.php`) | `/contact.php` |
| `#product-voip` | تلفن و ارتباطات سازمانی | سانترال تحت شبکه (VoIP)، منشی تلفنی و صف تماس، اتصال شعب و دورکاری، ضبط و گزارش مکالمات | VoIP core linking offices and a mobile (`voip.php`) | `/contact.php` |

Only the SMS panel is sold inside the panel; the other four lines are services ordered through
«تماس با ما», so the page names no prices. The texts are in the section markup of `landing.php`.
Scenes are included through a closure (`$renderProdScene`), so their loop variables cannot leak into
the page; they sit on graphite `.dx-scene` panels and show their fully drawn still frame when off
screen.
