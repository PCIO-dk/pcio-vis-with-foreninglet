<?php
/**
 * Remove extension configuration and its registry job, retaining imported data.
 *
 * @package PCIO_VIS_With_ForeningLet
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

wp_clear_scheduled_hook( 'pcio_me_cron_pcio_vis_foreninglet_sync' );
delete_option( 'pcio_vis_foreninglet_host' );
delete_option( 'pcio_vis_foreninglet_userpwd' );
delete_option( 'pcio_vis_foreninglet_lock' );

if ( class_exists( 'PCIO_VIS_Sync_DB' ) ) {
	$pcio_vis_foreninglet_job = PCIO_VIS_Sync_DB::get_by_cron_name( 'pcio_vis_foreninglet_sync' );
	if ( $pcio_vis_foreninglet_job ) {
		if ( ! PCIO_VIS_Sync_DB::delete( (int) $pcio_vis_foreninglet_job['id'] ) ) {
			throw new RuntimeException( esc_html__( 'Could not remove the ForeningLet sync job.', 'pcio-vis-with-foreninglet' ) );
		}
	}
}
