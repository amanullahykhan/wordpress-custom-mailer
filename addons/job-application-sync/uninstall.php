<?php
/**
 * Addon-level uninstall for Job Application Sync.
 *
 * Included by the core plugin's uninstall.php when the whole plugin is
 * deleted from the Plugins screen — NOT run on simple deactivation, and not
 * loaded as part of the normal request cycle. Cleans up only what this
 * addon itself created; the core plugin's own options are handled by its
 * own uninstall.php.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

$cmm4_jas_options = array(
    'cmm4_jas_client_id',
    'cmm4_jas_client_secret',
    'cmm4_jas_refresh_token',
    'cmm4_jas_connected_email',
    'cmm4_jas_spreadsheet_id',
    'cmm4_jas_sheet_name',
    'cmm4_jas_drive_folder_id',
    'cmm4_jas_form_ids',
    'cmm4_jas_enabled',
);
foreach ( $cmm4_jas_options as $cmm4_jas_option ) {
    delete_option( $cmm4_jas_option );
}

delete_transient( 'cmm4_jas_access_token' );
