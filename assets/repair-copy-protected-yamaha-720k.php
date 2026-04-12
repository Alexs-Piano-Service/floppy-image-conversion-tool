<?php
/**
 * Prepare a 720 KB Yamaha/FAT12 floppy image for extraction.
 *
 * Some copy-protected Yamaha disks blank or remove sector 0. Archive tools still
 * need a valid FAT12 boot sector, so this helper rebuilds a generic one when the
 * rest of the 720 KB filesystem is intact. Non-matching images are copied through
 * unchanged.
 */

if ( PHP_SAPI !== 'cli' ) {
    exit( 1 );
}

const FIC_YAMAHA_BYTES_PER_SECTOR   = 512;
const FIC_YAMAHA_SECTORS_PER_CLUSTER = 2;
const FIC_YAMAHA_RESERVED_SECTORS   = 1;
const FIC_YAMAHA_NUM_FATS           = 2;
const FIC_YAMAHA_ROOT_ENTRIES       = 112;
const FIC_YAMAHA_TOTAL_SECTORS      = 1440;
const FIC_YAMAHA_MEDIA_DESCRIPTOR   = 0xF9;
const FIC_YAMAHA_SECTORS_PER_FAT    = 3;
const FIC_YAMAHA_SECTORS_PER_TRACK  = 9;
const FIC_YAMAHA_NUM_HEADS          = 2;
const FIC_YAMAHA_TOTAL_SIZE         = FIC_YAMAHA_TOTAL_SECTORS * FIC_YAMAHA_BYTES_PER_SECTOR;
const FIC_YAMAHA_ROOT_DIR_SECTORS   = 7;
const FIC_YAMAHA_BOOT_SIGNATURE     = "\x55\xAA";
const FIC_YAMAHA_FAT_SIGNATURE      = "\xF9\xFF\xFF";

/**
 * @param string $bytes  Binary string.
 * @param int    $offset Byte offset.
 *
 * @return int
 */
function fic_yamaha_u16le( $bytes, $offset ) {
    $value = unpack( 'v', substr( $bytes, $offset, 2 ) );

    return (int) $value[1];
}

/**
 * @param string $bytes  Binary string.
 * @param int    $offset Byte offset.
 *
 * @return int
 */
function fic_yamaha_byte_at( $bytes, $offset ) {
    return ord( $bytes[ $offset ] );
}

/**
 * @param string $sector0 First sector bytes.
 *
 * @return bool
 */
function fic_yamaha_looks_like_valid_720k_boot_sector( $sector0 ) {
    if ( FIC_YAMAHA_BYTES_PER_SECTOR !== strlen( $sector0 ) ) {
        return false;
    }

    if ( FIC_YAMAHA_BOOT_SIGNATURE !== substr( $sector0, 510, 2 ) ) {
        return false;
    }

    return (
        FIC_YAMAHA_BYTES_PER_SECTOR === fic_yamaha_u16le( $sector0, 11 )
        && FIC_YAMAHA_SECTORS_PER_CLUSTER === fic_yamaha_byte_at( $sector0, 13 )
        && FIC_YAMAHA_RESERVED_SECTORS === fic_yamaha_u16le( $sector0, 14 )
        && FIC_YAMAHA_NUM_FATS === fic_yamaha_byte_at( $sector0, 16 )
        && FIC_YAMAHA_ROOT_ENTRIES === fic_yamaha_u16le( $sector0, 17 )
        && FIC_YAMAHA_TOTAL_SECTORS === fic_yamaha_u16le( $sector0, 19 )
        && FIC_YAMAHA_MEDIA_DESCRIPTOR === fic_yamaha_byte_at( $sector0, 21 )
        && FIC_YAMAHA_SECTORS_PER_FAT === fic_yamaha_u16le( $sector0, 22 )
        && FIC_YAMAHA_SECTORS_PER_TRACK === fic_yamaha_u16le( $sector0, 24 )
        && FIC_YAMAHA_NUM_HEADS === fic_yamaha_u16le( $sector0, 26 )
    );
}

/**
 * @param string $data   Image bytes.
 * @param int    $offset Byte offset.
 *
 * @return bool
 */
function fic_yamaha_fat_signature_at( $data, $offset ) {
    return (
        $offset >= 0
        && $offset + strlen( FIC_YAMAHA_FAT_SIGNATURE ) <= strlen( $data )
        && FIC_YAMAHA_FAT_SIGNATURE === substr( $data, $offset, strlen( FIC_YAMAHA_FAT_SIGNATURE ) )
    );
}

/**
 * @param string $raw_name Raw FAT directory name.
 *
 * @return bool
 */
function fic_yamaha_entry_name_looks_plausible( $raw_name ) {
    $allowed = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789$%\'-_@~`!(){}^#& ';
    $length  = strlen( $raw_name );

    for ( $i = 0; $i < $length; $i++ ) {
        if ( false === strpos( $allowed, $raw_name[ $i ] ) ) {
            return false;
        }
    }

    return true;
}

/**
 * @param string $data   Image bytes.
 * @param int    $offset Root directory offset.
 *
 * @return bool
 */
function fic_yamaha_root_dir_looks_plausible( $data, $offset ) {
    $end = $offset + FIC_YAMAHA_ROOT_DIR_SECTORS * FIC_YAMAHA_BYTES_PER_SECTOR;

    if ( $end > strlen( $data ) ) {
        return false;
    }

    $found = 0;

    for ( $i = $offset; $i < $end; $i += 32 ) {
        $entry = substr( $data, $i, 32 );
        $first = fic_yamaha_byte_at( $entry, 0 );
        $attr  = fic_yamaha_byte_at( $entry, 11 );

        if ( 0x00 === $first ) {
            break;
        }

        if ( 0xE5 === $first ) {
            continue;
        }

        if ( 0x0F === $attr ) {
            $found++;
            continue;
        }

        if ( $attr & 0xC0 ) {
            return false;
        }

        if ( ! fic_yamaha_entry_name_looks_plausible( substr( $entry, 0, 11 ) ) ) {
            return false;
        }

        $found++;
    }

    return $found > 0;
}

/**
 * @param string $data Image bytes.
 *
 * @return array<string, int|string>|null
 */
function fic_yamaha_detect_image_layout( $data ) {
    $size = strlen( $data );

    if (
        FIC_YAMAHA_TOTAL_SIZE === $size
        && fic_yamaha_looks_like_valid_720k_boot_sector( substr( $data, 0, FIC_YAMAHA_BYTES_PER_SECTOR ) )
    ) {
        return [
            'mode'        => 'already_valid',
            'fat1_offset' => FIC_YAMAHA_BYTES_PER_SECTOR,
            'fat2_offset' => ( 1 + FIC_YAMAHA_SECTORS_PER_FAT ) * FIC_YAMAHA_BYTES_PER_SECTOR,
            'root_offset' => ( 1 + FIC_YAMAHA_NUM_FATS * FIC_YAMAHA_SECTORS_PER_FAT ) * FIC_YAMAHA_BYTES_PER_SECTOR,
            'notes'       => 'valid 720 KB FAT12 boot sector already present',
        ];
    }

    if (
        FIC_YAMAHA_TOTAL_SIZE === $size
        && fic_yamaha_fat_signature_at( $data, FIC_YAMAHA_BYTES_PER_SECTOR )
        && fic_yamaha_fat_signature_at( $data, ( 1 + FIC_YAMAHA_SECTORS_PER_FAT ) * FIC_YAMAHA_BYTES_PER_SECTOR )
        && fic_yamaha_root_dir_looks_plausible( $data, ( 1 + FIC_YAMAHA_NUM_FATS * FIC_YAMAHA_SECTORS_PER_FAT ) * FIC_YAMAHA_BYTES_PER_SECTOR )
    ) {
        return [
            'mode'        => 'replace_sector0',
            'fat1_offset' => FIC_YAMAHA_BYTES_PER_SECTOR,
            'fat2_offset' => ( 1 + FIC_YAMAHA_SECTORS_PER_FAT ) * FIC_YAMAHA_BYTES_PER_SECTOR,
            'root_offset' => ( 1 + FIC_YAMAHA_NUM_FATS * FIC_YAMAHA_SECTORS_PER_FAT ) * FIC_YAMAHA_BYTES_PER_SECTOR,
            'notes'       => 'sector 0 appears blank/corrupt; FATs and root directory are intact',
        ];
    }

    if (
        FIC_YAMAHA_TOTAL_SIZE - FIC_YAMAHA_BYTES_PER_SECTOR === $size
        && fic_yamaha_fat_signature_at( $data, 0 )
        && fic_yamaha_fat_signature_at( $data, FIC_YAMAHA_SECTORS_PER_FAT * FIC_YAMAHA_BYTES_PER_SECTOR )
        && fic_yamaha_root_dir_looks_plausible( $data, FIC_YAMAHA_NUM_FATS * FIC_YAMAHA_SECTORS_PER_FAT * FIC_YAMAHA_BYTES_PER_SECTOR )
    ) {
        return [
            'mode'        => 'prepend_sector0',
            'fat1_offset' => 0,
            'fat2_offset' => FIC_YAMAHA_SECTORS_PER_FAT * FIC_YAMAHA_BYTES_PER_SECTOR,
            'root_offset' => FIC_YAMAHA_NUM_FATS * FIC_YAMAHA_SECTORS_PER_FAT * FIC_YAMAHA_BYTES_PER_SECTOR,
            'notes'       => 'first sector appears omitted; image needs a sector prepended',
        ];
    }

    return null;
}

/**
 * @param string $root_dir Root directory bytes.
 *
 * @return string|null
 */
function fic_yamaha_find_volume_label( $root_dir ) {
    $length = strlen( $root_dir );

    for ( $i = 0; $i < $length; $i += 32 ) {
        $entry = substr( $root_dir, $i, 32 );
        $first = fic_yamaha_byte_at( $entry, 0 );

        if ( 0x00 === $first ) {
            break;
        }

        if ( 0xE5 === $first ) {
            continue;
        }

        if ( 0x08 === fic_yamaha_byte_at( $entry, 11 ) ) {
            return substr( $entry, 0, 11 );
        }
    }

    return null;
}

/**
 * @param string|null $label FAT volume label.
 *
 * @return string
 */
function fic_yamaha_normalize_label( $label ) {
    $text = trim( (string) ( $label ?: 'NO NAME' ) );

    if ( '' === $text ) {
        $text = 'NO NAME';
    }

    $text = preg_replace( '/[^\x20-\x7E]/', ' ', $text );
    $text = strtoupper( (string) $text );

    return str_pad( substr( $text, 0, 11 ), 11, ' ' );
}

/**
 * @param int         $serial       FAT serial number.
 * @param string|null $volume_label FAT volume label.
 *
 * @return string
 */
function fic_yamaha_build_standard_boot_sector( $serial, $volume_label ) {
    $boot = str_repeat( "\0", FIC_YAMAHA_BYTES_PER_SECTOR );

    $write = static function ( $offset, $bytes ) use ( &$boot ) {
        $boot = substr_replace( $boot, $bytes, $offset, strlen( $bytes ) );
    };

    $write( 0, "\xEB\x3C\x90" );
    $write( 3, 'MSDOS5.0' );
    $write( 11, pack( 'v', FIC_YAMAHA_BYTES_PER_SECTOR ) );
    $boot[13] = chr( FIC_YAMAHA_SECTORS_PER_CLUSTER );
    $write( 14, pack( 'v', FIC_YAMAHA_RESERVED_SECTORS ) );
    $boot[16] = chr( FIC_YAMAHA_NUM_FATS );
    $write( 17, pack( 'v', FIC_YAMAHA_ROOT_ENTRIES ) );
    $write( 19, pack( 'v', FIC_YAMAHA_TOTAL_SECTORS ) );
    $boot[21] = chr( FIC_YAMAHA_MEDIA_DESCRIPTOR );
    $write( 22, pack( 'v', FIC_YAMAHA_SECTORS_PER_FAT ) );
    $write( 24, pack( 'v', FIC_YAMAHA_SECTORS_PER_TRACK ) );
    $write( 26, pack( 'v', FIC_YAMAHA_NUM_HEADS ) );
    $write( 28, pack( 'V', 0 ) );
    $write( 32, pack( 'V', 0 ) );
    $boot[36] = chr( 0x00 );
    $boot[37] = chr( 0x00 );
    $boot[38] = chr( 0x29 );
    $write( 39, pack( 'V', $serial ) );
    $write( 43, fic_yamaha_normalize_label( $volume_label ) );
    $write( 54, 'FAT12   ' );
    $write( 510, FIC_YAMAHA_BOOT_SIGNATURE );

    return $boot;
}

/**
 * @param string $input_path  Source image.
 * @param string $output_path Extractable image path.
 *
 * @return int
 */
function fic_yamaha_prepare_extractable_image( $input_path, $output_path ) {
    if ( ! is_file( $input_path ) || ! is_readable( $input_path ) ) {
        fwrite( STDERR, "ERROR: Input image is not readable.\n" );

        return 1;
    }

    $data = file_get_contents( $input_path );

    if ( false === $data ) {
        fwrite( STDERR, "ERROR: Could not read input image.\n" );

        return 1;
    }

    $detection = fic_yamaha_detect_image_layout( $data );

    if ( null === $detection ) {
        if ( ! copy( $input_path, $output_path ) ) {
            fwrite( STDERR, "ERROR: Could not prepare pass-through image.\n" );

            return 1;
        }

        echo "Copy-protection repair: no matching 720 KB Yamaha/FAT12 repair needed.\n";

        return 0;
    }

    if ( 'already_valid' === $detection['mode'] ) {
        if ( ! copy( $input_path, $output_path ) ) {
            fwrite( STDERR, "ERROR: Could not prepare valid FAT12 image.\n" );

            return 1;
        }

        echo 'Copy-protection repair: ' . $detection['notes'] . "; using original image.\n";

        return 0;
    }

    $root_dir = substr(
        $data,
        (int) $detection['root_offset'],
        FIC_YAMAHA_ROOT_DIR_SECTORS * FIC_YAMAHA_BYTES_PER_SECTOR
    );
    $serial   = (int) sprintf( '%u', crc32( substr( $data, (int) $detection['fat1_offset'] ) ) );
    $boot     = fic_yamaha_build_standard_boot_sector( $serial, fic_yamaha_find_volume_label( $root_dir ) );

    if ( 'prepend_sector0' === $detection['mode'] ) {
        $repaired = $boot . $data;
    } else {
        $repaired = $boot . substr( $data, FIC_YAMAHA_BYTES_PER_SECTOR );
    }

    if ( FIC_YAMAHA_TOTAL_SIZE !== strlen( $repaired ) ) {
        fwrite( STDERR, "ERROR: Repaired image has an unexpected size.\n" );

        return 1;
    }

    if ( false === file_put_contents( $output_path, $repaired ) ) {
        fwrite( STDERR, "ERROR: Could not write repaired image.\n" );

        return 1;
    }

    echo 'Copy-protection repair: ' . $detection['notes'] . "; wrote repaired 720 KB FAT12 image.\n";

    return 0;
}

if ( $argc < 3 ) {
    fwrite( STDERR, "Usage: php repair-copy-protected-yamaha-720k.php <input.img> <output.img>\n" );
    exit( 2 );
}

exit( fic_yamaha_prepare_extractable_image( $argv[1], $argv[2] ) );
