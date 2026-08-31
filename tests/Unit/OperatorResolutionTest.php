<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * app/Sms/OperatorResolution.php — the operator-resolution strategy seam (issue #9, backlog).
 * MNP is explicitly not implemented; these tests prove the seam exists, defaults safely, and never
 * silently pretends to support MNP before a real implementation lands — without touching real DB
 * state, since the prefix strategy itself (app/Sms/Pricing.php's ellsms_sms_operator_prefixes
 * lookup) is already covered elsewhere and needs a database.
 */
final class OperatorResolutionTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('OPERATOR_RESOLUTION_STRATEGY');
    }

    public function testDefaultStrategyIsPrefix(): void
    {
        putenv('OPERATOR_RESOLUTION_STRATEGY');
        $this->assertSame(OPERATOR_RESOLUTION_STRATEGY_PREFIX, \operator_resolution_strategy());
    }

    public function testExplicitPrefixStrategyIsHonored(): void
    {
        putenv('OPERATOR_RESOLUTION_STRATEGY=prefix');
        $this->assertSame(OPERATOR_RESOLUTION_STRATEGY_PREFIX, \operator_resolution_strategy());
    }

    public function testRequestingMnpBeforeItExistsSafelyFallsBackToPrefix(): void
    {
        putenv('OPERATOR_RESOLUTION_STRATEGY=mnp');
        $this->assertSame(
            OPERATOR_RESOLUTION_STRATEGY_PREFIX,
            \operator_resolution_strategy(),
            'MNP is backlog (issue #9) -- requesting it before a real implementation exists must never be honored'
        );
    }

    public function testAnUnrecognizedStrategyValueFallsBackToPrefix(): void
    {
        putenv('OPERATOR_RESOLUTION_STRATEGY=some_future_typo');
        $this->assertSame(OPERATOR_RESOLUTION_STRATEGY_PREFIX, \operator_resolution_strategy());
    }

    public function testTheMnpStubIsUnreachableThroughTheRealDispatchPath(): void
    {
        // operator_resolve() must never actually call operator_resolve_via_mnp() today, precisely
        // because operator_resolution_strategy() never returns 'mnp' -- proven indirectly: even
        // with the env var set to 'mnp', operator_resolve() must not throw the stub's RuntimeException.
        putenv('OPERATOR_RESOLUTION_STRATEGY=mnp');
        $result = \operator_resolve('989121234567');
        $this->assertIsArray($result);
        $this->assertArrayHasKey('operator_source', $result);
    }

    public function testTheMnpStubItselfThrowsWhenCalledDirectly(): void
    {
        // Directly proves the stub is inert (issue #9: MNP is not implemented), not that it works.
        $this->expectException(\RuntimeException::class);
        \operator_resolve_via_mnp('989121234567');
    }

    public function testCacheTtlHasASaneConfigurableDefault(): void
    {
        putenv('OPERATOR_RESOLUTION_CACHE_TTL_SECONDS');
        $this->assertSame(300, \operator_resolution_cache_ttl_seconds());
        putenv('OPERATOR_RESOLUTION_CACHE_TTL_SECONDS=60');
        $this->assertSame(60, \operator_resolution_cache_ttl_seconds());
        putenv('OPERATOR_RESOLUTION_CACHE_TTL_SECONDS');
    }
}
