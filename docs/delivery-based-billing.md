# Delivery-based billing (ارسال بر اساس تحویل)

An admin can switch on delivery-based billing for an individual user. While it is on, if a message
that user sent through the gateway transport gets a final delivery report of **failed**, **rejected**
or **expired**, a configurable percentage of what that message cost is credited back to the user's
wallet.

Code: `app/DeliveryRefunds.php`. Schema: `db/migrations/2026_10_24_delivery_based_billing.sql`.
Sweep: `cron/delivery-refunds.php` (scheduler job `delivery-refunds`). Tests:
`tests/Integration/DeliveryRefundsTest.php`.

## Switching it on

**Users → (user) → ارسال بر اساس تحویل.** The tab has a checkbox and a refund percentage (1–100, default
100). It also shows the user's totals, the periods when the service was on, and the 20 most recent
refunds. Only an admin can change it. The customer sees the setting under **حساب کاربری → اطلاعات حساب**,
and their recent refunds in a card on the same page.

Each change is audited (`user.delivery_billing`) and recorded as a period in
`ellsms_delivery_billing_periods`. Turning the service off, or changing the percentage, closes the open
period; turning it on, or saving a new percentage, opens a new one. Saving the settings already in
force changes nothing.

## What is refunded

| Final delivery state | Refunded? |
|---|---|
| `failed`, `rejected`, `expired` | yes, `refund_percent` of the message's charge, rounded down to whole Rials |
| `delivered` | no |
| no final report (`sent`, `queued`, `unknown`, or polling gave up) | no: without a report there is no proof the message wasn't delivered |
| refused at send time (the item is `failed` and never sent) | already free: a message is only charged once the gateway accepts it |

**Which percentage applies.** The period in force when the message was **sent** decides. That covers
the bulk item's `created_at` (when the job accepted it) or the attempt's `attempted_at`. Delivery
reports can arrive minutes or hours later, so:

- a message sent while the service was off is never refunded, even if the service is on by the time
  its report arrives;
- a message sent while the service was on is still refunded if the service is switched off before its
  report arrives, and it gets that period's percentage.

**What counts as its charge.**

- *Bulk item* (p2p, smart, gradual, regional, aimed, imports): the item's own wallet ledger row
  `commit:bulk_item:{id}`. If there is no such row (an admin's job, or an exempt send), it wasn't
  charged, so there's nothing to refund.
- *Direct, scheduled, API or auto-reply send*: the send commits one ledger row for all of its
  destinations. Right after that commit, `delivery_billing_record_attempt_charges()` writes each
  destination's share onto its `ellsms_message_attempts.charged_credits`, using the same accepted
  per-recipient prices the commit added up. The shares therefore always add up to the ledger row.

**Out of scope.** This does not cover the legacy transport (`SMS_GATEWAY_TRANSPORT=0`), only messages
sent through the gateway transport. Legacy rows live in the backend's `outbound_message`, and nothing links
them to what ELLSMS charged. Messages sent before the migration have no `charged_credits`, so they are
never refunded automatically. Voice messages are not SMS and aren't covered either.

## How a refund happens

1. `gateway_status_record()` is the single place where a row enters a final state, whether the update
   came from polling, a provider callback (`/delivery`) or an SMPP receipt. When that update moves the
   row into `failed`, `rejected` or `expired`, it calls `delivery_refund_on_terminal()`.
2. `delivery_refund_apply()` loads the row, finds the period that covered its send time, and reads its
   charge. In one transaction it then:
   - inserts the `ellsms_delivery_refunds` row. `UNIQUE(source, source_id)` is the claim, so this is
     a no-op if the row was already decided.
   - credits the wallet with `wallet_credit(..., 'delivery_refund', source, id, 'delivery_refund:{source}:{id}')`.
     This is an append-only ledger entry that keeps `user_.currentcredit` in sync.
3. Problems in the refund never fail the status write. Instead, the scheduler job `delivery-refunds`
   (every 5 minutes) sweeps up anything step 1 missed: a crash between the two writes, or a direct
   send whose report arrived before its per-destination charge was written (`charge_pending`). The
   sweep starts from the periods table, so its cost grows with the users who have the service, not
   with message history. It looks back `DELIVERY_REFUND_LOOKBACK_DAYS` (7) days and decides at most
   `DELIVERY_REFUND_SWEEP_BATCH` (500) rows per source per run.

A refund happens at most once per message, even when the status hook and the sweep reach the same row
at the same time. The second writer waits on the unique key and then changes nothing, and the wallet
credit carries its own idempotency key on top of that.

## Operations

```
make delivery-refunds-dry-run   # count the messages a refund pass would decide
make delivery-refunds           # run the pass now (the scheduler already does every 5 min)
```

Logs: `delivery_billing.refunded`, `delivery_billing.refund_failed`, `delivery_billing.sweep_completed`,
`delivery_billing.policy_saved`. Metric: `delivery_refunds{state}`. Ledger type: `delivery_refund`
(labelled «بازگشت هزینه‌ی پیام تحویل‌نشده» in the sub-account ledger).
