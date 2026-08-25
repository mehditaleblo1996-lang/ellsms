<?php
/** Loaded by PHP auto_prepend_file for HTTP entrypoints. */
if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/AuditMongo.php';
    audit_request_register();
}
