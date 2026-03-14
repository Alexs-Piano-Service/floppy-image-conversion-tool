# Floppy Image Converter (WordPress Plugin)

Refactored layout:

- `floppy-image-converter.php`: bootstrap and hook wiring
- `includes/config.php`: allowed format/diskdef config and UI defaults
- `includes/helpers.php`: shared filesystem/process utilities
- `includes/conversion.php`: upload validation, ClamAV scan, conversion pipeline builder
- `includes/status.php`: log parsing, progress/error status endpoint logic
- `includes/rest.php`: REST route registration
- `includes/frontend.php`: shortcode registration and template rendering
- `includes/cleanup.php`: scheduled cleanup jobs
- `templates/converter-form.php`: converter form markup
- `assets/floppy-converter.css`: converter form styling

Use shortcode `[floppy-converter-form]` (or `[floppy-image-converter]`) to render the form.
