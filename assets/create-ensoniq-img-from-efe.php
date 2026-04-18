<?php
/**
 * Create a blank Ensoniq EPS/ASR disk image and store one EFE file in it.
 *
 * The image formatting and EFE placement follow EpsLin Neo's FormatMedia and
 * PutEFE behavior for file-backed Ensoniq EPS/EPS16+/ASR images.
 */

if ( PHP_SAPI !== 'cli' ) {
    exit( 1 );
}

const FIC_EFE_IMG_BLOCK_SIZE      = 512;
const FIC_EFE_IMG_ID_BLOCK        = 1;
const FIC_EFE_IMG_OS_BLOCK        = 2;
const FIC_EFE_IMG_DIR_START_BLOCK = 3;
const FIC_EFE_IMG_DIR_END_BLOCK   = 4;
const FIC_EFE_IMG_EFE_ENTRY_SIZE  = 26;
const FIC_EFE_IMG_FAT_START_BLOCK = 5;
const FIC_EFE_IMG_FAT_ENTRIES_BLK = 170;
const FIC_EFE_IMG_FAT_EOF         = 1;
const FIC_EFE_IMG_DEFAULT_LABEL   = 'DISK000';
const FIC_EFE_IMG_OS_TYPES        = [
    1  => 0x3A8,
    27 => 0x390,
    32 => 0x6F2,
];
const FIC_EFE_IMG_FORMATS         = [
    'ensoniq.800'  => [
        'name'   => 'EPS/EPS16 800K',
        'bytes'  => 819200,
        'tracks' => 80,
        'nsect'  => 10,
    ],
    'ensoniq.1600' => [
        'name'   => 'ASR 1600K',
        'bytes'  => 1638400,
        'tracks' => 80,
        'nsect'  => 20,
    ],
];

/**
 * @param string $message Error message.
 *
 * @return never
 */
function fic_efe_img_fail( $message ) {
    fwrite( STDERR, 'ERROR: ' . $message . PHP_EOL );
    exit( 1 );
}

/**
 * @param string $image  Disk image bytes.
 * @param int    $offset Byte offset.
 * @param string $bytes  Bytes to write.
 *
 * @return void
 */
function fic_efe_img_write_at( &$image, $offset, $bytes ) {
    $image = substr_replace( $image, $bytes, $offset, strlen( $bytes ) );
}

/**
 * @param string $bytes  Binary string.
 * @param int    $offset Byte offset.
 *
 * @return int
 */
function fic_efe_img_byte_at( $bytes, $offset ) {
    if ( $offset < 0 || $offset >= strlen( $bytes ) ) {
        fic_efe_img_fail( 'Unexpected end of EFE while reading byte data.' );
    }

    return ord( $bytes[ $offset ] );
}

/**
 * @param string $bytes  Binary string.
 * @param int    $offset Byte offset.
 *
 * @return int
 */
function fic_efe_img_u16be( $bytes, $offset ) {
    if ( $offset < 0 || $offset + 2 > strlen( $bytes ) ) {
        fic_efe_img_fail( 'Unexpected end of EFE while reading 16-bit data.' );
    }

    $value = unpack( 'n', substr( $bytes, $offset, 2 ) );

    return (int) $value[1];
}

/**
 * @param int $value Integer value.
 *
 * @return string
 */
function fic_efe_img_u16be_bytes( $value ) {
    return pack( 'n', $value & 0xFFFF );
}

/**
 * @param int $value Integer value.
 *
 * @return string
 */
function fic_efe_img_u32be_bytes( $value ) {
    return pack( 'N', $value );
}

/**
 * @param string $efe Raw EFE bytes.
 *
 * @return string
 */
function fic_efe_img_normalize_mac_efe( $efe ) {
    if ( substr( $efe, 0, 3 ) !== "\x0D\x0D\x0A" ) {
        return $efe;
    }

    $normalized = '';
    $prev       = 0;
    $length     = strlen( $efe );

    for ( $i = 0; $i < $length; $i++ ) {
        if ( "\x0A" === $efe[ $i ] ) {
            $chunk_length = $i - $prev - 1;
            if ( $chunk_length > 0 ) {
                $normalized .= substr( $efe, $prev, $chunk_length );
            }
            $prev = $i;
        }
    }

    $normalized .= substr( $efe, $prev );

    return $normalized;
}

/**
 * @param string $raw_name Raw 12-byte EFE name.
 *
 * @return string
 */
function fic_efe_img_name_for_log( $raw_name ) {
    $name = str_replace( "\0", '', $raw_name );
    $name = preg_replace( '/[^\x20-\x7E]/', ' ', $name );
    $name = trim( preg_replace( '/\s+/', ' ', (string) $name ) );

    return '' === $name ? 'UNTITLED' : $name;
}

/**
 * @param string $efe Raw EFE bytes.
 *
 * @return array{type:int,name_raw:string,blocks:int,multifile:int,payload:string,os_version:string}
 */
function fic_efe_img_parse_efe( $efe ) {
    $efe = fic_efe_img_normalize_mac_efe( $efe );

    if ( strlen( $efe ) < FIC_EFE_IMG_BLOCK_SIZE ) {
        fic_efe_img_fail( 'EFE file is shorter than its 512-byte header.' );
    }

    if (
        substr( $efe, 0, 2 ) !== "\x0D\x0A"
        || substr( $efe, 47, 3 ) !== "\x0D\x0A\x1A"
    ) {
        fic_efe_img_fail( 'Input does not have a valid EFE header signature.' );
    }

    $type       = fic_efe_img_byte_at( $efe, 50 );
    $blocks     = fic_efe_img_u16be( $efe, 52 );
    $multifile  = fic_efe_img_byte_at( $efe, 58 );
    $payload    = substr( $efe, FIC_EFE_IMG_BLOCK_SIZE );
    $needed_len = $blocks * FIC_EFE_IMG_BLOCK_SIZE;

    if ( $type <= 0 || 2 === $type || 8 === $type || $type > 49 ) {
        fic_efe_img_fail( sprintf( 'EFE type %d cannot be stored as an exportable Ensoniq file.', $type ) );
    }

    if ( $blocks <= 0 ) {
        fic_efe_img_fail( 'EFE block count must be greater than zero.' );
    }

    if ( strlen( $payload ) < $needed_len ) {
        fic_efe_img_fail( 'EFE payload is shorter than the block count declared in its header.' );
    }

    if ( strlen( $payload ) > $needed_len ) {
        $payload = substr( $payload, 0, $needed_len );
    }

    $os_version = '';
    if ( isset( FIC_EFE_IMG_OS_TYPES[ $type ] ) ) {
        $offset = FIC_EFE_IMG_OS_TYPES[ $type ];
        if ( $offset + 4 <= strlen( $efe ) ) {
            $os_version = substr( $efe, $offset, 4 );
        }
    }

    return [
        'type'       => $type,
        'name_raw'   => substr( $efe, 18, 12 ),
        'blocks'     => $blocks,
        'multifile'  => $multifile,
        'payload'    => $payload,
        'os_version' => $os_version,
    ];
}

/**
 * @param string $label Disk label.
 *
 * @return string
 */
function fic_efe_img_normalize_label( $label ) {
    $label = strtoupper( preg_replace( '/[^\x20-\x7E]/', ' ', $label ) );
    $label = preg_replace( '/\s+/', '', (string) $label );

    if ( '' === $label ) {
        $label = FIC_EFE_IMG_DEFAULT_LABEL;
    }

    return str_pad( substr( $label, 0, 7 ), 7, ' ' );
}

/**
 * @param string                $image        Disk image bytes.
 * @param array{name:string,bytes:int,tracks:int,nsect:int} $format       Disk format.
 * @param int                   $free_blocks  Free block count.
 * @param string                $label        Disk label.
 *
 * @return void
 */
function fic_efe_img_write_system_blocks( &$image, array $format, $free_blocks, $label ) {
    $total_blocks = (int) ( $format['bytes'] / FIC_EFE_IMG_BLOCK_SIZE );

    $null = '';
    for ( $i = 0; $i < FIC_EFE_IMG_BLOCK_SIZE; $i += 2 ) {
        $null .= "\x6D\xB6";
    }
    fic_efe_img_write_at( $image, 0, $null );

    $id = str_repeat( "\0", FIC_EFE_IMG_BLOCK_SIZE );
    $id[1] = "\x80";
    $id[2] = "\x01";
    fic_efe_img_write_at( $id, 4, fic_efe_img_u16be_bytes( $format['nsect'] ) );
    fic_efe_img_write_at( $id, 6, fic_efe_img_u16be_bytes( 2 ) );
    fic_efe_img_write_at( $id, 8, fic_efe_img_u16be_bytes( $format['tracks'] ) );
    fic_efe_img_write_at( $id, 10, fic_efe_img_u32be_bytes( FIC_EFE_IMG_BLOCK_SIZE ) );
    fic_efe_img_write_at( $id, 14, fic_efe_img_u32be_bytes( $total_blocks ) );
    $id[18] = "\x1E";
    $id[19] = "\x02";
    $id[30] = "\xFF";
    fic_efe_img_write_at( $id, 31, fic_efe_img_normalize_label( $label ) );
    fic_efe_img_write_at( $id, 38, 'ID' );
    fic_efe_img_write_at( $image, FIC_EFE_IMG_ID_BLOCK * FIC_EFE_IMG_BLOCK_SIZE, $id );

    $os = str_repeat( "\0", FIC_EFE_IMG_BLOCK_SIZE );
    fic_efe_img_write_at( $os, 0, fic_efe_img_u32be_bytes( $free_blocks ) );
    fic_efe_img_write_at( $os, 28, 'OS' );
    fic_efe_img_write_at( $image, FIC_EFE_IMG_OS_BLOCK * FIC_EFE_IMG_BLOCK_SIZE, $os );

    $dir_a = str_repeat( "\0", FIC_EFE_IMG_BLOCK_SIZE );
    $dir_b = str_repeat( "\0", FIC_EFE_IMG_BLOCK_SIZE );
    fic_efe_img_write_at( $dir_b, 510, 'DR' );
    fic_efe_img_write_at( $image, FIC_EFE_IMG_DIR_START_BLOCK * FIC_EFE_IMG_BLOCK_SIZE, $dir_a );
    fic_efe_img_write_at( $image, FIC_EFE_IMG_DIR_END_BLOCK * FIC_EFE_IMG_BLOCK_SIZE, $dir_b );
}

/**
 * @param string $image Disk image bytes.
 * @param int    $block Block index.
 * @param int    $value FAT value.
 *
 * @return void
 */
function fic_efe_img_put_fat_entry( &$image, $block, $value ) {
    $fat_sector = (int) floor( $block / FIC_EFE_IMG_FAT_ENTRIES_BLK );
    $fat_pos    = $block % FIC_EFE_IMG_FAT_ENTRIES_BLK;
    $offset     = ( FIC_EFE_IMG_FAT_START_BLOCK + $fat_sector ) * FIC_EFE_IMG_BLOCK_SIZE + $fat_pos * 3;

    fic_efe_img_write_at(
        $image,
        $offset,
        chr( ( $value >> 16 ) & 0xFF ) . chr( ( $value >> 8 ) & 0xFF ) . chr( $value & 0xFF )
    );
}

/**
 * @param string $image     Disk image bytes.
 * @param int    $fat_blocks Number of FAT blocks.
 *
 * @return void
 */
function fic_efe_img_init_fat( &$image, $fat_blocks ) {
    for ( $i = 0; $i < $fat_blocks; $i++ ) {
        $block = str_repeat( "\0", FIC_EFE_IMG_BLOCK_SIZE );
        fic_efe_img_write_at( $block, 510, 'FB' );
        fic_efe_img_write_at( $image, ( FIC_EFE_IMG_FAT_START_BLOCK + $i ) * FIC_EFE_IMG_BLOCK_SIZE, $block );
    }

    $overhead_blocks = FIC_EFE_IMG_FAT_START_BLOCK + $fat_blocks;
    for ( $block = 0; $block < $overhead_blocks; $block++ ) {
        fic_efe_img_put_fat_entry( $image, $block, FIC_EFE_IMG_FAT_EOF );
    }
}

/**
 * @param array{type:int,name_raw:string,blocks:int,multifile:int} $efe         Parsed EFE metadata.
 * @param int                                                      $start_block First data block.
 *
 * @return string
 */
function fic_efe_img_build_dir_entry( array $efe, $start_block ) {
    $entry = str_repeat( "\0", FIC_EFE_IMG_EFE_ENTRY_SIZE );

    $entry[1] = chr( $efe['type'] );
    fic_efe_img_write_at( $entry, 2, str_pad( substr( $efe['name_raw'], 0, 12 ), 12, "\0" ) );
    fic_efe_img_write_at( $entry, 14, fic_efe_img_u16be_bytes( $efe['blocks'] ) );
    fic_efe_img_write_at( $entry, 16, fic_efe_img_u16be_bytes( $efe['blocks'] ) );
    fic_efe_img_write_at( $entry, 18, fic_efe_img_u32be_bytes( $start_block ) );
    $entry[22] = chr( $efe['multifile'] );

    return $entry;
}

/**
 * @param string $image        Disk image bytes.
 * @param array{type:int,name_raw:string,blocks:int,multifile:int,payload:string,os_version:string} $efe Parsed EFE data.
 * @param int    $fat_blocks   Number of FAT blocks.
 * @param int    $free_blocks  Free block count.
 *
 * @return int New free block count.
 */
function fic_efe_img_store_efe( &$image, array $efe, $fat_blocks, $free_blocks ) {
    if ( $efe['blocks'] > $free_blocks ) {
        fic_efe_img_fail( sprintf( 'Not enough free space: %d blocks needed, %d available.', $efe['blocks'], $free_blocks ) );
    }

    $start_block = FIC_EFE_IMG_FAT_START_BLOCK + $fat_blocks;

    fic_efe_img_write_at( $image, $start_block * FIC_EFE_IMG_BLOCK_SIZE, $efe['payload'] );

    for ( $i = 0; $i < $efe['blocks'] - 1; $i++ ) {
        fic_efe_img_put_fat_entry( $image, $start_block + $i, $start_block + $i + 1 );
    }
    fic_efe_img_put_fat_entry( $image, $start_block + $efe['blocks'] - 1, FIC_EFE_IMG_FAT_EOF );

    $entry_index  = isset( FIC_EFE_IMG_OS_TYPES[ $efe['type'] ] ) ? 0 : 1;
    $entry_offset = FIC_EFE_IMG_DIR_START_BLOCK * FIC_EFE_IMG_BLOCK_SIZE + FIC_EFE_IMG_EFE_ENTRY_SIZE * $entry_index;
    fic_efe_img_write_at( $image, $entry_offset, fic_efe_img_build_dir_entry( $efe, $start_block ) );

    $free_blocks -= $efe['blocks'];
    fic_efe_img_write_at( $image, FIC_EFE_IMG_OS_BLOCK * FIC_EFE_IMG_BLOCK_SIZE, fic_efe_img_u32be_bytes( $free_blocks ) );

    if ( '' !== $efe['os_version'] ) {
        fic_efe_img_write_at( $image, FIC_EFE_IMG_OS_BLOCK * FIC_EFE_IMG_BLOCK_SIZE + 4, $efe['os_version'] );
    }

    return $free_blocks;
}

if ( $argc < 4 ) {
    fic_efe_img_fail( 'Usage: php create-ensoniq-img-from-efe.php <input.efe> <output.img> <ensoniq.800|ensoniq.1600> [disk-label]' );
}

$input_path = $argv[1];
$output_path = $argv[2];
$diskdef = strtolower( $argv[3] );
$label = isset( $argv[4] ) ? $argv[4] : FIC_EFE_IMG_DEFAULT_LABEL;

if ( ! isset( FIC_EFE_IMG_FORMATS[ $diskdef ] ) ) {
    fic_efe_img_fail( sprintf( 'Unsupported Ensoniq disk definition: %s', $diskdef ) );
}

if ( ! is_file( $input_path ) || ! is_readable( $input_path ) ) {
    fic_efe_img_fail( sprintf( 'Input EFE is not readable: %s', $input_path ) );
}

$raw_efe = file_get_contents( $input_path );
if ( false === $raw_efe ) {
    fic_efe_img_fail( sprintf( 'Could not read input EFE: %s', $input_path ) );
}

$format = FIC_EFE_IMG_FORMATS[ $diskdef ];
$efe = fic_efe_img_parse_efe( $raw_efe );
$total_blocks = (int) ( $format['bytes'] / FIC_EFE_IMG_BLOCK_SIZE );
$fat_blocks = (int) ceil( $total_blocks / FIC_EFE_IMG_FAT_ENTRIES_BLK );
$free_blocks = $total_blocks - ( FIC_EFE_IMG_FAT_START_BLOCK + $fat_blocks );
$image = str_repeat( "\0", $format['bytes'] );

fic_efe_img_write_system_blocks( $image, $format, $free_blocks, $label );
fic_efe_img_init_fat( $image, $fat_blocks );
$free_blocks = fic_efe_img_store_efe( $image, $efe, $fat_blocks, $free_blocks );

$out_dir = dirname( $output_path );
if ( ! is_dir( $out_dir ) && ! mkdir( $out_dir, 0775, true ) && ! is_dir( $out_dir ) ) {
    fic_efe_img_fail( sprintf( 'Could not create output directory: %s', $out_dir ) );
}

if ( false === file_put_contents( $output_path, $image ) ) {
    fic_efe_img_fail( sprintf( 'Could not write output image: %s', $output_path ) );
}

printf(
    "Created %s image with EFE \"%s\" (%d block(s), %d free block(s) remaining).\n",
    $format['name'],
    fic_efe_img_name_for_log( $efe['name_raw'] ),
    $efe['blocks'],
    $free_blocks
);
