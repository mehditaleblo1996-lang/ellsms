<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Phase 12 (STEP 10/11): the API scope catalog fails closed for anything not explicitly listed —
 * this is the ONE check standing between "an operator/attacker crafts a POST with a made-up scope
 * string" and that scope silently being accepted onto a key.
 */
final class ApiScopesCatalogTest extends TestCase
{
    public function testIsValidAcceptsCatalogedScopes(): void
    {
        foreach (\ApiScopes::all() as $scope) {
            $this->assertTrue(\ApiScopes::isValid($scope));
        }
    }

    public function testIsValidRejectsUnknownScope(): void
    {
        $this->assertFalse(\ApiScopes::isValid('platform:admin'));
        $this->assertFalse(\ApiScopes::isValid('messages:send_extra'));
        $this->assertFalse(\ApiScopes::isValid(''));
    }

    public function testNormalizeRejectsEmptySet(): void
    {
        $this->assertNull(\ApiScopes::normalize([]));
    }

    public function testNormalizeRejectsAnyUnknownScopeInTheWholeRequest(): void
    {
        // One bad entry invalidates the WHOLE request rather than silently dropping it (STEP 11:
        // "unknown scope rejected", not "unknown scope ignored").
        $this->assertNull(\ApiScopes::normalize([\ApiScopes::MESSAGES_SEND, 'platform:admin']));
    }

    public function testNormalizeDedupesValidScopes(): void
    {
        $result = \ApiScopes::normalize([\ApiScopes::MESSAGES_SEND, \ApiScopes::MESSAGES_SEND, \ApiScopes::CONTACTS_READ]);
        $this->assertNotNull($result);
        $this->assertCount(2, $result);
        $this->assertContains(\ApiScopes::MESSAGES_SEND, $result);
        $this->assertContains(\ApiScopes::CONTACTS_READ, $result);
    }

    public function testNormalizeRejectsNonStringEntries(): void
    {
        $this->assertNull(\ApiScopes::normalize([123, \ApiScopes::MESSAGES_SEND]));
    }
}
