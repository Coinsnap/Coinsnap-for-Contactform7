<?php
// Removes only this plugin's own data when it is deleted: its tables, options and the per-form
// payment settings (API keys, webhook secret). Options of other Coinsnap plugins are left alone.
defined('WP_UNINSTALL_PLUGIN') || exit;

global $wpdb;

$coinsnapcf7_tables = [$wpdb->prefix . 'coinsnapcf7_extension', $wpdb->prefix . 'coinsnapcf7_invoices'];
foreach ($coinsnapcf7_tables as $coinsnapcf7_table) {
    $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $coinsnapcf7_table)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

$coinsnapcf7_meta_keys = [
    '_cf7_coinsnap_enable', '_cf7_coinsnap_autoredirect', '_cf7_coinsnap_paymentfirst', '_cf7_coinsnap_currency',
    '_cf7_coinsnap_provider', '_cf7_coinsnap_store_id', '_cf7_coinsnap_api_key', '_cf7_coinsnap_s_url',
    '_cf7_btcpay_server_url', '_cf7_btcpay_store_id', '_cf7_btcpay_api_key', '_cf7_coinsnap_webhook',
];
foreach ($coinsnapcf7_meta_keys as $coinsnapcf7_meta_key) {
    delete_post_meta_by_key($coinsnapcf7_meta_key);
}

delete_option('coinsnapcf7_db_version');
delete_option('coinsnapcf7_security_upgraded_at');
delete_option('coinsnapcf7_check_show_warning');
