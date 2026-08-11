<?php
/**
 * ELLSMS — SMS gateway configuration integrity audit (docs/sms-gateway-connectors.md).
 *
 * Read-only, and it NEVER auto-fixes: a tool that quietly "corrected" a connector would change where
 * customer messages are sent, which is the last place silent repair belongs.
 *
 * Exits non-zero on CRITICAL findings — a gateway that cannot compile, a secret that cannot be
 * decrypted, an endpoint that would be refused in production, or a route pointing at a gateway that
 * cannot serve it. Warnings are reported without failing the run.
 *
 * Usage: php cron/sms-gateway-integrity-check.php
 */
require_once __DIR__ . '/../app/backend.php';

$critical = 0;
$warnings = 0;
$db = db();

function gateway_section(string $title): void {
    echo "\n=== {$title} ===\n";
}
function gateway_report(bool $bad, bool $isCritical, string $message): void {
    global $critical, $warnings;
    if (!$bad) {
        echo "  [ok] {$message}\n";
        return;
    }
    echo '  [' . ($isCritical ? 'CRITICAL' : 'WARN') . "] {$message}\n";
    $isCritical ? $critical++ : $warnings++;
}

echo "ELLSMS SMS gateway integrity check\n";

/* ---------- 1. Static scan: configuration must never become code ---------- */
gateway_section('Static scan: no dynamic execution in the connector engine (Invariant K/L)');
// The central safety claim of this feature is that admin configuration is DATA. This scan is what
// stops that claim from quietly eroding: a future edit that reaches for eval() to "just support one
// more provider quirk" fails here rather than in a security review a year later.
// Tokenised, not grepped: these files DISCUSS eval() in their docblocks precisely because they
// promise not to use it, so a text search would report the documentation as a violation and the
// check would be switched off within a week. token_get_all() sees only real code.
$forbidden = ['eval', 'create_function', 'assert', 'shell_exec', 'exec', 'system', 'passthru', 'proc_open', 'popen', 'call_user_func', 'call_user_func_array'];
$engineFiles = glob(dirname(__DIR__) . '/app/Sms/Gateway*.php') ?: [];
$found = [];
foreach ($engineFiles as $file) {
    $tokens = token_get_all((string)file_get_contents($file));
    foreach ($tokens as $index => $token) {
        if (is_array($token) && $token[0] === T_STRING && in_array(strtolower($token[1]), $forbidden, true)) {
            $found[] = basename($file) . ':' . $token[2] . ' calls ' . $token[1] . '()';
        }
        // A backtick token is shell execution in PHP, whatever it looks like.
        if ($token === '`') {
            $found[] = basename($file) . ' uses shell execution (backticks)';
        }
        // eval is its own token, not a T_STRING.
        if (is_array($token) && $token[0] === T_EVAL) {
            $found[] = basename($file) . ':' . $token[2] . ' calls eval()';
        }
        unset($index);
    }
}
$found = array_values(array_unique($found));
gateway_report($found !== [], true, $found === []
    ? count($engineFiles) . ' connector engine file(s) contain no dynamic-execution construct'
    : implode('; ', $found));

/* ---------- 2. Every active gateway compiles ---------- */
gateway_section('Compilation');
$gateways = $db->query("SELECT * FROM ellsms_sms_gateways WHERE status = 'active' ORDER BY code")->fetchAll();
if ($gateways === []) {
    echo "  [info] no active gateways configured — the legacy REST client is still the only send path\n";
}
foreach ($gateways as $gateway) {
    $connector = gateway_compiled((int)$gateway['id']);
    gateway_report($connector === null, true, "gateway '{$gateway['code']}' compiles");
    if ($connector === null) {
        continue;
    }

    // An endpoint that production would refuse is reported here even on a staging host: finding it
    // now is the entire point of a preflight check. The verdict comes from the SAME function the
    // transport calls before every request, so this cannot drift from what actually happens.
    foreach ([['send', $connector['send']], ['status', $connector['status']]] as [$kind, $section]) {
        if ($section === null) {
            continue;
        }
        $scheme = strtolower((string)parse_url($section['endpoint'], PHP_URL_SCHEME));
        gateway_report($scheme !== 'https', false,
            "gateway '{$gateway['code']}' {$kind} endpoint uses " . ($scheme ?: 'no scheme') . ($scheme === 'https' ? '' : ' — production requires https'));
        gateway_report(!$section['tls_verify'], true,
            "gateway '{$gateway['code']}' {$kind} connector verifies TLS certificates");

        $verdict = gateway_endpoint_allowed($section['endpoint']);
        gateway_report(!$verdict['ok'], app_env() === 'production',
            "gateway '{$gateway['code']}' {$kind} endpoint is reachable"
            . ($verdict['ok'] ? '' : " — refused: {$verdict['reason']}"));
        if ($verdict['ok'] && $verdict['resolve'] === [] && $scheme === 'https') {
            echo "  [info] gateway '{$gateway['code']}' {$kind} endpoint is on the internal-host allowlist (no address check, no connection pin)\n";
        }
    }

    if ($connector['send_mode'] === 'batch' && $connector['send']['batch'] === null) {
        gateway_report(true, false, "gateway '{$gateway['code']}' is batch mode but has no batch response mapping — every destination will be assumed accepted");
    } else {
        gateway_report(false, false, "gateway '{$gateway['code']}' response mapping matches its send mode");
    }

    if ($connector['status_enabled'] && $connector['status']['statuses'] === []) {
        gateway_report(true, true, "gateway '{$gateway['code']}' polls delivery status but maps no provider status token — every poll would resolve to 'unknown'");
    }
}

/* ---------- 3. Secrets ---------- */
gateway_section('Secrets');
$secretRows = $db->query('SELECT gateway_id, secret_key, key_fingerprint FROM ellsms_sms_gateway_secrets')->fetchAll();
if ($secretRows === []) {
    echo "  [info] no database-backed gateway secrets stored\n";
} else {
    gateway_report(!gateway_secrets_configured(), true,
        'SMS_GATEWAY_MASTER_KEY is set (' . count($secretRows) . ' stored secret(s) depend on it)');
    if (gateway_secrets_configured()) {
        $fingerprint = gateway_secret_key_fingerprint();
        $mismatched = 0;
        foreach ($secretRows as $row) {
            if ($row['key_fingerprint'] !== '' && $row['key_fingerprint'] !== $fingerprint) {
                $mismatched++;
            }
        }
        // This is the exact signature of a database restored onto a host with a different master key
        // — the failure mode docs/backup-and-disaster-recovery.md warns about.
        gateway_report($mismatched > 0, true, $mismatched === 0
            ? 'every stored secret was encrypted with the current master key'
            : "{$mismatched} secret(s) were encrypted with a DIFFERENT master key — restore/rotation mismatch");
    }
}

/* ---------- 4. Routing ---------- */
gateway_section('Routing');
$orphanRoutes = (int)$db->query(
    "SELECT COUNT(*) FROM ellsms_sms_routes r
     LEFT JOIN ellsms_sms_gateways g ON g.id = r.gateway_id
     WHERE r.status = 'active' AND r.gateway_id IS NOT NULL AND (g.id IS NULL OR g.status <> 'active')"
)->fetchColumn();
gateway_report($orphanRoutes > 0, true, $orphanRoutes === 0
    ? 'every active route points at an active gateway (or at none)'
    : "{$orphanRoutes} active route(s) point at a missing or archived gateway");

$unassigned = (int)$db->query("SELECT COUNT(*) FROM ellsms_sms_routes WHERE status = 'active' AND gateway_id IS NULL")->fetchColumn();
if (gateway_transport_enabled()) {
    // With the transport enabled, an unassigned route is silently still using the legacy client.
    // That is intentional during rollout and dangerous afterwards, so it is reported either way.
    gateway_report($unassigned > 0, false, $unassigned === 0
        ? 'every active route has a gateway'
        : "{$unassigned} active route(s) have no gateway and still fall back to the legacy REST client");
} else {
    echo "  [info] gateway transport is disabled — {$unassigned} route(s) without a gateway are expected\n";
}

$defaults = (int)$db->query("SELECT COUNT(*) FROM ellsms_sms_gateways WHERE default_slot IS NOT NULL AND status = 'active'")->fetchColumn();
gateway_report($defaults > 1, true, $defaults <= 1
    ? 'at most one default gateway (enforced by the default_slot unique index)'
    : "{$defaults} gateways claim to be default");

/* ---------- 5. Delivery polling health ---------- */
gateway_section('Delivery status polling');
$stuck = (int)$db->query(
    "SELECT COUNT(*) FROM ellsms_bulk_items
     WHERE gateway_id IS NOT NULL AND status = 'sent'
       AND provider_message_id IS NOT NULL
       AND (delivery_status IS NULL OR delivery_status NOT IN ('delivered','failed','rejected','expired'))
       AND created_at < DATE_SUB(NOW(), INTERVAL 2 DAY)"
)->fetchColumn();
gateway_report($stuck > 0, false, $stuck === 0
    ? 'no gateway-sent message older than two days is still awaiting a delivery state'
    : "{$stuck} message(s) older than two days never reached a terminal delivery state");

$noProviderId = (int)$db->query(
    "SELECT COUNT(*) FROM ellsms_bulk_items WHERE gateway_id IS NOT NULL AND status = 'sent' AND provider_message_id IS NULL"
)->fetchColumn();
gateway_report($noProviderId > 0, false, $noProviderId === 0
    ? 'every gateway-sent message carries a provider message id'
    : "{$noProviderId} gateway-sent message(s) have no provider id and can never be polled — check the response mapping");

echo "\n";
echo $critical > 0
    ? "FAILED — {$critical} critical finding(s), {$warnings} warning(s).\n"
    : "OK — no critical findings, {$warnings} warning(s).\n";
exit($critical > 0 ? 1 : 0);
