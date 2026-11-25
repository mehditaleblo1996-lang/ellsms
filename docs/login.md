# Sign-in page (`/login.php`)

The page where every user (customer, reseller, staff, admin) signs in. It is a single card on a
light background: the form in its start half and, on the platform's own host, a slider of what the
panel does in its end half. After a correct username and password the user goes to the dashboard or,
with two-step sign-in on, to «تأیید دومرحله‌ای» (`/login/verify-2fa`). The sign-in logic (rate
limits, panel access, reseller host checks, blocked accounts, SMS / app codes) is unchanged by the
page's look and is described in [reseller-panel.md](reseller-panel.md) and the README.

| File | What |
|---|---|
| `public/login.php` | The page: the sign-in logic and the markup (background layers, header, card, form, mobile band) |
| `app/views/partials/login_slider.php` | The slider in the card's end half (platform host only): five slides, each a looping scene drawn in HTML/CSS, with title and text |
| `public/assets/css/login.css` | Tokens of the sign-in page (`.auth`), background, card, form, the visual layer and the narrow-screen band |
| `public/assets/css/login-slider.css` | The slider (`.ls`, `.sc-*` scenes), its orbit ring and controls |
| `public/assets/js/login.js` | Show / hide password, the Caps Lock hint, the busy state of «ورود», and the slider |

No table, setting or migration belongs to the page's look. It reads the registration setting (whether
«ثبت‌نام کنید» is shown) and, on a reseller's host, that reseller's brand.

## Layout

- **Header**: the DATIS logo (links to the home page) and «درباره‌ی داتیس رایان گستر ایرانیان» as a
  small glass pill with an arrow. On a reseller's host: the reseller's logo or name and its support
  phone instead.
- **Card** (`.auth-card`): frosted white glass with a lit turquoise edge (a gradient border that is
  brighter at two opposite corners), a hairline of light along the top and a deep, tinted shadow.
- **Form side**: the pill «خوش آمدید» (with a pinging dot), the title «ورود به حساب کاربری», the
  line under it (or the reseller's own login text), the error / notice box (with a round «!» / «i»
  marker), the two fields, «ورود», a note with a shield icon — «اگر ورود دومرحله‌ای برای حساب شما
  فعال باشد، در مرحله‌ی بعد کد تأیید خواسته می‌شود.» — and «حساب کاربری ندارید؟ ثبت‌نام کنید» when
  sign-up is open.
- **Fields**: a person / padlock icon at the start of each left-to-right field that turns turquoise
  on focus, a soft inner shade at rest, and a ring of light on focus. «نمایش» / «پنهان» on the
  password field has an eye icon that closes while the password is shown.
- **Caps Lock hint**: while typing in the password field with Caps Lock on, «کلید Caps Lock روشن
  است.» appears under it (`#authCaps`, `role="status"`); it hides again when Caps Lock is off or the
  field loses focus.
- **«ورود»**: turquoise with a light sweep and an arrow that slides on hover. Once the form is
  submitted (after the browser's own required-field check) the arrow is replaced by a spinner
  (`.is-busy`, `aria-busy="true"`); coming back with the browser's back button clears it.
- **Slider** (platform host, wide screens): see the comments in `login_slider.php`. It sits on a
  graphite panel with turquoise glows, a faint grid and a brushed-metal orbit ring (echoing the home
  page's core) with a light running round it; the active progress tick glows. It pauses on hover /
  focus, in a hidden tab and with the pause button.
- **Narrow screens (≤ 860px)**: the slider is hidden and, on the platform host, the card opens with
  a small graphite band (`.auth-mhero`) holding the DATIS core — the graphite sphere with the light
  mark, a turning orbit line and three twinkling sparks. On a reseller's host there is no band.
- **Short laptop screens (≥ 861px wide, ≤ 820px tall)**: tighter spacing so the whole card stays
  above the fold (checked at 1280×720).

## Background

`.auth-bg` is fixed behind everything and `aria-hidden`: two soft brand-coloured glows that drift
slowly, a fine grid that fades out from the centre, two brushed-metal rings in perspective (the large
one behind the card's side turns slowly), and three light streaks gliding right to left. All of it is
hidden for `prefers-contrast: more`.

## Colours and reseller hosts

The page uses the white + graphite + turquoise identity of the public site
([landing.md](landing.md#colours-public-site-and-sign-in-pages)). Every accent — button, focus ring,
icons, the pill, the card's lit edge, the background glows — is derived from `--a-brand`, which is
the platform turquoise or, on a reseller's host, `--login-brand` from `brand_color_style()`
(`app/ResellerPanel.php`). So a reseller's sign-in page takes its own colour throughout. The
metal rings and the slider are neutral graphite / turquoise decoration; the slider and the mobile
band are not shown on a reseller's host.

## Motion and accessibility

Only `transform` and `opacity` animate. With `prefers-reduced-motion: reduce` the glows, rings,
streaks, ping, orbit spark and mobile band stand still and the slider does not advance by itself.
Decoration is `aria-hidden` and takes no pointer events; the form works without script (the reveal
button, Caps Lock hint and busy state are added by `login.js`).
