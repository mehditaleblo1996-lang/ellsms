# Account passwords — rules, storage, guessing limit, sign-out on change

Code: `app/PasswordSecurity.php` (every place that sets or checks an account password goes through
it). Live check in the browser: `public/assets/js/password-policy.js`. Migration:
`db/migrations/2026_11_15_password_version.sql`. One-time conversion command:
`cron/password-migrate.php` (`make password-migrate`).

## The rules for a new password

A password that is **set** — «تغییر رمز عبور» on `/account/profile`, self-registration (`/register`),
the admin's «ساخت حساب تازه» and password reset (`/admin/users`), a reseller creating or resetting a
customer or a staff account, a parent account creating or resetting a sub-account — must have:

| Rule | Persian text shown to the user |
|---|---|
| 8 to 128 characters (counted as characters, not bytes) | «حداقل ۸ نویسه (و حداکثر ۱۲۸)» |
| at least one letter (any script) | «دست‌کم یک حرف» |
| at least one digit (Latin or Persian) | «دست‌کم یک عدد» |
| not equal to the username (case-insensitive) | «با نام کاربری یکسان نباشد» |

- The rules are listed under the password field and each line turns green (✓) or red (✕) **while the
  user types**; the form does not submit until all are met. The server checks the same rules again
  (`password_policy_error()`), so the browser check is a convenience, not the gate.
- Changing one's own password also requires the new password to differ from the current one.
- **Existing passwords keep working.** The rules apply only when a password is set; an account whose
  password is shorter or has no digit signs in as before, until its owner changes it.

## Guessing the current password

Proving the current password (changing the password, switching Google Authenticator or SMS OTP on or
off on `/account/security/`) is limited per account: `RATE_LIMIT_PASSWORD_CHECK_MAX` attempts
(default 5) per `RATE_LIMIT_PASSWORD_CHECK_WINDOW_SECONDS` (default 900). Past the limit the form
answers «رمز عبور فعلی چند بار پشت سر هم اشتباه وارد شده است…» and logs
`auth.password_check.rate_limited`. Every attempt counts, right or wrong, so an attacker sitting at an
open session cannot try passwords without end.

## Signing other sessions out when the password changes

`ellsms_meta.password_version` counts an account's password changes. A session remembers the number
it signed in under (`$_SESSION['_pwv']`); `current_user()` signs out a session that holds an older
number, with «رمز عبور این حساب تغییر کرده است؛ برای ادامه دوباره وارد شوید.» on the login page and
`auth.session_ended_password_changed` in the log.

- The user's own change: every **other** browser and device is signed out; the session that made the
  change stays.
- A reset by an admin, a reseller or a parent account: **every** session of that account is signed out.
- API keys are not sessions and are not affected. The legacy URL send (`/sms/url_send.html`) sends the
  password on every request, so it simply needs the new one.
- A platform admin's support session (impersonation) is not ended by the customer's password change.
- Applying the migration signs nobody out (the column defaults to 0 and an older session counts as
  0). Before the migration is applied the feature is off and nothing else changes.

## How the password is stored — `PASSWORD_HASH_MODE`

| Mode | What is stored | Who can check a password |
|---|---|---|
| `legacy` (default) | `user_.password` = the backend platform's **unsalted SHA-256**, as always | ELLSMS and the backend platform |
| `argon2id` | `ellsms_password_verifiers.verifier` = **Argon2id( hex( SHA-256(password) ) )**; `user_.password` = 32 random bytes | ELLSMS only |

Unsalted SHA-256 is fast to crack if the database or a backup is ever read. `argon2id` mode removes
it, and does so **without anyone having to change their password**:

- The Argon2id verifier is computed over the SHA-256 digest ("hash wrapping"), so it can be built from
  what is already stored. `make password-migrate` converts every panel account at once; an account it
  has not reached is converted the first time it signs in.
- Sign-in computes SHA-256 of what the user typed and checks it against the Argon2id verifier. Same
  password, same username; the user notices nothing.
- A password set after the switch is stored only as the Argon2id verifier.
- An account the backend creates (`POST /api/users/`) is converted right after it is created.

### Switching to `argon2id`

**Before switching, confirm that nothing but ELLSMS signs users in with `user_.password`.** After an
account is converted, the backend platform's own login for that account stops working, because the
column no longer holds its hash. If the backend's own login is still in use, stay on `legacy`.

1. Take a backup (`make backup`).
2. Apply migrations (`make db-migrations-apply`).
3. Set `PASSWORD_HASH_MODE=argon2id` in `.env` and redeploy.
4. `make password-migrate-dry-run`, then `make password-migrate`.

**It is one way.** Setting the mode back to `legacy` does not restore the SHA-256 column: a converted
account cannot sign in until its password is reset. Restore the backup from step 1 to go back.

Where the PHP build has no Argon2id, bcrypt is used instead (`algo = bcrypt-sha256`).

Rows that an earlier release wrote to `ellsms_password_verifiers` with `algo = argon2id` or `bcrypt`
(a shadow copy made at sign-in, never used for access) are **not** trusted: they may be older than the
current password. They are overwritten when the account is converted.

### What stays as it was

- A self-registration request keeps the SHA-256 of the chosen password on its row until an admin
  approves or rejects it; it is cleared at that point.
- 2FA (SMS OTP and Google Authenticator) is unchanged.
- Backups hold whatever the database holds. With `legacy` mode that includes the SHA-256 column —
  encrypt backups (`BACKUP_ENCRYPTION_ENABLED=1`, `docs/backup-and-disaster-recovery.md`).

## Settings

| Variable | Default | Meaning |
|---|---|---|
| `PASSWORD_HASH_MODE` | `legacy` | `legacy` or `argon2id` (see above) |
| `RATE_LIMIT_PASSWORD_CHECK_MAX` | `5` | attempts at the current password per window, per account |
| `RATE_LIMIT_PASSWORD_CHECK_WINDOW_SECONDS` | `900` | the window |

## Log events

`auth.password_changed`, `auth.password_check.rate_limited`, `auth.session_ended_password_changed`,
`auth.password_converted`, `auth.password_convert_failed`, `auth.password_migrate.started` /
`.finished` / `.account_failed`. Audit log actions `password.change`, `user.password_reset`,
`customer.child_password_reset`, `reseller.staff_password_reset` are unchanged.

## Tests

`tests/Unit/PasswordPolicyTest.php` — the rules, the hint markup, the mode switch and the wrapped
verifier round trip.
