<?php
/**
 * ForeningLet configuration view.
 *
 * @package PCIO_VIS_With_ForeningLet
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Vis - ForeningLet', 'pcio-vis-with-foreninglet' ); ?></h1>
	<?php if ( $saved ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'ForeningLet settings saved.', 'pcio-vis-with-foreninglet' ); ?></p></div>
	<?php endif; ?>
	<p><?php esc_html_e( 'Saving credentials permits member retrieval from ForeningLet when the sync job runs. No requests are made while displaying this page.', 'pcio-vis-with-foreninglet' ); ?></p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="pcio_vis_foreninglet_save">
		<?php wp_nonce_field( 'pcio_vis_foreninglet_save' ); ?>
		<h2><?php esc_html_e( 'Config', 'pcio-vis-with-foreninglet' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="foreninglet-host"><?php esc_html_e( 'Host (API URL)', 'pcio-vis-with-foreninglet' ); ?></label></th>
				<td>
					<input type="url" class="large-text" id="foreninglet-host" name="host" required value="<?php echo esc_attr( $host ); ?>">
					<p class="description"><?php esc_html_e( 'Use the full HTTPS member-list endpoint: https://foreninglet.dk/api/members?version=1. Redirects and filtered or paginated URLs are not accepted.', 'pcio-vis-with-foreninglet' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="foreninglet-userpwd"><?php esc_html_e( 'User password (username:password)', 'pcio-vis-with-foreninglet' ); ?></label></th>
				<td>
					<input type="password" class="regular-text" id="foreninglet-userpwd" name="userpwd" value="" autocomplete="new-password">
					<p class="description"><?php esc_html_e( 'Enter the API username and password separated by a colon. Leave blank to keep the saved credentials. Stored credentials are never displayed.', 'pcio-vis-with-foreninglet' ); ?></p>
					<label><input type="checkbox" name="clear_credentials" value="1"> <?php esc_html_e( 'Remove saved credentials (stops API access)', 'pcio-vis-with-foreninglet' ); ?></label>
				</td>
			</tr>
		</table>
		<?php submit_button( __( 'Save ForeningLet settings', 'pcio-vis-with-foreninglet' ) ); ?>
	</form>
	<p>
		<?php
		echo $configured
			? esc_html__( 'Credentials are configured.', 'pcio-vis-with-foreninglet' )
			: esc_html__( 'Credentials are not configured. The sync cannot run.', 'pcio-vis-with-foreninglet' );
		?>
	</p>
	<p><?php esc_html_e( 'The daily sync job starts disabled. Use Sync Jobs to run it manually, inspect its result, and enable or change its schedule. Every API-returned member is considered valid. Administrators are never changed; volunteers never lose vis_member. Member records and WordPress accounts are never deleted.', 'pcio-vis-with-foreninglet' ); ?></p>
	<p><?php esc_html_e( 'Members without email are skipped and counted as "no email" in the result; existing linked memberships are retained. Shared-email members get separate accounts with member-number usernames. They must log in and request password resets by username, not email. Anyone with access to the shared mailbox can receive password resets for those accounts.', 'pcio-vis-with-foreninglet' ); ?></p>
	<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=pcio-me-sync' ) ); ?>"><?php esc_html_e( 'Open Sync Jobs', 'pcio-vis-with-foreninglet' ); ?></a></p>
</div>
