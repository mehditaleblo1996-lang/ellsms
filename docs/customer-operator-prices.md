# Per-customer price per operator

A platform admin can give one account its own Rial price per SMS segment for each operator
(Users → edit → **تعرفه‌ی اختصاصی**). Code: `app/CustomerOperatorPrices.php`; table:
`ellsms_customer_operator_prices` (migration `2026_10_13_customer_operator_prices.sql`).

## How a recipient is priced

For each recipient the engine (`sms_pricing_price_messages()`) resolves the operator and route as
before, then:

1. If the account has a price for that operator, that is the price, **exactly**: neither the route
   price nor the account's tariff percentage applies.
2. Else, if the account has an **"other operators"** price, that is the price. It also covers
   numbers whose operator is unknown.
3. Else the price is as before: the route price × the account's tariff percentage.

An account price is a complete answer on its own, so a recipient the catalog could not price (no
route price and the fallback off) becomes priceable for that account. The message still travels its
normal route; only the price changes. Platform admins are still not charged.

Snapshots record `price_source = 'customer_price'` (shown as «تعرفه‌ی اختصاصی مشتری» on the message
detail page) and the unit price actually charged, so later changes never alter past sends.

## Who can have one

- Any account **not** under a reseller. An account under a reseller pays the tariff the reseller set,
  and reseller profit is computed from tariffs (`customer_profit_split()`); an exact price there
  would leave that profit undefined. The form is not shown for such accounts, saving is refused,
  and pricing ignores any row that exists anyway (for example after the account was moved under a
  reseller).
- A reseller itself may have one; its customers keep paying their tariff on the route price.
- A sub-organization pays its parent organization's prices, as it pays its tariff.

## Changes over time

Rows are effective-dated like route prices: saving closes the period in effect and opens a new one
at the same instant (UTC); an empty field closes it. Pricing reads the row in effect at the pricing
instant, so an import costed at analysis time is queued at the same price and a retried send reuses
its accepted price. Every change is written to the audit log
(`customer.operator_price_set` / `customer.operator_price_removed`).

One price applies to every message type (promotional, transactional, OTP).
