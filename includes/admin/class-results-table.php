<?php
/**
 * Content-based results view (one row per content item + location + shortcode).
 *
 * @package ImprezaCompatibilityScanner
 */

namespace ImprezaCompatScanner\Admin;

use ImprezaCompatScanner\Results_Store;
use ImprezaCompatScanner\Verdict;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Results_Table extends \WP_List_Table {

	/** @var string */
	protected $scan_id;

	public function __construct( $scan_id ) {
		$this->scan_id = $scan_id;
		parent::__construct(
			array(
				'singular' => 'ics_result',
				'plural'   => 'ics_results',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Read & sanitize filters from the request (GET, read-only view).
	 */
	public static function filters() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$f = array();
		foreach ( array( 'tag', 'content_type', 'classification', 'content_status' ) as $key ) {
			$f[ $key ] = isset( $_GET[ 'ics_' . $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ 'ics_' . $key ] ) ) : '';
		}
		$f['search'] = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		// phpcs:enable
		if ( '' !== $f['classification'] && ! in_array( $f['classification'], Verdict::CLASSIFICATIONS, true ) ) {
			$f['classification'] = '';
		}
		return $f;
	}

	public function get_columns() {
		return array(
			'title'          => __( 'Content', 'impreza-compatibility-scanner' ),
			'content_type'   => __( 'Type', 'impreza-compatibility-scanner' ),
			'content_status' => __( 'Status', 'impreza-compatibility-scanner' ),
			'tag'            => __( 'Shortcode', 'impreza-compatibility-scanner' ),
			'occurrences'    => __( 'Count', 'impreza-compatibility-scanner' ),
			'classification' => __( 'Classification', 'impreza-compatibility-scanner' ),
			'reason'         => __( 'Explanation', 'impreza-compatibility-scanner' ),
			'location'       => __( 'Source location', 'impreza-compatibility-scanner' ),
		);
	}

	protected function get_sortable_columns() {
		return array(
			'title'          => array( 'title', false ),
			'content_type'   => array( 'content_type', false ),
			'content_status' => array( 'content_status', false ),
			'tag'            => array( 'tag', false ),
			'occurrences'    => array( 'occurrences', true ),
			'classification' => array( 'classification', false ),
			'location'       => array( 'location', false ),
		);
	}

	protected function get_primary_column_name() {
		return 'title';
	}

	public function prepare_items() {
		$per_page = $this->get_items_per_page( 'ics_per_page', 50 );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'title';
		$order   = isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'asc';
		// phpcs:enable

		$result = Results_Store::query_occurrences( $this->scan_id, self::filters(), $orderby, $order, $per_page, $this->get_pagenum() );

		$this->items           = $result['rows'];
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), $this->get_primary_column_name() );
		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $result['total'] / $per_page ),
			)
		);
	}

	public function no_items() {
		esc_html_e( 'No shortcodes match the current filters.', 'impreza-compatibility-scanner' );
	}

	/**
	 * Filter dropdowns.
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		$f = self::filters();
		echo '<div class="alignleft actions ics-filters">';
		$this->dropdown( 'tag', __( 'All shortcodes', 'impreza-compatibility-scanner' ), Results_Store::distinct_values( $this->scan_id, 'tag' ), $f['tag'] );
		$this->dropdown( 'content_type', __( 'All content types', 'impreza-compatibility-scanner' ), Results_Store::distinct_values( $this->scan_id, 'content_type' ), $f['content_type'] );
		$classes = array();
		foreach ( Verdict::CLASSIFICATIONS as $c ) {
			$classes[ $c ] = Verdict::label( $c );
		}
		$this->dropdown( 'classification', __( 'All classifications', 'impreza-compatibility-scanner' ), $classes, $f['classification'], true );
		$this->dropdown( 'content_status', __( 'All statuses', 'impreza-compatibility-scanner' ), Results_Store::distinct_values( $this->scan_id, 'content_status' ), $f['content_status'] );
		submit_button( __( 'Filter', 'impreza-compatibility-scanner' ), '', 'ics_filter', false );
		echo '</div>';
	}

	/**
	 * @param string $key
	 * @param string $all_label
	 * @param array  $values
	 * @param string $current
	 * @param bool   $assoc Values are value => label.
	 */
	protected function dropdown( $key, $all_label, array $values, $current, $assoc = false ) {
		printf( '<label class="screen-reader-text" for="ics-filter-%1$s">%2$s</label>', esc_attr( $key ), esc_html( $all_label ) );
		printf( '<select name="ics_%1$s" id="ics-filter-%1$s">', esc_attr( $key ) );
		printf( '<option value="">%s</option>', esc_html( $all_label ) );
		foreach ( $values as $value => $label ) {
			if ( ! $assoc ) {
				$value = $label;
			}
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $value ), selected( $current, $value, false ), esc_html( $label ) );
		}
		echo '</select>';
	}

	/**
	 * Links for a row (edit, Live Builder, view, widgets screen).
	 *
	 * @return array label => url
	 */
	public static function links_for_row( array $row ) {
		$links = array();
		if ( 'post' === $row['source_type'] && $row['object_id'] ) {
			$pid = (int) $row['object_id'];
			// Unregistered post types (e.g. vc_grid_item while WPBakery is off) have no edit screen.
			if ( ! post_type_exists( $row['content_type'] ) || ! current_user_can( 'edit_post', $pid ) ) {
				return $links;
			}
			$edit = get_edit_post_link( $pid, 'raw' );
			if ( ! $edit ) {
				$edit = admin_url( 'post.php?post=' . $pid . '&action=edit' );
			}
			$links[ __( 'Edit', 'impreza-compatibility-scanner' ) ] = $edit;

			$type_obj = get_post_type_object( $row['content_type'] );
			// Live Builder: same post types US Core allows by default (public types + Reusable Blocks + Page Templates).
			$live_types = array( 'us_page_block', 'us_content_template' );
			if ( function_exists( 'usb_get_edit_link' ) && ( $type_obj->public || in_array( $row['content_type'], $live_types, true ) ) ) {
				$links[ __( 'Live Builder', 'impreza-compatibility-scanner' ) ] = usb_get_edit_link( $pid );
			}
			if ( $type_obj->public && 'publish' === $row['content_status'] ) {
				$view = get_permalink( $pid );
				if ( $view ) {
					$links[ __( 'View', 'impreza-compatibility-scanner' ) ] = $view;
				}
			}
		} elseif ( 'widget' === $row['source_type'] ) {
			$links[ __( 'Widgets', 'impreza-compatibility-scanner' ) ] = admin_url( 'widgets.php' );
		}
		return $links;
	}

	public function column_title( $row ) {
		$links = self::links_for_row( $row );
		$title = '' !== $row['title'] ? $row['title'] : __( '(no title)', 'impreza-compatibility-scanner' );
		$first = reset( $links );
		$out   = $first
			? sprintf( '<strong><a href="%1$s">%2$s</a></strong>', esc_url( $first ), esc_html( $title ) )
			: '<strong>' . esc_html( $title ) . '</strong>';

		if ( 'post' === $row['source_type'] ) {
			$out .= ' <span class="description">#' . (int) $row['object_id'] . '</span>';
		}
		$actions = array();
		foreach ( $links as $label => $url ) {
			$actions[ sanitize_key( $label ) ] = sprintf( '<a href="%1$s">%2$s</a>', esc_url( $url ), esc_html( $label ) );
		}
		return $out . $this->row_actions( $actions );
	}

	public function column_content_type( $row ) {
		$obj = get_post_type_object( $row['content_type'] );
		$lbl = $obj ? $obj->labels->singular_name : $row['content_type'];
		return esc_html( $lbl ) . '<br><code class="ics-small">' . esc_html( $row['content_type'] ) . '</code>';
	}

	public function column_content_status( $row ) {
		$obj = get_post_status_object( $row['content_status'] );
		return esc_html( $obj ? $obj->label : $row['content_status'] );
	}

	public function column_tag( $row ) {
		$url = add_query_arg(
			array(
				'page'    => Admin_Page::SLUG,
				'view'    => 'shortcodes',
				'ics_tag' => $row['tag'],
			),
			admin_url( 'tools.php' )
		);
		return sprintf( '<a href="%1$s"><code>%2$s</code></a>', esc_url( $url ), esc_html( $row['tag'] ) );
	}

	public function column_occurrences( $row ) {
		return (int) $row['occurrences'];
	}

	public function column_classification( $row ) {
		return Admin_Page::badge( $row['classification'] );
	}

	public function column_reason( $row ) {
		$out = '<p class="ics-reason">' . esc_html( (string) $row['reason'] ) . '</p>';
		$details = array();
		if ( '' !== $row['parents'] ) {
			$details[] = sprintf(
				/* translators: %s: list of tags */
				esc_html__( 'Nested inside: %s', 'impreza-compatibility-scanner' ),
				'<code>' . esc_html( str_replace( ',', ', ', $row['parents'] ) ) . '</code>'
			);
		}
		if ( '' !== $row['notes'] ) {
			$details[] = sprintf(
				/* translators: %s: notes */
				esc_html__( 'Notes: %s', 'impreza-compatibility-scanner' ),
				'<code>' . esc_html( str_replace( ',', ', ', $row['notes'] ) ) . '</code>'
			);
		}
		if ( '' !== $row['sample'] ) {
			$details[] = sprintf(
				/* translators: %s: shortcode sample */
				esc_html__( 'Example: %s', 'impreza-compatibility-scanner' ),
				'<code class="ics-sample">' . esc_html( $row['sample'] ) . '</code>'
			);
		}
		if ( $details ) {
			$out .= '<div class="ics-details">' . implode( '<br>', $details ) . '</div>';
		}
		return $out;
	}

	public function column_location( $row ) {
		return '<code class="ics-small">' . esc_html( $row['location'] ) . '</code>';
	}

	public function column_default( $row, $column_name ) {
		return isset( $row[ $column_name ] ) ? esc_html( (string) $row[ $column_name ] ) : '';
	}
}
