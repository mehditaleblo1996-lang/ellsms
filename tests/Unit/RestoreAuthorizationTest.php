<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Phase 11, STEP 36: restore (and backup) authorization is CLI-only by construction -- no
 * public/*.php page may reference backup/restore functionality, ever. A static scan, not a
 * runtime permission check, because there is deliberately no admin-panel "restore" button to gate
 * in the first place -- this test's job is making sure nobody adds one by accident later.
 */
final class RestoreAuthorizationTest extends TestCase
{
    public function testNoPublicPageReferencesBackupOrRestoreFunctionality(): void
    {
        $publicDir = dirname(__DIR__, 2) . '/public';
        $offenders = [];
        $pattern = '/\b(mysqldump|BACKUP_ENCRYPTION_KEY|backup_verify_artifact|backup_safe_path|ALLOW_DESTRUCTIVE_RESTORE|cron\/backup|cron\/restore|cron\/dr-drill)\b/i';

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($publicDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php' && $file->getExtension() !== 'html') continue;
            $content = file_get_contents($file->getPathname());
            if ($content !== false && preg_match($pattern, $content)) {
                $offenders[] = str_replace($publicDir . '/', '', $file->getPathname());
            }
        }

        $this->assertSame([], $offenders, 'no file under public/ may reference backup/restore internals -- these operations are CLI-only (STEP 36)');
    }
}
