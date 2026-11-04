<?php
/**
 * ELLSMS — AI provider adapters: the wire protocol of each provider type (docs/ai-engines.md).
 *
 * Six types, three protocols:
 *   openai_compatible, openai, openrouter, gapgpt
 *                                          GET {base}/models, POST {base}/chat/completions
 *                                          (Hermes, vLLM, LM Studio, DeepSeek, ... are all this one)
 *   ollama                                 GET {base}/api/tags, POST {base}/api/chat
 *   anthropic                              GET {base}/models (paginated), POST {base}/messages
 *
 * Everything that builds a request or reads a response is a PURE function of its arguments (no
 * database, no network) and is covered by tests/Unit/AiAdaptersTest.php. The one function that
 * talks to the network is ai_http_send(), and every call goes through it, so the outbound policy is
 * applied in exactly one place:
 *   - only http(s), no redirects followed, a response-size cap, TLS verification always on;
 *   - a provider WITHOUT allow_private_network must be https and resolve to public addresses only,
 *     and the connection is pinned to the address that was checked (no DNS-rebinding window) — the
 *     same check number forwarding and webhooks use;
 *   - a provider WITH allow_private_network (an internal Hermes) may use http and a private address.
 *
 * The API key is never written to a log, an error message or a test-run row: provider error text is
 * scrubbed of it before it is kept (ai_scrub_secret()).
 */

declare(strict_types=1);

/** A provider call failed in a way the admin can act on. ->errorCode is one of ai_error_label()'s keys. */
class AiProviderException extends AppException {
    public function __construct(public readonly string $errorCode, string $message = '', public readonly ?int $httpStatus = null) {
        parent::__construct($message !== '' ? $message : ai_error_label($errorCode));
    }
}

/** type => label, in the order the "new provider" form lists them. */
const AI_PROVIDER_TYPES = [
    'openai_compatible' => 'سازگار با OpenAI (Hermes و ...)',
    'ollama'            => 'Ollama',
    'openai'            => 'OpenAI',
    'anthropic'         => 'Anthropic (Claude)',
    'openrouter'        => 'OpenRouter',
    'gapgpt'            => 'GapGPT (ایرانی، بدون نیاز به پراکسی)',
];

/** Suggested base URL per type (the form pre-fills it; openai_compatible has no universal default). */
const AI_PROVIDER_DEFAULT_BASE_URLS = [
    'openai_compatible' => '',
    'ollama'            => 'http://localhost:11434',
    'openai'            => 'https://api.openai.com/v1',
    'anthropic'         => 'https://api.anthropic.com/v1',
    'openrouter'        => 'https://openrouter.ai/api/v1',
    'gapgpt'            => 'https://api.gapgpt.app/v1',
];

const AI_AUTH_SCHEMES = ['bearer' => 'Authorization: Bearer', 'header' => 'هدر سفارشی', 'none' => 'بدون کلید'];

/** API version sent to Anthropic. On this version temperature / top_p / top_k are rejected, so none is sent. */
const AI_ANTHROPIC_VERSION = '2026-10-21';

/** The app name OpenRouter lists requests under (X-Title). */
const AI_OPENROUTER_APP_TITLE = 'ELLSMS';

/** Discovery stops after this many models (OpenRouter lists several hundred). */
const AI_MAX_DISCOVERED_MODELS = 1000;
/** Responses larger than this are cut off and reported as response_too_large. */
const AI_MAX_RESPONSE_BYTES = 4 * 1024 * 1024;
/** Headers an admin may not set through extra headers — they are owned by the adapter or by curl. */
const AI_RESERVED_HEADERS = ['host', 'content-length', 'content-type', 'transfer-encoding', 'connection', 'authorization',
                             'x-api-key', 'anthropic-version', 'cookie', 'proxy-authorization', 'accept-encoding'];

/* ==========================================================================
   Errors
   ========================================================================== */

function ai_error_label(string $code): string {
    return match ($code) {
        'auth_failed'          => 'احراز هویت ناموفق بود؛ کلید API را بررسی کنید.',
        'no_api_key'           => 'برای این ارائه‌دهنده کلید API ذخیره نشده است.',
        'not_found'            => 'آدرس یا مدل در ارائه‌دهنده پیدا نشد (۴۰۴).',
        'rate_limited'         => 'ارائه‌دهنده محدودیت نرخ اعمال کرد (۴۲۹)؛ کمی بعد دوباره تلاش کنید.',
        'bad_request'          => 'ارائه‌دهنده درخواست را نپذیرفت.',
        'upstream_error'       => 'خطای سمت ارائه‌دهنده (۵xx).',
        'timeout'              => 'مهلت پاسخ ارائه‌دهنده تمام شد.',
        'connection_failed'    => 'اتصال به ارائه‌دهنده برقرار نشد.',
        'tls_error'            => 'خطای گواهی یا اتصال امن (TLS).',
        'dns_failed'           => 'نام میزبان پیدا نشد (DNS).',
        'blocked_private'      => 'این آدرس به شبکه‌ی داخلی اشاره می‌کند. برای سرور داخلی (مثل Hermes)، گزینه‌ی «اجازه‌ی آدرس داخلی» را روشن کنید.',
        'https_required'       => 'برای آدرس عمومی فقط https مجاز است. برای سرور داخلی، گزینه‌ی «اجازه‌ی آدرس داخلی» را روشن کنید.',
        'url_invalid'          => 'آدرس نامعتبر است.',
        'invalid_json'         => 'پاسخ ارائه‌دهنده JSON معتبر نبود.',
        'response_too_large'   => 'پاسخ ارائه‌دهنده بیش از حد بزرگ بود.',
        'empty_response'       => 'ارائه‌دهنده پاسخ متنی برنگرداند.',
        'refusal'              => 'مدل از پاسخ‌دادن خودداری کرد.',
        'models_not_supported' => 'این سرور فهرست مدل‌ها را ارائه نمی‌کند؛ مدل را دستی اضافه کنید.',
        'secret_unavailable'   => 'کلید API ذخیره‌شده قابل رمزگشایی نیست (کلید اصلی تغییر کرده است).',
        default                => 'خطای نامشخص.',
    };
}

/** Pure: removes every occurrence of $secret (and a masked tail of it) from $text. */
function ai_scrub_secret(string $text, ?string $secret): string {
    if ($secret !== null && $secret !== '' && strlen($secret) >= 6) {
        $text = str_replace($secret, '••••', $text);
    }
    return $text;
}

/**
 * Pure: an HTTP error response -> [code, message]. The message is the provider's own text when it
 * gives one (OpenAI/OpenRouter/Anthropic: error.message; Ollama: error), scrubbed and cut to 300.
 */
function ai_classify_http_error(int $status, ?array $json, string $rawBody, ?string $secret = null): array {
    $code = match (true) {
        $status === 401 || $status === 403 => 'auth_failed',
        $status === 404                    => 'not_found',
        $status === 429                    => 'rate_limited',
        $status >= 500                     => 'upstream_error',
        default                            => 'bad_request',
    };
    $message = '';
    if (is_array($json)) {
        $error = $json['error'] ?? null;
        if (is_array($error) && is_string($error['message'] ?? null)) {
            $message = $error['message'];
        } elseif (is_string($error)) {
            $message = $error;
        } elseif (is_string($json['message'] ?? null)) {
            $message = $json['message'];
        } elseif (is_string($json['detail'] ?? null)) {
            $message = $json['detail'];
        }
    }
    if ($message === '' && $json === null) {
        $message = trim(strip_tags($rawBody));
    }
    $message = mb_strimwidth(ai_scrub_secret(preg_replace('/\s+/u', ' ', $message) ?? '', $secret), 0, 300, '…');
    return [$code, $message];
}

/* ==========================================================================
   Configuration helpers (pure)
   ========================================================================== */

function ai_join_url(string $base, string $path): string {
    return rtrim($base, '/') . '/' . ltrim($path, '/');
}

/** Whether a provider type speaks the OpenAI chat-completions protocol. */
function ai_type_is_openai_like(string $type): bool {
    return in_array($type, ['openai_compatible', 'openai', 'openrouter', 'gapgpt'], true);
}

/**
 * Pure: validates extra headers given as "Name: value" lines.
 * @return array{0: array<string,string>, 1: list<string>} [headers, errors]
 */
function ai_parse_extra_headers(string $text): array {
    $headers = [];
    $errors = [];
    foreach (preg_split('/\R/u', $text) ?: [] as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $pos = strpos($line, ':');
        if ($pos === false) {
            $errors[] = 'سطر هدر باید به شکل «نام: مقدار» باشد: ' . mb_strimwidth($line, 0, 40, '…');
            continue;
        }
        $name = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));
        if (preg_match('/^[A-Za-z0-9-]{1,64}$/', $name) !== 1) {
            $errors[] = 'نام هدر نامعتبر است: ' . mb_strimwidth($name, 0, 40, '…');
            continue;
        }
        if (in_array(strtolower($name), AI_RESERVED_HEADERS, true)) {
            $errors[] = 'هدر «' . $name . '» توسط سامانه تنظیم می‌شود و قابل تعریف نیست.';
            continue;
        }
        if (strlen($value) > 512 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            $errors[] = 'مقدار هدر «' . $name . '» نامعتبر است.';
            continue;
        }
        $headers[$name] = $value;
    }
    if (count($headers) > 20) {
        $errors[] = 'حداکثر ۲۰ هدر اضافه مجاز است.';
    }
    return [$headers, $errors];
}

/**
 * Pure: the headers of every request to a provider. $cfg keys: type, auth_scheme, auth_header,
 * api_key (?string), extra_headers (array).
 * @return list<string>
 */
function ai_request_headers(array $cfg, bool $withBody): array {
    $headers = ['Accept: application/json'];
    if ($withBody) {
        $headers[] = 'Content-Type: application/json';
    }
    foreach (($cfg['extra_headers'] ?? []) as $name => $value) {
        $headers[] = $name . ': ' . $value;
    }
    if (($cfg['type'] ?? '') === 'openrouter' && !array_key_exists('x-title', array_change_key_case($cfg['extra_headers'] ?? []))) {
        // OpenRouter shows requests under this app name in its activity page (an extra header overrides it).
        $headers[] = 'X-Title: ' . AI_OPENROUTER_APP_TITLE;
    }
    $key = (string)($cfg['api_key'] ?? '');
    if (($cfg['type'] ?? '') === 'anthropic') {
        $headers[] = 'anthropic-version: ' . AI_ANTHROPIC_VERSION;
        if ($key !== '') $headers[] = 'x-api-key: ' . $key;
        return $headers;
    }
    if ($key === '') {
        return $headers;
    }
    switch ($cfg['auth_scheme'] ?? 'bearer') {
        case 'bearer':
            $headers[] = 'Authorization: Bearer ' . $key;
            break;
        case 'header':
            $name = (string)($cfg['auth_header'] ?? '');
            if ($name !== '') $headers[] = $name . ': ' . $key;
            break;
    }
    return $headers;
}

/**
 * Whether this provider config is missing an API key it cannot work without. Hosted providers
 * always need one; a self-hosted server (Hermes, Ollama) only when its auth scheme says so.
 */
function ai_config_missing_key(array $cfg): bool {
    if (($cfg['api_key'] ?? '') !== '') {
        return false;
    }
    $type = (string)($cfg['type'] ?? '');
    if ($type === 'ollama' || $type === 'openai_compatible') {
        return ($cfg['auth_scheme'] ?? 'bearer') !== 'none';
    }
    return true;
}

/* ==========================================================================
   Model discovery (pure)
   ========================================================================== */

/** @return array{method:string, url:string, headers:list<string>, body:?string} */
function ai_build_models_request(array $cfg, ?string $afterId = null): array {
    $base = (string)$cfg['base_url'];
    $url = match ($cfg['type']) {
        'ollama'    => ai_join_url($base, 'api/tags'),
        'anthropic' => ai_join_url($base, 'models') . '?limit=1000' . ($afterId !== null ? '&after_id=' . rawurlencode($afterId) : ''),
        default     => ai_join_url($base, 'models'),
    };
    return ['method' => 'GET', 'url' => $url, 'headers' => ai_request_headers($cfg, false), 'body' => null];
}

/** Pure: a USD-per-token price string (OpenRouter) -> USD per million tokens, or null. */
function ai_per_token_to_per_million(mixed $value): ?string {
    if (!is_numeric($value)) return null;
    $perMillion = (float)$value * 1_000_000;
    if ($perMillion < 0) return null;
    return rtrim(rtrim(number_format($perMillion, 8, '.', ''), '0'), '.') ?: '0';
}

/**
 * Pure: a models response -> the models it lists, plus the cursor of the next page (Anthropic only).
 * @return array{models: list<array{id:string, display_name:string, context_window:?int, max_output_tokens:?int,
 *               input_cost_per_million:?string, output_cost_per_million:?string, supports_tools:?bool}>, next: ?string}
 * supports_tools is known for OpenRouter only (its supported_parameters lists "tools").
 */
function ai_parse_models(string $type, array $json): array {
    $rows = $type === 'ollama' ? ($json['models'] ?? null) : ($json['data'] ?? null);
    if (!is_array($rows)) {
        throw new AiProviderException('invalid_json', 'پاسخ فهرست مدل‌ها قالب مورد انتظار را نداشت.');
    }
    $models = [];
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $id = $type === 'ollama' ? ($row['name'] ?? $row['model'] ?? null) : ($row['id'] ?? null);
        if (!is_string($id) || ($id = trim($id)) === '' || strlen($id) > 191) continue;
        $model = ['id' => $id, 'display_name' => $id, 'context_window' => null, 'max_output_tokens' => null,
                  'input_cost_per_million' => null, 'output_cost_per_million' => null, 'supports_tools' => null];
        if ($type === 'anthropic') {
            if (is_string($row['display_name'] ?? null) && $row['display_name'] !== '') $model['display_name'] = mb_substr($row['display_name'], 0, 191);
            if (is_int($row['max_input_tokens'] ?? null)) $model['context_window'] = $row['max_input_tokens'];
            if (is_int($row['max_tokens'] ?? null)) $model['max_output_tokens'] = $row['max_tokens'];
        } elseif ($type === 'openrouter') {
            if (is_string($row['name'] ?? null) && $row['name'] !== '') $model['display_name'] = mb_substr($row['name'], 0, 191);
            if (is_int($row['context_length'] ?? null)) $model['context_window'] = $row['context_length'];
            if (is_int($row['top_provider']['max_completion_tokens'] ?? null)) $model['max_output_tokens'] = $row['top_provider']['max_completion_tokens'];
            $model['input_cost_per_million'] = ai_per_token_to_per_million($row['pricing']['prompt'] ?? null);
            $model['output_cost_per_million'] = ai_per_token_to_per_million($row['pricing']['completion'] ?? null);
            if (is_array($row['supported_parameters'] ?? null)) {
                $model['supports_tools'] = in_array('tools', $row['supported_parameters'], true);
            }
        } elseif (is_int($row['context_length'] ?? null)) {
            // vLLM reports max_model_len, some OpenAI-compatible servers context_length.
            $model['context_window'] = $row['context_length'];
        } elseif (is_int($row['max_model_len'] ?? null)) {
            $model['context_window'] = $row['max_model_len'];
        }
        $models[$id] = $model;
    }
    $next = null;
    if ($type === 'anthropic' && ($json['has_more'] ?? false) === true && is_string($json['last_id'] ?? null)) {
        $next = $json['last_id'];
    }
    return ['models' => array_values($models), 'next' => $next];
}

/* ==========================================================================
   Chat (pure)
   ========================================================================== */

/** System prompts at least this long are marked cacheable for Anthropic (prompt caching). */
const AI_CACHE_MIN_SYSTEM_CHARS = 4000;

/**
 * Pure: the chat request for one model.
 *
 * $messages is a list of:
 *   ['role' => 'system'|'user'|'assistant', 'content' => string]
 *   ['role' => 'assistant', 'content' => string, 'tool_calls' => [['id', 'name', 'arguments' => array], ...]]
 *   ['role' => 'tool', 'tool_call_id' => string, 'name' => string, 'content' => string]
 * $tools: [['name', 'description', 'parameters' => JSON schema], ...] (null/[] = no tools).
 *
 * For Anthropic the system messages move to the top-level `system` field (marked cacheable when
 * long — the business information repeats on every answer), tool results become tool_result blocks
 * of a user turn, and consecutive turns of the same role are merged (the Messages API needs
 * alternating user/assistant turns starting with user). $temperature is sent to OpenAI-style and
 * Ollama providers only (Anthropic rejects it on AI_ANTHROPIC_VERSION).
 *
 * @return array{method:string, url:string, headers:list<string>, body:string}
 */
function ai_build_chat_request(array $cfg, string $model, array $messages, int $maxTokens, ?float $temperature = null, ?array $tools = null): array {
    $type = (string)$cfg['type'];
    $base = (string)$cfg['base_url'];
    $maxTokens = max(1, $maxTokens);
    $tools = $tools ?: [];

    if ($type === 'anthropic') {
        $system = [];
        $turns = [];
        $push = static function (string $role, array $blocks) use (&$turns): void {
            $last = array_key_last($turns);
            if ($last !== null && $turns[$last]['role'] === $role) {
                $turns[$last]['content'] = array_merge($turns[$last]['content'], $blocks);
            } else {
                $turns[] = ['role' => $role, 'content' => $blocks];
            }
        };
        foreach ($messages as $m) {
            $role = (string)($m['role'] ?? 'user');
            $content = (string)($m['content'] ?? '');
            if ($role === 'system') {
                if (trim($content) !== '') $system[] = $content;
            } elseif ($role === 'tool') {
                $push('user', [['type' => 'tool_result', 'tool_use_id' => (string)$m['tool_call_id'], 'content' => $content]]);
            } elseif ($role === 'assistant') {
                $blocks = trim($content) !== '' ? [['type' => 'text', 'text' => $content]] : [];
                foreach (($m['tool_calls'] ?? []) as $call) {
                    $blocks[] = ['type' => 'tool_use', 'id' => (string)$call['id'], 'name' => (string)$call['name'], 'input' => (object)($call['arguments'] ?? [])];
                }
                if ($blocks !== []) $push('assistant', $blocks);
            } else {
                $push('user', [['type' => 'text', 'text' => $content !== '' ? $content : '.']]);
            }
        }
        if ($turns === [] || $turns[0]['role'] !== 'user') {
            array_unshift($turns, ['role' => 'user', 'content' => [['type' => 'text', 'text' => '.']]]);
        }
        // A turn of plain text blocks only is sent as a string, like before tools existed.
        foreach ($turns as &$turn) {
            if (array_filter($turn['content'], static fn(array $b): bool => $b['type'] !== 'text') === []) {
                $turn['content'] = implode("\n\n", array_column($turn['content'], 'text'));
            }
        }
        unset($turn);
        $body = ['model' => $model, 'max_tokens' => $maxTokens, 'messages' => $turns];
        if ($system !== []) {
            $systemText = implode("\n\n", $system);
            $body['system'] = mb_strlen($systemText) >= AI_CACHE_MIN_SYSTEM_CHARS
                ? [['type' => 'text', 'text' => $systemText, 'cache_control' => ['type' => 'ephemeral']]]
                : $systemText;
        }
        if ($tools !== []) {
            $body['tools'] = array_map(static fn(array $t): array => ['name' => $t['name'], 'description' => $t['description'],
                'input_schema' => $t['parameters']], $tools);
        }
        $url = ai_join_url($base, 'messages');
    } else {
        $clean = [];
        foreach ($messages as $m) {
            $role = in_array($m['role'] ?? '', ['system', 'user', 'assistant', 'tool'], true) ? $m['role'] : 'user';
            $msg = ['role' => $role, 'content' => (string)($m['content'] ?? '')];
            if ($role === 'assistant' && !empty($m['tool_calls'])) {
                $msg['tool_calls'] = array_map(static fn(array $c): array => $type === 'ollama'
                    ? ['function' => ['name' => (string)$c['name'], 'arguments' => (object)($c['arguments'] ?? [])]]
                    : ['id' => (string)$c['id'], 'type' => 'function', 'function' => ['name' => (string)$c['name'],
                        'arguments' => json_encode((object)($c['arguments'] ?? []), JSON_UNESCAPED_UNICODE)]], $m['tool_calls']);
            }
            if ($role === 'tool') {
                if ($type === 'ollama') {
                    $msg['tool_name'] = (string)($m['name'] ?? '');
                } else {
                    $msg['tool_call_id'] = (string)($m['tool_call_id'] ?? '');
                }
            }
            $clean[] = $msg;
        }
        $toolDefs = array_map(static fn(array $t): array => ['type' => 'function', 'function' => ['name' => $t['name'],
            'description' => $t['description'], 'parameters' => $t['parameters']]], $tools);
        if ($type === 'ollama') {
            $options = ['num_predict' => $maxTokens];
            if ($temperature !== null) $options['temperature'] = $temperature;
            $body = ['model' => $model, 'messages' => $clean, 'stream' => false, 'options' => $options];
            $url = ai_join_url($base, 'api/chat');
        } else {
            $body = ['model' => $model, 'messages' => $clean, 'stream' => false];
            // OpenAI's current models reject max_tokens and take max_completion_tokens; compatible servers
            // (vLLM, Hermes, LM Studio) and OpenRouter take max_tokens.
            $body[$type === 'openai' ? 'max_completion_tokens' : 'max_tokens'] = $maxTokens;
            if ($temperature !== null) $body['temperature'] = $temperature;
            // OpenRouter then reports what the call actually cost, in USD (usage.cost).
            if ($type === 'openrouter') $body['usage'] = ['include' => true];
            $url = ai_join_url($base, 'chat/completions');
        }
        if ($toolDefs !== []) $body['tools'] = $toolDefs;
    }
    return [
        'method'  => 'POST',
        'url'     => $url,
        'headers' => ai_request_headers($cfg, true),
        'body'    => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    ];
}

/**
 * Pure: a chat response -> text, tool calls and token usage. A response with tool calls may have
 * no text; one with neither is an empty_response.
 *
 * Input tokens include cached prompt tokens (Anthropic reports them apart, as
 * cache_creation_input_tokens / cache_read_input_tokens; OpenAI already counts them in
 * prompt_tokens), so billing counts every token the model read.
 *
 * @return array{text:string, input_tokens:?int, output_tokens:?int, finish_reason:?string,
 *               tool_calls: list<array{id:string, name:string, arguments:array}>, cost:?float}
 * cost: the price the provider itself reported for this call, in USD (OpenRouter's usage.cost), or null.
 */
function ai_parse_chat(string $type, array $json): array {
    $text = '';
    $in = $out = null;
    $finish = null;
    $cost = null;
    $calls = [];
    $args = static function (mixed $raw): array {
        if (is_array($raw)) return $raw;
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    };
    if ($type === 'anthropic') {
        foreach (($json['content'] ?? []) as $block) {
            if (!is_array($block)) continue;
            if (($block['type'] ?? '') === 'text' && is_string($block['text'] ?? null)) {
                $text .= $block['text'];
            } elseif (($block['type'] ?? '') === 'tool_use' && is_string($block['name'] ?? null)) {
                $calls[] = ['id' => (string)($block['id'] ?? ('call_' . count($calls))), 'name' => $block['name'], 'arguments' => $args($block['input'] ?? [])];
            }
        }
        $usage = $json['usage'] ?? [];
        if (is_int($usage['input_tokens'] ?? null)) {
            $in = $usage['input_tokens'] + (int)($usage['cache_creation_input_tokens'] ?? 0) + (int)($usage['cache_read_input_tokens'] ?? 0);
        }
        $out = is_int($usage['output_tokens'] ?? null) ? $usage['output_tokens'] : null;
        $finish = is_string($json['stop_reason'] ?? null) ? $json['stop_reason'] : null;
        if ($finish === 'refusal') {
            throw new AiProviderException('refusal');
        }
    } elseif ($type === 'ollama') {
        $text = is_string($json['message']['content'] ?? null) ? $json['message']['content'] : '';
        foreach (($json['message']['tool_calls'] ?? []) as $i => $call) {
            if (is_array($call) && is_string($call['function']['name'] ?? null)) {
                $calls[] = ['id' => 'call_' . $i, 'name' => $call['function']['name'], 'arguments' => $args($call['function']['arguments'] ?? [])];
            }
        }
        $in = is_int($json['prompt_eval_count'] ?? null) ? $json['prompt_eval_count'] : null;
        $out = is_int($json['eval_count'] ?? null) ? $json['eval_count'] : null;
        $finish = is_string($json['done_reason'] ?? null) ? $json['done_reason'] : null;
    } else {
        $choice = $json['choices'][0] ?? null;
        if (!is_array($choice)) {
            throw new AiProviderException('invalid_json', 'پاسخ ارائه‌دهنده فاقد choices بود.');
        }
        $content = $choice['message']['content'] ?? '';
        if (is_array($content)) {
            // Some servers return content parts: [{"type":"text","text":"..."}].
            foreach ($content as $part) {
                if (is_array($part) && is_string($part['text'] ?? null)) $text .= $part['text'];
            }
        } elseif (is_string($content)) {
            $text = $content;
        }
        foreach (($choice['message']['tool_calls'] ?? []) as $i => $call) {
            if (is_array($call) && is_string($call['function']['name'] ?? null)) {
                $calls[] = ['id' => (string)($call['id'] ?? ('call_' . $i)), 'name' => $call['function']['name'], 'arguments' => $args($call['function']['arguments'] ?? [])];
            }
        }
        $in = is_int($json['usage']['prompt_tokens'] ?? null) ? $json['usage']['prompt_tokens'] : null;
        $out = is_int($json['usage']['completion_tokens'] ?? null) ? $json['usage']['completion_tokens'] : null;
        $finish = is_string($choice['finish_reason'] ?? null) ? $choice['finish_reason'] : null;
        // OpenRouter (and some gateways) report the actual price of the call, in USD.
        if (is_int($json['usage']['cost'] ?? null) || is_float($json['usage']['cost'] ?? null)) {
            $cost = max(0.0, (float)$json['usage']['cost']);
        }
    }
    // Reasoning models served through compatible servers (Hermes among them) can wrap their
    // reasoning in <think>…</think>; it is never part of the answer.
    $text = trim((string)preg_replace('#<think>.*?</think>#su', '', $text));
    if ($text === '' && $calls === []) {
        throw new AiProviderException('empty_response');
    }
    return ['text' => $text, 'input_tokens' => $in, 'output_tokens' => $out, 'finish_reason' => $finish, 'tool_calls' => $calls, 'cost' => $cost];
}

/** Pure: estimated cost in the model's currency, or null when a price is missing. */
function ai_cost_estimate(?int $inputTokens, ?int $outputTokens, ?string $inPerMillion, ?string $outPerMillion): ?float {
    if ($inputTokens === null || $outputTokens === null || $inPerMillion === null || $outPerMillion === null) {
        return null;
    }
    return ($inputTokens * (float)$inPerMillion + $outputTokens * (float)$outPerMillion) / 1_000_000;
}

/**
 * Pure: the cost to record — what the provider reported (USD) when the model is priced in USD, else
 * the estimate from the model's per-million prices.
 */
function ai_call_cost(?float $reported, ?int $inputTokens, ?int $outputTokens, array $model): ?float {
    if ($reported !== null && strtoupper((string)($model['cost_currency'] ?? 'USD')) === 'USD') {
        return $reported;
    }
    return ai_cost_estimate($inputTokens, $outputTokens, $model['input_cost_per_million'] ?? null, $model['output_cost_per_million'] ?? null);
}

/** Pure: OpenRouter's GET {base}/key (the key's own usage and limit, in USD). */
function ai_build_key_request(array $cfg): array {
    return ['method' => 'GET', 'url' => ai_join_url((string)$cfg['base_url'], 'key'), 'headers' => ai_request_headers($cfg, false), 'body' => null];
}

/**
 * Pure: OpenRouter's key response -> usage so far, the key's limit and what is left of it (USD; a
 * null limit means the key has none and spends the account's credit).
 * @return array{usage:?float, limit:?float, remaining:?float, free_tier:bool}
 */
function ai_parse_key_info(array $json): array {
    $d = is_array($json['data'] ?? null) ? $json['data'] : [];
    $num = static fn(mixed $v): ?float => is_int($v) || is_float($v) ? (float)$v : null;
    return ['usage' => $num($d['usage'] ?? null), 'limit' => $num($d['limit'] ?? null),
            'remaining' => $num($d['limit_remaining'] ?? null), 'free_tier' => ($d['is_free_tier'] ?? false) === true];
}

/* ==========================================================================
   Network
   ========================================================================== */

/**
 * Applies the outbound policy to $url.
 * @return array{ok:bool, reason?:string, host?:string, port?:int, ip?:?string}
 */
function ai_check_url_policy(string $url, bool $allowPrivate): array {
    $parts = parse_url($url);
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    if ($parts === false || empty($parts['host']) || !in_array($scheme, ['http', 'https'], true) || isset($parts['user'])) {
        return ['ok' => false, 'reason' => 'url_invalid'];
    }
    $host = strtolower(trim((string)$parts['host'], '[]'));
    $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
    if ($allowPrivate) {
        return ['ok' => true, 'host' => $host, 'port' => $port, 'ip' => null];
    }
    if ($scheme !== 'https') {
        return ['ok' => false, 'reason' => 'https_required'];
    }
    $target = number_forward_resolve_target($url);
    if (!$target['ok']) {
        return ['ok' => false, 'reason' => $target['reason'] === 'dns_resolution_failed' ? 'dns_failed'
            : ($target['reason'] === 'url_invalid' ? 'url_invalid' : 'blocked_private')];
    }
    return ['ok' => true, 'host' => $target['host'], 'port' => $target['port'], 'ip' => $target['ip']];
}

/**
 * Pure: the error of a proxy URL, or null when it is usable. http(s):// and socks5(h):// with a host;
 * credentials may be in the URL (it is stored in the AI vault, never shown).
 */
function ai_proxy_error(string $url): ?string {
    $parts = parse_url($url);
    if ($parts === false || empty($parts['host']) || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https', 'socks5', 'socks5h'], true)) {
        return 'آدرس پراکسی باید به شکل http://host:port یا socks5://user:pass@host:port باشد.';
    }
    return null;
}

/** Pure: the proxy URL curl gets — socks5 becomes socks5h, so the proxy resolves the provider's name. */
function ai_proxy_for_curl(string $url): string {
    return preg_replace('#^socks5://#i', 'socks5h://', $url) ?? $url;
}

/**
 * Sends one request built by ai_build_*_request() under the outbound policy and returns the parsed
 * JSON body. Throws AiProviderException for every failure, with the secret scrubbed.
 *
 * Tests replace the transport through $GLOBALS['__ai_http_transport'] (callable(array $request,
 * array $cfg): array{status:int, body:string, errno:int}).
 *
 * @return array{json: array, status: int, latency_ms: int}
 */
function ai_http_send(array $request, array $cfg): array {
    $secret = isset($cfg['api_key']) ? (string)$cfg['api_key'] : null;
    $started = microtime(true);

    $transport = $GLOBALS['__ai_http_transport'] ?? null;
    if (is_callable($transport)) {
        $raw = $transport($request, $cfg);
    } elseif (!empty($cfg['proxy'])) {
        // Through a proxy (a relay outside the country, for providers that refuse local addresses):
        // the proxy resolves and connects, so there is no address here to check or pin. The admin
        // who set the proxy chose where traffic goes; the scheme rule still applies.
        $scheme = strtolower((string)parse_url($request['url'], PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) throw new AiProviderException('url_invalid');
        if ($scheme !== 'https' && empty($cfg['allow_private_network'])) throw new AiProviderException('https_required');
        $raw = ai_http_curl($request, $cfg, ['ip' => null, 'proxy' => ai_proxy_for_curl((string)$cfg['proxy'])]);
    } else {
        $policy = ai_check_url_policy($request['url'], !empty($cfg['allow_private_network']));
        if (!$policy['ok']) {
            throw new AiProviderException($policy['reason']);
        }
        $raw = ai_http_curl($request, $cfg, $policy);
    }
    $latency = (int)round((microtime(true) - $started) * 1000);

    $errno = (int)($raw['errno'] ?? 0);
    if ($errno !== 0) {
        $code = match (true) {
            $errno === CURLE_OPERATION_TIMEDOUT                             => 'timeout',
            $errno === CURLE_COULDNT_RESOLVE_HOST                           => 'dns_failed',
            $errno === CURLE_WRITE_ERROR                                    => 'response_too_large',
            in_array($errno, NUMBER_FORWARD_TLS_ERRNOS, true)              => 'tls_error',
            default                                                         => 'connection_failed',
        };
        throw new AiProviderException($code);
    }
    $status = (int)$raw['status'];
    $body = (string)$raw['body'];
    $json = json_decode($body, true);
    if ($status < 200 || $status > 299) {
        [$code, $message] = ai_classify_http_error($status, is_array($json) ? $json : null, $body, $secret);
        throw new AiProviderException($code, $message !== '' ? ai_error_label($code) . ' — ' . $message : '', $status);
    }
    if (!is_array($json)) {
        throw new AiProviderException('invalid_json', '', $status);
    }
    return ['json' => $json, 'status' => $status, 'latency_ms' => $latency];
}

/** The real transport: one curl call, pinned to the checked address, no redirects, capped body. */
function ai_http_curl(array $request, array $cfg, array $policy): array {
    $body = '';
    $tooLarge = false;
    $ch = curl_init($request['url']);
    $options = [
        CURLOPT_CUSTOMREQUEST  => $request['method'],
        CURLOPT_HTTPHEADER     => $request['headers'],
        CURLOPT_CONNECTTIMEOUT_MS => max(500, (int)($cfg['connect_timeout_ms'] ?? 5000)),
        CURLOPT_TIMEOUT_MS     => max(1000, (int)($cfg['request_timeout_ms'] ?? 60000)),
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_NOSIGNAL       => true,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_WRITEFUNCTION  => static function ($ch, string $chunk) use (&$body, &$tooLarge): int {
            if (strlen($body) + strlen($chunk) > AI_MAX_RESPONSE_BYTES) {
                $tooLarge = true;
                return 0; // aborts the transfer with CURLE_WRITE_ERROR
            }
            $body .= $chunk;
            return strlen($chunk);
        },
    ];
    if ($request['body'] !== null) {
        $options[CURLOPT_POSTFIELDS] = $request['body'];
    }
    if (($policy['proxy'] ?? null) !== null) {
        $options[CURLOPT_PROXY] = $policy['proxy'];
        // The admin's proxy wins over a NO_PROXY in the container's environment.
        $options[CURLOPT_NOPROXY] = '';
    }
    if (($policy['ip'] ?? null) !== null) {
        $pin = str_contains((string)$policy['ip'], ':') ? '[' . $policy['ip'] . ']' : $policy['ip'];
        $options[CURLOPT_RESOLVE] = [$policy['host'] . ':' . $policy['port'] . ':' . $pin];
    }
    curl_setopt_array($ch, $options);
    curl_exec($ch);
    $errno = curl_errno($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($tooLarge) {
        $errno = CURLE_WRITE_ERROR;
    }
    return ['status' => $status, 'body' => $body, 'errno' => $errno];
}

/* ==========================================================================
   High-level calls (network, no database)
   ========================================================================== */

/**
 * Lists the provider's models, following Anthropic's pages.
 * @return array{models: list<array>, truncated: bool, latency_ms: int}
 */
function ai_list_models(array $cfg): array {
    $all = [];
    $after = null;
    $latency = 0;
    $truncated = false;
    for ($page = 0; $page < 20; $page++) {
        $response = ai_http_send(ai_build_models_request($cfg, $after), $cfg);
        $latency += $response['latency_ms'];
        $parsed = ai_parse_models((string)$cfg['type'], $response['json']);
        foreach ($parsed['models'] as $model) {
            if (count($all) >= AI_MAX_DISCOVERED_MODELS) {
                $truncated = true;
                break 2;
            }
            $all[$model['id']] = $model;
        }
        if ($parsed['next'] === null) break;
        $after = $parsed['next'];
    }
    return ['models' => array_values($all), 'truncated' => $truncated, 'latency_ms' => $latency];
}

/**
 * OpenRouter only: the key's usage and limit, or null when the provider is not OpenRouter or does
 * not answer (it never stops a connection test).
 */
function ai_key_info(array $cfg): ?array {
    if (($cfg['type'] ?? '') !== 'openrouter') return null;
    try {
        return ai_parse_key_info(ai_http_send(ai_build_key_request($cfg), $cfg)['json']);
    } catch (AiProviderException) {
        return null;
    }
}

/** Pure: a one-line Persian summary of ai_key_info() for the connection test. */
function ai_key_info_text(array $info): string {
    $usd = static fn(float $v): string => '$' . rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.');
    $parts = [];
    if ($info['usage'] !== null) $parts[] = 'مصرف این کلید: ' . $usd($info['usage']);
    if ($info['remaining'] !== null) {
        $parts[] = 'باقی‌مانده‌ی سقف کلید: ' . $usd($info['remaining']) . ($info['limit'] !== null ? ' از ' . $usd($info['limit']) : '');
    } elseif ($info['limit'] === null) {
        $parts[] = 'کلید سقف ندارد و از اعتبار حساب OpenRouter خرج می‌کند';
    }
    if ($info['free_tier']) $parts[] = 'حساب رایگان (فقط مدل‌های :free و با محدودیت تعداد)';
    return implode('؛ ', $parts);
}

/**
 * One chat completion.
 * @return array{text:string, input_tokens:?int, output_tokens:?int, finish_reason:?string, tool_calls:list<array>, cost:?float, latency_ms:int}
 */
function ai_chat(array $cfg, string $model, array $messages, int $maxTokens, ?float $temperature = null, ?array $tools = null): array {
    $response = ai_http_send(ai_build_chat_request($cfg, $model, $messages, $maxTokens, $temperature, $tools), $cfg);
    return ai_parse_chat((string)$cfg['type'], $response['json']) + ['latency_ms' => $response['latency_ms']];
}
