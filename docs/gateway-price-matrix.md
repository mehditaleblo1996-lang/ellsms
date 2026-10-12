# SMS price matrix: gateway × operator × encoding

The price of one SMS segment depends on three things:

- **the sending gateway** (عامل ارسال): the gateway pinned to the sending number, else the gateway of
  the route chosen for that recipient, else the default gateway;
- **the destination column**: each active operator, **سایر** (an Iranian number whose operator is
  unknown) or **بین‌الملل** (a number outside Iran, i.e. not starting with 98);
- **the encoding**: فارسی (any non-ASCII character, UCS-2) or لاتین (GSM-7), the same rule
  `sms_parts()` uses.

Code: `app/GatewayPrices.php`; table: `ellsms_gateway_prices` (migration
`2026_10_14_gateway_price_matrix.sql`); form: `app/views/price_matrix.php`.

## Where it is set

- **Global matrix:** تعرفه‌ی پیامک → تعرفه‌ی درگاه‌ها. A list price; each account's tariff
  percentage applies to it.
- **Per account:** Users → edit → تعرفه‌ی اختصاصی. The same table; a filled cell is that account's
  exact price (no tariff percentage); an empty cell shows the global value greyed out and falls back
  to it.

In both tables the top row of each column has an input and a ↓ button that writes that value into
every gateway of the column; an empty value clears the column. Nothing is saved unless every cell is
a valid positive Rial amount (up to three decimals; `1,124` and Persian digits are accepted).

## How a message is priced

`sms_pricing_price_messages()` works out the cell (gateway, column, encoding) for each message and
takes, in order:

1. the account's own cell → exact price, `price_source = 'customer_price'`;
2. the global cell → price × tariff percentage, `price_source = 'gateway_price'`;
3. otherwise the route price (تعرفه‌ها) or the fallback, × tariff percentage, as before.

A cell also prices a recipient the route catalog could not price. Platform admins are still not
charged. Snapshots keep the unit price actually charged, so later changes never alter past sends.

## Who can have own prices

Accounts not under a reseller. An account under a reseller pays the reseller's tariff, and reseller
profit is computed from tariffs; such accounts get no form, saving is refused and pricing ignores
any row. A reseller itself may have own prices. A sub-organization pays its parent organization's.

## History

Cells are effective-dated: saving closes the changed cells' current period and opens a new one at
the same instant (UTC); unchanged cells are untouched. Pricing reads the period in effect at the
pricing instant, so an import costed at analysis time and a retried send keep their price. Every
save is audited (`gateway_prices.saved`).

The per-operator account prices of the previous version (`ellsms_customer_operator_prices`) were
copied into the matrix by the migration: each price on every active gateway, both encodings, the
"every other operator" price into both سایر and بین‌الملل.
