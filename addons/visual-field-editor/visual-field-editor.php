<?php
/**
 * CMM4 Addon: Visual Field Editor
 *
 * Replaces the raw "Label|type|required|placeholder|class|options" textarea
 * on the Edit Form screen with a click-based UI: add, delete, reorder, and
 * edit each field's properties through normal form controls.
 *
 * This addon does NOT change the storage format. It still saves to the same
 * 'cmm4_fields' post meta, in the exact same pipe-delimited text the core
 * shortcode renderer already expects — so nothing else in the plugin needs
 * to change, and raw mode / hand-edited structures keep working.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ==========================================
// REPLACE THE RAW TEXTAREA WITH THE VISUAL BUILDER
// ==========================================
function cmm4_vfe_render_editor( $default_html, $form_id, $fields_raw ) {

    $rows = cmm4_vfe_parse_fields( $fields_raw );
    $rows_json = wp_json_encode( $rows );

    ob_start();
    ?>
    <div id="cmm4-vfe-app" data-fields='<?php echo esc_attr( $rows_json ); ?>'>

        <div id="cmm4-vfe-toolbar" style="margin-bottom:14px; display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
            <button type="button" class="cmm4-btn-black" id="cmm4-vfe-add-field" style="margin-top:0; padding:10px 18px;">+ Add Field</button>
            <button type="button" id="cmm4-vfe-toggle-raw" style="background:none; border:1px solid #d1c9bf; color:#666; padding:9px 16px; font-size:11px; text-transform:uppercase; letter-spacing:0.5px; cursor:pointer;">Switch to Advanced (Raw Text)</button>
            <span style="font-size:12px; color:#999;" id="cmm4-vfe-field-count"></span>
        </div>

        <div id="cmm4-vfe-list"></div>

        <p id="cmm4-vfe-empty" style="color:#999; font-size:14px; padding:30px; text-align:center; border:1px dashed #d1c9bf; display:none;">
            No fields yet. Click "+ Add Field" to build your form.
        </p>

        <!-- Raw mode (hidden by default) -->
        <div id="cmm4-vfe-raw-wrap" style="display:none; margin-top:10px;">
            <p style="color:#888; font-size:12px; margin:0 0 8px 0;">Format: <code>Label|type|required|placeholder|css_class|option1, option2</code> &middot; Label supports basic HTML: <code>&lt;a href="..."&gt;link&lt;/a&gt;</code>, <code>&lt;strong&gt;</code>, <code>&lt;em&gt;</code>.</p>
            <textarea id="cmm4-vfe-raw-textarea" rows="8" class="cmm4-input-text" style="max-width:100%; font-family:monospace;"><?php echo esc_textarea( $fields_raw ); ?></textarea>
        </div>

        <!-- The actual field submitted to the server — always kept in sync with either mode -->
        <textarea name="cmm4_fields" id="cmm4-vfe-hidden-output" style="display:none;"><?php echo esc_textarea( $fields_raw ); ?></textarea>
    </div>

    <!-- Card template (cloned by JS, never submitted directly) -->
    <template id="cmm4-vfe-card-template">
        <div class="cmm4-vfe-card" style="border:1px solid #e8e6e1; background:#fff; border-radius:4px; margin-bottom:12px; overflow:hidden;">
            <div class="cmm4-vfe-card-head" style="display:flex; align-items:center; gap:10px; padding:12px 16px; background:#faf9f8; border-bottom:1px solid #f0ede9;">
                <span class="cmm4-vfe-drag-handle" style="cursor:default; color:#bbb; font-size:14px;">☰</span>
                <strong class="cmm4-vfe-card-title" style="flex:1; font-size:13px; color:#333;"></strong>
                <button type="button" class="cmm4-vfe-move-up" title="Move up" style="background:none; border:none; cursor:pointer; color:#888; font-size:14px; padding:4px 8px;">↑</button>
                <button type="button" class="cmm4-vfe-move-down" title="Move down" style="background:none; border:none; cursor:pointer; color:#888; font-size:14px; padding:4px 8px;">↓</button>
                <button type="button" class="cmm4-vfe-remove" title="Delete field" style="background:none; border:none; cursor:pointer; color:#a00; font-size:13px; padding:4px 8px;">✕</button>
            </div>
            <div class="cmm4-vfe-card-body" style="padding:16px; display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                <label style="font-size:12px; color:#666;">Label <span style="color:#bbb;">(links allowed, e.g. &lt;a href="..."&gt;text&lt;/a&gt;)</span>
                    <input type="text" class="cmm4-vfe-input cmm4-vfe-field-label cmm4-input-text" style="max-width:100%; margin-top:4px;" />
                </label>
                <label style="font-size:12px; color:#666;">Field Type
                    <select class="cmm4-vfe-input cmm4-vfe-field-type cmm4-input-text" style="max-width:100%; margin-top:4px; height:38px;">
                        <option value="text">Text</option>
                        <option value="email">Email</option>
                        <option value="tel">Phone</option>
                        <option value="textarea">Textarea</option>
                        <option value="select">Dropdown</option>
                        <option value="checkbox">Checkbox</option>
                        <option value="file">File Upload</option>
						<option value="radio">Radio Buttons</option>
						<option value="acf_locked">ACF — Locked Text</option>
						<option value="acf_dropdown">ACF — Dropdown</option>
						<option value="acf_checkbox">ACF — Checkbox List</option>
						<option value="acf_radio">ACF — Radio Buttons</option>
						<?php foreach ( apply_filters( 'cmm4_vfe_field_types', array() ) as $cmm4_vfe_value => $cmm4_vfe_label ) : ?>
						<option value="<?php echo esc_attr( $cmm4_vfe_value ); ?>"><?php echo esc_html( $cmm4_vfe_label ); ?></option>
						<?php endforeach; ?>
                    </select>
                </label>
                <label style="font-size:12px; color:#666;">Placeholder <span style="color:#bbb;">(optional)</span>
                    <input type="text" class="cmm4-vfe-input cmm4-vfe-field-placeholder cmm4-input-text" style="max-width:100%; margin-top:4px;" />
                </label>
                <label style="font-size:12px; color:#666;">CSS Class <span style="color:#bbb;">(optional)</span>
                    <input type="text" class="cmm4-vfe-input cmm4-vfe-field-class cmm4-input-text" style="max-width:100%; margin-top:4px;" placeholder="e.g. full-row" />
                </label>
                <label class="cmm4-vfe-options-row" style="font-size:12px; color:#666; grid-column:1 / -1; display:none;"><span class="cmm4-vfe-options-label">Options <span style="color:#bbb;">(comma-separated)</span></span>
                    <input type="text" class="cmm4-vfe-input cmm4-vfe-field-options cmm4-input-text" style="max-width:100%; margin-top:4px;" placeholder="Option A, Option B, Option C" />
                </label>
                <label style="font-size:13px; color:#444; display:flex; align-items:center; gap:8px; grid-column:1 / -1; margin-top:4px;">
                    <input type="checkbox" class="cmm4-vfe-field-required" style="width:auto; margin:0;" /> Required field
                </label>
            </div>
        </div>
    </template>

    <script>
    (function() {
        var app          = document.getElementById('cmm4-vfe-app');
        var list          = document.getElementById('cmm4-vfe-list');
        var emptyMsg      = document.getElementById('cmm4-vfe-empty');
        var countLabel    = document.getElementById('cmm4-vfe-field-count');
        var addBtn        = document.getElementById('cmm4-vfe-add-field');
        var toggleRawBtn  = document.getElementById('cmm4-vfe-toggle-raw');
        var rawWrap       = document.getElementById('cmm4-vfe-raw-wrap');
        var rawTextarea   = document.getElementById('cmm4-vfe-raw-textarea');
        var hiddenOutput  = document.getElementById('cmm4-vfe-hidden-output');
        var cardTemplate  = document.getElementById('cmm4-vfe-card-template');
        var visualWrap    = document.getElementById('cmm4-vfe-list');
        var toolbarAddWrap = document.getElementById('cmm4-vfe-toolbar');

        var fields = [];
        try {
            fields = JSON.parse(app.getAttribute('data-fields')) || [];
        } catch(e) { fields = []; }

        var rawMode = false;

        function escapePipe(str) {
            // Pipe characters would break the storage format; strip them defensively.
            return (str || '').replace(/\|/g, '/');
        }

        function fieldsToRaw(list) {
            return list.map(function(f) {
                return [
                    escapePipe(f.label),
                    f.type,
                    f.required ? 'required' : '',
                    escapePipe(f.placeholder || ''),
                    escapePipe(f.css_class || ''),
                    escapePipe(f.options || '')
                ].join('|');
            }).join('\n');
        }

        function syncHiddenOutput() {
            if (rawMode) {
                hiddenOutput.value = rawTextarea.value;
            } else {
                hiddenOutput.value = fieldsToRaw(fields);
            }
        }

        function renderCards() {
            list.innerHTML = '';
            emptyMsg.style.display = fields.length === 0 ? 'block' : 'none';
            countLabel.textContent = fields.length + (fields.length === 1 ? ' field' : ' fields');

            fields.forEach(function(field, index) {
                var node = cardTemplate.content.cloneNode(true);
                var card = node.querySelector('.cmm4-vfe-card');
                var title = node.querySelector('.cmm4-vfe-card-title');
                var labelInput = node.querySelector('.cmm4-vfe-field-label');
                var typeSelect = node.querySelector('.cmm4-vfe-field-type');
                var placeholderInput = node.querySelector('.cmm4-vfe-field-placeholder');
                var classInput = node.querySelector('.cmm4-vfe-field-class');
                var optionsRow = node.querySelector('.cmm4-vfe-options-row');
                var optionsLabel = node.querySelector('.cmm4-vfe-options-label');
                var optionsInput = node.querySelector('.cmm4-vfe-field-options');
                var requiredCheckbox = node.querySelector('.cmm4-vfe-field-required');
                var upBtn = node.querySelector('.cmm4-vfe-move-up');
                var downBtn = node.querySelector('.cmm4-vfe-move-down');
                var removeBtn = node.querySelector('.cmm4-vfe-remove');

                title.textContent = field.label || '(untitled field)';
                labelInput.value = field.label || '';
                typeSelect.value = field.type || 'text';
                placeholderInput.value = field.placeholder || '';
                classInput.value = field.css_class || '';
                optionsInput.value = field.options || '';
                requiredCheckbox.checked = !!field.required;
                updateOptionsRowUi(field.type);

                function updateOptionsRowUi(type) {
					var acfTypes = ['acf_locked', 'acf_dropdown', 'acf_checkbox', 'acf_radio'];
					var isAcf = (acfTypes.indexOf(type) !== -1);
					var showRow = (type === 'select' || type === 'checkbox' || type === 'file' || type === 'radio' || isAcf);
					optionsRow.style.display = showRow ? 'block' : 'none';
					if (type === 'file') {
						optionsLabel.innerHTML = 'Allowed file types <span style="color:#bbb;">(comma-separated extensions)</span>';
						optionsInput.placeholder = 'pdf, doc, docx';
					} else if (isAcf) {
						optionsLabel.innerHTML = 'ACF field name <span style="color:#bbb;">(exact name from your job field group)</span>';
						optionsInput.placeholder = 'e.g. department, positions, skills';
					} else {
						optionsLabel.innerHTML = 'Options <span style="color:#bbb;">(comma-separated)</span>';
						optionsInput.placeholder = 'Option A, Option B, Option C';
					}
				}

                function updateField(key, value) {
                    fields[index][key] = value;
                    if (key === 'label') title.textContent = value || '(untitled field)';
                    if (key === 'type') updateOptionsRowUi(value);
                    syncHiddenOutput();
                }

                labelInput.addEventListener('input', function() { updateField('label', this.value); });
                typeSelect.addEventListener('change', function() { updateField('type', this.value); });
                placeholderInput.addEventListener('input', function() { updateField('placeholder', this.value); });
                classInput.addEventListener('input', function() { updateField('css_class', this.value); });
                optionsInput.addEventListener('input', function() { updateField('options', this.value); });
                requiredCheckbox.addEventListener('change', function() { updateField('required', this.checked); });

                upBtn.disabled = (index === 0);
                downBtn.disabled = (index === fields.length - 1);
                upBtn.style.opacity = upBtn.disabled ? '0.3' : '1';
                downBtn.style.opacity = downBtn.disabled ? '0.3' : '1';

                upBtn.addEventListener('click', function() {
                    if (index === 0) return;
                    var tmp = fields[index - 1];
                    fields[index - 1] = fields[index];
                    fields[index] = tmp;
                    syncHiddenOutput();
                    renderCards();
                });
                downBtn.addEventListener('click', function() {
                    if (index === fields.length - 1) return;
                    var tmp = fields[index + 1];
                    fields[index + 1] = fields[index];
                    fields[index] = tmp;
                    syncHiddenOutput();
                    renderCards();
                });
                removeBtn.addEventListener('click', function() {
                    if (!confirm('Remove this field?')) return;
                    fields.splice(index, 1);
                    syncHiddenOutput();
                    renderCards();
                });

                list.appendChild(node);
            });
        }

        addBtn.addEventListener('click', function() {
            fields.push({
                label: 'New Field',
                type: 'text',
                required: false,
                placeholder: '',
                css_class: '',
                options: ''
            });
            syncHiddenOutput();
            renderCards();
        });

        toggleRawBtn.addEventListener('click', function() {
            rawMode = !rawMode;
            if (rawMode) {
                // Entering raw mode: reflect the current visual state as text.
                rawTextarea.value = fieldsToRaw(fields);
                rawWrap.style.display = 'block';
                toolbarAddWrap.style.display = 'none';
                visualWrap.style.display = 'none';
                emptyMsg.style.display = 'none';
                toggleRawBtn.textContent = 'Switch to Visual Editor';
            } else {
                // Leaving raw mode: re-parse the raw text back into cards.
                fields = cmm4VfeParseRawText(rawTextarea.value);
                rawWrap.style.display = 'none';
                toolbarAddWrap.style.display = 'flex';
                visualWrap.style.display = 'block';
                toggleRawBtn.textContent = 'Switch to Advanced (Raw Text)';
                renderCards();
            }
            syncHiddenOutput();
        });

        rawTextarea.addEventListener('input', syncHiddenOutput);

        function cmm4VfeParseRawText(raw) {
            return raw.split(/\r\n|\r|\n/).map(function(line) {
                var parts = line.split('|');
                if (parts.length < 2 || parts[0].trim() === '') return null;
                return {
                    label: (parts[0] || '').trim(),
                    type: (parts[1] || 'text').trim(),
                    required: (parts[2] || '').trim() === 'required',
                    placeholder: (parts[3] || '').trim(),
                    css_class: (parts[4] || '').trim(),
                    options: (parts[5] || '').trim()
                };
            }).filter(function(f) { return f !== null; });
        }

        // Make sure the parent <form> submit always sends the up-to-date value,
        // even if the user left a field focused without blurring it.
        var parentForm = app.closest('form');
        if (parentForm) {
            parentForm.addEventListener('submit', syncHiddenOutput);
        }

        renderCards();
        syncHiddenOutput();
    })();
    </script>
    <?php
    return ob_get_clean();
}
add_filter( 'cmm4_fields_editor_ui', 'cmm4_vfe_render_editor', 10, 3 );

// ==========================================
// PARSE STORED FIELDS INTO STRUCTURED ARRAY (FOR THE JS APP'S INITIAL STATE)
// ==========================================
function cmm4_vfe_parse_fields( $fields_raw ) {
    $rows = array();
    if ( empty( $fields_raw ) ) {
        return $rows;
    }

    $lines = preg_split( '/\r\n|\r|\n/', $fields_raw );
    foreach ( $lines as $line ) {
        $parts = explode( '|', trim( $line ) );
        if ( count( $parts ) < 2 || trim( $parts[0] ) === '' ) {
            continue;
        }
        $rows[] = array(
            'label'       => trim( $parts[0] ),
            'type'        => isset( $parts[1] ) ? trim( $parts[1] ) : 'text',
            'required'    => ( isset( $parts[2] ) && trim( $parts[2] ) === 'required' ),
            'placeholder' => isset( $parts[3] ) ? trim( $parts[3] ) : '',
            'css_class'   => isset( $parts[4] ) ? trim( $parts[4] ) : '',
            'options'     => isset( $parts[5] ) ? trim( $parts[5] ) : '',
        );
    }
    return $rows;
}
