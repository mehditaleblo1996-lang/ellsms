<?php
/**
 * A minimal stand-in for the WordPress + WooCommerce functions the ELLSMS plugin calls, so its logic can be
 * exercised against the REAL ELLSMS API without a WordPress install (whose download hosts are not
 * reachable from the test environment). Only what the plugin uses is here; anything else is a fatal
 * "undefined function", which is the point — the plugin must not grow unnoticed WordPress dependencies.
 */

define('ABSPATH', __DIR__ . '/');

$GLOBALS['wp_hooks'] = [];
$GLOBALS['wp_options'] = [];
$GLOBALS['wc_orders'] = [];
$GLOBALS['as_queue'] = [];
$GLOBALS['wp_can'] = true;

function add_action($hook, $cb, $priority = 10, $args = 1) { $GLOBALS['wp_hooks'][$hook][] = $cb; return true; }
function add_filter($hook, $cb, $priority = 10, $args = 1) { $GLOBALS['wp_hooks'][$hook][] = $cb; return true; }
function do_action($hook, ...$args) { foreach ($GLOBALS['wp_hooks'][$hook] ?? [] as $cb) { $cb(...$args); } }
function apply_filters($hook, $value, ...$args) { foreach ($GLOBALS['wp_hooks'][$hook] ?? [] as $cb) { $value = $cb($value, ...$args); } return $value; }
function is_admin() { return false; }
function current_user_can($cap) { return $GLOBALS['wp_can']; }
function get_option($name, $default = false) { return $GLOBALS['wp_options'][$name] ?? $default; }
function update_option($name, $value, $autoload = null) { $GLOBALS['wp_options'][$name] = $value; return true; }
function home_url() { return 'https://shop.example'; }
function get_bloginfo($what) { return $what === 'name' ? 'فروشگاه نمونه &amp; شرکا' : ''; }
function wp_specialchars_decode($s, $q = ENT_NOQUOTES) { return htmlspecialchars_decode($s, $q); }
function wp_date($format, $ts) { return gmdate($format, $ts); }
function wc_get_order_statuses() {
    return ['wc-pending' => 'در انتظار پرداخت', 'wc-processing' => 'در حال انجام', 'wc-completed' => 'تکمیل شده', 'wc-cancelled' => 'لغو شده'];
}
function wc_get_order($id) { return $GLOBALS['wc_orders'][(int)$id] ?? false; }
function as_has_scheduled_action($hook, $args = null, $group = '') {
    foreach ($GLOBALS['as_queue'] as $job) { if ($job[0] === $hook && $job[1] === $args) return true; }
    return false;
}
function as_enqueue_async_action($hook, $args = [], $group = '') { $GLOBALS['as_queue'][] = [$hook, $args, $group]; return count($GLOBALS['as_queue']); }
/** Runs the queue like Action Scheduler: each job once; a thrown exception marks the job failed. */
function as_run_queue(): array {
    $failed = [];
    while ($job = array_shift($GLOBALS['as_queue'])) {
        try { do_action($job[0], ...$job[1]); } catch (Throwable $e) { $failed[] = [$job, $e]; }
    }
    return $failed;
}

// --- what the settings page uses (escaping mirrors WordPress closely enough for output checks) ---
$GLOBALS['wp_nonce_ok'] = true;
function esc_html($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function esc_attr($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function esc_textarea($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function esc_url($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function esc_url_raw($s) { return preg_match('#^https?://#i', (string)$s) ? (string)$s : ''; }
function admin_url($p = '') { return 'https://shop.example/wp-admin/' . $p; }
function plugin_basename($f) { return 'ellsms-woocommerce/' . basename($f); }
function add_submenu_page(...$a) { return 'hook'; }
function wp_nonce_field($action) { echo '<input type="hidden" name="_wpnonce" value="nonce-' . md5($action) . '">'; }
function check_admin_referer($action) { if (!$GLOBALS['wp_nonce_ok']) { throw new RuntimeException('nonce rejected'); } return 1; }
function wp_unslash($v) { return $v; }
function sanitize_key($k) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string)$k)); }
function sanitize_text_field($s) { return trim(strip_tags((string)$s)); }
function sanitize_textarea_field($s) { return trim(strip_tags((string)$s)); }
function checked($on) { if ($on) echo ' checked="checked"'; }
function wp_die($m) { throw new RuntimeException('wp_die: ' . $m); }

class WooCommerce {}

final class Stub_WC_Item { private $n; function __construct($n) { $this->n = $n; } function get_name() { return $this->n; } }
final class Stub_WC_Date { private $t; function __construct($t) { $this->t = $t; } function getTimestamp() { return $this->t; } function date($f) { return gmdate($f, $this->t); } }

final class Stub_WC_Order
{
    public $notes = [];
    public $meta = [];
    public $saves = 0;
    private $d;
    function __construct(array $d) { $this->d = $d + ['status' => 'pending', 'items' => []]; }
    function get_id() { return $this->d['id']; }
    function get_order_number() { return (string)$this->d['number']; }
    function get_status() { return $this->d['status']; }
    function set_status_raw($s) { $this->d['status'] = $s; }
    function get_billing_first_name() { return $this->d['first']; }
    function get_billing_last_name() { return $this->d['last']; }
    function get_billing_phone() { return $this->d['phone']; }
    function get_total() { return $this->d['total']; }
    function get_currency() { return $this->d['currency']; }
    function get_payment_method_title() { return $this->d['payment']; }
    function get_date_created() { return new Stub_WC_Date($this->d['created']); }
    function get_items() { return array_map(static fn($n) => new Stub_WC_Item($n), $this->d['items']); }
    function add_order_note($note) { $this->notes[] = $note; return count($this->notes); }
    function get_meta($key, $single = true) { return $this->meta[$key] ?? ''; }
    function update_meta_data($key, $value) { $this->meta[$key] = $value; }
    function save() { $this->saves++; return $this->d['id']; }
}
