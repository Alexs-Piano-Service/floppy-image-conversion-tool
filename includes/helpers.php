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
 * Build a user-facing download filename from the original upload name.
 *
 * @param string $original_name Uploaded filename.
 * @param string $out_fmt       Output format extension.
 *
 * @return string
 */
function fic_build_download_filename( $original_name, $out_fmt ) {
    $basename = wp_basename( (string) $original_name );
    $stem     = pathinfo( $basename, PATHINFO_FILENAME );
    $stem     = sanitize_file_name( $stem );
    $out_fmt  = strtolower( sanitize_key( (string) $out_fmt ) );

    if ( '' === $stem ) {
        $stem = 'converted-disk';
    }

    if ( '' === $out_fmt ) {
        return $stem;
    }

    return sanitize_file_name( $stem . '.' . $out_fmt );
}

/**
 * Normalise a requested download filename while forcing the output extension.
 *
 * @param string $filename Requested filename.
 * @param string $out_fmt  Output format extension.
 *
 * @return string
 */
function fic_normalize_download_filename( $filename, $out_fmt ) {
    return fic_build_download_filename( (string) $filename, $out_fmt );
}

/**
 * Build the REST download URL for one conversion job.
 *
 * @param string $job_id            Job UUID.
 * @param string $out_fmt           Output format extension.
 * @param string $download_filename User-facing download filename.
 *
 * @return string
 */
function fic_get_rest_download_url( $job_id, $out_fmt, $download_filename ) {
    return add_query_arg(
        [
            'job_id'   => (string) $job_id,
            'out_fmt'  => (string) $out_fmt,
            'filename' => fic_normalize_download_filename( $download_filename, $out_fmt ),
        ],
        rest_url( FIC_REST_NAMESPACE . '/download' )
    );
}

/**
 * Locate a completed output file for a job.
 *
 * @param string $job_id   Job UUID.
 * @param string $out_fmt  Output format extension.
 * @param array  $job_data Optional transient payload.
 *
 * @return string
 */
function fic_find_output_file_path( $job_id, $out_fmt, array $job_data = [] ) {
    $out_fmt = strtolower( sanitize_key( (string) $out_fmt ) );

    if ( isset( $job_data['out'] ) && is_string( $job_data['out'] ) && file_exists( $job_data['out'] ) ) {
        return $job_data['out'];
    }

    if ( '' === $job_id || '' === $out_fmt ) {
        return '';
    }

    $base_dir = fic_get_upload_base_dir();
    $paths    = [
        sprintf( '%1$s/%2$s.%3$s', $base_dir, $job_id, $out_fmt ),
        sprintf( '%1$s/%2$s-converted.%3$s', $base_dir, $job_id, $out_fmt ),
    ];

    foreach ( $paths as $path ) {
        if ( file_exists( $path ) ) {
            return $path;
        }
    }

    return '';
}

/**
 * Stream one converted artifact to the client with a friendly filename.
 *
 * @param string $file_path          Absolute file path.
 * @param string $download_filename  User-facing filename.
 *
 * @return void
 */
function fic_stream_download_file( $file_path, $download_filename ) {
    if ( ! is_string( $file_path ) || '' === $file_path || ! is_file( $file_path ) || ! is_readable( $file_path ) ) {
        status_header( 404 );
        exit;
    }

    $mime = wp_check_filetype( $download_filename );
    $type = isset( $mime['type'] ) && is_string( $mime['type'] ) && '' !== $mime['type'] ? $mime['type'] : 'application/octet-stream';

    while ( ob_get_level() > 0 ) {
        ob_end_clean();
    }

    nocache_headers();
    header( 'Content-Description: File Transfer' );
    header( 'Content-Type: ' . $type );
    header( 'Content-Disposition: attachment; filename="' . str_replace( '"', '', $download_filename ) . '"' );
    header( 'Content-Length: ' . (string) filesize( $file_path ) );

    readfile( $file_path );
    exit;
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
 * PHP CLI executable location, filterable for deployment-specific paths.
 *
 * @return string
 */
function fic_get_php_cli_path() {
    $default = 'php';

    if ( defined( 'PHP_BINARY' ) && is_string( PHP_BINARY ) && '' !== PHP_BINARY && @is_executable( PHP_BINARY ) ) {
        $basename = basename( PHP_BINARY );

        if ( preg_match( '/^php(?:[0-9.]+)?$/', $basename ) ) {
            $default = PHP_BINARY;
        }
    }

    return apply_filters( 'fic_php_cli_path', $default );
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
