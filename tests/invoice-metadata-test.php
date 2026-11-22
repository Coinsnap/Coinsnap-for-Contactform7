<?php
// Run with: php tests/invoice-metadata-test.php
// Submits a form through coinsnap_redirect_payment() with WordPress/CF7 stubs and a fake API, for
// both providers, and checks the create-invoice request: orderId = entry ID, and the metadata.
if (PHP_SAPI !== 'cli') { exit; }

define('ABSPATH', __DIR__ . '/');
define('COINSNAP_SERVER_URL', 'https://coinsnap.test');

$GLOBALS['t_meta'] = [];
$GLOBALS['t_requests'] = [];
function plugin_dir_path($f) { return dirname($f) . '/'; }
function add_action(...$a) {}
function add_filter(...$a) {}
function register_activation_hook(...$a) {}
function register_deactivation_hook(...$a) {}
function is_admin() { return false; }
function is_plugin_active($p) { return true; }
if (!function_exists('str_contains')) { function str_contains($h, $n) { return $n === '' || strpos($h, $n) !== false; } } // WordPress polyfill on PHP 7.4
function get_post_meta($id, $key, $single = false) { return $GLOBALS['t_meta'][$key] ?? ''; }
function get_bloginfo($k) { return 'Site'; }
function home_url() { return 'https://shop.test'; }
function get_option($k, $d = false) { return $k === 'admin_email' ? 'admin@shop.test' : $d; }
function update_option(...$a) { return true; }
function date_i18n($f) { return '2026-11-19'; }
function wp_unslash($v) { return $v; }
function sanitize_text_field($v) { return trim((string) $v); }
function wp_json_encode($v, $f = 0) { return json_encode($v, $f); }
function current_time($t, $gmt = false) { return '2026-11-19 10:00:00'; }
function determine_locale() { return 'de_DE'; }
function esc_html($v) { return $v; }
function __($v, $d = null) { return $v; }
function esc_url($v) { return $v; }
function is_wp_error($v) { return false; }
function wp_remote_retrieve_response_code($r) { return $r['response']['code']; }
function wp_remote_retrieve_body($r) { return $r['body']; }
function get_transient($k) { return false; }
function set_transient(...$a) { return true; }
class T_Headers { public function getAll() { return []; } }
function wp_remote_retrieve_headers($r) { return new T_Headers(); }
// The plugin exits after the redirect: stop there.
function wp_redirect($url) { $GLOBALS['t_redirect'] = $url; throw new RuntimeException('redirect'); }
function wp_remote_request($url, $args) {
    $GLOBALS['t_requests'][] = ['url' => $url, 'method' => $args['method'], 'body' => $args['body'] ?? ''];
    $json = function ($v) { return ['response' => ['code' => 200], 'body' => json_encode($v)]; };
    if (strpos($url, 'coingecko') !== false) { return $json(['rates' => ['eur' => ['value' => 80000], 'usd' => ['value' => 90000]]]); }
    if (substr($url, -16) === '/payment-methods') { return $json([['paymentMethodId' => 'BTC-LN', 'enabled' => true]]); }
    if (strpos($url, '/rates?') !== false) { return $json([['currencyPair' => 'BTC_EUR', 'rate' => '80000', 'errors' => []]]); }
    if ($args['method'] === 'POST' && substr($url, -9) === '/invoices') { return $json(['id' => 'inv' . count($GLOBALS['t_requests']), 'checkoutLink' => 'https://pay.test/i']); }
    return ['response' => ['code' => 404], 'body' => '{}'];
}

class T_Wpdb {
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $last_error = '';
    public function insert($t, $data, $f = null) { $this->insert_id = 42; return 1; }
    public function update(...$a) { return 1; }
    public function prepare($q, ...$args) { return $q; }
    public function query($q) { return 1; }
}
$GLOBALS['wpdb'] = new T_Wpdb();

class WPCF7_Pipes { public function zero() { return true; } }
class T_Tag {
    public $basetype; public $name; public $values = []; public $pipes = null; private $options;
    public function __construct($basetype, $name, $options = []) { $this->basetype = $basetype; $this->name = $name; $this->options = $options; }
    public function has_option($o) { return in_array($o, $this->options, true); }
    public function get_option($o, $p = '', $single = false) { return false; }
}
class WPCF7_ContactForm {
    public static $current;
    public static function get_current() { return self::$current; }
    public function id() { return 7; }
    public function title() { return 'Workshop booking'; }
    public function get_properties() { return ['form' => '[text* cs_name] [email* cs_email] [number cs_amount]']; }
    public function scan_form_tags($cond = null) {
        $tags = [
            new T_Tag('text', 'cs_name'), new T_Tag('email', 'cs_email'), new T_Tag('number', 'cs_amount'),
            new T_Tag('tel', 'your-phone'), new T_Tag('textarea', 'your-message'), new T_Tag('select', 'workshop'),
            new T_Tag('checkbox', 'extras'), new T_Tag('text', 'card'), new T_Tag('hidden', 'ref'),
            new T_Tag('file', 'cv'), new T_Tag('quiz', 'quiz-1'), new T_Tag('text', 'my_token'), new T_Tag('text', 'empty-one'),
        ];
        if (is_array($cond) && isset($cond['name'])) {
            return array_values(array_filter($tags, function ($t) use ($cond) { return $t->name === $cond['name']; }));
        }
        return $tags;
    }
}
class WPCF7_Submission {
    public static $instance;
    public static function get_instance() { return self::$instance; }
    public function get_posted_data() {
        return [
            'cs_name' => 'Ana Buyer', 'cs_email' => 'ana@example.com', 'cs_amount' => '25',
            'your-phone' => '+381 11 123', 'your-message' => "Please\nbring coffee", 'workshop' => ['Morning'],
            'extras' => ['Lunch', 'Parking'], 'card' => '4111 1111 1111 1111', 'ref' => 'abc', 'cv' => 'cv.pdf',
            'quiz-1' => '4', 'my_token' => 'secret', 'empty-one' => '', '_wpcf7_nonce' => 'n',
        ];
    }
    public function get_meta($k) { return ['url' => 'https://shop.test/book/', 'container_post_id' => 12][$k] ?? ''; }
    public function uploaded_files() { return []; }
}

require dirname(__DIR__) . '/coinsnap-for-contact-form-7.php';
require dirname(__DIR__) . '/library/loader.php';
require dirname(__DIR__) . '/coinsnapcf7-class.php';

$fail = 0;
$check = function ($ok, $label) use (&$fail) { echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n"; if (!$ok) { $fail++; } };

WPCF7_ContactForm::$current = new WPCF7_ContactForm();
WPCF7_Submission::$instance = new WPCF7_Submission();
$plugin = CoinsnapCf7::get_instance();

foreach (['coinsnap', 'btcpay'] as $provider) {
    $GLOBALS['t_requests'] = [];
    $GLOBALS['t_redirect'] = '';
    $GLOBALS['t_meta'] = [
        '_cf7_coinsnap_enable' => '1', '_cf7_coinsnap_currency' => 'EUR', '_cf7_coinsnap_provider' => $provider,
        '_cf7_coinsnap_store_id' => 'store1', '_cf7_coinsnap_api_key' => 'key',
        '_cf7_btcpay_store_id' => 'store1', '_cf7_btcpay_api_key' => 'key', '_cf7_btcpay_server_url' => 'https://btcpay.test',
    ];
    try { $plugin->coinsnap_redirect_payment(WPCF7_ContactForm::$current); } catch (RuntimeException $e) {}
    $create = array_values(array_filter($GLOBALS['t_requests'], function ($r) { return $r['method'] === 'POST' && substr($r['url'], -9) === '/invoices'; }));
    $check(count($create) === 1, "$provider: one create-invoice request");
    $check($GLOBALS['t_redirect'] === 'https://pay.test/i', "$provider: invoice recorded, buyer sent to the checkout");
    if (!$create) { continue; }
    $body = json_decode($create[0]['body'], true);
    $m = $body['metadata'] ?? [];
    $check(($body['orderId'] ?? null) === '42', "$provider: top-level orderId is the entry ID");
    $check(($body['buyerEmail'] ?? null) === 'ana@example.com', "$provider: top-level buyerEmail");
    $expected = [
        'orderId' => '42', 'orderNumber' => '42', 'entryId' => '42', 'formId' => '7', 'formTitle' => 'Workshop booking',
        'itemDesc' => 'Workshop booking', 'platform' => 'contact-form-7', 'pluginVersion' => COINSNAPCF7_VERSION,
        'pageUrl' => 'https://shop.test/book/', 'postId' => '12', 'language' => 'de_DE',
        'customerName' => 'Ana Buyer', 'buyerName' => 'Ana Buyer', 'buyerEmail' => 'ana@example.com', 'customerEmail' => 'ana@example.com',
        'buyerPhone' => '+381 11 123', 'buyerMessage' => "Please\nbring coffee",
    ];
    foreach ($expected as $k => $v) {
        $check(($m[$k] ?? null) === $v, "$provider: metadata.$k");
    }
    $check(($m['formFields'] ?? null) === ['workshop' => 'Morning', 'extras' => 'Lunch, Parking'], "$provider: formFields has only the other filled-in fields");
    $leaked = array_intersect(['4111 1111 1111 1111', 'abc', 'cv.pdf', 'secret', 'n', '4'], array_map('strval', array_merge(array_values(array_filter($m, 'is_scalar')), array_values($m['formFields'] ?? []))));
    $check(!$leaked, "$provider: no card number, hidden, file, quiz, token or nonce values");
}
exit($fail ? 1 : 0);
