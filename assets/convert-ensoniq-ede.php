<?php
/**
 * Convert Ensoniq/Giebler EDE disk images to and from raw Ensoniq IMG.
 *
 * EDE is a compact EPS/EPS16 disk-image container: a one-block header includes
 * a skip table, and only Ensoniq blocks marked used in the FAT are stored.
 * This follows EpsLin Neo's ConvertToImage and ConvertFromImage routines.
 */

if ( PHP_SAPI !== 'cli' ) {
    exit( 1 );
}

const FIC_EDE_BLOCK_SIZE       = 512;
const FIC_EDE_IMAGE_SIZE       = 819200;
const FIC_EDE_TOTAL_BLOCKS     = 1600;
const FIC_EDE_SKIP_START       = 0xA0;
const FIC_EDE_SKIP_SIZE        = 200;
const FIC_EDE_ID               = 0x03;
const FIC_EDE_LABEL            = 'EPS-16 Disk';
const FIC_EDE_FAT_START_BLOCK  = 5;
const FIC_EDE_FAT_ENTRIES_BLK  = 170;
const FIC_EDE_FILLER_PATTERN   = "\x6D\xB6";

/**
 * @param string $message Error message.
 *
 * @return never
 */
function fic_ede_fail( $message ) {
    fwrite( STDERR, 'ERROR: ' . $message . PHP_EOL );
    exit( 1 );
}

/**
 * @return string
 */
function fic_ede_filler_block() {
    return str_repeat( FIC_EDE_FILLER_PATTERN, FIC_EDE_BLOCK_SIZE / 2 );
}

/**
 * @param string $bytes Binary data.
 *
 * @return string
 */
function fic_ede_normalize_mac_format( $bytes ) {
    if ( substr( $bytes, 0, 3 ) !== "\x0D\x0D\x0A" ) {
        return $bytes;
    }

    $normalized = '';
    $prev       = 0;
    $length     = strlen( $bytes );

    for ( $i = 0; $i < $length; $i++ ) {
        if ( "\x0A" === $bytes[ $i ] ) {
            $chunk_length = $i - $prev - 1;
            if ( $chunk_length > 0 ) {
                $normalized .= substr( $bytes, $prev, $chunk_length );
            }
            $prev = $i;
        }
    }

    $normalized .= substr( $bytes, $prev );

    return $normalized;
}

/**
 * @param string $ede EDE bytes.
 *
 * @return void
 */
function fic_ede_validate_header( $ede ) {
    if ( strlen( $ede ) < FIC_EDE_BLOCK_SIZE + 1 ) {
        fic_ede_fail( 'EDE file is too short.' );
    }

    if (
        substr( $ede, 0, 2 ) !== "\x0D\x0A"
        || substr( $ede, FIC_EDE_SKIP_START - 3, 3 ) !== "\x0D\x0A\x1A"
        || ord( $ede[ FIC_EDE_BLOCK_SIZE - 1 ] ) !== FIC_EDE_ID
    ) {
        fic_ede_fail( 'Input does not have a valid EDE header signature.' );
    }
}

/**
 * @param string $ede_path EDE input path.
 * @param string $img_path IMG output path.
 *
 * @return void
 */
function fic_ede_to_img( $ede_path, $img_path ) {
    $ede = file_get_contents( $ede_path );
    if ( false === $ede ) {
        fic_ede_fail( sprintf( 'Could not read EDE input: %s', $ede_path ) );
    }

    $ede = fic_ede_normalize_mac_format( $ede );
    fic_ede_validate_header( $ede );

    $skip_table = substr( $ede, FIC_EDE_SKIP_START, FIC_EDE_SKIP_SIZE );
    $cursor     = FIC_EDE_BLOCK_SIZE;
    $image      = '';
    $filler     = fic_ede_filler_block();

    for ( $i = 0; $i < FIC_EDE_SKIP_SIZE; $i++ ) {
        $bits = ord( $skip_table[ $i ] );

        for ( $j = 0; $j < 8; $j++ ) {
            if ( $bits & ( 0x80 >> $j ) ) {
                $image .= $filler;
                continue;
            }

            $block = substr( $ede, $cursor, FIC_EDE_BLOCK_SIZE );
            if ( FIC_EDE_BLOCK_SIZE !== strlen( $block ) ) {
                fic_ede_fail( 'EDE ended before all non-skipped blocks were available.' );
            }

            $image  .= $block;
            $cursor += FIC_EDE_BLOCK_SIZE;
        }
    }

    if ( FIC_EDE_IMAGE_SIZE !== strlen( $image ) ) {
        fic_ede_fail( 'Internal EDE conversion error: IMG size was not 800K.' );
    }

    if ( false === file_put_contents( $img_path, $image ) ) {
        fic_ede_fail( sprintf( 'Could not write IMG output: %s', $img_path ) );
    }

    printf( "Converted EDE to raw EPS IMG (%d bytes).\n", strlen( $image ) );
}

/**
 * @param string $img   Raw IMG bytes.
 * @param int    $block Block index.
 *
 * @return int
 */
function fic_ede_get_fat_entry( $img, $block ) {
    $fat_sector = (int) floor( $block / FIC_EDE_FAT_ENTRIES_BLK );
    $fat_pos    = $block % FIC_EDE_FAT_ENTRIES_BLK;
    $offset     = ( FIC_EDE_FAT_START_BLOCK + $fat_sector ) * FIC_EDE_BLOCK_SIZE + $fat_pos * 3;

    if ( $offset + 3 > strlen( $img ) ) {
        fic_ede_fail( 'FAT entry points beyond IMG size.' );
    }

    return (
        ord( $img[ $offset ] ) << 16
        | ord( $img[ $offset + 1 ] ) << 8
        | ord( $img[ $offset + 2 ] )
    );
}

/**
 * @param string $img Raw IMG bytes.
 *
 * @return void
 */
function fic_ede_validate_img( $img ) {
    if ( FIC_EDE_IMAGE_SIZE !== strlen( $img ) ) {
        fic_ede_fail( 'EDE output requires an 800K Ensoniq EPS/EPS16 IMG.' );
    }

    if ( substr( $img, FIC_EDE_BLOCK_SIZE + 38, 2 ) !== 'ID' || substr( $img, 2 * FIC_EDE_BLOCK_SIZE + 28, 2 ) !== 'OS' ) {
        fic_ede_fail( 'IMG does not look like an Ensoniq EPS/EPS16 image.' );
    }
}

/**
 * @param string $skip_table 200-byte skip table.
 *
 * @return string
 */
function fic_ede_build_header( $skip_table ) {
    $header = str_repeat( ' ', FIC_EDE_BLOCK_SIZE );

    $write = static function ( $offset, $bytes ) use ( &$header ) {
        $header = substr_replace( $header, $bytes, $offset, strlen( $bytes ) );
    };

    $write( 0, "\x0D\x0A" );
    $write( 2, FIC_EDE_LABEL );
    $write( 0x4E, "\x0D\x0A" );
    $write( FIC_EDE_SKIP_START - 3, "\x0D\x0A\x1A" );
    $write( FIC_EDE_SKIP_START, $skip_table );
    $header[ FIC_EDE_BLOCK_SIZE - 1 ] = chr( FIC_EDE_ID );

    return $header;
}

/**
 * @param string $img_path IMG input path.
 * @param string $ede_path EDE output path.
 *
 * @return void
 */
function fic_img_to_ede( $img_path, $ede_path ) {
    $img = file_get_contents( $img_path );
    if ( false === $img ) {
        fic_ede_fail( sprintf( 'Could not read IMG input: %s', $img_path ) );
    }

    fic_ede_validate_img( $img );

    $skip_table = '';
    $body       = '';

    for ( $i = 0; $i < FIC_EDE_SKIP_SIZE; $i++ ) {
        $bits = 0;

        for ( $j = 0; $j < 8; $j++ ) {
            $block_index = $i * 8 + $j;
            $bits        = $bits << 1;

            if ( 0 === fic_ede_get_fat_entry( $img, $block_index ) ) {
                $bits = $bits | 0x01;
                continue;
            }

            $body .= substr( $img, $block_index * FIC_EDE_BLOCK_SIZE, FIC_EDE_BLOCK_SIZE );
        }

        $skip_table .= chr( $bits );
    }

    $ede = fic_ede_build_header( $skip_table ) . $body . "\x1A";
    if ( false === file_put_contents( $ede_path, $ede ) ) {
        fic_ede_fail( sprintf( 'Could not write EDE output: %s', $ede_path ) );
    }

    printf( "Converted raw EPS IMG to EDE (%d bytes).\n", strlen( $ede ) );
}

if ( $argc < 4 ) {
    fic_ede_fail( 'Usage: php convert-ensoniq-ede.php <to-img|from-img> <input> <output>' );
}

$mode = strtolower( $argv[1] );
$input_path = $argv[2];
$output_path = $argv[3];

if ( ! is_file( $input_path ) || ! is_readable( $input_path ) ) {
    fic_ede_fail( sprintf( 'Input is not readable: %s', $input_path ) );
}

$out_dir = dirname( $output_path );
if ( ! is_dir( $out_dir ) && ! mkdir( $out_dir, 0775, true ) && ! is_dir( $out_dir ) ) {
    fic_ede_fail( sprintf( 'Could not create output directory: %s', $out_dir ) );
}

if ( 'to-img' === $mode ) {
    fic_ede_to_img( $input_path, $output_path );
} elseif ( 'from-img' === $mode ) {
    fic_img_to_ede( $input_path, $output_path );
} else {
    fic_ede_fail( sprintf( 'Unsupported mode: %s', $mode ) );
}
