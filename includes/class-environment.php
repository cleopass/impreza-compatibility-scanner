<?php
/**
 * Read-only information about the environment: theme, US Core, WPBakery, Ultimate Addons,
 * and the relevant US Core configuration.
 *
 * Every US Core function is called only after function_exists() checks, so the scanner keeps
 * working (with reduced evidence) when US Core is inactive.
 *
 * @package ImprezaCompatibilityScanner
 */

namespace ImprezaCompatScanner;

defined( 'ABSPATH' ) || exit;

class Environment {

	/** @var array|null Cached US config lists. */
	private static $us_shortcodes_config = null;

	/** @var array Cache for element configs. */
	private static $element_cache = array();

	/**
	 * Impreza theme info (parent theme), or null when Impreza is not the active parent theme.
	 *
	 * @return array{name:string,version:string,is_impreza:bool,child:string}
	 */
	public static function theme() {
		$parent = wp_get_theme( get_template() );
		$child  = get_stylesheet() !== get_template() ? wp_get_theme()->get( 'Name' ) : '';
		$name   = defined( 'US_THEMENAME' ) ? US_THEMENAME : $parent->get( 'Name' );

		return array(
			'name'       => (string) $name,
			'version'    => defined( 'US_THEMEVERSION' ) ? (string) US_THEMEVERSION : (string) $parent->get( 'Version' ),
			'is_impreza' => ( false !== stripos( (string) $parent->get( 'Name' ), 'impreza' ) ) || ( defined( 'US_THEMENAME' ) && 'Impreza' === US_THEMENAME ),
			'child'      => (string) $child,
		);
	}

	/**
	 * US Core status.
	 */
	public static function us_core() {
		return array(
			'active'  => defined( 'US_CORE_VERSION' ) || class_exists( 'US_Shortcodes', false ),
			'version' => defined( 'US_CORE_VERSION' ) ? (string) US_CORE_VERSION : '',
		);
	}

	/**
	 * Find a plugin by its main file name in the installed plugins list.
	 *
	 * @param string $main_file e.g. 'js_composer.php'.
	 * @return array{installed:bool,active:bool,version:string,file:string,dir:string}
	 */
	public static function plugin_status( $main_file ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$result = array(
			'installed' => false,
			'active'    => false,
			'version'   => '',
			'file'      => '',
			'dir'       => '',
		);
		foreach ( get_plugins() as $file => $data ) {
			if ( basename( $file ) === $main_file ) {
				$result['installed'] = true;
				$result['file']      = $file;
				$result['dir']       = dirname( $file );
				$result['version']   = isset( $data['Version'] ) ? (string) $data['Version'] : '';
				$result['active']    = is_plugin_active( $file ) || ( is_multisite() && is_plugin_active_for_network( $file ) );
				break;
			}
		}
		return $result;
	}

	public static function wpbakery() {
		$status = self::plugin_status( 'js_composer.php' );
		// WPBakery may also be bundled differently; the runtime constant is authoritative for "active".
		if ( defined( 'WPB_VC_VERSION' ) ) {
			$status['active']  = true;
			$status['version'] = (string) WPB_VC_VERSION;
			if ( ! $status['installed'] ) {
				$status['installed'] = true;
			}
		}
		return $status;
	}

	public static function ultimate_addons() {
		return self::plugin_status( 'Ultimate_VC_Addons.php' );
	}

	/**
	 * Folder name of the WPBakery plugin (used to recognise handler file locations).
	 */
	public static function wpbakery_dir_name() {
		if ( defined( 'WPB_PLUGIN_DIR' ) ) {
			return basename( wp_normalize_path( WPB_PLUGIN_DIR ) );
		}
		$status = self::plugin_status( 'js_composer.php' );
		return $status['dir'] ? $status['dir'] : 'js_composer';
	}

	/**
	 * Theme option reader (US Core / Impreza), read-only.
	 */
	public static function us_option( $name, $default = null ) {
		if ( function_exists( 'us_get_option' ) ) {
			return us_get_option( $name, $default );
		}
		return $default;
	}

	/**
	 * The US Core shortcodes config (theme_elements, alias, disabled, added_design_options).
	 *
	 * @return array
	 */
	public static function us_shortcodes_config() {
		if ( null !== self::$us_shortcodes_config ) {
			return self::$us_shortcodes_config;
		}
		$config = array();
		if ( function_exists( 'us_config' ) ) {
			$config = (array) us_config( 'shortcodes', array() );
		}
		foreach ( array( 'theme_elements', 'alias', 'disabled', 'added_design_options' ) as $key ) {
			if ( ! isset( $config[ $key ] ) || ! is_array( $config[ $key ] ) ) {
				$config[ $key ] = array();
			}
		}
		self::$us_shortcodes_config = $config;
		return $config;
	}

	/**
	 * Map of full shortcode tag → element (config file) name for every Impreza theme element.
	 * Mirrors US_Shortcodes::init() / us_get_shortcode_full_name().
	 *
	 * @return array<string,string>
	 */
	public static function us_theme_element_tags() {
		$map = array();
		foreach ( self::us_shortcodes_config()['theme_elements'] as $element ) {
			$element = (string) $element;
			if ( function_exists( 'us_get_shortcode_full_name' ) ) {
				$tag = us_get_shortcode_full_name( $element );
			} else {
				$tag = ( 0 === strpos( $element, 'vc_' ) ) ? $element : 'us_' . $element;
			}
			$map[ $tag ] = $element;
		}
		// US_Shortcodes::init() also registers the "alias" keys with its own handler.
		foreach ( array_keys( self::us_shortcodes_config()['alias'] ) as $alias_tag ) {
			if ( ! isset( $map[ $alias_tag ] ) ) {
				$map[ $alias_tag ] = function_exists( 'us_get_shortcode_name' ) ? us_get_shortcode_name( $alias_tag ) : preg_replace( '/^us_/', '', $alias_tag );
			}
		}
		return $map;
	}

	/**
	 * Config of one US element (config/elements/<name>.php), cached. Read-only.
	 *
	 * @return array
	 */
	public static function us_element_config( $element ) {
		if ( ! isset( self::$element_cache[ $element ] ) ) {
			$config = array();
			if ( function_exists( 'us_config' ) && preg_match( '/^[a-z0-9_\-]+$/i', $element ) ) {
				$config = (array) us_config( 'elements/' . $element, array() );
			}
			self::$element_cache[ $element ] = $config;
		}
		return self::$element_cache[ $element ];
	}

	/**
	 * Does the Live Builder list this element as editable? Mirrors USBuilder::get_config():
	 * element is in theme_elements, has a config, and place_if is not false.
	 *
	 * @return string Verdict::YES / NO / NOT_SURE
	 */
	public static function us_element_in_live_builder( $element ) {
		if ( ! function_exists( 'us_config' ) ) {
			return Verdict::NOT_SURE;
		}
		if ( ! in_array( $element, self::us_shortcodes_config()['theme_elements'], true ) ) {
			return Verdict::NO;
		}
		$config = self::us_element_config( $element );
		if ( empty( $config ) ) {
			return Verdict::NO;
		}
		if ( isset( $config['place_if'] ) && ! $config['place_if'] ) {
			return Verdict::NO;
		}
		return Verdict::YES;
	}

	/**
	 * Is a tag mapped into the WPBakery editor? Read-only WPBMap lookup.
	 *
	 * @return bool|null Null when WPBakery is not loaded.
	 */
	public static function wpbakery_mapped( $tag ) {
		if ( class_exists( 'WPBMap', false ) && method_exists( 'WPBMap', 'exists' ) ) {
			return (bool) \WPBMap::exists( $tag );
		}
		return null;
	}

	/**
	 * Known WPBakery element tags (data file).
	 *
	 * @return array{source_version:string,tags:string[]}
	 */
	public static function known_wpbakery_tags() {
		static $data = null;
		if ( null === $data ) {
			$data         = require ICS_DIR . 'data/wpbakery-elements.php';
			$data['tags'] = (array) apply_filters( 'ics_known_wpbakery_tags', $data['tags'] );
		}
		return $data;
	}

	/**
	 * Add-ons with a verified WPBakery dependency (data file + filter).
	 *
	 * @return array
	 */
	public static function wpbakery_dependent_addons() {
		static $data = null;
		if ( null === $data ) {
			$data = (array) apply_filters( 'ics_wpbakery_dependent_addons', require ICS_DIR . 'data/wpbakery-dependent-addons.php' );
		}
		return $data;
	}

	/**
	 * Snapshot for the dashboard / scan state.
	 */
	public static function summary() {
		$theme = self::theme();
		$core  = self::us_core();
		$wpb   = self::wpbakery();
		$ult   = self::ultimate_addons();

		return array(
			'theme_name'       => $theme['name'],
			'theme_version'    => $theme['version'],
			'is_impreza'       => $theme['is_impreza'],
			'child_theme'      => $theme['child'],
			'us_core_active'   => $core['active'],
			'us_core_version'  => $core['version'],
			'wpb_installed'    => $wpb['installed'],
			'wpb_active'       => $wpb['active'],
			'wpb_version'      => $wpb['version'],
			'ult_installed'    => $ult['installed'],
			'ult_active'       => $ult['active'],
			'ult_version'      => $ult['version'],
			'live_builder'     => (bool) self::us_option( 'live_builder', 0 ),
			'disable_extra_vc' => (bool) self::us_option( 'disable_extra_vc', 1 ),
		);
	}
}
