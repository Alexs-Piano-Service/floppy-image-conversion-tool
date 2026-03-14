<?php
/**
 * Converter form template.
 *
 * Variables provided by shortcode callback:
 * - $basic_diskdefs
 * - $basic_outputs
 * - $advanced_diskdefs
 * - $advanced_outputs
 * - $default_diskdef
 * - $default_output
 * - $show_advanced
 */
?>
<div id="form-cont">
    <form id="floppy-form" enctype="multipart/form-data">
        <div class="upload-wrapper">
            <label for="disk-image" class="upload-label">
                <span class="btn">Browse...</span>
                <span class="filename" data-default="No file selected.">No file selected.</span>
                <input id="disk-image" type="file" name="file" class="upload-input" />
            </label>
        </div>

        <?php if ( $show_advanced ) : ?>
            <label class="fic-advanced-toggle">
                <input type="checkbox" id="advanced-toggle" />
                Advanced
            </label>
        <?php endif; ?>

        <div id="basic-options" class="fic-options-panel">
            <label class="fic-field-label">
                Image Format:
                <select name="diskdef" id="basic-diskdef">
                    <?php foreach ( $basic_diskdefs as $diskdef => $label ) : ?>
                        <option value="<?php echo esc_attr( $diskdef ); ?>" <?php selected( $diskdef, $default_diskdef ); ?>>
                            <?php echo esc_html( $label ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="fic-field-label">
                Output File:
                <select name="out_fmt" id="basic-out_fmt">
                    <?php foreach ( $basic_outputs as $format => $label ) : ?>
                        <option value="<?php echo esc_attr( $format ); ?>" <?php selected( $format, $default_output ); ?>>
                            <?php echo esc_html( $label ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>

        <div id="advanced-options" class="fic-options-panel" style="display:none;">
            <label class="fic-field-label">
                Image Format:
                <select name="diskdef" id="advanced-diskdef" disabled>
                    <?php foreach ( $advanced_diskdefs as $diskdef ) : ?>
                        <option value="<?php echo esc_attr( $diskdef ); ?>" <?php selected( $diskdef, $default_diskdef ); ?>>
                            <?php echo esc_html( fic_diskdef_label( $diskdef ) ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="fic-field-label">
                Output File:
                <select name="out_fmt" id="advanced-out_fmt" disabled>
                    <?php foreach ( $advanced_outputs as $format => $label ) : ?>
                        <option value="<?php echo esc_attr( $format ); ?>" <?php selected( $format, $default_output ); ?>>
                            <?php echo esc_html( $label ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>

        <button type="submit" class="btn">Convert</button>
    </form>

    <div id="status-container" style="display:none; margin-top:1em;">
        <p id="status-text" role="status" aria-live="polite"></p>
        <?php echo wp_kses_post( fic_render_progress_bar_shortcode() ); ?>
        <p>
            <a id="download-link" href="#" style="display:none;" download>Download converted file</a>
        </p>
    </div>
</div>
