<?php
// Run with: php tests/payment-errors-test.php
// What the buyer sees when no invoice can be created (the form shows a message instead of an empty
// page or "Thank you"), the 294-sat minimum, and the cached CoinGecko rates.
// WordPress/CF7 stubs and a fake API; no network.
if (PHP_SAPI !== 'cli') { exit; }

define('ABSPATH', __DIR__ . '/');
define('COINSNAP_SERVER_URL', 'https://coinsnap.test');
define('MINUTE_IN_SECONDS', 60);

$GLOBALS['t_meta'] = [];
$GLOBALS['t_requests'] = [];
$GLOBALS['t_options'] = [];
$GLOBALS['t_transients'] = [];
$GLOBALS['t_rates_status'] = 200;   // CoinGecko reply
$GLOBALS['t_invoice_status'] = 200; // create-invoice reply
$GLOBALS['t_posted_amount'] = '500';
$GLOBALS['t_done'] = false;
$GLOBALS['t_log'] = tempnam(sys_get_temp_dir(), 'cs-log');
ini_set('log_errors', '1');
ini_set('error_log', $GLOBALS['t_log']);

// The old code exits instead of returning: report that as a failure, not as a silent success.
register_shutdown_function(function () {
    if (!$GLOBALS['t_done']) { echo "FAIL the test stopped early (the plugin called exit without a redirect)\n"; exit(1); }
});

function plugin_dir_path($f) { return dirname($f) . '/'; }
function add_action(...$a) {}
function add_filter(...$a) {}
function register_activation_hook(...$a) {}
function register_deactivation_hook(...$a) {}
function is_admin() { return false; }
function is_plugin_active($p) { return true; }
if (!function_exists('str_contains')) { function str_contains($h, $n) { return $n === '' || strpos($h, $n) !== false; } }
function get_post_meta($id, $key, $single = false) { return $GLOBALS['t_meta'][$key] ?? ''; }
function get_bloginfo($k) { return 'Site'; }
function home_url() { return 'https://shop.test'; }
function get_option($k, $d = false) { return $k === 'admin_email' ? 'admin@shop.test' : ($GLOBALS['t_options'][$k] ?? $d); }
function update_option($k, $v, $autoload = null) { $GLOBALS['t_options'][$k] = $v; return true; }
function get_transient($k) { return $GLOBALS['t_transients'][$k] ?? false; }
function set_transient($k, $v, $ttl = 0) { $GLOBALS['t_transients'][$k] = $v; return true; }
function date_i18n($f) { return '2026-11-22'; }
function wp_unslash($v) { return $v; }
function sanitize_text_field($v) { return trim((string) $v); }
function wp_json_encode($v, $f = 0) { return json_encode($v, $f); }
function current_time($t, $gmt = false) { return '2026-11-22 10:00:00'; }
function determine_locale() { return 'en_US'; }
function esc_html($v) { return $v; }
function __($v, $d = null) { return $v; }
function esc_url($v) { return $v; }
function wp_parse_url($u, $c = -1) { return parse_url($u, $c); }
function is_wp_error($v) { return false; }
function wp_remote_retrieve_response_code($r) { return $r['response']['code']; }
function wp_remote_retrieve_body($r) { return $r['body']; }
class T_Headers { public function getAll() { return []; } }
function wp_remote_retrieve_headers($r) { return new T_Headers(); }
class T_Redirect extends Exception {}
function wp_redirect($url) { $GLOBALS['t_redirect'] = $url; throw new T_Redirect('redirect'); }
function wp_remote_request($url, $args) {
    $GLOBALS['t_requests'][] = ['url' => $url, 'method' => $args['method']];
    $json = function ($v, $code = 200) { return ['response' => ['code' => $code], 'body' => json_encode($v)]; };
    if (strpos($url, 'coingecko') !== false) {
        return $GLOBALS['t_rates_status'] === 200
            ? $json(['rates' => ['btc' => ['value' => 1], 'eur' => ['value' => 80000], 'usd' => ['value' => 90000]]])
            : $json(['error' => 'rate limited'], $GLOBALS['t_rates_status']);
    }
    if ($args['method'] === 'POST' && substr($url, -9) === '/invoices') {
        $code = $GLOBALS['t_invoice_status'];
        return $code === 200 ? $json(['id' => 'inv1', 'checkoutLink' => 'https://pay.test/i']) : $json(['message' => 'refused'], $code);
    }
    return $json([], 404);
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
    public $basetype; public $name; public $values = []; public $pipes = null;
    public function __construct($basetype, $name) { $this->basetype = $basetype; $this->name = $name; }
    public function has_option($o) { return false; }
    public function get_option($o, $p = '', $single = false) { return false; }
}
class WPCF7_ContactForm {
    public static $current;
    public static function get_current() { return self::$current; }
    public function id() { return 7; }
    public function title() { return 'Donation'; }
    public function get_properties() { return ['form' => '[email cs_email] [number cs_amount]']; }
    public function scan_form_tags($cond = null) {
        $tags = [new T_Tag('email', 'cs_email'), new T_Tag('number', 'cs_amount')];
        if (is_array($cond) && isset($cond['name'])) {
            return array_values(array_filter($tags, function ($t) use ($cond) { return $t->name === $cond['name']; }));
        }
        return $tags;
    }
}
class WPCF7_Submission {
    public static $instance;
    private $status = 'init';
    private $response = '';
    public static function get_instance() { return self::$instance; }
    public function get_posted_data() { return ['cs_email' => 'ana@example.com', 'cs_amount' => $GLOBALS['t_posted_amount']]; }
    public function get_meta($k) { return ''; }
    public function uploaded_files() { return []; }
    public function set_status($s) { $this->status = $s; return true; }
    public function get_status() { return $this->status; }
    public function set_response($r) { $this->response = $r; return true; }
    public function get_response() { return $this->response; }
}

require dirname(__DIR__) . '/coinsnap-for-contact-form-7.php';
require dirname(__DIR__) . '/library/loader.php';
require dirname(__DIR__) . '/coinsnapcf7-class.php';

$fail = 0;
$check = function ($ok, $label) use (&$fail) { echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n"; if (!$ok) { $fail++; } };
$geckoCalls = function () { return count(array_filter($GLOBALS['t_requests'], function ($r) { return strpos($r['url'], 'coingecko') !== false; })); };
$createCalls = function () { return count(array_filter($GLOBALS['t_requests'], function ($r) { return $r['method'] === 'POST'; })); };
$reset = function ($currency, $paymentFirst, $amount, $invoiceStatus = 200) {
    $GLOBALS['t_meta'] = [
        '_cf7_coinsnap_enable' => '1', '_cf7_coinsnap_currency' => $currency, '_cf7_coinsnap_provider' => 'coinsnap',
        '_cf7_coinsnap_paymentfirst' => $paymentFirst ? '1' : '', '_cf7_coinsnap_store_id' => 'store1', '_cf7_coinsnap_api_key' => 'key',
    ];
    $GLOBALS['t_posted_amount'] = $amount;
    $GLOBALS['t_invoice_status'] = $invoiceStatus;
    $GLOBALS['t_requests'] = [];
    $GLOBALS['t_redirect'] = '';
    WPCF7_Submission::$instance = new WPCF7_Submission();
};

// --- The 294-sat minimum (the amount check the form validation and the invoice step use) ---------
$plugin = CoinsnapCf7::get_instance();
$GLOBALS['t_meta'] = ['_cf7_coinsnap_provider' => 'coinsnap', '_cf7_coinsnap_store_id' => 'store1', '_cf7_coinsnap_api_key' => 'key'];
$v = function ($amount, $currency) use ($plugin) { return $plugin->coinsnapcf7_amount_validation($amount, $currency); };
$r = $v(100.0, 'SATS');
$check($r['result'] === false && $r['error'] === 'amountError' && (float) $r['min_value'] === 294.0, '100 sats is refused, minimum 294');
$check($v(293.0, 'SATS')['result'] === false, '293 sats is refused');
$check($v(294.0, 'SATS')['result'] === true, '294 sats is accepted');
$check($v(0.000002, 'BTC')['result'] === false && $v(0.000003, 'BTC')['result'] === true, 'BTC minimum is 0.00000294');
$r = $v(0.10, 'EUR');
$check($r['result'] === false && abs((float) $r['min_value'] - 0.24) < 0.0001, 'EUR minimum is 294 sats at the loaded rate (0.24 EUR at 80000 EUR/BTC)');
$check($v(0.24, 'EUR')['result'] === true, '0.24 EUR is accepted');
$check($v(5.0, 'XYZ')['error'] === 'currencyError', 'unsupported currency is refused');

// --- Rates are cached across requests; CoinGecko failures fall back to the last good rates ------
$check(isset($GLOBALS['t_transients']['coinsnapcf7_exchange_rates']), 'rates are kept in a transient for the next submissions');
$GLOBALS['t_requests'] = [];
$v(10.0, 'EUR');
$v(10.0, 'USD');
$check($geckoCalls() === 0, 'cached rates: no CoinGecko request on the next submissions');

unset($GLOBALS['t_transients']['coinsnapcf7_exchange_rates']); // fresh cache expired
$GLOBALS['t_rates_status'] = 429;
$GLOBALS['t_requests'] = [];
$r = $v(0.10, 'EUR');
$check($geckoCalls() === 1, '429: CoinGecko is asked once, without retries');
$check($r['result'] === false && $r['error'] === 'amountError', '429: the last good rates still give the EUR minimum');
$GLOBALS['t_requests'] = [];
$v(0.10, 'EUR');
$check($geckoCalls() === 0, '429: no new CoinGecko request during the back-off minute');

$GLOBALS['t_options'] = [];   // no last good rates either
$GLOBALS['t_transients'] = [];
$r = $v(0.10, 'EUR');
$check($r['result'] === true, 'no rates at all: a fiat amount is left to Coinsnap to accept or refuse');
$check($v(100.0, 'SATS')['result'] === false, 'no rates at all: SATS minimum still applies');
if (!class_exists('CoinsnapCf7_Rates')) {
    $check(false, 'BTCPay: the library gets the cached rates instead of asking CoinGecko');
} else {
    $GLOBALS['t_requests'] = [];
    CoinsnapCf7_Rates::share_with_library();
    $client = new \Coinsnap\Client\Invoice('https://btcpay.test', 'key');
    $r = $client->checkPaymentData(10.0, 'EUR', 'bitcoin');
    $check($r['result'] === false && $r['error'] === 'ratesListError' && $geckoCalls() === 0, 'BTCPay: the library uses the shared rates (here: none, so ratesListError) without asking CoinGecko again');
}

if (!method_exists($plugin, 'amount_error_message')) {
    $check(false, 'error keys (ratesListError, currenciesError, amountError) are turned into readable messages');
} else {
    $msg = $plugin->amount_error_message(['result' => false, 'error' => 'ratesListError'], 'EUR');
    $check($msg !== 'ratesListError' && strpos($msg, 'exchange rates') !== false, 'ratesListError is shown as a readable message');
    $msg = $plugin->amount_error_message(['result' => false, 'error' => 'currenciesError'], 'EUR');
    $check($msg !== 'currenciesError' && strpos($msg, 'currencies') !== false, 'currenciesError is shown as a readable message');
    $check($plugin->amount_error_message(['result' => false, 'error' => 'amountError', 'min_value' => 294.0], 'SATS') === 'Invoice amount cannot be less than 294 SATS', 'amount message for SATS');
    $check($plugin->amount_error_message(['result' => false, 'error' => 'amountError', 'min_value' => 0.00000294], 'BTC') === 'Invoice amount cannot be less than 0.00000294 BTC', 'amount message for BTC has no exponent');
}
$GLOBALS['t_rates_status'] = 200;
$GLOBALS['t_transients'] = [];

// --- Payment before mail: abort with a message instead of an empty page ------------------------
WPCF7_ContactForm::$current = $form = new WPCF7_ContactForm();
$run_before = function () use ($plugin, $form) {
    $abort = false;
    try {
        $plugin->coinsnap_payment_before($form, $abort, WPCF7_Submission::$instance);
    } catch (T_Redirect $e) {}
    return $abort;
};

$reset('SATS', true, '100');
$abort = $run_before();
$check($abort === true, 'before mail, 100 sats: mail aborted');
$check(strpos(WPCF7_Submission::$instance->get_response(), 'cannot be less than 294 SATS') !== false, 'before mail, 100 sats: the form says the minimum');
$check($createCalls() === 0, 'before mail, 100 sats: no invoice request');

$reset('SATS', true, '500', 400);
$abort = $run_before();
$check($abort === true && $GLOBALS['t_redirect'] === '', 'before mail, API refuses (400): mail aborted, no redirect');
$check(strpos(WPCF7_Submission::$instance->get_response(), 'refused') !== false, 'before mail, API refuses (400): the form says the payment was refused');

$reset('SATS', true, '500', 401);
$abort = $run_before();
$check($abort === true, 'before mail, wrong store/key (401): mail aborted');
$check(strpos(WPCF7_Submission::$instance->get_response(), 'not available') !== false, 'before mail, wrong store/key (401): the form says payment is not available');

$reset('SATS', true, '500');
$abort = $run_before();
$check($abort === false && $GLOBALS['t_redirect'] === 'https://pay.test/i', 'before mail, valid amount: redirect to the checkout');

// --- Payment after mail: no "Thank you" without a payment -------------------------------------
$reset('SATS', false, '500', 400);
WPCF7_Submission::$instance->set_status('mail_sent');
WPCF7_Submission::$instance->set_response('Thank you for your message. It has been sent.');
try { $plugin->coinsnap_payment_after($form); } catch (T_Redirect $e) {}
$check(WPCF7_Submission::$instance->get_status() === 'aborted', 'after mail, API refuses: status is no longer mail_sent');
$check(strpos(WPCF7_Submission::$instance->get_response(), 'Your message was sent, but the payment could not be started.') === 0, 'after mail, API refuses: the form says the payment could not be started');

$reset('SATS', false, '500');
try { $plugin->coinsnap_payment_after($form); } catch (T_Redirect $e) {}
$check($GLOBALS['t_redirect'] === 'https://pay.test/i', 'after mail, valid amount: redirect to the checkout');

// --- Log label --------------------------------------------------------------------------------
$log = (string) file_get_contents($GLOBALS['t_log']);
$check(strpos($log, 'payment reason=invoice create failed (400)') !== false, 'log: invoice failure is logged as a payment error with the status');
$check(strpos($log, 'webhook reason=invoice create failed') === false, 'log: not labelled as a webhook');
@unlink($GLOBALS['t_log']);

$GLOBALS['t_done'] = true;
exit($fail ? 1 : 0);
