<?php
/**
 * Extract Giebler-style EFE files from Ensoniq EPS/ASR disk images.
 *
 * The on-disk directory/FAT layout and EFE wrapper header follow EpsLin Neo's
 * GetEFEs routine for EPS/EPS16+/ASR image files.
 */

if ( PHP_SAPI !== 'cli' ) {
    exit( 1 );
}

const FIC_ENSONIQ_BLOCK_SIZE       = 512;
const FIC_ENSONIQ_ID_BLOCK         = 1;
const FIC_ENSONIQ_OS_BLOCK         = 2;
const FIC_ENSONIQ_DIR_START_BLOCK  = 3;
const FIC_ENSONIQ_DIR_BLOCKS       = 2;
const FIC_ENSONIQ_EFE_ENTRY_SIZE   = 26;
const FIC_ENSONIQ_MAX_DIR_ENTRIES  = 39;
const FIC_ENSONIQ_MAX_DIR_DEPTH    = 10;
const FIC_ENSONIQ_FAT_START_BLOCK  = 5;
const FIC_ENSONIQ_FAT_ENTRIES_BLK  = 170;
const FIC_ENSONIQ_FAT_EOF          = 1;
const FIC_ENSONIQ_EPS_FAMILY       = 0;
const FIC_ENSONIQ_EXPECTED_SIZES   = [
    819200  => 'EPS/EPS16 800K',
    1638400 => 'ASR 1600K',
    2611200 => 'EPS16 SuperDisk',
    5222400 => 'ASR SuperDisk',
];
const FIC_ENSONIQ_EPS_TYPES        = [
    '(empty)', 'EPS-OS ', 'DIR/   ', 'Instr  ', 'EPS-Bnk', 'EPS-Seq', 'EPS-Sng', 'EPS-Sys', '.. /   ', 'EPS-Mac',
    'SD-1Pro', 'SD-6Pro', 'SD-30Pg', 'SD-60Pg', 'SD-1Pre', 'SD-10Ps', 'SD-20Ps', 'SD-1Seq', 'SD30Seq', 'SD60Seq',
    'SD-SysX', 'SD-Set ', 'SD-SqOS', 'E16-Bnk', 'E16-Eff', 'E16-Seq', 'E16-Sng', 'E16-OS ', 'ASR-Seq', 'ASR-Sng',
    'ASR-Bnk', 'ASR-Trk', 'ASR-OS ', 'ASR-Eff', 'ASR-Mac', 'TS-6Pro', 'TS-60Pg', 'TS120Pg', 'TS-1Pre', 'TS-60Ps',
    'TS120Ps', 'TS-1Seq', 'TS30Seq', 'TS60Seq', 'ENS44xx', 'ENS45xx', 'ENS46xx', 'ENS47xx', 'ENS48xx', 'ENS49xx',
];

/**
 * @param string $message Error message.
 *
 * @return never
 */
function fic_ensoniq_fail( $message ) {
    fwrite( STDERR, 'ERROR: ' . $message . PHP_EOL );
    exit( 1 );
}

/**
 * @param string $bytes  Binary string.
 * @param int    $offset Byte offset.
 *
 * @return int
 */
function fic_ensoniq_byte_at( $bytes, $offset ) {
    if ( $offset < 0 || $offset >= strlen( $bytes ) ) {
        fic_ensoniq_fail( 'Unexpected end of image while reading byte data.' );
    }

    return ord( $bytes[ $offset ] );
}

/**
 * @param string $bytes  Binary string.
 * @param int    $offset Byte offset.
 *
 * @return int
 */
function fic_ensoniq_u16be( $bytes, $offset ) {
    if ( $offset < 0 || $offset + 2 > strlen( $bytes ) ) {
        fic_ensoniq_fail( 'Unexpected end of image while reading 16-bit data.' );
    }

    $value = unpack( 'n', substr( $bytes, $offset, 2 ) );

    return (int) $value[1];
}

/**
 * @param string $bytes  Binary string.
 * @param int    $offset Byte offset.
 *
 * @return int
 */
function fic_ensoniq_u32be( $bytes, $offset ) {
    if ( $offset < 0 || $offset + 4 > strlen( $bytes ) ) {
        fic_ensoniq_fail( 'Unexpected end of image while reading 32-bit data.' );
    }

    $value = unpack( 'N', substr( $bytes, $offset, 4 ) );

    return (int) $value[1];
}

/**
 * @param string $text Binary-safe source text.
 *
 * @return string
 */
function fic_ensoniq_clean_text( $text ) {
    $text = str_replace( "\0", '', $text );
    $text = preg_replace( '/[^\x20-\x7E]/', ' ', $text );
    $text = preg_replace( '/\s+/', ' ', (string) $text );

    return trim( $text );
}

/**
 * @param string $image Raw image bytes.
 *
 * @return array{total_blocks:int,fat_blocks:int,family:int,label:string,type:string}
 */
function fic_ensoniq_detect_volume( $image ) {
    $size = strlen( $image );

    if ( 0 === $size || 0 !== $size % FIC_ENSONIQ_BLOCK_SIZE ) {
        fic_ensoniq_fail( 'Image size is not a non-empty multiple of 512 bytes.' );
    }

    $id_offset = FIC_ENSONIQ_ID_BLOCK * FIC_ENSONIQ_BLOCK_SIZE;
    $os_offset = FIC_ENSONIQ_OS_BLOCK * FIC_ENSONIQ_BLOCK_SIZE;

    if ( substr( $image, $id_offset + 38, 2 ) !== 'ID' || substr( $image, $os_offset + 28, 2 ) !== 'OS' ) {
        fic_ensoniq_fail( 'Not an Ensoniq EPS/ASR image: expected ID/OS signatures were not found.' );
    }

    $total_blocks = fic_ensoniq_u32be( $image, $id_offset + 14 );
    if ( $total_blocks <= FIC_ENSONIQ_FAT_START_BLOCK ) {
        fic_ensoniq_fail( 'Invalid Ensoniq block count in ID block.' );
    }

    if ( $total_blocks * FIC_ENSONIQ_BLOCK_SIZE > $size ) {
        fic_ensoniq_fail( 'Image is shorter than the block count recorded in the Ensoniq ID block.' );
    }

    $fat_blocks = (int) ceil( $total_blocks / FIC_ENSONIQ_FAT_ENTRIES_BLK );
    if ( ( FIC_ENSONIQ_FAT_START_BLOCK + $fat_blocks ) * FIC_ENSONIQ_BLOCK_SIZE > $size ) {
        fic_ensoniq_fail( 'Image is shorter than the FAT size implied by the Ensoniq ID block.' );
    }

    return [
        'total_blocks' => $total_blocks,
        'fat_blocks'   => $fat_blocks,
        'family'       => fic_ensoniq_byte_at( $image, $os_offset + 9 ),
        'label'        => fic_ensoniq_clean_text( substr( $image, $id_offset + 31, 7 ) ),
        'type'         => isset( FIC_ENSONIQ_EXPECTED_SIZES[ $size ] ) ? FIC_ENSONIQ_EXPECTED_SIZES[ $size ] : 'Ensoniq',
    ];
}

/**
 * @param string $image Raw image bytes.
 * @param int    $block Block index.
 *
 * @return int
 */
function fic_ensoniq_get_fat_entry( $image, $block ) {
    $fat_sector = (int) floor( $block / FIC_ENSONIQ_FAT_ENTRIES_BLK );
    $fat_pos    = $block % FIC_ENSONIQ_FAT_ENTRIES_BLK;
    $offset     = ( FIC_ENSONIQ_FAT_START_BLOCK + $fat_sector ) * FIC_ENSONIQ_BLOCK_SIZE + $fat_pos * 3;

    if ( $offset + 3 > strlen( $image ) ) {
        fic_ensoniq_fail( 'FAT entry points beyond the image.' );
    }

    return (
        fic_ensoniq_byte_at( $image, $offset ) << 16
        | fic_ensoniq_byte_at( $image, $offset + 1 ) << 8
        | fic_ensoniq_byte_at( $image, $offset + 2 )
    );
}

/**
 * @param string $image        Raw image bytes.
 * @param int    $block        Block index.
 * @param int    $total_blocks Total volume blocks.
 *
 * @return string
 */
function fic_ensoniq_read_block( $image, $block, $total_blocks ) {
    if ( $block < 0 || $block >= $total_blocks ) {
        fic_ensoniq_fail( sprintf( 'Block %d is outside the Ensoniq volume.', $block ) );
    }

    $offset = $block * FIC_ENSONIQ_BLOCK_SIZE;
    $data   = substr( $image, $offset, FIC_ENSONIQ_BLOCK_SIZE );

    if ( FIC_ENSONIQ_BLOCK_SIZE !== strlen( $data ) ) {
        fic_ensoniq_fail( sprintf( 'Could not read complete block %d from image.', $block ) );
    }

    return $data;
}

/**
 * @param string $image           Raw image bytes.
 * @param int    $start_block     First block in chain.
 * @param int    $contiguous      Number of initial contiguous blocks.
 * @param int    $expected_blocks Number of blocks to read.
 * @param int    $total_blocks    Total volume blocks.
 *
 * @return string
 */
function fic_ensoniq_read_chain( $image, $start_block, $contiguous, $expected_blocks, $total_blocks ) {
    if ( $expected_blocks <= 0 ) {
        return '';
    }

    if ( $start_block <= 0 ) {
        fic_ensoniq_fail( 'Invalid start block in Ensoniq directory entry.' );
    }

    $data       = '';
    $remaining  = $expected_blocks;
    $run_length = max( 1, min( $contiguous, $remaining ) );
    $current    = $start_block;
    $visited    = [];

    for ( $i = 0; $i < $run_length; $i++ ) {
        $block = $start_block + $i;
        $data .= fic_ensoniq_read_block( $image, $block, $total_blocks );
        $visited[ $block ] = true;
        $current = $block;
        $remaining--;
    }

    while ( $remaining > 0 ) {
        $next = fic_ensoniq_get_fat_entry( $image, $current );

        if ( FIC_ENSONIQ_FAT_EOF === $next ) {
            fic_ensoniq_fail( 'FAT chain ended before the directory entry block count was satisfied.' );
        }

        if ( isset( $visited[ $next ] ) ) {
            fic_ensoniq_fail( 'Loop detected in Ensoniq FAT chain.' );
        }

        $data .= fic_ensoniq_read_block( $image, $next, $total_blocks );
        $visited[ $next ] = true;
        $current = $next;
        $remaining--;
    }

    return $data;
}

/**
 * @param string $raw_name Raw 12-byte Ensoniq name.
 *
 * @return string
 */
function fic_ensoniq_name_for_display( $raw_name ) {
    $name = fic_ensoniq_clean_text( rtrim( $raw_name, " \0" ) );

    return '' === $name ? 'UNTITLED' : $name;
}

/**
 * @param string $name Ensoniq name.
 *
 * @return string
 */
function fic_ensoniq_safe_name( $name ) {
    $name = str_replace( [ '*', '/', '\\' ], [ '#', '^', '^' ], $name );
    $name = preg_replace( '/[^\x20-\x7E]/', ' ', $name );
    $name = preg_replace( '/[<>:"|?]+/', '_', (string) $name );
    $name = preg_replace( '/\s+/', ' ', (string) $name );
    $name = trim( $name, " .\t\r\n" );

    return '' === $name ? 'UNTITLED' : $name;
}

/**
 * @param string $path Directory path.
 *
 * @return void
 */
function fic_ensoniq_prepare_directory( $path ) {
    if ( is_dir( $path ) ) {
        return;
    }

    if ( ! mkdir( $path, 0775, true ) && ! is_dir( $path ) ) {
        fic_ensoniq_fail( sprintf( 'Could not create output directory: %s', $path ) );
    }
}

/**
 * @param string $path Desired file path.
 *
 * @return string
 */
function fic_ensoniq_unique_path( $path ) {
    if ( ! file_exists( $path ) ) {
        return $path;
    }

    $dir  = dirname( $path );
    $base = pathinfo( $path, PATHINFO_FILENAME );
    $ext  = pathinfo( $path, PATHINFO_EXTENSION );

    for ( $i = 2; $i < 1000; $i++ ) {
        $candidate = sprintf( '%s/%s-%03d%s', $dir, $base, $i, $ext ? '.' . $ext : '' );
        if ( ! file_exists( $candidate ) ) {
            return $candidate;
        }
    }

    fic_ensoniq_fail( sprintf( 'Could not create a unique output filename for %s.', $path ) );
}

/**
 * @param array{type:int,name_raw:string,size:int,contiguous:int,start:int,multifile:int,index:int} $entry  Directory entry.
 * @param int                                                                              $family Ensoniq model family.
 *
 * @return string
 */
function fic_ensoniq_output_filename( array $entry, $family ) {
    $type_text = isset( FIC_ENSONIQ_EPS_TYPES[ $entry['type'] ] ) ? rtrim( FIC_ENSONIQ_EPS_TYPES[ $entry['type'] ] ) : 'TYPE' . $entry['type'];

    if ( $entry['multifile'] > 0 && FIC_ENSONIQ_EPS_FAMILY === $family ) {
        $type_text = sprintf( '%s %02d', $type_text, $entry['multifile'] );
    }

    return sprintf(
        '[%02d][%s] %s.efe',
        $entry['index'],
        fic_ensoniq_safe_name( $type_text ),
        fic_ensoniq_safe_name( fic_ensoniq_name_for_display( $entry['name_raw'] ) )
    );
}

/**
 * @param array{type:int,name_raw:string,size:int,contiguous:int,start:int,multifile:int} $entry Directory entry.
 *
 * @return string
 */
function fic_ensoniq_build_efe_header( array $entry ) {
    $header = str_repeat( "\0", FIC_ENSONIQ_BLOCK_SIZE );

    $write = static function ( $offset, $bytes ) use ( &$header ) {
        $header = substr_replace( $header, $bytes, $offset, strlen( $bytes ) );
    };

    $write( 0, "\x0D\x0A" );
    $write( 2, 'Eps File:       ' );
    $write( 18, str_pad( substr( $entry['name_raw'], 0, 12 ), 12, "\0" ) );
    $write( 30, isset( FIC_ENSONIQ_EPS_TYPES[ $entry['type'] ] ) ? FIC_ENSONIQ_EPS_TYPES[ $entry['type'] ] : 'Unknown' );
    $write( 37, '          ' );

    $header[47] = "\x0D";
    $header[48] = "\x0A";
    $header[49] = "\x1A";
    $header[50] = chr( $entry['type'] );
    $header[51] = "\0";
    $header[52] = chr( ( $entry['size'] >> 8 ) & 0xFF );
    $header[53] = chr( $entry['size'] & 0xFF );
    $header[54] = chr( ( $entry['contiguous'] >> 8 ) & 0xFF );
    $header[55] = chr( $entry['contiguous'] & 0xFF );
    $header[56] = chr( ( $entry['start'] >> 8 ) & 0xFF );
    $header[57] = chr( $entry['start'] & 0xFF );
    $header[58] = chr( $entry['multifile'] );

    return $header;
}

/**
 * @param string $dir_bytes Directory bytes.
 * @param int    $index     Directory entry index.
 *
 * @return array{type:int,name_raw:string,size:int,contiguous:int,start:int,multifile:int,index:int}
 */
function fic_ensoniq_parse_entry( $dir_bytes, $index ) {
    $offset = $index * FIC_ENSONIQ_EFE_ENTRY_SIZE;
    $entry  = substr( $dir_bytes, $offset, FIC_ENSONIQ_EFE_ENTRY_SIZE );

    if ( FIC_ENSONIQ_EFE_ENTRY_SIZE !== strlen( $entry ) ) {
        fic_ensoniq_fail( 'Could not read complete Ensoniq directory entry.' );
    }

    return [
        'type'       => fic_ensoniq_byte_at( $entry, 1 ),
        'name_raw'   => substr( $entry, 2, 12 ),
        'size'       => fic_ensoniq_u16be( $entry, 14 ),
        'contiguous' => fic_ensoniq_u16be( $entry, 16 ),
        'start'      => fic_ensoniq_u32be( $entry, 18 ),
        'multifile'  => fic_ensoniq_byte_at( $entry, 22 ),
        'index'      => $index,
    ];
}

/**
 * @param array{type:int} $entry Directory entry.
 *
 * @return bool
 */
function fic_ensoniq_entry_is_exportable( array $entry ) {
    return (
        $entry['type'] > 0
        && 2 !== $entry['type']
        && 8 !== $entry['type']
        && $entry['type'] <= 49
    );
}

/**
 * @param string                                                                            $image      Raw image bytes.
 * @param array{total_blocks:int,family:int}                                                $volume     Volume info.
 * @param int                                                                               $start      Directory start block.
 * @param int                                                                               $contiguous Directory contiguous blocks.
 * @param string                                                                            $output_dir Output directory.
 * @param int                                                                               $depth      Recursion depth.
 * @param array<string,bool>                                                                $seen_dirs  Seen directories.
 * @param int                                                                               $count      Extracted count.
 *
 * @return int
 */
function fic_ensoniq_extract_directory( $image, array $volume, $start, $contiguous, $output_dir, $depth, array $seen_dirs, $count ) {
    if ( $depth > FIC_ENSONIQ_MAX_DIR_DEPTH ) {
        fic_ensoniq_fail( 'Maximum Ensoniq directory depth exceeded.' );
    }

    $dir_key = (string) $start;
    if ( isset( $seen_dirs[ $dir_key ] ) ) {
        fic_ensoniq_fail( 'Loop detected in Ensoniq directory tree.' );
    }
    $seen_dirs[ $dir_key ] = true;

    fic_ensoniq_prepare_directory( $output_dir );

    $dir_bytes = fic_ensoniq_read_chain(
        $image,
        $start,
        $contiguous,
        FIC_ENSONIQ_DIR_BLOCKS,
        $volume['total_blocks']
    );

    for ( $i = 0; $i < FIC_ENSONIQ_MAX_DIR_ENTRIES; $i++ ) {
        $entry = fic_ensoniq_parse_entry( $dir_bytes, $i );

        if ( 0 === $entry['type'] || 8 === $entry['type'] ) {
            continue;
        }

        if ( 2 === $entry['type'] ) {
            $subdir = fic_ensoniq_safe_name( fic_ensoniq_name_for_display( $entry['name_raw'] ) );
            $count  = fic_ensoniq_extract_directory(
                $image,
                $volume,
                $entry['start'],
                $entry['contiguous'],
                $output_dir . '/' . $subdir,
                $depth + 1,
                $seen_dirs,
                $count
            );
            continue;
        }

        if ( ! fic_ensoniq_entry_is_exportable( $entry ) ) {
            continue;
        }

        $efe_path = fic_ensoniq_unique_path( $output_dir . '/' . fic_ensoniq_output_filename( $entry, $volume['family'] ) );
        $payload  = fic_ensoniq_read_chain(
            $image,
            $entry['start'],
            $entry['contiguous'],
            $entry['size'],
            $volume['total_blocks']
        );

        if ( false === file_put_contents( $efe_path, fic_ensoniq_build_efe_header( $entry ) . $payload ) ) {
            fic_ensoniq_fail( sprintf( 'Could not write EFE file: %s', $efe_path ) );
        }

        printf( "Extracted %s\n", basename( $efe_path ) );
        $count++;
    }

    return $count;
}

if ( $argc < 3 ) {
    fic_ensoniq_fail( 'Usage: php extract-ensoniq-efe.php <image.img> <output-directory>' );
}

$input_path = $argv[1];
$output_dir = rtrim( $argv[2], '/' );

if ( ! is_file( $input_path ) || ! is_readable( $input_path ) ) {
    fic_ensoniq_fail( sprintf( 'Input image is not readable: %s', $input_path ) );
}

fic_ensoniq_prepare_directory( $output_dir );

$image = file_get_contents( $input_path );
if ( false === $image ) {
    fic_ensoniq_fail( sprintf( 'Could not read input image: %s', $input_path ) );
}

$volume = fic_ensoniq_detect_volume( $image );
$label  = '' !== $volume['label'] ? sprintf( ', label "%s"', $volume['label'] ) : '';

printf( "Detected %s volume (%d blocks%s).\n", $volume['type'], $volume['total_blocks'], $label );

$count = fic_ensoniq_extract_directory(
    $image,
    $volume,
    FIC_ENSONIQ_DIR_START_BLOCK,
    FIC_ENSONIQ_DIR_BLOCKS,
    $output_dir,
    0,
    [],
    0
);

if ( 0 === $count ) {
    fic_ensoniq_fail( 'No exportable EFE files were found in the Ensoniq image.' );
}

printf( "Extracted %d EFE file(s).\n", $count );
