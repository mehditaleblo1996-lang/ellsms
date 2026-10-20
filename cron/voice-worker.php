<?php
/**
 * Voice messages worker (app/VoiceMessages.php, docs/voice-messages.md): sends scheduled voice
 * messages that are due, recovers sends a crashed process left half-done, polls ZarinCall for the
 * progress of sent ones and settles their cost (answered calls charged, the rest released), and
 * re-reads files whose duration ZarinCall had not reported yet.
 *
 * Run by the scheduler's `voice-worker` job every minute; safe to run by hand alongside it (a MySQL
 * named lock lets only one pass run at a time, and every step claims its rows).
 *
 *   php cron/voice-worker.php
 */
require_once __DIR__ . '/../app/backend.php';

if (maintenance_mode_active()) {
    echo "maintenance mode: skipped\n";
    exit(0);
}

// Deployed before its migration (deploy.sh restarts before it migrates): nothing to do yet, and not a
// failure worth an alert every minute.
if (db()->query("SHOW TABLES LIKE 'ellsms_voice_messages'")->fetchColumn() === false) {
    echo "voice tables not migrated yet: skipped\n";
    exit(0);
}

$lock = db()->query("SELECT GET_LOCK('ellsms_voice_worker', 0)")->fetchColumn();
if ((int)$lock !== 1) {
    echo "another voice worker pass is running\n";
    exit(0);
}

try {
    $stats = voice_worker_run();
} finally {
    db()->query("SELECT RELEASE_LOCK('ellsms_voice_worker')");
}
Logger::info('voice.worker.pass', $stats);
printf("recovered=%d dispatched=%d settled=%d files=%d\n", $stats['recovered'], $stats['dispatched'], $stats['settled'], $stats['files']);
