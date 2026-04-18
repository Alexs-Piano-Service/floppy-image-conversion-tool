<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Formats accepted as upload inputs.
 *
 * @return string[]
 */
function fic_allowed_input_formats() {
    return [
        'a2r', 'adf', 'ads', 'adm', 'adl', 'bin', 'ctr', 'd1m', 'd2m', 'd4m', 'd64', 'd71', 'd81', 'd88',
        'dcp', 'dim', 'dmk', 'do', 'dsd', 'dsk', 'edsk', 'efe', 'fd', 'fdi', 'hdm', 'hfe', 'ima', 'img',
        'imd', 'ipf', 'mgt', 'msa', 'nfd', 'nsi', 'po', 'raw', 'sf7', 'scp', 'ssd', 'st', 'td0', 'xdf',
    ];
}

/**
 * Formats accepted for API output.
 *
 * @return string[]
 */
function fic_allowed_output_formats() {
    return array_merge( fic_allowed_input_formats(), [ 'zip' ] );
}

/**
 * Disk definitions accepted by Greaseweazle.
 *
 * @return string[]
 */
function fic_allowed_diskdefs() {
    return [
        'acorn.adfs.160', 'acorn.adfs.1600', 'acorn.adfs.320', 'acorn.adfs.640', 'acorn.adfs.800',
        'acorn.dfs.ds', 'acorn.dfs.ds80', 'acorn.dfs.ss', 'acorn.dfs.ss80',
        'akai.1600', 'akai.800', 'amiga.amigados', 'amiga.amigados_hd',
        'apple2.appledos.140', 'apple2.nofs.140', 'apple2.prodos.140',
        'atari.90', 'atarist.360', 'atarist.400', 'atarist.440', 'atarist.720', 'atarist.800', 'atarist.880',
        'coco.decb', 'coco.decb.40t', 'coco.os9.40ds', 'coco.os9.40ss', 'coco.os9.80ds', 'coco.os9.80ss',
        'commodore.1541', 'commodore.1571', 'commodore.1581',
        'commodore.cmd.fd2000.dd', 'commodore.cmd.fd2000.hd', 'commodore.cmd.fd4000.ed',
        'datageneral.2f', 'dec.rx01', 'dec.rx02',
        'dragon.40ds', 'dragon.40ss', 'dragon.80ds', 'dragon.80ss',
        'ensoniq.1600', 'ensoniq.800', 'ensoniq.mirage',
        'epson.qx10.320', 'epson.qx10.396', 'epson.qx10.399', 'epson.qx10.400',
        'epson.qx10.booter', 'epson.qx10.logo', 'gem.1600', 'hp.mmfm.9885', 'hp.mmfm.9895',
        'ibm.1200', 'ibm.1440', 'ibm.160', 'ibm.1680', 'ibm.180', 'ibm.2880', 'ibm.320', 'ibm.360', 'ibm.720', 'ibm.800', 'ibm.dmf', 'ibm.scan',
        'kaypro.dsdd.40', 'kaypro.dsdd.80', 'kaypro.ssdd.40', 'mac.400', 'mac.800',
        'micropolis.100tpi.ds', 'micropolis.100tpi.ds.275', 'micropolis.100tpi.ss', 'micropolis.100tpi.ss.275',
        'micropolis.48tpi.ds', 'micropolis.48tpi.ds.275', 'micropolis.48tpi.ss', 'micropolis.48tpi.ss.275',
        'mm1.os9.80dshd_32', 'mm1.os9.80dshd_33', 'mm1.os9.80dshd_36', 'mm1.os9.80dshd_37',
        'msx.1d', 'msx.1dd', 'msx.2d', 'msx.2dd',
        'northstar.fm.ds', 'northstar.fm.ss', 'northstar.mfm.ds', 'northstar.mfm.ss',
        'occ1.dd', 'occ1.sd', 'olivetti.m20', 'pc98.2d', 'pc98.2dd', 'pc98.2hd', 'pc98.2hs', 'pc98.n88basic.hd',
        'raw.125', 'raw.250', 'raw.500', 'sci.prophet', 'sega.sf7000',
        'thomson.1s160', 'thomson.1s320', 'thomson.1s80', 'thomson.2s160', 'thomson.2s320',
        'tsc.flex.dsdd', 'tsc.flex.ssdd',
        'zx.3dos.ds80', 'zx.3dos.ss40', 'zx.d80.ds80', 'zx.fdd3000.ds80', 'zx.fdd3000.ss40',
        'zx.kempston.ds80', 'zx.kempston.ss40', 'zx.opus.ds80', 'zx.opus.ss40',
        'zx.plusd.ds80', 'zx.quorum.ds80', 'zx.rocky.ds80', 'zx.rocky.ss40',
        'zx.trdos.ds80', 'zx.turbodrive.ds40', 'zx.turbodrive.ds80', 'zx.watford.ds80', 'zx.watford.ss40',
    ];
}

/**
 * Disk definitions whose raw images can be exported as Ensoniq EFE files.
 *
 * @return string[]
 */
function fic_ensoniq_efe_diskdefs() {
    return [
        'ensoniq.800',
        'ensoniq.1600',
    ];
}

/**
 * Determine whether a disk definition uses the EPS/ASR filesystem supported by EFE extraction.
 *
 * @param string $diskdef Disk definition key.
 *
 * @return bool
 */
function fic_diskdef_supports_ensoniq_efe( $diskdef ) {
    return in_array( $diskdef, fic_ensoniq_efe_diskdefs(), true );
}

/**
 * Diskdefs shown in the basic UI mode.
 *
 * @return array<string, string>
 */
function fic_basic_diskdef_options() {
    return [
        'ibm.720'  => 'IBM 720 DD',
        'ibm.1440' => 'IBM 1440 HD',
    ];
}

/**
 * Output formats shown in the basic UI mode.
 *
 * @return array<string, string>
 */
function fic_basic_output_options() {
    return [
        'img' => 'IMG',
        'hfe' => 'HFE',
        'zip' => 'ZIP',
    ];
}

/**
 * Output formats shown in the advanced UI mode.
 *
 * @return array<string, string>
 */
function fic_advanced_output_options() {
    return [
        'zip'  => 'ZIP',
        'hfe'  => 'HFE',
        'img'  => 'IMG',
        'adf'  => 'ADF',
        'ads'  => 'ADS',
        'adm'  => 'ADM',
        'adl'  => 'ADL',
        'd1m'  => 'D1M',
        'd2m'  => 'D2M',
        'd4m'  => 'D4M',
        'd64'  => 'D64',
        'd71'  => 'D71',
        'd81'  => 'D81',
        'do'   => 'DO',
        'dsd'  => 'DSD',
        'dsk'  => 'DSK',
        'edsk' => 'EDSK',
        'efe'  => 'EFE',
        'fd'   => 'FD',
        'hdm'  => 'HDM',
        'ima'  => 'IMA',
        'imd'  => 'IMD',
        'mgt'  => 'MGT',
        'msa'  => 'MSA',
        'nsi'  => 'NSI',
        'po'   => 'PO',
        'sf7'  => 'SF7',
        'scp'  => 'SCP',
        'ssd'  => 'SSD',
        'st'   => 'ST',
        'xdf'  => 'XDF',
    ];
}

/**
 * Default disk definition used for UI and request fallback.
 *
 * @return string
 */
function fic_default_diskdef() {
    return 'ibm.720';
}

/**
 * Default output format used for UI and request fallback.
 *
 * @return string
 */
function fic_default_output_format() {
    return 'img';
}

/**
 * Human-friendly labels for selected disk definitions.
 *
 * @param string $diskdef Disk definition key.
 *
 * @return string
 */
function fic_diskdef_label( $diskdef ) {
    $labels = [
        'ibm.720'      => 'IBM 720 DD',
        'ibm.1440'     => 'IBM 1440 HD',
        'ensoniq.800'  => 'Ensoniq EPS/EPS16 800K',
        'ensoniq.1600' => 'Ensoniq ASR 1600K',
    ];

    return isset( $labels[ $diskdef ] ) ? $labels[ $diskdef ] : $diskdef;
}
