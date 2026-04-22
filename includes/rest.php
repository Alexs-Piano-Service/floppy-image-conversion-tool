<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Register plugin REST API routes.
 *
 * @return void
 */
function fic_register_rest_routes() {
    register_rest_route(
        FIC_REST_NAMESPACE,
        '/convert',
        [
            'methods'             => 'POST',
            'callback'            => 'fic_handle_convert',
            'permission_callback' => '__return_true',
            'args'                => [
                'out_fmt' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'diskdef' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]
    );

    register_rest_route(
        FIC_REST_NAMESPACE,
        '/status',
        [
            'methods'             => 'GET',
            'callback'            => 'fic_get_status',
            'permission_callback' => '__return_true',
            'args'                => [
                'job_id' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]
    );

    register_rest_route(
        FIC_REST_NAMESPACE,
        '/download',
        [
            'methods'             => 'GET',
            'callback'            => 'fic_handle_download',
            'permission_callback' => '__return_true',
            'args'                => [
                'job_id' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'out_fmt' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'filename' => [
                    'required'          => false,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]
    );
}

/**
 * Stream a finished conversion artifact with a user-friendly download filename.
 *
 * @param WP_REST_Request $request REST request.
 *
 * @return WP_Error|void
 */
function fic_handle_download( WP_REST_Request $request ) {
    $job_id  = sanitize_text_field( (string) $request->get_param( 'job_id' ) );
    $out_fmt = strtolower( sanitize_text_field( (string) $request->get_param( 'out_fmt' ) ) );

    if ( ! preg_match( '/^[a-f0-9-]{36}$/i', $job_id ) ) {
        return new WP_Error( 'invalid_job_id', 'Invalid job_id.', [ 'status' => 400 ] );
    }

    if ( ! in_array( $out_fmt, fic_allowed_output_formats(), true ) ) {
        return new WP_Error( 'invalid_output_format', 'Output format not allowed.', [ 'status' => 400 ] );
    }

    $job_data = get_transient( "fic_job_{$job_id}" );
    $job_data = is_array( $job_data ) ? $job_data : [];
    $file_path = fic_find_output_file_path( $job_id, $out_fmt, $job_data );

    if ( '' === $file_path ) {
        return new WP_Error( 'download_not_found', 'Requested conversion file was not found.', [ 'status' => 404 ] );
    }

    $download_name = isset( $job_data['download_name'] ) ? (string) $job_data['download_name'] : '';
    if ( '' === $download_name ) {
        $download_name = (string) $request->get_param( 'filename' );
    }

    fic_stream_download_file( $file_path, fic_normalize_download_filename( $download_name, $out_fmt ) );
}
