# Shared sender lines

One number, several users. A platform admin hands a line to additional users from
`/admin/numbers`; each of them can then pick it as the sender on every send page, while the line's
**inbox stays with its owner**.

This is the third way a user can reach a line, and the only many-to-many one:

| Mechanism | Column / table | Who gets the line | Send | Inbox, auto-reply, opt-out list |
|---|---|---|---|---|
| Assignment | `ellsms_numbers.assigned_user_id` | exactly one user | yes | yes |
| Organization | `ellsms_numbers.organization_id` | every member of that organization | yes | yes |
| **Share** | `ellsms_number_shares` | any number of users | **yes** | **no** |

## The one rule that makes it work

`app/authorization.php` keeps two lists where there used to be one:

- `allowed_originators($user)` — own + organization. **Ownership scope.** Read by `inbox.php`,
  `autoreply.php`, `line-optouts.php` and `can_view_inbound_message()`.
- `sendable_originators($user)` — the same, **plus** shared lines. Read by `can_use_originator()`,
  which every send path funnels through (`dispatch_message_raw()`, the scheduler, bulk workers, the
  public API).

A share appears in the second list and not the first. That split *is* the "send only" guarantee —
it is not an oversight, and collapsing the two functions back together would silently hand every
shared-line user the owner's incoming messages.

For the pickers the same split holds:

- `user_sendable_numbers($user)` — rows for the "ارسال‌کننده" dropdown on `new-send.php`,
  `send.php`, `p2p-send.php`, `smart-send.php` (and `regional-bulk.php` via
  `sendable_originators()`).
- `user_assigned_numbers($user)` — unchanged, own lines only; still what `autoreply.php` lists.

One edge case is handled explicitly. A user with no line of their own sends from their legacy
`ellsms_meta.originator` (or the panel's `default_originator`) through a **free-text** sender field,
which is what an empty `user_sendable_numbers()` renders. Granting such a user a share turns that
field into a dropdown — so the fallback sender is carried into the list, rather than being quietly
replaced by the shared line. A user with no lines at all still gets the free-text field, unchanged.

### A drift this closed

Before shares existed, `allowed_originators()` already included the organization's numbers (Phase 5)
but the dropdowns only ever listed `assigned_user_id` ones — so an organization member was permitted
to use a line the UI never offered them. Both halves now derive from `user_sendable_numbers()`, so
what a user sees and what `can_use_originator()` accepts cannot diverge again.

`autoreply.php` still has the receive-side version of that same gap (an organization line is valid
for a rule but is not in its dropdown). It is pre-existing, unrelated to sharing, and deliberately
left alone here.

## What a share does not change

- **Credit.** Each sender is charged from their own wallet, exactly as before — a share grants the
  line, never the owner's balance.
- **Gateway and price.** Both come from the number and its route, so every user of a shared line
  sends through the same gateway at the same price (`docs/number-gateway.md`,
  `docs/sms-pricing.md`).
- **Reports.** Each user keeps seeing their own messages only.
- **Opt-outs.** Still enforced on every send from the line (`#38`); a shared-line user simply cannot
  browse the list.
- **The assignment column.** Sharing is additive; it never moves or clears `assigned_user_id`.

## Administration

`/admin/numbers` (platform admin only, `require_admin()`) gains a «کاربران اشتراکی» column: pick a
user to add, press × to remove. Only accounts with ELLSMS panel access can be chosen — the handler
re-checks through `resolve_ellsms_managed_user()`, so a crafted POST cannot share a line with an
arbitrary id.

Both actions are audited as `number.share_add` / `number.share_remove` in `ellsms_audit_log`.

Removal takes effect on the user's next request — nothing in this path is cached. Deleting the
number removes its shares with it (`ON DELETE CASCADE`).

Organization owners cannot share their own lines; this stayed admin-only deliberately. Giving them
that power needs a new RBAC permission and a user-facing page, neither of which exists yet.

## Files

| | |
|---|---|
| Schema | `db/migrations/2026_10_04_shared_numbers.sql` |
| Logic | `app/authorization.php` — `user_shared_numbers()`, `user_sendable_numbers()`, `sendable_originators()` |
| Admin UI | `public/numbers.php` |
| Send pages | `public/new-send.php`, `send.php`, `p2p-send.php`, `smart-send.php`, `regional-bulk.php` |
| Tests | `tests/Integration/SharedNumbersTest.php` |
