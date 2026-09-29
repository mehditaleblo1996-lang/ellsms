<?php
/**
 * Core of the plugin: when an order's status changes, send the customer the SMS configured for the new
 * status. Sending happens in the background through WooCommerce's Action Scheduler (checkout is never
 * slowed by the SMS call). Each status SMS goes out at most once per order (order meta), and every send
 * carries a client_message_id so a retry after an answer of unknown outcome never sends twice. The
 * outcome is written as a private order note; "resend" is available in the order's actions box.
 */

defined('ABSPATH') || exit;

final class Ellsms_WC_Plugin
{
    public const OPTION = 'ellsms_wc_settings';
    public const ACTION = 'ellsms_wc_send_status_sms';

    /** @var self|null */
    private static $instance;

    public static function instance(): self
    {
        return self::$instance ?? (self::$instance = new self());
    }

    public function register(): void
    {
        add_action('woocommerce_order_status_changed', [$this, 'on_status_changed'], 20, 4);
        add_action(self::ACTION, [$this, 'send_status_sms'], 10, 2);
        // "Order actions" box on the order page: resend the SMS of the order's current status.
        add_filter('woocommerce_order_actions', [$this, 'order_actions'], 10, 2);
        add_action('woocommerce_order_action_ellsms_resend', [$this, 'resend_current_status']);
    }

    public function order_actions($actions, $order = null)
    {
        $actions['ellsms_resend'] = 'ارسال دوباره‌ی پیامک وضعیت فعلی (ELLSMS)';
        return $actions;
    }

    /** Manual resend from the order page: forgets that this status was sent, then sends it now. */
    public function resend_current_status($order): void
    {
        if (!current_user_can('edit_shop_orders')) {
            return;
        }
        $status = (string)$order->get_status();
        $sentMap = self::meta_map($order, self::META_SENT);
        if (isset($sentMap[$status])) {
            unset($sentMap[$status]);
            // A new key: the earlier message was delivered under the old one and must not be replayed.
            $attempts = self::meta_map($order, self::META_ATTEMPTS);
            $attempts[$status] = (int)($attempts[$status] ?? 0) + 1;
            $order->update_meta_data(self::META_SENT, $sentMap);
            $order->update_meta_data(self::META_ATTEMPTS, $attempts);
            $order->save();
        }
        try {
            $this->send_status_sms((int)$order->get_id(), $status, true);
        } catch (\Ellsms\EllsmsException $e) {
            // Already written as an order note.
        }
    }

    /** Settings with defaults. */
    public static function settings(): array
    {
        $saved = get_option(self::OPTION, []);
        return (is_array($saved) ? $saved : []) + [
            'base_url' => '',
            'api_key' => '',
            'originator' => '',
            'statuses' => [],   // status slug (without "wc-") => ['enabled' => bool, 'template' => string]
        ];
    }

    /** The default text offered for a status that has none yet. */
    public static function default_template(string $status): string
    {
        $defaults = [
            'pending' => '{first_name} عزیز، سفارش {order_number} ثبت شد و در انتظار پرداخت است. مبلغ: {total} {currency}' . "\n{site_name}",
            'processing' => '{first_name} عزیز، سفارش {order_number} پرداخت شد و در حال آماده‌سازی است.' . "\n{site_name}",
            'on-hold' => '{first_name} عزیز، سفارش {order_number} در انتظار بررسی است.' . "\n{site_name}",
            'completed' => '{first_name} عزیز، سفارش {order_number} تکمیل و ارسال شد. از خرید شما سپاسگزاریم.' . "\n{site_name}",
            'cancelled' => '{first_name} عزیز، سفارش {order_number} لغو شد.' . "\n{site_name}",
            'refunded' => '{first_name} عزیز، مبلغ سفارش {order_number} بازگردانده شد.' . "\n{site_name}",
            'failed' => '{first_name} عزیز، پرداخت سفارش {order_number} ناموفق بود.' . "\n{site_name}",
        ];
        return $defaults[$status] ?? '{first_name} عزیز، وضعیت سفارش {order_number} به «{status}» تغییر کرد.' . "\n{site_name}";
    }

    /** The variables a template may use, with their Persian descriptions (shown on the settings page). */
    public static function variables(): array
    {
        return [
            '{first_name}' => 'نام مشتری',
            '{last_name}' => 'نام خانوادگی مشتری',
            '{full_name}' => 'نام و نام خانوادگی',
            '{order_id}' => 'شناسه‌ی سفارش',
            '{order_number}' => 'شماره‌ی سفارش',
            '{total}' => 'مبلغ کل',
            '{currency}' => 'واحد پول',
            '{status}' => 'نام وضعیت جدید',
            '{items}' => 'نام محصولات (کوتاه‌شده)',
            '{payment_method}' => 'روش پرداخت',
            '{date}' => 'تاریخ ثبت سفارش',
            '{site_name}' => 'نام فروشگاه',
        ];
    }

    /**
     * Normalises an Iranian mobile number to 98XXXXXXXXXX. Accepts 0912…, 912…, +98912…, 0098912…, 98912…,
     * Persian/Arabic digits, spaces and dashes. Returns null for anything that is not an Iranian mobile.
     */
    public static function normalize_mobile(string $raw): ?string
    {
        $raw = strtr($raw, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
                            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if (strpos($digits, '0098') === 0) $digits = substr($digits, 4);
        elseif (strpos($digits, '98') === 0 && strlen($digits) === 12) $digits = substr($digits, 2);
        elseif (strpos($digits, '0') === 0) $digits = substr($digits, 1);
        return preg_match('/^9\d{9}$/', $digits) === 1 ? '98' . $digits : null;
    }

    /** Fills a template from an order. Unknown {placeholders} are left as they are. */
    public static function render(string $template, $order): string
    {
        $items = [];
        foreach ($order->get_items() as $item) {
            $items[] = $item->get_name();
        }
        $itemsText = implode('، ', $items);
        if (function_exists('mb_strlen') && mb_strlen($itemsText) > 60) {
            $itemsText = mb_substr($itemsText, 0, 59) . '…';
        }
        $created = $order->get_date_created();
        $statuses = function_exists('wc_get_order_statuses') ? wc_get_order_statuses() : [];
        $status = (string)$order->get_status();
        $values = [
            '{first_name}' => (string)$order->get_billing_first_name(),
            '{last_name}' => (string)$order->get_billing_last_name(),
            '{full_name}' => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
            '{order_id}' => (string)$order->get_id(),
            '{order_number}' => (string)$order->get_order_number(),
            '{total}' => self::plain_number((string)$order->get_total()),
            '{currency}' => self::currency_label((string)$order->get_currency()),
            '{status}' => (string)($statuses['wc-' . $status] ?? $status),
            '{items}' => $itemsText,
            '{payment_method}' => (string)$order->get_payment_method_title(),
            '{date}' => $created ? (function_exists('wp_date') ? wp_date('Y/m/d', $created->getTimestamp()) : $created->date('Y/m/d')) : '',
            '{site_name}' => wp_specialchars_decode((string)get_bloginfo('name'), ENT_QUOTES),
        ];
        return trim(strtr($template, $values));
    }

    private static function plain_number(string $amount): string
    {
        $n = (float)$amount;
        return floor($n) == $n ? number_format($n, 0, '.', ',') : number_format($n, 2, '.', ',');
    }

    private static function currency_label(string $code): string
    {
        $labels = ['IRR' => 'ریال', 'IRT' => 'تومان', 'IRHT' => 'هزار تومان', 'IRHR' => 'هزار ریال'];
        return $labels[$code] ?? $code;
    }

    /** woocommerce_order_status_changed: queue the SMS for the new status, if one is configured. */
    public function on_status_changed($orderId, $from, $to, $order = null): void
    {
        $to = (string)$to;
        $config = self::settings()['statuses'][$to] ?? null;
        if (!is_array($config) || empty($config['enabled']) || trim((string)($config['template'] ?? '')) === '') {
            return;
        }
        $args = [(int)$orderId, $to];
        if (function_exists('as_enqueue_async_action')) {
            // A status that flips twice in one request is queued once (the args are identical).
            if (function_exists('as_has_scheduled_action') && as_has_scheduled_action(self::ACTION, $args, 'ellsms')) {
                return;
            }
            as_enqueue_async_action(self::ACTION, $args, 'ellsms');
            return;
        }
        $this->send_status_sms((int)$orderId, $to);
    }

    /**
     * Idempotency key for (site, order, status, attempt). ELLSMS answers a repeated key with the FIRST
     * answer for 24 hours: after an answer of unknown outcome (network error, 5xx) the same key is reused,
     * so a message that did go out is never sent twice; after a definite refusal (e.g. no credit) the
     * attempt number moves on, so a later retry really sends instead of replaying the refusal.
     */
    public static function client_message_id(int $orderId, string $status, int $attempt = 0): string
    {
        return 'wc-' . substr(md5((string)home_url()), 0, 10) . '-' . $orderId . '-' . preg_replace('/[^A-Za-z0-9_.:-]/', '_', $status)
            . ($attempt > 0 ? '-r' . $attempt : '');
    }

    private const META_SENT = '_ellsms_wc_sent';
    private const META_ATTEMPTS = '_ellsms_wc_attempts';

    private static function meta_map($order, string $key): array
    {
        $value = $order->get_meta($key, true);
        return is_array($value) ? $value : [];
    }

    /** Action Scheduler job (or inline fallback): send one status SMS and record the outcome on the order. */
    public function send_status_sms($orderId, $status, bool $manual = false): void
    {
        $orderId = (int)$orderId;
        $status = (string)$status;
        $order = wc_get_order($orderId);
        if (!$order) {
            return;
        }
        $settings = self::settings();
        $config = $settings['statuses'][$status] ?? null;
        if (!is_array($config) || trim((string)($config['template'] ?? '')) === '' || (empty($config['enabled']) && !$manual)) {
            if ($manual) {
                $order->add_order_note('ELLSMS: برای این وضعیت متن پیامکی تنظیم نشده است.');
            }
            return;
        }
        if ((string)$order->get_status() !== $status) {
            // The order moved on before the queue ran: an SMS about a status it no longer has would mislead.
            return;
        }
        $sentMap = self::meta_map($order, self::META_SENT);
        if (isset($sentMap[$status])) {
            // Each status SMS goes out at most once per order (a status set again later is not re-announced).
            return;
        }
        $label = (string)(wc_get_order_statuses()['wc-' . $status] ?? $status);
        $mobile = self::normalize_mobile((string)$order->get_billing_phone());
        if ($mobile === null) {
            $order->add_order_note('ELLSMS: پیامک «' . $label . '» ارسال نشد — شماره‌ی موبایل مشتری معتبر نیست.');
            return;
        }
        $client = self::client($settings);
        if ($client === null) {
            $order->add_order_note('ELLSMS: پیامک «' . $label . '» ارسال نشد — تنظیمات افزونه (آدرس پنل و کلید API) کامل نیست.');
            return;
        }
        $text = self::render((string)$config['template'], $order);
        $attempts = self::meta_map($order, self::META_ATTEMPTS);
        $attempt = (int)($attempts[$status] ?? 0);
        $options = ['client_message_id' => self::client_message_id($orderId, $status, $attempt)];
        if (trim((string)$settings['originator']) !== '') {
            $options['originator'] = trim((string)$settings['originator']);
        }
        try {
            $result = $client->sendMessage([$mobile], $text, $options);
            $sentMap[$status] = time();
            $order->update_meta_data(self::META_SENT, $sentMap);
            $order->add_order_note('ELLSMS: پیامک «' . $label . '» برای ' . $mobile . ' ارسال شد.'
                . (isset($result['id']) ? ' (شناسه‌ی پیام: ' . $result['id'] . ')' : ''));
            $order->save();
        } catch (\Ellsms\EllsmsException $e) {
            $unknownOutcome = $e->getStatus() === 0 || $e->getStatus() >= 500;
            if (!$unknownOutcome && $e->getStatus() !== 429) {
                // A definite refusal is stored by ELLSMS under this key: move to a fresh key for any retry.
                $attempts[$status] = $attempt + 1;
                $order->update_meta_data(self::META_ATTEMPTS, $attempts);
            }
            $order->add_order_note('ELLSMS: پیامک «' . $label . '» ارسال نشد — ' . self::error_text($e));
            $order->save();
            if ($unknownOutcome || $e->getStatus() === 429) {
                // Temporary: rethrowing marks the Action Scheduler job failed so it shows up in
                // WooCommerce → Status → Scheduled Actions. The client_message_id makes a manual re-run safe.
                throw $e;
            }
        }
    }

    public static function client(?array $settings = null): ?\Ellsms\Client
    {
        $settings = $settings ?? self::settings();
        if (trim((string)$settings['base_url']) === '' || trim((string)$settings['api_key']) === '') {
            return null;
        }
        return new \Ellsms\Client((string)$settings['api_key'], (string)$settings['base_url'], 20, 1);
    }

    /** A short Persian explanation of an API error for order notes and the settings page. */
    public static function error_text(\Ellsms\EllsmsException $e): string
    {
        $known = [
            'unauthenticated' => 'کلید API نادرست، منقضی یا باطل شده است',
            'forbidden' => 'کلید API دسترسی «messages:send» ندارد',
            'feature_not_available' => 'پلن پنل شما شامل دسترسی API نیست',
            'subscription_inactive' => 'اشتراک پنل فعال نیست',
            'quota_exceeded' => 'سقف پیامک دوره‌ی پلن تمام شده است',
            'rate_limited' => 'تعداد درخواست‌ها زیاد است؛ کمی بعد دوباره تلاش می‌شود',
            'service_unavailable' => 'API پنل غیرفعال یا موقتاً در دسترس نیست',
            'network_error' => 'اتصال به پنل برقرار نشد',
        ];
        $text = $known[$e->getErrorCode()] ?? $e->getMessage();
        if ($e->getErrorCode() === 'validation_failed' && $e->getFields() !== []) {
            $fields = $e->getFields();
            if (isset($fields['originator'])) $text = 'خط ارسال انتخاب‌شده برای این کلید مجاز نیست';
            elseif (isset($fields['content'])) $text = 'متن پیامک پذیرفته نشد (ممکن است شامل کلمه‌ی غیرمجاز باشد)';
        }
        return $text . ($e->getRequestId() ? ' (کد پیگیری: ' . $e->getRequestId() . ')' : '');
    }
}
