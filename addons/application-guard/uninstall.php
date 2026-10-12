<?php
/**
 * Addon-level uninstall for Application Guard.
 *
 * Included by the core plugin's uninstall.php when the whole plugin is
 * deleted from the Plugins screen — NOT run on simple deactivation, and not
 * loaded as part of the normal request cycle. Cleans up only what this
 * addon itself created.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$cmm4_ag_options = array(
	'cmm4_ag_form_ids',
	'cmm4_ag_dupe_enabled',
	'cmm4_ag_dupe_message',
	'cmm4_ag_location_rules',
	// Legacy v1 options, in case an install is deleted before/after migration.
	'cmm4_ag_district_enabled',
	'cmm4_ag_district_field_names',
	'cmm4_ag_district_allowed',
	'cmm4_ag_district_message',
);

foreach ( $cmm4_ag_options as $cmm4_ag_option ) {
	delete_option( $cmm4_ag_option );
}