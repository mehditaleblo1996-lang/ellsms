# Self-registration («ثبت‌نام»)

The public sign-up page `/register` (`public/register.php`) lets a customer request an account. The
request is verified by SMS code, reviewed by an admin (or by the reseller whose sign-up link / host it
came through — see `docs/reseller-panel.md`), and only then turned into a real account.

## Flow

1. **`/register`** — the applicant fills the form. Rate-limited per IP and mobile
   (`RATE_LIMIT_REGISTRATION_MAX` / `RATE_LIMIT_REGISTRATION_WINDOW_SECONDS`); past the limit a
   self-hosted arithmetic CAPTCHA is asked; a honeypot field silently drops bots.
2. **`/register-verify.php`** — the 6-digit mobile code. After it the request is
   `pending_admin_approval` and the admins are notified.
3. **«درخواست‌های ثبت‌نام»** (`/registration-requests.php`, `/registration-request.php?id=…`) — the admin
   sees everything the applicant entered, confirms national code / gender, **chooses the domain**, and
   approves (`registration_activate_account()`, `app/RegistrationActivation.php`) or rejects with a
   reason.

## What the form asks — the same as «ساخت حساب تازه»

The sign-up form is the admin's create form: it renders the shared
`app/views/customer_identity_fields.php` («مشخصات مشتری», see `docs/customer-profile.md`) in `create`
mode and validates it with the same `profile_identity_validate($identity, true)`:

- **«نوع حساب»** — «حقیقی» / «حقوقی» switch.
- **Person** — first / last name, national code (required), father's name, birth certificate no.,
  birth date, gender. For a legal account this is the company's representative (the account holder).
- **Contact** — mobile and email (both required).
- **Company** (legal only) — legal name, registration no., company national id, economic code
  (required), company type, landline, start / expiry dates.
- **Address** — country, province, city, street, alley, building no., unit, postal code, full address.
- **Login** — username, password + repeat (8–128 characters with a letter and a digit, not the username; checked while typing — `docs/password-security.md`), and the terms / privacy consent.

The whole «مشخصات مشتری» part is stored on the request as JSON (`profile_json`, the
`profile_identity_from_request()` shape; read back with `registration_request_identity()`). On
activation it is written with `profile_identity_save()` — user profile, organization profile and
address in one transaction, exactly what «ساخت حساب تازه» saves; the national code / gender confirmed
by the admin on the review page win over what was typed. Requests made before this change have no
`profile_json` and activate as before (account type + company name only).

## Admin-only settings — never on the public form

Things that only an admin decides are **not** asked at sign-up and stay on the admin side:

- **Domain** — chosen by the admin on the review page when approving.
- **Daily send limit** — the `registration_default_daily_limit` setting (default 1000), editable later
  on the user's page.
- Customer type / reseller, prices («تعرفه‌ی اختصاصی»), delivery-based billing, menu roles, lines,
  plan — all set afterwards on `/users.php?edit=…` as for any account.

## Migrations

- `db/migrations/2026_08_25_registration_requests.sql`, `2026_08_26_registration_otp.sql`,
  `2026_08_26_registration_admin_review.sql`, `2026_08_26_registration_activation.sql`,
  `2026_10_18_registration_terms_acceptance.sql`, `2026_10_24_reseller_panel.sql` — the request table.
- **`db/migrations/2026_11_03_registration_profile.sql`** — adds `profile_json` (the full
  «مشخصات مشتری» of the request). Required by the current `/register` form.
