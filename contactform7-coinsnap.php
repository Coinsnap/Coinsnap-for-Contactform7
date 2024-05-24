<?php
/*
 * Plugin Name:     Coinsnap for Contact Form 7
 * Plugin URI:      https://www.coinsnap.io
 * Description:     Provides a <a href="https://coinsnap.io">Coinsnap</a>  - Bitcoin + Lightning Payment Gateway for <a href="https://wordpress.org/plugins/contact-form-7/">Contact Form 7</a>.
 * Version:         1.0.0
 * Author:          Coinsnap
 * Author URI:      https://coinsnap.io/
 * Text Domain:     coinsnap-for-contactform7
 * Domain Path:     /languages
 * Requires PHP:    7.4
 * Tested up to:    6.4.3
 * Requires at least: 5.2
 * License:         GPL2
 * License URI:     https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Network:         true
 */

defined('ABSPATH') || exit;
define( 'COINSNAP_REFERRAL_CODE', 'D19827' );

add_action('init', array('cf7_coinsnap', 'load'), 5);
register_activation_hook(__FILE__, "cf7_coinsnap_activate");
register_deactivation_hook(__FILE__, "cf7_coinsnap_deactivate");
define('WPCF7_LOAD_JS', false);

class cf7_coinsnap
{
    public static function load()
    {
        require_once (plugin_dir_path(__FILE__) . '/library/autoload.php');
        require_once('class-cf7-coinsnap.php');
        Cf7Coinsnap::get_instance();
    }
}

function cf7_coinsnap_activate()
{

    	global $wpdb;
		$table_name = $wpdb->prefix . "cf7_coinsnap_extension";
		if($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
    		$sql = "CREATE TABLE $table_name (
    				`id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
			      	`form_id` INT(11) NOT NULL,			      	
			      	`field_values` TEXT NOT NULL,
			      	`payment_details` TEXT NOT NULL,
			      	`submit_time` INT(11) NOT NULL,
				    `name` varchar(150)  NULL,
					`email` varchar(200)  NULL,
					`amount` decimal(12,2) NOT NULL DEFAULT 0,
			      	`status` varchar(20) NOT NULL DEFAULT 'New',
			      	PRIMARY KEY (`id`)
				) DEFAULT COLLATE=utf8_general_ci";

			require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
			dbDelta($sql);
		}

}

function cf7_coinsnap_deactivate()
{
    	global $wpdb;
		$table_name = $wpdb->prefix . "cf7_coinsnap_extension";
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $table_name );

}

// Add custom styling.
function my_plugin_enqueue_admin_styles($hook)
{
	// Register the CSS file
	wp_register_style('cf7_coinsnap-admin-styles', plugins_url('css/cf7_coinsnap-styles.css', __FILE__));

	// Enqueue the CSS file
	wp_enqueue_style('cf7_coinsnap-admin-styles');
}

add_action('admin_enqueue_scripts', 'my_plugin_enqueue_admin_styles');
