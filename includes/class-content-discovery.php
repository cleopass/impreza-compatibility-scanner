<?php
/**
 * Content discovery — finds shortcode-bearing content with read-only, batched queries.
 *
 * Sources:
 *  - posts of all relevant post types (post_content + post_excerpt), keyset-paginated by ID
 *  - post meta of those posts (string values containing "[", serialized arrays are walked safely)
 *  - widget instances (all widget_* options)
 *  - selected options (WPBakery "My templates": wpb_js_templates)
 *
 * @package ImprezaCompatibilityScanner
 */

namespace ImprezaCompatScanner;

defined( 'ABSPATH' ) || exit;

class Content_Discovery {

	/** Max length of a single meta/option string that will be parsed (bytes). */
	const MAX_VALUE_LENGTH = 2097152;

	/**
	 * Post types never scanned: no shortcode content, JSON content, or personal data.
	 */
	const EXCLUDED_POST_TYPES = array(
		'revision',
		'attachment',
		'nav_menu_item',
		'custom_css',
		'customize_changeset',
		'oembed_cache',
		'user_request',
		'wp_global_styles',
		'wp_font_family',
		'wp_font_face',
		'us_header',        // JSON layout (US Core).
		'us_grid_layout',   // JSON layout (US Core).
		'us_cform_inbound', // Contact form submissions (personal data).
		'shop_order',
		'shop_order_refund',
		'shop_order_placehold',
		'shop_coupon',
		'scheduled-action',
	);

	/**
	 * Meta keys never scanned (internal data, CSS, layout JSON, caches).
	 */
	const EXCLUDED_META_KEYS = array(
		'_wpb_shortcodes_custom_css',
		'_wpb_post_custom_css',
		'_wpb_post_custom_layout',
		'_wpb_vc_js_status',
		'_us_jsoncss_data',
		'_edit_lock',
		'_edit_last',
		'_wp_attachment_metadata',
		'_wp_page_template',
		'_thumbnail_id',
		'_elementor_data',
		'_elementor_css',
		'_oembed_time',
	);

	/**
	 * @return string[]
	 */
	public static function post_types() {
		global $wpdb;
		$types = array_values( get_post_types( array(), 'names' ) );

		/**
		 * Also scan post types that exist in the database but are not registered right now
		 * (e.g. vc_grid_item while WPBakery is inactive, or types of a deactivated plugin).
		 *
		 * @param bool $include
		 */
		if ( apply_filters( 'ics_include_unregistered_post_types', true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$in_db = (array) $wpdb->get_col( "SELECT DISTINCT post_type FROM {$wpdb->posts}" );
			$types = array_merge( $types, $in_db );
		}

		$types = array_values( array_diff( array_unique( $types ), self::EXCLUDED_POST_TYPES ) );
		sort( $types );
		/**
		 * Filter post types to scan.
		 *
		 * @param string[] $types
		 */
		return array_values( array_unique( (array) apply_filters( 'ics_scan_post_types', $types ) ) );
	}

	/**
	 * @return string[]
	 */
	public static function post_statuses() {
		/**
		 * Filter post statuses to scan.
		 *
		 * @param string[] $statuses
		 */
		return array_values( (array) apply_filters( 'ics_scan_post_statuses', array( 'publish', 'future', 'draft', 'pending', 'private' ) ) );
	}

	private static function in_list( array $values ) {
		global $wpdb;
		if ( empty( $values ) ) {
			return "('')";
		}
		$escaped = array_map(
			static function ( $v ) use ( $wpdb ) {
				return "'" . esc_sql( $v ) . "'";
			},
			$values
		);
		return '(' . implode( ',', $escaped ) . ')';
	}

	/**
	 * Number of posts that will be scanned.
	 */
	public static function count_posts( array $types, array $statuses ) {
		global $wpdb;
		$types_in    = self::in_list( $types );
		$statuses_in = self::in_list( $statuses );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN {$types_in} AND post_status IN {$statuses_in}" );
	}

	/**
	 * Next batch of posts after a given ID (keyset pagination — constant cost per batch).
	 *
	 * @return array[] Rows with ID, post_type, post_status, post_title, post_content, post_excerpt.
	 */
	public static function next_posts( $after_id, $limit, array $types, array $statuses ) {
		global $wpdb;
		$types_in    = self::in_list( $types );
		$statuses_in = self::in_list( $statuses );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT ID, post_type, post_status, post_title, post_content, post_excerpt FROM {$wpdb->posts}
				WHERE ID > %d AND post_type IN {$types_in} AND post_status IN {$statuses_in}
				ORDER BY ID ASC LIMIT %d",
				(int) $after_id,
				(int) $limit
			),
			ARRAY_A
		);
	}

	/**
	 * Meta values of given posts that may contain shortcodes.
	 *
	 * Included: non-protected keys (no leading underscore), e.g. ACF fields, plus keys allowed by filter.
	 *
	 * @param int[] $post_ids
	 * @return array<int,array<string,string[]>> post_id => meta_key => strings
	 */
	public static function meta_texts( array $post_ids ) {
		global $wpdb;
		$post_ids = array_filter( array_map( 'intval', $post_ids ) );
		if ( empty( $post_ids ) ) {
			return array();
		}
		$ids_in = '(' . implode( ',', $post_ids ) . ')';

		/**
		 * Protected (underscore) meta keys that should be scanned anyway.
		 *
		 * @param string[] $keys
		 */
		$allowed_protected = (array) apply_filters( 'ics_scan_protected_meta_keys', array() );
		/**
		 * Meta keys that should never be scanned.
		 *
		 * @param string[] $keys
		 */
		$excluded = (array) apply_filters( 'ics_excluded_meta_keys', self::EXCLUDED_META_KEYS );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta}
				WHERE post_id IN {$ids_in} AND meta_value LIKE %s AND LENGTH(meta_value) <= %d
				ORDER BY post_id ASC, meta_id ASC",
				'%[%',
				self::MAX_VALUE_LENGTH
			),
			ARRAY_A
		);
		// phpcs:enable

		$out = array();
		foreach ( (array) $rows as $row ) {
			$key = (string) $row['meta_key'];
			if ( in_array( $key, $excluded, true ) || 0 === strpos( $key, '_oembed_' ) ) {
				continue;
			}
			if ( '_' === substr( $key, 0, 1 ) && ! in_array( $key, $allowed_protected, true ) ) {
				continue;
			}
			$texts = self::strings_from_value( $row['meta_value'] );
			if ( $texts ) {
				$pid = (int) $row['post_id'];
				if ( ! isset( $out[ $pid ][ $key ] ) ) {
					$out[ $pid ][ $key ] = array();
				}
				$out[ $pid ][ $key ] = array_merge( $out[ $pid ][ $key ], $texts );
			}
		}
		return $out;
	}

	/**
	 * Turn a stored value into the list of strings that contain "[".
	 * Serialized data is unserialized WITHOUT allowing classes (no object instantiation).
	 *
	 * @param mixed $value
	 * @return string[]
	 */
	public static function strings_from_value( $value ) {
		if ( is_string( $value ) && is_serialized( $value ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors
			$value = @unserialize( trim( $value ), array( 'allowed_classes' => false ) );
		}
		$out = array();
		self::collect_strings( $value, $out, 0 );
		return $out;
	}

	private static function collect_strings( $value, array &$out, $depth ) {
		if ( $depth > 20 ) {
			return;
		}
		if ( is_string( $value ) ) {
			if ( false !== strpos( $value, '[' ) && strlen( $value ) <= self::MAX_VALUE_LENGTH ) {
				$out[] = $value;
			}
			return;
		}
		if ( is_object( $value ) ) {
			$value = (array) $value; // __PHP_Incomplete_Class or stdClass: properties only.
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				self::collect_strings( $item, $out, $depth + 1 );
			}
		}
	}

	/**
	 * Widget instances that contain "[".
	 *
	 * @return array[] Items: object_key, title, status, texts.
	 */
	public static function widget_items() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$names = (array) $wpdb->get_col(
			$wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name ASC", $wpdb->esc_like( 'widget_' ) . '%' )
		);

		// widget id → sidebar id.
		$placement = array();
		$sidebars  = get_option( 'sidebars_widgets', array() );
		foreach ( (array) $sidebars as $sidebar_id => $widget_ids ) {
			if ( is_array( $widget_ids ) ) {
				foreach ( $widget_ids as $wid ) {
					$placement[ $wid ] = $sidebar_id;
				}
			}
		}

		$items = array();
		foreach ( $names as $option_name ) {
			$id_base   = substr( $option_name, strlen( 'widget_' ) );
			$instances = get_option( $option_name );
			if ( ! is_array( $instances ) ) {
				continue;
			}
			foreach ( $instances as $number => $instance ) {
				if ( ! is_numeric( $number ) ) {
					continue; // "_multiwidget" flag.
				}
				$texts = array();
				self::collect_strings( $instance, $texts, 0 );
				if ( ! $texts ) {
					continue;
				}
				$widget_id = $id_base . '-' . $number;
				$sidebar   = isset( $placement[ $widget_id ] ) ? $placement[ $widget_id ] : '';
				$status    = '' === $sidebar ? 'unassigned' : ( 'wp_inactive_widgets' === $sidebar ? 'inactive' : 'active' );
				$title     = is_array( $instance ) && ! empty( $instance['title'] ) ? wp_strip_all_tags( (string) $instance['title'] ) : '';

				$items[] = array(
					'object_key' => $widget_id,
					'title'      => trim( $title . ' [' . $widget_id . ( $sidebar ? ' @ ' . $sidebar : '' ) . ']' ),
					'status'     => $status,
					'texts'      => $texts,
				);
			}
		}
		return $items;
	}

	/**
	 * Options that may contain shortcode content.
	 *
	 * @return array[] Items: option_name, title, texts.
	 */
	public static function option_items() {
		/**
		 * Filter option names scanned for shortcodes.
		 * Default: WPBakery "My templates" (wpb_js_templates).
		 *
		 * @param array<string,string> $options option_name => label
		 */
		$options = (array) apply_filters(
			'ics_scan_options',
			array(
				'wpb_js_templates' => __( 'WPBakery saved templates ("My templates")', 'impreza-compatibility-scanner' ),
			)
		);

		$items = array();
		foreach ( $options as $option_name => $label ) {
			$value = get_option( $option_name, null );
			if ( null === $value ) {
				continue;
			}
			// For WPBakery templates, report every template separately.
			if ( 'wpb_js_templates' === $option_name && is_array( $value ) ) {
				foreach ( $value as $key => $template ) {
					if ( ! is_array( $template ) || empty( $template['template'] ) ) {
						continue;
					}
					$texts = self::strings_from_value( (string) $template['template'] );
					if ( $texts ) {
						$items[] = array(
							'option_name' => $option_name . ':' . $key,
							'title'       => $label . ': ' . ( isset( $template['name'] ) ? wp_strip_all_tags( (string) $template['name'] ) : $key ),
							'texts'       => $texts,
						);
					}
				}
				continue;
			}
			$texts = self::strings_from_value( $value );
			if ( $texts ) {
				$items[] = array(
					'option_name' => $option_name,
					'title'       => $label,
					'texts'       => $texts,
				);
			}
		}
		return $items;
	}
}
