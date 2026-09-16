# Provider response normalization

Issue #18 makes provider-response handling a platform invariant instead of an optimistic side effect of admin configuration.

## Contract

Every send-provider response is normalized to one of three outcomes:

- `SUCCESS`: the provider returned an acceptable HTTP/JSON response and ELLSMS has a valid provider message reference for the message. Batch requests are accepted at envelope level only; every recipient is still required to have its own valid provider reference before that recipient is marked sent.
- `FAILED`: the provider explicitly rejected the request/message, returned a malformed/unknown 2xx response, returned a non-2xx response, or returned a missing/invalid provider message id.
- `UNKNOWN`: reserved for genuine transport ambiguity where no provider response was received (for example a timeout/connection failure after submission may have reached the provider). ELLSMS does not reinterpret this as success.

A negative ID-like token such as `-103` is never a successful provider reference. It is preserved as `provider_error_code` and normalized to `FAILED`.

## Built-in defaults and admin overrides

The transport always runs built-in parsing. Common response locations such as `message_id`, `messageId`, `provider_message_id`, `referenceId` and their `data.*` / `result.*` forms are recognized without any admin mapping. Common explicit failure signals (`success=false`, `ok=false`, failure status values, error objects, negative reference sentinels) are also recognized by default.

Admin configuration is an override layer, not the only parser:

- a configured provider-message-id path is tried first;
- a configured provider-error-code path and error-class map can reclassify a known provider error;
- a configured success rule is a positive override only;
- missing, stale or incomplete override paths fall back to the built-in parser;
- no override can make a missing, malformed or negative provider reference successful.

This means an incomplete admin mapping can reduce convenience, but cannot silently turn the provider adapter off or make malformed provider data look successful.

## Persistence and observability

Each provider HTTP attempt is written best-effort to `ellsms_provider_response_audit` with:

- gateway and connector (`send` / `status`);
- request id and HTTP status;
- normalized outcome;
- provider message id when valid;
- provider error code/detail when present;
- raw provider response.

Only the **response** is persisted here; request bodies, API keys and credentials are not. `provider_error_detail` is bounded to 500 characters and `raw_response` to 65,535 characters before insertion. Audit persistence failures are logged/metriced but never change the delivery outcome of the provider call.

The normal transport logs include `normalized_outcome` but not the raw provider response.

## Safety rules

1. HTTP 2xx is not sufficient for success.
2. A per-message send is not successful without a valid provider message id.
3. A batch recipient is not marked sent without its own valid provider message id.
4. Unknown/malformed responses fail closed and never crash the send worker.
5. Network ambiguity is `UNKNOWN`, not guessed as `FAILED` or `SUCCESS`.
6. Automatic retry policy is unchanged by this feature; normalization reports facts, while retry remains controlled by the existing platform policy.
