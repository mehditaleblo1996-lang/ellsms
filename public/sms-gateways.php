<?php
/**
 * ELLSMS — Platform Admin -> SMS gateways (connector builder). See docs/sms-gateway-connectors.md.
 *
 * PLATFORM ADMIN ONLY, via require_admin() — the same guard every global-configuration page uses, and
 * deliberately not an organization permission: a gateway decides where every tenant's messages go, so
 * an organization owner must not reach it even for their own organization. Every mutation is a POST
 * through the same guard and through csrf_check().
 *
 * SECRET VALUES ARE WRITE-ONLY. A stored credential is never rendered back into a form field, never
 * echoed in a preview, and never written to the audit trail — the trail records that a secret changed,
 * which is the part an operator actually needs. A blank secret field means "leave it alone", so
 * re-saving a form cannot silently blank a working credential.
 *
 * EVERY MUTATION BUMPS config_version. That increment is what makes a change reach running workers
 * (app/Sms/GatewayCache.php); an admin edit that skipped it would appear saved and change nothing for
 * up to the lifetime of the worker process.
 *
 * THE PASTED CURL COMMAND IS PARSED, NEVER EXECUTED (STEP 40). See gateway_parse_curl().
 */
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_admin();
$pageTitle = 'درگاه‌های پیامک';
$active = 'sms_gateways';

$db = db();
$gatewayId = (int)($_GET['gateway'] ?? $_POST['gateway_id'] ?? 0);
$tab = (string)($_GET['tab'] ?? 'connector');
$validTabs = ['connector', 'parameters', 'operators', 'secrets', 'import', 'test'];
if (!in_array($tab, $validTabs, true)) $tab = 'connector';

/** One audit shape for every gateway mutation, so the trail is greppable. Never carries a secret. */
function gateway_admin_audit(array $me, int $gatewayId, string $action, array $details): void {
    gateway_bump_version($gatewayId, $action, (int)$me['id'], json_encode($details, JSON_UNESCAPED_UNICODE) ?: '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = (string)($_POST['do'] ?? '');
    $redirectTab = (string)($_POST['tab'] ?? $tab);

    // Defence in depth behind require_admin(): a support session must never redirect a tenant's
    // traffic or touch a credential, even if it was somehow opened against an admin account.
    if (impersonation_guard_post(str_starts_with($do, 'secret_') ? 'gateway.secret' : 'gateway.config')) {
        redirect('/sms-gateways.php' . ($gatewayId ? '?gateway=' . $gatewayId : ''));
    }

    try {
        switch ($do) {
            /* ---------------- Gateways ---------------- */
            case 'gateway_create': {
                $code = strtolower(trim((string)($_POST['code'] ?? '')));
                $name = trim((string)($_POST['name'] ?? ''));
                if (preg_match('/^[a-z0-9_]{2,40}$/', $code) !== 1 || $name === '') {
                    flash('error', 'شناسه‌ی درگاه باید ۲ تا ۴۰ نویسه‌ی لاتین/عدد/زیرخط و نام آن غیرخالی باشد.');
                    break;
                }
                $sendMode = ($_POST['send_mode'] ?? 'per_message') === 'batch' ? 'batch' : 'per_message';
                $db->prepare(
                    "INSERT INTO ellsms_sms_gateways (code, name, status, send_mode, send_enabled, status_enabled, is_default, config_version)
                     VALUES (?,?,'active',?,1,0,0,1)"
                )->execute([$code, $name, $sendMode]);
                $newId = (int)$db->lastInsertId();
                // A gateway with no send connector cannot compile, so one is created immediately with
                // safe defaults rather than leaving the gateway in a state that reports as broken.
                $db->prepare(
                    "INSERT INTO ellsms_sms_gateway_send_connectors (gateway_id, endpoint_url, success_rule_json)
                     VALUES (?, '', ?)"
                )->execute([$newId, json_encode(['http' => ['min' => 200, 'max' => 299], 'require_json' => true, 'rules' => []])]);
                gateway_admin_audit($me, $newId, 'gateway.create', ['code' => $code, 'send_mode' => $sendMode]);
                flash('success', 'درگاه ساخته شد. اکنون آدرس و پارامترهای آن را تنظیم کنید.');
                redirect('/sms-gateways.php?gateway=' . $newId . '&tab=connector');
            }

            case 'gateway_update': {
                $id = (int)$_POST['gateway_id'];
                $status = ($_POST['status'] ?? 'active') === 'archived' ? 'archived' : 'active';
                $db->prepare('UPDATE ellsms_sms_gateways SET name = ?, status = ?, send_mode = ?, send_enabled = ?, status_enabled = ? WHERE id = ?')
                   ->execute([
                       trim((string)($_POST['name'] ?? '')), $status,
                       ($_POST['send_mode'] ?? 'per_message') === 'batch' ? 'batch' : 'per_message',
                       empty($_POST['send_enabled']) ? 0 : 1,
                       empty($_POST['status_enabled']) ? 0 : 1,
                       $id,
                   ]);
                gateway_admin_audit($me, $id, 'gateway.update', ['status' => $status]);
                flash('success', 'درگاه به‌روزرسانی شد.');
                break;
            }

            case 'gateway_default': {
                $id = (int)$_POST['gateway_id'];
                // default_slot is the application-maintained uniqueness column (never a generated
                // column — see docs/td-070-restore-safety-closure.md): cleared everywhere, then set
                // here, inside one transaction so the unique index is never transiently violated.
                db_transaction(function (PDO $db) use ($id): void {
                    $db->exec('UPDATE ellsms_sms_gateways SET is_default = 0, default_slot = NULL');
                    $db->prepare('UPDATE ellsms_sms_gateways SET is_default = 1, default_slot = 1 WHERE id = ?')->execute([$id]);
                });
                gateway_admin_audit($me, $id, 'gateway.set_default', []);
                flash('success', 'این درگاه به‌عنوان پیش‌فرض تنظیم شد.');
                break;
            }

            /* ---------------- Send / status connectors ---------------- */
            case 'connector_save': {
                $id = (int)$_POST['gateway_id'];
                $kind = ($_POST['connector'] ?? 'send') === 'status' ? 'status' : 'send';
                $endpoint = trim((string)($_POST['endpoint_url'] ?? ''));
                if ($endpoint !== '' && preg_match('#^https?://#i', $endpoint) !== 1) {
                    flash('error', 'آدرس باید با http:// یا https:// شروع شود.');
                    break;
                }
                $method = in_array($_POST['http_method'] ?? '', ['GET', 'POST', 'PUT', 'PATCH'], true) ? $_POST['http_method'] : 'POST';
                $contentType = ($_POST['content_type'] ?? '') === 'application/x-www-form-urlencoded'
                    ? 'application/x-www-form-urlencoded' : 'application/json';
                $authType = in_array($_POST['auth_type'] ?? '', GATEWAY_AUTH_TYPES, true) ? (string)$_POST['auth_type'] : 'none';

                // The auth CONFIG holds names and secret references only — never a credential value.
                $authConfig = array_filter([
                    'header'             => trim((string)($_POST['auth_header'] ?? '')) ?: null,
                    'param'              => trim((string)($_POST['auth_param'] ?? '')) ?: null,
                    'username'           => trim((string)($_POST['auth_username'] ?? '')) ?: null,
                    'token_secret'       => trim((string)($_POST['auth_token_secret'] ?? '')) ?: null,
                    'password_secret'    => trim((string)($_POST['auth_password_secret'] ?? '')) ?: null,
                    'service_id_env'     => trim((string)($_POST['auth_service_id_env'] ?? '')) ?: null,
                    'service_secret_env' => trim((string)($_POST['auth_service_secret_env'] ?? '')) ?: null,
                ], static fn($v) => $v !== null);

                $mappings = [];
                foreach (['success_rule_json', 'response_mapping_json', 'error_mapping_json', 'batch_mapping_json', 'status_mapping_json'] as $field) {
                    $raw = trim((string)($_POST[$field] ?? ''));
                    if ($raw === '') { $mappings[$field] = null; continue; }
                    $decoded = json_decode($raw, true);
                    if (!is_array($decoded)) {
                        flash('error', "مقدار {$field} باید JSON معتبر باشد.");
                        break 2;
                    }
                    $mappings[$field] = json_encode($decoded, JSON_UNESCAPED_UNICODE);
                }

                if ($kind === 'send') {
                    $db->prepare(
                        'INSERT INTO ellsms_sms_gateway_send_connectors
                           (gateway_id, endpoint_url, http_method, content_type, connect_timeout_ms, request_timeout_ms, tls_verify,
                            auth_type, auth_config_json, success_rule_json, response_mapping_json, error_mapping_json, batch_mapping_json)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
                         ON DUPLICATE KEY UPDATE
                           endpoint_url=VALUES(endpoint_url), http_method=VALUES(http_method), content_type=VALUES(content_type),
                           connect_timeout_ms=VALUES(connect_timeout_ms), request_timeout_ms=VALUES(request_timeout_ms),
                           tls_verify=VALUES(tls_verify), auth_type=VALUES(auth_type), auth_config_json=VALUES(auth_config_json),
                           success_rule_json=VALUES(success_rule_json), response_mapping_json=VALUES(response_mapping_json),
                           error_mapping_json=VALUES(error_mapping_json), batch_mapping_json=VALUES(batch_mapping_json)'
                    )->execute([
                        $id, $endpoint, $method, $contentType,
                        max(500, (int)($_POST['connect_timeout_ms'] ?? 5000)),
                        max(1000, (int)($_POST['request_timeout_ms'] ?? 30000)),
                        // TLS verification is not offered as a switch. A connector that skips it would
                        // send customer messages and a bearer token to whoever answers the DNS name.
                        1,
                        $authType, $authConfig === [] ? null : json_encode($authConfig, JSON_UNESCAPED_UNICODE),
                        $mappings['success_rule_json'], $mappings['response_mapping_json'],
                        $mappings['error_mapping_json'], $mappings['batch_mapping_json'],
                    ]);
                } else {
                    $db->prepare(
                        'INSERT INTO ellsms_sms_gateway_status_connectors
                           (gateway_id, endpoint_url, http_method, content_type, connect_timeout_ms, request_timeout_ms, tls_verify,
                            auth_type, auth_config_json, response_mapping_json, status_mapping_json,
                            poll_initial_delay_seconds, poll_max_attempts, poll_max_age_seconds)
                         VALUES (?,?,?,?,?,?,1,?,?,?,?,?,?,?)
                         ON DUPLICATE KEY UPDATE
                           endpoint_url=VALUES(endpoint_url), http_method=VALUES(http_method), content_type=VALUES(content_type),
                           connect_timeout_ms=VALUES(connect_timeout_ms), request_timeout_ms=VALUES(request_timeout_ms),
                           auth_type=VALUES(auth_type), auth_config_json=VALUES(auth_config_json),
                           response_mapping_json=VALUES(response_mapping_json), status_mapping_json=VALUES(status_mapping_json),
                           poll_initial_delay_seconds=VALUES(poll_initial_delay_seconds),
                           poll_max_attempts=VALUES(poll_max_attempts), poll_max_age_seconds=VALUES(poll_max_age_seconds)'
                    )->execute([
                        $id, $endpoint, in_array($method, ['GET', 'POST'], true) ? $method : 'GET', $contentType,
                        max(500, (int)($_POST['connect_timeout_ms'] ?? 5000)),
                        max(1000, (int)($_POST['request_timeout_ms'] ?? 15000)),
                        $authType, $authConfig === [] ? null : json_encode($authConfig, JSON_UNESCAPED_UNICODE),
                        $mappings['response_mapping_json'], $mappings['status_mapping_json'],
                        max(0, (int)($_POST['poll_initial_delay_seconds'] ?? 30)),
                        max(0, (int)($_POST['poll_max_attempts'] ?? 6)),
                        max(0, (int)($_POST['poll_max_age_seconds'] ?? 86400)),
                    ]);
                }
                gateway_admin_audit($me, $id, 'connector.save', ['connector' => $kind, 'host' => parse_url($endpoint, PHP_URL_HOST), 'auth_type' => $authType]);
                flash('success', 'کانکتور ذخیره شد.');
                break;
            }

            /* ---------------- Parameters ---------------- */
            case 'parameter_save': {
                $id = (int)$_POST['gateway_id'];
                $key = trim((string)($_POST['param_key'] ?? ''));
                if (preg_match('/^[A-Za-z0-9_.\-]{1,120}$/', $key) !== 1) {
                    flash('error', 'نام پارامتر نامعتبر است.');
                    break;
                }
                $connectorKind = ($_POST['connector'] ?? 'send') === 'status' ? 'status' : 'send';
                $location = in_array($_POST['location'] ?? '', ['header', 'query', 'body'], true) ? (string)$_POST['location'] : 'body';
                $scope = in_array($_POST['scope'] ?? '', ['gateway', 'route', 'operator'], true) ? (string)$_POST['scope'] : 'gateway';
                $scopeId = $scope === 'gateway' ? null : (int)($_POST['scope_id'] ?? 0);
                if ($scope !== 'gateway' && !$scopeId) {
                    flash('error', 'برای دامنه‌ی مسیر یا اپراتور باید مورد مربوطه انتخاب شود.');
                    break;
                }
                $valueType = (string)($_POST['value_type'] ?? 'static');
                $value = (string)($_POST['value'] ?? '');
                $dataType = in_array($_POST['data_type'] ?? '', ['string', 'integer', 'boolean', 'null', 'json', 'string_list', 'numeric'], true)
                    ? (string)$_POST['data_type'] : 'string';

                // Validated through the SAME compiler the runtime uses, so a value that saves here
                // cannot fail to compile later. Secrets are passed as key names only.
                try {
                    gateway_parameter_compile(
                        ['param_key' => $key, 'location' => $location, 'value_type' => $valueType, 'value' => $value, 'data_type' => $dataType],
                        $connectorKind,
                        array_fill_keys(array_column(gateway_secret_keys($id), 'secret_key'), '')
                    );
                } catch (GatewayConfigException $e) {
                    flash('error', $e->getMessage());
                    break;
                }

                $db->prepare(
                    "INSERT INTO ellsms_sms_gateway_parameters
                       (gateway_id, connector, location, scope, scope_id, param_key, value_type, value, data_type, status, sort_order, active_slot)
                     VALUES (?,?,?,?,?,?,?,?,?,'active',?,?)
                     ON DUPLICATE KEY UPDATE value_type=VALUES(value_type), value=VALUES(value),
                                             data_type=VALUES(data_type), sort_order=VALUES(sort_order)"
                )->execute([
                    $id, $connectorKind, $location, $scope, $scopeId, $key, $valueType, $value, $dataType,
                    (int)($_POST['sort_order'] ?? 100),
                    "{$id}:{$connectorKind}:{$location}:{$scope}:" . ($scopeId ?? '') . ":{$key}",
                ]);
                gateway_admin_audit($me, $id, 'parameter.save', ['key' => $key, 'scope' => $scope, 'location' => $location, 'value_type' => $valueType]);
                flash('success', 'پارامتر ذخیره شد.');
                break;
            }

            case 'parameter_delete': {
                $id = (int)$_POST['gateway_id'];
                $parameterId = (int)($_POST['parameter_id'] ?? 0);
                // Archived, not deleted — and active_slot is cleared so the same key can be re-added.
                $db->prepare("UPDATE ellsms_sms_gateway_parameters SET status = 'archived', active_slot = NULL WHERE id = ? AND gateway_id = ?")
                   ->execute([$parameterId, $id]);
                gateway_admin_audit($me, $id, 'parameter.delete', ['parameter_id' => $parameterId]);
                flash('info', 'پارامتر حذف شد.');
                break;
            }

            /* ---------------- Operator assignment ---------------- */
            case 'operators_save': {
                $id = (int)$_POST['gateway_id'];
                $selected = array_map('intval', (array)($_POST['operator_ids'] ?? []));
                db_transaction(function (PDO $db) use ($id, $selected): void {
                    $db->prepare('DELETE FROM ellsms_sms_gateway_operators WHERE gateway_id = ?')->execute([$id]);
                    foreach ($selected as $operatorId) {
                        $db->prepare("INSERT INTO ellsms_sms_gateway_operators (gateway_id, operator_id, status) VALUES (?,?, 'active')")
                           ->execute([$id, $operatorId]);
                    }
                });
                gateway_admin_audit($me, $id, 'operators.save', ['count' => count($selected)]);
                flash('success', $selected === []
                    ? 'همه‌ی اپراتورها مجازند (بدون محدودیت).'
                    : 'اپراتورهای این درگاه ذخیره شد.');
                break;
            }

            /* ---------------- Secrets ---------------- */
            case 'secret_save': {
                $id = (int)$_POST['gateway_id'];
                $key = trim((string)($_POST['secret_key'] ?? ''));
                $value = (string)($_POST['secret_value'] ?? '');
                if ($value === '') {
                    // A blank field means "leave it alone", so re-saving the form cannot silently
                    // blank a working credential.
                    flash('info', 'مقدار خالی بود؛ کلید محرمانه تغییر نکرد.');
                    break;
                }
                gateway_secret_put($id, $key, $value);
                // Records THAT it changed, never the value or its length.
                gateway_admin_audit($me, $id, 'secret.save', ['secret_key' => $key, 'changed' => true]);
                flash('success', 'کلید محرمانه ذخیره شد. مقدار آن دیگر نمایش داده نمی‌شود.');
                break;
            }

            case 'secret_delete': {
                $id = (int)$_POST['gateway_id'];
                gateway_secret_delete($id, (string)($_POST['secret_key'] ?? ''));
                gateway_admin_audit($me, $id, 'secret.delete', ['secret_key' => (string)($_POST['secret_key'] ?? '')]);
                flash('info', 'کلید محرمانه حذف شد.');
                break;
            }
        }
    } catch (GatewaySecretException | GatewayConfigException $e) {
        flash('error', $e->getMessage());
    } catch (PDOException $e) {
        Logger::error('gateway.admin_action_failed', ['do' => $do, 'exception' => $e]);
        flash('error', 'ثبت تغییر ممکن نشد. احتمالاً مقدار تکراری یا نامعتبر است.');
    }

    gateway_cache_reset();
    redirect('/sms-gateways.php' . ($gatewayId ? '?gateway=' . $gatewayId . '&tab=' . urlencode($redirectTab) : ''));
}

/* ======================= Read side ======================= */
$gateways = $db->query('SELECT * FROM ellsms_sms_gateways ORDER BY is_default DESC, code')->fetchAll();
$gateway = null;
foreach ($gateways as $row) {
    if ((int)$row['id'] === $gatewayId) $gateway = $row;
}

$sendConnector = $statusConnector = null;
$parameters = $assignedOperators = $secrets = $auditRows = [];
$compiled = null;
$curlDraft = null;
$dryRun = null;

if ($gateway !== null) {
    $st = $db->prepare('SELECT * FROM ellsms_sms_gateway_send_connectors WHERE gateway_id = ?');
    $st->execute([$gatewayId]);
    $sendConnector = $st->fetch() ?: null;

    $st = $db->prepare('SELECT * FROM ellsms_sms_gateway_status_connectors WHERE gateway_id = ?');
    $st->execute([$gatewayId]);
    $statusConnector = $st->fetch() ?: null;

    $st = $db->prepare("SELECT * FROM ellsms_sms_gateway_parameters WHERE gateway_id = ? AND status = 'active' ORDER BY connector, location, scope, sort_order, param_key");
    $st->execute([$gatewayId]);
    $parameters = $st->fetchAll();

    $st = $db->prepare("SELECT operator_id FROM ellsms_sms_gateway_operators WHERE gateway_id = ? AND status = 'active'");
    $st->execute([$gatewayId]);
    $assignedOperators = array_map('intval', array_column($st->fetchAll(), 'operator_id'));

    $secrets = gateway_secret_keys($gatewayId);

    $st = $db->prepare('SELECT * FROM ellsms_sms_gateway_config_audit WHERE gateway_id = ? ORDER BY id DESC LIMIT 20');
    $st->execute([$gatewayId]);
    $auditRows = $st->fetchAll();

    $compiled = $gateway['status'] === 'active' ? gateway_compiled($gatewayId) : null;

    if ($tab === 'import' && isset($_GET['curl'])) {
        // Parsed, never executed — see gateway_parse_curl()'s docblock.
        $curlDraft = gateway_parse_curl((string)$_GET['curl']);
    }

    if ($tab === 'test' && $compiled !== null && ($_GET['preview'] ?? '') === '1') {
        $to = trim((string)($_GET['to'] ?? ''));
        $senderLine = trim((string)($_GET['sender'] ?? ''));
        if ($to !== '') {
            $operator = sms_resolve_operator($to);
            $context = gateway_send_context([
                'sender' => $senderLine, 'recipients' => [$to], 'message' => (string)($_GET['text'] ?? 'نمونه'),
                'sender_user_id' => (int)$me['id'], 'gateway_code' => $compiled['gateway_code'],
                'operator_code' => (string)($operator['operator_code'] ?? ''),
            ]);
            // The dry run builds the REAL request through the REAL builder and does not send it — a
            // separately written preview could disagree with what the send path actually produces.
            $built = gateway_build_request($compiled, 'send', $context, null,
                $operator['operator_id'] !== null ? (int)$operator['operator_id'] : null);
            $dryRun = ['preview' => $built['preview'], 'body' => $built['body'], 'operator' => $operator['operator_code']];
        }
    }
}

$operators = $db->query("SELECT id, code, name FROM ellsms_sms_operators WHERE status = 'active' ORDER BY code")->fetchAll();
$routes = $db->query("SELECT id, code FROM ellsms_sms_routes WHERE status = 'active' ORDER BY code")->fetchAll();

require __DIR__ . '/../app/views/header.php';
?>
<div class="card">
  <h2>درگاه‌های پیامک</h2>
  <p class="muted">
    درگاه‌ها به‌صورت پیکربندی تعریف می‌شوند: آدرس، پارامترها، نوع احراز هویت و نگاشت پاسخ.
    پیکربندی «داده» است و هرگز به‌عنوان کد اجرا نمی‌شود. انتخاب درگاه «صریح» است — هیچ مسیریابی هوشمند،
    انتخاب ارزان‌ترین مسیر یا جابه‌جایی خودکار بین درگاه‌ها انجام نمی‌شود.
  </p>
  <p class="muted">
    وضعیت انتقال ارسال:
    <strong><?= gateway_transport_enabled() ? 'فعال — ارسال‌ها از درگاه پیکربندی‌شده انجام می‌شود' : 'غیرفعال — ارسال‌ها هنوز از مسیر قدیمی انجام می‌شود' ?></strong>
    (با متغیر محیطی <span class="ltr">SMS_GATEWAY_TRANSPORT</span> کنترل می‌شود)
  </p>
</div>

<div class="card">
  <h2>درگاه‌ها</h2>
  <table class="table">
    <thead><tr><th>شناسه</th><th>نام</th><th>وضعیت</th><th>حالت ارسال</th><th>نسخه‌ی پیکربندی</th><th>کامپایل</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($gateways as $row): $rowCompiled = $row['status'] === 'active' ? gateway_compiled((int)$row['id']) : null; ?>
      <tr>
        <td class="ltr"><?= e($row['code']) ?><?= $row['is_default'] ? ' ★' : '' ?></td>
        <td><?= e($row['name']) ?></td>
        <td><?= $row['status'] === 'active' ? 'فعال' : 'بایگانی' ?><?= $row['send_enabled'] ? '' : ' — ارسال خاموش' ?></td>
        <td><?= $row['send_mode'] === 'batch' ? 'دسته‌ای' : 'تک‌پیام' ?></td>
        <td class="ltr">v<?= (int)$row['config_version'] ?></td>
        <td><?= $rowCompiled !== null ? 'سالم' : '<strong>ناموفق</strong>' ?></td>
        <td>
          <a class="btn" href="/sms-gateways.php?gateway=<?= (int)$row['id'] ?>&tab=connector">ویرایش</a>
          <?php if (!$row['is_default'] && $row['status'] === 'active'): ?>
            <form method="post" style="display:inline">
              <?= csrf_field() ?><input type="hidden" name="do" value="gateway_default">
              <input type="hidden" name="gateway_id" value="<?= (int)$row['id'] ?>">
              <button class="btn">پیش‌فرض</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if ($gateways === []): ?>
      <tr><td colspan="7" class="muted">هیچ درگاهی تعریف نشده است. با دستور <span class="ltr">make sms-gateway-backfill</span> می‌توانید درگاه فعلی را ثبت کنید.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>

  <h3>درگاه جدید</h3>
  <form method="post" class="toolbar">
    <?= csrf_field() ?><input type="hidden" name="do" value="gateway_create">
    <label>شناسه <input type="text" name="code" class="ltr" placeholder="my_provider" required></label>
    <label>نام <input type="text" name="name" required></label>
    <label>حالت ارسال
      <select name="send_mode">
        <option value="per_message">تک‌پیام (هر مقصد یک درخواست)</option>
        <option value="batch">دسته‌ای (چند مقصد در یک درخواست)</option>
      </select>
    </label>
    <button class="btn btn-primary">ساخت</button>
  </form>
</div>

<?php if ($gateway !== null): ?>
<div class="card">
  <div class="toolbar">
    <?php foreach ([
      'connector' => 'کانکتورها', 'parameters' => 'پارامترها', 'operators' => 'اپراتورها',
      'secrets' => 'کلیدهای محرمانه', 'import' => 'ورود از curl', 'test' => 'پیش‌نمایش درخواست',
    ] as $key => $label): ?>
      <a class="btn<?= $tab === $key ? ' btn-primary' : '' ?>" href="/sms-gateways.php?gateway=<?= $gatewayId ?>&tab=<?= $key ?>"><?= $label ?></a>
    <?php endforeach; ?>
  </div>
  <p class="muted">
    درگاه <strong class="ltr"><?= e($gateway['code']) ?></strong> — نسخه‌ی پیکربندی <span class="ltr">v<?= (int)$gateway['config_version'] ?></span>.
    هر تغییر این نسخه را افزایش می‌دهد و حداکثر پس از <?= gateway_version_check_seconds() ?> ثانیه به همه‌ی کارگرها می‌رسد.
  </p>
</div>

<?php if ($tab === 'connector'): ?>
<div class="card">
  <h2>تنظیمات درگاه</h2>
  <form method="post" class="toolbar">
    <?= csrf_field() ?><input type="hidden" name="do" value="gateway_update">
    <input type="hidden" name="gateway_id" value="<?= $gatewayId ?>"><input type="hidden" name="tab" value="connector">
    <label>نام <input type="text" name="name" value="<?= e($gateway['name']) ?>"></label>
    <label>وضعیت
      <select name="status">
        <option value="active"<?= $gateway['status'] === 'active' ? ' selected' : '' ?>>فعال</option>
        <option value="archived"<?= $gateway['status'] === 'archived' ? ' selected' : '' ?>>بایگانی</option>
      </select>
    </label>
    <label>حالت ارسال
      <select name="send_mode">
        <option value="per_message"<?= $gateway['send_mode'] === 'per_message' ? ' selected' : '' ?>>تک‌پیام</option>
        <option value="batch"<?= $gateway['send_mode'] === 'batch' ? ' selected' : '' ?>>دسته‌ای</option>
      </select>
    </label>
    <label><input type="checkbox" name="send_enabled" value="1"<?= $gateway['send_enabled'] ? ' checked' : '' ?>> ارسال فعال</label>
    <label><input type="checkbox" name="status_enabled" value="1"<?= $gateway['status_enabled'] ? ' checked' : '' ?>> استعلام وضعیت تحویل</label>
    <button class="btn btn-primary">ذخیره</button>
  </form>
</div>

<?php foreach ([['send', 'کانکتور ارسال', $sendConnector], ['status', 'کانکتور وضعیت تحویل', $statusConnector]] as [$kind, $title, $connectorRow]): ?>
<div class="card">
  <h2><?= $title ?></h2>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="do" value="connector_save">
    <input type="hidden" name="gateway_id" value="<?= $gatewayId ?>"><input type="hidden" name="tab" value="connector">
    <input type="hidden" name="connector" value="<?= $kind ?>">
    <div class="toolbar">
      <label style="flex:1 1 100%">آدرس
        <input type="url" name="endpoint_url" class="ltr" style="width:100%"
               value="<?= e((string)($connectorRow['endpoint_url'] ?? '')) ?>" placeholder="https://provider.example/api/send">
      </label>
      <label>روش
        <select name="http_method">
          <?php foreach (($kind === 'send' ? ['POST', 'GET', 'PUT', 'PATCH'] : ['GET', 'POST']) as $method): ?>
            <option value="<?= $method ?>"<?= ($connectorRow['http_method'] ?? '') === $method ? ' selected' : '' ?>><?= $method ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>نوع محتوا
        <select name="content_type">
          <option value="application/json"<?= ($connectorRow['content_type'] ?? '') === 'application/json' ? ' selected' : '' ?>>application/json</option>
          <option value="application/x-www-form-urlencoded"<?= ($connectorRow['content_type'] ?? '') === 'application/x-www-form-urlencoded' ? ' selected' : '' ?>>form-urlencoded</option>
        </select>
      </label>
      <label>مهلت اتصال (ms) <input type="number" name="connect_timeout_ms" class="ltr" value="<?= (int)($connectorRow['connect_timeout_ms'] ?? 5000) ?>"></label>
      <label>مهلت پاسخ (ms) <input type="number" name="request_timeout_ms" class="ltr" value="<?= (int)($connectorRow['request_timeout_ms'] ?? ($kind === 'send' ? 30000 : 15000)) ?>"></label>
      <label>احراز هویت
        <select name="auth_type">
          <?php foreach (GATEWAY_AUTH_TYPES as $authType): ?>
            <option value="<?= $authType ?>"<?= ($connectorRow['auth_type'] ?? 'none') === $authType ? ' selected' : '' ?>><?= $authType ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>

    <?php $authConfig = gateway_json($connectorRow['auth_config_json'] ?? null) ?? []; ?>
    <div class="toolbar">
      <label>نام هدر <input type="text" name="auth_header" class="ltr" value="<?= e((string)($authConfig['header'] ?? '')) ?>" placeholder="Authorization"></label>
      <label>نام پارامتر <input type="text" name="auth_param" class="ltr" value="<?= e((string)($authConfig['param'] ?? '')) ?>" placeholder="api_key"></label>
      <label>نام کاربری <input type="text" name="auth_username" class="ltr" value="<?= e((string)($authConfig['username'] ?? '')) ?>"></label>
      <label>کلید محرمانه‌ی توکن <input type="text" name="auth_token_secret" class="ltr" value="<?= e((string)($authConfig['token_secret'] ?? '')) ?>" placeholder="api_token"></label>
      <label>کلید محرمانه‌ی گذرواژه <input type="text" name="auth_password_secret" class="ltr" value="<?= e((string)($authConfig['password_secret'] ?? '')) ?>"></label>
    </div>
    <p class="muted">در این بخش فقط «نام» کلیدهای محرمانه وارد می‌شود، نه مقدار آن‌ها. مقادیر در برگه‌ی «کلیدهای محرمانه» ذخیره می‌شوند و هرگز نمایش داده نمی‌شوند.</p>

    <?php if ($kind === 'send'): ?>
      <label style="display:block">قاعده‌ی موفقیت (JSON)
        <textarea name="success_rule_json" rows="3" class="ltr" style="width:100%"><?= e((string)($connectorRow['success_rule_json'] ?? '')) ?></textarea>
      </label>
      <label style="display:block">نگاشت پاسخ (JSON)
        <textarea name="response_mapping_json" rows="3" class="ltr" style="width:100%"><?= e((string)($connectorRow['response_mapping_json'] ?? '')) ?></textarea>
      </label>
      <label style="display:block">نگاشت خطا (JSON)
        <textarea name="error_mapping_json" rows="3" class="ltr" style="width:100%"><?= e((string)($connectorRow['error_mapping_json'] ?? '')) ?></textarea>
      </label>
      <label style="display:block">نگاشت پاسخ دسته‌ای (JSON)
        <textarea name="batch_mapping_json" rows="3" class="ltr" style="width:100%"><?= e((string)($connectorRow['batch_mapping_json'] ?? '')) ?></textarea>
      </label>
    <?php else: ?>
      <label style="display:block">شرط موفقیت پاسخ (JSON — فقط شرط‌های اضافی)
        <textarea name="success_rule_json" rows="3" class="ltr" style="width:100%" placeholder='{"rules":[{"path":"errorModel.errorCode","operator":"equals","values":[0]}]}'><?= e((string)($connectorRow['success_rule_json'] ?? '')) ?></textarea>
      </label>
      <p class="muted">
        شرط پایه (وضعیت HTTP در بازه‌ی ۲xx و بدنه‌ی JSON معتبر) همیشه اعمال می‌شود و قابل تغییر نیست؛
        این مقدار فقط شرط‌های سخت‌گیرانه‌تر اضافه می‌کند. برای نمونه، خطای سطح ارائه‌دهنده در بدنه‌ی پاسخ:
        <span class="ltr">errorModel.errorCode = 0</span>. اگر این شرط برقرار نباشد، هیچ وضعیتی از پاسخ خوانده نمی‌شود.
      </p>
      <label style="display:block">نگاشت پاسخ (JSON)
        <textarea name="response_mapping_json" rows="4" class="ltr" style="width:100%" placeholder='{"items_path":"states","id_path":"id","status_path":"state"}'><?= e((string)($connectorRow['response_mapping_json'] ?? '')) ?></textarea>
      </label>
      <p class="muted">
        برای پاسخ گروهی، <span class="ltr">items_path</span> محل آرایه‌ی نتایج، <span class="ltr">id_path</span> کلید
        شناسه‌ی پیام و <span class="ltr">status_path</span> کلید وضعیت را مشخص می‌کند. تطبیق هر نتیجه با پیام اصلی
        بر اساس شناسه انجام می‌شود، نه ترتیب آرایه؛ بنابراین ترتیب پاسخ ارائه‌دهنده اهمیتی ندارد.
        اگر <span class="ltr">items_path</span> خالی بماند، کانکتور تک‌پیامی در نظر گرفته می‌شود.
      </p>
      <label style="display:block">نگاشت وضعیت تحویل (JSON)
        <textarea name="status_mapping_json" rows="3" class="ltr" style="width:100%"><?= e((string)($connectorRow['status_mapping_json'] ?? '')) ?></textarea>
      </label>
      <div class="toolbar">
        <label>تأخیر اولین استعلام (ثانیه) <input type="number" name="poll_initial_delay_seconds" class="ltr" value="<?= (int)($connectorRow['poll_initial_delay_seconds'] ?? 30) ?>"></label>
        <label>حداکثر تعداد استعلام <input type="number" name="poll_max_attempts" class="ltr" value="<?= (int)($connectorRow['poll_max_attempts'] ?? 6) ?>"></label>
        <label>حداکثر عمر پیام (ثانیه) <input type="number" name="poll_max_age_seconds" class="ltr" value="<?= (int)($connectorRow['poll_max_age_seconds'] ?? 86400) ?>"></label>
      </div>
      <p class="muted">وضعیت نگاشت‌نشده همیشه «نامشخص» می‌شود و هرگز «تحویل‌شده» در نظر گرفته نمی‌شود.</p>
    <?php endif; ?>

    <button class="btn btn-primary">ذخیره‌ی کانکتور</button>
  </form>
</div>
<?php endforeach; ?>

<?php if ($auditRows !== []): ?>
<div class="card">
  <h2>تاریخچه‌ی تغییرات</h2>
  <table class="table">
    <thead><tr><th>زمان</th><th>تغییر</th><th>نسخه</th><th>جزئیات</th></tr></thead>
    <tbody>
    <?php foreach ($auditRows as $row): ?>
      <tr>
        <td class="ltr"><?= e((string)$row['created_at']) ?></td>
        <td class="ltr"><?= e((string)$row['change_type']) ?></td>
        <td class="ltr">v<?= (int)$row['version_before'] ?> → v<?= (int)$row['version_after'] ?></td>
        <td class="ltr"><?= e(mb_strimwidth((string)$row['detail'], 0, 120, '…')) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php elseif ($tab === 'parameters'): ?>
<div class="card">
  <h2>پارامترها</h2>
  <p class="muted">
    اولویت مقادیر ثابت است: درگاه &lt; مسیر &lt; اپراتور. مقدار با دامنه‌ی محدودتر همیشه جایگزین می‌شود.
    در قالب‌ها فقط متغیرهای مجاز به شکل <span class="ltr">{{variable}}</span> پذیرفته می‌شوند؛ متغیر ناشناخته ذخیره نمی‌شود.
  </p>
  <table class="table">
    <thead><tr><th>کانکتور</th><th>محل</th><th>دامنه</th><th>نام</th><th>نوع مقدار</th><th>مقدار</th><th>نوع داده</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($parameters as $row): ?>
      <tr>
        <td><?= $row['connector'] === 'status' ? 'وضعیت' : 'ارسال' ?></td>
        <td class="ltr"><?= e($row['location']) ?></td>
        <td class="ltr"><?= e($row['scope']) ?><?= $row['scope_id'] ? '#' . (int)$row['scope_id'] : '' ?></td>
        <td class="ltr"><?= e($row['param_key']) ?></td>
        <td class="ltr"><?= e($row['value_type']) ?></td>
        <td class="ltr"><?= in_array($row['value_type'], ['secret', 'env_secret'], true) ? e($row['value']) . ' (مقدار پنهان)' : e(mb_strimwidth((string)$row['value'], 0, 60, '…')) ?></td>
        <td class="ltr"><?= e($row['data_type']) ?></td>
        <td>
          <form method="post" style="display:inline">
            <?= csrf_field() ?><input type="hidden" name="do" value="parameter_delete">
            <input type="hidden" name="gateway_id" value="<?= $gatewayId ?>"><input type="hidden" name="tab" value="parameters">
            <input type="hidden" name="parameter_id" value="<?= (int)$row['id'] ?>">
            <button class="btn">حذف</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if ($parameters === []): ?><tr><td colspan="8" class="muted">هنوز پارامتری تعریف نشده است.</td></tr><?php endif; ?>
    </tbody>
  </table>

  <h3>افزودن / ویرایش پارامتر</h3>
  <form method="post" class="toolbar">
    <?= csrf_field() ?><input type="hidden" name="do" value="parameter_save">
    <input type="hidden" name="gateway_id" value="<?= $gatewayId ?>"><input type="hidden" name="tab" value="parameters">
    <label>کانکتور
      <select name="connector" id="param-connector"><option value="send">ارسال</option><option value="status">وضعیت</option></select>
    </label>
    <label>محل
      <select name="location"><option value="body">body</option><option value="query">query</option><option value="header">header</option></select>
    </label>
    <label>دامنه
      <select name="scope"><option value="gateway">درگاه</option><option value="route">مسیر</option><option value="operator">اپراتور</option></select>
    </label>
    <label>مسیر/اپراتور
      <select name="scope_id">
        <option value="0">—</option>
        <optgroup label="مسیرها">
          <?php foreach ($routes as $route): ?><option value="<?= (int)$route['id'] ?>">مسیر: <?= e($route['code']) ?></option><?php endforeach; ?>
        </optgroup>
        <optgroup label="اپراتورها">
          <?php foreach ($operators as $operator): ?><option value="<?= (int)$operator['id'] ?>">اپراتور: <?= e($operator['code']) ?></option><?php endforeach; ?>
        </optgroup>
      </select>
    </label>
    <label>نام <input type="text" name="param_key" class="ltr" required></label>
    <label>نوع مقدار
      <select name="value_type" id="param-value-type">
        <option value="static">ثابت</option><option value="variable">متغیر</option><option value="template">قالب</option>
        <option value="secret">کلید محرمانه</option><option value="env_secret">متغیر محیطی مجاز</option>
        <option value="timestamp">زمان</option><option value="uuid">شناسه‌ی یکتا</option>
      </select>
    </label>
    <label>مقدار
      <input type="text" name="value" id="param-value" class="ltr" list="param-variable-options">
      <datalist id="param-variable-options"></datalist>
    </label>
    <label>نوع داده
      <select name="data_type" id="param-data-type">
        <option value="string">string</option><option value="integer">integer</option><option value="numeric">numeric</option>
        <option value="string_list">string_list</option><option value="integer_list">integer_list</option>
        <option value="boolean">boolean</option><option value="json">json</option><option value="null">null</option>
      </select>
    </label>
    <label>ترتیب <input type="number" name="sort_order" class="ltr" value="100" size="4"></label>
    <button class="btn btn-primary">ذخیره</button>
  </form>
  <p class="muted">متغیرهای مجاز ارسال: <span class="ltr"><?= e(implode(', ', GATEWAY_SEND_VARIABLES)) ?></span></p>
  <p class="muted">متغیرهای مجاز وضعیت: <span class="ltr"><?= e(implode(', ', GATEWAY_STATUS_VARIABLES)) ?></span></p>
  <p class="muted">
    برای استعلام گروهی وضعیت، پارامتری با متغیر <span class="ltr">provider_message_ids</span> و نوع داده‌ی
    <span class="ltr">integer_list</span> تعریف کنید؛ خروجی آن آرایه‌ای از اعداد است
    (<span class="ltr">[7310136179845801812, 776846774851635393]</span>) و شناسه‌های بلند بدون از دست رفتن دقت ارسال می‌شوند.
    برای یک پیام هم آرایه‌ی تک‌عضوی تولید می‌شود، نه عدد تنها.
  </p>
</div>

<script>
// A CONVENIENCE, never the enforcement: the variable catalogs below are rendered from the same PHP
// constants the compiler validates against, and every value is re-validated server-side through
// gateway_parameter_compile() before it is stored. This only spares an admin a round trip.
(function () {
  var catalogs = <?= json_encode(['send' => GATEWAY_SEND_VARIABLES, 'status' => GATEWAY_STATUS_VARIABLES]) ?>;
  // Variables whose natural serialization is a list rather than a scalar.
  var listTypes = { provider_message_ids: 'integer_list', recipients: 'string_list' };

  var connector = document.getElementById('param-connector');
  var valueType = document.getElementById('param-value-type');
  var value     = document.getElementById('param-value');
  var dataType  = document.getElementById('param-data-type');
  var options   = document.getElementById('param-variable-options');
  if (!connector || !valueType || !value || !dataType || !options) { return; }

  function refresh() {
    var names = catalogs[connector.value] || [];
    options.innerHTML = '';
    if (valueType.value === 'variable') {
      names.forEach(function (name) {
        var option = document.createElement('option');
        option.value = name;
        options.appendChild(option);
      });
    }
    // A variable that belongs to the OTHER connector is a guaranteed save failure; clearing it is
    // friendlier than letting the admin submit and read a Persian error.
    if (valueType.value === 'variable' && value.value && names.indexOf(value.value) === -1) {
      value.value = '';
    }
  }

  function suggestDataType() {
    if (valueType.value !== 'variable') { return; }
    var suggested = listTypes[value.value];
    if (suggested) { dataType.value = suggested; }
  }

  connector.addEventListener('change', refresh);
  valueType.addEventListener('change', refresh);
  value.addEventListener('change', suggestDataType);
  refresh();
})();
</script>

<?php elseif ($tab === 'operators'): ?>
<div class="card">
  <h2>اپراتورهای این درگاه</h2>
  <p class="muted">اگر هیچ اپراتوری انتخاب نشود، درگاه بدون محدودیت همه‌ی اپراتورها را پشتیبانی می‌کند. در غیر این صورت، ارسال به اپراتور انتخاب‌نشده از این درگاه انجام نمی‌شود.</p>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="do" value="operators_save">
    <input type="hidden" name="gateway_id" value="<?= $gatewayId ?>"><input type="hidden" name="tab" value="operators">
    <?php foreach ($operators as $operator): ?>
      <label style="display:block">
        <input type="checkbox" name="operator_ids[]" value="<?= (int)$operator['id'] ?>"<?= in_array((int)$operator['id'], $assignedOperators, true) ? ' checked' : '' ?>>
        <span class="ltr"><?= e($operator['code']) ?></span> — <?= e($operator['name']) ?>
      </label>
    <?php endforeach; ?>
    <button class="btn btn-primary" style="margin-top:10px">ذخیره</button>
  </form>
</div>

<?php elseif ($tab === 'secrets'): ?>
<div class="card">
  <h2>کلیدهای محرمانه</h2>
  <?php if (!gateway_secrets_configured()): ?>
    <p class="muted"><strong>هشدار:</strong> متغیر محیطی <span class="ltr">SMS_GATEWAY_MASTER_KEY</span> تنظیم نشده است؛ تا زمانی که تنظیم نشود امکان ذخیره‌ی کلید محرمانه وجود ندارد.</p>
  <?php endif; ?>
  <p class="muted">مقدار کلیدها رمزگذاری‌شده ذخیره می‌شود و پس از ذخیره هرگز نمایش داده نمی‌شود — نه در این صفحه، نه در گزارش‌ها و نه در پیش‌نمایش درخواست.</p>
  <table class="table">
    <thead><tr><th>نام کلید</th><th>آخرین تغییر</th><th>مقدار</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($secrets as $row): ?>
      <tr>
        <td class="ltr"><?= e($row['secret_key']) ?></td>
        <td class="ltr"><?= e((string)$row['updated_at']) ?></td>
        <td class="ltr"><?= gateway_mask_secret('set') ?></td>
        <td>
          <form method="post" style="display:inline">
            <?= csrf_field() ?><input type="hidden" name="do" value="secret_delete">
            <input type="hidden" name="gateway_id" value="<?= $gatewayId ?>"><input type="hidden" name="tab" value="secrets">
            <input type="hidden" name="secret_key" value="<?= e($row['secret_key']) ?>">
            <button class="btn">حذف</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if ($secrets === []): ?><tr><td colspan="4" class="muted">هیچ کلید محرمانه‌ای ذخیره نشده است.</td></tr><?php endif; ?>
    </tbody>
  </table>

  <h3>ذخیره‌ی کلید</h3>
  <form method="post" class="toolbar" autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="do" value="secret_save">
    <input type="hidden" name="gateway_id" value="<?= $gatewayId ?>"><input type="hidden" name="tab" value="secrets">
    <label>نام کلید <input type="text" name="secret_key" class="ltr" placeholder="api_token" required></label>
    <label>مقدار <input type="password" name="secret_value" class="ltr" autocomplete="new-password"></label>
    <button class="btn btn-primary">ذخیره</button>
  </form>
</div>

<?php elseif ($tab === 'import'): ?>
<div class="card">
  <h2>ورود از دستور curl</h2>
  <p class="muted">
    دستور curl ارائه‌دهنده را اینجا بچسبانید تا به‌صورت «پیش‌نویس» تحلیل شود.
    این دستور <strong>هرگز اجرا نمی‌شود</strong> — فقط متن آن خوانده می‌شود و نتیجه را باید خودتان تأیید و ذخیره کنید.
    اطلاعات احراز هویت موجود در دستور عمداً منتقل نمی‌شود؛ آن‌ها را به‌صورت کلید محرمانه ذخیره کنید.
  </p>
  <form method="get" class="toolbar">
    <input type="hidden" name="gateway" value="<?= $gatewayId ?>"><input type="hidden" name="tab" value="import">
    <label style="flex:1 1 100%">دستور
      <textarea name="curl" rows="5" class="ltr" style="width:100%"><?= e((string)($_GET['curl'] ?? '')) ?></textarea>
    </label>
    <button class="btn btn-primary">تحلیل</button>
  </form>

  <?php if ($curlDraft !== null): ?>
    <h3>پیش‌نویس</h3>
    <?php foreach ($curlDraft['notes'] as $note): ?><p class="muted">• <?= e($note) ?></p><?php endforeach; ?>
    <p class="ltr">
      <?= e($curlDraft['method']) ?> <?= e($curlDraft['endpoint']) ?><br>
      Content-Type: <?= e($curlDraft['content_type']) ?>
    </p>
    <table class="table">
      <thead><tr><th>محل</th><th>نام</th><th>مقدار</th></tr></thead>
      <tbody>
      <?php foreach (['header' => $curlDraft['headers'], 'query' => $curlDraft['query'], 'body' => $curlDraft['body']] as $location => $items): ?>
        <?php foreach ($items as $key => $value): ?>
          <tr><td class="ltr"><?= $location ?></td><td class="ltr"><?= e((string)$key) ?></td><td class="ltr"><?= e(is_scalar($value) ? (string)$value : '') ?></td></tr>
        <?php endforeach; ?>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p class="muted">مقادیری که باید از پیام گرفته شوند را در برگه‌ی «پارامترها» به نوع «متغیر» یا «قالب» تغییر دهید.</p>
  <?php endif; ?>
</div>

<?php elseif ($tab === 'test'): ?>
<div class="card">
  <h2>پیش‌نمایش درخواست</h2>
  <p class="muted">درخواست دقیقاً با همان سازنده‌ای ساخته می‌شود که مسیر واقعی ارسال از آن استفاده می‌کند و <strong>ارسال نمی‌شود</strong>. مقادیر محرمانه پوشانده می‌شوند.</p>
  <form method="get" class="toolbar">
    <input type="hidden" name="gateway" value="<?= $gatewayId ?>"><input type="hidden" name="tab" value="test"><input type="hidden" name="preview" value="1">
    <label>خط ارسال <input type="text" name="sender" class="ltr" value="<?= e((string)($_GET['sender'] ?? '')) ?>"></label>
    <label>مقصد <input type="text" name="to" class="ltr" value="<?= e((string)($_GET['to'] ?? '')) ?>" placeholder="989121234567"></label>
    <label>متن <input type="text" name="text" value="<?= e((string)($_GET['text'] ?? 'نمونه پیام')) ?>"></label>
    <button class="btn btn-primary">ساخت پیش‌نمایش</button>
  </form>

  <?php if ($compiled === null): ?>
    <p class="muted">این درگاه کامپایل نمی‌شود؛ ابتدا خطاهای پیکربندی را برطرف کنید (<span class="ltr">make sms-gateway-integrity-check</span>).</p>
  <?php elseif ($dryRun !== null): ?>
    <h3>نتیجه</h3>
    <p class="ltr"><?= e($dryRun['preview']['method']) ?> <?= e($dryRun['preview']['endpoint']) ?></p>
    <p class="muted">اپراتور تشخیص‌داده‌شده: <span class="ltr"><?= e((string)$dryRun['operator']) ?></span></p>
    <table class="table">
      <thead><tr><th>محل</th><th>نام</th><th>مقدار</th></tr></thead>
      <tbody>
      <?php foreach (['header' => $dryRun['preview']['headers'], 'query' => $dryRun['preview']['query'], 'body' => $dryRun['preview']['body']] as $location => $items): ?>
        <?php foreach ($items as $key => $value): ?>
          <tr><td class="ltr"><?= $location ?></td><td class="ltr"><?= e((string)$key) ?></td>
              <td class="ltr"><?= e(is_scalar($value) ? (string)$value : json_encode($value, JSON_UNESCAPED_UNICODE)) ?></td></tr>
        <?php endforeach; ?>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p class="ltr">بدنه: <?= e((string)($dryRun['body'] ?? '—')) ?></p>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/../app/views/footer.php'; ?>
