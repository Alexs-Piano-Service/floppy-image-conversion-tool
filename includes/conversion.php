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

    if ( ( 'efe' === $input_ext || 'efe' === $out_fmt ) && ! fic_diskdef_supports_ensoniq_efe( $diskdef ) ) {
        return new WP_Error(
            'invalid_diskdef',
            'EFE conversion requires an Ensoniq EPS/ASR disk definition.',
            [ 'status' => 400 ]
        );
    }

    if ( ( 'ede' === $input_ext || 'ede' === $out_fmt ) && ! fic_diskdef_supports_ensoniq_ede( $diskdef ) ) {
        return new WP_Error(
            'invalid_diskdef',
            'EDE conversion requires the Ensoniq EPS/EPS16 800K disk definition.',
            [ 'status' => 400 ]
        );
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
    $php_cli     = fic_get_php_cli_path();
    $repair_cli  = FIC_PLUGIN_DIR . 'assets/repair-copy-protected-yamaha-720k.php';
    $efe_cli     = FIC_PLUGIN_DIR . 'assets/extract-ensoniq-efe.php';
    $efe_img_cli = FIC_PLUGIN_DIR . 'assets/create-ensoniq-img-from-efe.php';
    $ede_cli     = FIC_PLUGIN_DIR . 'assets/convert-ensoniq-ede.php';
    $tmp_img     = sprintf( '%1$s/IMG-%2$s.img', $base_dir, $job_id );
    $efe_img     = sprintf( '%1$s/EFE-%2$s.img', $base_dir, $job_id );
    $ede_img     = sprintf( '%1$s/EDE-%2$s.img', $base_dir, $job_id );
    $extract_img = sprintf( '%1$s/%2$s-extractable.img', $base_dir, $job_id );
    $tmp_dir     = sprintf( '%1$s/%2$s_zip', $base_dir, $job_id );
    $working_img = ( 'efe' === $ext ) ? $efe_img : ( ( 'ede' === $ext ) ? $ede_img : ( ( 'img' === $ext ) ? $in_path : $tmp_img ) );

    $steps   = [];
    $steps[] = fic_build_logged_step_command(
        1,
        $log_file,
        sprintf( 'mkdir -p %s', escapeshellarg( $tmp_dir ) )
    );

    if ( 'ede' === $ext ) {
        $steps[] = fic_build_logged_step_command(
            2,
            $log_file,
            sprintf(
                '( if command -v %1$s >/dev/null 2>&1; then %1$s %2$s to-img %3$s %4$s >> %5$s 2>&1; else echo %6$s >> %5$s; exit 1; fi ) || { echo %7$s >> %5$s; exit 1; }',
                escapeshellarg( $php_cli ),
                escapeshellarg( $ede_cli ),
                escapeshellarg( $in_path ),
                escapeshellarg( $ede_img ),
                escapeshellarg( $log_file ),
                escapeshellarg( 'ERROR: PHP CLI not found; cannot convert EDE to IMG.' ),
                escapeshellarg( 'ERROR: Could not convert EDE to IMG. Verify this is a valid Ensoniq EPS/EPS16 EDE disk image.' )
            )
        );
    } elseif ( 'efe' === $ext ) {
        $steps[] = fic_build_logged_step_command(
            2,
            $log_file,
            sprintf(
                '( if command -v %1$s >/dev/null 2>&1; then %1$s %2$s %3$s %4$s %5$s >> %6$s 2>&1; else echo %7$s >> %6$s; exit 1; fi ) || { echo %8$s >> %6$s; exit 1; }',
                escapeshellarg( $php_cli ),
                escapeshellarg( $efe_img_cli ),
                escapeshellarg( $in_path ),
                escapeshellarg( $efe_img ),
                escapeshellarg( $diskdef ),
                escapeshellarg( $log_file ),
                escapeshellarg( 'ERROR: PHP CLI not found; cannot create an Ensoniq IMG from EFE.' ),
                escapeshellarg( 'ERROR: Could not create an Ensoniq IMG from EFE. Verify this is a valid EFE file and the selected Ensoniq disk has enough space.' )
            )
        );
    } elseif ( 'img' !== $ext ) {
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
        if ( fic_diskdef_supports_ensoniq_efe( $diskdef ) ) {
            $steps[] = fic_build_logged_step_command(
                3,
                $log_file,
                sprintf(
                    '( if command -v %1$s >/dev/null 2>&1; then %1$s %2$s %3$s %4$s >> %5$s 2>&1; else echo %6$s >> %5$s; exit 1; fi ) || { echo %7$s >> %5$s; exit 1; }',
                    escapeshellarg( $php_cli ),
                    escapeshellarg( $efe_cli ),
                    escapeshellarg( $working_img ),
                    escapeshellarg( $tmp_dir ),
                    escapeshellarg( $log_file ),
                    escapeshellarg( 'ERROR: PHP CLI not found; cannot extract Ensoniq EFE files.' ),
                    escapeshellarg( 'ERROR: Could not extract Ensoniq EFE files from image. Verify this is an EPS/ASR disk image.' )
                )
            );
        } else {
            $steps[] = fic_build_logged_step_command(
                3,
                $log_file,
                sprintf(
                    '( if command -v %1$s >/dev/null 2>&1; then %1$s %2$s %3$s %4$s >> %5$s 2>&1; else echo %6$s >> %5$s && cp -f %3$s %4$s >> %5$s 2>&1; fi && 7z x -y -o%7$s %4$s >> %5$s 2>&1 ) || { echo %8$s >> %5$s; exit 1; }',
                    escapeshellarg( $php_cli ),
                    escapeshellarg( $repair_cli ),
                    escapeshellarg( $working_img ),
                    escapeshellarg( $extract_img ),
                    escapeshellarg( $log_file ),
                    escapeshellarg( 'Copy-protection repair skipped: PHP CLI not found; using original image.' ),
                    escapeshellarg( $tmp_dir ),
                    escapeshellarg( 'ERROR: Could not prepare or extract files from image. Verify format and that the image is not unsupported copy-protected media.' )
                )
            );
        }

        $steps[] = fic_build_logged_step_command(
            4,
            $log_file,
            sprintf(
                'rm -f %1$s %2$s >> %3$s 2>&1',
                escapeshellarg( $working_img ),
                escapeshellarg( $extract_img ),
                escapeshellarg( $log_file )
            )
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
    if ( 'efe' === $ext ) {
        $cleanup_paths[] = escapeshellarg( $efe_img );
    } elseif ( 'ede' === $ext ) {
        $cleanup_paths[] = escapeshellarg( $ede_img );
    } elseif ( 'img' !== $ext ) {
        $cleanup_paths[] = escapeshellarg( $tmp_img );
    }

    if ( 'efe' === $out_fmt ) {
        $steps[] = fic_build_logged_step_command(
            6,
            $log_file,
            sprintf(
                '( if command -v %1$s >/dev/null 2>&1; then %1$s %2$s %3$s %4$s >> %5$s 2>&1; else echo %6$s >> %5$s; exit 1; fi && cnt=$(find %4$s -type f -iname "*.efe" | wc -l) && if [ "$cnt" -ne 1 ]; then echo %7$s >> %5$s; exit 1; fi && efe_file=$(find %4$s -type f -iname "*.efe" | head -n 1) && mv "$efe_file" %8$s >> %5$s 2>&1 && rm -f %9$s >> %5$s 2>&1 && rm -rf %4$s >> %5$s 2>&1 ) || { echo %10$s >> %5$s; exit 1; }',
                escapeshellarg( $php_cli ),
                escapeshellarg( $efe_cli ),
                escapeshellarg( $working_img ),
                escapeshellarg( $tmp_dir ),
                escapeshellarg( $log_file ),
                escapeshellarg( 'ERROR: PHP CLI not found; cannot extract Ensoniq EFE files.' ),
                escapeshellarg( 'ERROR: EFE output requires the image to contain exactly one exportable Ensoniq file. Use ZIP output for images with multiple files.' ),
                escapeshellarg( $out_path ),
                implode( ' ', $cleanup_paths ),
                escapeshellarg( 'ERROR: Could not create EFE output from image. Verify this is an EPS/ASR disk image.' )
            )
        );

        return $steps;
    }

    if ( 'ede' === $out_fmt ) {
        $steps[] = fic_build_logged_step_command(
            6,
            $log_file,
            sprintf(
                '( if command -v %1$s >/dev/null 2>&1; then %1$s %2$s from-img %3$s %4$s >> %5$s 2>&1; else echo %6$s >> %5$s; exit 1; fi && rm -f %7$s >> %5$s 2>&1 && rm -rf %8$s >> %5$s 2>&1 ) || { echo %9$s >> %5$s; exit 1; }',
                escapeshellarg( $php_cli ),
                escapeshellarg( $ede_cli ),
                escapeshellarg( $working_img ),
                escapeshellarg( $out_path ),
                escapeshellarg( $log_file ),
                escapeshellarg( 'ERROR: PHP CLI not found; cannot convert IMG to EDE.' ),
                implode( ' ', $cleanup_paths ),
                escapeshellarg( $tmp_dir ),
                escapeshellarg( 'ERROR: Could not create EDE output. Verify the intermediate image is a valid 800K Ensoniq EPS/EPS16 IMG.' )
            )
        );

        return $steps;
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
    $download_name = fic_build_download_filename( (string) $file['name'], $out_fmt );

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
            'out_fmt'    => $out_fmt,
            'download_name' => $download_name,
            'pid'        => $pid,
            'step_total' => (int) FIC_TOTAL_STEPS,
        ],
        HOUR_IN_SECONDS
    );

    return rest_ensure_response(
        [
            'job_id'       => $job_id,
            'download_url' => esc_url_raw( fic_get_rest_download_url( $job_id, $out_fmt, $download_name ) ),
            'download_filename' => $download_name,
        ]
    );
}
