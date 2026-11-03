<?php
/**
 * ELLSMS — AI engines: providers, their models, and which accounts may use which model
 * (docs/ai-engines.md). Phase 1 of the AI SMS secretary.
 *
 * Built like the SMS gateways: a platform admin connects a provider (public/ai-engines.php), stores
 * its key in the AI vault (app/Ai/AiSecrets.php), presses «تست اتصال» and «تشخیص مدل‌ها», switches on
 * the models to offer, and gives accounts access. The wire protocol of each provider type lives in
 * app/Ai/AiAdapters.php; this file is everything that touches the database.
 *
 * MODEL ACCESS. Resolved per account by ai_access_resolve() — a PURE function over the account's
 * chain (top-level account first, the account itself last):
 *   - an account with a custom set uses it, intersected with its parent's effective set — a
 *     sub-account never gets a model its reseller / organization does not have;
 *   - an account without one inherits its parent's effective set;
 *   - a top-level account without one gets the platform default model when the setting
 *     ai_default_access is '1', otherwise nothing.
 * Only models that are switched on, on a provider that is switched on, are ever effective. The
 * default model is the nearest one in the chain that is still in the effective set, else the
 * platform default when it is, else the first effective model.
 *
 * Schema: db/migrations/2026_11_03_ai_engines.sql. Until it is applied the menu entry is hidden and
 * the page explains what to run.
 */

declare(strict_types=1);

/** Platform settings (ellsms_settings) of this feature: key => default. */
const AI_SETTINGS = [
    'ai_default_model_id' => '0',   // the platform default model (0 = none)
    'ai_default_access'   => '0',   // '1' = accounts with no custom set get the platform default model
];

/** False until 2026_11_03_ai_engines.sql is applied. */
function ai_engines_available(?bool $set = null): bool {
    static $available = null;
    if ($set !== null) {
        return $available = $set;
    }
    if ($available === null) {
        try {
            db()->query('SELECT 1 FROM ellsms_ai_providers LIMIT 0');
            db()->query('SELECT 1 FROM ellsms_ai_model_access LIMIT 0');
            $available = true;
        } catch (PDOException) {
            $available = false;
        }
    }
    return $available;
}

/* ==========================================================================
   Providers
   ========================================================================== */

/** @return list<array> every provider, with model counts. */
function ai_providers_all(): array {
    return db()->query(
        "SELECT p.*,
                (SELECT COUNT(*) FROM ellsms_ai_models m WHERE m.provider_id = p.id) AS model_count,
                (SELECT COUNT(*) FROM ellsms_ai_models m WHERE m.provider_id = p.id AND m.status = 'active') AS active_model_count
         FROM ellsms_ai_providers p ORDER BY p.status = 'active' DESC, p.name"
    )->fetchAll();
}

function ai_provider_find(int $id): ?array {
    $st = db()->prepare('SELECT * FROM ellsms_ai_providers WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

/**
 * Pure: validates the provider form. $existing is the stored row when editing.
 * @return array{0: array, 1: list<string>} [values to store, errors]
 */
function ai_provider_validate(array $input, ?array $existing = null): array {
    $errors = [];
    $type = $existing['type'] ?? (string)($input['type'] ?? '');
    if (!array_key_exists($type, AI_PROVIDER_TYPES)) {
        $errors[] = 'نوع ارائه‌دهنده نامعتبر است.';
        $type = 'openai_compatible';
    }
    $code = $existing['code'] ?? strtolower(trim((string)($input['code'] ?? '')));
    if (preg_match('/^[a-z0-9_]{2,40}$/', $code) !== 1) {
        $errors[] = 'شناسه باید ۲ تا ۴۰ نویسه‌ی لاتین کوچک، عدد یا زیرخط باشد.';
    }
    $name = trim((string)($input['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 120) {
        $errors[] = 'نام ارائه‌دهنده باید ۱ تا ۱۲۰ نویسه باشد.';
    }
    $baseUrl = rtrim(trim((string)($input['base_url'] ?? '')), '/');
    if ($baseUrl === '') {
        $baseUrl = AI_PROVIDER_DEFAULT_BASE_URLS[$type] ?? '';
    }
    $parts = parse_url($baseUrl);
    if ($baseUrl === '' || $parts === false || empty($parts['host'])
        || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
        || isset($parts['user']) || isset($parts['query']) || isset($parts['fragment']) || strlen($baseUrl) > 2048) {
        $errors[] = 'آدرس پایه باید یک آدرس http(s) کامل و بدون query باشد؛ مثلاً http://host.docker.internal:8642/v1';
    }
    $allowPrivate = !empty($input['allow_private_network']) ? 1 : 0;
    if (!$allowPrivate && strtolower((string)($parts['scheme'] ?? '')) === 'http') {
        $errors[] = ai_error_label('https_required');
    }
    $authScheme = array_key_exists((string)($input['auth_scheme'] ?? ''), AI_AUTH_SCHEMES) ? (string)$input['auth_scheme'] : 'bearer';
    $authHeader = trim((string)($input['auth_header'] ?? ''));
    if ($authScheme === 'header') {
        // x-api-key is reserved for extra headers but is a legitimate place for the key itself.
        $reserved = in_array(strtolower($authHeader), AI_RESERVED_HEADERS, true) && strtolower($authHeader) !== 'x-api-key';
        if (preg_match('/^[A-Za-z0-9-]{1,64}$/', $authHeader) !== 1 || $reserved) {
            $errors[] = 'نام هدر احراز هویت نامعتبر است.';
        }
    } else {
        $authHeader = '';
    }
    [$extraHeaders, $headerErrors] = ai_parse_extra_headers((string)($input['extra_headers'] ?? ''));
    $errors = array_merge($errors, $headerErrors);
    $connect = (int)($input['connect_timeout_ms'] ?? 5000);
    $request = (int)($input['request_timeout_ms'] ?? 60000);
    if ($connect < 500 || $connect > 30000) $errors[] = 'مهلت اتصال باید بین ۵۰۰ تا ۳۰۰۰۰ میلی‌ثانیه باشد.';
    if ($request < 1000 || $request > 300000) $errors[] = 'مهلت پاسخ باید بین ۱۰۰۰ تا ۳۰۰۰۰۰ میلی‌ثانیه باشد.';

    return [[
        'code' => $code, 'name' => $name, 'type' => $type, 'base_url' => $baseUrl,
        'status' => ($input['status'] ?? 'active') === 'disabled' ? 'disabled' : 'active',
        'auth_scheme' => $authScheme, 'auth_header' => $authHeader,
        'extra_headers_json' => $extraHeaders === [] ? null : json_encode($extraHeaders, JSON_UNESCAPED_UNICODE),
        'allow_private_network' => $allowPrivate,
        'connect_timeout_ms' => $connect, 'request_timeout_ms' => $request,
    ], $errors];
}

/** Pure: extra headers stored as JSON -> the "Name: value" lines the form shows. */
function ai_extra_headers_text(?string $json): string {
    $headers = $json ? json_decode($json, true) : null;
    if (!is_array($headers)) return '';
    $lines = [];
    foreach ($headers as $name => $value) $lines[] = $name . ': ' . $value;
    return implode("\n", $lines);
}

/** Creates a provider; returns its id. Throws AppException with the validation errors. */
function ai_provider_create(array $input, int $actorId): int {
    [$v, $errors] = ai_provider_validate($input);
    if ($errors !== []) throw new AppException(implode(' ', $errors));
    $st = db()->prepare('SELECT 1 FROM ellsms_ai_providers WHERE code = ?');
    $st->execute([$v['code']]);
    if ($st->fetchColumn()) throw new AppException('این شناسه قبلاً استفاده شده است.');
    db()->prepare(
        'INSERT INTO ellsms_ai_providers (code, name, type, status, base_url, auth_scheme, auth_header, extra_headers_json,
                                          allow_private_network, connect_timeout_ms, request_timeout_ms, created_by, updated_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([$v['code'], $v['name'], $v['type'], $v['status'], $v['base_url'], $v['auth_scheme'], $v['auth_header'],
                $v['extra_headers_json'], $v['allow_private_network'], $v['connect_timeout_ms'], $v['request_timeout_ms'], $actorId, $actorId]);
    $id = (int)db()->lastInsertId();
    audit($actorId, 'ai.provider.create', json_encode(['provider_id' => $id, 'code' => $v['code'], 'type' => $v['type']], JSON_UNESCAPED_UNICODE) ?: '');
    return $id;
}

function ai_provider_update(int $id, array $input, int $actorId): void {
    $existing = ai_provider_find($id) ?? throw new AppException('ارائه‌دهنده پیدا نشد.');
    [$v, $errors] = ai_provider_validate($input, $existing);
    if ($errors !== []) throw new AppException(implode(' ', $errors));
    db()->prepare(
        'UPDATE ellsms_ai_providers SET name = ?, status = ?, base_url = ?, auth_scheme = ?, auth_header = ?, extra_headers_json = ?,
                allow_private_network = ?, connect_timeout_ms = ?, request_timeout_ms = ?, updated_by = ? WHERE id = ?'
    )->execute([$v['name'], $v['status'], $v['base_url'], $v['auth_scheme'], $v['auth_header'], $v['extra_headers_json'],
                $v['allow_private_network'], $v['connect_timeout_ms'], $v['request_timeout_ms'], $actorId, $id]);
    audit($actorId, 'ai.provider.update', json_encode(['provider_id' => $id, 'status' => $v['status'], 'base_url' => $v['base_url'],
        'allow_private_network' => $v['allow_private_network']], JSON_UNESCAPED_UNICODE) ?: '');
}

function ai_provider_delete(int $id, int $actorId): void {
    $existing = ai_provider_find($id) ?? throw new AppException('ارائه‌دهنده پیدا نشد.');
    db()->prepare('DELETE FROM ellsms_ai_providers WHERE id = ?')->execute([$id]);
    audit($actorId, 'ai.provider.delete', json_encode(['provider_id' => $id, 'code' => $existing['code']], JSON_UNESCAPED_UNICODE) ?: '');
}

/**
 * The adapter config of a provider row, with its decrypted key. Never logged, never rendered.
 * @throws AiProviderException when a key is needed and cannot be had
 */
function ai_provider_config(array $provider): array {
    $cfg = [
        'type' => (string)$provider['type'], 'base_url' => (string)$provider['base_url'],
        'auth_scheme' => (string)$provider['auth_scheme'], 'auth_header' => (string)$provider['auth_header'],
        'extra_headers' => json_decode((string)($provider['extra_headers_json'] ?? ''), true) ?: [],
        'allow_private_network' => (bool)$provider['allow_private_network'],
        'connect_timeout_ms' => (int)$provider['connect_timeout_ms'], 'request_timeout_ms' => (int)$provider['request_timeout_ms'],
        'api_key' => null,
    ];
    if ($cfg['auth_scheme'] !== 'none' || $cfg['type'] === 'anthropic') {
        $cfg['api_key'] = ai_secret_get((int)$provider['id'], 'api_key');
        if ($cfg['api_key'] === null && ai_secret_is_set((int)$provider['id'], 'api_key')) {
            throw new AiProviderException('secret_unavailable');
        }
    }
    if (ai_config_missing_key($cfg)) {
        throw new AiProviderException('no_api_key');
    }
    // A proxy / relay outside the country (phase 5): its URL may carry credentials, so it is a secret.
    $cfg['proxy'] = ai_secret_get((int)$provider['id'], 'proxy_url');
    return $cfg;
}

/** Records one test run and, for connection/discovery, the provider's health columns. */
function ai_record_run(int $providerId, ?int $modelId, string $kind, ?int $actorId, bool $ok, ?AiProviderException $error,
                       ?int $latencyMs, ?int $discovered = null, ?int $inTokens = null, ?int $outTokens = null): void {
    $message = $error ? mb_strimwidth($error->getMessage(), 0, 500, '…') : null;
    db()->prepare(
        'INSERT INTO ellsms_ai_test_runs (provider_id, model_id, kind, actor_user_id, ok, error_code, error_message, http_status,
                                          latency_ms, discovered_count, input_tokens, output_tokens)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([$providerId, $modelId, $kind, $actorId, $ok ? 1 : 0, $error?->errorCode, $message, $error?->httpStatus,
                $latencyMs, $discovered, $inTokens, $outTokens]);
    if ($kind === 'connection' || $kind === 'discovery') {
        db()->prepare(
            $ok
                ? "UPDATE ellsms_ai_providers SET last_test_status = 'healthy', last_tested_at = NOW(), last_success_at = NOW(),
                          last_error_code = NULL, last_error_message = NULL, last_latency_ms = ? WHERE id = ?"
                : "UPDATE ellsms_ai_providers SET last_test_status = 'unhealthy', last_tested_at = NOW(), last_failure_at = NOW(),
                          last_error_code = ?, last_error_message = ?, last_latency_ms = ? WHERE id = ?"
        )->execute($ok ? [$latencyMs, $providerId] : [$error?->errorCode, $message, $latencyMs, $providerId]);
    }
    Logger::info('ai.provider.run', ['provider_id' => $providerId, 'model_id' => $modelId, 'kind' => $kind, 'ok' => $ok,
        'error_code' => $error?->errorCode, 'latency_ms' => $latencyMs]);
}

/** @return list<array> the provider's latest test runs, newest first. */
function ai_recent_runs(int $providerId, int $limit = 20): array {
    $st = db()->prepare('SELECT r.*, m.external_model_id FROM ellsms_ai_test_runs r LEFT JOIN ellsms_ai_models m ON m.id = r.model_id
                         WHERE r.provider_id = ? ORDER BY r.id DESC LIMIT ' . max(1, min(100, $limit)));
    $st->execute([$providerId]);
    return $st->fetchAll();
}

/**
 * «تست اتصال»: lists the provider's models without saving them. A server that answers but has no
 * models endpoint (404) is reachable — reported as healthy with models_not_supported, so a Hermes
 * build without /v1/models can still be used with a manually added model.
 * @return array{ok:bool, message:string, model_count:?int}
 */
function ai_provider_test(int $providerId, int $actorId): array {
    $provider = ai_provider_find($providerId) ?? throw new AppException('ارائه‌دهنده پیدا نشد.');
    try {
        $cfg = ai_provider_config($provider);
        $result = ai_list_models($cfg);
        ai_record_run($providerId, null, 'connection', $actorId, true, null, $result['latency_ms'], count($result['models']));
        return ['ok' => true, 'model_count' => count($result['models']),
                'message' => 'اتصال برقرار است — ' . count($result['models']) . ' مدل در ارائه‌دهنده موجود است (' . $result['latency_ms'] . ' ms).'];
    } catch (AiProviderException $e) {
        if ($e->errorCode === 'not_found') {
            ai_record_run($providerId, null, 'connection', $actorId, true, null, null, 0);
            return ['ok' => true, 'model_count' => null, 'message' => 'اتصال برقرار است، اما ' . ai_error_label('models_not_supported')];
        }
        ai_record_run($providerId, null, 'connection', $actorId, false, $e, null);
        return ['ok' => false, 'model_count' => null, 'message' => $e->getMessage()];
    }
}

/**
 * «تشخیص مدل‌ها»: lists the provider's models and upserts them. New models arrive DISABLED; an
 * existing model keeps its status, name and prices (OpenRouter's reported prices fill only empty
 * ones); a model the provider no longer lists gets missing_since.
 * @return array{ok:bool, message:string, added:int, seen:int}
 */
function ai_discover_models(int $providerId, int $actorId): array {
    $provider = ai_provider_find($providerId) ?? throw new AppException('ارائه‌دهنده پیدا نشد.');
    try {
        $cfg = ai_provider_config($provider);
        $result = ai_list_models($cfg);
    } catch (AiProviderException $e) {
        ai_record_run($providerId, null, 'discovery', $actorId, false, $e, null);
        $hint = $e->errorCode === 'not_found' ? ' ' . ai_error_label('models_not_supported') : '';
        return ['ok' => false, 'added' => 0, 'seen' => 0, 'message' => $e->getMessage() . $hint];
    }

    $added = 0;
    db_transaction(function (PDO $db) use ($providerId, $result, &$added): void {
        $insert = $db->prepare(
            "INSERT INTO ellsms_ai_models (provider_id, external_model_id, display_name, status, context_window, max_output_tokens,
                                          input_cost_per_million, output_cost_per_million, source, discovered_at, last_seen_at)
             VALUES (?,?,?,'disabled',?,?,?,?,'discovered',NOW(),NOW())
             ON DUPLICATE KEY UPDATE last_seen_at = NOW(), missing_since = NULL,
                 context_window = COALESCE(context_window, VALUES(context_window)),
                 max_output_tokens = COALESCE(max_output_tokens, VALUES(max_output_tokens)),
                 input_cost_per_million = COALESCE(input_cost_per_million, VALUES(input_cost_per_million)),
                 output_cost_per_million = COALESCE(output_cost_per_million, VALUES(output_cost_per_million))"
        );
        foreach ($result['models'] as $m) {
            $insert->execute([$providerId, $m['id'], $m['display_name'], $m['context_window'], $m['max_output_tokens'],
                              $m['input_cost_per_million'], $m['output_cost_per_million']]);
            if ($insert->rowCount() === 1) $added++; // 1 = inserted, 2 = updated, 0 = unchanged
        }
        $ids = array_column($result['models'], 'id');
        $sql = "UPDATE ellsms_ai_models SET missing_since = COALESCE(missing_since, NOW())
                WHERE provider_id = ? AND source = 'discovered'";
        $params = [$providerId];
        if ($ids !== []) {
            $sql .= ' AND external_model_id NOT IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            $params = array_merge($params, $ids);
        }
        $db->prepare($sql)->execute($params);
    });
    // Hosted APIs answer tool calls; a self-hosted server (Hermes, vLLM, Ollama) only with the right
    // parser, so the admin switches those on per model after testing.
    if (in_array($provider['type'], ['openai', 'anthropic', 'openrouter'], true)) {
        try {
            db()->prepare("UPDATE ellsms_ai_models SET supports_tools = 1 WHERE provider_id = ? AND discovered_at >= NOW() - INTERVAL 1 MINUTE AND updated_by IS NULL")
                ->execute([$providerId]);
        } catch (PDOException) {
            // 2026_11_06_ai_datasets.sql not applied yet.
        }
    }
    ai_record_run($providerId, null, 'discovery', $actorId, true, null, $result['latency_ms'], count($result['models']));
    audit($actorId, 'ai.models.discover', json_encode(['provider_id' => $providerId, 'seen' => count($result['models']), 'added' => $added]) ?: '');
    $message = count($result['models']) . ' مدل شناسایی شد؛ ' . $added . ' مدل جدید (غیرفعال) اضافه شد.'
        . ($result['truncated'] ? ' فهرست بیش از ' . AI_MAX_DISCOVERED_MODELS . ' مدل بود و کوتاه شد.' : '');
    return ['ok' => true, 'added' => $added, 'seen' => count($result['models']), 'message' => $message];
}

/* ==========================================================================
   Models
   ========================================================================== */

/** @return list<array> the provider's models. */
function ai_models_for_provider(int $providerId): array {
    $st = db()->prepare('SELECT * FROM ellsms_ai_models WHERE provider_id = ? ORDER BY status = \'active\' DESC, sort_order, external_model_id');
    $st->execute([$providerId]);
    return $st->fetchAll();
}

/** @return list<array> every model with its provider's name/type/status, active ones first. */
function ai_models_all(bool $activeOnly = false): array {
    $sql = "SELECT m.*, p.name AS provider_name, p.code AS provider_code, p.type AS provider_type, p.status AS provider_status
            FROM ellsms_ai_models m JOIN ellsms_ai_providers p ON p.id = m.provider_id";
    if ($activeOnly) $sql .= " WHERE m.status = 'active' AND p.status = 'active'";
    return db()->query($sql . " ORDER BY p.name, m.status = 'active' DESC, m.sort_order, m.display_name")->fetchAll();
}

function ai_model_find(int $id): ?array {
    $st = db()->prepare('SELECT m.*, p.name AS provider_name, p.status AS provider_status, p.type AS provider_type
                         FROM ellsms_ai_models m JOIN ellsms_ai_providers p ON p.id = m.provider_id WHERE m.id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

/** Pure: a decimal price field ('' = none) -> stored value or null; false when invalid. */
function ai_parse_price(string $value): string|null|false {
    $value = trim(str_replace(',', '.', $value));
    if ($value === '') return null;
    if (preg_match('/^\d{1,10}(\.\d{1,8})?$/', $value) !== 1) return false;
    return $value;
}

/** Adds a model the provider does not list (or before discovery works); it starts DISABLED. */
function ai_model_add_manual(int $providerId, string $externalId, string $displayName, int $actorId): int {
    ai_provider_find($providerId) ?? throw new AppException('ارائه‌دهنده پیدا نشد.');
    $externalId = trim($externalId);
    if ($externalId === '' || strlen($externalId) > 191 || preg_match('/[\x00-\x1F\x7F]/', $externalId) === 1) {
        throw new AppException('شناسه‌ی مدل نامعتبر است.');
    }
    $displayName = trim($displayName) !== '' ? mb_substr(trim($displayName), 0, 191) : $externalId;
    $st = db()->prepare('SELECT id FROM ellsms_ai_models WHERE provider_id = ? AND external_model_id = ?');
    $st->execute([$providerId, $externalId]);
    if ($st->fetchColumn()) throw new AppException('این مدل قبلاً در فهرست هست.');
    db()->prepare("INSERT INTO ellsms_ai_models (provider_id, external_model_id, display_name, status, source, updated_by)
                   VALUES (?,?,?,'disabled','manual',?)")->execute([$providerId, $externalId, $displayName, $actorId]);
    $id = (int)db()->lastInsertId();
    audit($actorId, 'ai.model.add', json_encode(['model_id' => $id, 'provider_id' => $providerId, 'model' => $externalId], JSON_UNESCAPED_UNICODE) ?: '');
    return $id;
}

function ai_model_update(int $modelId, array $input, int $actorId): void {
    $model = ai_model_find($modelId) ?? throw new AppException('مدل پیدا نشد.');
    $displayName = trim((string)($input['display_name'] ?? ''));
    if ($displayName === '' || mb_strlen($displayName) > 191) throw new AppException('نام نمایشی باید ۱ تا ۱۹۱ نویسه باشد.');
    $maxOut = trim((string)($input['max_output_tokens'] ?? ''));
    $context = trim((string)($input['context_window'] ?? ''));
    foreach ([[$maxOut, 'حداکثر خروجی'], [$context, 'پنجره‌ی متن']] as [$value, $label]) {
        if ($value !== '' && (!ctype_digit($value) || (int)$value < 1 || (int)$value > 10_000_000)) {
            throw new AppException($label . ' باید عدد مثبت باشد.');
        }
    }
    $inPrice = ai_parse_price((string)($input['input_cost_per_million'] ?? ''));
    $outPrice = ai_parse_price((string)($input['output_cost_per_million'] ?? ''));
    if ($inPrice === false || $outPrice === false) throw new AppException('قیمت باید عدد اعشاری نامنفی باشد (مثلاً 0.15).');
    $currency = strtoupper(trim((string)($input['cost_currency'] ?? 'USD')));
    if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) $currency = 'USD';
    $status = ($input['status'] ?? '') === 'active' ? 'active' : 'disabled';

    db()->prepare(
        'UPDATE ellsms_ai_models SET display_name = ?, status = ?, max_output_tokens = ?, context_window = ?,
                input_cost_per_million = ?, output_cost_per_million = ?, cost_currency = ?, sort_order = ?, updated_by = ? WHERE id = ?'
    )->execute([$displayName, $status, $maxOut === '' ? null : (int)$maxOut, $context === '' ? null : (int)$context,
                $inPrice, $outPrice, $currency, (int)($input['sort_order'] ?? 100), $actorId, $modelId]);
    // Tool calls (phase 4, the secretary's record lookup): only once the column exists.
    if (array_key_exists('supports_tools', $model)) {
        db()->prepare('UPDATE ellsms_ai_models SET supports_tools = ? WHERE id = ?')->execute([!empty($input['supports_tools']) ? 1 : 0, $modelId]);
    }
    audit($actorId, 'ai.model.update', json_encode(['model_id' => $modelId, 'status' => $status, 'model' => $model['external_model_id']], JSON_UNESCAPED_UNICODE) ?: '');
}

function ai_model_set_status(int $modelId, bool $active, int $actorId): void {
    db()->prepare('UPDATE ellsms_ai_models SET status = ?, updated_by = ? WHERE id = ?')->execute([$active ? 'active' : 'disabled', $actorId, $modelId]);
    audit($actorId, 'ai.model.status', json_encode(['model_id' => $modelId, 'status' => $active ? 'active' : 'disabled']) ?: '');
}

function ai_model_delete(int $modelId, int $actorId): void {
    $model = ai_model_find($modelId) ?? throw new AppException('مدل پیدا نشد.');
    db()->prepare('DELETE FROM ellsms_ai_models WHERE id = ?')->execute([$modelId]);
    if ((int)ai_setting('ai_default_model_id') === $modelId) set_setting('ai_default_model_id', '0');
    db()->prepare('UPDATE ellsms_ai_accounts SET default_model_id = NULL WHERE default_model_id = ?')->execute([$modelId]);
    audit($actorId, 'ai.model.delete', json_encode(['model_id' => $modelId, 'model' => $model['external_model_id']], JSON_UNESCAPED_UNICODE) ?: '');
}

/** The output cap to send to a model: its own max_output_tokens when lower than $wanted. */
function ai_model_token_cap(array $model, int $wanted): int {
    $cap = (int)($model['max_output_tokens'] ?? 0);
    return $cap > 0 ? max(1, min($wanted, $cap)) : max(1, $wanted);
}

/**
 * One chat call to a model, recorded as a test run of $kind ('model' or 'playground'). Works on a
 * disabled model too — an admin tests a model before switching it on.
 * @return array{ok:bool, text?:string, message?:string, input_tokens?:?int, output_tokens?:?int,
 *               latency_ms?:int, finish_reason?:?string, cost?:?float, currency?:string}
 */
function ai_model_chat(int $modelId, array $messages, int $maxTokens, ?float $temperature, string $kind, int $actorId): array {
    $model = ai_model_find($modelId) ?? throw new AppException('مدل پیدا نشد.');
    $provider = ai_provider_find((int)$model['provider_id']) ?? throw new AppException('ارائه‌دهنده پیدا نشد.');
    try {
        $cfg = ai_provider_config($provider);
        $reply = ai_chat($cfg, (string)$model['external_model_id'], $messages, ai_model_token_cap($model, $maxTokens), $temperature);
    } catch (AiProviderException $e) {
        ai_record_run((int)$provider['id'], $modelId, $kind, $actorId, false, $e, null);
        if ($kind === 'model') {
            db()->prepare('UPDATE ellsms_ai_models SET last_tested_at = NOW(), last_test_ok = 0, last_error_code = ?, last_latency_ms = NULL WHERE id = ?')
                ->execute([$e->errorCode, $modelId]);
        }
        return ['ok' => false, 'message' => $e->getMessage()];
    }
    ai_record_run((int)$provider['id'], $modelId, $kind, $actorId, true, null, $reply['latency_ms'], null, $reply['input_tokens'], $reply['output_tokens']);
    if ($kind === 'model') {
        db()->prepare('UPDATE ellsms_ai_models SET last_tested_at = NOW(), last_test_ok = 1, last_error_code = NULL, last_latency_ms = ? WHERE id = ?')
            ->execute([$reply['latency_ms'], $modelId]);
    }
    return ['ok' => true] + $reply + [
        'cost' => ai_cost_estimate($reply['input_tokens'], $reply['output_tokens'], $model['input_cost_per_million'], $model['output_cost_per_million']),
        'currency' => (string)$model['cost_currency'],
    ];
}

/** «تست مدل»: a one-line prompt with a tiny output cap. */
function ai_model_test(int $modelId, int $actorId): array {
    return ai_model_chat($modelId, [['role' => 'user', 'content' => 'Reply with the single word: OK']], 16, null, 'model', $actorId);
}

/* ==========================================================================
   Settings
   ========================================================================== */

function ai_setting(string $key): string {
    return (string)setting_fresh($key, AI_SETTINGS[$key] ?? '');
}

function ai_settings_save(int $defaultModelId, bool $defaultAccess, int $actorId): void {
    if ($defaultModelId !== 0) {
        $model = ai_model_find($defaultModelId);
        if ($model === null || $model['status'] !== 'active' || $model['provider_status'] !== 'active') {
            throw new AppException('مدل پیش‌فرض باید یک مدل فعال روی ارائه‌دهنده‌ی فعال باشد.');
        }
    }
    set_setting('ai_default_model_id', (string)$defaultModelId);
    set_setting('ai_default_access', $defaultAccess ? '1' : '0');
    audit($actorId, 'ai.settings', json_encode(['default_model_id' => $defaultModelId, 'default_access' => $defaultAccess]) ?: '');
}

/* ==========================================================================
   Model access per account
   ========================================================================== */

/**
 * Pure: the effective model set of an account.
 *
 * $chain: the account's chain, top-level account FIRST, the account itself LAST; each level is
 *   ['custom' => bool, 'models' => list<int>, 'default' => ?int].
 * $usable: ids of models that are switched on (on a switched-on provider), in display order.
 * $platformDefault: the platform default model id (0 = none); $platformAccess: whether a top-level
 *   account without a custom set gets it.
 *
 * @return array{models: list<int>, default: ?int, source: string}
 *   source: 'custom' (own set), 'inherited' (from a parent), 'platform' (platform default), 'none'
 */
function ai_access_resolve(array $chain, array $usable, int $platformDefault, bool $platformAccess): array {
    $usable = array_values(array_map('intval', $usable));
    $chain = array_values($chain);
    $set = ($platformAccess && $platformDefault > 0 && in_array($platformDefault, $usable, true)) ? [$platformDefault] : [];
    $defaults = [];
    $anyCustom = false;
    foreach ($chain as $depth => $level) {
        if (!empty($level['custom'])) {
            $anyCustom = true;
            $own = array_values(array_intersect($usable, array_map('intval', $level['models'] ?? [])));
            // The top-level account's custom set replaces the platform default; below it, a custom set
            // can only narrow what the parent has.
            $set = $depth === 0 ? $own : array_values(array_intersect($own, $set));
        }
        if (!empty($level['default'])) {
            $defaults[] = (int)$level['default'];
        }
    }
    $self = $chain === [] ? [] : $chain[array_key_last($chain)];
    $source = !empty($self['custom']) ? 'custom' : ($anyCustom ? 'inherited' : 'platform');
    if ($set === []) {
        return ['models' => [], 'default' => null, 'source' => 'none'];
    }
    $default = null;
    foreach (array_reverse($defaults) as $candidate) {
        if (in_array($candidate, $set, true)) {
            $default = $candidate;
            break;
        }
    }
    if ($default === null && in_array($platformDefault, $set, true)) {
        $default = $platformDefault;
    }
    return ['models' => $set, 'default' => $default ?? $set[0], 'source' => $source];
}

/** @return list<int> the account's chain of user ids, top-level first, the account last. */
function ai_account_chain(int $userId): array {
    $chain = [$userId];
    $seen = [$userId => true];
    $current = $userId;
    for ($i = 0; $i < 10; $i++) {
        $parent = customer_account($current)['parent_user_id'] ?? null;
        if ($parent === null || isset($seen[(int)$parent])) break;
        $current = (int)$parent;
        $seen[$current] = true;
        array_unshift($chain, $current);
    }
    return $chain;
}

/** The stored access row of each account in $userIds: user_id => ['custom', 'models', 'default']. */
function ai_access_rows(array $userIds): array {
    $out = [];
    foreach ($userIds as $id) $out[(int)$id] = ['custom' => false, 'models' => [], 'default' => null];
    if ($userIds === []) return $out;
    $in = implode(',', array_fill(0, count($userIds), '?'));
    $st = db()->prepare("SELECT user_id, custom, default_model_id FROM ellsms_ai_accounts WHERE user_id IN ($in)");
    $st->execute(array_values($userIds));
    foreach ($st->fetchAll() as $row) {
        $out[(int)$row['user_id']]['custom'] = (bool)$row['custom'];
        $out[(int)$row['user_id']]['default'] = $row['default_model_id'] !== null ? (int)$row['default_model_id'] : null;
    }
    $st = db()->prepare("SELECT user_id, model_id FROM ellsms_ai_model_access WHERE user_id IN ($in)");
    $st->execute(array_values($userIds));
    foreach ($st->fetchAll() as $row) {
        $out[(int)$row['user_id']]['models'][] = (int)$row['model_id'];
    }
    return $out;
}

/** @return list<int> ids of the models that are usable right now, in display order. */
function ai_usable_model_ids(): array {
    return array_map('intval', array_column(ai_models_all(true), 'id'));
}

/**
 * The account's effective models.
 * @return array{models: list<array>, default: ?array, source: string, own: array}
 *   own: the account's own stored row (custom flag, model ids, default) for the edit form.
 */
function ai_access_effective(int $userId): array {
    $chainIds = ai_account_chain($userId);
    $rows = ai_access_rows($chainIds);
    $chain = array_map(static fn(int $id): array => $rows[$id], $chainIds);
    $resolved = ai_access_resolve($chain, ai_usable_model_ids(), (int)ai_setting('ai_default_model_id'), ai_setting('ai_default_access') === '1');
    $byId = [];
    foreach (ai_models_all(true) as $m) $byId[(int)$m['id']] = $m;
    return [
        'models'  => array_values(array_map(static fn(int $id): array => $byId[$id], array_filter($resolved['models'], static fn(int $id): bool => isset($byId[$id])))),
        'default' => $resolved['default'] !== null ? ($byId[$resolved['default']] ?? null) : null,
        'source'  => $resolved['source'],
        'own'     => $rows[$userId],
        'chain'   => $chainIds,
    ];
}

/**
 * Saves an account's access. $custom false = inherit (the stored models are cleared). The default
 * must be one of the given models.
 */
function ai_access_save(int $userId, bool $custom, array $modelIds, ?int $defaultModelId, int $actorId): void {
    $modelIds = array_values(array_unique(array_filter(array_map('intval', $modelIds), static fn(int $id): bool => $id > 0)));
    if ($custom && $modelIds !== []) {
        $in = implode(',', array_fill(0, count($modelIds), '?'));
        $st = db()->prepare("SELECT id FROM ellsms_ai_models WHERE id IN ($in)");
        $st->execute($modelIds);
        $modelIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }
    if (!$custom) {
        $modelIds = [];
    }
    if ($defaultModelId !== null && $defaultModelId > 0 && $custom && !in_array($defaultModelId, $modelIds, true)) {
        throw new AppException('مدل پیش‌فرض باید یکی از مدل‌های انتخاب‌شده باشد.');
    }
    if ($defaultModelId !== null && $defaultModelId <= 0) {
        $defaultModelId = null;
    }
    db_transaction(function (PDO $db) use ($userId, $custom, $modelIds, $defaultModelId, $actorId): void {
        $db->prepare('INSERT INTO ellsms_ai_accounts (user_id, custom, default_model_id, updated_by) VALUES (?,?,?,?)
                      ON DUPLICATE KEY UPDATE custom = VALUES(custom), default_model_id = VALUES(default_model_id), updated_by = VALUES(updated_by)')
           ->execute([$userId, $custom ? 1 : 0, $defaultModelId, $actorId]);
        $db->prepare('DELETE FROM ellsms_ai_model_access WHERE user_id = ?')->execute([$userId]);
        $insert = $db->prepare('INSERT INTO ellsms_ai_model_access (user_id, model_id, granted_by) VALUES (?,?,?)');
        foreach ($modelIds as $id) $insert->execute([$userId, $id, $actorId]);
    });
    audit($actorId, 'ai.access.save', json_encode(['user_id' => $userId, 'custom' => $custom, 'models' => $modelIds, 'default' => $defaultModelId]) ?: '');
}

/** @return list<array{user_id:int, custom:bool, model_count:int, default_model_id:?int}> accounts with a stored row. */
function ai_access_accounts(): array {
    return db()->query(
        'SELECT a.user_id, a.custom, a.default_model_id, (SELECT COUNT(*) FROM ellsms_ai_model_access x WHERE x.user_id = a.user_id) AS model_count
         FROM ellsms_ai_accounts a ORDER BY a.updated_at DESC LIMIT 500'
    )->fetchAll();
}
