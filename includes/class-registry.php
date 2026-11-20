<?php
/**
 * Shortcode registry inspection.
 *
 * Reads the global $shortcode_tags (never calls a handler) and works out WHO handles each tag
 * by reflecting on the callback's source file.
 *
 * WPBakery registers most of its shortcodes only on the front end (template_redirect), see
 * docs/FINDINGS.md §4. The admin runtime is therefore incomplete, and the plugin captures the
 * front-end registry through an authenticated, nonce-protected probe request that stops at
 * template_redirect (priority 9999) and only returns tag → handler information.
 *
 * @package ImprezaCompatibilityScanner
 */

namespace ImprezaCompatScanner;

defined( 'ABSPATH' ) || exit;

class Registry {

	const PROBE_QUERY_VAR = 'ics_registry_probe';
	const PROBE_NONCE     = 'ics_registry_probe';
	const FRONTEND_OPTION = 'ics_frontend_registry';

	/** Origin identifiers. */
	const ORIGIN_US_CORE    = 'us-core';
	const ORIGIN_WPBAKERY   = 'wpbakery';
	const ORIGIN_WORDPRESS  = 'wordpress';
	const ORIGIN_PLUGIN     = 'plugin';
	const ORIGIN_MU_PLUGIN  = 'mu-plugin';
	const ORIGIN_THEME      = 'theme';
	const ORIGIN_CHILDTHEME = 'child-theme';
	const ORIGIN_UNKNOWN    = 'unknown';

	/**
	 * Snapshot of the current runtime's registry.
	 *
	 * @return array<string,array> tag => description (see describe_callback()).
	 */
	public static function snapshot() {
		global $shortcode_tags;
		$out = array();
		foreach ( (array) $shortcode_tags as $tag => $callback ) {
			$out[ (string) $tag ] = self::describe_callback( $callback );
		}
		return $out;
	}

	/**
	 * Describe a shortcode callback without invoking it.
	 *
	 * @param mixed $callback
	 * @return array{origin:string,slug:string,label:string,file:string}
	 */
	public static function describe_callback( $callback ) {
		$label = '';
		$file  = '';
		$class = '';

		try {
			if ( is_string( $callback ) && false !== strpos( $callback, '::' ) ) {
				$callback = explode( '::', $callback, 2 );
			}

			if ( is_array( $callback ) && 2 === count( $callback ) ) {
				list( $target, $method ) = array_values( $callback );
				$class = is_object( $target ) ? get_class( $target ) : (string) $target;
				$label = $class . ( is_object( $target ) ? '->' : '::' ) . $method;

				if ( class_exists( $class, false ) ) {
					$ref = new \ReflectionClass( $class );
					// US_Shortcodes routes every tag through __call(), so the method itself may not exist.
					if ( is_string( $method ) && $ref->hasMethod( $method ) ) {
						$file = (string) $ref->getMethod( $method )->getFileName();
					}
					if ( '' === $file ) {
						$file = (string) $ref->getFileName();
					}
				}
			} elseif ( $callback instanceof \Closure ) {
				$ref   = new \ReflectionFunction( $callback );
				$file  = (string) $ref->getFileName();
				$label = 'Closure';
			} elseif ( is_string( $callback ) && function_exists( $callback ) ) {
				$ref   = new \ReflectionFunction( $callback );
				$file  = (string) $ref->getFileName();
				$label = $callback . '()';
			} elseif ( is_object( $callback ) && method_exists( $callback, '__invoke' ) ) {
				$class = get_class( $callback );
				$ref   = new \ReflectionMethod( $callback, '__invoke' );
				$file  = (string) $ref->getFileName();
				$label = $class . '->__invoke';
			} else {
				$label = is_string( $callback ) ? $callback : gettype( $callback );
			}
		} catch ( \ReflectionException $e ) {
			$file = '';
		}

		list( $origin, $slug ) = self::origin_from_file( $file );

		// Strong, code-level identification takes precedence over file location.
		if ( '' !== $class && ( 'US_Shortcodes' === $class || is_subclass_of( $class, 'US_Shortcodes' ) ) ) {
			$origin = self::ORIGIN_US_CORE;
		} elseif (
			( is_string( $callback ) && 'vc_do_shortcode' === $callback )
			|| ( '' !== $class && ( is_a( $class, 'WPBakeryShortCode', true ) || is_a( $class, 'WPBakeryShortCodeFishBones', true ) ) )
		) {
			$origin = self::ORIGIN_WPBAKERY;
			$slug   = Environment::wpbakery_dir_name();
		}

		return array(
			'origin' => $origin,
			'slug'   => $slug,
			'label'  => $label,
			'file'   => self::relative_path( $file ),
		);
	}

	/**
	 * Work out where a file lives.
	 *
	 * @return array{0:string,1:string} origin, slug
	 */
	public static function origin_from_file( $file ) {
		if ( '' === (string) $file ) {
			return array( self::ORIGIN_UNKNOWN, '' );
		}
		$file = wp_normalize_path( $file );

		$candidates = array(
			array( wp_normalize_path( ABSPATH . WPINC ) . '/', self::ORIGIN_WORDPRESS, false ),
			array( wp_normalize_path( WP_PLUGIN_DIR ) . '/', self::ORIGIN_PLUGIN, true ),
			array( wp_normalize_path( WPMU_PLUGIN_DIR ) . '/', self::ORIGIN_MU_PLUGIN, true ),
			array( trailingslashit( wp_normalize_path( get_stylesheet_directory() ) ), self::ORIGIN_CHILDTHEME, false ),
			array( trailingslashit( wp_normalize_path( get_template_directory() ) ), self::ORIGIN_THEME, false ),
		);
		// Parent and child theme may be the same directory.
		if ( get_stylesheet_directory() === get_template_directory() ) {
			unset( $candidates[3] );
		}

		foreach ( $candidates as $c ) {
			list( $base, $origin, $has_slug ) = $c;
			if ( 0 === strpos( $file, $base ) ) {
				$rest = substr( $file, strlen( $base ) );
				$slug = '';
				if ( $has_slug ) {
					$parts = explode( '/', $rest );
					$slug  = count( $parts ) > 1 ? $parts[0] : basename( $rest, '.php' );
				} elseif ( self::ORIGIN_THEME === $origin ) {
					$slug = get_template();
				} elseif ( self::ORIGIN_CHILDTHEME === $origin ) {
					$slug = get_stylesheet();
				}

				if ( self::ORIGIN_PLUGIN === $origin ) {
					if ( Environment::wpbakery_dir_name() === $slug ) {
						return array( self::ORIGIN_WPBAKERY, $slug );
					}
					if ( 'us-core' === $slug ) {
						return array( self::ORIGIN_US_CORE, $slug );
					}
				}
				return array( $origin, $slug );
			}
		}
		return array( self::ORIGIN_UNKNOWN, '' );
	}

	private static function relative_path( $file ) {
		if ( '' === (string) $file ) {
			return '';
		}
		$file = wp_normalize_path( $file );
		$root = wp_normalize_path( ABSPATH );
		if ( 0 === strpos( $file, $root ) ) {
			return substr( $file, strlen( $root ) );
		}
		$content = wp_normalize_path( WP_CONTENT_DIR );
		if ( 0 === strpos( $file, $content ) ) {
			return 'wp-content' . substr( $file, strlen( $content ) );
		}
		return basename( $file );
	}

	/* ---------------------------------------------------------------------
	 * Front-end probe
	 * ------------------------------------------------------------------ */

	/**
	 * URL the admin page fetches (same origin, with the user's cookies).
	 */
	public static function probe_url() {
		return add_query_arg(
			array(
				self::PROBE_QUERY_VAR => wp_create_nonce( self::PROBE_NONCE ),
				'ics_nocache'         => time(),
			),
			home_url( '/' )
		);
	}

	/**
	 * template_redirect (priority 9999) handler: capture the front-end registry and stop.
	 * Runs after WPBakery's WPBMap::addAllMappedShortcodes (template_redirect, priority 10).
	 */
	public static function maybe_handle_probe() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified below.
		if ( ! isset( $_GET[ self::PROBE_QUERY_VAR ] ) ) {
			return;
		}
		nocache_headers();

		$nonce = sanitize_text_field( wp_unslash( $_GET[ self::PROBE_QUERY_VAR ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! wp_verify_nonce( $nonce, self::PROBE_NONCE ) || ! Plugin::current_user_can_scan() ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}

		$snapshot = array(
			'captured_at' => time(),
			'wpb_active'  => defined( 'WPB_VC_VERSION' ),
			'tags'        => self::snapshot(),
		);
		update_option( self::FRONTEND_OPTION, $snapshot, false );

		wp_send_json_success(
			array(
				'tags'       => count( $snapshot['tags'] ),
				'wpb_active' => $snapshot['wpb_active'],
			)
		);
	}

	/**
	 * Stored front-end snapshot (or null).
	 */
	public static function frontend_snapshot() {
		$snap = get_option( self::FRONTEND_OPTION );
		return ( is_array( $snap ) && isset( $snap['tags'] ) ) ? $snap : null;
	}

	/**
	 * Merge front-end and admin registries. The front end wins, because that is where content renders.
	 *
	 * @param int $max_age Max age (seconds) for the front-end snapshot to be used.
	 * @return array{tags:array,frontend:bool,frontend_at:int,frontend_wpb_active:bool|null}
	 */
	public static function merged( $max_age = 0 ) {
		$admin    = self::snapshot();
		$frontend = self::frontend_snapshot();
		$use_fe   = $frontend && ( ! $max_age || ( time() - (int) $frontend['captured_at'] ) <= $max_age );

		$tags = $admin;
		foreach ( $tags as $tag => $info ) {
			$tags[ $tag ]['context'] = 'admin';
		}
		if ( $use_fe ) {
			foreach ( (array) $frontend['tags'] as $tag => $info ) {
				$info['context'] = isset( $admin[ $tag ] ) ? 'frontend+admin' : 'frontend';
				$tags[ $tag ]    = $info;
			}
		}

		return array(
			'tags'                => $tags,
			'frontend'            => (bool) $use_fe,
			'frontend_at'         => $use_fe ? (int) $frontend['captured_at'] : 0,
			'frontend_wpb_active' => $use_fe ? (bool) $frontend['wpb_active'] : null,
		);
	}
}
