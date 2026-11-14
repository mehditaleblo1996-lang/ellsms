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
| `public/assets/video/ai-explainer/` | The «منشی هوشمند» explainer film shown in `#ai-video` (three MP4 cuts and two covers) |

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
| `#ai-video` | **«با منشی هوشمند داتیس آشنا شوید»**: the 60-second explainer film, described below |
| `#ai-secretary` | **«منشی هوشمند» showcase**: the seven-step slider described below |
| `#compare` | Manual sending vs. the panel |
| `#pricing` | Pricing cards (only when packages exist) |
| `#how` | Three steps to the first send |
| `#use-cases`, `#trust`, `#faq` | Use cases, trust points, FAQ (includes «منشی هوشمند چیست و چه هزینه‌ای دارد؟») |

The nav has a «منشی هوشمند» link to `/landing.php#ai-secretary`.

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

## Explainer film (`#ai-video`)

A 60-second Persian film in which the DATIS robot explains, with worked examples: personal messages
built from a customer table, «پیام اول» (table → filter → instruction → two previews → confirmation →
send), an incoming SMS answered from the sender's own order row, conversation memory, hand-off to a
human operator (the AI stops answering), and the controls (preview, sending caps, cancelling what is
left, SMS + AI cost). Every name, number, tracking code and chart in it is a fictional sample.

| File in `public/assets/video/ai-explainer/` | Used for |
|---|---|
| `datis-ai-explainer-16x9-web.mp4` | 1920×1080, 60 fps: the default |
| `datis-ai-explainer-16x9-720p.mp4` | 1280×720: landscape screens narrower than about 1400 device pixels |
| `datis-ai-explainer-9x16-web.mp4` | 1080×1920: phones held upright (`max-width: 640px` and portrait) |
| `cover-16x9.webp`, `cover-9x16.webp` | The cover shown before play (lazy-loaded `<picture>`) |

The films have burned-in Persian subtitles, so they are understandable with the sound off; the full
narration is also under «متن کامل ویدیو» below the player.

Behaviour (inline script after the section, styles in `landing-ai.css` under `#ai-video`):

- Nothing is downloaded before the visitor presses «پخش ویدیو» (`preload="none"`, the cover image is
  `loading="lazy"`). The file is chosen at that moment for the screen. It never autoplays.
- Controls: play/pause, replay from the start, a time bar (click, or focus it and use ←/→ ±5 s,
  Home/End), mute, and full screen. Space or K plays/pauses, M mutes. All buttons have Persian
  `aria-label`s and a visible focus ring; the time bar is an ARIA slider.
- Playback pauses when the player scrolls out of view or the tab is hidden.
- `prefers-reduced-motion`: no transitions on the player; the film only ever plays on request.
- Without JavaScript the `<video controls>` element plays the 16:9 file with the browser's controls.
- Below the player: «آشنایی با مراحل منشی هوشمند» (`#ai-secretary`) and «درخواست فعال‌سازی»
  (`/contact.php`).

To replace the film, keep the file names (or update the `data-src-*` attributes and the `<source>`).
The film is made with Remotion from source kept outside this repository
(`mehditaleblo1996-lang/datis-advertise`, `video/datis-ai-explainer/`): narration, timing, scenes
and render scripts live there. Keep each web file well under 15 MB.

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
