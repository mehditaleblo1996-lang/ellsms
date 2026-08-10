<?php
/**
 * ELLSMS — Platform Admin -> SMS pricing (operators, prefixes, providers, routes, sender routes,
 * prices). See docs/sms-pricing.md.
 *
 * PLATFORM ADMIN ONLY. The guard is require_admin() — the existing platform-admin guard every other
 * global-configuration page uses — deliberately NOT an organization permission like
 * settings.manage: these are GLOBAL rates that apply to every tenant, so an organization owner must
 * not be able to reach them even for their own organization (STEP 36). require_admin() redirects a
 * non-admin GET and, because every mutation below is a POST through the same guard, a forged POST
 * from an organization user never reaches any write either.
 *
 * Every mutation here is audited (STEP 37): price changes are financially sensitive, and an
 * unexplained tariff change is exactly the thing an operator needs to be able to trace back to a
 * person and a time.
 *
 * Deliberately NOT here: provider credentials. ellsms_sms_providers is pricing/configuration
 * metadata only; the actual gateway credentials stay in the existing secure integration layer and
 * are never rendered, edited, or logged by this page (STEP 32).
 */
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_admin();
$pageTitle = 'تعرفه‌ی پیامک';
$active = 'sms_pricing';

$tab = $_GET['tab'] ?? 'operators';
$validTabs = ['operators', 'prefixes', 'providers', 'routes', 'senders', 'prices'];
if (!in_array($tab, $validTabs, true)) $tab = 'operators';

/** Audit helper — one shape for every pricing mutation, so the trail is greppable. */
function pricing_audit(array $me, string $action, array $details): void {
    audit((int)$me['id'], 'sms_pricing.' . $action, json_encode($details, JSON_UNESCAPED_UNICODE) ?: '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';
    $db = db();

    try {
        switch ($do) {
            /* ---------------- Operators (STEP 30) ---------------- */
            case 'operator_create': {
                $code = strtolower(trim((string)($_POST['code'] ?? '')));
                $name = trim((string)($_POST['name'] ?? ''));
                if (!preg_match('/^[a-z0-9_]{2,40}$/', $code) || $name === '') {
                    flash('error', 'شناسه‌ی اپراتور باید ۲ تا ۴۰ نویسه‌ی لاتین/عدد/زیرخط و نام آن غیرخالی باشد.');
                    break;
                }
                $db->prepare('INSERT INTO ellsms_sms_operators (code, name, country_code, priority) VALUES (?,?,?,?)')
                   ->execute([$code, $name, strtoupper(trim((string)($_POST['country_code'] ?? 'IR'))) ?: 'IR', (int)($_POST['priority'] ?? 0)]);
                pricing_audit($me, 'operator.create', ['code' => $code, 'name' => $name]);
                flash('success', 'اپراتور افزوده شد.');
                break;
            }
            case 'operator_update': {
                $id = (int)($_POST['id'] ?? 0);
                $before = $db->query('SELECT * FROM ellsms_sms_operators WHERE id = ' . $id)->fetch();
                if (!$before) { flash('error', 'اپراتور پیدا نشد.'); break; }
                $name = trim((string)($_POST['name'] ?? $before['name']));
                $status = ($_POST['status'] ?? 'active') === 'archived' ? 'archived' : 'active';
                $db->prepare('UPDATE ellsms_sms_operators SET name = ?, status = ?, priority = ? WHERE id = ?')
                   ->execute([$name ?: $before['name'], $status, (int)($_POST['priority'] ?? $before['priority']), $id]);
                pricing_audit($me, 'operator.update', [
                    'id' => $id,
                    'before' => ['name' => $before['name'], 'status' => $before['status'], 'priority' => (int)$before['priority']],
                    'after'  => ['name' => $name, 'status' => $status, 'priority' => (int)($_POST['priority'] ?? 0)],
                ]);
                flash('success', 'اپراتور به‌روزرسانی شد.');
                break;
            }

            /* ---------------- Prefixes (STEP 31) ---------------- */
            case 'prefix_create': {
                $operatorId = (int)($_POST['operator_id'] ?? 0);
                $raw = trim(from_persian_digits((string)($_POST['prefix'] ?? '')));
                // Digits only, no whitespace, no wildcard/regex syntax — prefix matching is
                // deliberately not a pattern language (STEP 31).
                if (!preg_match('/^\d{2,15}$/', $raw)) {
                    flash('error', 'پیش‌شماره باید فقط رقم و بین ۲ تا ۱۵ رقم باشد (مثلاً 0912).');
                    break;
                }
                $normalized = sms_pricing_normalize_prefix($raw);
                if ($normalized === null) { flash('error', 'پیش‌شماره معتبر نیست.'); break; }
                try {
                    // active_prefix is the uniqueness slot the DB indexes (see the migration's
                    // header): it mirrors normalized_prefix while the rule is active and is NULL
                    // once archived, so archiving frees the prefix for another operator.
                    $db->prepare('INSERT INTO ellsms_sms_operator_prefixes (operator_id, prefix, normalized_prefix, prefix_length, priority, active_prefix) VALUES (?,?,?,?,?,?)')
                       ->execute([$operatorId, $raw, $normalized, strlen($normalized), (int)($_POST['priority'] ?? 0), $normalized]);
                } catch (PDOException) {
                    // uniq_active_prefix — the database itself refuses two ACTIVE rules claiming one
                    // prefix, which is what makes longest-prefix matching unambiguous (STEP 5).
                    flash('error', 'این پیش‌شماره در حال حاضر توسط اپراتور دیگری (به‌صورت فعال) ثبت شده است.');
                    break;
                }
                pricing_audit($me, 'prefix.create', ['operator_id' => $operatorId, 'prefix' => $raw, 'normalized' => $normalized]);
                flash('success', 'پیش‌شماره افزوده شد.');
                break;
            }
            case 'prefix_update': {
                $id = (int)($_POST['id'] ?? 0);
                $status = ($_POST['status'] ?? 'active') === 'archived' ? 'archived' : 'active';
                try {
                    $db->prepare("UPDATE ellsms_sms_operator_prefixes
                                  SET status = ?, priority = ?,
                                      active_prefix = IF(? = 'active', normalized_prefix, NULL)
                                  WHERE id = ?")
                       ->execute([$status, (int)($_POST['priority'] ?? 0), $status, $id]);
                } catch (PDOException) {
                    flash('error', 'فعال‌سازی این پیش‌شماره با یک قاعده‌ی فعال دیگر تداخل دارد.');
                    break;
                }
                pricing_audit($me, 'prefix.update', ['id' => $id, 'status' => $status]);
                flash('success', 'پیش‌شماره به‌روزرسانی شد.');
                break;
            }

            /* ---------------- Providers (STEP 32) ---------------- */
            case 'provider_create': {
                $code = strtolower(trim((string)($_POST['code'] ?? '')));
                $name = trim((string)($_POST['name'] ?? ''));
                if (!preg_match('/^[a-z0-9_]{2,40}$/', $code) || $name === '') {
                    flash('error', 'شناسه‌ی ارائه‌دهنده باید ۲ تا ۴۰ نویسه‌ی لاتین/عدد/زیرخط و نام آن غیرخالی باشد.');
                    break;
                }
                $db->prepare('INSERT INTO ellsms_sms_providers (code, name, description, priority) VALUES (?,?,?,?)')
                   ->execute([$code, $name, trim((string)($_POST['description'] ?? '')), (int)($_POST['priority'] ?? 0)]);
                pricing_audit($me, 'provider.create', ['code' => $code, 'name' => $name]);
                flash('success', 'ارائه‌دهنده افزوده شد.');
                break;
            }
            case 'provider_update': {
                $id = (int)($_POST['id'] ?? 0);
                $before = $db->query('SELECT * FROM ellsms_sms_providers WHERE id = ' . $id)->fetch();
                if (!$before) { flash('error', 'ارائه‌دهنده پیدا نشد.'); break; }
                $status = ($_POST['status'] ?? 'active') === 'archived' ? 'archived' : 'active';
                $name   = trim((string)($_POST['name'] ?? '')) ?: $before['name'];
                $db->prepare('UPDATE ellsms_sms_providers SET name = ?, description = ?, status = ?, priority = ? WHERE id = ?')
                   ->execute([$name, trim((string)($_POST['description'] ?? $before['description'])), $status, (int)($_POST['priority'] ?? $before['priority']), $id]);
                pricing_audit($me, 'provider.update', [
                    'id' => $id,
                    'before' => ['name' => $before['name'], 'status' => $before['status']],
                    'after'  => ['name' => $name, 'status' => $status],
                ]);
                flash('success', 'ارائه‌دهنده به‌روزرسانی شد.');
                break;
            }

            /* ---------------- Routes (STEP 33) ---------------- */
            case 'route_create': {
                $providerId = (int)($_POST['provider_id'] ?? 0);
                $code = strtolower(trim((string)($_POST['code'] ?? '')));
                $name = trim((string)($_POST['name'] ?? ''));
                $messageType = in_array($_POST['message_type'] ?? '', SMS_MESSAGE_TYPES, true) ? $_POST['message_type'] : 'default';
                if (!preg_match('/^[a-z0-9_]{2,40}$/', $code) || $name === '' || $providerId <= 0) {
                    flash('error', 'ارائه‌دهنده، شناسه و نام مسیر الزامی است.');
                    break;
                }
                $provider = $db->query('SELECT status FROM ellsms_sms_providers WHERE id = ' . $providerId)->fetch();
                if (!$provider) { flash('error', 'ارائه‌دهنده پیدا نشد.'); break; }
                if ($provider['status'] !== 'active') {
                    // An active route under an archived provider would be unusable for pricing
                    // anyway (sms_pricing_route_for_sender() filters on provider status), so it is
                    // refused at the source rather than created and silently ignored (STEP 33).
                    flash('error', 'نمی‌توان مسیر فعال زیر ارائه‌دهنده‌ی بایگانی‌شده ساخت.');
                    break;
                }
                try {
                    $isDefault = !empty($_POST['is_default']) ? 1 : 0;
                    $db->prepare('INSERT INTO ellsms_sms_routes (provider_id, code, name, message_type, is_default, priority, default_slot) VALUES (?,?,?,?,?,?,?)')
                       ->execute([$providerId, $code, $name, $messageType, $isDefault, (int)($_POST['priority'] ?? 0), $isDefault ? $messageType : null]);
                } catch (PDOException) {
                    flash('error', 'شناسه‌ی مسیر برای این ارائه‌دهنده تکراری است، یا مسیر پیش‌فرض دیگری برای این نوع پیام از قبل وجود دارد.');
                    break;
                }
                pricing_audit($me, 'route.create', ['provider_id' => $providerId, 'code' => $code, 'message_type' => $messageType, 'is_default' => !empty($_POST['is_default'])]);
                flash('success', 'مسیر افزوده شد.');
                break;
            }
            case 'route_update': {
                $id = (int)($_POST['id'] ?? 0);
                $before = $db->query('SELECT * FROM ellsms_sms_routes WHERE id = ' . $id)->fetch();
                if (!$before) { flash('error', 'مسیر پیدا نشد.'); break; }
                $status = ($_POST['status'] ?? 'active') === 'archived' ? 'archived' : 'active';
                try {
                    $isDefault = !empty($_POST['is_default']) ? 1 : 0;
                    $db->prepare("UPDATE ellsms_sms_routes
                                  SET name = ?, status = ?, is_default = ?, priority = ?,
                                      default_slot = IF(? = 'active' AND ? = 1, message_type, NULL)
                                  WHERE id = ?")
                       ->execute([trim((string)($_POST['name'] ?? '')) ?: $before['name'], $status, $isDefault, (int)($_POST['priority'] ?? $before['priority']), $status, $isDefault, $id]);
                } catch (PDOException) {
                    flash('error', 'مسیر پیش‌فرض دیگری برای این نوع پیام از قبل فعال است.');
                    break;
                }
                pricing_audit($me, 'route.update', [
                    'id' => $id,
                    'before' => ['status' => $before['status'], 'is_default' => (int)$before['is_default']],
                    'after'  => ['status' => $status, 'is_default' => !empty($_POST['is_default']) ? 1 : 0],
                ]);
                flash('success', 'مسیر به‌روزرسانی شد.');
                break;
            }

            /* ---------------- Sender routes (STEP 9/33) ---------------- */
            case 'sender_route_create': {
                $sender = normalize_originator((string)($_POST['sender'] ?? ''));
                $routeId = (int)($_POST['route_id'] ?? 0);
                $messageType = in_array($_POST['message_type'] ?? '', SMS_MESSAGE_TYPES, true) ? $_POST['message_type'] : 'default';
                if ($sender === null || $routeId <= 0) { flash('error', 'خط ارسال و مسیر الزامی است.'); break; }
                try {
                    $db->prepare('INSERT INTO ellsms_sender_routes (sender, message_type, route_id, priority, active_slot) VALUES (?,?,?,?,?)')
                       ->execute([$sender, $messageType, $routeId, (int)($_POST['priority'] ?? 0), $sender . ':' . $messageType]);
                } catch (PDOException) {
                    flash('error', 'برای این خط و این نوع پیام، تخصیص فعالی از قبل وجود دارد.');
                    break;
                }
                pricing_audit($me, 'sender_route.create', ['sender' => $sender, 'route_id' => $routeId, 'message_type' => $messageType]);
                flash('success', 'تخصیص مسیر ثبت شد.');
                break;
            }
            case 'sender_route_update': {
                $id = (int)($_POST['id'] ?? 0);
                $status = ($_POST['status'] ?? 'active') === 'archived' ? 'archived' : 'active';
                try {
                    $db->prepare("UPDATE ellsms_sender_routes
                                  SET status = ?, priority = ?,
                                      active_slot = IF(? = 'active', CONCAT(sender, ':', message_type), NULL)
                                  WHERE id = ?")
                       ->execute([$status, (int)($_POST['priority'] ?? 0), $status, $id]);
                } catch (PDOException) {
                    flash('error', 'فعال‌سازی این تخصیص با تخصیص فعال دیگری تداخل دارد.');
                    break;
                }
                pricing_audit($me, 'sender_route.update', ['id' => $id, 'status' => $status]);
                flash('success', 'تخصیص مسیر به‌روزرسانی شد.');
                break;
            }

            /* ---------------- Prices (STEP 34/35) ---------------- */
            case 'price_create': {
                $routeId    = (int)($_POST['route_id'] ?? 0);
                $operatorId = (int)($_POST['operator_id'] ?? 0) ?: null;
                $millis     = sms_pricing_credits_to_millicredits((string)($_POST['price'] ?? ''));
                $confirm    = !empty($_POST['confirm_replace']);
                if ($routeId <= 0 || $millis === null) {
                    flash('error', 'مسیر و قیمت معتبر (عدد با حداکثر سه رقم اعشار) الزامی است.');
                    break;
                }
                if ($millis <= 0) {
                    // Zero/negative rates are refused rather than stored: a free tariff is expressed
                    // by the platform-admin exemption or by a plan, never by a 0-priced route, and a
                    // 0 here would be indistinguishable from "not configured yet".
                    flash('error', 'قیمت باید بزرگ‌تر از صفر باشد.');
                    break;
                }
                // Effective-dated: the new period opens now (UTC) and the CURRENTLY effective row for
                // the same (route, operator) is CLOSED at the same instant rather than rewritten, so
                // every historical snapshot keeps pointing at a rule whose numbers never changed
                // (STEP 34: "close previous period, create new period" — never edit history).
                $now = sms_pricing_now();
                $st = $db->prepare(
                    "SELECT * FROM ellsms_sms_route_prices
                     WHERE route_id = ? AND status = 'active'
                       AND ((operator_id IS NULL AND ? IS NULL) OR operator_id = ?)
                       AND effective_from <= ? AND (effective_to IS NULL OR effective_to > ?)
                     ORDER BY effective_from DESC LIMIT 1"
                );
                $st->execute([$routeId, $operatorId, $operatorId, $now, $now]);
                $current = $st->fetch();
                if ($current && !$confirm) {
                    flash('error', 'برای این مسیر/اپراتور تعرفه‌ی فعالی وجود دارد. برای جایگزینی، گزینه‌ی تأیید را علامت بزنید.');
                    break;
                }
                // Replacing a rate set within the SAME second must not collide on the period's
                // unique index — see sms_pricing_next_effective_from(), which owns that rule so it
                // can be tested without going through this page.
                $effectiveFrom = sms_pricing_next_effective_from($routeId, $operatorId, $now);

                db_transaction(function (PDO $db) use ($current, $effectiveFrom, $routeId, $operatorId, $millis, $me): void {
                    if ($current) {
                        $db->prepare('UPDATE ellsms_sms_route_prices SET effective_to = ? WHERE id = ?')
                           ->execute([$effectiveFrom, (int)$current['id']]);
                    }
                    $db->prepare(
                        'INSERT INTO ellsms_sms_route_prices
                           (route_id, operator_id, operator_slot, price_per_segment_millicredits, currency, effective_from, status, note, created_by)
                         VALUES (?,?,?,?,?,?,?,?,?)'
                    )->execute([$routeId, $operatorId, $operatorId ?? 0, $millis, SMS_PRICING_CURRENCY, $effectiveFrom, 'active', trim((string)($_POST['note'] ?? '')), (int)$me['id']]);
                });
                pricing_audit($me, 'price.create', [
                    'route_id' => $routeId, 'operator_id' => $operatorId,
                    'before_millicredits' => $current ? (int)$current['price_per_segment_millicredits'] : null,
                    'after_millicredits'  => $millis,
                    'effective_from' => $effectiveFrom,
                ]);
                flash('success', 'تعرفه‌ی جدید ثبت شد و دوره‌ی قبلی بسته شد.');
                break;
            }
            case 'price_archive': {
                $id = (int)($_POST['id'] ?? 0);
                $before = $db->query('SELECT * FROM ellsms_sms_route_prices WHERE id = ' . $id)->fetch();
                if (!$before) { flash('error', 'تعرفه پیدا نشد.'); break; }
                $db->prepare("UPDATE ellsms_sms_route_prices SET status = 'archived', effective_to = COALESCE(effective_to, ?) WHERE id = ?")
                   ->execute([sms_pricing_now(), $id]);
                pricing_audit($me, 'price.archive', ['id' => $id, 'route_id' => (int)$before['route_id'], 'millicredits' => (int)$before['price_per_segment_millicredits']]);
                flash('info', 'تعرفه بایگانی شد.');
                break;
            }

            /* ---------------- Legacy fallback switch (STEP 50) ---------------- */
            case 'fallback_toggle': {
                $enabled = !empty($_POST['enabled']) ? '1' : '0';
                set_setting('sms_pricing_legacy_fallback', $enabled);
                pricing_audit($me, 'legacy_fallback.toggle', ['enabled' => $enabled === '1']);
                flash('success', $enabled === '1' ? 'تعرفه‌ی پیش‌فرض سازگاری فعال شد.' : 'تعرفه‌ی پیش‌فرض سازگاری غیرفعال شد — از این پس ارسال بدون تعرفه انجام نمی‌شود.');
                break;
            }
        }
    } catch (PDOException $e) {
        Logger::error('sms_pricing.admin_action_failed', ['do' => $do, 'exception' => $e]);
        flash('error', 'ثبت تغییر ممکن نشد. احتمالاً مقدار تکراری یا نامعتبر است.');
    }

    // The catalog just changed — drop this process's cached view so the redirected GET (and any
    // pricing done by this same process) sees the new configuration immediately rather than up to
    // SMS_PRICING_CACHE_TTL_SECONDS later.
    sms_pricing_cache_reset();
    redirect('/sms-pricing.php?tab=' . urlencode((string)($_POST['tab'] ?? $tab)));
}

$db = db();
$operators = $db->query('SELECT o.*, (SELECT COUNT(*) FROM ellsms_sms_operator_prefixes p WHERE p.operator_id = o.id) AS prefix_count FROM ellsms_sms_operators o ORDER BY o.priority, o.code')->fetchAll();
$providers = $db->query('SELECT p.*, (SELECT COUNT(*) FROM ellsms_sms_routes r WHERE r.provider_id = p.id) AS route_count FROM ellsms_sms_providers p ORDER BY p.priority, p.code')->fetchAll();
$routes    = $db->query('SELECT r.*, p.code AS provider_code, p.name AS provider_name, p.status AS provider_status FROM ellsms_sms_routes r JOIN ellsms_sms_providers p ON p.id = r.provider_id ORDER BY p.code, r.code')->fetchAll();
$prefixes  = $db->query('SELECT x.*, o.code AS operator_code, o.name AS operator_name FROM ellsms_sms_operator_prefixes x JOIN ellsms_sms_operators o ON o.id = x.operator_id ORDER BY x.prefix_length DESC, x.normalized_prefix')->fetchAll();
$senderRoutes = $db->query('SELECT s.*, r.code AS route_code, p.code AS provider_code FROM ellsms_sender_routes s JOIN ellsms_sms_routes r ON r.id = s.route_id JOIN ellsms_sms_providers p ON p.id = r.provider_id ORDER BY s.sender, s.message_type')->fetchAll();
$prices    = $db->query(
    'SELECT rp.*, r.code AS route_code, p.code AS provider_code, o.code AS operator_code
     FROM ellsms_sms_route_prices rp
     JOIN ellsms_sms_routes r ON r.id = rp.route_id
     JOIN ellsms_sms_providers p ON p.id = r.provider_id
     LEFT JOIN ellsms_sms_operators o ON o.id = rp.operator_id
     ORDER BY rp.route_id, rp.operator_id IS NULL DESC, rp.effective_from DESC'
)->fetchAll();
$numbers = $db->query('SELECT number, label FROM ellsms_numbers ORDER BY number')->fetchAll();
$fallbackOn = sms_pricing_legacy_fallback_enabled();
$nowUtc = sms_pricing_now();

/** Is this price row the one currently in effect for its (route, operator) pair? */
function price_is_current(array $row, string $nowUtc): bool {
    return $row['status'] === 'active'
        && (string)$row['effective_from'] <= $nowUtc
        && ($row['effective_to'] === null || (string)$row['effective_to'] > $nowUtc);
}

require __DIR__ . '/../app/views/header.php';
?>
<div class="card">
  <h2>تعرفه‌ی پیامک</h2>
  <p class="muted">
    اپراتورها، پیش‌شماره‌ها، ارائه‌دهنده‌ها، مسیرها و تعرفه‌ها فقط از این صفحه و فقط توسط مدیر سامانه قابل تنظیم‌اند.
    انتخاب مسیر «صریح» است: هیچ انتخاب خودکار ارزان‌ترین مسیر یا جابه‌جایی خودکار بین ارائه‌دهنده‌ها انجام نمی‌شود.
    تشخیص اپراتور بر پایه‌ی پیش‌شماره‌ی پیکربندی‌شده است و پس از جابه‌جایی شماره (پرتابیلیتی) لزوماً اپراتور فعلی را نشان نمی‌دهد.
  </p>
  <form method="post" class="toolbar" style="margin-top:12px">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="fallback_toggle">
    <input type="hidden" name="tab" value="<?= e($tab) ?>">
    <label>
      <input type="checkbox" name="enabled" value="1"<?= $fallbackOn ? ' checked' : '' ?>>
      تعرفه‌ی پیش‌فرض سازگاری (۱ واحد اعتبار برای هر بخش) وقتی تعرفه‌ای پیدا نشد
    </label>
    <button class="btn">ذخیره</button>
    <span class="muted"><?= $fallbackOn ? 'فعال — هیچ ارسالی به دلیل نبود تعرفه متوقف نمی‌شود.' : 'غیرفعال — ارسال بدون تعرفه‌ی مشخص انجام نمی‌شود.' ?></span>
  </form>
</div>

<div class="card">
  <div class="toolbar">
    <?php foreach ([
      'operators' => 'اپراتورها', 'prefixes' => 'پیش‌شماره‌ها', 'providers' => 'ارائه‌دهنده‌ها',
      'routes' => 'مسیرها', 'senders' => 'خط ← مسیر', 'prices' => 'تعرفه‌ها',
    ] as $key => $label): ?>
      <a class="btn<?= $tab === $key ? ' btn-primary' : '' ?>" href="/sms-pricing.php?tab=<?= $key ?>"><?= $label ?></a>
    <?php endforeach; ?>
  </div>
</div>

<?php if ($tab === 'operators'): ?>
<div class="card">
  <h2>افزودن اپراتور</h2>
  <form method="post" class="toolbar">
    <?= csrf_field() ?><input type="hidden" name="do" value="operator_create"><input type="hidden" name="tab" value="operators">
    <label>شناسه <input type="text" name="code" class="ltr" placeholder="mci" required></label>
    <label>نام <input type="text" name="name" placeholder="همراه اول" required></label>
    <label>کشور <input type="text" name="country_code" class="ltr" value="IR" size="4"></label>
    <label>اولویت <input type="number" name="priority" value="0" size="4"></label>
    <button class="btn btn-primary">افزودن</button>
  </form>
</div>
<div class="card">
  <h2>اپراتورها</h2>
  <div class="table-wrap"><table>
    <tr><th>شناسه</th><th>نام</th><th>کشور</th><th>پیش‌شماره‌ها</th><th>وضعیت / اولویت</th></tr>
    <?php foreach ($operators as $o): ?>
    <tr>
      <td class="ltr"><?= e($o['code']) ?></td>
      <td><?= e($o['name']) ?></td>
      <td class="ltr"><?= e($o['country_code']) ?></td>
      <td><?= to_persian_digits((string)$o['prefix_count']) ?></td>
      <td>
        <form method="post" class="toolbar" style="margin:0">
          <?= csrf_field() ?><input type="hidden" name="do" value="operator_update"><input type="hidden" name="tab" value="operators">
          <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
          <input type="text" name="name" value="<?= e($o['name']) ?>" size="14">
          <select name="status">
            <option value="active"<?= $o['status'] === 'active' ? ' selected' : '' ?>>فعال</option>
            <option value="archived"<?= $o['status'] === 'archived' ? ' selected' : '' ?>>بایگانی</option>
          </select>
          <input type="number" name="priority" value="<?= (int)$o['priority'] ?>" size="3">
          <button class="btn">ذخیره</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <p class="muted">اپراتورها حذف نمی‌شوند — بایگانی می‌شوند، تا تعرفه‌ها و سوابق هزینه‌ی قبلی همچنان معتبر بمانند.</p>
</div>

<?php elseif ($tab === 'prefixes'): ?>
<div class="card">
  <h2>افزودن پیش‌شماره</h2>
  <form method="post" class="toolbar">
    <?= csrf_field() ?><input type="hidden" name="do" value="prefix_create"><input type="hidden" name="tab" value="prefixes">
    <label>اپراتور <select name="operator_id" required>
      <?php foreach ($operators as $o): ?><option value="<?= (int)$o['id'] ?>"><?= e($o['name']) ?> (<?= e($o['code']) ?>)</option><?php endforeach; ?>
    </select></label>
    <label>پیش‌شماره <input type="text" name="prefix" class="ltr" placeholder="0912" required></label>
    <label>اولویت <input type="number" name="priority" value="0" size="4"></label>
    <button class="btn btn-primary">افزودن</button>
  </form>
  <p class="muted">فقط رقم. الگو/regex پشتیبانی نمی‌شود. تطبیق همیشه «طولانی‌ترین پیش‌شماره» است: 0912 بر 091 و 09 اولویت دارد.</p>
</div>
<div class="card">
  <h2>پیش‌شماره‌ها</h2>
  <div class="table-wrap"><table>
    <tr><th>پیش‌شماره</th><th>شکل تطبیق</th><th>طول</th><th>اپراتور</th><th>وضعیت</th></tr>
    <?php foreach ($prefixes as $p): ?>
    <tr>
      <td class="ltr"><?= e($p['prefix']) ?></td>
      <td class="ltr"><?= e($p['normalized_prefix']) ?></td>
      <td><?= to_persian_digits((string)$p['prefix_length']) ?></td>
      <td><?= e($p['operator_name']) ?></td>
      <td>
        <form method="post" class="toolbar" style="margin:0">
          <?= csrf_field() ?><input type="hidden" name="do" value="prefix_update"><input type="hidden" name="tab" value="prefixes">
          <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
          <select name="status">
            <option value="active"<?= $p['status'] === 'active' ? ' selected' : '' ?>>فعال</option>
            <option value="archived"<?= $p['status'] === 'archived' ? ' selected' : '' ?>>بایگانی</option>
          </select>
          <input type="number" name="priority" value="<?= (int)$p['priority'] ?>" size="3">
          <button class="btn">ذخیره</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table></div>
</div>

<?php elseif ($tab === 'providers'): ?>
<div class="card">
  <h2>افزودن ارائه‌دهنده</h2>
  <form method="post" class="toolbar">
    <?= csrf_field() ?><input type="hidden" name="do" value="provider_create"><input type="hidden" name="tab" value="providers">
    <label>شناسه <input type="text" name="code" class="ltr" placeholder="provider_a" required></label>
    <label>نام <input type="text" name="name" required></label>
    <label>توضیح <input type="text" name="description" size="30"></label>
    <button class="btn btn-primary">افزودن</button>
  </form>
  <p class="muted">این جدول فقط اطلاعات تعرفه/پیکربندی است. کلیدها و اطلاعات محرمانه‌ی اتصال به درگاه اینجا نگهداری و نمایش داده نمی‌شوند.</p>
</div>
<div class="card">
  <h2>ارائه‌دهنده‌ها</h2>
  <div class="table-wrap"><table>
    <tr><th>شناسه</th><th>نام</th><th>مسیرها</th><th>وضعیت</th></tr>
    <?php foreach ($providers as $p): ?>
    <tr>
      <td class="ltr"><?= e($p['code']) ?></td>
      <td><?= e($p['name']) ?></td>
      <td><?= to_persian_digits((string)$p['route_count']) ?></td>
      <td>
        <form method="post" class="toolbar" style="margin:0">
          <?= csrf_field() ?><input type="hidden" name="do" value="provider_update"><input type="hidden" name="tab" value="providers">
          <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
          <input type="text" name="name" value="<?= e($p['name']) ?>" size="14">
          <select name="status">
            <option value="active"<?= $p['status'] === 'active' ? ' selected' : '' ?>>فعال</option>
            <option value="archived"<?= $p['status'] === 'archived' ? ' selected' : '' ?>>بایگانی</option>
          </select>
          <button class="btn">ذخیره</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table></div>
</div>

<?php elseif ($tab === 'routes'): ?>
<div class="card">
  <h2>افزودن مسیر</h2>
  <form method="post" class="toolbar">
    <?= csrf_field() ?><input type="hidden" name="do" value="route_create"><input type="hidden" name="tab" value="routes">
    <label>ارائه‌دهنده <select name="provider_id" required>
      <?php foreach ($providers as $p): if ($p['status'] !== 'active') continue; ?><option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?>
    </select></label>
    <label>شناسه <input type="text" name="code" class="ltr" placeholder="promo" required></label>
    <label>نام <input type="text" name="name" required></label>
    <label>نوع پیام <select name="message_type">
      <?php foreach (SMS_MESSAGE_TYPES as $t): ?><option value="<?= $t ?>"><?= $t ?></option><?php endforeach; ?>
    </select></label>
    <label><input type="checkbox" name="is_default" value="1"> مسیر پیش‌فرض این نوع پیام</label>
    <button class="btn btn-primary">افزودن</button>
  </form>
</div>
<div class="card">
  <h2>مسیرها</h2>
  <div class="table-wrap"><table>
    <tr><th>ارائه‌دهنده</th><th>مسیر</th><th>نوع پیام</th><th>پیش‌فرض</th><th>وضعیت</th></tr>
    <?php foreach ($routes as $r): ?>
    <tr>
      <td class="ltr"><?= e($r['provider_code']) ?><?= $r['provider_status'] !== 'active' ? ' <span class="muted">(بایگانی)</span>' : '' ?></td>
      <td class="ltr"><?= e($r['code']) ?></td>
      <td class="ltr"><?= e($r['message_type']) ?></td>
      <td><?= $r['is_default'] ? '✓' : '' ?></td>
      <td>
        <form method="post" class="toolbar" style="margin:0">
          <?= csrf_field() ?><input type="hidden" name="do" value="route_update"><input type="hidden" name="tab" value="routes">
          <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <input type="text" name="name" value="<?= e($r['name']) ?>" size="12">
          <select name="status">
            <option value="active"<?= $r['status'] === 'active' ? ' selected' : '' ?>>فعال</option>
            <option value="archived"<?= $r['status'] === 'archived' ? ' selected' : '' ?>>بایگانی</option>
          </select>
          <label><input type="checkbox" name="is_default" value="1"<?= $r['is_default'] ? ' checked' : '' ?>> پیش‌فرض</label>
          <button class="btn">ذخیره</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table></div>
</div>

<?php elseif ($tab === 'senders'): ?>
<div class="card">
  <h2>تخصیص مسیر به خط ارسال</h2>
  <form method="post" class="toolbar">
    <?= csrf_field() ?><input type="hidden" name="do" value="sender_route_create"><input type="hidden" name="tab" value="senders">
    <label>خط ارسال <input type="text" name="sender" class="ltr" list="known-senders" placeholder="5000435800" required></label>
    <datalist id="known-senders"><?php foreach ($numbers as $n): ?><option value="<?= e($n['number']) ?>"><?= e($n['label']) ?></option><?php endforeach; ?></datalist>
    <label>نوع پیام <select name="message_type">
      <?php foreach (SMS_MESSAGE_TYPES as $t): ?><option value="<?= $t ?>"><?= $t ?></option><?php endforeach; ?>
    </select></label>
    <label>مسیر <select name="route_id" required>
      <?php foreach ($routes as $r): if ($r['status'] !== 'active') continue; ?>
        <option value="<?= (int)$r['id'] ?>"><?= e($r['provider_code'] . ' / ' . $r['code']) ?></option>
      <?php endforeach; ?>
    </select></label>
    <button class="btn btn-primary">افزودن</button>
  </form>
  <p class="muted">خطی که تخصیص اختصاصی ندارد از مسیر پیش‌فرض همان نوع پیام استفاده می‌کند — انتخاب همیشه قطعی و یکتاست.</p>
</div>
<div class="card">
  <h2>تخصیص‌ها</h2>
  <div class="table-wrap"><table>
    <tr><th>خط</th><th>نوع پیام</th><th>مسیر</th><th>وضعیت</th></tr>
    <?php foreach ($senderRoutes as $s): ?>
    <tr>
      <td class="ltr msisdn"><?= e($s['sender']) ?></td>
      <td class="ltr"><?= e($s['message_type']) ?></td>
      <td class="ltr"><?= e($s['provider_code'] . ' / ' . $s['route_code']) ?></td>
      <td>
        <form method="post" class="toolbar" style="margin:0">
          <?= csrf_field() ?><input type="hidden" name="do" value="sender_route_update"><input type="hidden" name="tab" value="senders">
          <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
          <select name="status">
            <option value="active"<?= $s['status'] === 'active' ? ' selected' : '' ?>>فعال</option>
            <option value="archived"<?= $s['status'] === 'archived' ? ' selected' : '' ?>>بایگانی</option>
          </select>
          <button class="btn">ذخیره</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table></div>
</div>

<?php else: ?>
<div class="card">
  <h2>ثبت تعرفه‌ی جدید</h2>
  <form method="post" class="toolbar">
    <?= csrf_field() ?><input type="hidden" name="do" value="price_create"><input type="hidden" name="tab" value="prices">
    <label>مسیر <select name="route_id" required>
      <?php foreach ($routes as $r): if ($r['status'] !== 'active') continue; ?>
        <option value="<?= (int)$r['id'] ?>"><?= e($r['provider_code'] . ' / ' . $r['code'] . ' — ' . $r['message_type']) ?></option>
      <?php endforeach; ?>
    </select></label>
    <label>اپراتور <select name="operator_id">
      <option value="0">— پیش‌فرض مسیر (همه‌ی اپراتورها و شماره‌های ناشناخته)</option>
      <?php foreach ($operators as $o): if ($o['status'] !== 'active') continue; ?>
        <option value="<?= (int)$o['id'] ?>"><?= e($o['name']) ?></option>
      <?php endforeach; ?>
    </select></label>
    <label>قیمت هر بخش (واحد اعتبار) <input type="text" name="price" class="ltr" placeholder="1.25" required></label>
    <label>توضیح <input type="text" name="note" size="20"></label>
    <label><input type="checkbox" name="confirm_replace" value="1"> تأیید جایگزینی تعرفه‌ی فعال فعلی</label>
    <button class="btn btn-primary">ثبت</button>
  </form>
  <p class="muted">
    ثبت تعرفه‌ی جدید، دوره‌ی فعلی را در همین لحظه می‌بندد و دوره‌ی تازه‌ای باز می‌کند؛ تعرفه‌ی قدیمی بازنویسی نمی‌شود
    و هزینه‌ی ارسال‌های گذشته تغییر نمی‌کند. زمان‌ها به وقت UTC ثبت می‌شوند.
  </p>
</div>
<div class="card">
  <h2>تعرفه‌ها</h2>
  <div class="table-wrap"><table>
    <tr><th>مسیر</th><th>اپراتور</th><th>هر بخش</th><th>از (UTC)</th><th>تا (UTC)</th><th>وضعیت</th><th></th></tr>
    <?php foreach ($prices as $p): $current = price_is_current($p, $nowUtc); ?>
    <tr<?= $current ? ' style="font-weight:600"' : '' ?>>
      <td class="ltr"><?= e($p['provider_code'] . ' / ' . $p['route_code']) ?></td>
      <td><?= $p['operator_code'] === null ? '<span class="muted">پیش‌فرض مسیر</span>' : e($p['operator_code']) ?></td>
      <td class="ltr"><?= e((string)sms_pricing_millicredits_to_credits((int)$p['price_per_segment_millicredits'])) ?> <?= e($p['currency']) ?></td>
      <td class="ltr"><?= e((string)$p['effective_from']) ?></td>
      <td class="ltr"><?= $p['effective_to'] === null ? '—' : e((string)$p['effective_to']) ?></td>
      <td><?= $current ? 'جاری' : ($p['status'] === 'archived' ? 'بایگانی' : 'تاریخی') ?></td>
      <td>
        <?php if ($p['status'] === 'active'): ?>
        <form method="post" style="margin:0" onsubmit="return confirm('این تعرفه بایگانی شود؟');">
          <?= csrf_field() ?><input type="hidden" name="do" value="price_archive"><input type="hidden" name="tab" value="prices">
          <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
          <button class="btn">بایگانی</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </table></div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
