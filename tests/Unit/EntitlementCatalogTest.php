<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Phase 13 (STEP 3/5): the entitlement and limit catalogs fail closed for anything not explicitly
 * listed — this is the check standing between "a typo in a controller or a crafted plan row" and
 * that string silently being honored as a real capability.
 */
final class EntitlementCatalogTest extends TestCase
{
    public function testEntitlementIsValidAcceptsCatalogedKeys(): void
    {
        foreach (\Entitlements::all() as $key) {
            $this->assertTrue(\Entitlements::isValid($key));
        }
    }

    public function testEntitlementIsValidRejectsUnknownKey(): void
    {
        $this->assertFalse(\Entitlements::isValid('platform_admin'));
        $this->assertFalse(\Entitlements::isValid('public_api_extra'));
        $this->assertFalse(\Entitlements::isValid(''));
    }

    public function testLimitIsValidAcceptsCatalogedKeys(): void
    {
        foreach (\Limits::all() as $key) {
            $this->assertTrue(\Limits::isValid($key));
        }
    }

    public function testLimitIsValidRejectsUnknownKey(): void
    {
        $this->assertFalse(\Limits::isValid('unlimited_everything'));
        $this->assertFalse(\Limits::isValid(''));
    }

    public function testMeterKeysHaveResetPeriodsAndResourceKeysDoNot(): void
    {
        $this->assertSame('monthly', \Limits::resetPeriod(\Limits::MONTHLY_MESSAGES));
        $this->assertSame('daily', \Limits::resetPeriod(\Limits::DAILY_MESSAGES));
        $this->assertSame('never', \Limits::resetPeriod(\Limits::API_KEYS));
        $this->assertTrue(\Limits::isMeter(\Limits::MONTHLY_MESSAGES));
        $this->assertFalse(\Limits::isMeter(\Limits::API_KEYS));
    }

    public function testUnknownLimitResetPeriodDefaultsToTheStricterReading(): void
    {
        // 'never' is stricter than a resetting period — a limit that never replenishes on its own.
        $this->assertSame('never', \Limits::resetPeriod('made_up_key'));
    }

    public function testEveryResourceCountLimitHasAResourceSource(): void
    {
        // Any limit that is neither a meter nor a per-request cap MUST be countable, or
        // entitlement_with_resource_slot() could never enforce it.
        $perRequest = [\Limits::BULK_ITEMS_PER_JOB, \Limits::API_REQUESTS_PER_MINUTE];
        foreach (\Limits::all() as $key) {
            if (\Limits::isMeter($key) || in_array($key, $perRequest, true)) {
                continue;
            }
            $source = \Limits::resourceSource($key);
            $this->assertIsArray($source, "resource limit '{$key}' has no resourceSource() mapping — it could never be enforced");
            $this->assertCount(3, $source);
            $this->assertStringStartsWith('ellsms_', $source[0], 'a resource limit must count from an ELLSMS-owned table, never a backend-owned one');
        }
    }

    public function testResourceSourceReturnsNullForMetersAndPerRequestCaps(): void
    {
        $this->assertNull(\Limits::resourceSource(\Limits::MONTHLY_MESSAGES));
        $this->assertNull(\Limits::resourceSource(\Limits::BULK_ITEMS_PER_JOB));
    }

    public function testCatalogsAreDisjointFromRbacPermissions(): void
    {
        // Invariant N: entitlements and permissions are separate layers. A shared string would make
        // it far too easy to accidentally check one where the other was meant.
        $overlap = array_intersect(\Entitlements::all(), \Permissions::all());
        $this->assertSame([], $overlap, 'an entitlement key must never collide with an RBAC permission string');
    }

    public function testLabelsExistForEveryCatalogedKey(): void
    {
        foreach (\Entitlements::all() as $key) {
            $this->assertNotSame($key, \Entitlements::label($key), "entitlement '{$key}' has no human label");
        }
        foreach (\Limits::all() as $key) {
            $this->assertNotSame($key, \Limits::label($key), "limit '{$key}' has no human label");
        }
    }
}
