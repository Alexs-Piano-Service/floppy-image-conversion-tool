<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Scan uploaded file with ClamAV.
 *
 * @param string $file_path Temp file path.
 *
 * @return array{status:string,detail:string}
 */
function fic_scan_with_clamav( $file_path ) {
    $scanner = fic_get_clamdscan_path();

    if ( ! function_exists( 'exec' ) || ! is_executable( $scanner ) ) {
        return [
            'status' => 'unavailable',
            'detail' => 'ClamAV scanner not available on this host.',
        ];
    }

    $cmd         = $scanner . ' --fdpass --no-summary ' . escapeshellarg( $file_path ) . ' 2>&1';
    $output      = [];
    $exit_status = 0;

    exec( $cmd, $output, $exit_status );

    $detail = implode( "\n", $output );

    if ( 0 === $exit_status ) {
        return [
            'status' => 'clean',
            'detail' => $detail,
        ];
    }

    if ( 1 === $exit_status ) {
        return [
            'status' => 'infected',
            'detail' => $detail,
        ];
    }

    return [
        'status' => 'error',
        'detail' => $detail,
    ];
}

/**
 * Pull and validate uploaded file payload from REST request.
 *
 * @param WP_REST_Request $request REST request.
 *
 * @return array|WP_Error
 */
function fic_extract_uploaded_file( WP_REST_Request $request ) {
    $files = $request->get_file_params();

    if ( empty( $files['file'] ) || ! is_array( $files['file'] ) ) {
        return new WP_Error( 'no_file', 'No file uploaded.', [ 'status' => 400 ] );
    }

    $file = $files['file'];

    if ( ! empty( $file['error'] ) && UPLOAD_ERR_OK !== (int) $file['error'] ) {
        return new WP_Error( 'upload_failed', 'Upload error while receiving file.', [ 'status' => 400 ] );
    }

    if ( empty( $file['tmp_name'] ) || ! file_exists( $file['tmp_name'] ) ) {
        return new WP_Error( 'upload_failed', 'Uploaded temp file is missing.', [ 'status' => 400 ] );
    }

    if ( empty( $file['name'] ) ) {
        return new WP_Error( 'invalid_upload', 'Uploaded file must have a name.', [ 'status' => 400 ] );
    }

    return $file;
}

/**
 * Validate requested conversion parameters.
 *
 * @param string $input_ext Original upload extension.
 * @param string $out_fmt   Requested output format.
 * @param string $diskdef   Requested disk definition.
 *
 * @return true|WP_Error
 */
function fic_validate_requested_formats( $input_ext, $out_fmt, $diskdef ) {
    if ( ! in_array( $input_ext, fic_allowed_input_formats(), true ) ) {
        return new WP_Error( 'invalid_input_format', 'Input format not allowed.', [ 'status' => 400 ] );
    }

    if ( ! in_array( $out_fmt, fic_allowed_output_formats(), true ) ) {
        return new WP_Error( 'invalid_output_format', 'Output format not allowed.', [ 'status' => 400 ] );
    }

    if ( ! in_array( $diskdef, fic_allowed_diskdefs(), true ) ) {
        return new WP_Error( 'invalid_diskdef', 'Disk definition not allowed.', [ 'status' => 400 ] );
    }

    return true;
}

/**
 * Build canonical conversion file paths for a given job id.
 *
 * @param string $base_dir Upload working directory.
 * @param string $job_id   Job UUID.
 * @param string $in_ext   Input extension (normalised).
 * @param string $out_fmt  Output format.
 *
 * @return array{in:string,out:string,log:string}
 */
function fic_build_job_paths( $base_dir, $job_id, $in_ext, $out_fmt ) {
    $in_path  = sprintf( '%1$s/%2$s.%3$s', $base_dir, $job_id, $in_ext );
    $out_path = sprintf( '%1$s/%2$s.%3$s', $base_dir, $job_id, $out_fmt );

    if ( $in_path === $out_path ) {
        $out_path = sprintf( '%1$s/%2$s-converted.%3$s', $base_dir, $job_id, $out_fmt );
    }

    return [
        'in'  => $in_path,
        'out' => $out_path,
        'log' => sprintf( '%1$s/%2$s.log', $base_dir, $job_id ),
    ];
}

/**
 * Build shell pipeline for one conversion job.
 *
 * @param array $job Job payload.
 *
 * @return string[]
 */
function fic_build_shell_steps( array $job ) {
    $in_path  = $job['in'];
    $out_path = $job['out'];
    $log_file = $job['log'];
    $ext      = $job['ext'];
    $out_fmt  = $job['out_fmt'];
    $diskdef  = $job['diskdef'];
    $base_dir = $job['base_dir'];
    $job_id   = $job['job_id'];

    $gw_cli      = fic_get_greaseweazle_cli_path();
    $tmp_img     = sprintf( '%1$s/IMG-%2$s.img', $base_dir, $job_id );
    $tmp_dir     = sprintf( '%1$s/%2$s_zip', $base_dir, $job_id );
    $working_img = ( 'img' === $ext ) ? $in_path : $tmp_img;

    $steps   = [];
    $steps[] = fic_build_logged_step_command(
        1,
        $log_file,
        sprintf( 'mkdir -p %s', escapeshellarg( $tmp_dir ) )
    );

    if ( 'img' !== $ext ) {
        $steps[] = fic_build_logged_step_command(
            2,
            $log_file,
            sprintf(
                '%1$s convert --format=%2$s %3$s %4$s >> %5$s 2>&1',
                escapeshellarg( $gw_cli ),
                escapeshellarg( $diskdef ),
                escapeshellarg( $in_path ),
                escapeshellarg( $tmp_img ),
                escapeshellarg( $log_file )
            )
        );
    } else {
        $steps[] = fic_build_logged_step_command(
            2,
            $log_file,
            sprintf(
                'echo %1$s >> %2$s',
                escapeshellarg( 'Source is already IMG; using upload as working image.' ),
                escapeshellarg( $log_file )
            )
        );
    }

    if ( 'zip' === $out_fmt ) {
        $steps[] = fic_build_logged_step_command(
            3,
            $log_file,
            sprintf(
                '( 7z x -y -o%1$s %2$s >> %3$s 2>&1 ) || { echo %4$s >> %3$s; exit 1; }',
                escapeshellarg( $tmp_dir ),
                escapeshellarg( $working_img ),
                escapeshellarg( $log_file ),
                escapeshellarg( 'ERROR: 7z could not extract files from image. Verify format and that the image is not copy-protected.' )
            )
        );

        $steps[] = fic_build_logged_step_command(
            4,
            $log_file,
            sprintf( 'rm -f %s >> %s 2>&1', escapeshellarg( $working_img ), escapeshellarg( $log_file ) )
        );

        $steps[] = fic_build_logged_step_command(
            5,
            $log_file,
            sprintf(
                "( cd %1\$s && cnt=\$(find . -type f ! -name '*.img' ! -name '*.IMG' | wc -l) && if [ \"\$cnt\" -eq 0 ]; then echo %2\$s >> %3\$s; exit 1; fi && zip -r %4\$s . -x \"*.img\" -x \"*.IMG\" >> %3\$s 2>&1 )",
                escapeshellarg( $tmp_dir ),
                escapeshellarg( 'ERROR: No files extracted from IMG (nothing to zip).' ),
                escapeshellarg( $log_file ),
                escapeshellarg( $out_path )
            )
        );

        $steps[] = fic_build_logged_step_command(
            6,
            $log_file,
            sprintf(
                'rm -rf %1$s >> %3$s 2>&1 && rm -f %2$s >> %3$s 2>&1',
                escapeshellarg( $tmp_dir ),
                escapeshellarg( $in_path ),
                escapeshellarg( $log_file )
            )
        );

        return $steps;
    }

    $cleanup_paths = [ escapeshellarg( $in_path ) ];
    if ( 'img' !== $ext ) {
        $cleanup_paths[] = escapeshellarg( $tmp_img );
    }

    $steps[] = fic_build_logged_step_command(
        6,
        $log_file,
        sprintf(
            '%1$s convert --format=%2$s %3$s %4$s >> %5$s 2>&1 && rm -f %6$s >> %5$s 2>&1 && rm -rf %7$s >> %5$s 2>&1',
            escapeshellarg( $gw_cli ),
            escapeshellarg( $diskdef ),
            escapeshellarg( $working_img ),
            escapeshellarg( $out_path ),
            escapeshellarg( $log_file ),
            implode( ' ', $cleanup_paths ),
            escapeshellarg( $tmp_dir )
        )
    );

    return $steps;
}

/**
 * Handle conversion request: queue background conversion process.
 *
 * @param WP_REST_Request $request REST request.
 *
 * @return WP_REST_Response|WP_Error
 */
function fic_handle_convert( WP_REST_Request $request ) {
    $file = fic_extract_uploaded_file( $request );
    if ( is_wp_error( $file ) ) {
        return $file;
    }

    $orig_ext = strtolower( pathinfo( (string) $file['name'], PATHINFO_EXTENSION ) );
    if ( '' === $orig_ext ) {
        return new WP_Error( 'missing_extension', 'Uploaded file must have an extension.', [ 'status' => 400 ] );
    }

    $out_fmt = strtolower( sanitize_text_field( (string) $request->get_param( 'out_fmt' ) ) );
    $diskdef = strtolower( sanitize_text_field( (string) $request->get_param( 'diskdef' ) ) );

    if ( '' === $out_fmt ) {
        $out_fmt = fic_default_output_format();
    }
    if ( '' === $diskdef ) {
        $diskdef = fic_default_diskdef();
    }

    $validation = fic_validate_requested_formats( $orig_ext, $out_fmt, $diskdef );
    if ( is_wp_error( $validation ) ) {
        return $validation;
    }

    $scan = fic_scan_with_clamav( (string) $file['tmp_name'] );
    if ( 'infected' === $scan['status'] ) {
        @unlink( (string) $file['tmp_name'] );

        return new WP_Error( 'infected', 'Virus detected in uploaded file.', [ 'status' => 400 ] );
    }

    $gw_cli = fic_get_greaseweazle_cli_path();
    if ( ! is_executable( $gw_cli ) ) {
        return new WP_Error( 'gw_missing', 'Greaseweazle CLI is unavailable on this server.', [ 'status' => 500 ] );
    }

    $base_dir = fic_get_upload_base_dir();
    if ( ! wp_mkdir_p( $base_dir ) ) {
        return new WP_Error( 'storage_unavailable', 'Could not prepare upload directory.', [ 'status' => 500 ] );
    }

    $input_ext = fic_normalize_input_extension( $orig_ext );
    $job_id    = wp_generate_uuid4();
    $paths     = fic_build_job_paths( $base_dir, $job_id, $input_ext, $out_fmt );

    if ( false === @file_put_contents( $paths['log'], '' ) ) {
        return new WP_Error( 'log_init_failed', 'Could not initialise conversion log.', [ 'status' => 500 ] );
    }

    if ( ! move_uploaded_file( (string) $file['tmp_name'], $paths['in'] ) ) {
        return new WP_Error( 'upload_failed', 'Could not save upload.', [ 'status' => 500 ] );
    }

    $steps = fic_build_shell_steps(
        [
            'job_id'   => $job_id,
            'base_dir' => $base_dir,
            'in'       => $paths['in'],
            'out'      => $paths['out'],
            'log'      => $paths['log'],
            'ext'      => $input_ext,
            'out_fmt'  => $out_fmt,
            'diskdef'  => $diskdef,
        ]
    );

    $pid = fic_spawn_background_job( $steps );
    if ( $pid <= 0 ) {
        return new WP_Error( 'spawn_failed', 'Could not start conversion process.', [ 'status' => 500 ] );
    }

    set_transient(
        "fic_job_{$job_id}",
        [
            'in'         => $paths['in'],
            'out'        => $paths['out'],
            'log'        => $paths['log'],
            'pid'        => $pid,
            'step_total' => (int) FIC_TOTAL_STEPS,
        ],
        HOUR_IN_SECONDS
    );

    return rest_ensure_response(
        [
            'job_id'       => $job_id,
            'download_url' => esc_url_raw( fic_get_download_url( $paths['out'] ) ),
        ]
    );
}
