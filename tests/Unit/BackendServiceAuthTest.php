<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * backend_service_auth_headers() (app/Backend/ApiClient.php) — the client-side half of Phase 2/8's
 * service-to-service authentication. There is no backend verifier in this repo to test against
 * (BACKEND VERIFIER STATUS: PARTIAL, per docs/service-boundaries.md), so these tests only prove
 * the ELLSMS-side contract: headers are absent (unchanged behavior) when the feature is
 * unconfigured; present/well-formed/verifiable when it is; and every signed component (method,
 * path, timestamp, body) actually changes the signature when tampered with, which is the
 * property any real backend-side verifier would depend on.
 */
final class BackendServiceAuthTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('BACKEND_SERVICE_ID');
        putenv('BACKEND_SERVICE_SECRET');
    }

    public function testNoHeadersWhenServiceCredentialsAreNotConfigured(): void
    {
        putenv('BACKEND_SERVICE_ID');
        putenv('BACKEND_SERVICE_SECRET');
        $this->assertSame([], backend_service_auth_headers('POST', '/api/messages/send', '{"x":1}', 'req-1'));
    }

    public function testNoHeadersWhenOnlyServiceIdIsConfigured(): void
    {
        putenv('BACKEND_SERVICE_ID=svc-1');
        putenv('BACKEND_SERVICE_SECRET');
        $this->assertSame([], backend_service_auth_headers('POST', '/api/messages/send', '{"x":1}', 'req-1'));
    }

    public function testNoHeadersWhenOnlySecretIsConfigured(): void
    {
        putenv('BACKEND_SERVICE_ID');
        putenv('BACKEND_SERVICE_SECRET=topsecret');
        $this->assertSame([], backend_service_auth_headers('POST', '/api/messages/send', '{"x":1}', 'req-1'));
    }

    public function testHeadersArePresentAndWellFormedWhenConfigured(): void
    {
        putenv('BACKEND_SERVICE_ID=svc-1');
        putenv('BACKEND_SERVICE_SECRET=topsecret');

        $headers = backend_service_auth_headers('POST', '/api/messages/send', '{"x":1}', 'req-1');

        $this->assertCount(4, $headers);
        $names = array_map(static fn ($h) => explode(':', $h, 2)[0], $headers);
        $this->assertSame(['X-Ellsms-Service-Id', 'X-Ellsms-Timestamp', 'X-Ellsms-Request-Id', 'X-Ellsms-Signature'], $names);
    }

    public function testSignatureIsAValidHmacOfMethodPathTimestampBodyHashAndServiceId(): void
    {
        putenv('BACKEND_SERVICE_ID=svc-1');
        putenv('BACKEND_SERVICE_SECRET=topsecret');
        $method = 'POST';
        $path = '/api/messages/send';
        $body = '{"to":"09120000000","text":"hi"}';

        $headers = backend_service_auth_headers($method, $path, $body, 'req-1');
        $values = [];
        foreach ($headers as $h) {
            [$name, $value] = explode(': ', $h, 2);
            $values[$name] = $value;
        }

        $signingString = implode("\n", [$method, $path, $values['X-Ellsms-Timestamp'], hash('sha256', $body), 'svc-1']);
        $expected = hash_hmac('sha256', $signingString, 'topsecret');
        $this->assertSame($expected, $values['X-Ellsms-Signature']);
    }

    private function signatureOf(array $headers): string {
        foreach ($headers as $h) {
            if (str_starts_with($h, 'X-Ellsms-Signature: ')) {
                return substr($h, strlen('X-Ellsms-Signature: '));
            }
        }
        $this->fail('X-Ellsms-Signature header missing');
    }

    public function testSignatureChangesIfBodyIsTampered(): void
    {
        putenv('BACKEND_SERVICE_ID=svc-1');
        putenv('BACKEND_SERVICE_SECRET=topsecret');

        $sigOriginal = $this->signatureOf(backend_service_auth_headers('POST', '/api/messages/send', 'original-body', 'req-1'));
        $sigTampered = $this->signatureOf(backend_service_auth_headers('POST', '/api/messages/send', 'tampered-body', 'req-1'));

        $this->assertNotSame($sigOriginal, $sigTampered, 'the body is part of the signed content — a tampered body must produce a different signature');
    }

    public function testSignatureChangesIfMethodDiffers(): void
    {
        putenv('BACKEND_SERVICE_ID=svc-1');
        putenv('BACKEND_SERVICE_SECRET=topsecret');

        $sigGet  = $this->signatureOf(backend_service_auth_headers('GET', '/api/messages/send', '{"x":1}', 'req-1'));
        $sigPost = $this->signatureOf(backend_service_auth_headers('POST', '/api/messages/send', '{"x":1}', 'req-1'));

        $this->assertNotSame($sigGet, $sigPost, 'the HTTP method is bound into the signature — a captured signature must not be replayable against a different method');
    }

    public function testSignatureChangesIfPathDiffers(): void
    {
        putenv('BACKEND_SERVICE_ID=svc-1');
        putenv('BACKEND_SERVICE_SECRET=topsecret');

        $sigSend    = $this->signatureOf(backend_service_auth_headers('POST', '/api/messages/send', '{"x":1}', 'req-1'));
        $sigAccount = $this->signatureOf(backend_service_auth_headers('POST', '/api/accounts/create', '{"x":1}', 'req-1'));

        $this->assertNotSame($sigSend, $sigAccount, 'the request path is bound into the signature — a captured signature must not be replayable against a different endpoint');
    }

    public function testDifferentSecretsProduceDifferentSignaturesForTheSameRequest(): void
    {
        putenv('BACKEND_SERVICE_ID=svc-1');
        putenv('BACKEND_SERVICE_SECRET=secret-a');
        $sigA = $this->signatureOf(backend_service_auth_headers('POST', '/api/messages/send', '{"x":1}', 'req-1'));

        putenv('BACKEND_SERVICE_SECRET=secret-b');
        $sigB = $this->signatureOf(backend_service_auth_headers('POST', '/api/messages/send', '{"x":1}', 'req-1'));

        $this->assertNotSame($sigA, $sigB, 'a verifier checking with the wrong secret must never accept a signature produced with the right one');
    }

    /**
     * The request id is carried as its own header for log correlation (Logger::currentRequestId())
     * but is deliberately NOT one of the components hashed into the signature (see the
     * $signingString construction in app/Backend/ApiClient.php) — it identifies the request for
     * tracing, it is not a signed anti-replay nonce. Documented here so that fact is a proven,
     * checked property of the contract, not just a comment someone could silently invalidate.
     */
    public function testRequestIdIsCarriedButDoesNotAffectTheSignature(): void
    {
        putenv('BACKEND_SERVICE_ID=svc-1');
        putenv('BACKEND_SERVICE_SECRET=topsecret');

        $sigReqA = $this->signatureOf(backend_service_auth_headers('POST', '/api/messages/send', '{"x":1}', 'req-a'));
        $sigReqB = $this->signatureOf(backend_service_auth_headers('POST', '/api/messages/send', '{"x":1}', 'req-b'));

        $this->assertSame($sigReqA, $sigReqB, 'the request id is for correlation only — it must not be part of the signed content');
    }
}
