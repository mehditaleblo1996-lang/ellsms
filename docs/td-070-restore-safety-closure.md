# TD-070 — subscription restore safety (CLOSED)

`ellsms_subscriptions.effective_organization_id` was a **STORED GENERATED** column. Any database
holding at least one subscription row therefore produced a `make backup` artifact that `make restore`
could not load. This document records the defect, the fix, and the proof.

Migration: `db/migrations/2026_08_10_td070_subscription_restore_safety.sql`
Derivation: `billing_effective_organization_id()` in `app/Billing.php`
Audit: `cron/subscription-integrity-check.php`

---

## 1. The defect

Phase 13 made "at most one EFFECTIVE subscription per organization" a database guarantee — the right
call, and one this change preserves — by deriving a column and putting a UNIQUE index on it:

```sql
effective_organization_id INT UNSIGNED GENERATED ALWAYS AS (
  CASE WHEN status IN ('trialing','active','past_due','grace') THEN organization_id ELSE NULL END
) STORED,
UNIQUE KEY uniq_effective_subscription (effective_organization_id)
```

The rule is sound. The **storage mechanism** was not, for this project's toolchain: the
`mariadb-client` mysqldump shipped in `docker/Dockerfile` emits generated columns as ordinary column
data. Restoring such a dump fails:

```
ERROR 3105 (HY000): The value specified for generated column 'effective_organization_id'
in table 'ellsms_subscriptions' is not allowed.
```

Two properties made this hide for months:

- **It needs rows.** A generated column with no data produces no INSERT, so nothing to reject.
  `ellsms_subscriptions` is empty until an operator runs `make billing-backfill`, and Phase 11's
  disaster-recovery test never seeded one.
- **The backup itself succeeds.** `cron/backup.php` verifies mysqldump's exit code, the completion
  trailer and a checksum — all of which pass. The artifact is well-formed; it is simply not
  loadable. The failure only appears during recovery, which is the worst possible moment to find it.

It was found while building the SMS pricing tables, which used the same technique and, unlike
subscriptions, had seeded rows on day one — so `RestoreDisasterRecoveryTest` failed immediately
there. The pricing tables were changed at once; the pre-existing subscriptions table was recorded as
TD-070 and is what this change closes.

## 2. The fix

`effective_organization_id` is now an **ordinary nullable column**, same name, same type, same UNIQUE
index, maintained by the application:

```php
function billing_effective_organization_id(int $organizationId, string $status): ?int
```

Every write to `ellsms_subscriptions` that changes `status` passes its NEW status through that one
function and stores the result — `subscription_create()`, `subscription_transition()`,
`subscription_change_plan()` (immediate), `payment_claim_and_activate_subscription()`, and the
lifecycle scheduler's cancel-at-period-end and rollover paths. Paths that do **not** change status
(a scheduled downgrade writing `pending_plan_id`, `cancel_at_period_end = 1`) deliberately leave the
column alone.

The helper **fails closed** on a status outside `BILLING_ALL_STATUSES`: it throws, aborting the
surrounding transaction. A refused transition is strictly better than a row whose uniqueness slot
silently stops matching its status.

### The migration

1. **Preflight** — refuses to run if any organization already has more than one effective
   subscription. No winner is guessed: which subscription an organization is on is a business
   question, not a data-repair one. The failure surfaces as
   `Table 'TD070_ABORTED_an_organization_has_more_than_one_effective_subscription_...' doesn't exist`,
   because MySQL does not allow `SIGNAL` inside a prepared statement and this file must stay plain,
   splittable SQL like every other migration here.
2. **Convert** — `ALTER TABLE ... MODIFY COLUMN effective_organization_id INT UNSIGNED NULL`. MySQL
   retains every stored value when the `GENERATED ALWAYS` clause is dropped, so this is metadata
   only: no row is rewritten, no id changes, no timestamp moves, and **the unique index is never
   dropped** — there is no window in which the guarantee is absent.
3. **Ensure the index** exists (a no-op on the normal path; a repair for a database whose index was
   dropped by hand).
4. **Backfill/normalize** — matches zero rows immediately after step 2. It exists for a database
   that was already converted and has since drifted. `updated_at = updated_at` is deliberate: the
   column carries `ON UPDATE CURRENT_TIMESTAMP`, and restamping a historical subscription would
   rewrite state this migration has no business touching.
5. **Post-validate** — every row must equal its derived value and the column must no longer be
   generated, or the migration aborts without being recorded as applied.

Every step is guarded on the schema's actual state, so re-running the file changes nothing.

## 3. Why not a trigger

A `BEFORE INSERT`/`BEFORE UPDATE` trigger would keep the derivation inside the database and would
additionally reject a raw INSERT that *omits* the column. It was built and tested, and rejected on
evidence:

- **Creating one requires SUPER while binary logging is enabled.** As the ordinary application
  database user: `ERROR 1419 (HY000): You do not have the SUPER privilege and binary logging is
  enabled`. The migration would simply fail on a normal production MySQL.
- **mysqldump needs the TRIGGER privilege to dump one.** A restricted backup user would start
  producing backups missing the trigger — reintroducing exactly the class of defect being fixed,
  and silently.

(The round-trip itself does work — a dumped trigger restores cleanly, and mysqldump emits triggers
*after* the table's data so they do not fire during a restore. The blocker is privilege, not
mechanism.)

## 4. Residual difference, stated plainly

| Write | Generated column (before) | Ordinary column (now) |
|---|---|---|
| Application code | correct | correct |
| Raw SQL supplying the column | **rejected** by the unique index | **rejected** by the unique index |
| Raw SQL **omitting** the column | derived, then rejected | accepted, stored as `NULL` |

The last row is the only behavioural difference, and it is not a way to smuggle in a second
subscription: a row with `effective_organization_id = NULL` is, by the schema's own definition, not
effective. `subscription_for_organization()` does not return it, entitlements do not see it, and
`cron/subscription-integrity-check.php` reports it as CRITICAL `effective_slot_missing`. It is
asserted explicitly in `SubscriptionEffectiveColumnTest` rather than left implied.

## 5. Integrity checking

`cron/subscription-integrity-check.php` re-derives every row from `BILLING_EFFECTIVE_STATUSES` in
PHP — never by repeating the status list in SQL, so the check cannot drift from what the application
writes — and reports as CRITICAL:

| Finding | Meaning |
|---|---|
| `effective_slot_missing` | an effective row with `NULL` — invisible to every lookup, unprotected by the index |
| `effective_slot_stale` | an ended row still holding the slot — blocking a new subscription |
| `effective_slot_wrong_organization` | the slot points at a different organization |
| `unknown_subscription_status` | a status outside `BILLING_ALL_STATUSES`; the derivation is undefined |
| `overlapping_subscriptions` | two effective rows for one organization |
| `effective_column_is_generated` | TD-070 has regressed |
| `effective_unique_index_missing` | the actual guarantee is gone |

Nothing is auto-repaired; the tool exits non-zero on any critical finding.

## 6. Proof

| Claim | Where |
|---|---|
| The column is not generated | `SubscriptionEffectiveColumnTest::testTheColumnIsNoLongerAGeneratedColumn` |
| The UNIQUE index still exists and is unique | same class |
| Raw SQL cannot insert a second effective row | `testRawSqlCannotCreateASecondEffectiveSubscriptionForOneOrganization` |
| Raw SQL cannot promote a second row into the slot | `testRawSqlCannotPromoteASecondRowIntoTheEffectiveSlotEither` |
| A raw insert omitting the slot is inert and detectable | `testARawInsertThatOmitsTheSlotProducesANonEffectiveRowAndTheIntegrityCheckFlagsIt` |
| Every permitted lifecycle transition keeps the slot correct | `testEveryPermittedTransitionLeavesTheSlotConsistent` (6 chains) + trial/upgrade/downgrade/cancel cases |
| Two processes creating concurrently produce exactly one | `SubscriptionEffectiveSlotConcurrencyTest` |
| Two processes cancelling concurrently release it exactly once | same class |
| A **legacy** database upgrades with no data loss and becomes restorable | `SubscriptionLegacySchemaUpgradeTest::testALegacyDatabaseWithRealSubscriptionsUpgradesAndBecomesRestorable` |
| The migration refuses ambiguous data instead of guessing | `testTheMigrationRefusesToRunOnAmbiguousData` |
| The migration is rerun-safe | `testTheMigrationIsRerunSafeOnAnAlreadyConvertedDatabase` |
| Migrations apply from zero in deterministic order, then backup/restore | `testEveryMigrationAppliesFromZeroInDeterministicOrder` |
| Real backup → DROP DATABASE → restore with real subscriptions | `RestoreDisasterRecoveryTest::testFullDisasterRecoveryRestoreCycle` |

The legacy-upgrade test is the decisive one: same seed, same backup/restore stack, restore **fails**
before the migration and **succeeds** after it, with every subscription column compared whole
(`assertSame` on the entire row) either side.

## 7. Operator notes

Nothing to enable and no configuration to change. Apply the migration the usual way:

```bash
make backup && make backup-status
make db-integrity-check
make db-migrations-apply
make subscription-integrity-check     # expect zero CRITICAL findings
```

If the migration aborts with a `TD070_ABORTED_...` table-not-found error, the database has two
simultaneously-effective subscriptions for one organization. That is ambiguous by definition —
decide which one is real, end the other through the normal lifecycle, and re-run. The migration will
not choose for you.

After applying, an install carrying subscription rows produces restorable backups for the first
time. Verifying that on a copy is worthwhile:

```bash
make backup
make restore-test BACKUP=<backup_id>   # real disposable-MySQL restore, never production
```
