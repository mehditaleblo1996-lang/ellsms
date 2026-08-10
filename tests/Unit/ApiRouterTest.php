<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Api/Router.php';

/** Phase 12 (STEP 4): pure route-matching logic, independent of any HTTP server. */
final class ApiRouterTest extends TestCase
{
    private \Closure $handlerMe;
    private \Closure $handlerContactGet;
    private \Closure $handlerContactUpdate;

    protected function setUp(): void
    {
        $this->handlerMe = static fn() => 'me';
        $this->handlerContactGet = static fn() => 'contact_get';
        $this->handlerContactUpdate = static fn() => 'contact_update';
    }

    private function router(): \ApiRouter
    {
        $router = new \ApiRouter();
        $router->map('GET', '/api/v1/me', $this->handlerMe);
        $router->map('GET', '/api/v1/contacts/{id}', $this->handlerContactGet, 'contacts:read');
        $router->map('PATCH', '/api/v1/contacts/{id}', $this->handlerContactUpdate, 'contacts:write');
        return $router;
    }

    public function testMatchesAnExactStaticRoute(): void
    {
        $result = $this->router()->dispatch('GET', '/api/v1/me');
        $this->assertTrue($result['matched']);
        $this->assertSame($this->handlerMe, $result['handler']);
        $this->assertSame([], $result['params']);
    }

    public function testMatchesAndExtractsANamedParameter(): void
    {
        $result = $this->router()->dispatch('GET', '/api/v1/contacts/42');
        $this->assertTrue($result['matched']);
        $this->assertSame($this->handlerContactGet, $result['handler']);
        $this->assertSame(['id' => '42'], $result['params']);
        $this->assertSame('contacts:read', $result['scope']);
    }

    public function testDifferentMethodsOnTheSamePathRouteToDifferentHandlers(): void
    {
        $get = $this->router()->dispatch('GET', '/api/v1/contacts/7');
        $patch = $this->router()->dispatch('PATCH', '/api/v1/contacts/7');
        $this->assertSame($this->handlerContactGet, $get['handler']);
        $this->assertSame($this->handlerContactUpdate, $patch['handler']);
        $this->assertSame('contacts:write', $patch['scope']);
    }

    public function testUnknownPathDoesNotMatch(): void
    {
        $result = $this->router()->dispatch('GET', '/api/v1/nonexistent');
        $this->assertFalse($result['matched']);
        $this->assertFalse($result['method_not_allowed']);
    }

    public function testKnownPathWrongMethodReportsMethodNotAllowed(): void
    {
        $result = $this->router()->dispatch('DELETE', '/api/v1/me');
        $this->assertFalse($result['matched']);
        $this->assertTrue($result['method_not_allowed']);
    }

    public function testRouteWithNoScopeReturnsNullScope(): void
    {
        $result = $this->router()->dispatch('GET', '/api/v1/me');
        $this->assertNull($result['scope']);
    }

    public function testPathParameterCannotSpanASegmentBoundary(): void
    {
        // /api/v1/contacts/{id} must not match a path with an extra segment after the id.
        $result = $this->router()->dispatch('GET', '/api/v1/contacts/42/extra');
        $this->assertFalse($result['matched']);
    }
}
