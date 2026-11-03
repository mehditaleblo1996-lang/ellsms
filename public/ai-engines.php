<?php
/**
 * ELLSMS — Platform Admin -> AI engines (موتورهای هوش مصنوعی). See docs/ai-engines.md.
 *
 * PLATFORM ADMIN ONLY (require_admin()), like the SMS gateway page: a provider spends money on an
 * external account and decides where customers' text is sent. Every mutation is a POST through
 * csrf_check() and the support-session guard.
 *
 * Views:
 *   providers (default)  provider list + «ارائه‌دهنده‌ی جدید»; with ?provider=ID its tabs:
 *                        settings (connection, API key, «تست اتصال», «تشخیص مدل‌ها»), models, history
 *   access               platform default model + which models each account may use
 *   playground           send one message to any model (or as an account) and see the reply
 *
 * THE API KEY IS WRITE-ONLY: it is never rendered back, a blank field keeps the stored key, and the
 * audit trail records only that it changed.
 */
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_admin();
$pageTitle = 'موتورهای هوش مصنوعی';
$active = 'ai_engines';

$view = (string)($_GET['view'] ?? $_POST['view'] ?? 'providers');
if (!in_array($view, ['providers', 'access', 'pricing', 'usage', 'playground'], true)) $view = 'providers';
if (in_array($view, ['pricing', 'usage'], true) && !ai_billing_available()) $view = 'providers';
$providerId = (int)($_GET['provider'] ?? $_POST['provider_id'] ?? 0);
$tab = (string)($_GET['tab'] ?? $_POST['tab'] ?? 'settings');
if (!in_array($tab, ['settings', 'models', 'history'], true)) $tab = 'settings';

if (!ai_engines_available()) {
    require __DIR__ . '/../app/views/header.php';
    echo '<div class="card"><h2>موتورهای هوش مصنوعی</h2><p class="muted">ابتدا مایگریشن '
        . '<span class="ltr">db/migrations/2026_11_03_ai_engines.sql</span> را اجرا کنید '
        . '(<span class="ltr">make db-migrations-apply</span>).</p></div>';
    require __DIR__ . '/../app/views/footer.php';
    exit;
}

$self = static function (array $params = []) use ($view, $providerId, $tab): string {
    $params += ['view' => $view];
    if ($params['view'] === 'providers' && $providerId > 0 && !array_key_exists('provider', $params)) {
        $params['provider'] = $providerId;
        $params += ['tab' => $tab];
    }
    return '/ai-engines.php?' . http_build_query(array_filter($params, static fn($v) => $v !== null && $v !== ''));
};

$playgroundResult = null;
$accessUser = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = (string)($_POST['do'] ?? '');
    if (impersonation_guard_post($do === 'secret_save' || $do === 'secret_delete' ? 'ai.secret' : 'ai.config')) {
        redirect($self());
    }
    $actor = (int)$me['id'];
    $stay = false;
    try {
        switch ($do) {
            case 'provider_create':
                $newId = ai_provider_create($_POST, $actor);
                $key = (string)($_POST['api_key'] ?? '');
                if ($key !== '') ai_secret_put($newId, 'api_key', $key);
                flash('success', 'ارائه‌دهنده ساخته شد. اکنون «تست اتصال» و سپس «تشخیص مدل‌ها» را بزنید.');
                redirect('/ai-engines.php?provider=' . $newId . '&tab=settings');

            case 'provider_update':
                ai_provider_update($providerId, $_POST, $actor);
                flash('success', 'تنظیمات ارائه‌دهنده ذخیره شد.');
                break;

            case 'provider_delete':
                ai_provider_delete($providerId, $actor);
                flash('success', 'ارائه‌دهنده و مدل‌هایش حذف شدند.');
                redirect('/ai-engines.php');

            case 'secret_save': {
                $key = (string)($_POST['api_key'] ?? '');
                if ($key === '') {
                    flash('error', 'مقدار کلید خالی است؛ چیزی تغییر نکرد.');
                    break;
                }
                ai_provider_find($providerId) ?? throw new AppException('ارائه‌دهنده پیدا نشد.');
                ai_secret_put($providerId, 'api_key', $key);
                audit($actor, 'ai.provider.secret', json_encode(['provider_id' => $providerId, 'changed' => true]) ?: '');
                flash('success', 'کلید API ذخیره شد.');
                break;
            }

            case 'secret_delete':
                ai_secret_delete($providerId, 'api_key');
                audit($actor, 'ai.provider.secret', json_encode(['provider_id' => $providerId, 'deleted' => true]) ?: '');
                flash('success', 'کلید API حذف شد.');
                break;

            case 'provider_test': {
                $r = ai_provider_test($providerId, $actor);
                flash($r['ok'] ? 'success' : 'error', $r['message']);
                break;
            }

            case 'models_discover': {
                $r = ai_discover_models($providerId, $actor);
                flash($r['ok'] ? 'success' : 'error', $r['message']);
                $tab = 'models';
                break;
            }

            case 'model_add':
                ai_model_add_manual($providerId, (string)($_POST['external_model_id'] ?? ''), (string)($_POST['display_name'] ?? ''), $actor);
                flash('success', 'مدل اضافه شد (غیرفعال). پس از تست، آن را فعال کنید.');
                $tab = 'models';
                break;

            case 'model_update':
                ai_model_update((int)$_POST['model_id'], $_POST, $actor);
                flash('success', 'مدل ذخیره شد.');
                $tab = 'models';
                break;

            case 'model_toggle':
                ai_model_set_status((int)$_POST['model_id'], ($_POST['status'] ?? '') === 'active', $actor);
                $tab = 'models';
                break;

            case 'model_delete':
                ai_model_delete((int)$_POST['model_id'], $actor);
                flash('success', 'مدل حذف شد.');
                $tab = 'models';
                break;

            case 'model_test': {
                $r = ai_model_test((int)$_POST['model_id'], $actor);
                flash($r['ok'] ? 'success' : 'error', $r['ok']
                    ? 'مدل پاسخ داد (' . $r['latency_ms'] . ' ms): «' . mb_strimwidth($r['text'], 0, 80, '…') . '»'
                    : $r['message']);
                $tab = 'models';
                break;
            }

            case 'billing_settings_save':
                ai_billing_settings_save($_POST, $actor);
                flash('success', 'تنظیمات تعرفه‌ی هوش مصنوعی ذخیره شد.');
                break;

            case 'prices_global_save': {
                [$rows, $error] = ai_prices_from_form((array)($_POST['price'] ?? []), true);
                if ($error !== null) throw new AppException($error);
                ai_prices_save('global', 0, $rows, $actor);
                flash('success', 'تعرفه‌ی عمومی هوش مصنوعی ذخیره شد.');
                break;
            }

            case 'prices_account_save': {
                $userId = (int)($_POST['user_id'] ?? 0);
                if ($userId <= 0 || backend_usernames_by_ids([$userId]) === []) throw new AppException('کاربر پیدا نشد.');
                if (!customer_prices_eligible($userId)) {
                    throw new AppException('این حساب زیر نماینده یا زیرسازمان است؛ قیمتش از تعرفه‌ی نماینده یا سازمان بالادست می‌آید.');
                }
                [$rows, $error] = ai_prices_from_form((array)($_POST['price'] ?? []), false);
                if ($error !== null) throw new AppException($error);
                ai_prices_save('account', $userId, $rows, $actor);
                flash('success', 'قیمت اختصاصی هوش مصنوعی این حساب ذخیره شد.');
                redirect('/ai-engines.php?view=pricing&price_user=' . $userId);
            }

            case 'settings_save':
                ai_settings_save((int)($_POST['default_model_id'] ?? 0), !empty($_POST['default_access']), $actor);
                flash('success', 'تنظیمات دسترسی پیش‌فرض ذخیره شد.');
                break;

            case 'access_save': {
                $userId = (int)($_POST['user_id'] ?? 0);
                if ($userId <= 0 || backend_usernames_by_ids([$userId]) === []) throw new AppException('کاربر پیدا نشد.');
                $custom = ($_POST['mode'] ?? 'inherit') === 'custom';
                ai_access_save($userId, $custom, (array)($_POST['model_ids'] ?? []),
                    ($_POST['default_model_id'] ?? '') === '' ? null : (int)$_POST['default_model_id'], $actor);
                flash('success', 'دسترسی مدل‌های این کاربر ذخیره شد.');
                redirect('/ai-engines.php?view=access&user=' . $userId);
            }

            case 'playground': {
                $stay = true;
                $message = trim((string)($_POST['message'] ?? ''));
                $system = trim((string)($_POST['system'] ?? ''));
                $maxTokens = max(1, min(4000, (int)($_POST['max_tokens'] ?? 300)));
                $temperature = trim((string)($_POST['temperature'] ?? '')) === '' ? null : max(0.0, min(2.0, (float)$_POST['temperature']));
                if ($message === '' || mb_strlen($message) > 4000) throw new AppException('متن پیام باید ۱ تا ۴۰۰۰ نویسه باشد.');
                $modelId = (int)($_POST['model_id'] ?? 0);
                $asUser = trim((string)($_POST['as_user'] ?? ''));
                $resolvedNote = null;
                if ($asUser !== '') {
                    $uid = ctype_digit($asUser) ? (int)$asUser : (int)backend_find_user_id_by_username($asUser);
                    if ($uid <= 0 || backend_usernames_by_ids([$uid]) === []) throw new AppException('کاربر «' . $asUser . '» پیدا نشد.');
                    $eff = ai_access_effective($uid);
                    $effectiveIds = array_map(static fn(array $m): int => (int)$m['id'], $eff['models']);
                    if ($modelId > 0 && !in_array($modelId, $effectiveIds, true)) {
                        throw new AppException('این مدل برای این کاربر مجاز نیست.');
                    }
                    if ($modelId <= 0) {
                        if ($eff['default'] === null) throw new AppException('هیچ مدلی برای این کاربر فعال نیست.');
                        $modelId = (int)$eff['default']['id'];
                    }
                    $resolvedNote = 'به‌عنوان کاربر #' . $uid . ' — مدل: ' . (ai_model_find($modelId)['display_name'] ?? '');
                }
                if ($modelId <= 0) throw new AppException('یک مدل انتخاب کنید.');
                $messages = [];
                if ($system !== '') $messages[] = ['role' => 'system', 'content' => $system];
                $messages[] = ['role' => 'user', 'content' => $message];
                $playgroundResult = ai_model_chat($modelId, $messages, $maxTokens, $temperature, 'playground', $actor) + ['note' => $resolvedNote, 'model_id' => $modelId];
                break;
            }

            default:
                flash('error', 'عملیات ناشناخته.');
        }
    } catch (AiSecretException | AppException $e) {
        flash('error', $e->getMessage());
        if ($do === 'access_save' && (int)($_POST['user_id'] ?? 0) > 0) {
            redirect('/ai-engines.php?view=access&user=' . (int)$_POST['user_id']);
        }
        if ($do === 'playground') $stay = true;
    }
    if (!$stay) {
        redirect($self(['tab' => $tab]));
    }
}

/* ---------------- Data for the view ---------------- */
$providers = ai_providers_all();
$provider = $providerId > 0 ? ai_provider_find($providerId) : null;
$allModels = ai_models_all();
$activeModels = array_values(array_filter($allModels, static fn(array $m): bool => $m['status'] === 'active' && $m['provider_status'] === 'active'));
$secretsReady = ai_secrets_configured();

if ($view === 'access' && isset($_GET['user']) && trim((string)$_GET['user']) !== '') {
    $needle = trim((string)$_GET['user']);
    $uid = ctype_digit($needle) ? (int)$needle : (int)backend_find_user_id_by_username($needle);
    $found = $uid > 0 ? backend_usernames_by_ids([$uid]) : [];
    if ($found === []) {
        flash('error', 'کاربر «' . $needle . '» پیدا نشد.');
    } else {
        $accessUser = ['id' => $uid, 'username' => (string)(backend_usernames_by_ids([$uid])[$uid] ?? ('#' . $uid))] + ai_access_effective($uid);
    }
}

$priceUser = null;
if ($view === 'pricing' && trim((string)($_GET['price_user'] ?? '')) !== '') {
    $needle = trim((string)$_GET['price_user']);
    $uid = ctype_digit($needle) ? (int)$needle : (int)backend_find_user_id_by_username($needle);
    $name = $uid > 0 ? (backend_usernames_by_ids([$uid])[$uid] ?? null) : null;
    if ($name === null) {
        flash('error', 'کاربر «' . $needle . '» پیدا نشد.');
    } else {
        $priceUser = ['id' => $uid, 'username' => (string)$name, 'eligible' => customer_prices_eligible($uid), 'rows' => ai_prices_for('account', $uid)];
    }
}
$rialOf = static fn(?int $millis): string => $millis === null ? '—' : to_persian_digits(number_format($millis / SMS_PRICE_SCALE, $millis % SMS_PRICE_SCALE === 0 ? 0 : 3));

$sourceLabels = ['custom' => 'مدل‌های اختصاصی همین حساب', 'inherited' => 'به ارث رسیده از حساب بالادست (نماینده/سازمان)',
                 'platform' => 'مدل پیش‌فرض سامانه', 'none' => 'هیچ مدلی'];
$statusBadge = static function (string $status): string {
    return match ($status) {
        'healthy'   => '<strong style="color:#15803d">سالم</strong>',
        'unhealthy' => '<strong style="color:#b91c1c">ناموفق</strong>',
        default     => '<span class="muted">تست نشده</span>',
    };
};

require __DIR__ . '/../app/views/header.php';
?>
<div class="card">
  <h2>موتورهای هوش مصنوعی</h2>
  <p class="muted">
    ارائه‌دهنده‌های هوش مصنوعی را مثل درگاه‌های پیامک وصل کنید: آدرس و کلید را وارد کنید، «تست اتصال» و «تشخیص مدل‌ها» را بزنید،
    مدل‌های دلخواه را فعال کنید و آن‌ها را به کاربران اختصاص دهید. Hermes و هر سرور سازگار با OpenAI از نوع
    «سازگار با OpenAI» تعریف می‌شوند.
  </p>
  <div class="toolbar">
    <?php foreach (['providers' => 'ارائه‌دهنده‌ها و مدل‌ها', 'access' => 'دسترسی کاربران'] + (ai_billing_available() ? ['pricing' => 'تعرفه‌ی هوش مصنوعی', 'usage' => 'مصرف و سود'] : []) + ['playground' => 'چت آزمایشی'] as $key => $label): ?>
      <a class="btn<?= $view === $key ? ' btn-primary' : '' ?>" href="/ai-engines.php?view=<?= $key ?>"><?= $label ?></a>
    <?php endforeach; ?>
  </div>
  <?php if (!$secretsReady): ?>
    <p class="muted"><strong>هشدار:</strong> متغیر محیطی <span class="ltr">AI_MASTER_KEY</span> (یا <span class="ltr">SMS_GATEWAY_MASTER_KEY</span>)
      تنظیم نشده است؛ ذخیره‌ی کلید API ممکن نیست. ارائه‌دهنده‌ی بدون کلید (مثل Hermes داخلی) کار می‌کند.</p>
  <?php endif; ?>
</div>

<?php if ($view === 'providers'): ?>

<div class="card">
  <h2>ارائه‌دهنده‌ها</h2>
  <table class="table">
    <thead><tr><th>شناسه</th><th>نام</th><th>نوع</th><th>آدرس</th><th>وضعیت</th><th>مدل‌ها (فعال/کل)</th><th>آخرین تست</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($providers as $row): ?>
      <tr>
        <td class="mono"><?= e($row['code']) ?></td>
        <td><?= e($row['name']) ?></td>
        <td><?= e(AI_PROVIDER_TYPES[$row['type']] ?? $row['type']) ?></td>
        <td><span class="mono"><?= e(mb_strimwidth((string)$row['base_url'], 0, 48, '…')) ?></span><?= $row['allow_private_network'] ? ' <span class="badge">داخلی</span>' : '' ?></td>
        <td><?= $row['status'] === 'active' ? 'فعال' : 'غیرفعال' ?></td>
        <td class="ltr"><?= (int)$row['active_model_count'] ?> / <?= (int)$row['model_count'] ?></td>
        <td>
          <?= $statusBadge((string)$row['last_test_status']) ?>
          <?php if ($row['last_latency_ms'] !== null): ?><span class="muted ltr"><?= (int)$row['last_latency_ms'] ?> ms</span><?php endif; ?>
          <?php if ($row['last_test_status'] === 'unhealthy' && $row['last_error_code']): ?><br><span class="muted"><?= e(ai_error_label((string)$row['last_error_code'])) ?></span><?php endif; ?>
        </td>
        <td><a class="btn" href="/ai-engines.php?provider=<?= (int)$row['id'] ?>&tab=settings">مدیریت</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($providers === []): ?><tr><td colspan="8" class="muted">هنوز ارائه‌دهنده‌ای تعریف نشده است.</td></tr><?php endif; ?>
    </tbody>
  </table>

  <details<?= $providers === [] ? ' open' : '' ?>>
  <summary><strong>ارائه‌دهنده‌ی جدید</strong></summary>
  <form method="post" class="toolbar" autocomplete="off" id="ai-create">
    <?= csrf_field() ?><input type="hidden" name="do" value="provider_create">
    <label>نوع
      <select name="type" id="ai-type">
        <?php foreach (AI_PROVIDER_TYPES as $type => $label): ?><option value="<?= $type ?>"><?= e($label) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label>شناسه <input type="text" name="code" class="ltr" placeholder="hermes_stage" required pattern="[a-z0-9_]{2,40}"></label>
    <label>نام <input type="text" name="name" placeholder="Hermes استیج" required></label>
    <label style="flex:1 1 100%">آدرس پایه
      <input type="text" name="base_url" id="ai-base-url" class="mono" style="width:100%" placeholder="http://host.docker.internal:8642/v1">
    </label>
    <label>احراز هویت
      <select name="auth_scheme">
        <?php foreach (AI_AUTH_SCHEMES as $scheme => $label): ?><option value="<?= $scheme ?>"><?= e($label) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label>نام هدر (برای «هدر سفارشی») <input type="text" name="auth_header" class="ltr" placeholder="api-key"></label>
    <label>کلید API <input type="password" name="api_key" class="ltr" autocomplete="new-password" placeholder="اختیاری"></label>
    <label><input type="checkbox" name="allow_private_network" value="1" id="ai-private"> اجازه‌ی آدرس داخلی / http (سرور داخلی مثل Hermes)</label>
    <input type="hidden" name="connect_timeout_ms" value="5000"><input type="hidden" name="request_timeout_ms" value="60000">
    <button class="btn btn-primary">ساخت</button>
  </form>
  <p class="muted">
    نمونه‌ی Hermes روی استیج: نوع «سازگار با OpenAI»، آدرس <span class="mono">http://&lt;host&gt;:8642/v1</span>، احراز هویت «بدون کلید»
    (یا Bearer اگر <span class="ltr">API_SERVER_KEY</span> روی Hermes فعال است) و تیک «آدرس داخلی».
  </p>
  </details>
</div>
<script>
// Convenience only: pre-fills the usual base URL of the chosen type while the field is empty or still
// holds another type's default. The server validates the URL either way.
(function () {
  var defaults = <?= json_encode(AI_PROVIDER_DEFAULT_BASE_URLS) ?>;
  var type = document.getElementById('ai-type'), url = document.getElementById('ai-base-url'), priv = document.getElementById('ai-private');
  if (!type || !url) { return; }
  type.addEventListener('change', function () {
    var known = Object.keys(defaults).map(function (k) { return defaults[k]; });
    if (url.value === '' || known.indexOf(url.value) !== -1) { url.value = defaults[type.value] || ''; }
    if (priv && (type.value === 'ollama' || type.value === 'openai_compatible')) { priv.checked = true; }
  });
  if (priv && type.value === 'openai_compatible') { priv.checked = true; }
})();
</script>

<?php if ($provider !== null): ?>
<?php $pid = (int)$provider['id']; $keySet = ai_secret_is_set($pid, 'api_key'); ?>
<div class="card">
  <div class="toolbar">
    <?php foreach (['settings' => 'اتصال و کلید', 'models' => 'مدل‌ها', 'history' => 'تاریخچه‌ی تست'] as $key => $label): ?>
      <a class="btn<?= $tab === $key ? ' btn-primary' : '' ?>" href="/ai-engines.php?provider=<?= $pid ?>&tab=<?= $key ?>"><?= $label ?></a>
    <?php endforeach; ?>
    <form method="post" style="display:inline">
      <?= csrf_field() ?><input type="hidden" name="do" value="provider_test"><input type="hidden" name="provider_id" value="<?= $pid ?>"><input type="hidden" name="tab" value="<?= e($tab) ?>">
      <button class="btn">تست اتصال</button>
    </form>
    <form method="post" style="display:inline">
      <?= csrf_field() ?><input type="hidden" name="do" value="models_discover"><input type="hidden" name="provider_id" value="<?= $pid ?>">
      <button class="btn btn-primary">تشخیص مدل‌ها</button>
    </form>
  </div>
  <p class="muted">
    <strong><?= e($provider['name']) ?></strong> — <span class="mono"><?= e($provider['code']) ?></span> — <?= e(AI_PROVIDER_TYPES[$provider['type']] ?? $provider['type']) ?> —
    وضعیت آخرین تست: <?= $statusBadge((string)$provider['last_test_status']) ?>
    <?php if ($provider['last_tested_at']): ?>(<?= jdate((string)$provider['last_tested_at']) ?>)<?php endif; ?>
    <?php if ($provider['last_test_status'] === 'unhealthy' && $provider['last_error_message']): ?><br>خطا: <?= e((string)$provider['last_error_message']) ?><?php endif; ?>
  </p>
</div>

<?php if ($tab === 'settings'): ?>
<div class="card">
  <h2>اتصال</h2>
  <form method="post" class="toolbar">
    <?= csrf_field() ?><input type="hidden" name="do" value="provider_update"><input type="hidden" name="provider_id" value="<?= $pid ?>"><input type="hidden" name="tab" value="settings">
    <label>نام <input type="text" name="name" value="<?= e($provider['name']) ?>" required></label>
    <label>وضعیت
      <select name="status">
        <option value="active"<?= $provider['status'] === 'active' ? ' selected' : '' ?>>فعال</option>
        <option value="disabled"<?= $provider['status'] === 'disabled' ? ' selected' : '' ?>>غیرفعال</option>
      </select>
    </label>
    <label style="flex:1 1 100%">آدرس پایه <input type="text" name="base_url" class="mono" style="width:100%" value="<?= e($provider['base_url']) ?>"></label>
    <label>احراز هویت
      <select name="auth_scheme"<?= $provider['type'] === 'anthropic' ? ' disabled' : '' ?>>
        <?php foreach (AI_AUTH_SCHEMES as $scheme => $label): ?>
          <option value="<?= $scheme ?>"<?= $provider['auth_scheme'] === $scheme ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if ($provider['type'] === 'anthropic'): ?><input type="hidden" name="auth_scheme" value="bearer"><?php endif; ?>
    </label>
    <label>نام هدر <input type="text" name="auth_header" class="ltr" value="<?= e($provider['auth_header']) ?>"></label>
    <label>مهلت اتصال (ms) <input type="number" name="connect_timeout_ms" class="ltr" value="<?= (int)$provider['connect_timeout_ms'] ?>"></label>
    <label>مهلت پاسخ (ms) <input type="number" name="request_timeout_ms" class="ltr" value="<?= (int)$provider['request_timeout_ms'] ?>"></label>
    <label><input type="checkbox" name="allow_private_network" value="1"<?= $provider['allow_private_network'] ? ' checked' : '' ?>> اجازه‌ی آدرس داخلی / http</label>
    <label style="flex:1 1 100%">هدرهای اضافه (هر سطر «نام: مقدار»)
      <textarea name="extra_headers" rows="2" class="mono" style="width:100%" placeholder="HTTP-Referer: https://panel.example"><?= e(ai_extra_headers_text($provider['extra_headers_json'])) ?></textarea>
    </label>
    <button class="btn btn-primary">ذخیره</button>
  </form>
  <?php if ($provider['type'] === 'anthropic'): ?>
    <p class="muted">برای Anthropic کلید همیشه در هدر <span class="mono">x-api-key</span> با نسخه‌ی API <span class="mono"><?= AI_ANTHROPIC_VERSION ?></span> ارسال می‌شود.</p>
  <?php endif; ?>
  <p class="muted">بدون «آدرس داخلی»، آدرس باید https باشد و به نشانی عمومی برسد؛ اتصال به همان نشانی بررسی‌شده قفل می‌شود و تغییر مسیر (redirect) دنبال نمی‌شود.</p>
</div>

<div class="card">
  <h2>کلید API</h2>
  <p>وضعیت: <?= $keySet ? '<strong>ذخیره شده</strong> <span class="ltr">••••••••</span>' : '<span class="muted">ذخیره نشده</span>' ?></p>
  <p class="muted">کلید رمزگذاری‌شده ذخیره می‌شود و پس از ذخیره هرگز نمایش داده نمی‌شود. خالی گذاشتن فیلد چیزی را تغییر نمی‌دهد.</p>
  <form method="post" class="toolbar" autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="do" value="secret_save"><input type="hidden" name="provider_id" value="<?= $pid ?>"><input type="hidden" name="tab" value="settings">
    <label>کلید جدید <input type="password" name="api_key" class="ltr" autocomplete="new-password"<?= $secretsReady ? '' : ' disabled' ?>></label>
    <button class="btn btn-primary"<?= $secretsReady ? '' : ' disabled' ?>>ذخیره‌ی کلید</button>
  </form>
  <?php if ($keySet): ?>
    <form method="post" style="display:inline" onsubmit="return confirm('کلید API حذف شود؟');">
      <?= csrf_field() ?><input type="hidden" name="do" value="secret_delete"><input type="hidden" name="provider_id" value="<?= $pid ?>"><input type="hidden" name="tab" value="settings">
      <button class="btn">حذف کلید</button>
    </form>
  <?php endif; ?>
</div>

<div class="card">
  <h2>حذف ارائه‌دهنده</h2>
  <p class="muted">همه‌ی مدل‌های این ارائه‌دهنده، دسترسی کاربران به آن‌ها و تاریخچه‌ی تست حذف می‌شود. برای توقف موقت، وضعیت را «غیرفعال» کنید.</p>
  <form method="post" onsubmit="return confirm('این ارائه‌دهنده و همه‌ی مدل‌هایش حذف شود؟');">
    <?= csrf_field() ?><input type="hidden" name="do" value="provider_delete"><input type="hidden" name="provider_id" value="<?= $pid ?>">
    <button class="btn">حذف</button>
  </form>
</div>

<?php elseif ($tab === 'models'): ?>
<?php $models = ai_models_for_provider($pid); ?>
<div class="card">
  <h2>مدل‌ها</h2>
  <p class="muted">
    مدل‌های شناسایی‌شده <strong>غیرفعال</strong> اضافه می‌شوند؛ پس از «تست» آن‌ها را فعال کنید. فقط مدل فعال روی ارائه‌دهنده‌ی فعال به کاربران قابل اختصاص است.
    قیمت‌ها هزینه‌ی خرید ما به ازای یک میلیون توکن است و مبنای تعرفه در فاز بعد خواهد بود.
  </p>
  <div style="overflow-x:auto">
  <table class="table">
    <thead><tr><th>مدل</th><th>نام نمایشی</th><th>حداکثر خروجی</th><th>پنجره‌ی متن</th><th>قیمت ورودی / خروجی (هر ۱M توکن)</th><th></th><th>آخرین تست</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($models as $m): $mid = (int)$m['id']; $f = 'model-form-' . $mid; ?>
      <tr>
        <td>
          <span class="mono"><?= e($m['external_model_id']) ?></span>
          <br><?= $m['status'] === 'active' ? '<strong style="color:#15803d">فعال</strong>' : '<span class="muted">غیرفعال</span>' ?>
          <?php if ($m['missing_since']): ?><br><span class="badge" title="<?= e((string)$m['missing_since']) ?>">دیگر فهرست نمی‌شود</span><?php endif; ?>
          <?php if ($m['source'] === 'manual'): ?><span class="muted"> · دستی</span><?php endif; ?>
          <form method="post" id="<?= $f ?>">
            <?= csrf_field() ?><input type="hidden" name="do" value="model_update"><input type="hidden" name="provider_id" value="<?= $pid ?>">
            <input type="hidden" name="tab" value="models"><input type="hidden" name="model_id" value="<?= $mid ?>">
            <input type="hidden" name="sort_order" value="<?= (int)$m['sort_order'] ?>"><input type="hidden" name="status" value="<?= e($m['status']) ?>">
          </form>
        </td>
        <td><input form="<?= $f ?>" type="text" name="display_name" value="<?= e($m['display_name']) ?>" style="min-width:9em;width:100%" aria-label="نام نمایشی"></td>
        <td><input form="<?= $f ?>" type="number" name="max_output_tokens" class="mono" value="<?= e((string)($m['max_output_tokens'] ?? '')) ?>" placeholder="—" style="width:7em" aria-label="حداکثر خروجی"></td>
        <td><input form="<?= $f ?>" type="number" name="context_window" class="mono" value="<?= e((string)($m['context_window'] ?? '')) ?>" placeholder="—" style="width:7em" aria-label="پنجره‌ی متن"></td>
        <td style="white-space:nowrap">
          <input form="<?= $f ?>" type="text" name="input_cost_per_million" class="mono" value="<?= e((string)($m['input_cost_per_million'] ?? '')) ?>" placeholder="0.00" style="width:5.5em" aria-label="قیمت ورودی">
          <input form="<?= $f ?>" type="text" name="output_cost_per_million" class="mono" value="<?= e((string)($m['output_cost_per_million'] ?? '')) ?>" placeholder="0.00" style="width:5.5em" aria-label="قیمت خروجی">
          <input form="<?= $f ?>" type="text" name="cost_currency" class="mono" value="<?= e((string)$m['cost_currency']) ?>" style="width:3.5em" aria-label="ارز">
        </td>
        <td><button form="<?= $f ?>" class="btn">ذخیره</button></td>
        <td>
          <?php if ($m['last_tested_at']): ?>
            <?= $m['last_test_ok'] ? '<strong style="color:#15803d">موفق</strong>' : '<strong style="color:#b91c1c">ناموفق</strong>' ?>
            <?php if ($m['last_latency_ms'] !== null): ?><span class="muted ltr"><?= (int)$m['last_latency_ms'] ?> ms</span><?php endif; ?>
            <?php if (!$m['last_test_ok'] && $m['last_error_code']): ?><br><span class="muted"><?= e(ai_error_label((string)$m['last_error_code'])) ?></span><?php endif; ?>
          <?php else: ?><span class="muted">—</span><?php endif; ?>
        </td>
        <td style="white-space:nowrap">
          <form method="post" style="display:inline">
            <?= csrf_field() ?><input type="hidden" name="do" value="model_test"><input type="hidden" name="provider_id" value="<?= $pid ?>">
            <input type="hidden" name="model_id" value="<?= $mid ?>"><button class="btn">تست</button>
          </form>
          <form method="post" style="display:inline">
            <?= csrf_field() ?><input type="hidden" name="do" value="model_toggle"><input type="hidden" name="provider_id" value="<?= $pid ?>">
            <input type="hidden" name="model_id" value="<?= $mid ?>"><input type="hidden" name="status" value="<?= $m['status'] === 'active' ? 'disabled' : 'active' ?>">
            <button class="btn<?= $m['status'] === 'active' ? '' : ' btn-primary' ?>"><?= $m['status'] === 'active' ? 'غیرفعال' : 'فعال‌سازی' ?></button>
          </form>
          <form method="post" style="display:inline" onsubmit="return confirm('این مدل حذف شود؟ دسترسی کاربران به آن هم حذف می‌شود.');">
            <?= csrf_field() ?><input type="hidden" name="do" value="model_delete"><input type="hidden" name="provider_id" value="<?= $pid ?>">
            <input type="hidden" name="model_id" value="<?= $mid ?>"><button class="btn">حذف</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if ($models === []): ?><tr><td colspan="8" class="muted">مدلی ثبت نشده است. «تشخیص مدل‌ها» را بزنید یا مدل را دستی اضافه کنید.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>

  <h3>افزودن دستی مدل</h3>
  <p class="muted">برای سروری که فهرست مدل‌ها را ارائه نمی‌کند، یا مدلی که در فهرست نیامده است. شناسه باید دقیقاً همان نامی باشد که سرور در فیلد <span class="ltr">model</span> می‌پذیرد.</p>
  <form method="post" class="toolbar">
    <?= csrf_field() ?><input type="hidden" name="do" value="model_add"><input type="hidden" name="provider_id" value="<?= $pid ?>">
    <label>شناسه‌ی مدل <input type="text" name="external_model_id" class="mono" placeholder="hermes-agent" required></label>
    <label>نام نمایشی <input type="text" name="display_name" placeholder="Hermes"></label>
    <button class="btn btn-primary">افزودن</button>
  </form>
</div>

<?php else: ?>
<?php $runs = ai_recent_runs($pid, 50); ?>
<div class="card">
  <h2>تاریخچه‌ی تست</h2>
  <p class="muted">متن پیام‌ها و پاسخ‌ها ذخیره نمی‌شود؛ فقط نتیجه، زمان پاسخ و تعداد توکن.</p>
  <table class="table">
    <thead><tr><th>زمان</th><th>نوع</th><th>مدل</th><th>نتیجه</th><th>زمان پاسخ</th><th>توکن (ورودی/خروجی)</th><th>جزئیات</th></tr></thead>
    <tbody>
    <?php foreach ($runs as $r): ?>
      <tr>
        <td><?= jdate((string)$r['created_at']) ?></td>
        <td><?= ['connection' => 'تست اتصال', 'discovery' => 'تشخیص مدل‌ها', 'model' => 'تست مدل', 'playground' => 'چت آزمایشی'][$r['kind']] ?? e($r['kind']) ?></td>
        <td class="mono"><?= e((string)($r['external_model_id'] ?? '—')) ?></td>
        <td><?= $r['ok'] ? '<strong style="color:#15803d">موفق</strong>' : '<strong style="color:#b91c1c">ناموفق</strong>' ?></td>
        <td class="ltr"><?= $r['latency_ms'] !== null ? (int)$r['latency_ms'] . ' ms' : '—' ?></td>
        <td class="ltr"><?= $r['input_tokens'] !== null || $r['output_tokens'] !== null ? (int)$r['input_tokens'] . ' / ' . (int)$r['output_tokens'] : '—' ?></td>
        <td><?= $r['ok'] ? ($r['discovered_count'] !== null ? (int)$r['discovered_count'] . ' مدل' : '') : e((string)$r['error_message']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($runs === []): ?><tr><td colspan="7" class="muted">هنوز تستی انجام نشده است.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?php endif; ?>

<?php elseif ($view === 'access'): ?>
<?php $defaultModelId = (int)ai_setting('ai_default_model_id'); $defaultAccess = ai_setting('ai_default_access') === '1'; ?>
<div class="card">
  <h2>پیش‌فرض سامانه</h2>
  <form method="post" class="toolbar">
    <?= csrf_field() ?><input type="hidden" name="do" value="settings_save"><input type="hidden" name="view" value="access">
    <label>مدل پیش‌فرض
      <select name="default_model_id">
        <option value="0">— بدون مدل پیش‌فرض —</option>
        <?php foreach ($activeModels as $m): ?>
          <option value="<?= (int)$m['id'] ?>"<?= (int)$m['id'] === $defaultModelId ? ' selected' : '' ?>><?= e($m['provider_name'] . ' — ' . $m['display_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><input type="checkbox" name="default_access" value="1"<?= $defaultAccess ? ' checked' : '' ?>> همه‌ی کاربرانِ بدون تنظیم اختصاصی، مدل پیش‌فرض را داشته باشند</label>
    <button class="btn btn-primary">ذخیره</button>
  </form>
  <p class="muted">
    ترتیب اعمال: تنظیم اختصاصی کاربر ← تنظیم حساب بالادست (نماینده یا سازمان) ← پیش‌فرض سامانه.
    زیرمجموعه هیچ‌وقت مدلی بیشتر از حساب بالادست خود نمی‌گیرد.
  </p>
</div>

<div class="card">
  <h2>دسترسی یک کاربر</h2>
  <form method="get" class="toolbar">
    <input type="hidden" name="view" value="access">
    <label>نام کاربری یا شناسه <input type="text" name="user" class="ltr" value="<?= e((string)($_GET['user'] ?? '')) ?>" required></label>
    <button class="btn btn-primary">نمایش</button>
  </form>

  <?php if ($accessUser !== null): ?>
    <?php $own = $accessUser['own']; ?>
    <h3>کاربر <span class="ltr"><?= e($accessUser['username']) ?></span> (#<?= (int)$accessUser['id'] ?>)</h3>
    <?php if (count($accessUser['chain']) > 1): ?>
      <p class="muted">زنجیره‌ی حساب: <span class="ltr"><?= e(implode(' ← ', array_map(static fn(int $id): string => '#' . $id, array_reverse($accessUser['chain'])))) ?></span></p>
    <?php endif; ?>
    <p>
      مدل‌های قابل استفاده‌ی فعلی (<?= e($sourceLabels[$accessUser['source']] ?? $accessUser['source']) ?>):
      <?php if ($accessUser['models'] === []): ?><strong>هیچ</strong><?php endif; ?>
    </p>
    <?php if ($accessUser['models'] !== []): ?>
      <ul>
        <?php foreach ($accessUser['models'] as $m): ?>
          <li><?= e($m['provider_name'] . ' — ' . $m['display_name']) ?> <span class="muted mono"><?= e($m['external_model_id']) ?></span>
            <?= $accessUser['default'] !== null && (int)$accessUser['default']['id'] === (int)$m['id'] ? ' <span class="badge">پیش‌فرض</span>' : '' ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="do" value="access_save"><input type="hidden" name="view" value="access">
      <input type="hidden" name="user_id" value="<?= (int)$accessUser['id'] ?>">
      <p>
        <label><input type="radio" name="mode" value="inherit"<?= !$own['custom'] ? ' checked' : '' ?>> پیروی از حساب بالادست / پیش‌فرض سامانه</label><br>
        <label><input type="radio" name="mode" value="custom"<?= $own['custom'] ? ' checked' : '' ?>> مدل‌های اختصاصی:</label>
      </p>
      <table class="table">
        <thead><tr><th>مجاز</th><th>پیش‌فرض</th><th>مدل</th><th>ارائه‌دهنده</th></tr></thead>
        <tbody>
        <?php foreach ($activeModels as $m): $mid = (int)$m['id']; ?>
          <tr>
            <td><input type="checkbox" name="model_ids[]" value="<?= $mid ?>"<?= in_array($mid, $own['models'], true) ? ' checked' : '' ?>></td>
            <td><input type="radio" name="default_model_id" value="<?= $mid ?>"<?= $own['default'] === $mid ? ' checked' : '' ?>></td>
            <td><?= e($m['display_name']) ?> <span class="muted mono"><?= e($m['external_model_id']) ?></span></td>
            <td><?= e($m['provider_name']) ?></td>
          </tr>
        <?php endforeach; ?>
        <tr><td></td><td><input type="radio" name="default_model_id" value=""<?= $own['default'] === null ? ' checked' : '' ?>></td><td colspan="2" class="muted">بدون پیش‌فرض اختصاصی</td></tr>
        <?php if ($activeModels === []): ?><tr><td colspan="4" class="muted">هیچ مدل فعالی وجود ندارد؛ ابتدا در «ارائه‌دهنده‌ها و مدل‌ها» مدلی را فعال کنید.</td></tr><?php endif; ?>
        </tbody>
      </table>
      <button class="btn btn-primary">ذخیره‌ی دسترسی</button>
    </form>
  <?php endif; ?>
</div>

<?php $accounts = ai_access_accounts(); if ($accounts !== []): $names = backend_usernames_by_ids(array_map('intval', array_column($accounts, 'user_id'))); ?>
<div class="card">
  <h2>کاربرانِ دارای تنظیم</h2>
  <table class="table">
    <thead><tr><th>کاربر</th><th>حالت</th><th>تعداد مدل</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($accounts as $a): ?>
      <tr>
        <td class="ltr"><?= e((string)($names[(int)$a['user_id']] ?? '#' . (int)$a['user_id'])) ?></td>
        <td><?= $a['custom'] ? 'اختصاصی' : 'پیروی' ?></td>
        <td class="ltr"><?= (int)$a['model_count'] ?></td>
        <td><a class="btn" href="/ai-engines.php?view=access&user=<?= (int)$a['user_id'] ?>">ویرایش</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php elseif ($view === 'pricing'): ?>
<?php
  $globalRows = ai_prices_for('global', 0);
  $avgTokens = ai_average_tokens();
  $usdRate = ai_billing_setting('ai_usd_rial_rate');
  $pricedModels = array_values(array_filter($allModels, static fn(array $m): bool => $m['status'] === 'active' || isset($globalRows[(int)$m['id']])));
?>
<div class="card">
  <h2>تعرفه‌ی هوش مصنوعی</h2>
  <p class="muted">
    هر پاسخ هوشمند (و هر «آزمایش منشی» مشتری) جدا از هزینه‌ی پیامکش، با این تعرفه محاسبه و از کیف پول کسر می‌شود:
    «قیمت هر پاسخ» تا «توکن رایگان هر پاسخ» (ورودی + خروجی)، و برای هر ۱۰۰۰ توکن بیشتر، «قیمت هر ۱۰۰۰ توکن اضافه».
    ردیف «همه‌ی مدل‌ها» برای مدلی است که ردیف خودش را ندارد. مانند تعرفه‌ی پیامک: قیمت اختصاصی حساب بر درصد تعرفه‌ی آن مقدم است،
    مشتریان نماینده با تعرفه‌ی نماینده محاسبه می‌شوند و سود نماینده در اجرای روزانه‌ی سود پرداخت می‌شود.
  </p>
  <form method="post" class="toolbar">
    <?= csrf_field() ?><input type="hidden" name="do" value="billing_settings_save"><input type="hidden" name="view" value="pricing">
    <label><input type="checkbox" name="ai_billing_enabled" value="1"<?= ai_billing_enabled() ? ' checked' : '' ?>> هزینه‌ی هوش مصنوعی کسر شود</label>
    <label>توکن رایگان پیش‌فرض هر پاسخ <input type="number" name="ai_default_included_tokens" min="0" value="<?= ai_billing_setting('ai_default_included_tokens') ?>" style="width:7em"></label>
    <label>نرخ دلار (ریال، فقط برای محاسبه‌ی سود) <input type="number" name="ai_usd_rial_rate" min="0" value="<?= $usdRate ?>" style="width:9em"></label>
    <button class="btn btn-primary">ذخیره</button>
  </form>
</div>

<div class="card">
  <h2>تعرفه‌ی عمومی (قیمت پایه)</h2>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="do" value="prices_global_save"><input type="hidden" name="view" value="pricing">
    <div style="overflow-x:auto">
    <table class="table">
      <thead><tr><th>مدل</th><th>قیمت هر پاسخ (ریال)</th><th>توکن رایگان هر پاسخ</th><th>هر ۱۰۰۰ توکن اضافه (ریال)</th>
        <th>میانگین توکن (۳۰ روز)</th><th>هزینه‌ی خرید هر پاسخ</th><th>درآمد هر پاسخ</th></tr></thead>
      <tbody>
      <?php foreach (array_merge([['id' => 0, 'display_name' => 'همه‌ی مدل‌ها (پیش‌فرض)', 'provider_name' => '', 'input_cost_per_million' => null, 'output_cost_per_million' => null, 'cost_currency' => 'USD']], $pricedModels) as $m):
          $mid = (int)$m['id']; $row = $globalRows[$mid] ?? ['answer' => null, 'overage' => null, 'included' => null];
          $avg = $avgTokens[$mid] ?? null;
          $buy = $avg !== null && $m['input_cost_per_million'] !== null && $m['output_cost_per_million'] !== null
              ? $avg * ((float)$m['input_cost_per_million'] * 0.8 + (float)$m['output_cost_per_million'] * 0.2) / 1000000 : null;
          $sell = $avg !== null && $mid > 0 ? ai_charge_millis((int)ai_price_cell($globalRows, $mid, 'answer'), (int)ai_price_cell($globalRows, $mid, 'overage'), ai_included_tokens($mid), $avg) : null; ?>
        <tr>
          <td><?= e($m['display_name']) ?><?= $m['provider_name'] !== '' ? ' <span class="muted">' . e($m['provider_name']) . '</span>' : '' ?></td>
          <td><input type="text" name="price[<?= $mid ?>][answer]" class="mono" value="<?= e(ai_price_to_rial($row['answer'])) ?>" placeholder="<?= $mid > 0 ? 'از ردیف همه' : '0' ?>" style="width:8em"></td>
          <td><input type="text" name="price[<?= $mid ?>][included]" class="mono" value="<?= $row['included'] !== null ? (int)$row['included'] : '' ?>" placeholder="<?= $mid > 0 ? 'از ردیف همه' : ai_billing_setting('ai_default_included_tokens') ?>" style="width:7em"></td>
          <td><input type="text" name="price[<?= $mid ?>][overage]" class="mono" value="<?= e(ai_price_to_rial($row['overage'])) ?>" placeholder="<?= $mid > 0 ? 'از ردیف همه' : '0' ?>" style="width:8em"></td>
          <td class="mono"><?= $avg !== null ? $avg : '—' ?></td>
          <td class="mono"><?= $buy !== null ? e(number_format($buy, 5) . ' ' . $m['cost_currency']) . ($usdRate > 0 && $m['cost_currency'] === 'USD' ? '<br>≈ ' . e(number_format($buy * $usdRate)) . ' ریال' : '') : '—' ?></td>
          <td class="mono"><?= $sell !== null ? $rialOf($sell) . ' ریال' : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <p class="muted">«هزینه‌ی خرید» تخمینی است: میانگین توکن ۳۰ روز اخیر با فرض ۸۰٪ ورودی و ۲۰٪ خروجی، به قیمت خریدی که در برگه‌ی مدل‌ها وارد کرده‌اید.
      «درآمد هر پاسخ» به قیمت پایه و همان میانگین توکن است (قبل از درصد تعرفه‌ی حساب‌ها).</p>
    <button class="btn btn-primary">ذخیره‌ی تعرفه‌ی عمومی</button>
  </form>
</div>

<div class="card">
  <h2>قیمت اختصاصی یک حساب</h2>
  <p class="muted">قیمت دقیق یک حساب، جایگزین «قیمت پایه × درصد تعرفه»ی آن حساب. خانه‌ی خالی یعنی همان قیمت پایه با درصد تعرفه‌ی حساب.
    برای مشتریان نماینده، قیمت از تعرفه‌ی نماینده می‌آید (پنل نمایندگی ← تعرفه‌ها).</p>
  <form method="get" class="toolbar">
    <input type="hidden" name="view" value="pricing">
    <label>نام کاربری یا شناسه <input type="text" name="price_user" class="mono" value="<?= e((string)($_GET['price_user'] ?? '')) ?>" required></label>
    <button class="btn btn-primary">نمایش</button>
  </form>
  <?php if ($priceUser !== null): ?>
    <h3>حساب <span class="mono"><?= e($priceUser['username']) ?></span></h3>
    <?php if (!$priceUser['eligible']): ?>
      <p class="muted">این حساب زیر نماینده یا زیرسازمان است و قیمت اختصاصی نمی‌گیرد.</p>
    <?php else: ?>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="do" value="prices_account_save"><input type="hidden" name="view" value="pricing">
        <input type="hidden" name="user_id" value="<?= (int)$priceUser['id'] ?>">
        <table class="table">
          <thead><tr><th>مدل</th><th>قیمت هر پاسخ (ریال)</th><th>هر ۱۰۰۰ توکن اضافه (ریال)</th><th>قیمت فعلی این حساب</th></tr></thead>
          <tbody>
          <?php foreach (array_merge([['id' => 0, 'display_name' => 'همه‌ی مدل‌ها']], $activeModels) as $m): $mid = (int)$m['id'];
              $row = $priceUser['rows'][$mid] ?? ['answer' => null, 'overage' => null];
              $now = $mid > 0 ? ai_price_resolve((int)$priceUser['id'], $mid) : null; ?>
            <tr>
              <td><?= e($m['display_name']) ?></td>
              <td><input type="text" name="price[<?= $mid ?>][answer]" class="mono" value="<?= e(ai_price_to_rial($row['answer'])) ?>" style="width:8em"></td>
              <td><input type="text" name="price[<?= $mid ?>][overage]" class="mono" value="<?= e(ai_price_to_rial($row['overage'])) ?>" style="width:8em"></td>
              <td class="mono"><?= $now !== null ? $rialOf($now['answer']) . ' / ' . $rialOf($now['overage']) : '' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <button class="btn btn-primary">ذخیره‌ی قیمت این حساب</button>
      </form>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php elseif ($view === 'usage'): ?>
<?php
  $days = in_array((int)($_GET['days'] ?? 30), [1, 7, 30, 90], true) ? (int)($_GET['days'] ?? 30) : 30;
  $usage = ai_usage_by_model(gmdate('Y-m-d H:i:s', time() - $days * 86400), gmdate('Y-m-d H:i:s', time() + 60));
  $usdRate = ai_billing_setting('ai_usd_rial_rate');
?>
<div class="card">
  <h2>مصرف و سود هوش مصنوعی</h2>
  <div class="toolbar">
    <?php foreach ([1 => '۲۴ ساعت', 7 => '۷ روز', 30 => '۳۰ روز', 90 => '۹۰ روز'] as $d => $label): ?>
      <a class="btn<?= $days === $d ? ' btn-primary' : '' ?>" href="/ai-engines.php?view=usage&days=<?= $d ?>"><?= $label ?></a>
    <?php endforeach; ?>
  </div>
  <div style="overflow-x:auto">
  <table class="table">
    <thead><tr><th>مدل</th><th>پاسخ</th><th>توکن</th><th>درآمد (ریال)</th><th>پرداخت‌نشده</th><th>هزینه‌ی خرید</th><th>سود تقریبی (ریال)</th></tr></thead>
    <tbody>
    <?php foreach ($usage as $u): $buyRial = $usdRate > 0 && ($u['currency'] ?? 'USD') === 'USD' ? $u['purchase_cost'] * $usdRate : null; ?>
      <tr>
        <td><?= e((string)($u['display_name'] ?? ('#' . $u['model_id']))) ?></td>
        <td class="mono"><?= number_format($u['answers']) ?></td>
        <td class="mono"><?= number_format($u['tokens']) ?></td>
        <td class="mono"><?= number_format($u['revenue']) ?></td>
        <td class="mono"><?= $u['unpaid'] > 0 ? '<strong style="color:#b91c1c">' . number_format($u['unpaid']) . '</strong>' : '۰' ?></td>
        <td class="mono"><?= e(number_format($u['purchase_cost'], 4) . ' ' . ($u['currency'] ?? '')) ?><?= $buyRial !== null ? '<br>≈ ' . number_format($buyRial) . ' ریال' : '' ?></td>
        <td class="mono"><?= $buyRial !== null ? number_format($u['revenue'] - $buyRial) : '—' ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($usage === []): ?><tr><td colspan="7" class="muted">در این بازه پاسخ هوشمندی ثبت نشده است.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
  <p class="muted">درآمد: آن‌چه از حساب پاسخ‌دهنده کسر شده (سهم نماینده‌ها جداگانه در «سود نمایندگان» پرداخت می‌شود). هزینه‌ی خرید از توکن واقعی هر پاسخ و قیمت خرید مدل محاسبه می‌شود؛
    برای تبدیل به ریال، «نرخ دلار» را در برگه‌ی تعرفه وارد کنید.</p>
</div>

<?php else: /* playground */ ?>
<div class="card">
  <h2>چت آزمایشی</h2>
  <p class="muted">یک پیام به مدل ارسال می‌شود و پاسخ همین‌جا نمایش داده می‌شود؛ هیچ پیامکی ارسال نمی‌شود و متن پیام و پاسخ ذخیره نمی‌شود.
    مدل غیرفعال هم قابل آزمایش است. با پرکردن «به‌عنوان کاربر»، از مدل‌های مجاز همان کاربر استفاده می‌شود.</p>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="do" value="playground"><input type="hidden" name="view" value="playground">
    <div class="toolbar">
      <label>مدل
        <select name="model_id">
          <option value="0">— مدل پیش‌فرض کاربر —</option>
          <?php foreach ($allModels as $m): ?>
            <option value="<?= (int)$m['id'] ?>"<?= (int)($_POST['model_id'] ?? 0) === (int)$m['id'] ? ' selected' : '' ?>>
              <?= e($m['provider_name'] . ' — ' . $m['display_name'] . ($m['status'] !== 'active' || $m['provider_status'] !== 'active' ? ' (غیرفعال)' : '')) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>به‌عنوان کاربر <input type="text" name="as_user" class="ltr" value="<?= e((string)($_POST['as_user'] ?? '')) ?>" placeholder="نام کاربری (اختیاری)"></label>
      <label>حداکثر توکن خروجی <input type="number" name="max_tokens" class="ltr" min="1" max="4000" value="<?= (int)($_POST['max_tokens'] ?? 300) ?>" style="width:6em"></label>
      <label>temperature <input type="text" name="temperature" class="ltr" value="<?= e((string)($_POST['temperature'] ?? '')) ?>" placeholder="پیش‌فرض" style="width:6em"></label>
    </div>
    <label style="display:block">دستورالعمل سیستم (اختیاری)
      <textarea name="system" rows="3" style="width:100%" placeholder="تو منشی پیامکی یک کلینیک هستی. کوتاه و مؤدبانه جواب بده."><?= e((string)($_POST['system'] ?? '')) ?></textarea>
    </label>
    <label style="display:block">پیام
      <textarea name="message" rows="3" style="width:100%" required placeholder="سلام، ساعت کاری فردا چنده؟"><?= e((string)($_POST['message'] ?? '')) ?></textarea>
    </label>
    <button class="btn btn-primary" style="margin-top:8px">ارسال</button>
  </form>
  <p class="muted">برای Anthropic، مقدار temperature ارسال نمی‌شود (نسخه‌ی API فعلی آن را نمی‌پذیرد).</p>
</div>

<?php if ($playgroundResult !== null): ?>
<div class="card">
  <h2>پاسخ</h2>
  <?php if ($playgroundResult['note']): ?><p class="muted"><?= e($playgroundResult['note']) ?></p><?php endif; ?>
  <?php if ($playgroundResult['ok']): ?>
    <div style="white-space:pre-wrap;border:1px solid var(--border, #ddd);border-radius:8px;padding:12px"><?= e($playgroundResult['text']) ?></div>
    <p class="muted">
      زمان پاسخ: <span class="ltr"><?= (int)$playgroundResult['latency_ms'] ?> ms</span> ·
      توکن ورودی/خروجی: <span class="ltr"><?= $playgroundResult['input_tokens'] ?? '—' ?> / <?= $playgroundResult['output_tokens'] ?? '—' ?></span> ·
      پایان: <span class="ltr"><?= e((string)($playgroundResult['finish_reason'] ?? '—')) ?></span>
      <?php if ($playgroundResult['cost'] !== null): ?> · هزینه‌ی تقریبی: <span class="ltr"><?= e(number_format($playgroundResult['cost'], 6) . ' ' . $playgroundResult['currency']) ?></span><?php endif; ?>
      · طول پاسخ: <?= mb_strlen($playgroundResult['text']) ?> نویسه
      (≈ <?= sms_parts($playgroundResult['text']) ?> پارت پیامک)
    </p>
  <?php else: ?>
    <p><strong style="color:#b91c1c">ناموفق:</strong> <?= e($playgroundResult['message']) ?></p>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/../app/views/footer.php'; ?>
