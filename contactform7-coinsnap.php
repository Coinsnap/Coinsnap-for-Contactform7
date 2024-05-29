<?php
/*
 * Plugin Name:     Coinsnap for Contact Form 7
 * Plugin URI:      https://www.coinsnap.io
 * Description:     Provides a <a href="https://coinsnap.io">Coinsnap</a>  - Bitcoin + Lightning Payment Gateway for <a href="https://wordpress.org/plugins/contact-form-7/">Contact Form 7</a>.
 * Version:         1.0.1
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

defined( 'ABSPATH' ) || exit;
define( 'COINSNAP_REFERRAL_CODE', 'D19827' );

if (!function_exists('is_plugin_active')) {
	include_once(ABSPATH . 'wp-admin/includes/plugin.php');
}

function check_contact_form_7_dependency() {
	if (!is_plugin_active('contact-form-7/wp-contact-form-7.php')) {
		add_action('admin_notices', 'cf7_dependency_notice');
		// Optionally, deactivate your plugin
		deactivate_plugins(plugin_basename(__FILE__));
	}
}
add_action('admin_init', 'check_contact_form_7_dependency');

function cf7_dependency_notice() {
	?>
	<div class="notice notice-error">
		<p><?php _e('Your plugin requires Contact Form 7 to be installed and activated.', 'your-plugin-textdomain'); ?></p>
	</div>
	<?php
}

add_action( 'init', array( 'cf7_coinsnap', 'load' ), 5 );
register_activation_hook( __FILE__, "cf7_coinsnap_activate" );
register_deactivation_hook( __FILE__, "cf7_coinsnap_deactivate" );
define( 'WPCF7_LOAD_JS', false );

class cf7_coinsnap {
	public static function load() {
		require_once( plugin_dir_path( __FILE__ ) . '/library/autoload.php' );
		require_once( 'class-cf7-coinsnap.php' );
		Cf7Coinsnap::get_instance();
	}
}

function cf7_coinsnap_activate() {

	global $wpdb;
	$table_name = $wpdb->prefix . "cf7_coinsnap_extension";
	if ( $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" ) != $table_name ) {
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

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		dbDelta( $sql );
	}

}

function cf7_coinsnap_deactivate() {
	global $wpdb;
	$table_name = $wpdb->prefix . "cf7_coinsnap_extension";
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $table_name );

}

// Add custom styling.
function cf7_coinsnap_enqueue_admin_styles( $hook ) {
	// Register the CSS file
	wp_register_style( 'cf7_coinsnap-admin-styles', plugins_url( 'css/cf7_coinsnap-styles.css', __FILE__ ) );

	// Enqueue the CSS file
	wp_enqueue_style( 'cf7_coinsnap-admin-styles' );
}

add_action( 'admin_enqueue_scripts', 'cf7_coinsnap_enqueue_admin_styles' );


// Hook into the 'admin_notices' action
add_action( 'admin_notices', 'cf7_coinsnap_check_criteria_and_show_warning', 10, 1 );

/**
 * Function to check criteria and show admin warning.
 */
function cf7_coinsnap_check_criteria_and_show_warning() {

	if ( get_option( 'cf7_coinsnap_check_show_warning' ) ) {
		echo '<div class="notice notice-error is-dismissible">';
		echo '<p><strong>Warning:</strong> You must include `cs_amount` field in the Coinsnap form in order to successfully connect to your Coinsnap account.</p>';
		echo '</div>';
	}
}

// Hook into the 'wpcf7_save_contact_form' action
add_action( 'wpcf7_save_contact_form', 'cf7_coinsnap_check_field_existence', 10, 1 );

/**
 * Function to check if a specific field exists in the form.
 *
 * @param WPCF7_ContactForm $contact_form The Contact Form 7 form object.
 */
function cf7_coinsnap_check_field_existence( $contact_form ) {
	// Get the form ID
	$form_id = $contact_form->id();

	// Get the form properties
	$form_properties = $contact_form->get_properties();

	// Get the form content
	$form_content = $form_properties['form'];

	// Define the field you want to check
	$field_to_check = 'cs_amount';

	// Check if the field exists in the form content
	if ( str_contains( $form_content, $field_to_check ) ) {
		update_option( 'cf7_coinsnap_check_show_warning', false );
	} else {
		update_option( 'cf7_coinsnap_check_show_warning', true );
	}
}
