<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Absolute directory where conversion artifacts are stored.
 *
 * @return string
 */
function fic_get_upload_base_dir() {
    $upload_dir = wp_upload_dir();

    return trailingslashit( $upload_dir['basedir'] ) . trim( FIC_UPLOAD_SUBDIR, '/' );
}

/**
 * Map a file path under uploads/ to a public URL.
 *
 * @param string $file_path Absolute file path.
 *
 * @return string
 */
function fic_get_download_url( $file_path ) {
    $upload_dir = wp_upload_dir();
    $base_dir   = trailingslashit( $upload_dir['basedir'] );
    $base_url   = trailingslashit( $upload_dir['baseurl'] );

    if ( 0 === strpos( $file_path, $base_dir ) ) {
        return $base_url . ltrim( substr( $file_path, strlen( $base_dir ) ), '/' );
    }

    return '';
}

/**
 * Greaseweazle executable location, filterable for deployment-specific paths.
 *
 * @return string
 */
function fic_get_greaseweazle_cli_path() {
    return apply_filters( 'fic_greaseweazle_cli_path', '/home/peppe/greaseweazle/.venv/bin/gw' );
}

/**
 * ClamAV scanner location, filterable for deployment-specific paths.
 *
 * @return string
 */
function fic_get_clamdscan_path() {
    return apply_filters( 'fic_clamdscan_path', '/usr/bin/clamdscan' );
}

/**
 * Convert historical alias extensions into canonical input format names.
 *
 * @param string $extension Uploaded extension.
 *
 * @return string
 */
function fic_normalize_input_extension( $extension ) {
    return ( 'bin' === $extension ) ? 'img' : $extension;
}

/**
 * Build shell command with a stable step marker in the log.
 *
 * @param int    $step_no  1-based step index.
 * @param string $log_file Log file path.
 * @param string $command  Shell command to execute.
 *
 * @return string
 */
function fic_build_logged_step_command( $step_no, $log_file, $command ) {
    return sprintf(
        'echo STEP %1$d/%2$d >> %3$s && %4$s',
        (int) $step_no,
        (int) FIC_TOTAL_STEPS,
        escapeshellarg( $log_file ),
        $command
    );
}

/**
 * Spawn a shell pipeline in the background and return the PID when possible.
 *
 * @param string[] $steps Array of shell commands chained with &&.
 *
 * @return int
 */
function fic_spawn_background_job( array $steps ) {
    if ( empty( $steps ) || ! function_exists( 'exec' ) ) {
        return 0;
    }

    $cmd = implode( ' && ', $steps ) . ' & echo $!';
    $pid = trim( (string) exec( $cmd ) );

    return ctype_digit( $pid ) ? (int) $pid : 0;
}

/**
 * Best-effort check whether a background PID is still alive.
 *
 * @param int $pid Process id.
 *
 * @return bool|null
 */
function fic_is_process_running( $pid ) {
    $pid = (int) $pid;
    if ( $pid <= 0 ) {
        return null;
    }

    if ( function_exists( 'posix_kill' ) ) {
        return @posix_kill( $pid, 0 );
    }

    if ( function_exists( 'exec' ) ) {
        $output = [];
        $rc     = 0;
        @exec( sprintf( 'ps -p %d 2>/dev/null', $pid ), $output, $rc );

        if ( 0 !== $rc ) {
            return false;
        }

        return count( $output ) >= 2;
    }

    return null;
}

/**
 * Sanitise process/log error text before returning to clients.
 *
 * @param string $message Raw message.
 *
 * @return string
 */
function fic_sanitize_error_message( $message ) {
    $message = (string) $message;
    $message = wp_strip_all_tags( $message );

    // Replace absolute unix paths with just basename to avoid leaking server layout.
    $message = preg_replace_callback(
        '#/(?:[^\s:]+/)*[^\s:]+#',
        static function ( $matches ) {
            return basename( $matches[0] );
        },
        $message
    );

    $message = trim( preg_replace( '/\s+/', ' ', $message ) );

    if ( '' === $message ) {
        return 'Conversion failed.';
    }

    return mb_substr( $message, 0, 220 );
}
