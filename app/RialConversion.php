<?php
/**
 * ELLSMS — one-time conversion of stored amounts from the old wallet unit ("credit") to Rial
 * (docs/rial-currency.md).
 *
 * Until this change one wallet credit was worth `rial_per_credit` Rial, while the Negar backend reads
 * user_.currentcredit — which the wallet keeps in lockstep with its own balance — as Rial. From now on
 * one wallet unit IS one Rial. Every amount already stored in the old unit is multiplied by a single
 * factor (the Rial value of one old credit, 1200 by default) exactly once:
 *
 *   - wallet balances (available + reserved), and user_.currentcredit with them
 *   - active wallet reservations, and the per-item frozen costs of bulk rows they will be settled with
 *   - route prices, price snapshots, import-job estimates, regional and Bale costs
 *   - payments (a pending payment credits `credits` when it is confirmed later)
 *   - landing-page packages, low-credit thresholds and the amount-valued settings
 *
 * WALLET OPENING BALANCES. `make wallet-backfill` seeded each wallet from user_.currentcredit, which
 * was already Rial, without converting it — that part of a balance is Rial and must not be
 * multiplied. With $openingIsRial (the default) the new balance is
 *     factor × available − (factor − 1) × opening
 * i.e. the opening amount kept as Rial and everything that happened afterwards (purchases, sends,
 * transfers — all in old credits) multiplied. A balance that would go below zero (the opening Rial
 * was spent as if it were credits) is clamped to zero and reported.
 *
 * The wallet ledger is not rewritten: each account gets one 'currency_conversion' entry whose
 * before/after are the old and new balance, so the ledger still sums to the balance.
 *
 * Safety: refuses to apply outside maintenance mode (workers and web are paused, so no reservation
 * is between reserve and commit); big tables are converted in resumable chunks whose progress is
 * stored in ellsms_settings; the final step (wallets, settings, the "done" flag) is one transaction,
 * and once the flag is set every later run is a no-op.
 */

declare(strict_types=1);

/** Default Rial value of one old credit. */
const RIAL_CONVERSION_DEFAULT_FACTOR = 1200;

/** Settings key whose value 'IRR' marks the conversion as finished. */
const RIAL_CONVERSION_DONE_KEY = 'wallet_currency';

function rial_conversion_done(): bool {
    return setting_fresh(RIAL_CONVERSION_DONE_KEY, '') === SMS_PRICING_CURRENCY;
}

/**
 * New available balance for one wallet. Pure; see this file's header for the formula.
 *
 * @return array{balance:int, clamped:bool}
 */
function rial_conversion_wallet_target(int $available, int $openingRial, int $factor, bool $openingIsRial): array {
    $opening = $openingIsRial ? max(0, $openingRial) : 0;
    $target = $factor * $available - ($factor - 1) * $opening;
    return ['balance' => max(0, $target), 'clamped' => $target < 0];
}

/** "500,1000" -> "600000,1200000" for factor 1200. Pure. Empty entries are dropped. */
function rial_conversion_scale_list(string $csv, int $factor): string {
    $out = [];
    foreach (explode(',', $csv) as $part) {
        $part = trim($part);
        if ($part === '' || !ctype_digit($part)) {
            continue;
        }
        $out[] = (string)((int)$part * $factor);
    }
    return implode(',', $out);
}

/** A numeric setting multiplied by the factor, formatted without float noise. Pure. */
function rial_conversion_scale_number(string $value, int $factor): string {
    if (!is_numeric($value)) {
        return $value;
    }
    $scaled = (float)$value * $factor;
    return floor($scaled) == $scaled ? (string)(int)$scaled : rtrim(rtrim(sprintf('%.6F', $scaled), '0'), '.');
}

/** Settings holding amounts in the old unit, and how each is scaled. Only keys that exist are touched. */
function rial_conversion_amount_settings(): array {
    return [
        'min_credit_purchase'                   => 'number',
        'credit_packages'                       => 'list',
        'bale_price_credits'                    => 'number',
        'regional_bulk_credits_per_price_unit'  => 'number',
    ];
}

/** wallet user_id => Rial opening balance recorded by cron/wallet-backfill.php. */
function rial_conversion_opening_balances(PDO $db): array {
    $out = [];
    $rows = $db->query("SELECT user_id, SUM(amount) AS opening FROM ellsms_wallet_transactions
                        WHERE type = 'migration_opening_balance' GROUP BY user_id");
    foreach ($rows as $row) {
        $out[(int)$row['user_id']] = (int)$row['opening'];
    }
    return $out;
}

/** True when the table exists (an install may not have applied every feature's migration). */
function rial_conversion_table_exists(PDO $db, string $table): bool {
    $st = $db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $st->execute([$table]);
    return (int)$st->fetchColumn() > 0;
}

/** True once 2026_10_12_rial_currency.sql has widened the amount columns (checked on ellsms_payments.credits). */
function rial_conversion_schema_ready(PDO $db): bool {
    $st = $db->prepare("SELECT DATA_TYPE FROM information_schema.columns
                        WHERE table_schema = DATABASE() AND table_name = 'ellsms_payments' AND column_name = 'credits'");
    $st->execute();
    return strtolower((string)$st->fetchColumn()) === 'bigint';
}

/** Read-only report of what rial_conversion_apply() would change. */
function rial_conversion_plan(int $factor, bool $openingIsRial): array {
    $db = db();
    $opening = rial_conversion_opening_balances($db);
    $wallets = [];
    $totals = ['available_before' => 0, 'available_after' => 0, 'reserved_before' => 0, 'reserved_after' => 0];
    $clamped = 0;
    foreach ($db->query('SELECT user_id, available_balance, reserved_balance FROM ellsms_wallet_accounts ORDER BY user_id') as $row) {
        $userId = (int)$row['user_id'];
        $target = rial_conversion_wallet_target((int)$row['available_balance'], $opening[$userId] ?? 0, $factor, $openingIsRial);
        $clamped += $target['clamped'] ? 1 : 0;
        $totals['available_before'] += (int)$row['available_balance'];
        $totals['available_after']  += $target['balance'];
        $totals['reserved_before']  += (int)$row['reserved_balance'];
        $totals['reserved_after']   += $factor * (int)$row['reserved_balance'];
        $wallets[] = [
            'user_id'   => $userId,
            'available' => (int)$row['available_balance'],
            'opening'   => $opening[$userId] ?? null,
            'new'       => $target['balance'],
            'clamped'   => $target['clamped'],
        ];
    }

    $count = static function (string $sql) use ($db): int {
        return (int)$db->query($sql)->fetchColumn();
    };
    $settings = [];
    foreach (rial_conversion_amount_settings() as $key => $kind) {
        $value = setting_fresh($key);
        if ($value !== null) {
            $settings[$key] = [$value, $kind === 'list' ? rial_conversion_scale_list($value, $factor) : rial_conversion_scale_number($value, $factor)];
        }
    }

    return [
        'done'                  => rial_conversion_done(),
        'factor'                => $factor,
        'opening_is_rial'       => $openingIsRial,
        'maintenance'           => maintenance_mode_active(),
        'schema_ready'          => rial_conversion_schema_ready($db),
        'rial_per_credit'       => setting_fresh('rial_per_credit'),
        'wallets'               => $wallets,
        'wallet_totals'         => $totals,
        'wallets_with_opening'  => count(array_intersect_key($opening, array_flip(array_column($wallets, 'user_id')))),
        'wallets_clamped'       => $clamped,
        'active_reservations'   => $count("SELECT COUNT(*) FROM ellsms_wallet_reservations WHERE status = 'active'"),
        'route_prices'          => $count("SELECT COUNT(*) FROM ellsms_sms_route_prices WHERE currency = 'credit'"),
        'price_snapshots'       => $count("SELECT COUNT(*) FROM ellsms_sms_price_snapshots WHERE currency = 'credit'"),
        'bulk_items_priced'     => $count('SELECT COUNT(*) FROM ellsms_bulk_items WHERE price_cost_credits IS NOT NULL OR unit_price_millicredits IS NOT NULL'),
        'payments'              => $count('SELECT COUNT(*) FROM ellsms_payments'),
        'payments_open'         => $count("SELECT COUNT(*) FROM ellsms_payments WHERE status IN ('pending','verification_failed')"),
        'settings'              => $settings,
        'today_sms_debits'      => $count("SELECT COUNT(*) FROM ellsms_wallet_transactions WHERE type = 'sms_debit' AND created_at >= CURDATE()"),
    ];
}

/**
 * Multiplies $columns of $table by $factor in id-ordered chunks, resuming after the id stored under
 * a progress key. Each chunk and its progress mark commit together, so a crash never converts a row
 * twice. Rows above the id that was the maximum when the run began are left alone: the conversion
 * runs in maintenance mode, so none should appear, and any that did were written in the new unit.
 */
function rial_conversion_scale_by_id(PDO $db, string $table, array $columns, int $factor, int $chunk, ?callable $progress = null): int {
    if (!rial_conversion_table_exists($db, $table)) {
        return 0;
    }
    $key = 'rial_conversion.progress.' . $table;
    $maxKey = 'rial_conversion.max_id.' . $table;
    $maxId = setting_fresh($maxKey);
    if ($maxId === null) {
        $maxId = (string)(int)$db->query("SELECT COALESCE(MAX(id), 0) FROM {$table}")->fetchColumn();
        set_setting($maxKey, $maxId);
    }
    $maxId = (int)$maxId;
    $done = (int)(setting_fresh($key, '0') ?? '0');
    $set = implode(', ', array_map(static fn(string $c): string => "{$c} = {$c} * {$factor}", $columns));
    $notNull = implode(' OR ', array_map(static fn(string $c): string => "{$c} IS NOT NULL", $columns));
    $updated = 0;
    while ($done < $maxId) {
        $to = min($maxId, $done + $chunk);
        $updated += db_transaction(function (PDO $db) use ($table, $set, $notNull, $done, $to, $key): int {
            $st = $db->prepare("UPDATE {$table} SET {$set} WHERE id > ? AND id <= ? AND ({$notNull})");
            $st->execute([$done, $to]);
            $db->prepare('INSERT INTO ellsms_settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)')
               ->execute([$key, (string)$to]);
            return $st->rowCount();
        });
        $done = $to;
        if ($progress) {
            $progress($table, $done, $maxId);
        }
    }
    return $updated;
}

/**
 * Converts rows still marked currency='credit', in chunks. The marker flips with the amounts in the
 * same statement, so re-running converts only what is left.
 */
function rial_conversion_scale_by_currency(PDO $db, string $table, array $columns, int $factor, int $chunk): int {
    if (!rial_conversion_table_exists($db, $table)) {
        return 0;
    }
    $set = implode(', ', array_map(static fn(string $c): string => "{$c} = {$c} * {$factor}", $columns));
    $updated = 0;
    do {
        $n = $db->exec("UPDATE {$table} SET {$set}, currency = '" . SMS_PRICING_CURRENCY . "' WHERE currency = '"
            . SMS_PRICING_LEGACY_CURRENCY . "' LIMIT " . max(1, $chunk));
        $updated += (int)$n;
    } while ($n > 0);
    return $updated;
}

/**
 * Applies the conversion. Returns a summary; throws on refusal (not in maintenance mode, bad factor).
 * Calling it again after it finished returns ['already_done' => true] and changes nothing.
 */
function rial_conversion_apply(int $factor, bool $openingIsRial, int $chunk = 20000, ?callable $progress = null): array {
    if ($factor < 1) {
        throw new InvalidArgumentException('factor must be a positive integer');
    }
    if (rial_conversion_done()) {
        return ['already_done' => true];
    }
    if (!maintenance_mode_active()) {
        throw new RuntimeException('Turn maintenance mode on first (make maintenance-on): web and workers must be paused while amounts are converted.');
    }
    if (!rial_conversion_schema_ready(db())) {
        throw new RuntimeException('Apply db/migrations/2026_10_12_rial_currency.sql first (make db-migrations-apply): it widens the amount columns the conversion multiplies.');
    }
    // A run that was interrupted must resume with the factor it started with, or the chunks it already
    // converted and the ones still to come would disagree.
    $started = setting_fresh('rial_conversion.factor');
    if ($started !== null && (int)$started !== $factor) {
        throw new RuntimeException("An earlier run started with factor {$started}; resume with --factor={$started}.");
    }
    set_setting('rial_conversion.factor', (string)$factor);

    $db = db();
    $summary = ['already_done' => false, 'factor' => $factor];

    // 1. Large or history tables, resumable.
    $summary['bulk_items']       = rial_conversion_scale_by_id($db, 'ellsms_bulk_items', ['unit_price_millicredits', 'price_cost_credits'], $factor, $chunk, $progress);
    $summary['channel_messages'] = rial_conversion_scale_by_id($db, 'ellsms_channel_messages', ['cost_credits'], $factor, $chunk, $progress);
    $summary['price_snapshots']  = rial_conversion_scale_by_currency($db, 'ellsms_sms_price_snapshots', ['unit_price_millicredits', 'total_cost_credits', 'committed_cost_credits'], $factor, $chunk);
    $summary['import_jobs']      = rial_conversion_scale_by_currency($db, 'ellsms_import_jobs', ['estimated_cost_credits'], $factor, $chunk);

    // 2. Everything that must change together, then the done flag.
    $final = db_transaction(function (PDO $db) use ($factor, $openingIsRial): array {
        $out = ['wallets' => 0, 'wallets_clamped' => 0];
        $opening = rial_conversion_opening_balances($db);
        $accounts = $db->query('SELECT user_id, available_balance, reserved_balance FROM ellsms_wallet_accounts ORDER BY user_id FOR UPDATE')->fetchAll();
        $ledger = $db->prepare('INSERT INTO ellsms_wallet_transactions
            (user_id, type, amount, balance_before, balance_after, reference_type, reference_id, idempotency_key, metadata)
            VALUES (?,?,?,?,?,?,?,?,?)');
        $update = $db->prepare('UPDATE ellsms_wallet_accounts SET available_balance = ?, reserved_balance = ? WHERE user_id = ?');
        foreach ($accounts as $row) {
            $userId = (int)$row['user_id'];
            $before = (int)$row['available_balance'];
            $target = rial_conversion_wallet_target($before, $opening[$userId] ?? 0, $factor, $openingIsRial);
            $reserved = $factor * (int)$row['reserved_balance'];
            $ledger->execute([$userId, 'currency_conversion', $target['balance'] - $before, $before, $target['balance'],
                'rial_conversion', (string)$userId, 'rial_conversion:' . $userId, json_encode([
                    'factor' => $factor, 'opening_rial' => $openingIsRial ? ($opening[$userId] ?? 0) : 0,
                    'reserved_before' => (int)$row['reserved_balance'], 'reserved_after' => $reserved, 'clamped' => $target['clamped'],
                ])]);
            $update->execute([$target['balance'], $reserved, $userId]);
            wallet_sync_legacy_currentcredit($db, $userId);
            $out['wallets']++;
            $out['wallets_clamped'] += $target['clamped'] ? 1 : 0;
        }

        $scale = static function (string $sql) use ($db): int {
            return (int)$db->exec($sql);
        };
        $out['reservations'] = $scale("UPDATE ellsms_wallet_reservations SET amount = amount * {$factor}, remaining_amount = remaining_amount * {$factor} WHERE status = 'active'");
        $out['route_prices'] = $scale("UPDATE ellsms_sms_route_prices SET price_per_segment_millicredits = price_per_segment_millicredits * {$factor}, currency = '" . SMS_PRICING_CURRENCY . "' WHERE currency = '" . SMS_PRICING_LEGACY_CURRENCY . "'");
        $out['payments'] = $scale("UPDATE ellsms_payments SET credits = credits * {$factor}");
        $out['pricing_packages'] = rial_conversion_table_exists($db, 'ellsms_pricing_packages')
            ? $scale("UPDATE ellsms_pricing_packages SET credit_amount = credit_amount * {$factor}") : 0;
        $out['low_credit_thresholds'] = rial_conversion_table_exists($db, 'ellsms_organization_notification_preferences')
            ? $scale("UPDATE ellsms_organization_notification_preferences SET low_credit_threshold = low_credit_threshold * {$factor}") : 0;
        $out['regional_requests'] = rial_conversion_table_exists($db, 'ellsms_regional_bulk_requests')
            ? $scale("UPDATE ellsms_regional_bulk_requests SET charged_credits = charged_credits * {$factor}, settled_credits = settled_credits * {$factor}") : 0;

        $setSetting = $db->prepare('INSERT INTO ellsms_settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)');
        $out['settings'] = [];
        foreach (rial_conversion_amount_settings() as $key => $kind) {
            $value = setting_fresh($key);
            if ($value === null) {
                continue;
            }
            $new = $kind === 'list' ? rial_conversion_scale_list($value, $factor) : rial_conversion_scale_number($value, $factor);
            $setSetting->execute([$key, $new]);
            $out['settings'][$key] = [$value, $new];
        }
        // Nothing reads it any more; keep the old value next to the factor for the record.
        $old = setting_fresh('rial_per_credit');
        if ($old !== null) {
            $setSetting->execute(['rial_conversion.old_rial_per_credit', $old]);
            $db->prepare("DELETE FROM ellsms_settings WHERE skey = 'rial_per_credit'")->execute();
        }
        $setSetting->execute(['rial_conversion.opening_is_rial', $openingIsRial ? '1' : '0']);
        $setSetting->execute(['rial_conversion.finished_at', gmdate('Y-m-d H:i:s')]);
        $setSetting->execute([RIAL_CONVERSION_DONE_KEY, SMS_PRICING_CURRENCY]);
        return $out;
    });

    sms_pricing_cache_reset();
    Logger::info('rial_conversion.finished', ['factor' => $factor, 'opening_is_rial' => $openingIsRial, 'wallets' => $final['wallets'], 'clamped' => $final['wallets_clamped']]);
    return $summary + $final;
}
