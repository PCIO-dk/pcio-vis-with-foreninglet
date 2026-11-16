<?php
/**
 * Plugin Name: PCIO VIS With ForeningLet
 * Plugin URI: https://www.pcio.dk/vis-plugins
 * Description: Imports ForeningLet members into PCIO VIS Member Event through a registered sync job.
 * Version: 1.1.0
 * Author: PCIO
 * Author URI: https://www.pcio.dk
 * Requires at least: 6.2
 * Requires PHP: 8.1
 * Requires Plugins: pcio-vis-member-event
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: pcio-vis-with-foreninglet
 * Domain Path: /languages
 *
 * @package PCIO_VIS_With_ForeningLet
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-pcio-vis-with-foreninglet-source.php';
require_once __DIR__ . '/includes/class-pcio-vis-with-foreninglet.php';
require_once __DIR__ . '/includes/class-pcio-vis-with-foreninglet-plugin.php';

add_action(
	'plugins_loaded',
	static function (): void {
		if ( class_exists( 'PCIO_VIS_Plugin' ) ) {
			PCIO_VIS_With_ForeningLet_Plugin::register();
		}
	}
);
register_deactivation_hook( __FILE__, array( 'PCIO_VIS_With_ForeningLet_Plugin', 'deactivate' ) );
