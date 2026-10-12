<?php
/**
 * Plugin Name: Custom Minimalist Mailer (CMM4)
 * Description: A lightweight, sandboxed form generation and SMTP routing engine. Customized for ALK Elementor theme architecture.
 * Version: 6.13.0
 * Author: Amanullah Khan
 * Author URI: https://github.com/amanullahykhan
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

// ==========================================
// 0a. SUBMISSION LOG TABLE (CREATED ON ACTIVATION)
// ==========================================
function cmm4_activate_plugin() {
    global $wpdb;
    $table_name      = $wpdb->prefix . 'cmm4_submissions';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table_name} (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        form_id BIGINT(20) UNSIGNED NOT NULL,
        form_title VARCHAR(255) NOT NULL,
        submission_data LONGTEXT NOT NULL,
        submitted_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        KEY form_id (form_id)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );

    cmm4_ensure_private_upload_dir();
}
register_activation_hook( __FILE__, 'cmm4_activate_plugin' );

// ==========================================
// 0a-i. PRIVATE FILE UPLOAD DIRECTORY (RESUMES / ATTACHMENTS)
// ==========================================
// Files are stored outside the normal public media library, in a folder
// protected by .htaccess (Apache) — direct URL access is blocked. Files can
// only be retrieved through cmm4_handle_file_download(), which requires an
// admin login and a valid HMAC signature (see cmm4_sign_stored_filename()).
function cmm4_get_private_upload_dir() {
    $upload_dir = wp_upload_dir();
    return trailingslashit( $upload_dir['basedir'] ) . 'cmm4-private-files';
}

function cmm4_ensure_private_upload_dir() {
    $dir = cmm4_get_private_upload_dir();
    if ( ! file_exists( $dir ) ) {
        wp_mkdir_p( $dir );
    }

    // Block direct access on Apache. (On Nginx, the admin should add an
    // equivalent `location` block — this alone doesn't cover Nginx, so we
    // also randomize filenames as a second layer of protection either way.)
    $htaccess_path = $dir . '/.htaccess';
    if ( ! file_exists( $htaccess_path ) ) {
        file_put_contents( $htaccess_path, "Order deny,allow\nDeny from all\n" );
    }

    // Belt-and-braces: an empty index.php so directory listing shows nothing
    // even if .htaccess is ignored by the server config.
    $index_path = $dir . '/index.php';
    if ( ! file_exists( $index_path ) ) {
        file_put_contents( $index_path, "<?php\n// Silence is golden.\n" );
    }
}
// Safety net: if the table is somehow missing (e.g. plugin was updated in
// place without a re-activation), create it on init too. dbDelta() is safe
// to call repeatedly — it only creates/updates, never drops data.
function cmm4_maybe_create_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'cmm4_submissions';
    if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) !== $table_name ) {
        cmm4_activate_plugin();
    }
    cmm4_ensure_private_upload_dir();
}
add_action( 'plugins_loaded', 'cmm4_maybe_create_table' );

// ==========================================
// 0b. ADDON ECOSYSTEM (REGISTRY + LOADER)
// ==========================================
// Any addon lives at addons/{addon-folder}/{addon-folder}.php and is
// discovered automatically. Addons hook into the actions/filters listed in
// "ADDON HOOK POINTS" below — they should never need to edit this core file.
//
// An addon can declare a standard header comment anywhere in its main file
// (matching the WordPress plugin-header convention) so it shows up nicely on
// the "CMM Forms → Addons" screen:
//
//   /**
//    * Addon Name: My Addon
//    * Description: One line describing what it does.
//    * Version: 2.0.0
//    * Author: Amanullah Khan
//    * Requires CMM4 at least: 6.10.0
//    */
//
// All header fields are optional — an addon with no header still loads fine,
// it just shows its folder name on the Addons screen instead of a friendly
// name. "Requires CMM4 at least" is checked before loading; if the running
// core version is older, the addon is skipped with a clear reason instead of
// risking a fatal error from calling a function/hook that doesn't exist yet.
//
// Addons can be turned off individually from CMM Forms → Addons without
// deleting any files. And if an addon throws a fatal error while loading, it
// is caught, logged, and the addon is skipped for that request — one broken
// addon can no longer take down the rest of the site.
define( 'CMM4_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CMM4_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CMM4_VERSION', '6.13.0' );

// Scans /addons and returns metadata for every addon folder found, whether
// or not it's currently enabled. Cheap to call repeatedly within one request
// (static cache) — this deliberately doesn't touch options/DB.
function cmm4_get_available_addons() {
    static $addons = null;
    if ( $addons !== null ) {
        return $addons;
    }

    $addons = array();
    $addons_dir = CMM4_PLUGIN_DIR . 'addons';
    if ( ! is_dir( $addons_dir ) ) {
        return $addons;
    }

    foreach ( glob( $addons_dir . '/*', GLOB_ONLYDIR ) as $addon_folder ) {
        $addon_slug = basename( $addon_folder );
        $addon_file = $addon_folder . '/' . $addon_slug . '.php';
        if ( ! file_exists( $addon_file ) ) {
            continue;
        }

        $data = function_exists( 'get_file_data' ) ? get_file_data( $addon_file, array(
            'Name'          => 'Addon Name',
            'Description'   => 'Description',
            'Version'       => 'Version',
            'Author'        => 'Author',
            'RequiresCmm4'  => 'Requires CMM4 at least',
        ) ) : array();

        $addons[ $addon_slug ] = array(
            'slug'          => $addon_slug,
            'file'          => $addon_file,
            'name'          => ! empty( $data['Name'] ) ? $data['Name'] : $addon_slug,
            'description'   => isset( $data['Description'] ) ? $data['Description'] : '',
            'version'       => isset( $data['Version'] ) ? $data['Version'] : '',
            'author'        => isset( $data['Author'] ) ? $data['Author'] : '',
            'requires_cmm4' => isset( $data['RequiresCmm4'] ) ? $data['RequiresCmm4'] : '',
        );
    }

    return $addons;
}

// Slugs the site owner has switched off from the Addons screen.
function cmm4_get_disabled_addons() {
    $disabled = get_option( 'cmm4_disabled_addons', array() );
    return is_array( $disabled ) ? $disabled : array();
}

// Slug => human-readable reason, populated by cmm4_load_addons() each time it
// runs. Surfaced both as an admin notice and on the Addons screen so a
// broken or incompatible addon is loud and diagnosable instead of silently
// missing.
function cmm4_get_addon_errors() {
    $errors = get_option( 'cmm4_addon_errors', array() );
    return is_array( $errors ) ? $errors : array();
}

function cmm4_load_addons() {
    $addons          = cmm4_get_available_addons();
    $disabled        = cmm4_get_disabled_addons();
    $errors_this_run = array();

    foreach ( $addons as $slug => $addon ) {
        if ( in_array( $slug, $disabled, true ) ) {
            continue;
        }

        if ( ! empty( $addon['requires_cmm4'] ) && version_compare( CMM4_VERSION, $addon['requires_cmm4'], '<' ) ) {
            $errors_this_run[ $slug ] = sprintf(
                'Requires Custom Minimalist Mailer version %s or higher (this site is running %s).',
                $addon['requires_cmm4'],
                CMM4_VERSION
            );
            continue;
        }

        // Fatal errors thrown while an addon file loads (bad syntax that
        // slipped past php -l on a different PHP version, a missing class,
        // etc.) are \Throwable in PHP 7+, so they can be caught here instead
        // of taking down every page on the site.
        try {
            require_once $addon['file'];
        } catch ( \Throwable $e ) {
            $errors_this_run[ $slug ] = $e->getMessage();
        }
    }

    // Only write when something actually changed, so a normal healthy
    // request (the common case) doesn't do an extra DB write.
    if ( $errors_this_run !== cmm4_get_addon_errors() ) {
        update_option( 'cmm4_addon_errors', $errors_this_run, false );
    }
}
add_action( 'plugins_loaded', 'cmm4_load_addons', 20 ); // after core table check (priority 10 default)

// Loud-but-not-broken: tell the site owner exactly which addon failed and
// why, right in wp-admin, instead of it just silently not working.
function cmm4_addon_error_notices() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    $errors = cmm4_get_addon_errors();
    if ( empty( $errors ) ) {
        return;
    }
    foreach ( $errors as $slug => $message ) {
        echo '<div class="notice notice-error"><p><strong>CMM Forms:</strong> the addon "' . esc_html( $slug ) . '" could not be loaded and has been skipped — ' . esc_html( $message ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=cmm4-addons' ) ) . '">Manage addons</a></p></div>';
    }
}
add_action( 'admin_notices', 'cmm4_addon_error_notices' );

// ==========================================
// ADDON HOOK POINTS (for reference — addons attach to these)
// ==========================================
// Action  'cmm4_admin_menu_after'         — fires after core admin menu items are registered. Add addon submenu pages here.
// Filter  'cmm4_fields_editor_ui'         — ( $default_html, $form_id, $fields_raw ) lets an addon replace the raw textarea editor with a custom UI.
// Action  'cmm4_before_form_render'       — ( $form_id, $atts ) fires before the shortcode builds form HTML.
// Action  'cmm4_after_submission_saved'   — ( $form_id, $clean_data, $submission_id ) fires right after a submission is logged to the DB.
// Filter  'cmm4_notification_email_rows'  — ( $rows_html, $clean_data, $form_id ) lets an addon adjust the notification email's field rows.
// Filter  'cmm4_validate_submission'      — ( '', $form_id, $clean_data ) return a non-empty message to block/reject a submission before it's saved or emailed.
// Filter  'cmm4_render_custom_field'    — ( null, $type, $name, $classes, $styles, $required ) lets an addon render a new field type. Return HTML to claim it, or leave null.

// Global variable to catch early submission alerts and display them in the shortcode canvas
global $cmm4_submission_feedback;
$cmm4_submission_feedback = '';

// Global flag: true only when the current page actually contains a [cmm4_form] shortcode.
// This is what lets us load reCAPTCHA only where it's needed, instead of on every page.
global $cmm4_page_has_form;
$cmm4_page_has_form = false;

// ==========================================
// 0. DETECT IF CURRENT PAGE ACTUALLY USES THE FORM
// ==========================================
function cmm4_detect_form_on_page() {
    global $cmm4_page_has_form;
    if ( is_admin() ) return;

    // Case 1 & 2: Standard Page/Post Content & Elementor Data
    if ( is_singular() ) {
        $post = get_post();
        if ( $post && has_shortcode( $post->post_content, 'cmm4_form' ) ) {
            $cmm4_page_has_form = true; return;
        }
        $post_id = get_the_ID();
        if ( $post_id ) {
            $elementor_data = get_post_meta( $post_id, '_elementor_data', true );
            if ( $elementor_data && strpos( $elementor_data, 'cmm4_form' ) !== false ) {
                $cmm4_page_has_form = true; return;
            }
        }
    }

    // Case 3: Widgets
    if ( is_active_widget( false, false, 'text', true ) || is_active_widget( false, false, 'custom_html', true ) ) {
        $widget_text = wp_json_encode( get_option( 'widget_text' ) ) . wp_json_encode( get_option( 'widget_custom_html' ) );
        if ( strpos( $widget_text, 'cmm4_form' ) !== false ) {
            $cmm4_page_has_form = true; return;
        }
    }

    // Case 4: ELEMENTOR POPUPS (Verified Architecture)
    $popup_hit = get_transient( 'cmm4_popup_has_form' );
    if ( false === $popup_hit ) {
        $popup_hit = 'no';
        $popups = get_posts( array(
            'post_type'      => 'elementor_library', // Elementor stores popups here
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => array( array( 'key' => '_elementor_template_type', 'value' => 'popup' ) ),
        ) );
        foreach ( $popups as $popup_id ) {
            $content = get_post_field( 'post_content', $popup_id );
            $el_data = get_post_meta( $popup_id, '_elementor_data', true );
            if ( strpos( (string) $content, 'cmm4_form' ) !== false || ( $el_data && strpos( (string) $el_data, 'cmm4_form' ) !== false ) ) {
                $popup_hit = 'yes'; break;
            }
        }
        set_transient( 'cmm4_popup_has_form', $popup_hit, HOUR_IN_SECONDS );
    }
    if ( 'yes' === $popup_hit ) {
        $cmm4_page_has_form = true;
    }
}
// Flush cache when a popup is updated
add_action( 'save_post', 'cmm4_flush_popup_form_cache', 10, 2 );
function cmm4_flush_popup_form_cache( $post_id, $post ) {
    if ( isset( $post->post_type ) && 'elementor_library' === $post->post_type ) {
        delete_transient( 'cmm4_popup_has_form' );
    }
}
add_action( 'wp', 'cmm4_detect_form_on_page' );

// ==========================================
// 1. REGISTER HIDDEN CUSTOM POST TYPE
// ==========================================
function cmm4_register_post_type() {
    $args = array(
        'public'              => false,
        'show_ui'             => false,
        'query_var'           => false,
        'rewrite'             => false,
        'capability_type'     => 'post',
        'hierarchical'        => false,
        'supports'            => array( 'title' ),
    );
    register_post_type( 'cmm4_form', $args );
}
add_action( 'init', 'cmm4_register_post_type' );

// ==========================================
// 2. ADMIN MENU ROUTING
// ==========================================
function cmm4_admin_menu() {
    add_menu_page( 'CMM Forms', 'Minimal Mailer', 'manage_options', 'cmm4-dashboard', 'cmm4_page_dashboard', 'dashicons-email', 58 );
    add_submenu_page( null, 'Edit Form', 'Edit Form', 'manage_options', 'cmm4-edit', 'cmm4_page_edit' );
    add_submenu_page( 'cmm4-dashboard', 'SMTP Gateway', 'SMTP Gateway', 'manage_options', 'cmm4-smtp', 'cmm4_page_smtp_settings' );
    add_submenu_page( 'cmm4-dashboard', 'Autoreply Studio', 'Autoreply Studio', 'manage_options', 'cmm4-autoreply', 'cmm4_page_autoreply_settings' );
    add_submenu_page( 'cmm4-dashboard', 'Email Branding', 'Email Branding', 'manage_options', 'cmm4-branding', 'cmm4_page_branding_settings' );
    add_submenu_page( 'cmm4-dashboard', 'Security Shield', 'Security Shield', 'manage_options', 'cmm4-security', 'cmm4_page_security_settings' );
    add_submenu_page( 'cmm4-dashboard', 'Data Retention', 'Data Retention', 'manage_options', 'cmm4-retention', 'cmm4_page_data_retention' );
    add_submenu_page( 'cmm4-dashboard', 'Addons', 'Addons', 'manage_options', 'cmm4-addons', 'cmm4_page_addons' );
    add_submenu_page( null, 'Submissions', 'Submissions', 'manage_options', 'cmm4-submissions', 'cmm4_page_submissions' );

    // Hook point: addons can register their own submenu pages here.
    do_action( 'cmm4_admin_menu_after' );
}
add_action( 'admin_menu', 'cmm4_admin_menu' );

// ==========================================
// 2a. CSV EXPORT (RUNS BEFORE ANY HTML OUTPUT)
// ==========================================
// Prevents CSV formula injection: if a value starts with =, +, -, or @,
// spreadsheet apps may treat it as a formula when the file is opened.
// Prefixing with a straight quote forces it to display as plain text.
function cmm4_neutralize_csv_formula( $val ) {
    $val = (string) $val;
    if ( isset( $val[0] ) && in_array( $val[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
        return "'" . $val;
    }
    return $val;
}

// Writes a full result set of submission rows to an open file handle as CSV.
// Shared by cmm4_maybe_export_csv() (both the full-log and single-entry
// downloads) so there is exactly one place that knows how to turn a
// clean_data array into a CSV row — including file-link and formula-
// injection handling — rather than keeping that logic in sync by hand
// across multiple call sites.
function cmm4_write_csv_rows( $output, $all_keys, $parsed_rows ) {
    $header_row = array_merge( array( 'Submitted At' ), array_map( function( $k ) {
        return ucfirst( str_replace( '-', ' ', $k ) );
    }, $all_keys ) );
    fputcsv( $output, $header_row );

    foreach ( $parsed_rows as $row ) {
        $line = array( $row['submitted_at'] );
        foreach ( $all_keys as $key ) {
            $val = isset( $row['data'][ $key ] ) ? $row['data'][ $key ] : '';

            if ( cmm4_is_file_value( $val ) ) {
                $download_url = cmm4_get_file_download_url( $val['stored_name'], $val['original_name'] );
                // Excel/Sheets render this as a clickable link showing the
                // original filename, while the URL underneath does the real work.
                $safe_name = str_replace( '"', '""', cmm4_neutralize_csv_formula( $val['original_name'] ) );
                $val = '=HYPERLINK("' . $download_url . '","' . $safe_name . '")';
            } else {
                if ( is_array( $val ) ) {
                    $val = implode( ', ', $val );
                }
                // CSV formula-injection guard: if a submitter typed something
                // starting with =, +, -, or @, Excel/Sheets could interpret it
                // as a formula when the file is opened. Prefixing with a
                // straight quote forces it to stay literal text. This is the
                // one and only formula we intentionally allow (the HYPERLINK
                // above), so every other field gets neutralized.
                $val = cmm4_neutralize_csv_formula( $val );
            }

            $line[] = $val;
        }
        fputcsv( $output, $line );
    }
}

// Writes ONE submission's data to a CSV file at $dest_path. Used to attach a
// per-candidate CSV to the admin notification email — thin wrapper around
// cmm4_write_csv_rows() so the single-entry email attachment and the
// Submissions-page exports always produce byte-identical formatting.
function cmm4_write_single_entry_csv( $dest_path, $clean_data, $submitted_at ) {
    $output = fopen( $dest_path, 'w' );
    if ( ! $output ) {
        return false;
    }

    // UTF-8 BOM so Excel correctly detects encoding (needed for non-English characters).
    fwrite( $output, "\xEF\xBB\xBF" );
    cmm4_write_csv_rows( $output, array_keys( $clean_data ), array( array( 'data' => $clean_data, 'submitted_at' => $submitted_at ) ) );
    fclose( $output );
    return true;
}

function cmm4_maybe_export_csv() {
    if ( ! is_admin() || ! isset( $_GET['cmm4_export'] ) || $_GET['cmm4_export'] !== 'csv' ) {
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    if ( ! isset( $_GET['form_id'] ) || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'cmm4_export_' . intval( $_GET['form_id'] ) ) ) {
        return;
    }

    global $wpdb;
    $form_id    = intval( $_GET['form_id'] );
    $table_name = $wpdb->prefix . 'cmm4_submissions';
    $form_title = get_the_title( $form_id );

    // If entry_id is present, this is a single-entry download (from the row's
    // "⬇ CSV" link on the Submissions page) instead of the full log — same
    // nonce, same permission check, same output code path, just filtered to
    // one row. entry_id is intval'd and always paired with form_id in the
    // WHERE clause, so a signed-in admin can't use it to pull a row from a
    // form they don't have the nonce for.
    $entry_id = isset( $_GET['entry_id'] ) ? intval( $_GET['entry_id'] ) : 0;

    if ( $entry_id ) {
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT submission_data, submitted_at FROM {$table_name} WHERE id = %d AND form_id = %d LIMIT 1",
            $entry_id, $form_id
        ) );
    } else {
        // Capped at 10,000 rows to protect server memory on very high-traffic forms.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT submission_data, submitted_at FROM {$table_name} WHERE form_id = %d ORDER BY submitted_at DESC LIMIT 10000",
            $form_id
        ) );
    }

    // Collect the full set of field names across all submissions, in first-seen order,
    // so the CSV header covers every column even if the form structure changed over time.
    $all_keys = array();
    $parsed_rows = array();
    foreach ( $rows as $row ) {
        $data = json_decode( $row->submission_data, true );
        if ( ! is_array( $data ) ) {
            $data = array();
        }
        foreach ( $data as $key => $val ) {
            if ( ! in_array( $key, $all_keys, true ) ) {
                $all_keys[] = $key;
            }
        }
        $parsed_rows[] = array( 'data' => $data, 'submitted_at' => $row->submitted_at );
    }

    $filename = $entry_id
        ? 'submission-' . sanitize_title( $form_title ) . '-entry-' . $entry_id . '.csv'
        : 'submissions-' . sanitize_title( $form_title ) . '-' . current_time( 'Y-m-d' ) . '.csv';

    nocache_headers();
    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

    $output = fopen( 'php://output', 'w' );
    // UTF-8 BOM so Excel correctly detects encoding (needed for non-English characters).
    fwrite( $output, "\xEF\xBB\xBF" );
    cmm4_write_csv_rows( $output, $all_keys, $parsed_rows );
    fclose( $output );
    exit;
}
add_action( 'admin_init', 'cmm4_maybe_export_csv' );

// ==========================================
// 2a. BULK ZIP EXPORT (ALL APPLICATIONS + ALL RESUME FILES)
// ==========================================
// Builds one ZIP containing:
//   - applications.csv (same data/format as the normal CSV export)
//   - every uploaded file (resume, etc.) from every submission on this form,
//     each renamed to include the applicant's name/email + original filename
//     so files don't collide and stay identifiable outside the admin area.
// Same access rule as the CSV export: manage_options + a valid per-form
// nonce, so this can't be triggered by a guessed/crafted URL.
function cmm4_maybe_export_zip() {
    if ( ! is_admin() || ! isset( $_GET['cmm4_export'] ) || $_GET['cmm4_export'] !== 'zip' ) {
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    if ( ! isset( $_GET['form_id'] ) || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'cmm4_export_' . intval( $_GET['form_id'] ) ) ) {
        return;
    }
    if ( ! class_exists( 'ZipArchive' ) ) {
        wp_die( 'Your server does not have the PHP Zip extension enabled, so a combined ZIP download is not available. Please use the CSV export and the per-row resume download links instead, or ask your host to enable the "zip" PHP extension.' );
    }

    global $wpdb;
    $form_id    = intval( $_GET['form_id'] );
    $table_name = $wpdb->prefix . 'cmm4_submissions';
    $form_title = get_the_title( $form_id );

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT submission_data, submitted_at FROM {$table_name} WHERE form_id = %d ORDER BY submitted_at DESC LIMIT 10000",
        $form_id
    ) );

    $all_keys    = array();
    $parsed_rows = array();
    foreach ( $rows as $row ) {
        $data = json_decode( $row->submission_data, true );
        if ( ! is_array( $data ) ) {
            $data = array();
        }
        foreach ( $data as $key => $val ) {
            if ( ! in_array( $key, $all_keys, true ) ) {
                $all_keys[] = $key;
            }
        }
        $parsed_rows[] = array( 'data' => $data, 'submitted_at' => $row->submitted_at );
    }

    // Build the ZIP in a temp file first (ZipArchive needs a real filesystem
    // path to write to), then stream it and delete it — nothing is left
    // behind on the server either on success or if something fails midway.
    $tmp_zip_path = wp_tempnam( 'cmm4-bulk-export' );
    $zip = new ZipArchive();
    if ( $zip->open( $tmp_zip_path, ZipArchive::OVERWRITE ) !== true ) {
        @unlink( $tmp_zip_path );
        wp_die( 'Could not create the ZIP file. Please try again, or contact your host if this keeps happening.' );
    }

    // 1. applications.csv — identical format/columns to the normal CSV export.
    $csv_tmp = wp_tempnam( 'cmm4-bulk-export-csv' );
    $csv_out = fopen( $csv_tmp, 'w' );
    fwrite( $csv_out, "\xEF\xBB\xBF" ); // UTF-8 BOM, same as the normal export.
    cmm4_write_csv_rows( $csv_out, $all_keys, $parsed_rows );
    fclose( $csv_out );
    $zip->addFile( $csv_tmp, 'applications.csv' );

    // 2. Every uploaded file across every row, one per submission, placed in
    // a "resumes/" folder inside the ZIP. Renamed to
    // "<row-date>_<name-or-email>_<original-filename>" so files are easy to
    // tell apart once they're outside the admin table, and so two different
    // applicants who both uploaded "resume.pdf" don't overwrite each other.
    $private_dir   = cmm4_get_private_upload_dir();
    $used_zip_names = array(); // guards against any remaining name collisions
    $missing_files  = array(); // files referenced in the DB but no longer on disk

    foreach ( $parsed_rows as $row ) {
        // Figure out a human label for this row: prefer a field with "name"
        // in its key, fall back to one with "email" in its key, else "entry".
        $label = '';
        foreach ( $row['data'] as $key => $val ) {
            if ( ! is_array( $val ) && stripos( (string) $key, 'name' ) !== false ) {
                $label = (string) $val;
                break;
            }
        }
        if ( $label === '' ) {
            foreach ( $row['data'] as $key => $val ) {
                if ( ! is_array( $val ) && stripos( (string) $key, 'email' ) !== false ) {
                    $label = (string) $val;
                    break;
                }
            }
        }
        $label = $label !== '' ? sanitize_title( $label ) : 'entry';
        $date_prefix = preg_replace( '/[^0-9]/', '', substr( (string) $row['submitted_at'], 0, 10 ) ); // YYYYMMDD

        foreach ( $row['data'] as $val ) {
            if ( ! cmm4_is_file_value( $val ) ) {
                continue;
            }
            $source_path = trailingslashit( $private_dir ) . $val['stored_name'];
            if ( ! file_exists( $source_path ) ) {
                $missing_files[] = $val['original_name'] . ' (' . $label . ')';
                continue;
            }

            $ext          = pathinfo( $val['original_name'], PATHINFO_EXTENSION );
            $base_name    = pathinfo( $val['original_name'], PATHINFO_FILENAME );
            $zip_filename = 'resumes/' . $date_prefix . '_' . $label . '_' . sanitize_file_name( $base_name ) . ( $ext ? '.' . $ext : '' );

            // De-duplicate if two files from the same applicant/day still collide.
            $counter = 2;
            $final_name = $zip_filename;
            while ( in_array( $final_name, $used_zip_names, true ) ) {
                $final_name = 'resumes/' . $date_prefix . '_' . $label . '_' . sanitize_file_name( $base_name ) . '-' . $counter . ( $ext ? '.' . $ext : '' );
                $counter++;
            }
            $used_zip_names[] = $final_name;

            $zip->addFile( $source_path, $final_name );
        }
    }

    // If any files were referenced in the database but missing from disk
    // (e.g. manually deleted from the server), note that inside the ZIP
    // itself rather than silently producing an incomplete download.
    if ( ! empty( $missing_files ) ) {
        $zip->addFromString(
            'MISSING_FILES.txt',
            "The following files were listed in submissions but could not be found on the server and are NOT included in this ZIP:\n\n" . implode( "\n", $missing_files )
        );
    }

    $zip->close();

    $filename = 'applications-' . sanitize_title( $form_title ) . '-' . current_time( 'Y-m-d' ) . '.zip';

    nocache_headers();
    header( 'Content-Type: application/zip' );
    header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
    header( 'Content-Length: ' . filesize( $tmp_zip_path ) );

    readfile( $tmp_zip_path );

    @unlink( $tmp_zip_path );
    @unlink( $csv_tmp );
    exit;
}
add_action( 'admin_init', 'cmm4_maybe_export_zip' );

// ==========================================
// 3. ADMIN STYLES (MORANDI / MINIMALIST)
// ==========================================
function cmm4_admin_styles() {
    // Cast $_GET['page'] to string safely to protect strpos from null execution
    if ( isset( $_GET['page'] ) && ( strpos( (string) $_GET['page'], 'cmm4-' ) === 0 || $_GET['page'] === 'cmm4-edit' ) ) {
        echo '<style>
            .cmm4-admin-wrap { max-width: 900px; margin: 24px auto; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; color: #333; }
            /* Wider variant for pages with naturally wide content (e.g. the
               Submissions table, which can have a couple dozen columns) —
               scoped to its own class so every other admin screen keeps the
               narrower, centered 900px layout. */
            .cmm4-admin-wrap-wide { max-width: 100%; margin: 24px 24px; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; color: #333; }

            .cmm4-back-link { text-decoration: none; color: #8a8478; font-size: 13px; font-weight: 500; letter-spacing: 0.2px; transition: color 0.15s ease; }
            .cmm4-back-link:hover { color: #222; }

            .cmm4-title { font-size: 25px; font-weight: 300; letter-spacing: 0.3px; margin-bottom: 6px; color: #1a1a1a; }
            .cmm4-subtitle { color: #8a8478; margin-bottom: 28px; font-size: 14px; line-height: 1.5; }

            .cmm4-card { background: #faf9f8; border: 1px solid #e8e6e1; padding: 32px; border-radius: 6px; box-shadow: 0 1px 3px rgba(20,18,14,0.03), 0 8px 24px rgba(20,18,14,0.03); margin-bottom: 22px; transition: box-shadow 0.2s ease; }

            .cmm4-table { width: 100%; border-collapse: collapse; text-align: left; }
            .cmm4-table th, .cmm4-table td { padding: 14px 12px; border-bottom: 1px solid #f0ede9; vertical-align: middle; font-size: 13.5px; }
            .cmm4-table th { font-weight: 600; font-size: 11px; color: #8a8478; text-transform: uppercase; letter-spacing: 0.6px; border-bottom: 1px solid #e0dcd3; }
            .cmm4-table tr { transition: background-color 0.12s ease; }
            .cmm4-table tr:hover td { background-color: #f6f4f1; }
            .cmm4-table tr:last-child td { border-bottom: none; }

            .cmm4-input-text { width: 100%; max-width: 400px; padding: 11px 12px; border: 1px solid #d1c9bf; border-radius: 3px; background: #fff; outline: none; font-size: 14px; transition: border-color 0.15s ease, box-shadow 0.15s ease; }
            .cmm4-input-text:focus { border-color: #8a8478; box-shadow: 0 0 0 3px rgba(138,132,120,0.12); }

            .cmm4-btn-black { background: #1f1f1f; color: #fff; border: none; padding: 12px 26px; border-radius: 3px; cursor: pointer; text-transform: uppercase; font-size: 11px; font-weight: 600; letter-spacing: 1px; transition: background 0.15s ease, transform 0.1s ease; margin-top: 20px; display: inline-block; text-decoration: none; }
            .cmm4-btn-black:hover { background: #3a3a3a; color: #fff; transform: translateY(-1px); }
            .cmm4-btn-black:active { transform: translateY(0); }

            .cmm4-shortcode-box { background: #eeece7; padding: 6px 11px; border-radius: 3px; font-family: "SF Mono", Monaco, monospace; font-size: 12.5px; color: #444; }

            /* Submissions table: file links + row actions */
            .cmm4-file-link { color: #1a56db; text-decoration: none; font-weight: 500; border-bottom: 1px solid transparent; transition: border-color 0.15s ease; }
            .cmm4-file-link:hover { border-bottom-color: #1a56db; }
            .cmm4-file-size { color: #a39d90; font-size: 11.5px; margin-left: 2px; }

            .cmm4-col-actions { width: 1%; white-space: nowrap; text-align: right; }
            .cmm4-row-action { display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; border-radius: 4px; text-decoration: none; font-size: 14px; margin-left: 4px; transition: background-color 0.15s ease; color: #8a8478; }
            .cmm4-row-action:hover { background-color: #eeece7; color: #333; }
            .cmm4-row-action-delete:hover { background-color: #fdf0f0; color: #b03a3a; }

            .cmm4-link-action { color: #8a8478; text-decoration: none; font-weight: 500; transition: color 0.15s ease; }
            .cmm4-link-action:hover { color: #1a1a1a; }
            .cmm4-link-action-danger { color: #b5625f; }
            .cmm4-link-action-danger:hover { color: #8a3333; }
            .cmm4-link-sep { color: #d1c9bf; margin: 0 2px; }

            /* Submissions table: scrollable container (both axes), sticky
               header row, checkbox column, and a WP-style bulk actions bar. */
            .cmm4-table-scroll { overflow-x: auto; overflow-y: auto; max-height: 75vh; border: 1px solid #e8e6e1; border-radius: 5px; }
            .cmm4-table-scroll::-webkit-scrollbar { height: 12px; width: 12px; }
            .cmm4-table-scroll::-webkit-scrollbar-track { background: #f0ede9; }
            .cmm4-table-scroll::-webkit-scrollbar-thumb { background: #c9c2b6; border-radius: 6px; border: 3px solid #f0ede9; }
            .cmm4-table-scroll::-webkit-scrollbar-thumb:hover { background: #a39d90; }
            .cmm4-table-scroll .cmm4-table { min-width: max-content; }
            .cmm4-table-scroll .cmm4-table th { position: sticky; top: 0; background: #faf9f8; z-index: 2; box-shadow: 0 1px 0 #e0dcd3; }
            .cmm4-table-scroll .cmm4-table th.cmm4-col-check { position: sticky; left: 0; z-index: 3; background: #faf9f8; }
            .cmm4-table-scroll .cmm4-table td.cmm4-col-check { position: sticky; left: 0; background: #fff; z-index: 1; }
            .cmm4-table-scroll .cmm4-table tr:hover td.cmm4-col-check { background: #f6f4f1; }
            .cmm4-col-check { width: 1%; white-space: nowrap; text-align: center; }
            .cmm4-col-check input[type="checkbox"] { margin: 0; }
            td.cmm4-wrap-cell { max-width: 260px; white-space: normal; word-break: break-word; }

            .cmm4-bulk-bar { display: flex; align-items: center; gap: 8px; margin-bottom: 12px; }
            .cmm4-bulk-bar select { padding: 6px 8px; border: 1px solid #d1c9bf; border-radius: 3px; background: #fff; font-size: 13px; }
            .cmm4-bulk-apply-btn { background: #fff; color: #333; border: 1px solid #d1c9bf; padding: 7px 16px; border-radius: 3px; cursor: pointer; font-size: 12px; font-weight: 600; }
            .cmm4-bulk-apply-btn:hover { background: #f0ede9; }
            .cmm4-bulk-selected-count { font-size: 12px; color: #8a8478; }
        </style>';
    }
}
add_action( 'admin_head', 'cmm4_admin_styles' );

// ==========================================
// 4. DASHBOARD: FORM LIST & CREATION
// ==========================================
function cmm4_page_dashboard() {
    if ( isset( $_POST['cmm4_create_form'] ) && check_admin_referer( 'cmm4_create_action' ) ) {
        $title = isset( $_POST['new_form_title'] ) ? sanitize_text_field( wp_unslash( $_POST['new_form_title'] ) ) : '';
        if ( ! empty( $title ) ) {
            $post_id = wp_insert_post( array(
                'post_title'  => $title,
                'post_type'   => 'cmm4_form',
                'post_status' => 'publish'
            ) );
            
            $default_fields = "Full Name|text|required|Your Full Name *||\nEmail Address|email|required|Your Email Address *||\nMessage|textarea|required|Your Message...||";
            update_post_meta( $post_id, 'cmm4_fields', $default_fields );
            update_post_meta( $post_id, 'cmm4_target_email', get_bloginfo('admin_email') );
            echo '<div class="updated"><p>New routing canvas created.</p></div>';
        }
    }

    if ( isset( $_GET['delete'] ) && current_user_can('manage_options') ) {
        $delete_id = intval( $_GET['delete'] );
        if ( isset( $_GET['_wpnonce'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'cmm4_delete_' . $delete_id ) && get_post_type( $delete_id ) === 'cmm4_form' ) {
            wp_delete_post( $delete_id, true );
            echo '<div class="updated"><p>Form permanently dismantled.</p></div>';
        } else {
            echo '<div class="error"><p>Security check failed. The form was not deleted — please use the Delete link from the dashboard.</p></div>';
        }
    }

    $forms = get_posts( array( 'post_type' => 'cmm4_form', 'numberposts' => -1 ) );
    ?>
    <div class="cmm4-admin-wrap">
        <h1 class="cmm4-title">Routing Canvases</h1>
        <p class="cmm4-subtitle">Manage dynamic input structures and shortcode generation.</p>
        
        <div class="cmm4-card">
            <table class="cmm4-table">
                <tr><th>Form Blueprint Title</th><th>Deployment Shortcode</th><th>Target Inbox</th><th>Actions</th></tr>
                <?php foreach ( $forms as $form ) : 
                    $target = get_post_meta( $form->ID, 'cmm4_target_email', true );
                ?>
                <tr>
                    <td><strong><?php echo esc_html( $form->post_title ); ?></strong></td>
                    <td><span class="cmm4-shortcode-box">[cmm4_form id="<?php echo $form->ID; ?>"]</span></td>
                    <td><?php echo esc_html( $target ); ?></td>
                    <td>
                        <a href="?page=cmm4-edit&id=<?php echo $form->ID; ?>" class="cmm4-link-action">Configure</a>
                        <span class="cmm4-link-sep">·</span>
                        <a href="?page=cmm4-submissions&id=<?php echo $form->ID; ?>" class="cmm4-link-action">Submissions</a>
                        <span class="cmm4-link-sep">·</span>
                        <a href="<?php echo esc_url( wp_nonce_url( '?page=cmm4-dashboard&delete=' . $form->ID, 'cmm4_delete_' . $form->ID ) ); ?>" class="cmm4-link-action cmm4-link-action-danger" onclick="return confirm('Erase this form?');">Delete</a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if ( empty($forms) ) echo '<tr><td colspan="4">No forms instantiated.</td></tr>'; ?>
            </table>
        </div>

        <div class="cmm4-card" style="margin-top: 40px;">
            <h2 style="font-size: 18px; font-weight: 400; margin-top: 0;">Instantiate New Form</h2>
            <form method="post" action="">
                <?php wp_nonce_field( 'cmm4_create_action' ); ?>
                <input type="text" name="new_form_title" class="cmm4-input-text" placeholder="e.g., General Inquiries" required />
                <input type="submit" name="cmm4_create_form" class="cmm4-btn-black" value="Compile Blueprint" />
            </form>
        </div>
    </div>
    <?php
}

// ==========================================
// 4a. ADDONS SCREEN — enable/disable, versions, and load errors
// ==========================================
function cmm4_page_addons() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'You do not have permission to access this page.' );
    }

    $notice = '';

    if ( isset( $_POST['cmm4_addons_save'] ) && check_admin_referer( 'cmm4_addons_action', 'cmm4_addons_nonce' ) ) {
        $all_addons    = cmm4_get_available_addons();
        $enabled_slugs = isset( $_POST['cmm4_enabled_addons'] ) && is_array( $_POST['cmm4_enabled_addons'] )
            ? array_map( 'sanitize_key', wp_unslash( $_POST['cmm4_enabled_addons'] ) )
            : array();

        $disabled = array();
        foreach ( $all_addons as $slug => $addon ) {
            if ( ! in_array( $slug, $enabled_slugs, true ) ) {
                $disabled[] = $slug;
            }
        }
        update_option( 'cmm4_disabled_addons', $disabled );
        $notice = '<div class="updated"><p>Addon settings saved. Reload this page to see the updated status.</p></div>';
    }

    $addons   = cmm4_get_available_addons();
    $disabled = cmm4_get_disabled_addons();
    $errors   = cmm4_get_addon_errors();
    ?>
    <div class="cmm4-admin-wrap">
        <h1 class="cmm4-title">Addons</h1>
        <p class="cmm4-subtitle">Every folder inside <code>/addons</code> is picked up automatically. Turn any of them off here without deleting files — and if one addon has a problem, the rest of CMM Forms keeps working normally.</p>

        <?php echo $notice; ?>

        <?php if ( empty( $addons ) ) : ?>
            <div class="cmm4-card">
                <p style="margin:0; color:#8a8478;">No addons installed yet. Drop an addon folder into <code>/wp-content/plugins/&lt;this-plugin&gt;/addons/</code> to get started.</p>
            </div>
        <?php else : ?>
        <form method="post" action="">
            <?php wp_nonce_field( 'cmm4_addons_action', 'cmm4_addons_nonce' ); ?>
            <div class="cmm4-card">
                <table class="cmm4-table">
                    <tr>
                        <th style="width:40%;">Addon</th>
                        <th style="width:12%;">Version</th>
                        <th>Status</th>
                        <th style="width:90px;">Enabled</th>
                    </tr>
                    <?php foreach ( $addons as $slug => $addon ) :
                        $has_error   = isset( $errors[ $slug ] );
                        $is_disabled = in_array( $slug, $disabled, true );
                    ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html( $addon['name'] ); ?></strong>
                            <?php if ( $addon['description'] ) : ?>
                                <br><span style="color:#8a8478; font-size:12.5px;"><?php echo esc_html( $addon['description'] ); ?></span>
                            <?php endif; ?>
                            <?php if ( $addon['author'] ) : ?>
                                <br><span style="color:#a39d90; font-size:11.5px;">by <?php echo esc_html( $addon['author'] ); ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html( $addon['version'] ? $addon['version'] : '—' ); ?></td>
                        <td>
                            <?php if ( $has_error ) : ?>
                                <span style="color:#b5625f; font-weight:600;">⚠ Error</span><br>
                                <span style="font-size:12px; color:#a39d90;"><?php echo esc_html( $errors[ $slug ] ); ?></span>
                            <?php elseif ( $is_disabled ) : ?>
                                <span style="color:#a39d90;">Disabled</span>
                            <?php else : ?>
                                <span style="color:#4a7a4a; font-weight:600;">● Active</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <label><input type="checkbox" name="cmm4_enabled_addons[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( ! $is_disabled ); ?> /> On</label>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>
                <input type="submit" name="cmm4_addons_save" class="cmm4-btn-black" value="Save Addon Settings" />
            </div>
        </form>
        <?php endif; ?>
    </div>
    <?php
}

// ==========================================
// 5. EDIT FORM PAGE
// ==========================================
function cmm4_page_edit() {
    $form_id = isset( $_GET['id'] ) ? intval( $_GET['id'] ) : 0;
    if ( ! $form_id ) return;

    // Only ever edit CMM forms. Without this check, any post ID in the URL
    // could have its title and target email overwritten.
    if ( get_post_type( $form_id ) !== 'cmm4_form' ) {
        wp_die( 'That form could not be found.' );
    }

    if ( isset( $_POST['cmm4_save_form'] ) && check_admin_referer( 'cmm4_edit_action' ) ) {
        $posted_fields = isset( $_POST['cmm4_fields'] ) ? wp_unslash( $_POST['cmm4_fields'] ) : '';
        $posted_email  = isset( $_POST['cmm4_target_email'] ) ? wp_unslash( $_POST['cmm4_target_email'] ) : '';
        $posted_title  = isset( $_POST['cmm4_title'] ) ? wp_unslash( $_POST['cmm4_title'] ) : '';

        update_post_meta( $form_id, 'cmm4_fields', cmm4_sanitize_fields_raw( $posted_fields ) );
        update_post_meta( $form_id, 'cmm4_target_email', sanitize_email( $posted_email ) );
        wp_update_post( array( 'ID' => $form_id, 'post_title' => sanitize_text_field( $posted_title ) ) );
        echo '<div class="updated"><p>Form architecture updated.</p></div>';
    }

    $title  = get_the_title( $form_id );
    $fields = get_post_meta( $form_id, 'cmm4_fields', true );
    $target = get_post_meta( $form_id, 'cmm4_target_email', true );

    // Default raw editor markup. An addon (e.g. the Visual Field Editor) can
    // completely replace this via the 'cmm4_fields_editor_ui' filter — it
    // just needs to output a form control named "cmm4_fields" so saving
    // keeps working unchanged.
    $default_editor_html = '<textarea name="cmm4_fields" rows="8" class="cmm4-input-text" style="max-width:100%; font-family:monospace;">' . esc_textarea( $fields ) . '</textarea>';
    $editor_html = apply_filters( 'cmm4_fields_editor_ui', $default_editor_html, $form_id, $fields );
    ?>
    <div class="cmm4-admin-wrap">
        <a href="?page=cmm4-dashboard" class="cmm4-back-link">&larr; Return to Dashboard</a>
        <h1 class="cmm4-title" style="margin-top: 20px;">Configure Blueprint: <?php echo esc_html($title); ?></h1>
        
        <div class="cmm4-card">
            <form method="post" action="">
                <?php wp_nonce_field( 'cmm4_edit_action' ); ?>
                <table class="cmm4-table">
                    <tr>
                        <th style="width: 25%;">Form Title</th>
                        <td><input type="text" name="cmm4_title" value="<?php echo esc_attr($title); ?>" class="cmm4-input-text" required /></td>
                    </tr>
                    <tr><th>Target Delivery Inbox</th><td><input type="email" name="cmm4_target_email" value="<?php echo esc_attr($target); ?>" class="cmm4-input-text" required /></td></tr>
                    <tr>
                        <th style="vertical-align:top; padding-top:20px;">Form Fields</th>
                        <td><?php echo $editor_html; ?></td>
                    </tr>
                </table>
                <input type="submit" name="cmm4_save_form" class="cmm4-btn-black" value="Lock Architecture" />
            </form>
        </div>
    </div>
    <?php
}

// ==========================================
// 5a-i. DELETE A SINGLE SUBMISSION (+ ITS ATTACHED FILE, IF ANY)
// ==========================================
// Same security pattern as the existing form-delete link on the dashboard:
// capability check + a nonce scoped to the specific entry being removed, so
// a crafted link can't delete an arbitrary row (CSRF-safe, matches the
// precedent already established elsewhere in this plugin).
function cmm4_maybe_delete_submission() {
    if ( ! is_admin() || ! isset( $_GET['cmm4_delete_entry'] ) ) {
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $entry_id = intval( $_GET['cmm4_delete_entry'] );
    $form_id  = isset( $_GET['id'] ) ? intval( $_GET['id'] ) : 0;
    if ( ! $entry_id || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'cmm4_delete_entry_' . $entry_id ) ) {
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'cmm4_submissions';

    // Read the row first so any attached file can be cleaned up from disk —
    // deleting only the database row would silently orphan the file forever.
    $row = $wpdb->get_row( $wpdb->prepare( "SELECT submission_data FROM {$table_name} WHERE id = %d AND form_id = %d", $entry_id, $form_id ) );
    if ( $row ) {
        $data = json_decode( $row->submission_data, true );
        if ( is_array( $data ) ) {
            foreach ( $data as $val ) {
                if ( cmm4_is_file_value( $val ) ) {
                    $file_path = trailingslashit( cmm4_get_private_upload_dir() ) . $val['stored_name'];
                    if ( file_exists( $file_path ) ) {
                        @unlink( $file_path );
                    }
                }
            }
        }
        $wpdb->delete( $table_name, array( 'id' => $entry_id, 'form_id' => $form_id ), array( '%d', '%d' ) );
    }

    // Redirect back to the same Submissions page (without the delete params
    // in the URL) so refreshing the page can't re-trigger the delete action.
    wp_safe_redirect( admin_url( 'admin.php?page=cmm4-submissions&id=' . $form_id . '&cmm4_deleted=1' ) );
    exit;
}
add_action( 'admin_init', 'cmm4_maybe_delete_submission' );

// ==========================================
// 5a-ii. BULK DELETE SELECTED SUBMISSIONS (+ THEIR ATTACHED FILES)
// ==========================================
// Same capability + nonce pattern as the single-entry delete above, scoped
// to a bulk action nonce keyed to the form ID so a crafted link can't be
// reused across forms or replayed after the fact.
function cmm4_maybe_bulk_delete_submissions() {
    if ( ! is_admin() || ! isset( $_POST['cmm4_bulk_action'] ) ) {
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $form_id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
    if ( ! $form_id || ! isset( $_POST['cmm4_bulk_nonce'] ) || ! wp_verify_nonce( $_POST['cmm4_bulk_nonce'], 'cmm4_bulk_action_' . $form_id ) ) {
        return;
    }

    $action = sanitize_text_field( wp_unslash( $_POST['cmm4_bulk_action'] ) );
    if ( $action !== 'delete' ) {
        return; // Only "delete" exists today; unrecognized actions are ignored rather than guessed at.
    }

    $entry_ids = isset( $_POST['cmm4_entry_ids'] ) && is_array( $_POST['cmm4_entry_ids'] ) ? array_map( 'intval', $_POST['cmm4_entry_ids'] ) : array();
    $entry_ids = array_filter( array_unique( $entry_ids ) );
    if ( empty( $entry_ids ) ) {
        wp_safe_redirect( admin_url( 'admin.php?page=cmm4-submissions&id=' . $form_id . '&cmm4_bulk_error=none_selected' ) );
        exit;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'cmm4_submissions';

    $placeholders = implode( ',', array_fill( 0, count( $entry_ids ), '%d' ) );
    $query_args   = array_merge( $entry_ids, array( $form_id ) );
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT id, submission_data FROM {$table_name} WHERE id IN ({$placeholders}) AND form_id = %d",
        $query_args
    ) );

    $deleted_count = 0;
    foreach ( $rows as $row ) {
        $data = json_decode( $row->submission_data, true );
        if ( is_array( $data ) ) {
            foreach ( $data as $val ) {
                if ( cmm4_is_file_value( $val ) ) {
                    $file_path = trailingslashit( cmm4_get_private_upload_dir() ) . $val['stored_name'];
                    if ( file_exists( $file_path ) ) {
                        @unlink( $file_path );
                    }
                }
            }
        }
        $wpdb->delete( $table_name, array( 'id' => $row->id, 'form_id' => $form_id ), array( '%d', '%d' ) );
        $deleted_count++;
    }

    wp_safe_redirect( admin_url( 'admin.php?page=cmm4-submissions&id=' . $form_id . '&cmm4_bulk_deleted=' . $deleted_count ) );
    exit;
}
add_action( 'admin_init', 'cmm4_maybe_bulk_delete_submissions' );

// ==========================================
// 5a. SUBMISSIONS LOG PAGE (VIEW + CSV DOWNLOAD)
// ==========================================
function cmm4_page_submissions() {
    $form_id = isset( $_GET['id'] ) ? intval( $_GET['id'] ) : 0;
    if ( ! $form_id ) return;

    global $wpdb;
    $table_name = $wpdb->prefix . 'cmm4_submissions';
    $title      = get_the_title( $form_id );

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$table_name} WHERE form_id = %d ORDER BY submitted_at DESC LIMIT 200",
        $form_id
    ) );

    $export_url     = wp_nonce_url( admin_url( 'admin.php?page=cmm4-submissions&cmm4_export=csv&form_id=' . $form_id ), 'cmm4_export_' . $form_id );
    $zip_export_url = wp_nonce_url( admin_url( 'admin.php?page=cmm4-submissions&cmm4_export=zip&form_id=' . $form_id ), 'cmm4_export_' . $form_id );
    $bulk_nonce = wp_create_nonce( 'cmm4_bulk_action_' . $form_id );

    // Work out which columns to show in the on-screen table (union of keys, capped for readability).
    $all_keys = array();
    $parsed   = array();
    foreach ( $rows as $row ) {
        $data = json_decode( $row->submission_data, true );
        if ( ! is_array( $data ) ) $data = array();
        foreach ( $data as $key => $val ) {
            if ( ! in_array( $key, $all_keys, true ) ) $all_keys[] = $key;
        }
        $parsed[] = array( 'id' => $row->id, 'data' => $data, 'submitted_at' => $row->submitted_at );
    }
    ?>
    <div class="cmm4-admin-wrap cmm4-admin-wrap-wide">
        <a href="?page=cmm4-dashboard" class="cmm4-back-link">&larr; Return to Dashboard</a>
        <h1 class="cmm4-title" style="margin-top: 20px;">Submissions: <?php echo esc_html( $title ); ?></h1>
        <p class="cmm4-subtitle">Showing the most recent <?php echo count( $rows ); ?> entries. Download the full log as a CSV file for Excel.</p>

        <?php if ( isset( $_GET['cmm4_deleted'] ) ) : ?>
            <div class="updated"><p>Submission deleted.</p></div>
        <?php endif; ?>
        <?php if ( isset( $_GET['cmm4_bulk_deleted'] ) ) : ?>
            <div class="updated"><p><?php echo intval( $_GET['cmm4_bulk_deleted'] ); ?> submission(s) deleted.</p></div>
        <?php endif; ?>
        <?php if ( isset( $_GET['cmm4_bulk_error'] ) && $_GET['cmm4_bulk_error'] === 'none_selected' ) : ?>
            <div class="error"><p>No submissions were selected.</p></div>
        <?php endif; ?>

        <a href="<?php echo esc_url( $export_url ); ?>" class="cmm4-btn-black" style="margin-bottom: 20px;">Download CSV (Excel)</a>
        <a href="<?php echo esc_url( $zip_export_url ); ?>" class="cmm4-btn-black" style="margin-bottom: 20px; margin-left: 10px;">⬇ Download All (ZIP: Data + Resumes)</a>

        <div class="cmm4-card" style="overflow: visible;">
            <?php if ( empty( $rows ) ) : ?>
                <p>No submissions received yet.</p>
            <?php else : ?>
                <form method="post" action="" id="cmm4-bulk-form" onsubmit="return cmm4ConfirmBulkDelete(event);">
                    <input type="hidden" name="id" value="<?php echo esc_attr( $form_id ); ?>" />
                    <input type="hidden" name="cmm4_bulk_nonce" value="<?php echo esc_attr( $bulk_nonce ); ?>" />

                    <div class="cmm4-bulk-bar">
                        <select name="cmm4_bulk_action" id="cmm4-bulk-action-select">
                            <option value="">Bulk actions</option>
                            <option value="delete">Delete</option>
                        </select>
                        <button type="submit" class="cmm4-bulk-apply-btn">Apply</button>
                        <span class="cmm4-bulk-selected-count" id="cmm4-bulk-selected-count"></span>
                    </div>

                    <div class="cmm4-table-scroll">
                        <table class="cmm4-table">
                            <tr>
                                <th class="cmm4-col-check"><input type="checkbox" id="cmm4-check-all" /></th>
                                <th>Submitted At</th>
                                <?php foreach ( $all_keys as $key ) : ?>
                                    <th><?php echo esc_html( ucfirst( str_replace( '-', ' ', $key ) ) ); ?></th>
                                <?php endforeach; ?>
                                <th class="cmm4-col-actions">Actions</th>
                            </tr>
                            <?php foreach ( $parsed as $row ) :
                                $entry_export_url = wp_nonce_url(
                                    admin_url( 'admin.php?page=cmm4-submissions&cmm4_export=csv&form_id=' . $form_id . '&entry_id=' . $row['id'] ),
                                    'cmm4_export_' . $form_id
                                );
                                $entry_delete_url = wp_nonce_url(
                                    admin_url( 'admin.php?page=cmm4-submissions&id=' . $form_id . '&cmm4_delete_entry=' . $row['id'] ),
                                    'cmm4_delete_entry_' . $row['id']
                                );
                            ?>
                                <tr>
                                    <td class="cmm4-col-check"><input type="checkbox" class="cmm4-row-check" name="cmm4_entry_ids[]" value="<?php echo esc_attr( $row['id'] ); ?>" /></td>
                                    <td><?php echo esc_html( $row['submitted_at'] ); ?></td>
                                    <?php foreach ( $all_keys as $key ) :
                                        $val = isset( $row['data'][ $key ] ) ? $row['data'][ $key ] : '';
                                    ?>
                                        <td class="cmm4-wrap-cell">
                                            <?php if ( cmm4_is_file_value( $val ) ) : ?>
                                                <a href="<?php echo esc_url( cmm4_get_file_download_url( $val['stored_name'], $val['original_name'] ) ); ?>" class="cmm4-file-link">📎 <?php echo esc_html( $val['original_name'] ); ?></a>
                                                <span class="cmm4-file-size">(<?php echo esc_html( size_format( $val['size'] ) ); ?>)</span>
                                            <?php else : ?>
                                                <?php echo esc_html( is_array( $val ) ? implode( ', ', $val ) : $val ); ?>
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
                                    <td class="cmm4-col-actions">
                                        <a href="<?php echo esc_url( $entry_export_url ); ?>" class="cmm4-row-action" title="Download this entry as CSV">⬇</a>
                                        <a href="<?php echo esc_url( $entry_delete_url ); ?>" class="cmm4-row-action cmm4-row-action-delete" title="Delete this submission" onclick="return confirm('Delete this submission? This also removes any attached file. This cannot be undone.');">🗑</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                    </div>
                </form>

                <script>
                (function() {
                    var checkAll   = document.getElementById('cmm4-check-all');
                    var rowChecks  = document.querySelectorAll('.cmm4-row-check');
                    var countLabel = document.getElementById('cmm4-bulk-selected-count');
                    var actionSel  = document.getElementById('cmm4-bulk-action-select');

                    function updateCount() {
                        var checked = document.querySelectorAll('.cmm4-row-check:checked').length;
                        countLabel.textContent = checked > 0 ? checked + ' selected' : '';
                    }

                    if ( checkAll ) {
                        checkAll.addEventListener('change', function() {
                            rowChecks.forEach(function(cb) { cb.checked = checkAll.checked; });
                            updateCount();
                        });
                    }
                    rowChecks.forEach(function(cb) {
                        cb.addEventListener('change', function() {
                            if ( ! cb.checked && checkAll ) { checkAll.checked = false; }
                            updateCount();
                        });
                    });

                    window.cmm4ConfirmBulkDelete = function(evt) {
                        var action = actionSel.value;
                        var checked = document.querySelectorAll('.cmm4-row-check:checked').length;
                        if ( ! action ) {
                            evt.preventDefault();
                            alert('Choose a bulk action first.');
                            return false;
                        }
                        if ( checked === 0 ) {
                            evt.preventDefault();
                            alert('Select at least one submission first.');
                            return false;
                        }
                        if ( action === 'delete' ) {
                            return confirm('Delete ' + checked + ' selected submission(s)? This also removes any attached files. This cannot be undone.');
                        }
                        return true;
                    };
                })();
                </script>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

// ==========================================
// 6. SMTP, AUTOREPLY & SECURITY SUBMENUS
// ==========================================
function cmm4_page_smtp_settings() {
    if ( isset( $_POST['cmm4_save_smtp'] ) && check_admin_referer( 'cmm4_smtp_action', 'cmm4_smtp_nonce' ) ) {
        update_option( 'cmm_smtp_host', isset( $_POST['cmm_smtp_host'] ) ? sanitize_text_field( wp_unslash( $_POST['cmm_smtp_host'] ) ) : '' );
        update_option( 'cmm_smtp_port', isset( $_POST['cmm_smtp_port'] ) ? absint( $_POST['cmm_smtp_port'] ) : 587 );
        update_option( 'cmm_smtp_auth', isset( $_POST['cmm4_smtp_auth'] ) ? 'yes' : 'no' );
        update_option( 'cmm_smtp_user', isset( $_POST['cmm_smtp_user'] ) ? sanitize_text_field( wp_unslash( $_POST['cmm_smtp_user'] ) ) : '' );
        // Stored exactly as typed. sanitize_text_field() would strip anything that looks
        // like an HTML tag, which can silently change a password.
        if ( ! empty( $_POST['cmm_smtp_pass'] ) ) {
            update_option( 'cmm_smtp_pass', trim( str_replace( array( "\r", "\n" ), '', wp_unslash( $_POST['cmm_smtp_pass'] ) ) ) );
        }
        update_option( 'cmm_smtp_secure', isset( $_POST['cmm_smtp_secure'] ) ? sanitize_text_field( wp_unslash( $_POST['cmm_smtp_secure'] ) ) : 'tls' );
        update_option( 'cmm_from_email', isset( $_POST['cmm_from_email'] ) ? sanitize_email( wp_unslash( $_POST['cmm_from_email'] ) ) : '' );
        update_option( 'cmm_from_name', isset( $_POST['cmm_from_name'] ) ? sanitize_text_field( wp_unslash( $_POST['cmm_from_name'] ) ) : '' );
        echo '<div class="updated"><p>SMTP delivery settings updated.</p></div>';
    }

    $host   = get_option( 'cmm_smtp_host', '' );
    $port   = get_option( 'cmm_smtp_port', '587' );
    $auth   = get_option( 'cmm_smtp_auth', 'yes' );
    $user   = get_option( 'cmm_smtp_user', '' );
    $secure = get_option( 'cmm_smtp_secure', 'tls' );
    $from_e = get_option( 'cmm_from_email', get_bloginfo( 'admin_email' ) );
    $from_n = get_option( 'cmm_from_name', get_bloginfo( 'name' ) );
    ?>
    <div class="cmm4-admin-wrap">
        <h1 class="cmm4-title">SMTP Mailer Gateway</h1>
        <p class="cmm4-subtitle">Configure private transactional relays to secure baseline system deliverability parameters.</p>
        <div class="cmm4-card">
            <form method="post" action="">
                <?php wp_nonce_field( 'cmm4_smtp_action', 'cmm4_smtp_nonce' ); ?>
                <table class="cmm4-table">
                    <tr><th style="width: 25%;">Outgoing Mail Host</th><td><input type="text" name="cmm_smtp_host" value="<?php echo esc_attr($host); ?>" class="cmm4-input-text" placeholder="smtp.mailgun.org" required /></td></tr>
                    <tr><th>Server Target Port</th><td><input type="number" name="cmm_smtp_port" value="<?php echo esc_attr($port); ?>" class="cmm4-input-text" style="max-width:100px;" required /></td></tr>
                    <tr>
                        <th>Encryption Architecture</th>
                        <td>
                            <select name="cmm_smtp_secure" style="border-radius:0; border-color:#d1c9bf; height:40px; width:120px;">
                                <option value="none" <?php selected($secure, 'none'); ?>>None</option>
                                <option value="ssl" <?php selected($secure, 'ssl'); ?>>SSL</option>
                                <option value="tls" <?php selected($secure, 'tls'); ?>>TLS</option>
                            </select>
                        </td>
                    </tr>
                    <tr><th>Authentication Filter</th><td><label><input type="checkbox" name="cmm4_smtp_auth" value="yes" <?php checked($auth, 'yes'); ?> /> Require encrypted server verification</label></td></tr>
                    <tr><th>Server User Login</th><td><input type="text" name="cmm_smtp_user" value="<?php echo esc_attr($user); ?>" class="cmm4-input-text" autocomplete="off" /></td></tr>
                    <tr><th>Server Access Key</th><td><input type="password" name="cmm_smtp_pass" class="cmm4-input-text" placeholder="••••••••" autocomplete="new-password" /></td></tr>
                    <tr><td colspan="2"><hr style="border:0; border-top:1px solid #f0ede9; margin: 20px 0;"></td></tr>
                    <tr><th>Global From Address</th><td><input type="email" name="cmm_from_email" value="<?php echo esc_attr($from_e); ?>" class="cmm4-input-text" required /></td></tr>
                    <tr><th>Sender Identity Display</th><td><input type="text" name="cmm_from_name" value="<?php echo esc_attr($from_n); ?>" class="cmm4-input-text" required /></td></tr>
                </table>
                <input type="submit" name="cmm4_save_smtp" class="cmm4-btn-black" value="Lock Server Credentials" />
            </form>
        </div>
    </div>
    <?php
}

function cmm4_page_autoreply_settings() {
    if ( isset( $_POST['cmm4_save_autoreply'] ) && check_admin_referer( 'cmm4_auto_action', 'cmm4_auto_nonce' ) ) {
        update_option( 'cmm_auto_enable', isset( $_POST['cmm_auto_enable'] ) ? 'yes' : 'no' );
        update_option( 'cmm_auto_subject', isset( $_POST['cmm_auto_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['cmm_auto_subject'] ) ) : '' );
        update_option( 'cmm_auto_body', isset( $_POST['cmm_auto_body'] ) ? wp_kses_post( wp_unslash( $_POST['cmm_auto_body'] ) ) : '' );
        echo '<div class="updated"><p>Automated responses tracking criteria established.</p></div>';
    }

    $enabled = get_option( 'cmm_auto_enable', 'yes' );
    $subject = get_option( 'cmm_auto_subject', 'Transmission Successfully Logged' );
    $body    = get_option( 'cmm_auto_body', "Thank you for reaching out.\n\nWe have safely received your transmission. A dedicated team member will review your message details and follow up directly." );
    ?>
    <div class="cmm4-admin-wrap">
        <h1 class="cmm4-title">Autoreply Layout Studio</h1>
        <p class="cmm4-subtitle">Compose clean, behavioral customer response tracking cards. Applied globally across all active forms.</p>
        <div class="cmm4-card">
            <form method="post" action="">
                <?php wp_nonce_field( 'cmm4_auto_action', 'cmm4_auto_nonce' ); ?>
                <table class="cmm4-table">
                    <tr><th style="width: 25%;">Active Automation Rule</th><td><label><input type="checkbox" name="cmm_auto_enable" value="yes" <?php checked($enabled, 'yes'); ?> /> Deploy instantaneous execution on user submit events</label></td></tr>
                    <tr><th>Client Subject Line</th><td><input type="text" name="cmm_auto_subject" value="<?php echo esc_attr($subject); ?>" class="cmm4-input-text" style="max-width:550px;" required /></td></tr>
                    <tr><th>Content Blueprint Body</th><td><?php wp_editor( $body, 'cmm_auto_body', array( 'textarea_rows' => 12, 'media_buttons' => false ) ); ?></td></tr>
                </table>
                <input type="submit" name="cmm4_save_autoreply" class="cmm4-btn-black" value="Compile Automation Card" />
            </form>
        </div>
    </div>
    <?php
}

// ==========================================
// 6a. EMAIL BRANDING (OPTIONAL LOGO) SETTINGS
// ==========================================
function cmm4_page_branding_settings() {
    if ( isset( $_POST['cmm4_save_branding'] ) && check_admin_referer( 'cmm4_branding_action', 'cmm4_branding_nonce' ) ) {
        $posted_position  = isset( $_POST['cmm_logo_position'] ) ? wp_unslash( $_POST['cmm_logo_position'] ) : '';
        $posted_alignment = isset( $_POST['cmm_logo_alignment'] ) ? wp_unslash( $_POST['cmm_logo_alignment'] ) : '';
        $posted_url       = isset( $_POST['cmm_logo_url'] ) ? wp_unslash( $_POST['cmm_logo_url'] ) : '';
        $posted_width     = isset( $_POST['cmm_logo_width'] ) ? wp_unslash( $_POST['cmm_logo_width'] ) : 160;
        $posted_padding   = isset( $_POST['cmm_logo_padding'] ) ? wp_unslash( $_POST['cmm_logo_padding'] ) : 20;

        update_option( 'cmm_logo_enable', isset( $_POST['cmm_logo_enable'] ) ? 'yes' : 'no' );
        update_option( 'cmm_logo_url', esc_url_raw( $posted_url ) );
        update_option( 'cmm_logo_position', in_array( $posted_position, array( 'top', 'bottom' ), true ) ? $posted_position : 'top' );
        update_option( 'cmm_logo_alignment', in_array( $posted_alignment, array( 'left', 'center', 'right' ), true ) ? $posted_alignment : 'center' );
        update_option( 'cmm_logo_width', max( 20, min( 400, absint( $posted_width ) ) ) );
        update_option( 'cmm_logo_padding', max( 0, min( 80, absint( $posted_padding ) ) ) );
        echo '<div class="updated"><p>Email branding updated.</p></div>';
    }

    $enable    = get_option( 'cmm_logo_enable', 'no' );
    $logo_url  = get_option( 'cmm_logo_url', '' );
    $position  = get_option( 'cmm_logo_position', 'top' );
    $alignment = get_option( 'cmm_logo_alignment', 'center' );
    $width     = get_option( 'cmm_logo_width', 160 );
    $padding   = get_option( 'cmm_logo_padding', 20 );

    wp_enqueue_media();
    ?>
    <div class="cmm4-admin-wrap">
        <h1 class="cmm4-title">Email Branding</h1>
        <p class="cmm4-subtitle">Optionally add your logo to outgoing notification and autoreply emails. Leave disabled to keep emails logo-free.</p>

        <div class="cmm4-card">
            <form method="post" action="" id="cmm4-branding-form">
                <?php wp_nonce_field( 'cmm4_branding_action', 'cmm4_branding_nonce' ); ?>
                <table class="cmm4-table">
                    <tr>
                        <th style="width: 25%;">Show Logo In Emails</th>
                        <td><label><input type="checkbox" id="cmm_logo_enable" name="cmm_logo_enable" value="yes" <?php checked($enable, 'yes'); ?> /> Enable optional logo on emails</label></td>
                    </tr>
                    <tr>
                        <th>Logo Image</th>
                        <td>
                            <input type="text" id="cmm_logo_url" name="cmm_logo_url" value="<?php echo esc_attr($logo_url); ?>" class="cmm4-input-text" placeholder="https://yoursite.com/logo.png" style="max-width:400px; display:inline-block; vertical-align:middle;" />
                            <button type="button" class="cmm4-btn-black" id="cmm4-logo-upload-btn" style="margin-top:0; vertical-align:middle; padding:10px 18px;">Choose Image</button>
                            <div style="margin-top:15px;">
                                <img id="cmm4-logo-preview-img" src="<?php echo esc_url($logo_url); ?>" style="max-width:200px; max-height:100px; <?php echo empty($logo_url) ? 'display:none;' : ''; ?> border:1px solid #e8e6e1; padding:8px; background:#fff;" />
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th>Logo Placement</th>
                        <td>
                            <select id="cmm_logo_position" name="cmm_logo_position" style="border-radius:0; border-color:#d1c9bf; height:40px; width:160px;">
                                <option value="top" <?php selected($position, 'top'); ?>>Top of email</option>
                                <option value="bottom" <?php selected($position, 'bottom'); ?>>Bottom of email</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>Logo Alignment</th>
                        <td>
                            <select id="cmm_logo_alignment" name="cmm_logo_alignment" style="border-radius:0; border-color:#d1c9bf; height:40px; width:160px;">
                                <option value="left" <?php selected($alignment, 'left'); ?>>Left</option>
                                <option value="center" <?php selected($alignment, 'center'); ?>>Center</option>
                                <option value="right" <?php selected($alignment, 'right'); ?>>Right</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>Logo Width (px)</th>
                        <td><input type="number" id="cmm_logo_width" name="cmm_logo_width" value="<?php echo esc_attr($width); ?>" min="20" max="400" class="cmm4-input-text" style="max-width:100px;" /></td>
                    </tr>
                    <tr>
                        <th>Logo Padding (px)</th>
                        <td><input type="number" id="cmm_logo_padding" name="cmm_logo_padding" value="<?php echo esc_attr($padding); ?>" min="0" max="80" class="cmm4-input-text" style="max-width:100px;" /><br><small style="color:#888;">Space around the logo, on all sides.</small></td>
                    </tr>
                </table>
                <input type="submit" name="cmm4_save_branding" class="cmm4-btn-black" value="Save Branding Settings" />
            </form>
        </div>

        <div class="cmm4-card">
            <h2 style="font-size: 16px; font-weight: 400; margin-top: 0;">Live Preview</h2>
            <p style="color:#888; font-size:13px; margin-top:-10px;">This mirrors exactly how the logo will appear inside your notification and autoreply emails.</p>
            <div id="cmm4-logo-preview-wrap" style="border:1px solid #e8e6e1; background:#f4f3f1; padding:20px;">
                <div id="cmm4-logo-preview-cell" style="background:#fff; border:1px solid #e8e6e1; border-radius:4px; max-width:600px; margin:0 auto; overflow:hidden;">
                    <div id="cmm4-logo-preview-slot-top" style="text-align:center;"></div>
                    <div style="padding:20px 30px; color:#999; font-size:13px; text-align:center; border-top:1px dashed #eee; border-bottom:1px dashed #eee;">— email content —</div>
                    <div id="cmm4-logo-preview-slot-bottom" style="text-align:center;"></div>
                </div>
            </div>
        </div>
    </div>
    <script>
    (function() {
        var enableEl    = document.getElementById('cmm_logo_enable');
        var urlEl       = document.getElementById('cmm_logo_url');
        var positionEl  = document.getElementById('cmm_logo_position');
        var alignEl     = document.getElementById('cmm_logo_alignment');
        var widthEl     = document.getElementById('cmm_logo_width');
        var paddingEl   = document.getElementById('cmm_logo_padding');
        var previewImg  = document.getElementById('cmm4-logo-preview-img');
        var slotTop     = document.getElementById('cmm4-logo-preview-slot-top');
        var slotBottom  = document.getElementById('cmm4-logo-preview-slot-bottom');

        function renderPreview() {
            slotTop.innerHTML = '';
            slotBottom.innerHTML = '';

            var url = urlEl.value.trim();
            if ( ! enableEl.checked || ! url ) {
                return;
            }

            var align = alignEl.value;
            var width = parseInt(widthEl.value, 10) || 160;
            var pad   = parseInt(paddingEl.value, 10);
            if ( isNaN(pad) ) pad = 20;

            var img = document.createElement('img');
            img.src = url;
            img.style.maxWidth = width + 'px';
            img.style.display = 'inline-block';

            var cell = document.createElement('div');
            cell.style.padding = pad + 'px';
            cell.style.textAlign = align;
            cell.appendChild(img);

            if ( positionEl.value === 'top' ) {
                slotTop.appendChild(cell);
            } else {
                slotBottom.appendChild(cell);
            }
        }

        [enableEl, urlEl, positionEl, alignEl, widthEl, paddingEl].forEach(function(el) {
            el.addEventListener('input', renderPreview);
            el.addEventListener('change', renderPreview);
        });
        renderPreview();

        // Media uploader
        var uploadBtn = document.getElementById('cmm4-logo-upload-btn');
        uploadBtn.addEventListener('click', function(e) {
            e.preventDefault();
            var frame = wp.media({
                title: 'Select or Upload Logo',
                button: { text: 'Use this image' },
                multiple: false
            });
            frame.on('select', function() {
                var attachment = frame.state().get('selection').first().toJSON();
                urlEl.value = attachment.url;
                previewImg.src = attachment.url;
                previewImg.style.display = 'inline-block';
                renderPreview();
            });
            frame.open();
        });
    })();
    </script>
    <?php
}

function cmm4_page_security_settings() {
    if ( isset( $_POST['cmm4_save_security'] ) && check_admin_referer( 'cmm4_secure_action', 'cmm4_secure_nonce' ) ) {
        update_option( 'cmm_recaptcha_enable', isset( $_POST['cmm_recaptcha_enable'] ) ? 'yes' : 'no' );
        update_option( 'cmm_recaptcha_site_key', isset( $_POST['cmm_recaptcha_site_key'] ) ? sanitize_text_field( wp_unslash( $_POST['cmm_recaptcha_site_key'] ) ) : '' );
        update_option( 'cmm_recaptcha_secret_key', isset( $_POST['cmm_recaptcha_secret_key'] ) ? sanitize_text_field( wp_unslash( $_POST['cmm_recaptcha_secret_key'] ) ) : '' );
        echo '<div class="updated"><p>Security configuration criteria compiled.</p></div>';
    }

    $recaptcha_enable = get_option( 'cmm_recaptcha_enable', 'no' );
    $site_key         = get_option( 'cmm_recaptcha_site_key', '' );
    $secret_key       = get_option( 'cmm_recaptcha_secret_key', '' );
    ?>
    <div class="cmm4-admin-wrap">
        <h1 class="cmm4-title">Anti-Spam Security Shield</h1>
        <p class="cmm4-subtitle">Run zero-friction asynchronous verification parameters directly over API server channels.</p>
        <div class="cmm4-card">
            <form method="post" action="">
                <?php wp_nonce_field( 'cmm4_secure_action', 'cmm4_secure_nonce' ); ?>
                <table class="cmm4-table">
                    <tr><th style="width: 25%;">Background Security Guard</th><td><label><input type="checkbox" name="cmm_recaptcha_enable" value="yes" <?php checked($recaptcha_enable, 'yes'); ?> /> Enforce automated algorithmic risk assessment filters (reCAPTCHA v3)</label></td></tr>
                    <tr><th>Public Site Verification Token</th><td><input type="text" name="cmm_recaptcha_site_key" value="<?php echo esc_attr($site_key); ?>" class="cmm4-input-text" style="max-width:550px;" /></td></tr>
                    <tr><th>Private API Handshake Key</th><td><input type="password" name="cmm_recaptcha_secret_key" value="<?php echo esc_attr($secret_key); ?>" class="cmm4-input-text" style="max-width:550px;" /></td></tr>
                </table>
                <input type="submit" name="cmm4_save_security" class="cmm4-btn-black" value="Activate API Firewalls" />
            </form>
        </div>
    </div>
    <?php
}

// ==========================================
// 6b. DATA RETENTION (WHAT HAPPENS WHEN THE PLUGIN IS DELETED)
// ==========================================
// Controls what uninstall.php does when the plugin is deleted from the Plugins
// screen. 'no' (the default) keeps forms, submissions, settings and uploaded
// files. 'yes' erases all of it. Deactivating the plugin never deletes data,
// whatever this setting says.
function cmm4_page_data_retention() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'You do not have permission to access this page.' );
    }

    if ( isset( $_POST['cmm4_save_retention'] ) && check_admin_referer( 'cmm4_retention_action', 'cmm4_retention_nonce' ) ) {
        $choice = isset( $_POST['cmm_delete_data_on_uninstall'] ) ? wp_unslash( $_POST['cmm_delete_data_on_uninstall'] ) : 'no';
        update_option( 'cmm_delete_data_on_uninstall', $choice === 'yes' ? 'yes' : 'no' );
        echo '<div class="updated"><p>Data retention setting saved.</p></div>';
    }

    $current = get_option( 'cmm_delete_data_on_uninstall', 'no' ) === 'yes' ? 'yes' : 'no';
    ?>
    <div class="cmm4-admin-wrap">
        <h1 class="cmm4-title">Data Retention</h1>
        <p class="cmm4-subtitle">Choose what happens to your forms, submissions, uploaded resumes and settings if this plugin is ever deleted from the Plugins screen.</p>

        <?php if ( $current === 'yes' ) : ?>
            <div class="cmm4-card" style="border-left:4px solid #b5625f;">
                <strong style="color:#8a3333;">Current setting: delete all data when the plugin is deleted.</strong>
                <p style="margin:6px 0 0 0; color:#555; font-size:13px;">Deleting the plugin will permanently erase everything listed below. Deactivating it never deletes anything.</p>
            </div>
        <?php else : ?>
            <div class="cmm4-card" style="border-left:4px solid #4a7a4a;">
                <strong style="color:#2d4a2d;">Current setting: keep all data (recommended).</strong>
                <p style="margin:6px 0 0 0; color:#555; font-size:13px;">Deleting the plugin removes only its files. Your forms, submissions, resumes and settings stay in place.</p>
            </div>
        <?php endif; ?>

        <div class="cmm4-card">
            <form method="post" action="" id="cmm4-retention-form">
                <?php wp_nonce_field( 'cmm4_retention_action', 'cmm4_retention_nonce' ); ?>
                <table class="cmm4-table">
                    <tr>
                        <th style="width:25%; vertical-align:top;">When the plugin is deleted</th>
                        <td>
                            <label style="display:block; margin-bottom:18px;">
                                <input type="radio" name="cmm_delete_data_on_uninstall" value="no" <?php checked( $current, 'no' ); ?> />
                                <strong>Keep all data</strong> (recommended)
                                <span style="display:block; color:#8a8478; font-size:13px; line-height:1.5; margin:4px 0 0 24px;">Forms, submissions, uploaded resumes and settings are kept. Reinstalling the plugin brings everything back.</span>
                            </label>
                            <label style="display:block;">
                                <input type="radio" name="cmm_delete_data_on_uninstall" value="yes" <?php checked( $current, 'yes' ); ?> />
                                <strong>Delete all data</strong>
                                <span style="display:block; color:#8a8478; font-size:13px; line-height:1.5; margin:4px 0 0 24px;">Permanently erases every form, every submission (including applications and their CSV data), every uploaded resume and attachment, all SMTP, branding and autoreply settings, and addon settings and connections (such as Google Drive and Sheets).</span>
                            </label>
                        </td>
                    </tr>
                </table>
                <p style="color:#8a8478; font-size:13px; margin:18px 0 0 0;">This setting only applies when the plugin is deleted. Clicking Delete on a single form or submission always removes just that item.</p>
                <input type="submit" name="cmm4_save_retention" class="cmm4-btn-black" value="Save Data Retention" />
            </form>
        </div>

        <div class="cmm4-card" style="background:#faf9f8;">
            <p style="margin:0; font-size:13px; color:#555;"><strong>Before deleting the plugin:</strong> download a backup from the Submissions page (CSV or ZIP). To keep resume files too, copy the <code>wp-content/uploads/cmm4-private-files/</code> folder.</p>
        </div>
    </div>
    <script>
    (function() {
        var form = document.getElementById('cmm4-retention-form');
        if (!form) { return; }
        form.addEventListener('submit', function(e) {
            var chosen = form.querySelector('input[name="cmm_delete_data_on_uninstall"]:checked');
            if (chosen && chosen.value === 'yes' && !confirm('Save this setting? If the plugin is deleted later, ALL forms, submissions and uploaded resumes will be permanently erased.')) {
                e.preventDefault();
            }
        });
    })();
    </script>
    <?php
}

// ==========================================
// 7. FRONTEND STYLES & SCRIPT
// ==========================================
function cmm4_frontend_assets() {
    echo '<style>
        .cmm4-alert { width: 100%; padding: 15px; margin-bottom: 20px; border: 1px solid; font-size: 13px; box-sizing: border-box; }
        .cmm4-alert-success { background-color: #f6f8f6; border-color: #c3d0c3; color: #2d4a2d; }
        .cmm4-alert-error { background-color: #fdf5f5; border-color: #e6c5c5; color: #8a3333; }
    </style>';

    global $cmm4_page_has_form;

    // Only load reCAPTCHA's JS on pages that actually contain the form.
    // Previously this loaded on every single page site-wide (~234 KiB unused
    // on non-form pages, confirmed via Lighthouse treemap audit).
    if ( $cmm4_page_has_form && get_option( 'cmm_recaptcha_enable' ) === 'yes' ) {
        $site_key = get_option( 'cmm_recaptcha_site_key' );
        if ( ! empty( $site_key ) ) {
            echo '<script src="https://www.google.com/recaptcha/api.js?render=' . esc_attr( $site_key ) . '" async defer></script>';
        }
    }
}
add_action( 'wp_head', 'cmm4_frontend_assets' );

// ==========================================
// 8. SHORTCODE & FORM RENDER ENGINE (ALK OVERRIDE)
// ==========================================
function cmm4_shortcode_render( $atts ) {
    global $cmm4_submission_feedback;
    
    // 1. EXTEND SHORTCODE ATTRIBUTES
    $atts = shortcode_atts( array( 
        'id'             => 0,
        'labels'         => 'yes',  // Options: yes | no
        'label_position' => 'top',  // Options: top | left
        'cols'           => '1'     // Options: 1  | 2  | 3
    ), $atts, 'cmm4_form' );

    $form_id = intval( $atts['id'] );
    if ( ! $form_id || get_post_type($form_id) !== 'cmm4_form' ) return '';

    $fields_raw = get_post_meta( $form_id, 'cmm4_fields', true );
    if ( empty( $fields_raw ) ) return '';

    // Hook point: addons can act before the form HTML is built (e.g. enqueue
    // addon-specific frontend assets only on pages that render a form).
    do_action( 'cmm4_before_form_render', $form_id, $atts );

    // Parse attribute configurations
    $show_labels = ( trim(strtolower($atts['labels'])) === 'no' ) ? false : true;
    $label_pos   = ( trim(strtolower($atts['label_position'])) === 'left' ) ? 'left' : 'top';
    $cols        = min( max( intval( $atts['cols'] ), 1 ), 3 ); // Force safe integer bounds 1-3

    // Generate a unique instance hash to prevent multi-form style bleed
    $instance_id = 'cmm4-canvas-' . $form_id . '-' . wp_rand(1000, 9999);

    // 2. COMPILE RESPONSIVE LAYOUT SANDBOX CSS
    $field_width = '100%';
    if ( $cols === 2 ) { $field_width = 'calc(50% - 10px)'; }
    if ( $cols === 3 ) { $field_width = 'calc(33.333% - 13.33px)'; }

    $output = '<style>
        #' . $instance_id . ' { box-sizing: border-box; }
        #' . $instance_id . ' * { box-sizing: border-box; }
        #' . $instance_id . ' .cmm4-field-wrap { width: 100%; max-width: 100%; }
        @media (min-width: 768px) {
            #' . $instance_id . ' .cmm4-field-wrap { width: ' . $field_width . '; }
            #' . $instance_id . ' .cmm4-field-wrap.cmm4-wide-row { width: 100% !important; }
        }
    </style>';

    $output .= '<div class="alk-contact-form alk-elementor-widget-text-editor">';
    
    // Output success/error alerts
    if ( isset($_POST['cmm4_form_id']) && intval($_POST['cmm4_form_id']) === $form_id ) {
        $output .= $cmm4_submission_feedback;
    }

    // Set up the primary form flex container
    $output .= '<form id="' . $instance_id . '" method="post" action="" enctype="multipart/form-data" style="width: 100%; display: flex; flex-wrap: wrap; gap: 20px; align-items: stretch;">';
    $output .= wp_nonce_field( 'cmm4_submit_' . $form_id, 'cmm4_nonce', true, false );
    $output .= '<input type="hidden" name="cmm4_form_id" value="' . $form_id . '" />';

    // Security Check Token Field
    $recaptcha_enable = get_option( 'cmm_recaptcha_enable', 'no' );
    $site_key = get_option( 'cmm_recaptcha_site_key', '' );
    if ( $recaptcha_enable === 'yes' && ! empty( $site_key ) ) {
        $output .= '<input type="hidden" name="cmm4_recaptcha_token" id="cmm4_token_' . $instance_id . '" value="" />';
        $output .= '<script>
		(function(){
			var key = "' . esc_js($site_key) . '";
			var fieldId = "cmm4_token_' . $instance_id . '";
			var tries = 0;
			function grab() {
				if (typeof grecaptcha === "undefined") {
					if (tries++ < 40) { setTimeout(grab, 250); } // Wait for API to load
					return;
				}
				grecaptcha.ready(function() {
					grecaptcha.execute(key, {action: "submit"}).then(function(token) {
						var f = document.getElementById(fieldId);
						if (f) { f.value = token; }
					});
				});
			}
			grab();
			// Safety net: If token is still empty at the exact moment of submit, fetch it synchronously
			document.addEventListener("submit", function(e) {
				var form = e.target;
				if (!form || !form.querySelector) { return; }
				var f = form.querySelector("#" + fieldId);
				if (!f || f.value || typeof grecaptcha === "undefined") { return; }
				e.preventDefault();
				grecaptcha.ready(function() {
					grecaptcha.execute(key, {action: "submit"}).then(function(token) {
						f.value = token;
						if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); }
					});
				});
			});
		})();
		</script>';
    }

    // CROSS-OS SAFE LINE SPLIT (\r\n or \n or \r)
    $lines = preg_split( '/\r\n|\r|\n/', $fields_raw );
    
    foreach ( $lines as $line ) {
        $parts = explode( '|', trim( $line ) );
        if ( count( $parts ) < 2 ) continue;

        $label_raw = trim( $parts[0] );
        // Labels may contain a simple inline link (e.g. "I agree to the
        // <a href='/privacy'>privacy policy</a>"), so this allows a small,
        // safe subset of HTML instead of stripping all tags outright.
        // $label_plain (fully stripped) is used anywhere HTML can't go —
        // the field's machine name, and the placeholder/aria fallback text.
        $label       = cmm4_kses_field_label( $label_raw );
        $label_plain = wp_strip_all_tags( $label_raw );
        $name        = sanitize_title( $label_plain );
        $type      = sanitize_text_field( trim( $parts[1] ) );
        $required  = ( isset($parts[2]) && trim($parts[2]) === 'required' ) ? 'required' : '';
        
        $explicit_place = ( isset($parts[3]) && ! empty( trim($parts[3]) ) ) ? trim($parts[3]) : '';
        $placeholder_attr = !empty($explicit_place) ? esc_attr($explicit_place) : esc_attr($label_plain . ($required ? ' *' : ''));
        
        $css_class = ( isset($parts[4]) ) ? sanitize_html_class( trim($parts[4]) ) : '';
        // If user manually added 'full-row', we keep it in the raw class string to check below, 
        // but it will also output in HTML which is harmless.
        $combined_classes = trim('alk-interactive-element ' . $css_class);
        
        // SAFE OPTIONS PARSING (Removes accidental trailing commas or empty spaces)
        $raw_opts = ( isset($parts[5]) ) ? explode(',', $parts[5]) : array();
        $select_opts = array_filter( array_map( 'trim', $raw_opts ), 'strlen' );

        // 3. DYNAMICALLY GENERATE INLINE STRUCTURE AND FLEX BOX RULES
        $wrapper_styles = 'display: flex; text-align: left;';
        $label_styles   = 'font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; color: #444; margin: 0;';
        $input_styles   = 'width: 100%;';
        $wide_row_class = '';

        // Textareas, Checkbox Lists, or manual 'full-row' class break columns to span full width
        	if ( $type === 'textarea' || ( $type === 'checkbox' && ! empty($select_opts) ) || in_array( $type, array( 'radio', 'acf_checkbox', 'acf_radio' ), true ) || strpos($css_class, 'full-row') !== false ) {
            $wide_row_class = ' cmm4-wide-row';
        }

        if ( $label_pos === 'top' || ! $show_labels ) {
            $wrapper_styles .= ' flex-direction: column; gap: 8px;';
        } else {
            // "Left" label alignments
            if ( $type === 'textarea' || ( $type === 'checkbox' && ! empty($select_opts) ) ) {
                $wrapper_styles .= ' flex-direction: row; align-items: flex-start; gap: 20px;';
                $label_styles   .= ' width: 140px; flex-shrink: 0; margin-top: 12px;';
            } else {
                $wrapper_styles .= ' flex-direction: row; align-items: center; gap: 20px;';
                $label_styles   .= ' width: 140px; flex-shrink: 0;';
            }
            $input_styles .= ' flex: 1;';
        }

        // 4. PRINT FIELD CONTAINER
        $output .= '<div class="cmm4-field-wrap' . $wide_row_class . '" style="' . $wrapper_styles . '">';

        // Render standard structural labels
        	if ( $show_labels && ! in_array( $type, array( 'checkbox', 'radio', 'acf_checkbox', 'acf_radio' ), true ) ) {
            $output .= '<label class="cmm4-label" style="' . $label_styles . '">' . $label . ( $required ? ' *' : '' ) . '</label>';
        }

        // 5. EVALUATE ELEMENTS
        if ( $type === 'textarea' ) {
            $output .= '<textarea name="cmm4_data[' . $name . ']" class="' . $combined_classes . '" placeholder="' . $placeholder_attr . '" rows="5" style="' . $input_styles . '" ' . $required . '></textarea>';
            
        } elseif ( $type === 'select' ) {
            $output .= '<select name="cmm4_data[' . $name . ']" class="' . $combined_classes . '" style="color: var(--text-gray); ' . $input_styles . '" ' . $required . '>';
            $output .= '<option value="">' . $placeholder_attr . '</option>';
            foreach($select_opts as $opt) {
                $opt_val = sanitize_title($opt);
                $opt_label = esc_html($opt);
                $output .= '<option value="' . $opt_val . '">' . $opt_label . '</option>';
            }
            $output .= '</select>';
            
        } elseif ( $type === 'checkbox' ) {
            if ( ! empty( $select_opts ) ) {
                // Multi-Option Group
                if ( $show_labels ) {
                    $output .= '<label class="cmm4-label" style="' . $label_styles . '">' . $label . ( $required ? ' *' : '' ) . '</label>';
                }
                $output .= '<div class="cmm4-checkbox-list" style="display: flex; flex-direction: column; gap: 10px; flex: 1;">';
                foreach ( $select_opts as $opt ) {
                    $opt_label = esc_html( $opt );
                    $output   .= '<label style="display: flex; align-items: center; gap: 10px; font-size: 14px; color: #333; cursor: pointer; font-weight: normal; margin: 0;">';
                    $output   .= '<input type="checkbox" name="cmm4_data[' . $name . '][]" value="' . esc_attr( $opt_label ) . '" class="' . $css_class . '" style="margin: 0; width: auto;" />';
                    $output   .= '<span>' . $opt_label . '</span>';
                    $output   .= '</label>';
                }
                $output .= '</div>';
            } else {
                // Single Standalone Checkbox
                if ( $label_pos === 'left' && $show_labels ) {
                    $output .= '<div style="' . $label_styles . '"></div>'; // Empty block for spacing alignment
                }
                $output .= '<label style="display: flex; align-items: center; gap: 10px; font-size: 14px; color: #333; cursor: pointer; font-weight: normal; margin: 0; flex: 1;">';
                $output .= '<input type="checkbox" name="cmm4_data[' . $name . ']" value="Yes" class="' . $css_class . '" style="margin: 0; width: auto;" ' . $required . ' />';
                $output .= '<span>' . $label . ( $required ? ' *' : '' ) . '</span>';
                $output .= '</label>';
            }
            
        } elseif ( $type === 'acf_locked' ) {
            // Read-only field auto-filled from an ACF field on the current
            // job post (e.g. "Department"). The ACF field name/key goes in
            // the Options column (parts[5]) of the field definition line —
            // e.g.: Post Applying For|acf_locked|required||full-row|department
            $acf_field_name = ( isset( $parts[5] ) ) ? trim( $parts[5] ) : '';
            $acf_value      = cmm4_get_acf_value_from_current_post( $acf_field_name );
           
		   //Changing Start
			$output .= '<input type="text" name="cmm4_data[' . $name . ']" class="' . $combined_classes . '" value="' . esc_attr( $acf_value ) . '" placeholder="' . $placeholder_attr . '" style="' . $input_styles . ' background:#f4f4f4; cursor:not-allowed;" readonly="readonly" ' . $required . ' />';
		} elseif ( $type === 'radio' || $type === 'acf_radio' ) {
			// Radio button group. Plain "radio" takes its options from the Options
			// column (comma list); "acf_radio" pulls them live from the ACF field
			// named in the Options column of the current post. The submitted value
			// is the option's visible label (same convention as checkbox groups).
			if ( $type === 'acf_radio' ) {
				$acf_field_name = ( isset( $parts[5] ) ) ? trim( $parts[5] ) : '';
				$radio_opts     = cmm4_get_acf_choices_from_current_post( $acf_field_name );
			} else {
				$radio_opts = $select_opts;
			}
			if ( $show_labels ) {
				$output .= '<label class="cmm4-label" style="' . $label_styles . '">' . $label . ( $required ? ' *' : '' ) . '</label>';
			}
			if ( empty( $radio_opts ) ) {
				$output .= '<span style="font-size:13px; color:#999;">Options are not available right now.</span>';
			} else {
				$output .= '<div class="cmm4-radio-list" style="display: flex; flex-direction: column; gap: 10px; flex: 1;">';
				foreach ( $radio_opts as $opt ) {
					$output .= '<label style="display: flex; align-items: center; gap: 10px; font-size: 14px; color: #333; cursor: pointer; font-weight: normal; margin: 0;">';
					$output .= '<input type="radio" name="cmm4_data[' . $name . ']" value="' . esc_attr( $opt ) . '" class="' . $css_class . '" style="margin: 0; width: auto;" ' . $required . ' />';
					$output .= '<span>' . esc_html( $opt ) . '</span>';
					$output .= '</label>';
				}
				$output .= '</div>';
			}
		} elseif ( $type === 'acf_dropdown' ) {
			// Dropdown populated live from an ACF field on the current post.
			// The ACF field name goes in the Options column, same convention as
			// acf_locked. The submitted value is the option's visible label.
			$acf_field_name = ( isset( $parts[5] ) ) ? trim( $parts[5] ) : '';
			$acf_opts       = cmm4_get_acf_choices_from_current_post( $acf_field_name );
			$output .= '<select name="cmm4_data[' . $name . ']" class="' . $combined_classes . '" style="color: var(--text-gray); ' . $input_styles . '" ' . $required . '>';
			$output .= '<option value="">' . $placeholder_attr . '</option>';
			foreach ( $acf_opts as $opt ) {
				$output .= '<option value="' . esc_attr( $opt ) . '">' . esc_html( $opt ) . '</option>';
			}
			$output .= '</select>';
		} elseif ( $type === 'acf_checkbox' ) {
			// Multi-option checkbox list populated live from an ACF field on the
			// current post (ACF field name in the Options column).
			$acf_field_name = ( isset( $parts[5] ) ) ? trim( $parts[5] ) : '';
			$acf_opts       = cmm4_get_acf_choices_from_current_post( $acf_field_name );
			if ( $show_labels ) {
				$output .= '<label class="cmm4-label" style="' . $label_styles . '">' . $label . ( $required ? ' *' : '' ) . '</label>';
			}
			if ( empty( $acf_opts ) ) {
				$output .= '<span style="font-size:13px; color:#999;">Options are not available right now.</span>';
			} else {
				$output .= '<div class="cmm4-checkbox-list" style="display: flex; flex-direction: column; gap: 10px; flex: 1;">';
				foreach ( $acf_opts as $opt ) {
					$output .= '<label style="display: flex; align-items: center; gap: 10px; font-size: 14px; color: #333; cursor: pointer; font-weight: normal; margin: 0;">';
					$output .= '<input type="checkbox" name="cmm4_data[' . $name . '][]" value="' . esc_attr( $opt ) . '" class="' . $css_class . '" style="margin: 0; width: auto;" />';
					$output .= '<span>' . esc_html( $opt ) . '</span>';
					$output .= '</label>';
				}
				$output .= '</div>';
			}
		} elseif ( $type === 'file' ) {
			//changing End>
			
            // Accept common document/image types by default; can be overridden
            // by putting a comma list of extensions in the Options column
            // (e.g. "pdf,doc,docx" for a resume field).
            $accept_exts = ! empty( $select_opts ) ? $select_opts : array( 'pdf', 'doc', 'docx' );
            $accept_attr = esc_attr( implode( ',', array_map( function( $ext ) { return '.' . ltrim( trim( $ext ), '.' ); }, $accept_exts ) ) );
            $output .= '<input type="file" name="cmm4_file_' . $name . '" class="' . $combined_classes . '" accept="' . $accept_attr . '" style="' . $input_styles . '" ' . $required . ' />';
            $output .= '<small style="display:block; margin-top:4px; font-size:11px; color:#999;">Accepted: ' . esc_html( implode( ', ', $accept_exts ) ) . ' &middot; Max 5MB</small>';

        } else {
            // General structural text configurations
            // Custom field types from addons (e.g. geo_province). An addon returns
            // HTML to claim the type; null means nobody claimed it, so use the default input.
            $custom_html = apply_filters( 'cmm4_render_custom_field', null, $type, $name, $combined_classes, $input_styles, $required );
            if ( $custom_html !== null ) {
                $output .= $custom_html;
            } else {
                $output .= '<input type="' . esc_attr($type) . '" name="cmm4_data[' . $name . ']" class="' . $combined_classes . '" placeholder="' . $placeholder_attr . '" style="' . $input_styles . '" ' . $required . ' />';
            }
        }

        $output .= '</div>'; // Close field container
    }

    $output .= '<button type="submit" name="cmm4_submit_form" class="alk-btn-primary alk-interactive-element" style="width: 100%; justify-content: center; display: flex; align-items: center; margin-top: 10px;">';
    $output .= '<span>SEND MESSAGE <i class="fa-solid fa-paper-plane" style="margin-left: 8px;"></i></span>';
    $output .= '</button>';
    
    $output .= '</form></div>';

    return $output;
}

// ----------------------------------------------------
// FIX #1 APPLIED HERE: Hook moved outside the comments
// ----------------------------------------------------
add_shortcode( 'cmm4_form', 'cmm4_shortcode_render' ); 

//USAGE: 
/*

First Name|text|required|||
Phone Number|tel||+92 300 0000000||

Project Details|textarea|required|Tell us about your project...||

Budget Range|select|required|||Under $1k, $1k - $5k, $5k+

I agree to the privacy policy|checkbox|required|||

Services Required|checkbox||||Apparel Sourcing, Brand Design, Logistics

Street Address|text|required||full-row|

Resume|file|required|||pdf, doc, docx
Department|acf_locked|required||full-row|department
Available Positions|acf_dropdown|required||full-row|positions
Skills You Have|acf_checkbox|||full-row|skills
Shift Preference|acf_radio|required|||shift_options
Gender|radio|||full-row|Male, Female, Other
District|select|required|||Ghotki, Mirpur Mathelo, Daharki, Ubaro
Province|select|required|||Sindh, Punjab, KPK, Balochistan

SHORT CODE
----------
[cmm4_form id="2659"]

[cmm4_form id="2659" cols="2"]
[cmm4_form id="2659" cols="3" labels="no"]
[cmm4_form id="2659" cols="1" label_position="left"]

*/

// ==========================================
// 8a. SHARED EMAIL LOGO BLOCK (OPTIONAL, USER-CONFIGURED)
// ==========================================
// Returns ready-to-insert HTML for the logo row, or '' if disabled/no URL set.
// Used by both the admin notification email and the autoreply email so the
// two stay visually consistent.
function cmm4_get_logo_block() {
    if ( get_option( 'cmm_logo_enable', 'no' ) !== 'yes' ) {
        return '';
    }
    $logo_url = get_option( 'cmm_logo_url', '' );
    if ( empty( $logo_url ) ) {
        return '';
    }

    $alignment = get_option( 'cmm_logo_alignment', 'center' );
    if ( ! in_array( $alignment, array( 'left', 'center', 'right' ), true ) ) {
        $alignment = 'center';
    }
    $width   = max( 20, min( 400, absint( get_option( 'cmm_logo_width', 160 ) ) ) );
    $padding = max( 0, min( 80, absint( get_option( 'cmm_logo_padding', 20 ) ) ) );

    return '<tr>
        <td align="' . esc_attr( $alignment ) . '" style="padding:' . $padding . 'px;">
            <img src="' . esc_url( $logo_url ) . '" alt="' . esc_attr( get_bloginfo( 'name' ) ) . '" width="' . $width . '" style="max-width:' . $width . 'px; width:100%; height:auto; display:inline-block; border:0;" />
        </td>
    </tr>';
}

function cmm4_get_logo_position() {
    $position = get_option( 'cmm_logo_position', 'top' );
    return in_array( $position, array( 'top', 'bottom' ), true ) ? $position : 'top';
}

// ==========================================
// 8a-i. FILE-FIELD DISPLAY HELPERS
// ==========================================
// A submission value represents an uploaded file if it's the marker array
// cmm4_process_file_upload() produces. Centralizing the check here means
// the email template, Submissions page, and CSV export all agree on format.
// Sanitizes a field label for HTML display, allowing a small safe subset of
// tags (plain links, and basic emphasis) so labels like "I agree to the
// <a href='/privacy'>privacy policy</a>" can render as real clickable links,
// without opening the door to arbitrary/script HTML in a form field that
// might be edited by more than one admin user.
// Reads an ACF field's value from the job post that the form is currently
// being rendered on (e.g. "Department" on a single Job page), for the
// 'acf_locked' field type. Returns '' if ACF isn't active, we're not on a
// single post, or the field is empty/not set — callers should treat an
// empty result as "just show a normal empty field" rather than an error.
function cmm4_get_acf_value_from_current_post( $acf_field_name ) {
    if ( empty( $acf_field_name ) || ! is_singular() ) {
        return '';
    }
    $post_id = get_the_ID();
    if ( ! $post_id ) {
        return '';
    }
	
	// Taxonomy name (incl. ACF-built taxonomies): return the assigned
	// terms' names, comma-joined — same convention as the choices helper.
	$tax_name = cmm4_resolve_taxonomy_name( $acf_field_name );
	if ( $tax_name !== '' ) {
		$terms = get_the_terms( $post_id, $tax_name );
		if ( is_array( $terms ) && ! empty( $terms ) ) {
			return implode( ', ', wp_list_pluck( $terms, 'name' ) );
		}
		return '';
	}
	
    if ( function_exists( 'get_field' ) ) {
        // Normal ACF path.
        $value = get_field( $acf_field_name, $post_id );
    } else {
        // Fallback if ACF's own function isn't available for some reason
        // (e.g. called too early) — read the raw post meta directly.
        $value = get_post_meta( $post_id, $acf_field_name, true );
    }

    // ACF Select/Relationship/Taxonomy fields can return arrays or objects;
    // reduce sensibly to a readable string instead of choking on that.
    if ( is_array( $value ) ) {
        $flat = array_map( function( $v ) {
            if ( is_object( $v ) && isset( $v->name ) )       return $v->name;   // e.g. WP_Term
            if ( is_object( $v ) && isset( $v->post_title ) ) return $v->post_title; // e.g. WP_Post
            if ( is_array( $v ) && isset( $v['label'] ) )     return $v['label'];
            if ( is_scalar( $v ) )                            return (string) $v;
            return '';
        }, $value );
        $value = implode( ', ', array_filter( $flat, 'strlen' ) );
    } elseif ( is_object( $value ) ) {
        $value = isset( $value->name ) ? $value->name : ( isset( $value->post_title ) ? $value->post_title : '' );
    }

    return is_string( $value ) ? trim( $value ) : '';
}
// Same as cmm4_get_acf_value_from_current_post(), but returns an ARRAY of
// distinct option strings instead of one flattened value — this is what the
// choice-list field types (acf_dropdown, acf_checkbox, acf_radio) need.
// Resolution order:
//   1. If the name is an ACF Select / Checkbox / Radio field, use its
//      configured "choices" list (the canonical set of options).
//   2. If the name is a registered taxonomy (including taxonomies built in
//      ACF's Taxonomies screen, like "Positions"), use the terms ASSIGNED TO
//      THE CURRENT POST — e.g. the positions this specific job vacancy is
//      hiring for. If the post has none assigned, fall back to every term
//      in the taxonomy so the field is never silently empty.
//   3. Otherwise fall back to the field's stored value: arrays / taxonomy
//      objects / related posts are flattened by the existing value helper
//      and then split on commas; a plain text value like "Ghotki, Daharki"
//      is split the same way.
//
// Resolves a name to a registered taxonomy, case-insensitively — so typing
// "Positions" in the Options column matches a taxonomy registered as
// "positions" (ACF's taxonomy builder lowercases keys). Returns the real
// taxonomy name, or '' if no taxonomy by that name exists.
function cmm4_resolve_taxonomy_name( $name ) {
	$name_lower = strtolower( trim( (string) $name ) );
	if ( $name_lower === '' ) {
		return '';
	}
	foreach ( get_taxonomies( array(), 'names' ) as $tax_name ) {
		if ( strtolower( $tax_name ) === $name_lower ) {
			return $tax_name;
		}
	}
	return '';
}

function cmm4_get_acf_choices_from_current_post( $acf_field_name ) {
	if ( empty( $acf_field_name ) || ! is_singular() ) {
		return array();
	}
	$post_id = get_the_ID();
	if ( ! $post_id ) {
		return array();
	}

	if ( function_exists( 'get_field_object' ) ) {
		$field_obj = get_field_object( $acf_field_name, $post_id );
		if ( is_array( $field_obj ) && ! empty( $field_obj['choices'] ) && is_array( $field_obj['choices'] ) ) {
			$choices = array();
			foreach ( $field_obj['choices'] as $choice_label ) {
				if ( is_scalar( $choice_label ) ) {
					$choice_label = trim( (string) $choice_label );
					if ( $choice_label !== '' ) {
						$choices[] = $choice_label;
					}
				}
			}
			if ( ! empty( $choices ) ) {
				return array_values( array_unique( $choices ) );
			}
		}
	}

	// Taxonomy branch (ACF-built or native): terms assigned to this post
	// first, all terms of the taxonomy as fallback.
	$tax_name = cmm4_resolve_taxonomy_name( $acf_field_name );
	if ( $tax_name !== '' ) {
		$terms = get_the_terms( $post_id, $tax_name );
		if ( ! is_array( $terms ) || empty( $terms ) ) {
			$terms = get_terms( array( 'taxonomy' => $tax_name, 'hide_empty' => false ) );
		}
		if ( is_array( $terms ) && ! is_wp_error( $terms ) ) {
			$names = array();
			foreach ( $terms as $term ) {
				if ( isset( $term->name ) && trim( $term->name ) !== '' ) {
					$names[] = trim( $term->name );
				}
			}
			if ( ! empty( $names ) ) {
				return array_values( array_unique( $names ) );
			}
		}
		return array();
	}

	$value = cmm4_get_acf_value_from_current_post( $acf_field_name );
	if ( $value === '' ) {
		return array();
	}
	$parts = array_filter( array_map( 'trim', explode( ',', $value ) ), 'strlen' );
	return array_values( array_unique( $parts ) );
}

function cmm4_kses_field_label( $label_raw ) {
    $allowed = array(
        'a'      => array( 'href' => true, 'target' => true, 'rel' => true ),
        'strong' => array(),
        'b'      => array(),
        'em'     => array(),
        'i'      => array(),
    );
    return wp_kses( $label_raw, $allowed );
}

// Sanitizes the whole pipe-delimited 'cmm4_fields' definition block on save
// (both the plain-textarea editor and the Visual Field Editor write here).
// A plain sanitize_textarea_field() strips ALL HTML, which was destroying
// the "<a href='/privacy'>privacy policy</a>" link inside labels on every
// save. Instead, this runs line-by-line: only the label (first '|' segment)
// is passed through the same safe-subset kses whitelist used at render
// time, so the link (and only that small set of tags) survives saving.
// Every other '|' segment (type, required, placeholder, css class, options)
// is left as plain text, same as before.
function cmm4_sanitize_fields_raw( $fields_raw ) {
    $lines = preg_split( '/\r\n|\r|\n/', (string) $fields_raw );
    $clean_lines = array();

    foreach ( $lines as $line ) {
        if ( trim( $line ) === '' ) {
            $clean_lines[] = '';
            continue;
        }
        $parts = explode( '|', $line );
        if ( isset( $parts[0] ) ) {
            $parts[0] = cmm4_kses_field_label( trim( $parts[0] ) );
        }
        $clean_lines[] = implode( '|', $parts );
    }

    return implode( "\n", $clean_lines );
}

function cmm4_is_file_value( $val ) {
    return is_array( $val ) && ! empty( $val['__cmm4_file'] ) && ! empty( $val['stored_name'] );
}

// Builds a long-lived, tamper-proof download URL for a stored file. Unlike a
// WordPress nonce (which expires in ~1-2 days), this uses an HMAC signature
// tied to the site's own secret keys — it keeps working indefinitely (e.g.
// in an old CSV export or an email from months ago), for as long as this
// WordPress install's AUTH_KEY/AUTH_SALT stay the same. The signature can't
// be forged or reused for a different filename without knowing those secrets.
function cmm4_sign_stored_filename( $stored_name ) {
    $secret = defined( 'AUTH_KEY' ) && AUTH_KEY ? AUTH_KEY : wp_salt( 'auth' );
    return substr( hash_hmac( 'sha256', $stored_name, $secret ), 0, 32 );
}

function cmm4_get_file_download_url( $stored_name, $original_name ) {
    $url = admin_url( 'admin.php' );
    return add_query_arg( array(
        'cmm4_download' => '1',
        'file'          => $stored_name,
        'name'          => rawurlencode( $original_name ),
        'sig'           => cmm4_sign_stored_filename( $stored_name ),
    ), $url );
}

// ==========================================
// 8b. SECURE FILE UPLOAD HANDLING
// ==========================================
// Processes one $_FILES entry for a "file" type field. Validates extension
// and size, then moves it into the private upload directory under a
// randomized filename (so the original filename — which may contain a
// person's real name — is never exposed in a guessable URL).
//
// Returns an array of file metadata on success, or a WP_Error on failure.
// This never trusts the browser-supplied MIME type alone; it checks the
// real file extension and re-validates size against PHP's own upload limits.
function cmm4_process_file_upload( $file_key, $allowed_exts = array( 'pdf', 'doc', 'docx' ), $max_bytes = 5242880 ) {
    if ( ! isset( $_FILES[ $file_key ] ) || empty( $_FILES[ $file_key ]['name'] ) ) {
        return null; // No file submitted for this field — not an error, just optional/empty.
    }

    $file = $_FILES[ $file_key ];

    // UPLOAD_ERR_INI_SIZE / UPLOAD_ERR_FORM_SIZE mean PHP itself rejected the
    // file for exceeding upload_max_filesize or post_max_size — this happens
    // before our own $max_bytes check ever runs, so give a clearer message
    // than the generic "could not be uploaded" for that specific case.
    if ( $file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE ) {
        return new WP_Error( 'cmm4_upload_too_large', 'The file is too large for this server\'s upload settings. Please try a smaller file.' );
    }

    if ( $file['error'] !== UPLOAD_ERR_OK ) {
        return new WP_Error( 'cmm4_upload_error', 'The file could not be uploaded. Please try again.' );
    }

    if ( $file['size'] > $max_bytes ) {
        return new WP_Error( 'cmm4_upload_too_large', 'The file is too large. Maximum size is ' . size_format( $max_bytes ) . '.' );
    }

    $original_name = sanitize_file_name( $file['name'] );
    $ext = strtolower( pathinfo( $original_name, PATHINFO_EXTENSION ) );

    $allowed_exts = array_map( function( $e ) { return strtolower( ltrim( trim( $e ), '.' ) ); }, $allowed_exts );
    if ( ! in_array( $ext, $allowed_exts, true ) ) {
        return new WP_Error( 'cmm4_upload_bad_type', 'That file type is not allowed. Accepted: ' . implode( ', ', $allowed_exts ) . '.' );
    }

    // Double-check against WordPress's own allowed-mimes list as a second
    // layer — blocks disguised/executable files even if the extension matches.
    $filetype = wp_check_filetype( $original_name );
    if ( empty( $filetype['type'] ) ) {
        return new WP_Error( 'cmm4_upload_bad_type', 'That file type is not allowed.' );
    }

    // wp_check_filetype() only reads the file NAME. Check the file's actual
    // content too, so a file renamed to "resume.pdf" is rejected.
    if ( ! cmm4_file_content_matches_ext( $file['tmp_name'], $ext ) ) {
        return new WP_Error( 'cmm4_upload_bad_type', 'That file does not look like a valid ' . strtoupper( $ext ) . ' file.' );
    }

    cmm4_ensure_private_upload_dir();
    $dir = cmm4_get_private_upload_dir();

    // Random token filename — original name is preserved separately in the
    // submission record for display, but never used as the actual disk/URL path.
    $token = wp_generate_password( 32, false, false );
    $stored_filename = $token . '.' . $ext;
    $destination = trailingslashit( $dir ) . $stored_filename;

    if ( ! move_uploaded_file( $file['tmp_name'], $destination ) ) {
        return new WP_Error( 'cmm4_upload_move_failed', 'The file could not be saved on the server.' );
    }

    return array(
        'original_name' => $original_name,
        'stored_name'   => $stored_filename,
        'size'          => $file['size'],
    );
}

// Checks the file's real content (its first bytes) against its extension.
// Returns true when they agree, or when this server can't check the type.
function cmm4_file_content_matches_ext( $tmp_path, $ext ) {
    $expected = array(
        'pdf'  => array( 'application/pdf' ),
        'doc'  => array( 'application/msword', 'application/x-ole-storage', 'application/vnd.ms-office' ),
        'docx' => array( 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip' ),
        'jpg'  => array( 'image/jpeg' ),
        'jpeg' => array( 'image/jpeg' ),
        'png'  => array( 'image/png' ),
    );
    if ( ! isset( $expected[ $ext ] ) || ! class_exists( 'finfo' ) ) {
        return true;
    }
    $finfo    = new finfo( FILEINFO_MIME_TYPE );
    $detected = $finfo->file( $tmp_path );
    return is_string( $detected ) && in_array( $detected, $expected[ $ext ], true );
}

// Secure download endpoint. Requires an admin session and a valid HMAC
// signature tied to the specific stored filename, so links can't be guessed
// or reused for a different file — and unlike a nonce, the signature doesn't
// expire, so links in old emails/CSV exports keep working indefinitely.
function cmm4_handle_file_download() {
    if ( ! isset( $_GET['cmm4_download'] ) || $_GET['cmm4_download'] !== '1' ) {
        return;
    }
    if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
        wp_die( 'You do not have permission to access this file.', 'Forbidden', array( 'response' => 403 ) );
    }

    $stored_name = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : '';
    $signature   = isset( $_GET['sig'] ) ? sanitize_text_field( wp_unslash( $_GET['sig'] ) ) : '';
    if ( empty( $stored_name ) || empty( $signature ) || ! hash_equals( cmm4_sign_stored_filename( $stored_name ), $signature ) ) {
        wp_die( 'This download link is invalid.', 'Forbidden', array( 'response' => 403 ) );
    }

    $real_dir = realpath( cmm4_get_private_upload_dir() );
    $path     = $real_dir ? realpath( trailingslashit( $real_dir ) . $stored_name ) : false;

    // Guard against path traversal: the resolved path must still live inside
    // the private upload directory (trailing slash stops "folder-evil" matching "folder").
    if ( ! $path || ! $real_dir || strpos( $path, trailingslashit( $real_dir ) ) !== 0 || ! file_exists( $path ) ) {
        wp_die( 'File not found.', 'Not Found', array( 'response' => 404 ) );
    }

    // sanitize_file_name() targets filesystem safety, not HTTP header safety —
    // it doesn't reliably strip every double-quote in every edge case (see
    // WordPress core Trac #16191). Since this value goes directly into a
    // Content-Disposition header, quotes and control characters are stripped
    // explicitly here to prevent header injection regardless of what
    // sanitize_file_name() does or doesn't catch.
    $display_name = isset( $_GET['name'] ) ? sanitize_file_name( wp_unslash( $_GET['name'] ) ) : basename( $path );
    $display_name = str_replace( array( '"', "\r", "\n" ), '', $display_name );

    nocache_headers();
    header( 'Content-Type: application/octet-stream' );
    header( 'Content-Disposition: attachment; filename="' . $display_name . '"' );
    header( 'Content-Length: ' . filesize( $path ) );
    readfile( $path );
    exit;
}
add_action( 'admin_init', 'cmm4_handle_file_download' );

// ==========================================
// 9. SUBMISSION ROUTER (HOOKED SECURELY TO WP LOAD)
// ==========================================
function cmm4_route_early_submission() {
    if ( ! isset( $_POST['cmm4_submit_form'] ) || ! isset( $_POST['cmm4_form_id'] ) ) return;
    
    global $cmm4_submission_feedback;
    $form_id = intval( $_POST['cmm4_form_id'] );
    // Only accept submissions for real CMM forms.
    if ( $form_id <= 0 || get_post_type( $form_id ) !== 'cmm4_form' ) {
        return;
    }
    
    if ( ! isset( $_POST['cmm4_nonce'] ) || ! wp_verify_nonce( $_POST['cmm4_nonce'], 'cmm4_submit_' . $form_id ) ) {
        $cmm4_submission_feedback = '<div class="cmm4-alert cmm4-alert-error">Security token expired. Please refresh the page.</div>';
        return;
    }

    if ( get_option( 'cmm_recaptcha_enable' ) === 'yes' ) {
        // FAIL CLOSED: when protection is on, a submission must include a token
        // AND Google must confirm it. The old code skipped the check whenever the
        // token or the secret was empty, so any bot could bypass it by leaving
        // the token out.
        $secret = (string) get_option( 'cmm_recaptcha_secret_key', '' );
        $token  = isset( $_POST['cmm4_recaptcha_token'] ) ? sanitize_text_field( wp_unslash( $_POST['cmm4_recaptcha_token'] ) ) : '';

        if ( $secret === '' ) {
            // Protection is switched on but no secret key is saved. Refuse rather
            // than silently accepting unprotected submissions.
            $cmm4_submission_feedback = '<div class="cmm4-alert cmm4-alert-error">This form is temporarily unavailable. Please try again later.</div>';
            return;
        }

        $verified = false;
        if ( $token !== '' ) {
            // Parameters go in the POST body rather than the URL, so they are
            // encoded correctly and the secret never appears in server access logs.
            $response = wp_remote_post( 'https://www.google.com/recaptcha/api/siteverify', array(
                'timeout' => 10,
                'body'    => array(
                    'secret'   => $secret,
                    'response' => $token,
                ),
            ) );
            $result   = is_wp_error( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ) );
            $verified = is_object( $result )
                && ! empty( $result->success )
                && isset( $result->score ) && (float) $result->score >= 0.5
                && ( ! isset( $result->action ) || $result->action === 'submit' );
        }

        if ( ! $verified ) {
            $cmm4_submission_feedback = '<div class="cmm4-alert cmm4-alert-error">Automated traffic detected. Transmission blocked.</div>';
            return;
        }
    }

    $target_email  = get_post_meta( $form_id, 'cmm4_target_email', true );
    $form_title    = get_the_title( $form_id );
    $user_email    = '';
    $clean_data    = array(); // key => cleaned value (string or array), stored to DB + used to build the email

    // wp_unslash(): WordPress adds backslashes to request data. Without this an
    // apostrophe in a name would be saved as O\'Brien.
    $posted_data = ( isset($_POST['cmm4_data']) && is_array($_POST['cmm4_data']) ) ? wp_unslash( $_POST['cmm4_data'] ) : array();
    if ( ! empty( $posted_data ) ) {
        foreach ( $posted_data as $key => $val ) {
            
            // ----------------------------------------------------
            // FIX #2 APPLIED HERE: Handles checkbox arrays safely
            // ----------------------------------------------------
            if ( is_array( $val ) ) {
                $clean_val = array_map( 'sanitize_text_field', $val );
            } else {
                $clean_val = sanitize_textarea_field( $val );
            }

            $clean_data[ $key ] = $clean_val;

            // Typecasting $key ensures string strictness for PHP 8.1+ in core checks
            $flat_val = is_array( $clean_val ) ? implode( ', ', $clean_val ) : $clean_val;
            if ( strpos( (string) $key, 'email' ) !== false && is_email( $flat_val ) ) {
                $user_email = $flat_val;
            }
        }
    }

    // ----------------------------------------------------
    // PROCESS FILE UPLOAD FIELDS
    // ----------------------------------------------------
    // Cross-reference the form's own field definitions so we know which
    // fields are declared as type "file", whether they're required, and
    // which extensions they accept — mirrors the same parsing the renderer
    // uses, so behavior always matches what's configured on the form.
    $fields_raw   = get_post_meta( $form_id, 'cmm4_fields', true );
    $field_lines  = preg_split( '/\r\n|\r|\n/', (string) $fields_raw );
    $upload_error = '';

    foreach ( $field_lines as $line ) {
        $parts = explode( '|', trim( $line ) );
        if ( count( $parts ) < 2 ) continue;

        $f_label = trim( $parts[0] );
        $f_name  = sanitize_title( $f_label );
        $f_type  = sanitize_text_field( trim( $parts[1] ) );
        if ( $f_type !== 'file' ) continue;

        $f_required = ( isset( $parts[2] ) && trim( $parts[2] ) === 'required' );
        $f_opts_raw = isset( $parts[5] ) ? explode( ',', $parts[5] ) : array();
        $f_exts     = array_filter( array_map( 'trim', $f_opts_raw ), 'strlen' );
        if ( empty( $f_exts ) ) {
            $f_exts = array( 'pdf', 'doc', 'docx' );
        }

        $file_key = 'cmm4_file_' . $f_name;
        $result   = cmm4_process_file_upload( $file_key, $f_exts );

        if ( is_wp_error( $result ) ) {
            $upload_error = $result->get_error_message();
            break;
        }

        if ( $result === null ) {
            if ( $f_required ) {
                $upload_error = 'Please attach a file for "' . esc_html( $f_label ) . '".';
                break;
            }
            continue; // optional field, nothing uploaded, that's fine
        }

        // Stored as a small marker array so the email/CSV/submissions views
        // can tell this apart from plain text and render a download link.
        $clean_data[ $f_name ] = array(
            '__cmm4_file'   => true,
            'original_name' => $result['original_name'],
            'stored_name'   => $result['stored_name'],
            'size'          => $result['size'],
        );
    }

    if ( ! empty( $upload_error ) ) {
        $cmm4_submission_feedback = '<div class="cmm4-alert cmm4-alert-error">' . esc_html( $upload_error ) . '</div>';
        return;
    }
		// ----------------------------------------------------
	// SERVER-SIDE REQUIRED-FIELD ENFORCEMENT
	// ----------------------------------------------------
	// The HTML5 "required" attribute is a browser-side convenience only —
	// it's trivially bypassed (disable JS, strip the attribute, POST
	// directly). A bypassed required dropdown would otherwise sail straight
	// past location restrictions with an empty value. So every field marked
	// "required" in the form definition is re-checked here on the server,
	// before anything is saved or emailed. File fields were already
	// enforced in the loop above and are skipped here.
	$required_error = '';
	foreach ( $field_lines as $line ) {
		$parts = explode( '|', trim( $line ) );
		if ( count( $parts ) < 2 ) continue;
		$f_label    = trim( $parts[0] );
		$f_type     = sanitize_text_field( trim( $parts[1] ) );
		$f_required = ( isset( $parts[2] ) && trim( $parts[2] ) === 'required' );
		if ( ! $f_required || $f_type === 'file' ) continue;
		$f_name     = sanitize_title( $f_label );
		$is_missing = true;
		if ( isset( $clean_data[ $f_name ] ) ) {
			$val = $clean_data[ $f_name ];
			if ( is_array( $val ) ) {
				// Checkbox group: "missing" = nothing ticked (or only blanks).
				$is_missing = empty( array_filter( array_map( 'trim', array_map( 'strval', $val ) ), 'strlen' ) );
			} else {
				$is_missing = ( trim( (string) $val ) === '' );
			}
		}
		if ( $is_missing ) {
			$required_error = 'Please fill in the "' . wp_strip_all_tags( $f_label ) . '" field.';
			break;
		}
	}
	if ( ! empty( $required_error ) ) {
		// Any file already moved into the private upload dir this request is
		// orphaned since we're not saving this submission — clean it up.
		foreach ( $clean_data as $val ) {
			if ( cmm4_is_file_value( $val ) ) {
				$orphan_path = trailingslashit( cmm4_get_private_upload_dir() ) . $val['stored_name'];
				if ( file_exists( $orphan_path ) ) {
					@unlink( $orphan_path );
				}
			}
		}
		$cmm4_submission_feedback = '<div class="cmm4-alert cmm4-alert-error">' . esc_html( $required_error ) . '</div>';
		return;
	}

    // ----------------------------------------------------
    // VALIDATION HOOK — lets an addon reject a submission before it is
    // saved or emailed (e.g. duplicate application, region restriction).
    // An addon returns a non-empty string (the message to show the visitor)
    // to block the submission, or '' / unchanged to let it through.
    // Runs after file uploads are processed so an addon can inspect
    // $clean_data (including any uploaded file's original_name) if needed,
    // but before anything is written to the DB or emailed.
    // ----------------------------------------------------
    $validation_error = apply_filters( 'cmm4_validate_submission', '', $form_id, $clean_data );
    if ( ! empty( $validation_error ) ) {
        // Any file already moved into the private upload dir this request is
        // orphaned since we're not saving this submission — clean it up.
        foreach ( $clean_data as $val ) {
            if ( cmm4_is_file_value( $val ) ) {
                $orphan_path = trailingslashit( cmm4_get_private_upload_dir() ) . $val['stored_name'];
                if ( file_exists( $orphan_path ) ) {
                    @unlink( $orphan_path );
                }
            }
        }
        $cmm4_submission_feedback = '<div class="cmm4-alert cmm4-alert-error">' . esc_html( $validation_error ) . '</div>';
        return;
    }

    // ----------------------------------------------------
    // SAVE SUBMISSION TO DATABASE (for CSV/Excel export)
    // ----------------------------------------------------
    global $wpdb;
    $insert_result = $wpdb->insert(
        $wpdb->prefix . 'cmm4_submissions',
        array(
            'form_id'         => $form_id,
            'form_title'      => $form_title,
            'submission_data' => wp_json_encode( $clean_data ),
            'submitted_at'    => current_time( 'mysql' ),
        ),
        array( '%d', '%s', '%s', '%s' )
    );
    // If the log write fails, don't fail silently — surface it in the WP debug log
    // so it's diagnosable, without exposing details to the site visitor.
    if ( $insert_result === false && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( 'CMM4: failed to log submission for form ID ' . $form_id . ' — ' . $wpdb->last_error );
    }

    // Hook point: addons can act after a submission is logged (e.g. an
    // analytics addon recording stats, or a CRM sync addon).
    do_action( 'cmm4_after_submission_saved', $form_id, $clean_data, $wpdb->insert_id );

    // ----------------------------------------------------
    // BUILD HTML EMAIL BODY — polished layout, optional logo, same data as before
    // ----------------------------------------------------
    $rows_html = '';
    $row_index = 0;
    foreach ( $clean_data as $key => $val ) {
        $field_lbl = esc_html( ucfirst( str_replace( '-', ' ', $key ) ) );
        $row_bg    = ( $row_index % 2 === 0 ) ? '#ffffff' : '#faf9f8';
        $row_index++;

        if ( cmm4_is_file_value( $val ) ) {
            $download_url = cmm4_get_file_download_url( $val['stored_name'], $val['original_name'] );
            $size_label   = size_format( $val['size'] );
            $field_val    = '<a href="' . esc_url( $download_url ) . '" style="color:#1a56db; text-decoration:none; font-weight:600;">📎 ' . esc_html( $val['original_name'] ) . '</a> <span style="color:#999; font-size:12px;">(' . esc_html( $size_label ) . ')</span>';
        } else {
            $flat_val  = is_array( $val ) ? implode( ', ', $val ) : $val;
            $field_val = nl2br( esc_html( $flat_val ) );
        }

        $rows_html .= '<tr style="background:' . $row_bg . ';">
            <td style="padding:14px 18px; border-bottom:1px solid #f0ede9; font-weight:600; font-size:12px; color:#8a8478; text-transform:uppercase; letter-spacing:0.6px; width:38%; vertical-align:top;">' . $field_lbl . '</td>
            <td style="padding:14px 18px; border-bottom:1px solid #f0ede9; font-size:14px; line-height:1.5; color:#2b2b2b; vertical-align:top;">' . $field_val . '</td>
        </tr>';
    }
    $rows_html = apply_filters( 'cmm4_notification_email_rows', $rows_html, $clean_data, $form_id );

    $logo_block    = cmm4_get_logo_block();
    $logo_position = cmm4_get_logo_position();
    $logo_top      = ( $logo_block && $logo_position === 'top' ) ? $logo_block : '';
    $logo_bottom   = ( $logo_block && $logo_position === 'bottom' ) ? $logo_block : '';
    $site_name     = esc_html( get_bloginfo( 'name' ) );
    $received_at   = esc_html( current_time( 'F j, Y' ) . ' at ' . current_time( 'g:i A' ) );

    $body_content = '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>New Form Submission</title></head>
    <body style="margin:0; padding:0; background-color:#eeece7; font-family:-apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#eeece7; padding:32px 16px;">
            <tr>
                <td align="center">
                    <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%; max-width:600px; background:#ffffff; border:1px solid #e8e6e1; border-radius:6px; overflow:hidden;">
                        ' . $logo_top . '
                        <tr>
                            <td style="height:4px; background:#222222; font-size:0; line-height:0;">&nbsp;</td>
                        </tr>
                        <tr>
                            <td style="padding:28px 32px 8px 32px;">
                                <span style="display:inline-block; background:#f0ede9; color:#555; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.8px; padding:5px 10px; border-radius:3px; margin-bottom:14px;">New Submission</span>
                                <h1 style="margin:14px 0 6px 0; font-size:20px; font-weight:600; color:#1a1a1a;">' . esc_html( $form_title ) . '</h1>
                                <p style="margin:0 0 20px 0; font-size:13px; color:#8a8478;">Received on ' . $received_at . ' &middot; ' . $site_name . '</p>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding:0 32px 32px 32px;">
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #f0ede9; border-radius:5px; overflow:hidden;">
                                    ' . $rows_html . '
                                </table>
                            </td>
                        </tr>
                        ' . $logo_bottom . '
                        <tr>
                            <td style="padding:16px 32px; background:#faf9f8; border-top:1px solid #e8e6e1;">
                                <span style="font-size:12px; color:#a39d90;">This message was sent automatically by the contact form on ' . $site_name . '.</span>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </body></html>';

    add_action( 'phpmailer_init', 'cmm4_configure_smtp' );

    // Force this specific email to send as HTML without changing wp_mail's
    // behavior anywhere else on the site (filter is added, used, then removed).
    add_filter( 'wp_mail_content_type', 'cmm4_set_html_content_type' );

    // Build a per-candidate CSV and attach it to the notification email.
    // Written to a temp file because PHPMailer's addAttachment() needs a
    // real file path, not in-memory data. wp_tempnam() guarantees a unique
    // path per call, so two people submitting the same form at the same
    // moment can never collide on the same filename.
    //
    // Attached directly here (rather than via wp_mail()'s $attachments
    // param) because only PHPMailer's addAttachment() lets us set a
    // friendly display name — e.g. "submission-job-application.csv" instead
    // of the random temp filename the recipient would otherwise see.
    $csv_temp_path = wp_tempnam( 'cmm4-submission-' . $form_id . '.csv' );
    $csv_ready      = $csv_temp_path && cmm4_write_single_entry_csv( $csv_temp_path, $clean_data, current_time( 'mysql' ) );
    $csv_display_name = 'submission-' . sanitize_title( $form_title ) . '.csv';

    // Also attach the actual uploaded file(s) themselves (e.g. a resume),
    // not just the clickable download link already in the email body — so
    // the file lands directly in the recipient's inbox even if they don't
    // have (or don't want to use) an admin login to fetch it.
    $upload_dir = cmm4_get_private_upload_dir();
    $file_attachments = array();
    foreach ( $clean_data as $val ) {
        if ( ! cmm4_is_file_value( $val ) ) continue;
        $full_path = trailingslashit( $upload_dir ) . $val['stored_name'];
        if ( file_exists( $full_path ) ) {
            $file_attachments[] = array( 'path' => $full_path, 'display_name' => $val['original_name'] );
        }
    }

    $cmm4_attach_csv_cb = function( $phpmailer ) use ( $csv_temp_path, $csv_display_name, $csv_ready, $file_attachments ) {
        if ( $csv_ready ) {
            $phpmailer->addAttachment( $csv_temp_path, $csv_display_name );
        }
        foreach ( $file_attachments as $att ) {
            $phpmailer->addAttachment( $att['path'], $att['display_name'] );
        }
    };
    add_action( 'phpmailer_init', $cmm4_attach_csv_cb, 20 ); // after cmm4_configure_smtp (default priority 10)

    $sent = wp_mail( $target_email, "New Transmission: {$form_title}", $body_content );

    remove_action( 'phpmailer_init', $cmm4_attach_csv_cb, 20 );
    if ( $csv_temp_path && file_exists( $csv_temp_path ) ) {
        @unlink( $csv_temp_path );
    }

    if ( $sent && get_option('cmm_auto_enable') === 'yes' && ! empty( $user_email ) ) {
        $auto_subj      = get_option( 'cmm_auto_subject' );
        $auto_body_raw  = get_option( 'cmm_auto_body' ); // Already HTML from wp_editor().
        $auto_logo      = cmm4_get_logo_block();
        $auto_logo_pos  = cmm4_get_logo_position();

        // Wrap the autoreply content in the same card shell as the notification
        // email so branding (including the optional logo) stays consistent.
        $auto_body = '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
        <body style="margin:0; padding:0; background-color:#eeece7; font-family:-apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#eeece7; padding:32px 16px;">
                <tr>
                    <td align="center">
                        <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%; max-width:600px; background:#ffffff; border:1px solid #e8e6e1; border-radius:6px; overflow:hidden;">
                            ' . ( $auto_logo_pos === 'top' ? $auto_logo : '' ) . '
                            <tr><td style="height:4px; background:#222222; font-size:0; line-height:0;">&nbsp;</td></tr>
                            <tr>
                                <td style="padding:32px; font-size:14px; line-height:1.6; color:#2b2b2b;">
                                    ' . $auto_body_raw . '
                                </td>
                            </tr>
                            ' . ( $auto_logo_pos === 'bottom' ? $auto_logo : '' ) . '
                            <tr>
                                <td style="padding:16px 32px; background:#faf9f8; border-top:1px solid #e8e6e1;">
                                    <span style="font-size:12px; color:#a39d90;">' . esc_html( get_bloginfo( 'name' ) ) . '</span>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </body></html>';

        wp_mail( $user_email, $auto_subj, $auto_body );
    }
    remove_filter( 'wp_mail_content_type', 'cmm4_set_html_content_type' );

    remove_action( 'phpmailer_init', 'cmm4_configure_smtp' );

    if ( $sent ) {
        $cmm4_submission_feedback = '<div class="cmm4-alert cmm4-alert-success">Transmission successfully delivered.</div>';
    } else {
        $cmm4_submission_feedback = '<div class="cmm4-alert cmm4-alert-error">Server relay failed. Please try again later.</div>';
    }
}
add_action( 'wp_loaded', 'cmm4_route_early_submission' );

// Sets the outgoing email format to HTML. Only ever active for the single
// wp_mail() call it wraps (added + removed right around that call), so it
// cannot affect any other email sent by WordPress or other plugins.
function cmm4_set_html_content_type() {
    return 'text/html';
}

function cmm4_configure_smtp( $phpmailer ) {
    $host = get_option( 'cmm_smtp_host' );
    if ( empty( $host ) ) return;

    $phpmailer->isSMTP();
    $phpmailer->Host       = $host;
    $phpmailer->SMTPAuth   = ( get_option( 'cmm_smtp_auth' ) === 'yes' );
    $phpmailer->Port       = get_option( 'cmm_smtp_port' );
    $phpmailer->Username   = get_option( 'cmm_smtp_user' );
    $phpmailer->Password   = get_option( 'cmm_smtp_pass' );
    $phpmailer->SMTPSecure = get_option( 'cmm_smtp_secure' ) === 'none' ? '' : get_option( 'cmm_smtp_secure' );
    $phpmailer->From       = get_option( 'cmm_from_email' );
    $phpmailer->FromName   = get_option( 'cmm_from_name' );
}