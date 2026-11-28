# User page header — profile card and «تنظیمات و دسترسی‌ها»

The top of a user's page in the platform admin (**«کاربران» → a user**, `/users.php?edit=<id>`), above the
tabs («حساب و اعتبار», «مدارک», …). Admin-only; customers and resellers never see it.

Code: `app/views/user_admin_header.php` (markup), `public/assets/css/user-admin.css` (styles, loaded only on
`/users.php`), the last block of `public/assets/js/panel.js` (switches, copy, quick links). No new table,
migration, setting or API route.

## 1. Profile card

| Part | What it shows |
|---|---|
| Identity | an avatar with the first letter of the name (gold for a «مشتری ویژه»), username, «★ ویژه» badge, full name, «شناسه» (user id) and the organization name |
| Status chips | «فعال» / «غیرفعال» / «حذف‌شده», «تعلیق‌شده» and «منقضی‌شده» (from «نمایندگی و دسترسی منو»), «مدیر» / «کاربر», the customer type («عادی», «نماینده», «سازمانی», …), «حقیقی» / «حقوقی», and whether «۲مرحله‌ای» is on |
| Quick actions | «ورود به پنل مشتری» (support impersonation, [admin-impersonation](admin-impersonation.md); shown only when allowed), «تغییر اعتبار» and «رمز عبور» (open the «حساب و اعتبار» tab and scroll to that card), «بازگشت به فهرست» |
| Facts | «اعتبار» (rial), «پیامک ارسال‌شده» (same count as the users list), «موبایل» and «ایمیل» (each with a copy button), «خط ارسال» (the first line the user may send from, `+n` for more; «همه‌ی خطوط» for an admin; a red «خطی تخصیص نیافته» link to «شماره‌ها» when none), «آخرین ورود» (time and IP of the last `login` / `login_2fa` / `login_totp` row in `ellsms_audit_log`) and «اعتبار حساب تا» (only when the account has an expiry date; red once passed) |

An inactive / deleted account, or a suspended one, gets a red notice under the identity row.

## 2. «تنظیمات و دسترسی‌ها»

Every per-user admin switch is one row — icon, title, a short explanation, its current state, who set it and
when (where recorded), and a toggle switch. **A switch saves as soon as it changes** (the page reloads with
the usual message). Each row is still an ordinary POST form, so without JavaScript its «ثبت» button saves it.

| Row | Action (`do`) | Notes |
|---|---|---|
| «نقش مدیر» | `toggle_admin` | asks for confirmation; locked (padlock) on your own account |
| «ورود دومرحله‌ای» | `toggle_2fa` | |
| «مشتری ویژه» | `vip_save` | [vip-customers](vip-customers.md); needs `2026_11_12_vip_customers.sql` |
| «ارسال خودکار نظیر به نظیر» | `p2p_auto_send_save` | [p2p-auto-send](p2p-auto-send.md); needs `2026_11_15_p2p_auto_send.sql` |
| «افزودن «لغو۱۱» به انتهای پیام» | `optout_suffix_save` | [optout-suffix](optout-suffix.md); needs `2026_11_24_user_optout_suffix.sql` |

A row whose migration has not been applied is simply not shown.

`toggle_admin` and `toggle_2fa` accept the wanted state: the switch posts `set=1` plus `on=1` (on) or no `on`
(off), so a double submit cannot flip the value back. A post without `set` still flips the value as before.
Both now return to the user's page instead of the list. Every change stays audited (`user.toggle_admin`,
`user.toggle_2fa`, …).
