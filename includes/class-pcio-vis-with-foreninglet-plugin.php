<?php
/**
 * Core hooks and the administrator-only ForeningLet configuration controller.
 *
 * @package PCIO_VIS_With_ForeningLet
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Registers the extension and manages administrator configuration. */
class PCIO_VIS_With_ForeningLet_Plugin {
	const CRON_NAME = 'pcio_vis_foreninglet_sync';

	/** Register extension hooks after the core is loaded. */
	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 20 );
		add_action( 'admin_post_pcio_vis_foreninglet_save', array( __CLASS__, 'save_settings' ) );
		add_action( 'pcio_me_sync_register_jobs', array( __CLASS__, 'register_job' ) );
		add_filter( 'pcio_me_sync_callables', array( __CLASS__, 'register_callable' ) );
		add_filter( 'pcio_me_member_subscription', array( __CLASS__, 'filter_subscription' ), 10, 3 );
	}

	/**
	 * Show a ForeningLet-backed member as an active membership on the member
	 * Profile -> Membership tab. The sync only imports current ForeningLet
	 * members, so presence in Vis means the membership is current; ForeningLet
	 * does not expose an expiry date through the member endpoint.
	 *
	 * @param array|null          $subscription Existing subscription from another source.
	 * @param int                 $member_id    Vis member ID.
	 * @param array<string,mixed> $member       Member row.
	 * @return array|null
	 */
	public static function filter_subscription( $subscription, int $member_id, array $member ) {
		if ( is_array( $subscription ) ) {
			return $subscription;
		}
		$assignment = class_exists( 'PCIO_VIS_Roles_DB' ) ? PCIO_VIS_Roles_DB::get_member_role( $member_id ) : null;
		$uid        = (int) ( $assignment['wp_user_id'] ?? 0 );
		if ( $uid < 1 || ! get_user_meta( $uid, PCIO_VIS_With_ForeningLet::USER_MEMBER_NUMBER, true ) ) {
			return $subscription;
		}
		return array(
			'status'       => 'active',
			'label'        => __( 'Active in ForeningLet', 'pcio-vis-with-foreninglet' ),
			'source'       => 'ForeningLet',
			'product_name' => '',
			'start_date'   => (string) ( $member['join_date'] ?? '' ),
			'expire_date'  => '',
		);
	}

	/**
	 * Seed one disabled daily job without overwriting administrator changes.
	 *
	 * @throws RuntimeException When the registry cannot store the job.
	 */
	public static function register_job(): void {
		if ( PCIO_VIS_Sync_DB::get_by_cron_name( self::CRON_NAME ) ) {
			return;
		}
		$id = PCIO_VIS_Sync_DB::create(
			array(
				'name'         => __( 'ForeningLet member sync', 'pcio-vis-with-foreninglet' ),
				'description'  => __( 'Imports ForeningLet members and grants vis_member. Revokes vis_member from absent accounts, except administrators and volunteers. Keeps all member records and WordPress accounts.', 'pcio-vis-with-foreninglet' ),
				'cron_name'    => self::CRON_NAME,
				'php_function' => 'PCIO_VIS_With_ForeningLet::run_sync',
				'sync_url'     => '',
				'frequence'    => 24,
				'enabled'      => 0,
			)
		);
		if ( ! $id ) {
			throw new RuntimeException( esc_html__( 'Could not register the ForeningLet sync job.', 'pcio-vis-with-foreninglet' ) );
		}
	}

	/**
	 * Allow the core executor to run this extension.
	 *
	 * @param array $callables Core callable allow-list.
	 * @return array
	 */
	public static function register_callable( array $callables ): array {
		$callables['PCIO_VIS_With_ForeningLet::run_sync'] = __( 'ForeningLet member sync', 'pcio-vis-with-foreninglet' );
		return $callables;
	}

	/** Add the ForeningLet menu to Vis administration. */
	public static function add_menu(): void {
		add_submenu_page(
			'pcio-me-settings',
			__( 'ForeningLet', 'pcio-vis-with-foreninglet' ),
			__( 'ForeningLet', 'pcio-vis-with-foreninglet' ),
			'vis_manage_settings',
			'pcio-vis-foreninglet',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	/** Validate and save connection details through a nonce-protected POST. */
	public static function save_settings(): void {
		if ( ! current_user_can( 'vis_manage_settings' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'pcio-vis-with-foreninglet' ) );
		}
		check_admin_referer( 'pcio_vis_foreninglet_save' );
		$host = isset( $_POST['host'] ) && is_string( $_POST['host'] ) ? esc_url_raw( wp_unslash( $_POST['host'] ) ) : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated by valid_userpwd() below; text sanitization corrupts passwords.
		$userpwd = isset( $_POST['userpwd'] ) && is_string( $_POST['userpwd'] ) ? wp_unslash( $_POST['userpwd'] ) : '';
		$clear   = isset( $_POST['clear_credentials'] ) && is_string( $_POST['clear_credentials'] )
			&& '1' === sanitize_key( wp_unslash( $_POST['clear_credentials'] ) );
		if ( ! PCIO_VIS_With_ForeningLet_Source::valid_host( $host )
			|| ( '' !== $userpwd && ! PCIO_VIS_With_ForeningLet_Source::valid_userpwd( $userpwd ) ) ) {
			wp_die( esc_html__( 'Settings were not saved. Use https://foreninglet.dk/api/members?version=1 and credentials in username:password format, without control characters.', 'pcio-vis-with-foreninglet' ) );
		}
		self::store_option( PCIO_VIS_With_ForeningLet_Source::OPT_HOST, $host );
		if ( $clear || '' !== $userpwd ) {
			// Passwords are validated, not text-sanitized: sanitization can corrupt valid secrets.
			self::store_option( PCIO_VIS_With_ForeningLet_Source::OPT_USERPWD, $clear ? '' : $userpwd );
		}
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'     => 'pcio-vis-foreninglet',
					'saved'    => '1',
					'_wpnonce' => wp_create_nonce( 'pcio_vis_foreninglet_saved' ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Save non-autoloaded options and report actual storage failures.
	 *
	 * @param string $name Option name.
	 * @param string $value Validated option value.
	 */
	private static function store_option( string $name, string $value ): void {
		if ( ! update_option( $name, $value, false ) && get_option( $name, null ) !== $value ) {
			wp_die( esc_html__( 'Could not save the ForeningLet settings.', 'pcio-vis-with-foreninglet' ) );
		}
	}

	/** Render the view without handling writes or member synchronization. */
	public static function render_settings_page(): void {
		if ( ! current_user_can( 'vis_manage_settings' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'pcio-vis-with-foreninglet' ) );
		}
		$host       = (string) get_option( PCIO_VIS_With_ForeningLet_Source::OPT_HOST, PCIO_VIS_With_ForeningLet_Source::DEFAULT_HOST );
		$configured = PCIO_VIS_With_ForeningLet_Source::valid_userpwd( (string) get_option( PCIO_VIS_With_ForeningLet_Source::OPT_USERPWD, '' ) );
		$saved      = isset( $_GET['_wpnonce'] ) && is_string( $_GET['_wpnonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'pcio_vis_foreninglet_saved' )
			&& isset( $_GET['saved'] ) && is_string( $_GET['saved'] )
			&& '1' === sanitize_key( wp_unslash( $_GET['saved'] ) );
		require dirname( __DIR__ ) . '/templates/settings.php';
	}

	/**
	 * Disable the registry job and remove events; retain settings and history.
	 *
	 * @throws RuntimeException When the registry job cannot be disabled.
	 */
	public static function deactivate(): void {
		if ( class_exists( 'PCIO_VIS_Sync_DB' ) ) {
			$job = PCIO_VIS_Sync_DB::get_by_cron_name( self::CRON_NAME );
			if ( $job && (int) $job['enabled'] && ! PCIO_VIS_Sync_DB::update( (int) $job['id'], array( 'enabled' => 0 ) ) ) {
				throw new RuntimeException( esc_html__( 'Could not disable the ForeningLet sync job.', 'pcio-vis-with-foreninglet' ) );
			}
		}
		wp_clear_scheduled_hook( 'pcio_me_cron_' . self::CRON_NAME );
	}
}
