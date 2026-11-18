<?php
// Run with: php tests/deactivation-test.php
// Deactivating the plugin must not delete payment records (entries and invoice records).
if (PHP_SAPI !== 'cli') { exit; }

define('ABSPATH', __DIR__ . '/');
$GLOBALS['coinsnapcf7_test_deleted_options'] = [];
function plugin_dir_path($f) { return dirname($f) . '/'; }
function add_action(...$a) {}
function add_filter(...$a) {}
function register_activation_hook(...$a) {}
function register_deactivation_hook(...$a) {}
function is_plugin_active($p) { return true; }
function delete_option($o) { $GLOBALS['coinsnapcf7_test_deleted_options'][] = $o; return true; }

class CoinsnapCf7TestWpdb {
    public $prefix = 'wp_';
    public $queries = [];
    public function prepare($q, ...$args) { return vsprintf(str_replace('%i', '`%s`', $q), $args); }
    public function query($q) { $this->queries[] = $q; return true; }
}
$GLOBALS['wpdb'] = new CoinsnapCf7TestWpdb();

require dirname(__DIR__) . '/coinsnap-for-contact-form-7.php';

coinsnapcf7_deactivate();

$fail = 0;
$drops = preg_grep('/DROP|DELETE|TRUNCATE/i', $GLOBALS['wpdb']->queries);
if ($drops) { echo "FAIL deactivation ran: " . implode('; ', $drops) . "\n"; $fail++; } else { echo "PASS deactivation keeps the entries and invoice tables\n"; }
$gone = array_intersect(['coinsnapcf7_db_version', 'coinsnapcf7_security_upgraded_at'], $GLOBALS['coinsnapcf7_test_deleted_options']);
if ($gone) { echo "FAIL deactivation deleted " . implode(', ', $gone) . "\n"; $fail++; } else { echo "PASS deactivation keeps the schema version and upgrade time\n"; }
exit($fail ? 1 : 0);
