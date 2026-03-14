<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Register plugin shortcodes.
 *
 * @return void
 */
function fic_register_shortcodes() {
    add_shortcode( 'progress-bar', 'fic_render_progress_bar_shortcode' );
    add_shortcode( 'floppy-converter-form', 'fic_render_converter_form_shortcode' );
    add_shortcode( 'floppy-image-converter', 'fic_render_converter_form_shortcode' );
}

/**
 * Enqueue frontend assets needed by converter UI.
 *
 * @return void
 */
function fic_enqueue_frontend_assets() {
    wp_enqueue_style(
        'fic-converter-form',
        FIC_PLUGIN_URL . 'assets/floppy-converter.css',
        [],
        FIC_PLUGIN_VERSION
    );
}

/**
 * Render reusable progress bar element.
 *
 * @return string
 */
function fic_render_progress_bar_shortcode() {
    return '<progress id="status-bar" class="fic-status-bar" max="100" value="0"></progress>';
}

/**
 * Render converter form UI from template.
 *
 * @param array $atts Shortcode attributes.
 *
 * @return string
 */
function fic_render_converter_form_shortcode( $atts = [] ) {
    fic_enqueue_frontend_assets();

    $atts = shortcode_atts(
        [
            'show_advanced' => '1',
        ],
        $atts,
        'floppy-converter-form'
    );

    $template_path = FIC_PLUGIN_DIR . 'templates/converter-form.php';
    if ( ! file_exists( $template_path ) ) {
        return '';
    }

    $basic_diskdefs    = fic_basic_diskdef_options();
    $basic_outputs     = fic_basic_output_options();
    $advanced_diskdefs = fic_allowed_diskdefs();
    $advanced_outputs  = fic_advanced_output_options();
    $default_diskdef   = fic_default_diskdef();
    $default_output    = fic_default_output_format();
    $show_advanced     = '0' !== (string) $atts['show_advanced'];

    ob_start();
    include $template_path;

    return ob_get_clean();
}
