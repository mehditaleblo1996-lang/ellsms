<?php
/**
 * ELLSMS — provider push callback: inbound SMS (MO).
 * Called by the SMS provider directly (no session, no CSRF); see app/Sms/GatewayCallbacks.php.
 */

require_once __DIR__ . '/../app/bootstrap.php';

sms_callback_handle('mo');
