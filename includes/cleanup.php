<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Schedule daily cleanup event.
 *
 * @return void
 */
function fic_schedule_cleanup() {
    if ( ! wp_next_scheduled( 'fic_cleanup_hook' ) ) {
        wp_schedule_event( time(), 'daily', 'fic_cleanup_hook' );
    }
}

/**
 * Remove scheduled cleanup hook.
 *
 * @return void
 */
function fic_clear_cleanup() {
    wp_clear_scheduled_hook( 'fic_cleanup_hook' );
}

/**
 * Delete stale conversion artifacts older than one week.
 *
 * @return void
 */
function fic_cleanup_old_files() {
    $base_dir = fic_get_upload_base_dir();
    if ( ! is_dir( $base_dir ) ) {
        return;
    }

    $now   = time();
    $files = glob( $base_dir . '/*' );

    if ( ! is_array( $files ) ) {
        return;
    }

    foreach ( $files as $file ) {
        if ( is_file( $file ) && ( $now - filemtime( $file ) ) > WEEK_IN_SECONDS ) {
            @unlink( $file );
            continue;
        }

        if ( is_dir( $file ) && ( $now - filemtime( $file ) ) > WEEK_IN_SECONDS ) {
            fic_delete_directory_recursively( $file );
        }
    }
}

/**
 * Recursively remove a directory tree.
 *
 * @param string $directory Absolute directory path.
 *
 * @return void
 */
function fic_delete_directory_recursively( $directory ) {
    $entries = glob( trailingslashit( $directory ) . '*' );
    if ( is_array( $entries ) ) {
        foreach ( $entries as $entry ) {
            if ( is_dir( $entry ) ) {
                fic_delete_directory_recursively( $entry );
            } elseif ( is_file( $entry ) ) {
                @unlink( $entry );
            }
        }
    }

    @rmdir( $directory );
}
