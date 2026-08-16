# ELLSMS — Global font integration

**Status: complete and active.** All five Vazirmatn weights are installed and self-hosted; the panel
renders in the intended font with no external dependency. Verify with `make font-check` (exit 0).

The binaries were not in the supplied pack and were obtained separately from the official upstream
repository — see [Font binaries](#font-binaries).

## What this changed

The panel previously loaded Vazirmatn from a public webfont CDN via an `@import` at the top of
`style.css`. Every page render therefore depended on a third-party host: a blocked or slow provider
degraded the whole UI, and each page view leaked a request off-site. That import is gone. Fonts are
now declared once, locally, in `public/assets/css/fonts.css`, and served from this application's own
static assets.

Typography is otherwise unchanged by design — the site already used Vazirmatn, so this is a
change of *delivery*, not of *appearance*. No colours, spacing, components, layout or RTL behaviour
were touched.

## Font binaries

The supplied pack (`ai-bilingual-rtl-font-integration-pack-v1.zip`) contained **no font files** —
its own `manifest.json` declares `"contains_font_binaries": false`, and `font-assets/` held only a
placeholder. The binaries were therefore obtained from the official upstream repository instead:

| | |
|---|---|
| Source | <https://github.com/rastikerdar/vazirmatn> (official, `rastikerdar`) |
| Commit | `6e553e33489a8f9dfaccc76860a2e3f3c1e66de7` (2023-05-01) |
| Upstream path | `fonts/webfonts/` |
| Method | shallow clone to a temp directory outside the repo, copy 5 files, delete the clone |

No CDN, mirror, or third-party download was used. The binaries are unmodified — each installed file
hashes identically to its upstream source.

**Variant choice matters.** The repository ships 16 webfont variants; `fonts/webfonts/` (the
default) is correct. `misc/Farsi-Digits*` force Persian digits, which would corrupt the Latin-digit
values the panel deliberately renders LTR (phone numbers, provider references, prices);
`misc/*Non-Latin*` strip the Latin glyphs needed for gateway codes, API keys and URLs; `misc/UI*`
alter vertical metrics; `Round-Dots/` is a stylistic alternate. The choice was confirmed objectively
rather than by name: `Vazirmatn-Regular.woff2` from `fonts/webfonts/` has SHA256
`e382101336c6eb32cfb31381c027d02d2e0354bad08f6a395d4088beb3db3d91`, matching the reference checksum
in the pack's own `FONT_FILES_REQUIRED.md` byte-for-byte.

The weight→file mapping in `fonts.css` was verified against upstream's own
`Vazirmatn-font-face.css` and matches for all five weights, so `fonts.css` needed no edits.

`OFL.txt` ships beside the binaries as the SIL Open Font License requires for redistribution.

Health is checked by `make font-check`: exit 0 means every declared weight is present, is a genuine
WOFF2, and is self-hosted; exit 1 names precisely what is missing. Details in
`public/assets/fonts/README.md`.

## Font identity

| | |
|---|---|
| Family | **Vazirmatn** |
| Script coverage | Persian/Arabic **and** Latin/digits (a single family covers both, so no second face is needed) |
| Licence | SIL Open Font License 1.1 — embedding and redistribution permitted; full text shipped as `public/assets/fonts/OFL.txt` |
| Format | WOFF2 |
| Style | normal only (no italic — Vazirmatn ships none, and the UI requests none) |

### Weights

Five weights, matching what the CSS actually requests (the previous CDN import specified
`wght@400;500;600;700;800`):

| Weight | File | Used by |
|---:|---|---|
| 400 | `Vazirmatn-Regular.woff2` | body text, table cells, inputs |
| 500 | `Vazirmatn-Medium.woff2` | secondary emphasis |
| 600 | `Vazirmatn-SemiBold.woff2` | labels, table headers, badges |
| 700 | `Vazirmatn-Bold.woff2` | buttons, headings |
| 800 | `Vazirmatn-ExtraBold.woff2` | stat values, price amounts |

Each weight points at its **own** file. No weight is faked — declaring one file as several weights
would make the browser synthesise (smear) the others.

## Architecture

The project has **no Bootstrap, no DataTables, no jQuery and no vendor CSS** — a single hand-written
stylesheet, already variable-driven. There was therefore nothing to override and no vendor file to
edit.

```
public/assets/css/fonts.css   ← the ONLY @font-face declarations in the project
        ↑ @import
public/assets/css/style.css   ← :root variables + global typography floor
        ↑ <link>
app/views/header.php, app/views/public_header.php,
public/login.php, public/verify-2fa.php,
public/bootstrap-admin.php, public/logout.php
```

Those six entry points cover every page — panel, admin and public — so the change reaches the whole
UI rather than one section.

### Variables

```css
--font-ui   : 'Vazirmatn', 'Segoe UI', Tahoma, Arial, sans-serif;   /* the default typeface */
--font-num  : var(--font-ui);                                       /* display numerals */
--font-mono : ui-monospace, "SF Mono", "Cascadia Mono", Consolas,
              "Liberation Mono", monospace;                         /* diagnostic values */
```

`--font-ui` is the single source of truth. `--font` and `--mono` are retained as aliases so the
existing call sites keep working without a sweeping rename of unrelated CSS.

### A defect fixed along the way

`--mono` previously resolved to `'Vazirmatn', ui-monospace, …`. Because Vazirmatn is a
**proportional** face and came first, anything marked "monospace" — including provider references
and API keys — was never actually monospaced. The variable is now split by *role*:

- **`--font-num`** — dashboard stat values, price amounts, chart labels. Branded headline numbers
  that want the UI face, kept LTR and tabular. These keep their exact previous appearance.
- **`--font-mono`** — `provider_message_id`, API keys, hashes, request IDs, JSON. Genuinely
  fixed-width, so a 19-digit reference can be compared character by character against a provider's
  dashboard.

Keeping these separate matters: repointing every `--mono` call site to a true monospace would have
restyled the dashboard's headline numbers, which is a redesign this work must not perform.

## Global coverage

Browsers do **not** inherit `font-family` into form controls from `body` — the UA stylesheet
substitutes a system face. Without naming them explicitly, inputs would silently render in a
different font from everything around them. Declared once:

```css
html, body, button, input, select, textarea, option, optgroup,
table, th, td, a, label, legend, fieldset, dialog, [role="dialog"] { font-family: var(--font-ui); }
```

`option` and `optgroup` are included because Windows styles dropdown items from the UA sheet rather
than from the `<select>`.

## Long provider references

A 19-digit reference such as `4473621976262727360` must remain exact and legible. Typography is
presentation only and never alters the value; verified byte-exact in a served page.

Handling:

- `.provider-ref` applies `--font-mono` with `tabular-nums`, so digits align in a fixed column.
- `direction: ltr; unicode-bidi: isolate` prevents visual reversal inside RTL text.
- `word-break: break-all` keeps a long reference inside its table cell instead of widening the row.
- `user-select: all` makes it a one-click copy.
- Every report table sits in `.table-wrap { overflow-x: auto }`, so narrow screens scroll rather
  than break the layout.

Inline `style="font-family:monospace"` in `message-detail.php` (×2), `api-keys.php` and
`webhooks.php` was replaced with the semantic `.provider-ref` / `.api-key` classes, centralising
typography per STEP 8.

## RTL

Untouched. `html { direction: rtl }`, `dir="rtl"` on every page, `th, td { text-align: right }`,
logical properties (`margin-inline-start`) and the existing `.ltr` isolation for Latin runs all
remain exactly as they were. Vazirmatn is a Persian-first family, so RTL shaping is native.

## Deliberately not changed

`app/maintenance.php` and `app/Support/ErrorHandler.php` emit self-contained HTML with inline
`font-family: Tahoma, Arial, sans-serif` and link **no** stylesheet. That is correct: these pages
must render when the application is degraded or erroring, so giving them a webfont dependency would
be a regression. Their inline Tahoma *is* the fallback stack.

## Performance & security

- `font-display: swap` on all five faces — text paints immediately in the fallback and repaints when
  Vazirmatn arrives. A delivery report is never invisible while a font downloads.
- WOFF2 only, the most compressed format.
- **No preload.** The project has no existing preload architecture, and preloading five weights
  would compete with the stylesheet for early bandwidth. Not added speculatively.
- Fonts are ordinary static files under `public/assets/fonts/`; normal web-server caching applies.
  No PHP route, no database dependency, no dynamic or user-controlled font path, so no traversal
  surface. No secrets in CSS or asset metadata.

## Verification

| Check | Result |
|---|---|
| `make lint` | 272 PHP files parse cleanly |
| `make font-check` | **Exit 0** — all 5 weights present, valid WOFF2, self-hosted |
| Installed binaries | 5/5, each SHA256-identical to upstream (unmodified) |
| `file(1)` on all five | "Web Open Font Format (Version 2), TrueType" |
| Font assets over HTTP | 5/5 HTTP 200, `Content-Type: font/woff2`, served bytes == on-disk |
| CSS brace balance | style.css 239/239, fonts.css 5/5 |
| `@font-face` declarations | 5 blocks, 5 distinct files, all `font-display: swap` |
| External host references | **0**, verified in served bytes and repo-wide |
| `@import` chain | resolves to `/assets/css/fonts.css` (local) |
| Variable resolution | `--font-ui` / `--font-num` / `--font-mono` resolve correctly |
| Duplicate `font-family` rules | none |
| 19-digit reference in served HTML | `4473621976262727360` — byte-exact |
| Static assets | `style.css` HTTP 200, `fonts.css` HTTP 200 |

Pages could not be rendered end-to-end in this environment: `login.php` returns HTTP 500 from
`PDOException: Access denied for user 'change_me'` — no database is running here. That failure
occurs at DB connection, before any HTML or CSS is emitted, and is unrelated to typography. CSS
delivery was verified directly instead, plus a representative Persian RTL page exercising tables,
badges, form controls and a long provider reference.

## Business logic

None touched. This change is confined to CSS, three inline-style attributes in view markup, one new
verification script, one Makefile target and this document. No gateway, polling, routing, billing,
pricing, sending, API, database, queue, worker or permission code was modified.
