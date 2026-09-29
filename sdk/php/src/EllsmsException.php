<?php

declare(strict_types=1);

namespace Ellsms;

/**
 * Every failure the client reports: an API error answer (4xx/5xx with the ELLSMS error body) or a
 * transport failure (status 0, code "network_error").
 */
class EllsmsException extends \RuntimeException
{
    /** @var int HTTP status; 0 when no answer arrived */
    private $status;
    /** @var string ELLSMS error code, e.g. "validation_failed", "not_found", "rate_limited" */
    private $errorCode;
    /** @var array<string, list<string>> field => reasons, for validation errors */
    private $fields;
    /** @var string|null */
    private $requestId;
    /** @var int|null seconds, from Retry-After on 429 */
    private $retryAfter;

    public function __construct(int $status, string $errorCode, string $message, array $fields = [], ?string $requestId = null, ?int $retryAfter = null)
    {
        parent::__construct($message, $status);
        $this->status = $status;
        $this->errorCode = $errorCode;
        $this->fields = $fields;
        $this->requestId = $requestId;
        $this->retryAfter = $retryAfter;
    }

    public function getStatus(): int { return $this->status; }
    public function getErrorCode(): string { return $this->errorCode; }
    public function getFields(): array { return $this->fields; }
    public function getRequestId(): ?string { return $this->requestId; }
    public function getRetryAfter(): ?int { return $this->retryAfter; }
}
