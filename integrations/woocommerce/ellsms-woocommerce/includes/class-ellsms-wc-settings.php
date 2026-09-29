<?php
/**
 * Settings page: WooCommerce → پیامک ELLSMS. Panel address, API key (never shown again once saved),
 * sending line, and one on/off switch + text per order status. Also "test connection" and "send test SMS".
 */

defined('ABSPATH') || exit;

final class Ellsms_WC_Settings
{
    private const SLUG = 'ellsms-woocommerce';
    private const NONCE = 'ellsms_wc_settings';

    public static function register(): void
    {
        add_action('admin_menu', static function () {
            add_submenu_page('woocommerce', 'پیامک ELLSMS', 'پیامک ELLSMS', 'manage_woocommerce', self::SLUG, [self::class, 'render']);
        });
        add_filter('plugin_action_links_' . plugin_basename(ELLSMS_WC_FILE), static function ($links) {
            array_unshift($links, '<a href="' . esc_url(admin_url('admin.php?page=' . self::SLUG)) . '">تنظیمات</a>');
            return $links;
        });
    }

    /** Handles a POST from the page; returns [type, message] for the notice, or null. */
    public static function handle_post(): ?array
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !isset($_POST['ellsms_wc_do'])) {
            return null;
        }
        if (!current_user_can('manage_woocommerce')) {
            return ['error', 'دسترسی ندارید.'];
        }
        check_admin_referer(self::NONCE);
        $do = sanitize_key(wp_unslash($_POST['ellsms_wc_do']));
        $settings = Ellsms_WC_Plugin::settings();

        if ($do === 'save') {
            $baseUrl = esc_url_raw(trim((string)wp_unslash($_POST['base_url'] ?? '')));
            if ($baseUrl !== '' && stripos($baseUrl, 'https://') !== 0 && stripos($baseUrl, 'http://localhost') !== 0 && stripos($baseUrl, 'http://127.0.0.1') !== 0) {
                return ['error', 'آدرس پنل باید با https:// شروع شود.'];
            }
            $settings['base_url'] = $baseUrl;
            $newKey = trim((string)wp_unslash($_POST['api_key'] ?? ''));
            if ($newKey !== '') {
                $settings['api_key'] = sanitize_text_field($newKey);
            }
            $settings['originator'] = preg_replace('/\D/', '', (string)wp_unslash($_POST['originator'] ?? '')) ?? '';
            $statuses = [];
            $posted = isset($_POST['statuses']) && is_array($_POST['statuses']) ? wp_unslash($_POST['statuses']) : [];
            foreach (array_keys(wc_get_order_statuses()) as $key) {
                $slug = substr($key, 3);
                $row = is_array($posted[$slug] ?? null) ? $posted[$slug] : [];
                $statuses[$slug] = [
                    'enabled' => !empty($row['enabled']),
                    'template' => sanitize_textarea_field((string)($row['template'] ?? '')),
                ];
            }
            $settings['statuses'] = $statuses;
            update_option(Ellsms_WC_Plugin::OPTION, $settings, false);
            return ['success', 'تنظیمات ذخیره شد.'];
        }

        if ($do === 'forget_key') {
            $settings['api_key'] = '';
            update_option(Ellsms_WC_Plugin::OPTION, $settings, false);
            return ['success', 'کلید API حذف شد.'];
        }

        $client = Ellsms_WC_Plugin::client($settings);
        if ($client === null) {
            return ['error', 'ابتدا آدرس پنل و کلید API را ذخیره کنید.'];
        }
        try {
            if ($do === 'test_connection') {
                $me = $client->me();
                $scopes = isset($me['scopes']) && is_array($me['scopes']) ? $me['scopes'] : [];
                if (!in_array('messages:send', $scopes, true)) {
                    return ['error', 'اتصال برقرار است، اما این کلید دسترسی messages:send ندارد و نمی‌تواند پیامک بفرستد.'];
                }
                if (!in_array('balance:read', $scopes, true)) {
                    return ['success', 'اتصال برقرار است و کلید اجازه‌ی ارسال دارد (برای دیدن اعتبار، دسترسی balance:read را هم به کلید بدهید).'];
                }
                $balance = $client->balance();
                return ['success', 'اتصال برقرار است. اعتبار قابل استفاده: ' . number_format((int)($balance['available'] ?? 0))];
            }
            if ($do === 'test_sms') {
                $mobile = Ellsms_WC_Plugin::normalize_mobile((string)wp_unslash($_POST['test_mobile'] ?? ''));
                if ($mobile === null) {
                    return ['error', 'شماره‌ی موبایل آزمایشی معتبر نیست.'];
                }
                $options = $settings['originator'] !== '' ? ['originator' => $settings['originator']] : [];
                $client->sendMessage([$mobile], 'پیامک آزمایشی افزونه‌ی ELLSMS از ' . wp_specialchars_decode((string)get_bloginfo('name'), ENT_QUOTES), $options);
                return ['success', 'پیامک آزمایشی برای ' . $mobile . ' ارسال شد.'];
            }
        } catch (\Ellsms\EllsmsException $e) {
            return ['error', Ellsms_WC_Plugin::error_text($e)];
        }
        return null;
    }

    public static function render(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('دسترسی ندارید.');
        }
        $notice = self::handle_post();
        $settings = Ellsms_WC_Plugin::settings();
        $key = (string)$settings['api_key'];
        $keyHint = $key !== '' ? 'ذخیره شده (…' . esc_html(substr($key, -4)) . ') — برای تغییر، کلید جدید را وارد کنید' : 'ellsms_live_…';
        ?>
        <div class="wrap" dir="rtl">
            <h1>پیامک ELLSMS برای ووکامرس</h1>
            <?php if ($notice): ?>
                <div class="notice notice-<?php echo $notice[0] === 'success' ? 'success' : 'error'; ?> is-dismissible"><p><?php echo esc_html($notice[1]); ?></p></div>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field(self::NONCE); ?>
                <h2>اتصال به پنل</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="ellsms-base-url">آدرس پنل</label></th>
                        <td><input id="ellsms-base-url" name="base_url" type="url" class="regular-text" dir="ltr" value="<?php echo esc_attr((string)$settings['base_url']); ?>" placeholder="https://panel.example.com">
                            <p class="description">همان آدرسی که با آن وارد پنل پیامک می‌شوید.</p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ellsms-api-key">کلید API</label></th>
                        <td><input id="ellsms-api-key" name="api_key" type="password" class="regular-text" dir="ltr" autocomplete="new-password" value="" placeholder="<?php echo esc_attr($keyHint); ?>">
                            <p class="description">در پنل: یکپارچه‌سازی ← کلیدهای API ← ساخت کلید با دسترسی <code>messages:send</code> و <code>balance:read</code>.</p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ellsms-originator">خط ارسال (اختیاری)</label></th>
                        <td><input id="ellsms-originator" name="originator" type="text" class="regular-text" dir="ltr" value="<?php echo esc_attr((string)$settings['originator']); ?>" placeholder="5000…">
                            <p class="description">خالی = خط پیش‌فرض حساب شما در پنل.</p></td>
                    </tr>
                </table>

                <h2>پیامک هر وضعیت سفارش</h2>
                <p>متغیرهای قابل استفاده در متن:
                    <?php foreach (Ellsms_WC_Plugin::variables() as $var => $label): ?>
                        <code title="<?php echo esc_attr($label); ?>"><?php echo esc_html($var); ?></code>
                    <?php endforeach; ?>
                </p>
                <table class="widefat striped" style="max-width:900px">
                    <thead><tr><th style="width:170px">وضعیت</th><th style="width:60px">فعال</th><th>متن پیامک</th></tr></thead>
                    <tbody>
                    <?php foreach (wc_get_order_statuses() as $statusKey => $statusLabel):
                        $slug = substr($statusKey, 3);
                        $row = $settings['statuses'][$slug] ?? ['enabled' => false, 'template' => Ellsms_WC_Plugin::default_template($slug)];
                        ?>
                        <tr>
                            <td><strong><?php echo esc_html($statusLabel); ?></strong><br><code><?php echo esc_html($slug); ?></code></td>
                            <td><input type="checkbox" name="statuses[<?php echo esc_attr($slug); ?>][enabled]" value="1" <?php checked(!empty($row['enabled'])); ?>></td>
                            <td><textarea name="statuses[<?php echo esc_attr($slug); ?>][template]" rows="3" style="width:100%"><?php echo esc_textarea((string)$row['template']); ?></textarea></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="description">پیامک هر وضعیت برای هر سفارش حداکثر یک بار ارسال می‌شود و نتیجه در یادداشت‌های سفارش ثبت می‌شود.
                    ارسال دوباره: صفحه‌ی سفارش ← کادر «عملیات سفارش» ← «ارسال دوباره‌ی پیامک وضعیت فعلی».</p>
                <p><button class="button button-primary" name="ellsms_wc_do" value="save">ذخیره‌ی تنظیمات</button></p>
            </form>

            <hr>
            <h2>آزمایش</h2>
            <form method="post" style="display:inline-block;margin-left:24px">
                <?php wp_nonce_field(self::NONCE); ?>
                <button class="button" name="ellsms_wc_do" value="test_connection">آزمایش اتصال و نمایش اعتبار</button>
            </form>
            <form method="post" style="display:inline-block">
                <?php wp_nonce_field(self::NONCE); ?>
                <input name="test_mobile" type="text" dir="ltr" placeholder="0912…">
                <button class="button" name="ellsms_wc_do" value="test_sms">ارسال پیامک آزمایشی</button>
            </form>
            <?php if ($key !== ''): ?>
            <form method="post" style="margin-top:16px" onsubmit="return confirm('کلید API حذف شود؟')">
                <?php wp_nonce_field(self::NONCE); ?>
                <button class="button-link-delete" name="ellsms_wc_do" value="forget_key">حذف کلید API ذخیره‌شده</button>
            </form>
            <?php endif; ?>
        </div>
        <?php
    }
}
