<?php
/**
 * Uninstall handler for Custom Minimalist Mailer (CMM4).
 *
 * Runs only when the plugin is DELETED from the Plugins screen in wp-admin.
 * It never runs on deactivation.
 *
 * What happens depends on the site owner's choice under
 * CMM Forms > Data Retention:
 *
 *   'no'  (default): nothing is deleted. Forms, submissions, settings, uploaded
 *                    resumes and addon settings all stay in place.
 *   'yes'          : all plugin data is erased, including uploaded files.
 *
 * Clicking Delete on a single form or submission always removes just that
 * item, regardless of this setting.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

/**
 * Erases every piece of CMM4 data. Only called when the owner chose 'yes'.
 */
function cmm4_uninstall_erase_all_data() {
    global $wpdb;

    // 1. Submissions log table (every form entry, including applications).
    $table_name = $wpdb->prefix . 'cmm4_submissions';
    $wpdb->query( "DROP TABLE IF EXISTS {$table_name}" ); // phpcs:ignore -- table name is not user input

    // 2. Every form (post + meta), including trashed ones.
    $form_ids = get_posts( array(
        'post_type'   => 'cmm4_form',
        'numberposts' => -1,
        'fields'      => 'ids',
        'post_status' => 'any',
    ) );
    foreach ( $form_ids as $form_id ) {
        wp_delete_post( $form_id, true );
    }

    // 3. Uploaded resumes and attachments in the private folder.
    cmm4_uninstall_delete_private_files();

    // 4. Core plugin options.
    $options = array(
        'cmm_smtp_host', 'cmm_smtp_port', 'cmm_smtp_auth', 'cmm_smtp_user', 'cmm_smtp_pass',
        'cmm_smtp_secure', 'cmm_from_email', 'cmm_from_name',
        'cmm_auto_enable', 'cmm_auto_subject', 'cmm_auto_body',
        'cmm_recaptcha_enable', 'cmm_recaptcha_site_key', 'cmm_recaptcha_secret_key',
        'cmm_logo_enable', 'cmm_logo_url', 'cmm_logo_position', 'cmm_logo_alignment',
        'cmm_logo_width', 'cmm_logo_padding',
    );
    foreach ( $options as $option ) {
        delete_option( $option );
    }

    // 5. Addons: each one ships its own uninstall.php and cleans up only what
    //    it created. Their options (including Google connection tokens) go too.
    $addons_dir = plugin_dir_path( __FILE__ ) . 'addons';
    if ( is_dir( $addons_dir ) ) {
        foreach ( glob( $addons_dir . '/*', GLOB_ONLYDIR ) as $addon_folder ) {
            $addon_uninstall = $addon_folder . '/uninstall.php';
            if ( file_exists( $addon_uninstall ) ) {
                include $addon_uninstall;
            }
        }
    }

    // 6. Ecosystem options, then the retention setting itself.
    delete_option( 'cmm4_disabled_addons' );
    delete_option( 'cmm4_addon_errors' );
    delete_option( 'cmm_delete_data_on_uninstall' );
}

/**
 * Deletes the files in the private resume folder. Works without the plugin
 * loaded, so it can't call the plugin's own helpers.
 */
function cmm4_uninstall_delete_private_files() {
    $upload = wp_upload_dir();
    if ( ! empty( $upload['error'] ) || empty( $upload['basedir'] ) ) {
        return;
    }

    $dir = trailingslashit( $upload['basedir'] ) . 'cmm4-private-files';
    if ( ! is_dir( $dir ) ) {
        return;
    }
    $real_dir = realpath( $dir );
    if ( ! $real_dir ) {
        return;
    }

    $items = scandir( $real_dir );
    if ( ! is_array( $items ) ) {
        return;
    }
    foreach ( $items as $item ) {
        if ( $item === '.' || $item === '..' ) {
            continue;
        }
        $path = $real_dir . DIRECTORY_SEPARATOR . $item;
        if ( is_file( $path ) ) {
            @unlink( $path ); // phpcs:ignore -- best effort during uninstall
        }
    }
    @rmdir( $real_dir ); // Only succeeds if the folder is now empty.
}

// Only erase data when the owner explicitly chose it. The default is 'no', so a
// site that never opened the Data Retention page keeps everything.
if ( get_option( 'cmm_delete_data_on_uninstall', 'no' ) === 'yes' ) {
    cmm4_uninstall_erase_all_data();
}
