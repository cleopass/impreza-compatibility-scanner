<?php
/**
 * Plugin Name:       Impreza Compatibility Scanner
 * Description:       Read-only audit of shortcodes in your content: which ones are native Impreza / US Core elements, which depend on WPBakery Page Builder, and which need manual review before WPBakery is deactivated.
 * Plugin URI:        https://github.com/cleopas/impreza-compatibility-scanner
 * Version:           0.9.0-beta
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Cleopas
 * Author URI:        https://cleopas.si
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       impreza-compatibility-scanner
 * Domain Path:       /languages
 *
 * Not affiliated with or endorsed by UpSolution (Impreza, US Core) or WPBakery.
 *
 * @package ImprezaCompatibilityScanner
 */

defined( 'ABSPATH' ) || exit;

define( 'ICS_VERSION', '0.9.0-beta' );
define( 'ICS_DB_VERSION', '1' );
define( 'ICS_FILE', __FILE__ );
define( 'ICS_DIR', plugin_dir_path( __FILE__ ) );
define( 'ICS_URL', plugin_dir_url( __FILE__ ) );

/**
 * Minimal PSR-4 style autoloader for the ImprezaCompatScanner namespace.
 *
 * ImprezaCompatScanner\Admin\Results_Table → includes/admin/class-results-table.php
 * ImprezaCompatScanner\Rules\Rule_Wpbakery  → includes/rules/class-rule-wpbakery.php
 */
spl_autoload_register(
	static function ( $class ) {
		$prefix = 'ImprezaCompatScanner\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$relative = substr( $class, strlen( $prefix ) );
		$parts    = explode( '\\', $relative );
		$name     = array_pop( $parts );
		$dir      = $parts ? strtolower( implode( '/', $parts ) ) . '/' : '';
		$prefixes = array( 'class-', 'interface-' );
		foreach ( $prefixes as $file_prefix ) {
			$file = ICS_DIR . 'includes/' . $dir . $file_prefix . strtolower( str_replace( '_', '-', $name ) ) . '.php';
			if ( is_readable( $file ) ) {
				require_once $file;
				return;
			}
		}
	}
);

register_activation_hook( __FILE__, array( 'ImprezaCompatScanner\\Results_Store', 'install' ) );

add_action( 'plugins_loaded', array( 'ImprezaCompatScanner\\Plugin', 'boot' ) );
