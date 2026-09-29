<?php

declare(strict_types=1);

namespace Ellsms;

/**
 * ELLSMS public API v1 client. PHP 7.4+, needs only ext-curl and ext-json.
 *
 *   $sms = new Ellsms\Client('ellsms_live_…', 'https://panel.example.com');
 *   $sms->sendMessage(['09121234567'], 'سلام');
 *
 * Every method returns the "data" part of the answer as an array and throws EllsmsException on any error.
 * Rate-limited (429) answers and transport/5xx failures of SAFE requests (GET, or a POST carrying an
 * idempotency key) are retried up to $maxRetries times; nothing that could send twice is ever retried.
 */
final class Client
{
    public const VERSION = '1.0.0';

    /** @var string */
    private $apiKey;
    /** @var string */
    private $baseUrl;
    /** @var int */
    private $timeout;
    /** @var int */
    private $maxRetries;
    /** @var callable|null test hook: fn(int $seconds) */
    private $sleeper;

    public function __construct(string $apiKey, string $baseUrl, int $timeout = 30, int $maxRetries = 2)
    {
        if (trim($apiKey) === '') {
            throw new \InvalidArgumentException('An API key is required.');
        }
        $this->apiKey = trim($apiKey);
        $this->baseUrl = rtrim($baseUrl, '/');
        if (substr($this->baseUrl, -7) !== '/api/v1') {
            $this->baseUrl .= '/api/v1';
        }
        $this->timeout = max(1, $timeout);
        $this->maxRetries = max(0, $maxRetries);
    }

    /** @internal for tests */
    public function setSleeper(callable $sleeper): void { $this->sleeper = $sleeper; }

    /* ---------------- account ---------------- */

    public function me(): array { return $this->request('GET', '/me'); }
    public function organization(): array { return $this->request('GET', '/organization'); }
    /** @return array{available:int, reserved:int, total:int, unit:string} */
    public function balance(): array { return $this->request('GET', '/balance'); }

    /* ---------------- messages ---------------- */

    /**
     * Sends one text to up to 100 numbers, synchronously.
     *
     * @param list<string> $destinations
     * @param array{originator?:string, channel?:string, client_message_id?:string} $options
     *        client_message_id makes a retry within 24 hours safe (no second send).
     */
    public function sendMessage(array $destinations, string $content, array $options = []): array
    {
        $body = ['destinations' => array_values($destinations), 'content' => $content] + $options;
        // With a client_message_id the server replays instead of re-sending, so a retry is safe.
        return $this->request('POST', '/messages', $body, null, isset($options['client_message_id']));
    }

    public function getMessage(string $id): array { return $this->request('GET', '/messages/' . rawurlencode($id)); }

    /** Cost estimate for the same payload as sendMessage(); sends nothing. */
    public function previewMessage(array $destinations, string $content, array $options = []): array
    {
        return $this->request('POST', '/messages/preview', ['destinations' => array_values($destinations), 'content' => $content] + $options, null, true);
    }

    /* ---------------- bulk jobs ---------------- */

    /**
     * Queues a bulk job. $job: type (p2p|smart|gradual), title, originator, items [{mobile, content}],
     * and for gradual throttle_count + throttle_minutes. An idempotency key is required by the API;
     * one is generated when you pass none — pass your own to make retries across processes safe.
     */
    public function createBulkJob(array $job, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', '/bulk-jobs', $job, $idempotencyKey ?? self::newIdempotencyKey());
    }

    public function getBulkJob(string $id): array { return $this->request('GET', '/bulk-jobs/' . rawurlencode($id)); }
    public function previewBulkJob(array $job): array { return $this->request('POST', '/bulk-jobs/preview', $job, null, true); }

    /* ---------------- contacts ---------------- */

    /** One page: ['data' => [...], 'next_cursor' => ?string]. */
    public function listContacts(int $limit = 50, ?string $after = null): array
    {
        $query = ['limit' => $limit] + ($after !== null ? ['after' => $after] : []);
        $answer = $this->requestFull('GET', '/contacts?' . http_build_query($query));
        return ['data' => $answer['data'] ?? [], 'next_cursor' => $answer['meta']['next_cursor'] ?? null];
    }

    /** Every contact, page by page, without loading them all at once. */
    public function eachContact(int $pageSize = 200): \Generator
    {
        $after = null;
        do {
            $page = $this->listContacts($pageSize, $after);
            foreach ($page['data'] as $contact) {
                yield $contact;
            }
            $after = $page['next_cursor'];
        } while ($after !== null);
    }

    /** @param array{mobile:string, name?:string, group?:string} $contact */
    public function createContact(array $contact): array { return $this->request('POST', '/contacts', $contact); }
    public function getContact(string $id): array { return $this->request('GET', '/contacts/' . rawurlencode($id)); }
    public function updateContact(string $id, array $changes): array { return $this->request('PATCH', '/contacts/' . rawurlencode($id), $changes); }
    public function deleteContact(string $id): void { $this->request('DELETE', '/contacts/' . rawurlencode($id)); }

    /* ---------------- webhooks ---------------- */

    public function listWebhooks(): array { return $this->request('GET', '/webhooks'); }
    /** Returns ['id' => …, 'secret' => …] — the secret is shown only this once. */
    public function createWebhook(string $url, array $eventTypes, string $description = ''): array
    {
        return $this->request('POST', '/webhooks', ['url' => $url, 'event_types' => array_values($eventTypes), 'description' => $description]);
    }
    public function getWebhook(string $id): array { return $this->request('GET', '/webhooks/' . rawurlencode($id)); }
    public function updateWebhook(string $id, array $changes): array { return $this->request('PATCH', '/webhooks/' . rawurlencode($id), $changes); }
    public function deleteWebhook(string $id): void { $this->request('DELETE', '/webhooks/' . rawurlencode($id)); }
    public function rotateWebhookSecret(string $id): array { return $this->request('POST', '/webhooks/' . rawurlencode($id) . '/rotate-secret', []); }
    public function testWebhook(string $id): array { return $this->request('POST', '/webhooks/' . rawurlencode($id) . '/test', []); }

    /* ---------------- transport ---------------- */

    public static function newIdempotencyKey(): string
    {
        return 'sdk-php-' . bin2hex(random_bytes(16));
    }

    private function request(string $method, string $path, ?array $body = null, ?string $idempotencyKey = null, bool $replaySafe = false)
    {
        $answer = $this->requestFull($method, $path, $body, $idempotencyKey, $replaySafe);
        return $answer['data'] ?? [];
    }

    private function requestFull(string $method, string $path, ?array $body = null, ?string $idempotencyKey = null, bool $replaySafe = false): array
    {
        $retrySafe = $method === 'GET' || $idempotencyKey !== null || $replaySafe;
        $attempt = 0;
        while (true) {
            $attempt++;
            try {
                return $this->once($method, $path, $body, $idempotencyKey);
            } catch (EllsmsException $e) {
                $retryable = $e->getStatus() === 429 || ($retrySafe && ($e->getStatus() === 0 || $e->getStatus() >= 500));
                if (!$retryable || $attempt > $this->maxRetries) {
                    throw $e;
                }
                $wait = $e->getRetryAfter() ?? min(8, 2 ** ($attempt - 1));
                $this->sleeper !== null ? ($this->sleeper)($wait) : sleep(max(0, min(60, $wait)));
            }
        }
    }

    private function once(string $method, string $path, ?array $body, ?string $idempotencyKey): array
    {
        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Accept: application/json',
            'User-Agent: ellsms-php/' . self::VERSION,
        ];
        if ($idempotencyKey !== null) {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }
        $ch = curl_init($this->baseUrl . $path);
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_FOLLOWLOCATION => false,
        ];
        if ($body !== null && $method !== 'GET' && $method !== 'DELETE') {
            $headers[] = 'Content-Type: application/json';
            $options[CURLOPT_POSTFIELDS] = json_encode($body === [] ? new \stdClass() : $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $options[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $options);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $status === 0) {
            throw new EllsmsException(0, 'network_error', 'Could not reach ELLSMS: ' . $curlError);
        }
        $rawHeaders = substr((string)$raw, 0, $headerSize);
        $rawBody = substr((string)$raw, $headerSize);
        $decoded = $rawBody === '' ? [] : json_decode($rawBody, true);

        if ($status >= 200 && $status < 300) {
            return is_array($decoded) ? $decoded : [];
        }
        $error = is_array($decoded) && is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
        $retryAfter = preg_match('/^Retry-After:\s*(\d+)/mi', $rawHeaders, $m) ? (int)$m[1] : null;
        $requestId = $error['request_id'] ?? (preg_match('/^X-Request-Id:\s*(\S+)/mi', $rawHeaders, $m) ? $m[1] : null);
        throw new EllsmsException(
            $status,
            (string)($error['code'] ?? 'http_' . $status),
            (string)($error['message'] ?? 'HTTP ' . $status),
            is_array($error['fields'] ?? null) ? $error['fields'] : [],
            $requestId,
            $retryAfter
        );
    }
}
