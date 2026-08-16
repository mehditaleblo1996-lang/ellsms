# ELLSMS — font assets

**Status: installed and active.** All five required Vazirmatn weights are present and self-hosted.
Verify at any time with `make font-check` (exit 0 = healthy).

## Installed files

Sourced from the official upstream repository
<https://github.com/rastikerdar/vazirmatn>, commit `6e553e33489a8f9dfaccc76860a2e3f3c1e66de7`,
upstream path `fonts/webfonts/`. Copied byte-for-byte — the binaries are unmodified.

| File | Weight | Bytes | Used for |
|---|---:|---:|---|
| `Vazirmatn-Regular.woff2`   | 400 | 50,684 | body text, table cells, inputs |
| `Vazirmatn-Medium.woff2`    | 500 | 51,128 | secondary emphasis |
| `Vazirmatn-SemiBold.woff2`  | 600 | 51,032 | labels, table headers, badges |
| `Vazirmatn-Bold.woff2`      | 700 | 51,020 | buttons, headings |
| `Vazirmatn-ExtraBold.woff2` | 800 | 51,120 | stat values, price amounts |

The weight→file mapping in `public/assets/css/fonts.css` matches upstream's own
`Vazirmatn-font-face.css` exactly, so nothing is guessed.

### Which upstream variant, and why it matters

The repository ships 16 webfont variants. `fonts/webfonts/` — the default — is the correct one:

- `misc/Farsi-Digits*` force Persian digits, which would corrupt the appearance of Latin-digit
  values the panel deliberately renders LTR (phone numbers, provider references, prices).
- `misc/*Non-Latin*` strip Latin glyphs, which the panel needs for gateway codes, API keys and URLs.
- `misc/UI*` are UI-metric variants with tighter vertical metrics.
- `Round-Dots/` is a stylistic alternate.

Confirmed objectively: `Vazirmatn-Regular.woff2` from `fonts/webfonts/` has SHA256
`e382101336c6eb32cfb31381c027d02d2e0354bad08f6a395d4088beb3db3d91`, matching the reference checksum
in the supplied integration pack's `FONT_FILES_REQUIRED.md` byte-for-byte.

## Licence

Vazirmatn is licensed under the **SIL Open Font License 1.1**, which permits embedding and
redistribution. The full licence text ships beside the binaries as `OFL.txt`, as the licence
requires — do not delete it.

## Re-installing or upgrading

1. Clone the official repository **outside** this project:
   `git clone --depth 1 https://github.com/rastikerdar/vazirmatn.git /tmp/vazirmatn-font-source`
2. Copy the five files above from `fonts/webfonts/` into this directory, keeping their names.
3. Copy `OFL.txt` from the repository root into this directory.
4. Run `make font-check`. Exit 0 means every declared weight is present, is a genuine WOFF2, and is
   served from this host.
5. Delete the temporary clone — never vendor the upstream repository or its `.git` into ELLSMS.

## Notes

- **Do not** add a Google Fonts / CDN `@import` as a shortcut. `make font-check` fails if one
  reappears, and it would reintroduce the external dependency this work removed.
- If you ever switch to the variable font (`Vazirmatn[wght].woff2`), point all five `@font-face`
  rules at it and give each a single `font-weight` value, so the browser instances the correct
  weight rather than synthesising it.
- If these files are ever removed, the panel falls back to Tahoma and stays fully usable including
  Persian. That is the designed fallback, not a broken state.
