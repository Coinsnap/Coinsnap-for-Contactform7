<?php
/**
 * Plugin Name:     Coinsnap for Contact Form 7-plugin
 * Plugin URI:      https://www.coinsnap.io
 * Description:     Provides a <a href="https://coinsnap.io">Coinsnap</a>  - Bitcoin + Lightning Payment Gateway for Contact Form 7.
 * Version:         1.0.2
 * Author:          Coinsnap
 * Author URI:      https://coinsnap.io/
 * Text Domain:     coinsnap-for-contactform7
 * Domain Path:     /languages
 * Requires PHP:    7.4
 * Tested up to:    6.7.1
 * Requires at least: 6.2
 * CF7 tested up to: 6.0.2
 * License:         GPL2
 * License URI:     https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Network:         true
 */

defined( 'ABSPATH' ) || exit;
if(!defined( 'COINSNAPCF7_REFERRAL_CODE' )){define( 'COINSNAPCF7_REFERRAL_CODE', 'D19827' );}
if(!defined( 'COINSNAPCF7_VERSION' )){define( 'COINSNAPCF7_VERSION', '1.0.2' );}
add_action( 'init', array( 'cf7_coinsnap', 'load' ), 5 );
if(!defined( 'WPCF7_LOAD_JS' )){define( 'WPCF7_LOAD_JS', false );}
register_activation_hook( __FILE__, "coinsnapcf7_activate" );
register_deactivation_hook( __FILE__, "coinsnapcf7_deactivate" );

class cf7_coinsnap {
    public static function load() {
        require_once( plugin_dir_path( __FILE__ ) . 'library/loader.php' );
        require_once( 'coinsnapcf7-class.php' );
	CoinsnapCf7::get_instance();
    }
}

//  Transaction table creation
function coinsnapcf7_activate() {
    global $wpdb;
    $table_name = $wpdb->prefix . "coinsnapcf7_extension";
        if ( $wpdb->query($wpdb->prepare( "SHOW TABLES LIKE %s", $table_name )) != $table_name ) {
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

function coinsnapcf7_deactivate() {
	global $wpdb;
	$table_name = $wpdb->prefix . "coinsnapcf7_extension";
	$wpdb->query( $wpdb->prepare( "DROP TABLE IF EXISTS %i", $table_name ) );
	delete_option( 'coinsnapcf7_check_show_warning' );
}

if (!function_exists('is_plugin_active')) {
	include_once(ABSPATH . 'wp-admin/includes/plugin.php');
}

function coinsnapcf7_check_contact_form_7_dependency() {
	if (!is_plugin_active('contact-form-7/wp-contact-form-7.php')) {
		add_action('admin_notices', 'coinsnapcf7_dependency_notice');
		// Optionally, deactivate your plugin
		deactivate_plugins(plugin_basename(__FILE__));
	}
}
add_action('admin_init', 'coinsnapcf7_check_contact_form_7_dependency');

function coinsnapcf7_dependency_notice() {?>
  <div class="notice notice-error">
    <p><?php esc_html_e('Coinsnap for Contact Form 7 plugin requires Contact Form 7 to be installed and activated.', 'coinsnap-for-contactform7'); ?></p>
  </div><?php
}

// Add custom styling.
function coinsnapcf7_enqueue_admin_styles( $hook ) {
	// Register the CSS file
	wp_register_style( 'coinsnapcf7_admin-styles', plugins_url( 'css/coinsnapcf7-styles.css', __FILE__ ), array(), COINSNAPCF7_VERSION );

	// Enqueue the CSS file
	wp_enqueue_style( 'coinsnapcf7_admin-styles' );
}

add_action( 'admin_enqueue_scripts', 'coinsnapcf7_enqueue_admin_styles' );

// Hook into the 'admin_notices' action
add_action( 'admin_notices', 'coinsnapcf7_check_criteria_and_show_warning', 10, 1 );

/**
 * Function to check criteria and show admin warning.
 */
function coinsnapcf7_check_criteria_and_show_warning() {

	if ( get_option( 'coinsnapcf7_check_show_warning' ) ) {
		echo '<div class="notice notice-error is-dismissible">';
		echo '<p><strong>Warning:</strong> You must include `cs_amount` field in the Coinsnap form in order to successfully connect to your Coinsnap account.</p>';
		echo '</div>';
	}
}





// Hook into the 'wpcf7_save_contact_form' action
add_action( 'wpcf7_save_contact_form', 'coinsnapcf7_check_field_existence', 10, 1 );

/**
 * Function to check if a specific field exists in the form.
 *
 * @param WPCF7_ContactForm $contact_form The Contact Form 7 form object.
 */
function coinsnapcf7_check_field_existence( $contact_form ) {
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
		update_option( 'coinsnapcf7_check_show_warning', false );
	} else {
		update_option( 'coinsnapcf7_check_show_warning', true );
	}
}
