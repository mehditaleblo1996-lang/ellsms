# Resellers, organizational customers, sub-account credit and menu access

Modeled on the Negar platform's customer types (`RESELLER` / `ORGAN` / `NORMAL_CUSTOMER`, its
`maxSubsetLimit`, parent-to-child credit transfer, per-customer tariff and nightly
`InsertMtProfitCreditJob`) and its role-based menu access (`Role` / `AccessName`).

Code: `app/CustomerHierarchy.php`, `app/MenuAccess.php`, `public/subaccounts.php`,
`public/menu-roles.php`, `public/reseller-profits.php`, the «نمایندگی و دسترسی منو» tab of
`public/users.php`. Schema: `db/migrations/2026_10_12_customer_hierarchy.sql`.

**Inert until migrated and configured.** Before the migration is applied, and for any account with
no row in `ellsms_customer_accounts`, nothing changes: a normal customer, 100% tariff, no expiry, every
menu visible.

## Customer types

| Type | Label | May create | Notes |
|---|---|---|---|
| `normal` | مشتری عادی | nothing | |
| `reseller`, level 0 | نماینده | normal, organ, reseller | created (or promoted) by a platform admin |
| `reseller`, level ≥ 1 | زیرنماینده | normal, organ | |
| `organ` (top level, or under a reseller) | مشتری سازمانی | organ (= sub-organization) | |
| `organ` under an `organ` | زیرسازمان | nothing | always pays its parent's tariff; expires with its parent unless set otherwise; blocked while its parent is |

**Quota.** Every parent has `max_subset_limit`. A child uses its own quota + 1 of the parent's.
The used amount is summed from the children, never stored. A parent's quota is checked again under a
row lock when the child is attached, so two concurrent creations cannot both use the last slot.

**Creating a sub-account** (`customer_create_sub_account()`): every rule is checked first. Then
the backend account is created through `POST /api/users/` in the parent's domain. Then ELLSMS grants
panel access, creates the default organization and attaches the child. If the final locked check
loses a race, the new account's panel access is switched off rather than left outside the hierarchy.

**Expiry and suspension.** `expires_on` is the last Tehran-local day an account may log in.
`suspended` is set by the parent or the admin. A blocked account:

- is refused at login,
- is logged out of an existing session,
- sends nothing: direct and API sends, scheduled sends, auto-replies and queued bulk items are
  refused.

A parent's (or admin's) support session can still enter a blocked account to look at it.

## Credit

The parent moves credit to a child, or takes back the child's free credit, with
`wallet_transfer()`. Both ledger entries (`transfer_out` / `transfer_in`, reference
`customer_credit_transfer`) are written in one transaction. Both accounts are locked in id order, and
the transfer never overdraws either side. A transfer carries a per-form operation id, so a double
submit is a replay, not a second transfer.

## Tariff and reseller profit

`tariff_bp` is a coefficient in basis points on the system price list (`app/Sms/Pricing.php`),
10000 = 100%. `sms_pricing_price_messages()` takes the charged account (`$customerUserId`) and
scales every unit price before computing the cost. The preview, the reservation, the snapshot and
the settlement therefore all carry the account's price. Retries keep reusing the accepted snapshot
price.

- The admin sets a reseller's coefficient, e.g. 80% (a discount).
- The reseller sets each child's coefficient, never below its own (`tariff_below_parent`).
- A sub-reseller cannot lower its own tariff below what its children are charged.
- Every change is kept in `ellsms_customer_tariff_history`.

**Profit** (`customer_profit_run()`; the worker runs it hourly, also `make reseller-profit`, or the
button on «سود نمایندگان»):

- Each finished Tehran day is processed once, an hour after it ends.
- For every `sms_debit` of an account that has a parent, the list price is `amount / tariff`.
- Every reseller up the chain earns `(tariff of the account below it on the chain − its own tariff) × list price`.
  Example: a customer at 150% under a sub-reseller at 120% under a reseller at 100%. A 1500 charge
  (list 1000) pays the sub-reseller 300 and the reseller 200.
- Organizations in the chain earn nothing but pass their tariff up.
- Tariffs are taken from the history at the moment of the charge.

The day's rows (`ellsms_reseller_profits`), one wallet credit per reseller (`reseller_profit`,
idempotency key `reseller_profit:<day>:<reseller>`) and the day marker
(`ellsms_reseller_profit_days`) are written in one transaction. A second run never pays twice.

## Sending lines

A parent shares its own lines (assigned to it or to its organization) with a child through the
existing send-only `ellsms_number_shares` (`docs/shared-numbers.md`).

## Parent capabilities (`/subaccounts.php`)

- Create sub-accounts.
- Give and take back credit; see the child's credit ledger.
- Set the child's tariff, expiry and quota.
- Suspend and re-activate the child; reset its password.
- Share lines with the child.
- Set the child's menu access.
- See the child's sending report: today's counts, 14 days of daily sends, recent bulk jobs.
- See the reseller's own daily profit.
- **Enter the child's panel.** This is the existing support impersonation
  (`docs/admin-impersonation.md`), now also open to an ancestor of the target and re-validated on
  every request (`impersonation_resolve_actor()`). It is read-mostly: sending, credentials and the
  other sensitive actions stay blocked, and the session is audited.

All POSTs on this page are refused inside a support session (`subaccounts.manage`).

## Menu access

`menu_catalog()` lists the customer menus (send pages, reports, inbox, contacts, …, API keys,
webhooks, billing, sub-accounts) and the pages under each. Dashboard, notifications, guide,
onboarding and profile are always available. Platform admins are never restricted.

- A **role** (`ellsms_menu_roles`) is a named set of menus. Admin roles (`owner_user_id` NULL) are
  made on «نقش‌های دسترسی منو». A reseller or organization may make its own on `/subaccounts.php`,
  containing only menus it has itself.
- A user gets at most one role plus per-user exceptions (`allow` / `deny`). No role and no
  exceptions = every menu.
- **Effective menus** = own set ∩ parent's ∩ grandparent's … So a sub-account never sees more than
  its parent, and taking a menu away from a reseller takes it from its whole sub-tree.
- A parent's `allow` exception for a menu it does not have is dropped on save.
- **Enforcement:**
  - The sidebar leaves closed menus out (`app/views/header.php`).
  - `require_login()` answers 403 for any page under a closed menu (`menu_access_enforce()`).
  - The public API refuses a route when the key's creator has lost its menu (`send` for messages and
    bulk jobs, `contacts`, `webhooks`) or has lost «کلیدهای API» itself.
