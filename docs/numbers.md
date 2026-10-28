# Numbers (/numbers.php)

Admin-only management of sender lines, modelled on the Vesal portal's «مدیریت شماره‌ها»
(`customer_number_list`, `number_edit`, `number_add`, `number_owner_info`). Migrations:
`db/migrations/2026_10_28_number_details.sql` and `2026_10_29_number_owners_and_quota.sql`; until
they run the page keeps working and the new parts are hidden. Logic lives in `app/NumberDetails.php`.

## Pages

- **List** — filters (number/label, user, service line, send type, opt-out bypass, gateway, status,
  expiry), rows coloured by status with a clickable legend, 25 per page, «خروجی Excel» (CSV with BOM,
  same filters). Each row has an edit icon and a «…» menu of quick actions.
- **Add** — one number, a range (`از … تا …`, same length, at most 10,000) or a file (xlsx/csv/txt,
  column A, a first row without digits is a header), with label, owner, initial status and expiry.
  Range and file adds leave a per-number report (added / already there / invalid / duplicate) for
  download.
- **ویرایش شماره** (`?id=`) — every per-line setting: details, the quick-action toolbar, assignment
  and the owner's bandwidth ceiling, shares, service line, opt-out bypass, gateways, URL forwarding,
  owner info, delete.
- **مالکان شماره‌ها** (`?tab=owners`) — searchable list of recorded owners.

## What changes sending

| Setting | Effect |
|---|---|
| Status ≠ `active`, or `expires_on` before today (Tehran) | the line cannot send for anyone: `can_use_originator()`, the sender pickers and `dispatch_message_raw()` (with the reason, for the line's own users). Inbound SMS, inbox, delivery reports and forwarding continue. The system line is never blocked. |
| `bypass_blacklist` (خدماتی) | skips the customer blacklist — docs/service-lines.md |
| `bypass_optouts` (عبور از لیست سیاه داخلی) | `line_optout_filter()` returns nothing for the line, so "11" opt-outs are not applied on any path. Meant for OTP-style lines only. |
| User bandwidth ceiling (`ellsms_user_line_quota`) | the bandwidth of a user's lines — except reserved, transferred and deleted ones — may not exceed it: assigning, bulk-adding for them, raising bandwidth or reactivating a line that would pass it is refused. No row = no ceiling. |

Status, expiry and the opt-out bypass are cached for 30 seconds per process (`number_unsendable_map()`,
`number_bypasses_optouts()`), so a change reaches long-running workers within that time.

Recorded and shown only: priority, bandwidth (other than the ceiling check), confidential sending,
inbound cost, the commitment letter (`storage/number-commitments/`), the block/deactivation reason
and date, and owner info (nothing is sent to the regulator).

## Quick actions

Deactivate / block (with an optional reason, stored with the date), unblock / activate, one-year
renewal (one Jalali year past the later of today and the current expiry), service line, opt-out
bypass, confidential, delete (status `deleted`, restorable) and restore. Every action is audited
(`number.*`). Permanent deletion stays on the edit page.
