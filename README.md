# Floppy Image Converter (WordPress Plugin)

Convert floppy disk image files asynchronously using Greaseweazle from WordPress.
This plugin exposes a REST API for conversion jobs, polling status, and downloading outputs.

## Features

- Asynchronous conversion jobs using background shell execution
- REST API endpoints for `convert` and `status`
- Built-in optional ClamAV scan on upload
- Support for many legacy floppy image formats
- Optional ZIP extraction workflow (`7z` + `zip`)
- Automatic repair for 720 KB Yamaha/FAT12 images with a blank or omitted first sector before ZIP extraction
- Ensoniq EPS/EPS16/ASR EFE import/export via intermediate IMG images
- Ensoniq EPS/EPS16 EDE disk-image import/export via intermediate IMG images
- Shortcodes for rendering a converter form and progress bar
- Daily cleanup of old conversion artifacts

## Requirements

- WordPress (current supported version)
- PHP with `exec()` available
- Greaseweazle CLI (`gw`) installed on the server (required)
- `7z` and `zip` CLI tools available for ZIP output
- PHP CLI available to the web process for Yamaha/FAT12 copy-protection repair and Ensoniq EFE/EDE conversion; set the `fic_php_cli_path` filter if needed
- Standard shell tools (`find`, `rm`, `ps`)
- `clamdscan` (optional, used when available)

## Installation

1. Copy this plugin folder into `wp-content/plugins/floppy-image-conversion-tool`.
2. Activate **Floppy Image Converter** in WordPress admin.
3. Ensure server paths for `gw` and optional `clamdscan` are valid.
4. Place a shortcode in a page, or call the REST API directly.

## Shortcodes

- `[floppy-converter-form]`
- `[floppy-image-converter]`
- `[progress-bar]`

`[floppy-converter-form]` supports:

- `show_advanced="1"` (default)
- `show_advanced="0"`

Example:

```text
[floppy-converter-form show_advanced="0"]
```

Frontend note:

- This plugin provides the form markup/CSS and API endpoints.
- Wire the form submit + polling behavior from your theme/plugin JavaScript using the IDs in `templates/converter-form.php`.

## REST API

Namespace: `floppy/v1`

### POST `/wp-json/floppy/v1/convert`

Starts a new conversion job.

Required fields:

- `file` (multipart file upload)
- `out_fmt`
- `diskdef`

Example:

```bash
curl -X POST "https://example.com/wp-json/floppy/v1/convert" \
  -F "file=@/path/to/disk.img" \
  -F "out_fmt=zip" \
  -F "diskdef=ibm.720"
```

Typical success response:

```json
{
  "job_id": "f7d0f8f0-13cc-4a2b-b750-2f600c1f7f57",
  "download_url": "https://example.com/wp-content/uploads/floppy-convert/f7d0f8f0-13cc-4a2b-b750-2f600c1f7f57.zip"
}
```

### GET `/wp-json/floppy/v1/status?job_id=...`

Polls job state.

Possible `status` values:

- `processing`
- `complete`
- `error`

Typical `processing` response:

```json
{
  "status": "processing",
  "message": "STEP 3/6",
  "percent": 58,
  "step": 3,
  "step_total": 6,
  "phase": "Extracting files from intermediate image"
}
```

Typical `complete` response:

```json
{
  "status": "complete",
  "download_url": "https://example.com/wp-content/uploads/floppy-convert/<job>.<fmt>"
}
```

Typical `error` response:

```json
{
  "status": "error",
  "message": "No files extracted from IMG (nothing to zip).",
  "log_tail": ["..."]
}
```

## Supported formats

Input formats:

- `a2r, adf, ads, adm, adl, bin, ctr, d1m, d2m, d4m, d64, d71, d81, d88, dcp, dim, dmk, do, dsd, dsk, ede, edsk, efe, fd, fdi, hdm, hfe, ima, img, imd, ipf, mgt, msa, nfd, nsi, po, raw, sf7, scp, ssd, st, td0, xdf`

Output formats:

- All input formats above
- `zip`

Ensoniq EFE notes:

- Uploading an `.efe` first creates a temporary Ensoniq IMG using the selected `ensoniq.800` or `ensoniq.1600` disk definition, then passes that IMG to Greaseweazle for the requested output.
- When `out_fmt=efe`, the selected Ensoniq image must contain exactly one exportable file. Use `out_fmt=zip` for disks with multiple files.
- When `out_fmt=zip` and `diskdef` is `ensoniq.800` or `ensoniq.1600`, the ZIP contains extracted `.efe` files from the Ensoniq EPS/EPS16/ASR filesystem.
- Other ZIP jobs continue to use the generic archive extraction path via `7z`.

Ensoniq EDE notes:

- EDE is a compact Giebler EPS/EPS16 disk-image container, not a single-file EFE container.
- EDE conversion requires `diskdef=ensoniq.800`; the ASR 1600K sibling format is EDA.
- Uploading `.ede` first expands it to a temporary raw Ensoniq IMG, then passes that IMG to Greaseweazle for other output formats.
- When `out_fmt=ede`, the intermediate IMG is compacted back to EDE after conversion.

Disk definitions:

- Includes a large built-in list (IBM, Amiga, Acorn, ZX, etc.) in `includes/config.php` via `fic_allowed_diskdefs()`.

## Configuration

Override executable paths via WordPress filters:

```php
add_filter( 'fic_greaseweazle_cli_path', function () {
    return '/opt/greaseweazle/.venv/bin/gw';
} );

add_filter( 'fic_clamdscan_path', function () {
    return '/usr/bin/clamdscan';
} );

add_filter( 'fic_php_cli_path', function () {
    return '/usr/bin/php';
} );
```

## Storage, cleanup, and job lifetime

- Converted files/logs are stored under: `wp-content/uploads/floppy-convert/`
- A cleanup cron runs daily and removes artifacts older than one week
- Job metadata is stored as a WordPress transient for one hour

## Security notes

- REST endpoints currently use public permission callbacks (`__return_true`).
- If you need restricted access, replace with an auth/capability check.
- Shell arguments are escaped and error messages are sanitized before API output.

## Project structure

- `floppy-image-converter.php` plugin bootstrap and hooks
- `includes/config.php` formats, diskdefs, defaults
- `includes/helpers.php` path/process helpers and filters
- `includes/conversion.php` upload handling and conversion pipeline
- `includes/status.php` log parsing and status responses
- `includes/rest.php` REST route registration
- `includes/frontend.php` shortcodes and template rendering
- `includes/cleanup.php` scheduled artifact cleanup
- `templates/converter-form.php` shortcode HTML template
- `assets/floppy-converter.css` shortcode CSS
- `assets/repair-copy-protected-yamaha-720k.php` copy-protected Yamaha/FAT12 repair helper
- `assets/create-ensoniq-img-from-efe.php` Ensoniq EFE to IMG helper
- `assets/convert-ensoniq-ede.php` Ensoniq EDE/IMG conversion helper
- `assets/extract-ensoniq-efe.php` Ensoniq EPS/EPS16/ASR EFE extraction helper

## Development

PHP syntax check:

```bash
php -l floppy-image-converter.php
for f in includes/*.php templates/*.php; do php -l "$f"; done
```
