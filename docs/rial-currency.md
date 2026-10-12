# Rial-denominated wallet

## Why

ELLSMS shares its database with the Negar backend. Negar reads `user_.currentcredit` and its prices
as **Rial**. ELLSMS used to keep its wallet in its own "credit" unit, worth `rial_per_credit` Rial
(1000 by default), and still wrote that number into `user_.currentcredit`. The two systems therefore
disagreed by a factor of `rial_per_credit` about every balance.

Now **one wallet unit is one Rial**, in both systems. The calculation code did not change: the wallet
was already an integer, prices were already integer thousandths of a unit. Only the meaning of the
unit and the stored data changed.

| What | Before | Now |
|---|---|---|
| Wallet balance, reservations, `user_.currentcredit` | credits | Rial |
| SMS price per segment (`price_per_segment_millicredits`) | thousandths of a credit | thousandths of a Rial (1200 Rial = `1200000`) |
| Price/snapshot `currency` column | `credit` | `IRR` |
| Legacy fallback price (no tariff found) | 1 credit | `sms_default_price_rial`, default **1200 Rial**, editable on the SMS-tariff page |
| Buying credit | N credits × `rial_per_credit` | the Rial amount entered (before VAT) is added to the wallet as-is |
| `rial_per_credit` setting | Rial per credit | removed; the API still returns `rial_per_credit: 1` |
| `GET /balance` `unit`, preview `currency` | `credits` / `credit` | `IRR` |
| Reseller tariffs, transfers, reseller profit | credits | Rial (they work on the same numbers) |

Column and function names that still say `credit`/`millicredits` were kept to avoid renaming stored
columns; read them as Rial.

## Upgrading an existing install

1. Deploy the code and apply migrations (`make db-migrations-apply`). The migration
   `2026_10_12_rial_currency.sql` only widens a few amount columns to `BIGINT`, sets the currency
   columns' default to `IRR` and seeds `sms_default_price_rial = 1200`. It changes no amount.
2. Preview the data conversion:

   ```bash
   make rial-conversion-dry-run              # FACTOR=1200 by default
   ```

   It lists every wallet's balance before and after, how many prices, snapshots, payments and bulk
   rows will change, and how each amount-valued setting will change.
3. Turn maintenance mode on, convert, turn it off:

   ```bash
   make maintenance-on
   make rial-conversion
   make maintenance-off
   make wallet-audit && make sms-pricing-integrity-check
   ```

Do steps 1 and 3 back to back. In between, purchases and the default (fallback) price are already
Rial while balances are still in credits.

`FACTOR` is the Rial value of one old credit. It is 1200 by default. Use the real value if it was
different (`make rial-conversion FACTOR=1000`). The dry run shows the current `rial_per_credit`
setting and flags a mismatch.

### What the conversion changes

Everything below is multiplied by `FACTOR` exactly once:

- wallet `available_balance` and `reserved_balance`, and `user_.currentcredit` with them
- active wallet reservations, and the frozen per-item costs of bulk rows (`ellsms_bulk_items`)
- route prices and price snapshots still marked `credit` (they become `IRR`)
- import-job estimates, regional-bulk charges, Bale message costs
- `ellsms_payments.credits`; a pending payment confirmed later credits Rial
- landing-page package amounts and organizations' low-credit thresholds
- the settings `min_credit_purchase`, `credit_packages`, `bale_price_credits` and
  `regional_bulk_credits_per_price_unit`, when set

`rial_per_credit` is deleted and its old value kept as `rial_conversion.old_rial_per_credit`.

### Wallets opened by `make wallet-backfill`

The backfill copied each user's `user_.currentcredit` (Negar's Rial) into the wallet as its opening
balance without converting it. That part of a balance is already Rial and is kept as is. Only what
happened afterwards (purchases, sends, transfers, all in credits) is multiplied:

    new balance = FACTOR × balance − (FACTOR − 1) × opening balance

If a user spent their Rial opening balance as if it were credits, the result is negative; it is set
to zero and the dry run marks the wallet `CLAMPED` so it can be looked at. To multiply opening
balances like everything else instead, pass `OPENING=credit`.

The ledger is not rewritten. Each wallet gets one `currency_conversion` entry from the old to the new
balance, so the ledger still sums to the balance.

### Safety

- The real run refuses to start unless maintenance mode is on (web and workers paused, so no send
  is between reserving and committing).
- Large tables are converted in chunks; an interrupted run resumes when re-run with the same
  `FACTOR` (a different factor is refused).
- When it finishes it sets `wallet_currency = IRR`; every later run does nothing.
- History is not re-priced: wallet transactions before the conversion stay in credits, and
  `ellsms_reseller_profits` rows already paid stay as paid. Send charges made earlier on the day of
  the conversion are counted by that night's reseller-profit run at their credit value.

## Fresh installs

There are no balances to convert, but the seeds are in the old unit too: the legacy route price is
`1000 credit` and `min_credit_purchase`/`credit_packages` are `100` and `500,1000,5000,20000`. Run
`make maintenance-on && make rial-conversion && make maintenance-off` once after applying the
migrations; it turns them into 1200 Rial per segment, a 120,000 Rial minimum purchase and
600,000–24,000,000 Rial packages. `make sms-pricing-integrity-check` reports any price
still in `credit`.
