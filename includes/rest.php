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
}
