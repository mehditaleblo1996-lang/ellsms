<?php
/**
 * ELLSMS — AI pricing and charging (تعرفه‌ی هوش مصنوعی), phase 3 (docs/ai-billing.md).
 *
 * WHAT IS SOLD. One AI answer. A price is three numbers: the price of one answer, the tokens one
 * answer includes (input + output together), and the price of every 1,000 tokens beyond that.
 * Prices are millicredits (thousandths of a Rial), like the SMS price matrix.
 *
 * WHO PAYS WHAT — the SMS chain, unchanged (app/ResellerTariffs.php customer_chain_prices()):
 *   - the platform list price: the global row of the model, else the global «همه‌ی مدل‌ها» row;
 *   - an account without a parent: its own exact price (scope 'account'), else the list price times
 *     its tariff percentage (customer_effective_tariff_bp());
 *   - an account under a reseller: priced down the chain — the top account as above, every account
 *     below by its reseller's named tariff (scope 'tariff'), else list x its percentage, and never
 *     below what the reseller directly above it pays for the same answer.
 * The answer price and the overage price are each run through the chain (as two «encodings» of a
 * per-model «column»), so a reseller can price either or both.
 *
 * WHEN. A secretary answer is charged once its SMS has gone out (app/Ai/AiSecretary.php): a send
 * that fails never costs the AI part. The balance is checked BEFORE the model is asked (an account
 * that cannot pay one answer is not answered), so a debit that still fails afterwards is rare; it is
 * recorded as 'unpaid'. A fallback text costs no AI. A customer's «آزمایش منشی» is charged like an
 * answer (it is a real model call); a platform admin's tests are free.
 *
 * Every charge is one wallet debit of type 'ai_debit' (reference 'ai_usage' / 'job:<id>' or
 * 'test:<token>') and one ellsms_ai_charges row with the chain's prices, from which the daily
 * reseller-profit run (customer_profit_run_day()) pays each reseller its margin.
 *
 * Schema: db/migrations/2026_11_05_ai_pricing.sql.
 */

declare(strict_types=1);

/** Platform settings: key => [default, min, max]. */
const AI_BILLING_SETTINGS = [
    'ai_billing_enabled'         => ['1', 0, 1],          // charge the AI part at all
    'ai_default_included_tokens' => ['2000', 0, 1000000], // tokens per answer when no price row says
    'ai_usd_rial_rate'           => ['0', 0, 100000000],  // Rial per USD, only for the margin columns
];

/** The two priced parts of an answer: encoding key => label. */
const AI_PRICE_PARTS = ['answer' => 'هر پاسخ', 'overage' => 'هر ۱۰۰۰ توکن اضافه'];

/** Rough characters per token, for a provider that returns no usage. Conservative (charges more tokens). */
const AI_CHARS_PER_TOKEN_ESTIMATE = 3;

function ai_billing_available(?bool $set = null): bool {
    static $available = null;
    if ($set !== null) {
        return $available = $set;
    }
    if ($available === null) {
        $available = false;
        if (ai_engines_available()) {
            try {
                db()->query('SELECT 1 FROM ellsms_ai_prices LIMIT 0');
                db()->query('SELECT 1 FROM ellsms_ai_charges LIMIT 0');
                $available = true;
            } catch (PDOException) {
                $available = false;
            }
        }
    }
    return $available;
}

function ai_billing_setting(string $key): int {
    [$default, $min, $max] = AI_BILLING_SETTINGS[$key];
    return max($min, min($max, (int)(setting_fresh($key, $default) ?? $default)));
}

function ai_billing_enabled(): bool {
    return ai_billing_available() && ai_billing_setting('ai_billing_enabled') === 1;
}

function ai_billing_settings_save(array $input, int $actorId): void {
    foreach (AI_BILLING_SETTINGS as $key => [$default, $min, $max]) {
        $value = $key === 'ai_billing_enabled' ? (empty($input[$key]) ? 0 : 1) : (int)from_persian_digits((string)($input[$key] ?? $default));
        set_setting($key, (string)max($min, min($max, $value)));
    }
    audit($actorId, 'ai.billing.settings', json_encode(array_intersect_key($input, AI_BILLING_SETTINGS)) ?: '');
}

/* ==========================================================================
   Price rows
   ========================================================================== */

/**
 * The price rows of one scope/owner: model id (0 = every model) => [answer, overage, included].
 * @return array<int, array{answer:?int, overage:?int, included:?int}>
 */
function ai_prices_for(string $scope, int $ownerId): array {
    $cache = &$GLOBALS['__ai_prices'];
    $key = $scope . ':' . $ownerId;
    if (isset($cache[$key])) return $cache[$key];
    $out = [];
    if (ai_billing_available()) {
        $st = db()->prepare('SELECT model_id, answer_price_millis, overage_per_1k_millis, included_tokens FROM ellsms_ai_prices WHERE scope = ? AND owner_id = ?');
        $st->execute([$scope, $ownerId]);
        foreach ($st as $row) {
            $out[(int)$row['model_id']] = [
                'answer'   => $row['answer_price_millis'] !== null ? (int)$row['answer_price_millis'] : null,
                'overage'  => $row['overage_per_1k_millis'] !== null ? (int)$row['overage_per_1k_millis'] : null,
                'included' => $row['included_tokens'] !== null ? (int)$row['included_tokens'] : null,
            ];
        }
    }
    return $cache[$key] = $out;
}

function ai_prices_cache_reset(): void {
    $GLOBALS['__ai_prices'] = [];
}

/** Pure: a Rial amount typed in a form ('1,250.5', '۱۲۵۰') -> millicredits; null for empty, false if invalid. */
function ai_price_from_rial(string $value): int|null|false {
    $value = trim(str_replace([',', '٬', ' '], '', from_persian_digits($value)));
    if ($value === '') return null;
    if (preg_match('/^\d{1,9}(\.\d{1,3})?$/', $value) !== 1) return false;
    return (int)round((float)$value * SMS_PRICE_SCALE);
}

/** Pure: millicredits -> a Rial string for a form ('1250', '12.5'), '' for null. */
function ai_price_to_rial(?int $millis): string {
    if ($millis === null) return '';
    return rtrim(rtrim(number_format($millis / SMS_PRICE_SCALE, 3, '.', ''), '0'), '.');
}

/**
 * Pure: the posted price form (price[model][answer|overage|included]) -> rows, or an error message.
 * @return array{0: array<int, array{answer:?int, overage:?int, included:?int}>, 1: ?string}
 */
function ai_prices_from_form(array $posted, bool $withIncluded): array {
    $rows = [];
    foreach ($posted as $modelId => $fields) {
        if (!is_array($fields) || !ctype_digit((string)$modelId)) continue;
        $row = ['answer' => null, 'overage' => null, 'included' => null];
        foreach (['answer', 'overage'] as $part) {
            $v = ai_price_from_rial((string)($fields[$part] ?? ''));
            if ($v === false) return [[], 'قیمت‌ها باید عدد نامنفی به ریال باشند (حداکثر سه رقم اعشار).'];
            $row[$part] = $v;
        }
        if ($withIncluded) {
            $inc = trim(from_persian_digits((string)($fields['included'] ?? '')));
            if ($inc !== '' && (!ctype_digit($inc) || (int)$inc > 1000000)) return [[], 'توکن رایگان هر پاسخ باید عددی بین ۰ و ۱٬۰۰۰٬۰۰۰ باشد.'];
            $row['included'] = $inc === '' ? null : (int)$inc;
        }
        $rows[(int)$modelId] = $row;
    }
    return [$rows, null];
}

/** Replaces every price row of one scope/owner (rows that are all-empty are removed). */
function ai_prices_save(string $scope, int $ownerId, array $rows, int $actorId): void {
    db_transaction(function (PDO $db) use ($scope, $ownerId, $rows, $actorId): void {
        $db->prepare('DELETE FROM ellsms_ai_prices WHERE scope = ? AND owner_id = ?')->execute([$scope, $ownerId]);
        $ins = $db->prepare('INSERT INTO ellsms_ai_prices (scope, owner_id, model_id, answer_price_millis, overage_per_1k_millis, included_tokens, updated_by)
                             VALUES (?,?,?,?,?,?,?)');
        foreach ($rows as $modelId => $r) {
            if ($r['answer'] === null && $r['overage'] === null && ($r['included'] ?? null) === null) continue;
            $ins->execute([$scope, $ownerId, (int)$modelId, $r['answer'], $r['overage'], $scope === 'global' ? $r['included'] : null, $actorId]);
        }
    });
    ai_prices_cache_reset();
    audit($actorId, 'ai.prices.save', json_encode(['scope' => $scope, 'owner_id' => $ownerId, 'rows' => count($rows)]) ?: '');
}

/* ==========================================================================
   Resolving a price
   ========================================================================== */

/** Pure: a row's part for $modelId, else the «همه‌ی مدل‌ها» row's. */
function ai_price_cell(array $rows, int $modelId, string $part): ?int {
    return $rows[$modelId][$part] ?? $rows[0][$part] ?? null;
}

/** The tokens one answer of $modelId includes. */
function ai_included_tokens(int $modelId): int {
    $global = ai_prices_for('global', 0);
    return $global[$modelId]['included'] ?? $global[0]['included'] ?? ai_billing_setting('ai_default_included_tokens');
}

/** A reseller tariff's AI rows in reseller_tariff_cell_key() form, for customer_chain_prices(). */
function ai_tariff_cells(int $tariffId): array {
    $cells = [];
    foreach (ai_prices_for('tariff', $tariffId) as $modelId => $row) {
        $column = $modelId === 0 ? RESELLER_TARIFF_ALL : 'm' . $modelId;
        foreach (['answer', 'overage'] as $part) {
            if ($row[$part] !== null) $cells[reseller_tariff_cell_key($column, $part)] = $row[$part];
        }
    }
    return $cells;
}

/**
 * What $userId pays for an answer by $modelId.
 *
 * @return array{answer:int, overage:int, included:int,
 *               chain: list<array{user_id:int, type:string, answer:?int, overage:?int}>|null, source:string}
 *   chain is null for an account without a parent; else spender first, one entry per account.
 */
function ai_price_resolve(int $userId, int $modelId): array {
    $global = ai_prices_for('global', 0);
    $listAnswer = ai_price_cell($global, $modelId, 'answer');
    $listOverage = ai_price_cell($global, $modelId, 'overage');
    $included = ai_included_tokens($modelId);

    $chain = customer_pricing_chain($userId);
    if ($chain === null) {
        // Its own exact prices — a sub-organization's are its parent organization's.
        $owner = customer_price_owner($userId);
        $own = $owner !== null ? ai_prices_for('account', $owner) : [];
        $bp = customer_effective_tariff_bp($userId);
        $answer = ai_price_cell($own, $modelId, 'answer');
        $overage = ai_price_cell($own, $modelId, 'overage');
        return [
            'answer'   => $answer ?? ($listAnswer !== null ? customer_tariff_apply($listAnswer, $bp) : 0),
            'overage'  => $overage ?? ($listOverage !== null ? customer_tariff_apply($listOverage, $bp) : 0),
            'included' => $included,
            'chain'    => null,
            'source'   => $answer !== null || $overage !== null ? 'customer_price' : 'list',
        ];
    }

    $top = $chain[count($chain) - 1];
    $topRows = customer_price_owner((int)$top['user_id']) === (int)$top['user_id'] ? ai_prices_for('account', (int)$top['user_id']) : [];
    $tariffCells = [];
    foreach ($chain as $acc) {
        if ($acc['tariff_id'] !== null) $tariffCells[$acc['tariff_id']] ??= ai_tariff_cells((int)$acc['tariff_id']);
    }
    $column = 'm' . $modelId;
    $answers = customer_chain_prices($chain, $listAnswer, ai_price_cell($topRows, $modelId, 'answer'), $tariffCells, $column, 'answer');
    $overages = customer_chain_prices($chain, $listOverage, ai_price_cell($topRows, $modelId, 'overage'), $tariffCells, $column, 'overage');
    $out = [];
    foreach ($answers as $i => $a) {
        $out[] = ['user_id' => $a['user_id'], 'type' => $a['type'], 'answer' => $a['price'], 'overage' => $overages[$i]['price']];
    }
    return [
        'answer'   => (int)($out[0]['answer'] ?? 0),
        'overage'  => (int)($out[0]['overage'] ?? 0),
        'included' => $included,
        'chain'    => $out,
        'source'   => $answers[0]['source'],
    ];
}

/** Pure: millicredits for one answer of $tokens tokens at these prices. */
function ai_charge_millis(int $answer, int $overagePer1k, int $included, int $tokens): int {
    $extra = max(0, $tokens - max(0, $included));
    return max(0, $answer) + intdiv($extra * max(0, $overagePer1k) + 999, 1000);
}

/** Pure: the Rial debited for $millis (rounded up to a whole Rial, like an SMS charge). */
function ai_charge_credits(int $millis): int {
    return intdiv(max(0, $millis) + SMS_PRICE_SCALE - 1, SMS_PRICE_SCALE);
}

/**
 * Pure: the tokens an answer is billed for. Uses the provider's usage; when it reported none, an
 * estimate from the characters sent and received (rounded up), flagged as estimated.
 * @return array{input:int, output:int, estimated:bool}
 */
function ai_billable_tokens(?int $inputTokens, ?int $outputTokens, int $promptChars, int $replyChars): array {
    $estimated = false;
    if ($inputTokens === null) {
        $inputTokens = intdiv($promptChars + AI_CHARS_PER_TOKEN_ESTIMATE - 1, AI_CHARS_PER_TOKEN_ESTIMATE);
        $estimated = true;
    }
    if ($outputTokens === null) {
        $outputTokens = intdiv($replyChars + AI_CHARS_PER_TOKEN_ESTIMATE - 1, AI_CHARS_PER_TOKEN_ESTIMATE);
        $estimated = true;
    }
    return ['input' => max(0, $inputTokens), 'output' => max(0, $outputTokens), 'estimated' => $estimated];
}

/**
 * Pure: chain_prices for the profit run — each account's total for this one answer, spender first:
 * [[user_id, type, millicredits], ...]. Null without a chain.
 */
function ai_charge_chain_prices(?array $chain, int $included, int $tokens): ?array {
    if ($chain === null) return null;
    $out = [];
    foreach ($chain as $acc) {
        $out[] = [(int)$acc['user_id'], (string)$acc['type'],
            $acc['answer'] === null && $acc['overage'] === null ? null : ai_charge_millis((int)$acc['answer'], (int)$acc['overage'], $included, $tokens)];
    }
    return $out;
}

/**
 * Whether $userId can pay for one answer by $modelId right now (the answer price, before overage).
 * True when billing is off or the price is 0.
 */
function ai_can_afford(int $userId, int $modelId): bool {
    if (!ai_billing_enabled()) return true;
    $credits = ai_charge_credits(ai_price_resolve($userId, $modelId)['answer']);
    return $credits === 0 || wallet_balance($userId)['available'] >= $credits;
}

/**
 * Charges one AI answer. Idempotent per $referenceId ('job:<id>' / 'test:<token>'): a second call
 * returns the first charge. Never throws for an empty wallet — the charge is recorded 'unpaid'.
 *
 * @param array $usage {input_tokens:?int, output_tokens:?int, prompt_chars:int, reply_chars:int, cost:?float, estimated?:bool}
 * @return array{status:string, amount:int, amount_millis:int, tokens:int, estimated:bool}
 */
function ai_charge(string $referenceId, int $userId, array $model, array $usage): array {
    if (!ai_billing_available()) {
        return ['status' => 'free', 'amount' => 0, 'amount_millis' => 0, 'tokens' => 0, 'estimated' => false];
    }
    $existing = db()->prepare('SELECT status, amount, amount_millis, input_tokens + output_tokens AS tokens, tokens_estimated FROM ellsms_ai_charges WHERE reference_id = ?');
    $existing->execute([$referenceId]);
    if ($row = $existing->fetch()) {
        return ['status' => (string)$row['status'], 'amount' => (int)$row['amount'], 'amount_millis' => (int)$row['amount_millis'],
                'tokens' => (int)$row['tokens'], 'estimated' => (bool)$row['tokens_estimated']];
    }

    $modelId = (int)$model['id'];
    $tokens = ai_billable_tokens($usage['input_tokens'] ?? null, $usage['output_tokens'] ?? null,
        (int)($usage['prompt_chars'] ?? 0), (int)($usage['reply_chars'] ?? 0));
    if (!empty($usage['estimated'])) {
        $tokens['estimated'] = true; // counts estimated earlier (stored on the job) stay flagged
    }
    $total = $tokens['input'] + $tokens['output'];
    $price = ai_billing_enabled() ? ai_price_resolve($userId, $modelId) : ['answer' => 0, 'overage' => 0, 'included' => 0, 'chain' => null];
    $millis = ai_charge_millis($price['answer'], $price['overage'], $price['included'], $total);
    $credits = ai_charge_credits($millis);
    $status = 'free';
    if ($credits > 0) {
        $debit = wallet_debit($userId, $credits, 'ai_debit', 'ai_usage', $referenceId, 'ai_usage:' . $referenceId, null,
            ['model_id' => $modelId, 'tokens' => $total, 'estimated' => $tokens['estimated']]);
        $status = !empty($debit['ok']) ? 'charged' : 'unpaid';
        if ($status === 'unpaid') {
            Logger::warning('ai.charge.unpaid', ['reference_id' => $referenceId, 'user_id' => $userId, 'amount' => $credits]);
        }
    }
    $chainPrices = ai_charge_chain_prices($price['chain'], $price['included'], $total);
    db()->prepare(
        'INSERT IGNORE INTO ellsms_ai_charges (reference_id, user_id, model_id, input_tokens, output_tokens, tokens_estimated, included_tokens,
                                               answer_price_millis, overage_per_1k_millis, amount_millis, amount, purchase_cost, chain_prices, status)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([$referenceId, $userId, $modelId, $tokens['input'], $tokens['output'], $tokens['estimated'] ? 1 : 0, $price['included'],
                $price['answer'], $price['overage'], $millis, $status === 'charged' ? $credits : 0,
                isset($usage['cost']) ? $usage['cost'] : null,
                $chainPrices !== null ? json_encode($chainPrices) : null, $status]);
    return ['status' => $status, 'amount' => $status === 'charged' ? $credits : 0, 'amount_millis' => $millis,
            'tokens' => $total, 'estimated' => $tokens['estimated']];
}

/**
 * The profit run's price snapshot of one AI charge: [{cost, chain}] like
 * customer_profit_snapshot_groups(), empty when the charge had no chain.
 */
function ai_charge_profit_groups(string $referenceId, int $userId): array {
    if (!ai_billing_available()) return [];
    $st = db()->prepare('SELECT amount, chain_prices FROM ellsms_ai_charges WHERE reference_id = ? AND user_id = ? AND chain_prices IS NOT NULL');
    $st->execute([$referenceId, $userId]);
    $row = $st->fetch();
    if (!$row) return [];
    $chain = json_decode((string)$row['chain_prices'], true);
    return is_array($chain) && count($chain) >= 2 ? [['cost' => (int)$row['amount'], 'chain' => $chain]] : [];
}

/* ==========================================================================
   Reseller tariffs
   ========================================================================== */

/**
 * What a reseller itself pays per model, for the floor of its tariffs:
 * model id => ['answer' => millis, 'overage' => millis]. Model 0 is the MOST the reseller pays for
 * any model: a tariff's «همه‌ی مدل‌ها» row prices every model without a row of its own, so it must
 * cover the dearest one.
 */
function ai_reseller_floor(int $resellerId): array {
    $out = [];
    foreach (ai_models_all(true) as $m) {
        $p = ai_price_resolve($resellerId, (int)$m['id']);
        $out[(int)$m['id']] = ['answer' => $p['answer'], 'overage' => $p['overage']];
    }
    if ($out !== []) {
        $out[0] = ['answer' => max(array_column($out, 'answer')), 'overage' => max(array_column($out, 'overage'))];
    }
    return $out;
}

/**
 * Saves a reseller tariff's AI prices. Each price must be at least what the reseller pays for the
 * same model (for the «همه‌ی مدل‌ها» row: the most it pays for any model, since that row prices all).
 * @return array{ok:bool, reason?:string, model_id?:int, part?:string}
 */
function ai_reseller_tariff_save(int $ownerId, int $tariffId, array $rows, int $actorId): array {
    if (reseller_tariff_owned($ownerId, $tariffId) === null) return ['ok' => false, 'reason' => 'not_found'];
    $floor = ai_reseller_floor($ownerId);
    foreach ($rows as $modelId => $row) {
        foreach (['answer', 'overage'] as $part) {
            if ($row[$part] !== null && isset($floor[$modelId]) && $row[$part] < $floor[$modelId][$part]) {
                return ['ok' => false, 'reason' => 'below_own_price', 'model_id' => (int)$modelId, 'part' => $part];
            }
        }
    }
    ai_prices_save('tariff', $tariffId, $rows, $actorId);
    return ['ok' => true];
}

/* ==========================================================================
   Reports
   ========================================================================== */

/**
 * Per model, between two UTC instants: answers, tokens, revenue (Rial charged) and purchase cost.
 * @return list<array{model_id:int, display_name:?string, answers:int, tokens:int, revenue:int, unpaid:int, purchase_cost:float, currency:?string}>
 */
function ai_usage_by_model(string $fromUtc, string $toUtc, ?int $userId = null): array {
    $sql = "SELECT c.model_id, m.display_name, m.cost_currency, COUNT(*) answers, SUM(c.input_tokens + c.output_tokens) tokens,
                   SUM(c.amount) revenue, SUM(c.status = 'unpaid') unpaid, COALESCE(SUM(c.purchase_cost), 0) purchase_cost
            FROM ellsms_ai_charges c LEFT JOIN ellsms_ai_models m ON m.id = c.model_id
            WHERE c.created_at >= ? AND c.created_at < ?" . ($userId !== null ? ' AND c.user_id = ?' : '') . '
            GROUP BY c.model_id, m.display_name, m.cost_currency ORDER BY revenue DESC';
    $st = db()->prepare($sql);
    $st->execute($userId !== null ? [$fromUtc, $toUtc, $userId] : [$fromUtc, $toUtc]);
    return array_map(static fn(array $r): array => [
        'model_id' => (int)$r['model_id'], 'display_name' => $r['display_name'], 'answers' => (int)$r['answers'],
        'tokens' => (int)$r['tokens'], 'revenue' => (int)$r['revenue'], 'unpaid' => (int)$r['unpaid'],
        'purchase_cost' => (float)$r['purchase_cost'], 'currency' => $r['cost_currency'],
    ], $st->fetchAll());
}

/** Average tokens per answer of each model over the last 30 days: model id => tokens. */
function ai_average_tokens(): array {
    $out = [];
    foreach (db()->query("SELECT model_id, AVG(input_tokens + output_tokens) t FROM ellsms_ai_charges
                          WHERE created_at >= NOW() - INTERVAL 30 DAY AND model_id IS NOT NULL GROUP BY model_id") as $r) {
        $out[(int)$r['model_id']] = (int)round((float)$r['t']);
    }
    return $out;
}
