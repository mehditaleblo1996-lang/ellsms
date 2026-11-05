<?php
/**
 * ELLSMS — AI provider credit balance and low-balance alerts (docs/ai-engines.md §Provider balance
 * and alerts). Schema: db/migrations/2026_11_09_ai_provider_balance.sql.
 *
 * AI providers sell prepaid credit, not tokens, so what is watched is money: how much is left on the
 * provider account and how many days that lasts at the recent spend.
 *
 *   - OpenRouter reports it: GET /key (the key's limit) and GET /credits (the account), read by
 *     ai_provider_credit(). Source 'api'.
 *   - Every other provider (GapGPT, OpenAI, Anthropic, self-hosted) does not, with a normal key. The
 *     admin records top-ups («شارژ کردم») or the exact balance the provider's panel shows («موجودی
 *     واقعی»), and the balance is estimated from that minus the purchase cost of every answer and
 *     test since (ellsms_ai_jobs.cost_estimate, ellsms_ai_test_runs tokens x the model's prices).
 *     Source 'ledger'.
 *   - Whatever the estimate says, a provider that refuses a call for lack of credit (HTTP 402,
 *     insufficient_quota, "credit balance is too low", see ai_error_is_out_of_credit()) raises an
 *     emergency alert at once, through ai_http_send() -> ai_balance_on_out_of_credit().
 *
 * Levels (ai_balance_level(), pure): emergency = the provider said it is out; critical = 2 days or
 * less, or 1 USD or less; warning = 7 days or less (all per-provider settings). Alerts go through
 * AlertManager (Telegram + email, dedup, repeat until acknowledged, a recovery notice once fine) with
 * the key ai_balance:<provider code>. The scheduler job `ai-balance` (cron/ai-balance-check.php)
 * re-checks every provider every 15 minutes.
 */

declare(strict_types=1);

/** Defaults of a new balance row; critical_amount applies to USD providers only. */
const AI_BALANCE_DEFAULTS = ['warn_days' => 7.0, 'critical_days' => 2.0, 'critical_amount_usd' => 1.0];

/** How many days of spend the burn rate averages. */
const AI_BALANCE_BURN_DAYS = 7;

const AI_BALANCE_LEVEL_LABELS = [
    'unknown'   => 'نامشخص',
    'ok'        => 'کافی',
    'warning'   => 'رو به اتمام',
    'critical'  => 'بحرانی',
    'emergency' => 'تمام شده',
];

/** False until 2026_11_09_ai_provider_balance.sql is applied. */
function ai_balance_available(?bool $set = null): bool {
    static $available = null;
    if ($set !== null) {
        return $available = $set;
    }
    if ($available === null) {
        try {
            db()->query('SELECT 1 FROM ellsms_ai_provider_balance LIMIT 0');
            db()->query('SELECT 1 FROM ellsms_ai_provider_topups LIMIT 0');
            $available = true;
        } catch (PDOException) {
            $available = false;
        }
    }
    return $available;
}

/* ==========================================================================
   Pure rules
   ========================================================================== */

/** Pure: days the balance lasts at $dailyBurn, or null when nothing is being spent. */
function ai_balance_days_left(?float $balance, ?float $dailyBurn): ?float {
    if ($balance === null || $dailyBurn === null || $dailyBurn <= 0) return null;
    return max(0.0, $balance / $dailyBurn);
}

/**
 * Pure: the alert level of a balance.
 * @param array{warn_days:?float, critical_days:?float, warn_amount:?float, critical_amount:?float} $t
 */
function ai_balance_level(?float $balance, ?float $daysLeft, array $t, bool $exhausted): string {
    if ($exhausted) return 'emergency';
    if ($balance === null) return 'unknown';
    $at = static fn(?float $value, $limit): bool => $value !== null && $limit !== null && $value <= (float)$limit;
    if ($balance <= 0 || $at($balance, $t['critical_amount'] ?? null) || $at($daysLeft, $t['critical_days'] ?? null)) return 'critical';
    if ($at($balance, $t['warn_amount'] ?? null) || $at($daysLeft, $t['warn_days'] ?? null)) return 'warning';
    return 'ok';
}

/**
 * Pure: the ledger estimate. $entries are the provider's top-up rows in its currency, oldest first
 * ({kind, amount}); the estimate starts from the last 'set' (or 0 before the first entry) and adds
 * every later 'topup'. Returns null when nothing was ever recorded. The spend since that starting
 * point is subtracted by the caller.
 */
function ai_balance_ledger_base(array $entries): ?float {
    if ($entries === []) return null;
    $base = 0.0;
    foreach ($entries as $entry) {
        $base = $entry['kind'] === 'set' ? (float)$entry['amount'] : $base + (float)$entry['amount'];
    }
    return $base;
}

/** Pure: an amount for the panel and the alert: $12.35 for USD, 1,250,000 IRR otherwise. */
function ai_balance_format(?float $amount, string $currency): string {
    if ($amount === null) return '—';
    if (strtoupper($currency) === 'USD') {
        $abs = abs($amount);
        return ($amount < 0 ? '-' : '') . '$' . number_format($abs, $abs < 10 ? 4 : 2, '.', ',');
    }
    return number_format($amount, abs($amount) < 100 ? 2 : 0, '.', ',') . ' ' . strtoupper($currency);
}

/** Pure: days left for a person: «حدود ۳٫۵ روز», «کمتر از یک ساعت». */
function ai_balance_days_text(?float $days): string {
    if ($days === null) return 'نامشخص (مصرفی در ۷ روز اخیر نبوده)';
    if ($days < 1 / 24) return 'کمتر از یک ساعت';
    if ($days < 1) return 'حدود ' . to_persian_digits((string)max(1, (int)round($days * 24))) . ' ساعت';
    return 'حدود ' . to_persian_digits(rtrim(rtrim(number_format($days, 1, '.', ''), '0'), '.')) . ' روز';
}

/* ==========================================================================
   Rows
   ========================================================================== */

/** @return array<int,array> balance rows keyed by provider id. */
function ai_balance_rows(): array {
    $rows = [];
    foreach (db()->query('SELECT * FROM ellsms_ai_provider_balance') as $row) {
        $rows[(int)$row['provider_id']] = $row;
    }
    return $rows;
}

/** The provider's balance row, created with the defaults when missing. */
function ai_balance_row(array $provider): array {
    $id = (int)$provider['id'];
    $st = db()->prepare('SELECT * FROM ellsms_ai_provider_balance WHERE provider_id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if ($row) return $row;
    // GapGPT and other Iranian gateways usually bill in Rial; anything else in USD. Editable.
    $currency = $provider['type'] === 'gapgpt' ? 'IRR' : 'USD';
    db()->prepare('INSERT IGNORE INTO ellsms_ai_provider_balance (provider_id, currency, warn_days, critical_days, critical_amount) VALUES (?,?,?,?,?)')
        ->execute([$id, $currency, AI_BALANCE_DEFAULTS['warn_days'], AI_BALANCE_DEFAULTS['critical_days'],
                   $currency === 'USD' ? AI_BALANCE_DEFAULTS['critical_amount_usd'] : null]);
    $st->execute([$id]);
    return $st->fetch();
}

/** @return list<array> the provider's recorded top-ups, newest first. */
function ai_balance_topups(int $providerId, int $limit = 20): array {
    $st = db()->prepare('SELECT * FROM ellsms_ai_provider_topups WHERE provider_id = ? ORDER BY id DESC LIMIT ' . max(1, min(200, $limit)));
    $st->execute([$providerId]);
    return $st->fetchAll();
}

/** Pure-ish parsing of an optional non-negative decimal form field: null for blank, false when invalid. */
function ai_balance_parse_amount(string $value): float|null|false {
    $value = str_replace([',', '٬', '،', ' '], '', trim(from_persian_digits($value)));
    if ($value === '') return null;
    if (preg_match('/^\d{1,14}(\.\d{1,6})?$/', $value) !== 1) return false;
    return (float)$value;
}

/** Saves the per-provider thresholds and currency. */
function ai_balance_settings_save(int $providerId, array $input, int $actorId): void {
    $provider = ai_provider_find($providerId) ?? throw new AppException('ارائه‌دهنده پیدا نشد.');
    ai_balance_row($provider);
    $currency = strtoupper(trim((string)($input['currency'] ?? 'USD')));
    if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) throw new AppException('ارز باید یک کد سه‌حرفی باشد (USD، IRR و ...).');
    $values = [];
    foreach (['warn_days', 'critical_days', 'warn_amount', 'critical_amount'] as $field) {
        $v = ai_balance_parse_amount((string)($input[$field] ?? ''));
        if ($v === false) throw new AppException('آستانه‌ها باید عدد نامنفی باشند (خالی یعنی بررسی نشود).');
        $values[$field] = $v;
    }
    if ($values['warn_days'] !== null && $values['warn_days'] > 3650) throw new AppException('آستانه‌ی روز بیش از حد بزرگ است.');
    db()->prepare('UPDATE ellsms_ai_provider_balance SET currency = ?, warn_days = ?, critical_days = ?, warn_amount = ?, critical_amount = ?,
                          alerts_enabled = ?, updated_by = ? WHERE provider_id = ?')
        ->execute([$currency, $values['warn_days'], $values['critical_days'], $values['warn_amount'], $values['critical_amount'],
                   !empty($input['alerts_enabled']) ? 1 : 0, $actorId, $providerId]);
    audit($actorId, 'ai.balance.settings', json_encode(['provider_id' => $providerId, 'currency' => $currency] + $values
        + ['alerts_enabled' => !empty($input['alerts_enabled'])], JSON_UNESCAPED_UNICODE) ?: '');
}

/**
 * Records a top-up ('topup': add $amount) or the exact current balance ('set'), then re-checks the
 * provider so the panel and any open alert reflect it at once. A recorded top-up clears a "provider
 * said it is out of credit" flag.
 */
function ai_balance_record(int $providerId, string $kind, string $amount, string $note, int $actorId): array {
    $provider = ai_provider_find($providerId) ?? throw new AppException('ارائه‌دهنده پیدا نشد.');
    if (!in_array($kind, ['topup', 'set'], true)) throw new AppException('نوع ثبت نامعتبر است.');
    $value = ai_balance_parse_amount($amount);
    if ($value === null || $value === false || ($kind === 'topup' && $value <= 0)) {
        throw new AppException($kind === 'topup' ? 'مبلغ شارژ باید عددی بزرگ‌تر از صفر باشد.' : 'موجودی باید عددی نامنفی باشد.');
    }
    $row = ai_balance_row($provider);
    db()->prepare('INSERT INTO ellsms_ai_provider_topups (provider_id, kind, amount, currency, note, created_by) VALUES (?,?,?,?,?,?)')
        ->execute([$providerId, $kind, $value, $row['currency'], mb_substr(trim($note), 0, 255) ?: null, $actorId]);
    if ($kind === 'topup' || $value > 0) {
        db()->prepare('UPDATE ellsms_ai_provider_balance SET exhausted_at = NULL, exhausted_message = NULL WHERE provider_id = ?')->execute([$providerId]);
    }
    audit($actorId, 'ai.balance.' . $kind, json_encode(['provider_id' => $providerId, 'amount' => $value, 'currency' => $row['currency']]) ?: '');
    return ai_balance_check_provider(ai_provider_find($providerId));
}

/* ==========================================================================
   Spend
   ========================================================================== */

/**
 * Purchase cost of the provider's answers and tests in $currency since $since (a timestamp read
 * from the database, so it compares in the same time zone), or over the last $days days.
 */
function ai_balance_spend(int $providerId, string $currency, ?string $since, ?int $days = null): float {
    $window = $since !== null ? '>= ?' : '>= NOW() - INTERVAL ' . max(1, (int)$days) . ' DAY';
    $param = $since !== null ? [$since] : [];
    $total = 0.0;
    if (ai_secretary_available()) {
        // Secretary, Telegram and «پیام اول» jobs: cost_estimate is in the model's purchase currency
        // (OpenRouter's real cost when it reported one).
        $st = db()->prepare("SELECT COALESCE(SUM(j.cost_estimate), 0) FROM ellsms_ai_jobs j JOIN ellsms_ai_models m ON m.id = j.model_id
                             WHERE m.provider_id = ? AND m.cost_currency = ? AND j.cost_estimate IS NOT NULL AND j.completed_at {$window}");
        $st->execute(array_merge([$providerId, $currency], $param));
        $total += (float)$st->fetchColumn();
    }
    // Model tests, the playground and «آزمایش منشی»: tokens x the model's per-million prices.
    $st = db()->prepare("SELECT COALESCE(SUM(COALESCE(r.input_tokens, 0) * COALESCE(m.input_cost_per_million, 0)
                                          + COALESCE(r.output_tokens, 0) * COALESCE(m.output_cost_per_million, 0)), 0) / 1000000
                         FROM ellsms_ai_test_runs r JOIN ellsms_ai_models m ON m.id = r.model_id
                         WHERE r.provider_id = ? AND r.ok = 1 AND r.kind IN ('model','playground') AND m.cost_currency = ? AND r.created_at {$window}");
    $st->execute(array_merge([$providerId, $currency], $param));
    return $total + (float)$st->fetchColumn();
}

/**
 * The ledger estimate: [balance, as-of] or [null, null] when nothing was recorded.
 * @return array{0:?float, 1:?string}
 */
function ai_balance_ledger(int $providerId, string $currency): array {
    $st = db()->prepare('SELECT id, kind, amount, created_at FROM ellsms_ai_provider_topups WHERE provider_id = ? AND currency = ? ORDER BY id');
    $st->execute([$providerId, $currency]);
    $entries = $st->fetchAll();
    if ($entries === []) return [null, null];
    // Spend counts from the last 'set' (the balance was exact then), else from the first top-up.
    $from = $entries[0]['created_at'];
    foreach ($entries as $entry) {
        if ($entry['kind'] === 'set') $from = $entry['created_at'];
    }
    $base = ai_balance_ledger_base($entries);
    return [$base - ai_balance_spend($providerId, $currency, (string)$from), (string)$from];
}

/** Whether the provider answered successfully after $since (it was evidently topped up). */
function ai_balance_succeeded_since(int $providerId, string $since): bool {
    $st = db()->prepare("SELECT 1 FROM ellsms_ai_test_runs WHERE provider_id = ? AND ok = 1 AND kind IN ('model','playground') AND created_at > ? LIMIT 1");
    $st->execute([$providerId, $since]);
    if ($st->fetchColumn()) return true;
    if (!ai_secretary_available()) return false;
    $st = db()->prepare("SELECT 1 FROM ellsms_ai_jobs j JOIN ellsms_ai_models m ON m.id = j.model_id
                         WHERE m.provider_id = ? AND j.reply_text IS NOT NULL AND j.is_fallback = 0 AND j.completed_at > ? LIMIT 1");
    $st->execute([$providerId, $since]);
    return (bool)$st->fetchColumn();
}

/* ==========================================================================
   Check and alert
   ========================================================================== */

/**
 * Re-reads one provider's balance, stores it and raises / resolves its alert.
 * @return array the stored balance row
 */
function ai_balance_check_provider(array $provider): array {
    $id = (int)$provider['id'];
    $row = ai_balance_row($provider);
    $currency = (string)$row['currency'];
    $balance = null;
    $source = null;
    $error = null;

    if ($provider['type'] === 'openrouter' && $provider['status'] === 'active') {
        try {
            $balance = ai_provider_credit(ai_provider_config($provider));
            if ($balance !== null) {
                $source = 'api';
                if ($currency !== 'USD') {
                    $currency = 'USD'; // OpenRouter always reports USD
                    db()->prepare("UPDATE ellsms_ai_provider_balance SET currency = 'USD' WHERE provider_id = ?")->execute([$id]);
                }
            } else {
                $error = 'OpenRouter موجودی را گزارش نکرد؛ تخمین از روی شارژهای ثبت‌شده است.';
            }
        } catch (AiProviderException $e) {
            $error = $e->getMessage();
        }
    }
    if ($balance === null) {
        [$balance] = ai_balance_ledger($id, $currency);
        $source = $balance !== null ? 'ledger' : null;
    }

    $burn = ai_balance_spend($id, $currency, null, AI_BALANCE_BURN_DAYS) / AI_BALANCE_BURN_DAYS;
    $days = ai_balance_days_left($balance, $burn);

    // "Out of credit" from the provider stays until it is evidently over.
    $exhaustedAt = $row['exhausted_at'];
    if ($exhaustedAt !== null && (($source === 'api' && $balance > 0) || ai_balance_succeeded_since($id, (string)$exhaustedAt))) {
        $exhaustedAt = null;
        db()->prepare('UPDATE ellsms_ai_provider_balance SET exhausted_at = NULL, exhausted_message = NULL WHERE provider_id = ?')->execute([$id]);
    }

    $thresholds = [
        'warn_days' => $row['warn_days'] !== null ? (float)$row['warn_days'] : null,
        'critical_days' => $row['critical_days'] !== null ? (float)$row['critical_days'] : null,
        'warn_amount' => $row['warn_amount'] !== null ? (float)$row['warn_amount'] : null,
        'critical_amount' => $row['critical_amount'] !== null ? (float)$row['critical_amount'] : null,
    ];
    $level = ai_balance_level($balance, $days, $thresholds, $exhaustedAt !== null);

    db()->prepare('UPDATE ellsms_ai_provider_balance SET balance = ?, balance_source = ?, balance_at = ' . ($balance !== null ? 'NOW()' : 'NULL') . ',
                          daily_burn = ?, days_left = ?, level = ?, last_checked_at = NOW(), last_check_error = ? WHERE provider_id = ?')
        ->execute([$balance, $source, $burn, $days !== null ? min($days, 99999999.0) : null, $level,
                   $error !== null ? mb_substr($error, 0, 500) : null, $id]);

    ai_balance_alert($provider, $level, $balance, $currency, $days, $burn, $source, $row['exhausted_message'] ?? null);
    Logger::info('ai.balance.checked', ['provider_id' => $id, 'level' => $level, 'source' => $source]);

    $st = db()->prepare('SELECT * FROM ellsms_ai_provider_balance WHERE provider_id = ?');
    $st->execute([$id]);
    return $st->fetch();
}

/** Raises or resolves the provider's balance alert (key ai_balance:<code>). */
function ai_balance_alert(array $provider, string $level, ?float $balance, string $currency, ?float $days, float $burn,
                          ?string $source, ?string $providerMessage): void {
    $key = 'ai_balance:' . $provider['code'];
    $st = db()->prepare('SELECT alerts_enabled FROM ellsms_ai_provider_balance WHERE provider_id = ?');
    $st->execute([(int)$provider['id']]);
    $enabled = (bool)$st->fetchColumn() && $provider['status'] === 'active';
    try {
        if (!$enabled || in_array($level, ['ok', 'unknown'], true)) {
            AlertManager::recover($key, 'موجودی ارائه‌دهنده‌ی هوش مصنوعی «' . $provider['name'] . '» دوباره کافی است'
                . ($balance !== null ? ': ' . ai_balance_format($balance, $currency) : '') . '.');
            return;
        }
        $severity = match ($level) {
            'emergency' => AlertManager::SEVERITY_EMERGENCY,
            'critical'  => AlertManager::SEVERITY_CRITICAL,
            default     => AlertManager::SEVERITY_WARNING,
        };
        $title = match ($level) {
            'emergency' => 'اعتبار هوش مصنوعی «' . $provider['name'] . '» تمام شد',
            'critical'  => 'اعتبار هوش مصنوعی «' . $provider['name'] . '» در آستانه‌ی اتمام است',
            default     => 'اعتبار هوش مصنوعی «' . $provider['name'] . '» رو به اتمام است',
        };
        $lines = [];
        if ($level === 'emergency') {
            $lines[] = 'ارائه‌دهنده درخواست‌ها را به دلیل نبود اعتبار رد می‌کند؛ منشی‌ها و «پیام اول» با این ارائه‌دهنده جواب نمی‌دهند.';
            if ($providerMessage) $lines[] = 'پیام ارائه‌دهنده: ' . $providerMessage;
        }
        if ($balance !== null) {
            $lines[] = 'موجودی' . ($source === 'ledger' ? ' (تخمینی)' : '') . ': ' . ai_balance_format($balance, $currency);
        }
        if ($burn > 0) {
            $lines[] = 'مصرف روزانه (میانگین ۷ روز): ' . ai_balance_format($burn, $currency) . ' — کافی برای ' . ai_balance_days_text($days);
        }
        $lines[] = 'حساب ارائه‌دهنده را شارژ کنید و شارژ را در «موتورهای هوش مصنوعی ← ' . $provider['name'] . ' ← موجودی و هشدار» ثبت کنید.';
        AlertManager::fire($key, $severity, $title, implode("\n", $lines), ['provider_id' => (int)$provider['id'], 'level' => $level]);
    } catch (Throwable $t) {
        // The alert tables are missing or a channel is broken: the balance itself is still stored.
        Logger::warning('ai.balance.alert_failed', ['provider_id' => (int)$provider['id'], 'error' => $t->getMessage()]);
    }
}

/**
 * Called by ai_http_send() when a provider refuses a call for lack of credit: marks the provider
 * exhausted and raises the emergency alert now. Cheap to call repeatedly (the alert is deduplicated).
 */
function ai_balance_on_out_of_credit(int $providerId, string $providerMessage): void {
    if (!ai_balance_available()) return;
    $provider = ai_provider_find($providerId);
    if ($provider === null) return;
    $row = ai_balance_row($provider);
    db()->prepare('UPDATE ellsms_ai_provider_balance SET exhausted_at = COALESCE(exhausted_at, NOW()), exhausted_message = ?, level = ? WHERE provider_id = ?')
        ->execute([$providerMessage !== '' ? mb_substr($providerMessage, 0, 500) : null, 'emergency', $providerId]);
    Logger::warning('ai.balance.out_of_credit', ['provider_id' => $providerId]);
    ai_balance_alert($provider, 'emergency', $row['balance'] !== null ? (float)$row['balance'] : null, (string)$row['currency'],
        $row['days_left'] !== null ? (float)$row['days_left'] : null, (float)($row['daily_burn'] ?? 0), $row['balance_source'], $providerMessage);
}

/**
 * The scheduler pass: every provider (a disabled one only resolves its alert).
 * @return list<array{provider:string, level:string, balance:?string}>
 */
function ai_balance_check_all(): array {
    $out = [];
    foreach (ai_providers_all() as $provider) {
        try {
            $row = ai_balance_check_provider($provider);
            $out[] = ['provider' => (string)$provider['code'], 'level' => (string)$row['level'],
                      'balance' => $row['balance'] !== null ? ai_balance_format((float)$row['balance'], (string)$row['currency']) : null];
        } catch (Throwable $t) {
            Logger::error('ai.balance.check_failed', ['provider_id' => (int)$provider['id'], 'exception' => $t]);
            $out[] = ['provider' => (string)$provider['code'], 'level' => 'error', 'balance' => null];
        }
    }
    return $out;
}
