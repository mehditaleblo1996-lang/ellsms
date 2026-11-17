# «درباره ما» — company page (`/about`)

The public page that introduces the company (داتیس رایان گستر ایرانیان): who it is, its five service
areas, the trust of large organizations, mission, vision and core values. It is part of the public
marketing site and shares its white / charcoal / turquoise theme and chrome with the landing page
([landing.md](landing.md#colours-public-site-and-sign-in-pages)). The hero, section bar, statement and
mission/vision cards are charcoal bands; the rest of the page is white.

| File | What |
|---|---|
| `public/about.php` | The page: hero, section bar, the sections below, and a small inline script |
| `public/assets/css/about.css` | Everything for this page (prefix `.ab-`). Loaded only by `about.php` through the public header's `$extraStyles`. |
| `app/views/public_header.php` | The site nav: the «درباره ما» item with its submenu (`$aboutMenu`) |
| `app/views/public_footer.php` | The submenu's script (hover, chevron button, Escape, click outside) |
| `public/assets/css/style.css` | The submenu's styles (`.lp-nav-drop`, `.lp-nav-menu`), shared by every public page |
| `app/Legal.php` | `legal_footer_links_html()`: the «درباره ما» link in both footers (public site and panel) |

The text is fixed HTML in `about.php`; there is no table, setting or admin page behind it and no
migration is needed. To change the wording, edit `about.php` (the service areas, sectors, pillars and
values are the arrays `$abServices`, `$abSectors`, `$abPillars` and `$abValues` at the top).

## URL and routing

- Clean URL `/about` → `about.php` (`docker/apache-clean-urls.conf`); a GET of `/about.php` is
  redirected (301) to `/about`, and `app/Support/AuditPrepend.php` maps `/about.php` → `/about` in
  `Location` headers. `tests/Unit/CleanUrlRoutingTest.php` checks all three.
- On a reseller's own branded host the page redirects to `/login`, exactly like the landing page:
  that host has no platform marketing site.

## Where it is linked

- **Site nav** (every public page): «درباره ما», between «راهنمای استفاده» and «تماس با ما». On a
  wide screen with a mouse its submenu opens on hover; anywhere, the chevron button next to it opens
  it (`aria-expanded`). The submenu lists the five parts of the page, each with an icon and a short
  line — «معرفی داتیس رایان» (`#intro`), «حوزه‌های تخصصی» (`#services`), «اعتماد سازمان‌ها»
  (`#trust`), «مأموریت و چشم‌انداز» (`#mission`), «ارزش‌های بنیادین» (`#values`) — and a
  «گفت‌وگو با ما» link to `/contact`. Escape closes it and returns focus to the button. In the phone
  menu (under 980 px) it opens in place under «درباره ما».
- **Footer** (public site and panel): «درباره ما» before «تماس با ما».
- The nav marks the current page with `aria-current="page"`: a page sets `$lpNavCurrent` before
  including the public header (`about.php` sets `'about'`, `contact.php` sets `'contact'`).

When a section of the page is added, removed or renamed, update both `$abSections` in `about.php`
(the section bar) and `$aboutMenu` in `public_header.php` (the nav submenu).

## Sections, top to bottom

| Anchor | Section |
|---|---|
| `#intro` | **Hero**: «درباره ما» badge, the company name, the two intro paragraphs, buttons to `#services` and `/contact`. Beside it an animated hub: the brand mark in the middle (`/assets/img/brand/mark-light.svg`) with the five service areas on an orbit, pulses running out along the spokes and two dots circling. |
| — | **Section bar**: sticky under the site nav, one chip per section; the section in view is highlighted (`aria-current="location"`). On a phone it scrolls sideways and keeps the current chip in view. |
| `#story` | «از پیامک، تا سبد کامل خدمات فناوری»: how SMS was the starting point, the audience chips (سازمان‌ها، نهادهای حاکمیتی، بانک‌ها، مجموعه‌های بزرگ اقتصادی، پلتفرم‌های دیجیتال), and a tree in which «پیامک و ارتباطات» branches into the four other areas (lines draw in, then a light runs along each branch). Below it the statement «برای سازمان‌های بزرگ، فناوری صرفاً یک ابزار نیست» with four tiles: تداوم کسب‌وکار، امنیت، بهره‌وری، تجربه مشتری. |
| `#services` | «حوزه‌های تخصصی خدمات»: a bento of five cards. The large one is SMS («نقطه‌ی آغاز ما») with a small animated message thread; the others are cloud, AI, IT and telephony. Each card has an animated icon (typing dots, sync arrows, a chip with a sparkle, blinking server LEDs, a ringing phone) and its tags. |
| `#trust` | «اعتماد سازمان‌های بزرگ، سرمایه ارزشمند ما»: the paragraph, the quote «ما خود را صرفاً تأمین‌کننده سرویس نمی‌دانیم…», four sector tiles (بانک‌ها، وزارتخانه‌ها، سازمان‌های بزرگ، سوپراپلیکیشن‌ها) that light up in turn around a trust badge, and five pillars: پایداری، امنیت، سرعت پاسخ‌گویی، مقیاس‌پذیری، کیفیت پشتیبانی. |
| `#mission` | Two cards, «مأموریت ما» and «چشم‌انداز ما», with a slowly turning gradient edge. |
| `#values` | «ارزش‌های بنیادین ما»: five cards — اعتماد و مسئولیت‌پذیری، امنیت و پایداری، نوآوری و توسعه، مشتری‌مداری، کیفیت و تخصص. |
| — | Closing call to action: «تماس با ما» and «آشنایی با پنل پیامک» (the landing page). |

The page shows no figures (years, customer counts, client logos): none were provided, and nothing is
invented. Add them to `about.php` only from real data.

## Motion and accessibility

- All motion is CSS/SVG; there are no images other than the brand mark and no library.
- Looping animations run only while their section is on screen: `[data-ab-live]` sections get
  `.is-live` from an `IntersectionObserver`. Content fades in with the shared `.lp-reveal`.
- `prefers-reduced-motion`: nothing moves, the tree is drawn complete and the message thread is shown.
- The hub, tree and other illustrations are `aria-hidden`; everything they say is also in the text.
- Layout: the hero, story and trust sections stack under 980 px; under 640 px the tree becomes a
  vertical list and the cards a single column.
