<?php
/**
 * Addon Name: Application Guard
 * Description: Eligibility rules for job application forms — blocks repeat applications (same email OR phone number), and restricts applicants by location (district / city / province, each with its own allowed list).
 * Version: 2.0.1
 * Author: Amanullah Khan
 * Requires CMM4 at least: 6.10.0
 *
 * CMM4 Addon: Application Guard
 *
 * Purpose-built for job application forms, the same way Job Application
 * Sync is — scoped to specific form ID(s), not applied to every cmm4 form
 * on the site. It hooks into the core's 'cmm4_validate_submission' filter,
 * which runs after a submission passes normal required-field checks but
 * before anything is saved to the database or emailed, so a rejected
 * submission never gets logged, never gets synced anywhere, and any file
 * the applicant uploaded that request is deleted (the core plugin handles
 * that cleanup automatically whenever a validator rejects a submission).
 *
 * RULE 1 — No duplicate applications
 *   If a previous submission to the SAME form (job posting) already has
 *   either the same email address OR the same phone/contact number, the
 *   new application is blocked. Phone numbers are normalized before
 *   comparison (spaces/dashes/parentheses stripped; "+92", "0092" and the
 *   local leading "0" treated as the same number), so "0300 1234567",
 *   "+92 300 1234567" and "923001234567" all count as one person. Email
 *   matching is case-insensitive. EITHER one matching is enough to block —
 *   an applicant can't dodge the check by changing just one of the two.
 *
 * RULE 2 — Location restrictions (up to 3 independent rules)
 *   Three rule slots, pre-labeled District, City and Province. Each slot
 *   has its own enable switch, its own form-field label(s) to check, its
 *   own allowed-values list, and its own rejection message — so you can
 *   e.g. allow only Ghotki district AND only Sindh province at the same
 *   time. Matching is case-insensitive, ignores extra spaces, and accepts
 *   both the plain value ("Mirpur Mathelo") and the slug form the core
 *   renderer stores for classic dropdown fields ("mirpur-mathelo").
 *
 * SETUP (one-time):
 *   1. Go to CMM Forms → Application Guard.
 *   2. Enter the Job Application Form ID(s) this should apply to (comma-
 *      separated if more than one), same as Job Application Sync.
 *   3. Turn on whichever rule(s) you want.
 *   4. For each location rule, make sure the form actually has a matching
 *      field (label it exactly "District", "City" or "Province" — or put
 *      your own labels in the rule's "Field label(s)" box). A Dropdown
 *      field type is recommended so applicants can only pick from your
 *      list in the first place; the rule then re-checks server-side, so
 *      tampering with the submitted data directly doesn't bypass it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// How many independent location-rule slots the settings page offers.
// District + City + Province covers the intended use; storage already
// handles any count if this is ever bumped (add matching defaults in
// cmm4_ag_default_location_rules() too).
define( 'CMM4_AG_LOCATION_RULE_SLOTS', 3 );

// ==========================================
// SETTINGS PAGE — registered under the core CMM Forms menu
// ==========================================

add_action( 'cmm4_admin_menu_after', 'cmm4_ag_register_menu' );

function cmm4_ag_register_menu() {
	add_submenu_page( 'cmm4-dashboard', 'Application Guard', 'Application Guard', 'manage_options', 'cmm4-application-guard', 'cmm4_ag_settings_page' );
}

function cmm4_ag_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'You do not have permission to access this page.' );
	}

	$notice = '';

	if ( isset( $_POST['cmm4_ag_save'] ) && check_admin_referer( 'cmm4_ag_action', 'cmm4_ag_nonce' ) ) {
		update_option( 'cmm4_ag_form_ids', isset( $_POST['cmm4_ag_form_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['cmm4_ag_form_ids'] ) ) : '' );
		update_option( 'cmm4_ag_dupe_enabled', isset( $_POST['cmm4_ag_dupe_enabled'] ) ? 'yes' : 'no' );
		update_option( 'cmm4_ag_dupe_message', isset( $_POST['cmm4_ag_dupe_message'] ) ? sanitize_text_field( wp_unslash( $_POST['cmm4_ag_dupe_message'] ) ) : '' );

		$rules = array();
		for ( $i = 1; $i <= CMM4_AG_LOCATION_RULE_SLOTS; $i++ ) {
			$rules[] = array(
				'enabled'     => isset( $_POST['cmm4_ag_loc_enabled'][ $i ] ) ? 'yes' : 'no',
				'field_names' => isset( $_POST['cmm4_ag_loc_fields'][ $i ] ) ? sanitize_text_field( wp_unslash( $_POST['cmm4_ag_loc_fields'][ $i ] ) ) : '',
				'allowed'     => isset( $_POST['cmm4_ag_loc_allowed'][ $i ] ) ? sanitize_textarea_field( wp_unslash( $_POST['cmm4_ag_loc_allowed'][ $i ] ) ) : '',
				'message'     => isset( $_POST['cmm4_ag_loc_message'][ $i ] ) ? sanitize_text_field( wp_unslash( $_POST['cmm4_ag_loc_message'][ $i ] ) ) : '',
			);
		}
		update_option( 'cmm4_ag_location_rules', $rules );

		$notice = '<div class="updated"><p>Application Guard settings saved.</p></div>';

		// Same safety net as v1: rules with no Form ID silently mean "every
		// cmm4 form on the site". Make that explicit instead of a surprise.
		$any_enabled = ( get_option( 'cmm4_ag_dupe_enabled' ) === 'yes' );
		if ( ! $any_enabled ) {
			foreach ( $rules as $rule ) {
				if ( $rule['enabled'] === 'yes' ) {
					$any_enabled = true;
					break;
				}
			}
		}
		if ( $any_enabled && trim( (string) get_option( 'cmm4_ag_form_ids', '' ) ) === '' ) {
			$notice .= '<div class="error" style="border-left-color:#dba617;"><p>Heads up: no Form ID is set, so these rules will apply to <strong>every</strong> cmm4 form on the site — not just job applications. Fill in the Form ID field above if that\'s not what you want.</p></div>';
		}
	}

	$form_ids     = get_option( 'cmm4_ag_form_ids', '' );
	$dupe_enabled = get_option( 'cmm4_ag_dupe_enabled', 'no' );
	$dupe_message = get_option( 'cmm4_ag_dupe_message', '' );
	$rules        = cmm4_ag_get_location_rules();
	$slot_titles  = array( 'District rule', 'City rule', 'Province rule' );
	?>
	<div class="cmm4-admin-wrap">
		<h1 class="cmm4-title">Application Guard</h1>
		<p class="cmm4-subtitle">Eligibility rules for job application forms: block repeat applications (same email <em>or</em> phone number), and restrict applicants by district / city / province. Scoped to the form ID(s) below — other cmm4 forms are never touched.</p>
		<?php echo $notice; ?>

		<div class="cmm4-card">
			<form method="post" action="">
				<?php wp_nonce_field( 'cmm4_ag_action', 'cmm4_ag_nonce' ); ?>

				<table class="cmm4-table">
					<tr>
						<th style="width:25%;">Job Application Form ID(s)</th>
						<td>
							<input type="text" name="cmm4_ag_form_ids" class="cmm4-input-text" value="<?php echo esc_attr( $form_ids ); ?>" placeholder="e.g. 2659 or 2659, 2701" />
							<p style="margin:6px 0 0 0; font-size:12px; color:#999;">Comma-separated if you have more than one job application form. All rules below only apply to these form(s).</p>
						</td>
					</tr>
				</table>

				<h2 style="font-size:15px; margin:28px 0 10px 0; border-top:1px solid #eee; padding-top:20px;">Rule 1 — Block duplicate applications (same email OR phone)</h2>
				<table class="cmm4-table">
					<tr>
						<th style="width:25%;">Enable</th>
						<td><label><input type="checkbox" name="cmm4_ag_dupe_enabled" value="yes" <?php checked( $dupe_enabled, 'yes' ); ?> /> Don't allow the same person to apply to the same job posting more than once</label></td>
					</tr>
					<tr>
						<th>Message shown to applicant</th>
						<td>
							<input type="text" name="cmm4_ag_dupe_message" class="cmm4-input-text" value="<?php echo esc_attr( $dupe_message ); ?>" placeholder="You've already applied for this position with this email address or phone number." />
							<p style="margin:6px 0 0 0; font-size:12px; color:#999;">Matches on email <strong>or</strong> phone — either one matching an earlier application blocks the new one. Phone comparison ignores spaces/dashes and treats 0300…, +92 300… and 92 300… as the same number. Leave blank to use the default message shown above.</p>
						</td>
					</tr>
				</table>

				<h2 style="font-size:15px; margin:28px 0 10px 0; border-top:1px solid #eee; padding-top:20px;">Rule 2 — Location restrictions (one slot each for District / City / Province)</h2>

				<?php for ( $i = 0; $i < CMM4_AG_LOCATION_RULE_SLOTS; $i++ ) :
					$idx  = $i + 1;
					$rule = $rules[ $i ];
					$slot_name = isset( $slot_titles[ $i ] ) ? $slot_titles[ $i ] : 'Location rule ' . $idx;
				?>
				<div style="border:1px solid #f0ede9; border-radius:4px; padding:18px; margin-top:14px; background:#fdfcfc;">
					<strong style="font-size:12px; text-transform:uppercase; letter-spacing:0.6px; color:#8a8478;"><?php echo esc_html( $slot_name ); ?></strong>
					<table class="cmm4-table" style="margin-top:6px;">
						<tr>
							<th style="width:25%;">Enable</th>
							<td><label><input type="checkbox" name="cmm4_ag_loc_enabled[<?php echo $idx; ?>]" value="yes" <?php checked( $rule['enabled'], 'yes' ); ?> /> Only accept applications whose <?php echo esc_html( strtolower( str_replace( ' rule', '', $slot_name ) ) ); ?> is on the allowed list below</label></td>
						</tr>
						<tr>
							<th>Field label(s) to check</th>
							<td>
								<input type="text" name="cmm4_ag_loc_fields[<?php echo $idx; ?>]" class="cmm4-input-text" value="<?php echo esc_attr( $rule['field_names'] ); ?>" placeholder="District" />
								<p style="margin:6px 0 0 0; font-size:12px; color:#999;">The form field whose value gets checked — must match the field's Label exactly as set in the form editor. Comma-separate if the field could be named differently across your forms (e.g. "District, Area").</p>
							</td>
						</tr>
						<tr>
							<th>Allowed values</th>
							<td>
								<textarea name="cmm4_ag_loc_allowed[<?php echo $idx; ?>]" rows="5" class="cmm4-input-text" style="width:100%;"><?php echo esc_textarea( $rule['allowed'] ); ?></textarea>
								<p style="margin:6px 0 0 0; font-size:12px; color:#999;">One per line. Matching ignores case and extra spaces ("ghotki" = "Ghotki"). Anything not on this list is rejected. Leaving this empty disables the rule even if it's ticked.</p>
							</td>
						</tr>
						<tr>
							<th>Message shown to applicant</th>
							<td>
								<input type="text" name="cmm4_ag_loc_message[<?php echo $idx; ?>]" class="cmm4-input-text" value="<?php echo esc_attr( $rule['message'] ); ?>" placeholder="<?php echo esc_attr( $rule['default_message'] ); ?>" />
								<p style="margin:6px 0 0 0; font-size:12px; color:#999;">Leave blank to use the default message shown above.</p>
							</td>
						</tr>
					</table>
				</div>
				<?php endfor; ?>

				<p style="margin-top:24px;">
					<button type="submit" name="cmm4_ag_save" class="cmm4-btn-black">Save Settings</button>
				</p>
			</form>
		</div>

		<div class="cmm4-card" style="margin-top:20px; background:#faf9f8;">
			<p style="margin:0; font-size:13px; color:#555;"><strong>Recommended field setup:</strong> build District, City and Province as <code>Dropdown</code> fields in the form editor, listing only the allowed values as options — applicants can then only pick from your list in the first place. These rules re-check the submitted value server-side, so tampering with the form data directly still can't bypass them.</p>
		</div>
	</div>
	<?php
}

// ==========================================
// ONE-TIME MIGRATION FROM v1 (single district rule -> slot 1)
// ==========================================
// v1 stored cmm4_ag_district_* options. If they exist and the new
// location-rules option doesn't yet, carry the old district config into
// rule slot 1 and drop the old options — so upgrading never silently loses
// an existing restriction.

add_action( 'admin_init', 'cmm4_ag_maybe_migrate_v1_settings' );

function cmm4_ag_maybe_migrate_v1_settings() {
	if ( get_option( 'cmm4_ag_location_rules' ) !== false ) {
		return; // Already migrated, or settings have been saved on v2.
	}
	if ( get_option( 'cmm4_ag_district_enabled', false ) === false ) {
		return; // Fresh install — v1 district options were never saved.
	}

	$rules    = cmm4_ag_default_location_rules();
	$rules[0] = wp_parse_args( array(
		'enabled'     => get_option( 'cmm4_ag_district_enabled', 'no' ),
		'field_names' => get_option( 'cmm4_ag_district_field_names', 'District, City' ),
		'allowed'     => get_option( 'cmm4_ag_district_allowed', '' ),
		'message'     => get_option( 'cmm4_ag_district_message', '' ),
	), $rules[0] );

	update_option( 'cmm4_ag_location_rules', $rules );
	delete_option( 'cmm4_ag_district_enabled' );
	delete_option( 'cmm4_ag_district_field_names' );
	delete_option( 'cmm4_ag_district_allowed' );
	delete_option( 'cmm4_ag_district_message' );
}

// ==========================================
// LOCATION RULES — storage + defaults
// ==========================================

function cmm4_ag_default_location_rules() {
	return array(
		array(
			'enabled'         => 'no',
			'field_names'     => 'District',
			'allowed'         => "Ghotki\nMirpur Mathelo\nDaharki\nUbaro",
			'message'         => '',
			'default_message' => 'This position is only open to applicants based in Ghotki district.',
		),
		array(
			'enabled'         => 'no',
			'field_names'     => 'City',
			'allowed'         => '',
			'message'         => '',
			'default_message' => 'This position is not open to applicants from your city.',
		),
		array(
			'enabled'         => 'no',
			'field_names'     => 'Province',
			'allowed'         => '',
			'message'         => '',
			'default_message' => 'This position is not open to applicants from your province.',
		),
	);
}

function cmm4_ag_get_location_rules() {
	$saved    = get_option( 'cmm4_ag_location_rules', array() );
	$defaults = cmm4_ag_default_location_rules();
	$rules    = array();
	for ( $i = 0; $i < CMM4_AG_LOCATION_RULE_SLOTS; $i++ ) {
		$default = isset( $defaults[ $i ] ) ? $defaults[ $i ] : array(
			'enabled'         => 'no',
			'field_names'     => '',
			'allowed'         => '',
			'message'         => '',
			'default_message' => 'This position is only open to applicants from the allowed locations.',
		);
		$rule    = ( isset( $saved[ $i ] ) && is_array( $saved[ $i ] ) ) ? $saved[ $i ] : array();
		$rules[] = wp_parse_args( $rule, $default );
	}
	return $rules;
}

// ==========================================
// VALIDATION — hooks into the core's cmm4_validate_submission filter
// ==========================================

add_filter( 'cmm4_validate_submission', 'cmm4_ag_validate_submission', 10, 3 );

function cmm4_ag_validate_submission( $error, $form_id, $clean_data ) {
	// Already blocked by an earlier validator — don't overwrite that message.
	if ( ! empty( $error ) ) {
		return $error;
	}

	if ( ! cmm4_ag_form_is_scoped( $form_id ) ) {
		return $error;
	}

	// Location rules first (cheap, no DB scan), then the duplicate check.
	foreach ( cmm4_ag_get_location_rules() as $rule ) {
		$location_error = cmm4_ag_check_location_rule( $rule, $clean_data );
		if ( ! empty( $location_error ) ) {
			return $location_error;
		}
	}

	if ( get_option( 'cmm4_ag_dupe_enabled' ) === 'yes' ) {
		$dupe_error = cmm4_ag_check_duplicate( $form_id, $clean_data );
		if ( ! empty( $dupe_error ) ) {
			return $dupe_error;
		}
	}

	return $error;
}

// Is this form ID one of the ones Application Guard should watch? Empty
// setting = every form (documented and flagged on the settings page), same
// convention as Job Application Sync uses.
function cmm4_ag_form_is_scoped( $form_id ) {
	$configured = trim( (string) get_option( 'cmm4_ag_form_ids', '' ) );
	if ( $configured === '' ) {
		return true;
	}
	$ids = array_map( 'trim', explode( ',', $configured ) );
	return in_array( (string) $form_id, $ids, true );
}

// ==========================================
// RULE 1 — DUPLICATE APPLICATION CHECK (EMAIL OR PHONE)
// ==========================================

function cmm4_ag_check_duplicate( $form_id, $clean_data ) {
	$applicant_email = cmm4_ag_extract_email( $clean_data );
	$applicant_phone = cmm4_ag_extract_phone( $clean_data );

	if ( $applicant_email === '' && $applicant_phone === '' ) {
		return ''; // Neither field exists on this form — nothing to compare against.
	}

	global $wpdb;
	$table = $wpdb->prefix . 'cmm4_submissions';

	// Filtering by form_id (indexed) first keeps this fast even with a lot
	// of historical submissions; the email/phone match itself is done in PHP
	// since either could sit under any field name inside the JSON blob.
	$rows = $wpdb->get_col( $wpdb->prepare(
		"SELECT submission_data FROM {$table} WHERE form_id = %d",
		$form_id
	) );

	if ( empty( $rows ) ) {
		return '';
	}

	foreach ( $rows as $row_json ) {
		$row_data = json_decode( $row_json, true );
		if ( ! is_array( $row_data ) ) {
			continue;
		}

		// Email match (case-insensitive).
		if ( $applicant_email !== '' ) {
			$existing_email = cmm4_ag_extract_email( $row_data );
			if ( $existing_email !== '' && strcasecmp( $existing_email, $applicant_email ) === 0 ) {
				return cmm4_ag_dupe_message();
			}
		}

		// Phone match (normalized + suffix-tolerant, see cmm4_ag_phones_match()).
		if ( $applicant_phone !== '' ) {
			$existing_phone = cmm4_ag_extract_phone( $row_data );
			if ( cmm4_ag_phones_match( $applicant_phone, $existing_phone ) ) {
				return cmm4_ag_dupe_message();
			}
		}
	}

	return '';
}

function cmm4_ag_dupe_message() {
	$custom = trim( (string) get_option( 'cmm4_ag_dupe_message', '' ) );
	return $custom !== '' ? $custom : "You've already applied for this position with this email address or phone number.";
}

// Same "any key containing 'email' with a valid email value" convention the
// core plugin's own submission router uses, so this stays in sync regardless
// of what a given form names its email field.
function cmm4_ag_extract_email( $data ) {
	foreach ( $data as $key => $val ) {
		if ( is_array( $val ) ) {
			continue;
		}
		if ( strpos( (string) $key, 'email' ) !== false && is_email( $val ) ) {
			return $val;
		}
	}
	return '';
}

// Finds a phone-ish field by its key name ("Phone Number" -> phone-number,
// "Contact No" -> contact-no, plus mobile/tel/whatsapp variants) and returns
// it normalized to digits only.
function cmm4_ag_extract_phone( $data ) {
	$needles = array( 'phone', 'contact', 'mobile', 'tel', 'whatsapp' );
	foreach ( $data as $key => $val ) {
		if ( is_array( $val ) ) {
			continue;
		}
		$key_lower = strtolower( (string) $key );
		foreach ( $needles as $needle ) {
			if ( strpos( $key_lower, $needle ) !== false ) {
				$normalized = cmm4_ag_normalize_phone( $val );
				// >= 7 digits so junk like "N/A" or "—" never counts as a phone.
				if ( strlen( $normalized ) >= 7 ) {
					return $normalized;
				}
			}
		}
	}
	return '';
}

// Reduces any phone format to comparable digits: strips +, spaces, dashes,
// parentheses; drops an international "00" prefix; drops a single local
// trunk "0". Result: "0300 1234567" -> "3001234567", "+92 300 1234567" ->
// "923001234567" — and cmm4_ag_phones_match() bridges those two forms.
function cmm4_ag_normalize_phone( $phone ) {
	$digits = preg_replace( '/\D+/', '', (string) $phone );
	if ( $digits === '' ) {
		return '';
	}
	if ( strpos( $digits, '00' ) === 0 ) {
		$digits = substr( $digits, 2 );
	}
	$digits = ltrim( $digits, '0' );
	return $digits;
}

// Two numbers match if identical, or — when both are real phone lengths
// (>= 9 digits) — if the shorter is the tail of the longer. That last part
// is what makes local-format "3001234567" match country-code "923001234567"
// without hardcoding any specific country's length.
function cmm4_ag_phones_match( $a, $b ) {
	if ( $a === '' || $b === '' ) {
		return false;
	}
	if ( $a === $b ) {
		return true;
	}
	if ( strlen( $a ) < 9 || strlen( $b ) < 9 ) {
		return false;
	}
	$short = strlen( $a ) <= strlen( $b ) ? $a : $b;
	$long  = strlen( $a ) <= strlen( $b ) ? $b : $a;
	return substr( $long, -strlen( $short ) ) === $short;
}

// ==========================================
// RULE 2 — LOCATION RESTRICTIONS (PER SLOT)
// ==========================================

function cmm4_ag_check_location_rule( $rule, $clean_data ) {
	if ( empty( $rule['enabled'] ) || $rule['enabled'] !== 'yes' ) {
		return '';
	}

	$field_names = array_filter( array_map( 'trim', explode( ',', (string) $rule['field_names'] ) ), 'strlen' );
	if ( empty( $field_names ) ) {
		return ''; // No field label configured — nothing to check.
	}

	$allowed_lines = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) $rule['allowed'] ) ), 'strlen' );
	if ( empty( $allowed_lines ) ) {
		return ''; // No allowed list configured — nothing to check against.
	}

	// Two lookup sets, because the submitted value's format depends on the
	// field type the admin used:
	//   - a classic Dropdown stores sanitize_title() of the chosen option
	//     ("Mirpur Mathelo" -> "mirpur-mathelo"),
	//   - a Text field / Radio / new ACF types store the plain label.
	// Checking both keeps the rule correct for every field type.
	$allowed_slugs = array();
	$allowed_plain = array();
	foreach ( $allowed_lines as $line ) {
		$allowed_slugs[] = sanitize_title( $line );
		$allowed_plain[] = strtolower( preg_replace( '/\s+/', ' ', $line ) );
	}

	// Find the submitted value under any of the configured field labels.
	$submitted_values = array();
	foreach ( $field_names as $candidate_label ) {
		$candidate_key = sanitize_title( $candidate_label );
		if ( isset( $clean_data[ $candidate_key ] ) ) {
			$val = $clean_data[ $candidate_key ];
			if ( is_array( $val ) ) {
				// Checkbox-group location field: collect every selected value.
				$submitted_values = array_filter( array_map( 'trim', array_map( 'strval', $val ) ), 'strlen' );
			} else {
				$trimmed          = trim( (string) $val );
				$submitted_values = ( $trimmed !== '' ) ? array( $trimmed ) : array();
			}
			break;
		}
	}

	if ( empty( $submitted_values ) ) {
		// Field absent or left empty — the core's server-side required-field
		// check is what should catch that, not this rule (so a form without
		// this field at all is never accidentally blocked).
		return '';
	}

	// EVERY selected value must be on the allowed list. Checking for ANY let an
	// applicant tick one allowed district plus one blocked district and get
	// through. A single-value dropdown behaves exactly as before.
	$all_allowed = true;
	foreach ( $submitted_values as $submitted_value ) {
		$slug  = sanitize_title( $submitted_value );
		$plain = strtolower( preg_replace( '/\s+/', ' ', $submitted_value ) );
		if ( ! in_array( $slug, $allowed_slugs, true ) && ! in_array( $plain, $allowed_plain, true ) ) {
			$all_allowed = false;
			break;
		}
	}
	if ( $all_allowed ) {
		return '';
	}

	$custom = trim( (string) $rule['message'] );
	if ( $custom !== '' ) {
		return $custom;
	}
	return ( isset( $rule['default_message'] ) && $rule['default_message'] !== '' )
		? $rule['default_message']
		: 'This position is only open to applicants from the allowed locations.';
}