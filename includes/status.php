<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Read job log lines.
 *
 * @param string $log_path Log file path.
 *
 * @return string[]
 */
function fic_read_log_lines( $log_path ) {
    if ( empty( $log_path ) || ! file_exists( $log_path ) ) {
        return [];
    }

    $lines = file( $log_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );

    return is_array( $lines ) ? $lines : [];
}

/**
 * Detect whether one log line represents an actionable error.
 *
 * @param string $line Log line.
 *
 * @return string|null
 */
function fic_extract_error_from_log_line( $line ) {
    $patterns = [
        '/^ERROR\b/i',
        '/\bFATAL\b/i',
        '/Traceback \(most recent call last\)/i',
        '/command not found/i',
        '/Permission denied/i',
        '/No such file or directory/i',
        '/could not extract files from image/i',
        '/No files extracted from IMG/i',
    ];

    foreach ( $patterns as $pattern ) {
        if ( preg_match( $pattern, $line ) ) {
            return fic_sanitize_error_message( $line );
        }
    }

    return null;
}

/**
 * Parse current log state into status fields.
 *
 * @param string[] $log_lines Log lines.
 *
 * @return array{last_line:string,max_step:int,percent_in_log:int|null,error_message:string|null}
 */
function fic_parse_log_state( array $log_lines ) {
    $last_line      = '';
    $max_step       = 0;
    $percent_in_log = null;
    $error_message  = null;

    foreach ( $log_lines as $line ) {
        $trim = trim( (string) $line );
        if ( '' === $trim ) {
            continue;
        }

        if ( preg_match( '/^STEP\s+([1-9]\d*)\/(\d+)\b/i', $trim, $matches ) ) {
            $step_no = (int) $matches[1];
            if ( $step_no > $max_step ) {
                $max_step = $step_no;
            }
        }

        if ( preg_match( '/(\d{1,3})\s*%/', $trim, $matches ) ) {
            $percent = (int) $matches[1];
            if ( $percent >= 0 && $percent <= 100 ) {
                $percent_in_log = max( (int) $percent_in_log, $percent );
            }
        }

        $line_error = fic_extract_error_from_log_line( $trim );
        if ( null !== $line_error ) {
            $error_message = $line_error;
        }

        $last_line = $trim;
    }

    return [
        'last_line'      => $last_line,
        'max_step'       => $max_step,
        'percent_in_log' => $percent_in_log,
        'error_message'  => $error_message,
    ];
}

/**
 * Calculate monotonic progress percent.
 *
 * @param int      $max_step       Highest completed step.
 * @param int|null $percent_in_log Percent parsed from logs.
 * @param int      $line_count     Number of log lines.
 *
 * @return int
 */
function fic_calculate_progress_percent( $max_step, $percent_in_log, $line_count ) {
    if ( null !== $percent_in_log ) {
        return min( 99, (int) $percent_in_log );
    }

    if ( $max_step > 0 ) {
        $by_step = (int) round( ( $max_step / (int) FIC_TOTAL_STEPS ) * 100 );

        return min( 99, $by_step );
    }

    if ( $line_count > 0 ) {
        return min( 95, $line_count * 3 );
    }

    return 5;
}

/**
 * Map current step to a human-readable phase.
 *
 * @param int    $step     Current step index.
 * @param string $out_path Destination output path.
 *
 * @return string
 */
function fic_describe_step( $step, $out_path ) {
    $out_is_zip = ( is_string( $out_path ) && '.zip' === substr( $out_path, -4 ) );
    $out_is_efe = ( is_string( $out_path ) && '.efe' === substr( $out_path, -4 ) );

    switch ( (int) $step ) {
        case 1:
            return 'Preparing temporary workspace';
        case 2:
            return 'Normalising image via gw convert';
        case 3:
            return $out_is_zip ? 'Preparing image and extracting files' : 'Preparing output image';
        case 4:
            return $out_is_zip ? 'Cleaning up intermediate image' : 'Finalising conversion';
        case 5:
            return $out_is_zip ? 'Creating ZIP archive of extracted files' : 'Finalising conversion';
        case 6:
            if ( $out_is_efe ) {
                return 'Extracting EFE output';
            }

            return $out_is_zip ? 'Removing temporary ZIP workspace' : 'Finishing conversion';
        default:
            if ( $out_is_efe ) {
                return 'Queued for EFE extraction';
            }

            return $out_is_zip ? 'Queued for ZIP extraction' : 'Queued for image conversion';
    }
}

/**
 * Poll job status and return progress/error details.
 *
 * @param WP_REST_Request $request REST request.
 *
 * @return WP_REST_Response|WP_Error
 */
function fic_get_status( WP_REST_Request $request ) {
    $job_id = sanitize_text_field( (string) $request->get_param( 'job_id' ) );
    $data   = get_transient( "fic_job_{$job_id}" );

    if ( ! $data ) {
        return new WP_Error( 'no_such_job', 'Unknown job_id.', [ 'status' => 404 ] );
    }

    $out_path = isset( $data['out'] ) ? (string) $data['out'] : '';
    $log_path = isset( $data['log'] ) ? (string) $data['log'] : '';
    $pid      = isset( $data['pid'] ) ? (int) $data['pid'] : 0;

    if ( $out_path && file_exists( $out_path ) ) {
        return rest_ensure_response(
            [
                'status'       => 'complete',
                'download_url' => esc_url_raw( fic_get_download_url( $out_path ) ),
            ]
        );
    }

    $log_lines = fic_read_log_lines( $log_path );
    $parsed    = fic_parse_log_state( $log_lines );

    if ( null !== $parsed['error_message'] ) {
        return rest_ensure_response(
            [
                'status'   => 'error',
                'message'  => $parsed['error_message'],
                'log_tail' => array_values( array_slice( $log_lines, -8 ) ),
            ]
        );
    }

    $process_running = fic_is_process_running( $pid );
    if ( false === $process_running && $out_path && ! file_exists( $out_path ) ) {
        return rest_ensure_response(
            [
                'status'   => 'error',
                'message'  => fic_sanitize_error_message( $parsed['last_line'] ?: 'Conversion process stopped before output was created.' ),
                'log_tail' => array_values( array_slice( $log_lines, -8 ) ),
            ]
        );
    }

    return rest_ensure_response(
        [
            'status'     => 'processing',
            'message'    => mb_substr( $parsed['last_line'] ?: 'Starting Greaseweazle conversion...', 0, 160 ),
            'percent'    => fic_calculate_progress_percent( $parsed['max_step'], $parsed['percent_in_log'], count( $log_lines ) ),
            'step'       => (int) $parsed['max_step'],
            'step_total' => (int) FIC_TOTAL_STEPS,
            'phase'      => fic_describe_step( $parsed['max_step'], $out_path ),
        ]
    );
}
