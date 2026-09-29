<?php
/**
 * Plugin Name:       ELLSMS برای ووکامرس
 * Description:       ارسال خودکار پیامک به مشتری هنگام تغییر وضعیت سفارش، از طریق پنل پیامک ELLSMS.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            ELLSMS
 * License:           MIT
 * Text Domain:       ellsms-woocommerce
 * WC requires at least: 6.0
 * WC tested up to:   9.9
 */

defined('ABSPATH') || exit;

define('ELLSMS_WC_VERSION', '1.0.0');
define('ELLSMS_WC_FILE', __FILE__);
define('ELLSMS_WC_DIR', __DIR__);

// The ELLSMS PHP SDK ships inside the plugin (lib/). Another plugin may already have loaded it.
foreach (['EllsmsException', 'Webhook', 'Client'] as $ellsms_wc_class) {
    if (!class_exists('Ellsms\\' . $ellsms_wc_class, false)) {
        require_once __DIR__ . '/lib/ellsms-php/' . $ellsms_wc_class . '.php';
    }
}
unset($ellsms_wc_class);

require_once __DIR__ . '/includes/class-ellsms-wc-plugin.php';
require_once __DIR__ . '/includes/class-ellsms-wc-settings.php';

// Works with WooCommerce's High-Performance Order Storage: the plugin only uses the WC_Order API.
add_action('before_woocommerce_init', static function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', ELLSMS_WC_FILE, true);
    }
});

add_action('plugins_loaded', static function () {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', static function () {
            echo '<div class="notice notice-error"><p>افزونه‌ی «ELLSMS برای ووکامرس» به ووکامرس نیاز دارد.</p></div>';
        });
        return;
    }
    Ellsms_WC_Plugin::instance()->register();
    if (is_admin()) {
        Ellsms_WC_Settings::register();
    }
});
