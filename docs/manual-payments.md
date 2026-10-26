# Manual payment methods (روش‌های پرداخت دستی)

Next to the online gateway (ZarinPal), a customer can buy credit on **/billing/credit** with:

| Method | What the customer enters |
|---|---|
| کارت به کارت | destination card (picked from the configured cards), document/tracking number, date and time, optional receipt image, description |
| واریز به حساب | destination account, document number, date and time, optional receipt image, description |
| نقدی | document/receipt number, date and time, description |
| پرداخت چک | destination account, document number, date and time, cheque amount, bank, branch code, due date, owner name, national id / company id, owner Sheba, Sayad number, serial, series, cheque image (required), description |

The customer enters the credit amount; VAT is added exactly as for an online purchase and the page
shows the amount to transfer (a cheque must be written for exactly that total).

Code: `app/ManualPayments.php`, `public/buy-credit.php`, the review/settings page body
`app/views/manual_payments_panel.php` used by `public/manual-payments.php` and
`public/reseller-payments.php`, and `public/payment-receipt.php` (serves receipt images from
`storage/payment-receipts/`, outside the web root). Schema:
`db/migrations/2026_10_25_manual_payments.sql`. **Inert until migrated:** only the online gateway
is offered before the migration is applied.

## What a report creates

One `ellsms_payments` row (`gateway` = the method, `status = 'pending'`), its invoice (status
`issued`, shown as **«در انتظار بررسی»**), and one `ellsms_manual_payments` row with what the
customer entered. **Nothing is credited until the payment is reviewed.**

- **Approve** — in one transaction: credit the customer, mark the payment `paid`
  (`ref_id = MANUAL-<METHOD>-<id>`) and the invoice `paid`. A second approval is refused
  (`already_reviewed`), so a double click never credits twice.
- **Reject** (a reason is required and shown to the customer) — the payment becomes `failed`, the
  invoice `cancelled`, and the invoice reads **«ردشده»** with the reason. The same document number
  may then be reported again.

The customer is notified in the panel either way. A manual invoice is never re-paid online from
/billing/invoices, and the invoice's own admin "mark paid" / "disable" refuse it
(`manual_review_required`) — it is decided only by its review.

## Who receives the money and reviews it

- **The platform** (owner 0): a platform admin chooses the accepted methods and adds the cards and
  accounts on **/admin/billing/manual-payments** («روش‌ها، کارت‌ها و حساب‌ها»), and reviews the
  payments on the same page. Approval credits the wallet (`purchase`, like an online purchase).
- **A reseller**: on **/reseller/payments** (permission `payments.manage` for its staff) the reseller
  chooses the methods it accepts and adds its own cards and accounts. For a customer under that
  reseller, a method the reseller accepts shows the reseller's cards/accounts and is reviewed by the
  reseller; any other method falls back to the platform's. The reseller holds the money, so
  approval **moves the credit from the reseller's wallet** to the customer's (`wallet_transfer`),
  exactly like giving credit on the customer's page; it is refused while the reseller's balance is
  short, and nothing changes. A platform admin sees resellers' payments read-only.

A card-to-card method is offered only while it is enabled and has an active card; deposit and
cheque need an active account. Cards are checked with Luhn, Sheba numbers with the IBAN mod-97
check; nothing is looked up at a bank. A deleted card/account is soft-deleted so older payments
still show where they were paid.

Invoices (/billing/invoices), the payment history on /billing/credit and the admin financial report
(/admin/billing/finance) show each payment's method and the review state.
