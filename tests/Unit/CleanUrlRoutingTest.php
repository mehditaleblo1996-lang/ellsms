<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CleanUrlRoutingTest extends TestCase
{
    private string $config;

    protected function setUp(): void
    {
        $path = dirname(__DIR__, 2) . '/docker/apache-clean-urls.conf';
        self::assertFileExists($path);
        $this->config = (string)file_get_contents($path);
    }

    public function testCriticalCompatibilityContractsRemainPresent(): void
    {
        self::assertStringContainsString('RewriteRule ^health/ready/?$ health-ready.php [L]', $this->config);
        self::assertStringContainsString('RewriteRule ^health/?$ health.php [L]', $this->config);
        self::assertStringContainsString('RewriteRule ^api/v1(/.*)?$ api/index.php [QSA,L]', $this->config);
        self::assertStringNotContainsString('url_send.html$ /', $this->config);
    }

    public function testLegacyRedirectsAreLimitedToGetAndHead(): void
    {
        self::assertStringContainsString('RewriteCond %{REQUEST_METHOD} ^(?:GET|HEAD)$', $this->config);
        self::assertStringContainsString('THE_REQUEST', $this->config);
        self::assertStringNotContainsString('RewriteCond %{REQUEST_METHOD} ^(?:POST', $this->config);
    }

    public function testCriticalCleanRoutesRewriteToExistingEntrypoints(): void
    {
        $routes = [
            'RewriteRule ^$ landing.php [QSA,L]',
            'RewriteRule ^dashboard/?$ index.php [QSA,L]',
            'RewriteRule ^login/?$ login.php [QSA,L]',
            'RewriteRule ^register/?$ register.php [QSA,L]',
            'RewriteRule ^messages/new/?$ new-send.php [QSA,L]',
            'RewriteRule ^messages/reports/?$ reports.php [QSA,L]',
            'RewriteRule ^contacts/?$ contacts.php [QSA,L]',
            'RewriteRule ^billing/?$ billing.php [QSA,L]',
            'RewriteRule ^developers/api-keys/?$ api-keys.php [QSA,L]',
            'RewriteRule ^admin/users/?$ users.php [QSA,L]',
            'RewriteRule ^admin/registrations/?$ registration-requests.php [QSA,L]',
            'RewriteRule ^admin/settings/?$ settings.php [QSA,L]',
            'RewriteRule ^payments/zarinpal/callback/?$ zarinpal-callback.php [QSA,L]',
        ];

        foreach ($routes as $route) {
            self::assertStringContainsString($route, $this->config, $route);
        }
    }

    public function testEveryRootPhpPageIsCoveredOrExplicitlyStable(): void
    {
        $publicDir = dirname(__DIR__, 2) . '/public';
        $files = glob($publicDir . '/*.php') ?: [];

        $stable = [
            'health.php',
            'health-ready.php',
            'zarinpal-callback.php',
        ];

        $missing = [];
        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $stable, true)) {
                continue;
            }
            if (!str_contains($this->config, $name . ' [QSA,L]')) {
                $missing[] = $name;
            }
        }

        self::assertSame([], $missing, 'Root public PHP files missing a clean URL rewrite: ' . implode(', ', $missing));
    }

    public function testSensitiveProviderCallbackIsNotCanonicallyRedirected(): void
    {
        self::assertStringNotContainsString('THE_REQUEST} \\s/+zarinpal-callback\\.php', $this->config);
        self::assertStringContainsString('RewriteRule ^payments/zarinpal/callback/?$ zarinpal-callback.php [QSA,L]', $this->config);
    }
}
