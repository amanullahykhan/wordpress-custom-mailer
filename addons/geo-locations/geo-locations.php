<?php
/**
 * Addon Name: Pakistan Location Dropdowns
 * Description: Adds cascading Province > District > City dropdowns to forms. Choosing a province shows only its districts, and choosing a district shows only its cities.
 * Version: 1.2.0
 * Author: Amanullah Khan
 * Requires CMM4 at least: 6.12.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/geo-data.php';

// The three field types this addon provides.
function cmm4_geo_field_types() {
    return array(
        'geo_province' => 'Province',
        'geo_district' => 'District',
        'geo_city'     => 'City',
    );
}

// ==========================================
// 1. RENDER THE FIELDS (core hook: cmm4_render_custom_field)
// ==========================================
add_filter( 'cmm4_render_custom_field', 'cmm4_geo_render_field', 10, 6 );

function cmm4_geo_render_field( $html, $type, $name, $classes, $styles, $required ) {
    // Another addon already claimed this field, or it isn't ours.
    if ( $html !== null || ! array_key_exists( $type, cmm4_geo_field_types() ) ) {
        return $html;
    }

    $level = str_replace( 'geo_', '', $type ); // province | district | city

    $out  = '<select name="cmm4_data[' . esc_attr( $name ) . ']" data-geo="' . esc_attr( $level ) . '"';
    $out .= ' class="' . esc_attr( $classes ) . '" style="color: var(--text-gray); ' . $styles . '" ' . $required . '>';
    $out .= '<option value="">Select ' . esc_html( ucfirst( $level ) ) . '</option>';

    // Only the province list is filled on the server. The JS fills the rest.
    if ( $level === 'province' ) {
        foreach ( array_keys( cmm4_geo_data() ) as $province ) {
            $out .= '<option value="' . esc_attr( $province ) . '">' . esc_html( $province ) . '</option>';
        }
    }

    $out .= '</select>';

    // Print the cascade script once per page.
    static $script_done = false;
    if ( ! $script_done ) {
        $script_done = true;
        $out .= cmm4_geo_script();
    }

    return $out;
}

// ==========================================
// 2. THE CASCADE SCRIPT
// Uses event delegation, so it works for any number of forms on the page.
// ==========================================
function cmm4_geo_script() {
    // HEX flags stop any '<', '>', '&', quote or apostrophe in the data from
    // ever closing the <script> block early.
    $json = wp_json_encode(
        cmm4_geo_data(),
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );

    return '<script>(function(){
        if (window.cmm4GeoReady) {
            return;
        }
        window.cmm4GeoReady = true;

        var GEO = ' . $json . ';

        function fill(sel, items, placeholder) {
            if (!sel) {
                return;
            }

            var previous = sel.value;

            sel.innerHTML = "";

            var placeholderOption = document.createElement("option");
            placeholderOption.value = "";
            placeholderOption.textContent = placeholder;
            sel.appendChild(placeholderOption);

            for (var i = 0; i < items.length; i++) {
                var option = document.createElement("option");
                option.value = items[i];
                option.textContent = items[i];
                sel.appendChild(option);
            }

            sel.value = previous;
        }

        function initForm(form) {
            if (!form || form.dataset.cmm4GeoInit === "1") {
                return;
            }

            var pSel = form.querySelector("select[data-geo=province]");
            var dSel = form.querySelector("select[data-geo=district]");
            var cSel = form.querySelector("select[data-geo=city]");

            if (!pSel) {
                return;
            }

            form.dataset.cmm4GeoInit = "1";

            fill(pSel, Object.keys(GEO), "Select Province");

            var districts = [];
            if (pSel.value && GEO[pSel.value]) {
                districts = Object.keys(GEO[pSel.value]);
            }

            fill(dSel, districts, "Select District");

            var cities = [];
            if (
                pSel.value &&
                dSel &&
                dSel.value &&
                GEO[pSel.value] &&
                GEO[pSel.value][dSel.value]
            ) {
                cities = GEO[pSel.value][dSel.value];
            }

            fill(cSel, cities, "Select City");
        }

        function initAll(scope) {
            scope = scope || document;

            var provinceSelects = scope.querySelectorAll("select[data-geo=province]");

            for (var i = 0; i < provinceSelects.length; i++) {
                var form = provinceSelects[i].form || provinceSelects[i].closest("form");
                if (form) {
                    initForm(form);
                }
            }
        }

        document.addEventListener("change", function(e) {
            var t = e.target;

            if (!t || t.tagName !== "SELECT" || !t.getAttribute("data-geo") || !t.form) {
                return;
            }

            var level = t.getAttribute("data-geo");
            var form  = t.form;

            var pSel = form.querySelector("select[data-geo=province]");
            var dSel = form.querySelector("select[data-geo=district]");
            var cSel = form.querySelector("select[data-geo=city]");

            if (level === "province") {
                var districts = [];

                if (GEO[t.value]) {
                    districts = Object.keys(GEO[t.value]);
                }

                if (dSel) {
                    dSel.value = "";
                }

                if (cSel) {
                    cSel.value = "";
                }

                fill(dSel, districts, "Select District");
                fill(cSel, [], "Select City");
            }

            if (level === "district") {
                var province = pSel ? pSel.value : "";
                var cities = [];

                if (province && GEO[province] && GEO[province][t.value]) {
                    cities = GEO[province][t.value];
                }

                if (cSel) {
                    cSel.value = "";
                }

                fill(cSel, cities, "Select City");
            }
        });

        if (document.readyState === "loading") {
            document.addEventListener("DOMContentLoaded", function() {
                initAll(document);
            });
        } else {
            initAll(document);
        }
    })();</script>';
}

// ==========================================
// 3. VISUAL FIELD EDITOR: add the types to the "Field Type" dropdown
// (filter provided by the visual-field-editor addon)
// ==========================================
add_filter( 'cmm4_vfe_field_types', 'cmm4_geo_add_builder_types' );

function cmm4_geo_add_builder_types( $types ) {
    foreach ( cmm4_geo_field_types() as $value => $label ) {
        $types[ $value ] = 'Pakistan Location — ' . $label;
    }

    return $types;
}