<?php
/**
 * Plugin Name: Floppy Image Converter
 * Plugin URI:  https://alexanderpeppe.com/
 * Description: Async REST API to convert floppy images via Greaseweazle, poll status, extract & zip, and report errors.
 * Version:     1.3.0
 * Author:      Alexander Peppe
 * Author URI:  https://alexanderpeppe.com/
 * License:     Public Domain
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'FIC_PLUGIN_VERSION' ) ) {
    define( 'FIC_PLUGIN_VERSION', '1.3.0' );
}
if ( ! defined( 'FIC_PLUGIN_FILE' ) ) {
    define( 'FIC_PLUGIN_FILE', __FILE__ );
}
if ( ! defined( 'FIC_PLUGIN_DIR' ) ) {
    define( 'FIC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'FIC_PLUGIN_URL' ) ) {
    define( 'FIC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'FIC_UPLOAD_SUBDIR' ) ) {
    define( 'FIC_UPLOAD_SUBDIR', 'floppy-convert' );
}
if ( ! defined( 'FIC_REST_NAMESPACE' ) ) {
    define( 'FIC_REST_NAMESPACE', 'floppy/v1' );
}
if ( ! defined( 'FIC_TOTAL_STEPS' ) ) {
    define( 'FIC_TOTAL_STEPS', 6 );
}

require_once FIC_PLUGIN_DIR . 'includes/config.php';
require_once FIC_PLUGIN_DIR . 'includes/helpers.php';
require_once FIC_PLUGIN_DIR . 'includes/conversion.php';
require_once FIC_PLUGIN_DIR . 'includes/status.php';
require_once FIC_PLUGIN_DIR . 'includes/frontend.php';
require_once FIC_PLUGIN_DIR . 'includes/rest.php';
require_once FIC_PLUGIN_DIR . 'includes/cleanup.php';

add_action( 'init', 'fic_register_shortcodes' );
add_action( 'rest_api_init', 'fic_register_rest_routes' );
add_action( 'fic_cleanup_hook', 'fic_cleanup_old_files' );

register_activation_hook( __FILE__, 'fic_schedule_cleanup' );
register_deactivation_hook( __FILE__, 'fic_clear_cleanup' );
