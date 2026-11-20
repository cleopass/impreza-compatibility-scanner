<?php
/**
 * Shortcode-based summary view (one row per shortcode tag).
 *
 * @package ImprezaCompatibilityScanner
 */

namespace ImprezaCompatScanner\Admin;

use ImprezaCompatScanner\Results_Store;
use ImprezaCompatScanner\Verdict;

defined( 'ABSPATH' ) || exit;

class Summary_Table extends Results_Table {

	public function __construct( $scan_id ) {
		parent::__construct( $scan_id );
		$this->_args['singular'] = 'ics_shortcode';
		$this->_args['plural']   = 'ics_shortcodes';
	}

	public function get_columns() {
		return array(
			'tag'                 => __( 'Shortcode', 'impreza-compatibility-scanner' ),
			'classification'      => __( 'Classification', 'impreza-compatibility-scanner' ),
			'renders_without_wpb' => __( 'Renders without WPBakery', 'impreza-compatibility-scanner' ),
			'lb_editable'         => __( 'Native Live Builder element', 'impreza-compatibility-scanner' ),
			'items'               => __( 'Content items', 'impreza-compatibility-scanner' ),
			'occurrences'         => __( 'Occurrences', 'impreza-compatibility-scanner' ),
			'reason'              => __( 'Explanation and evidence', 'impreza-compatibility-scanner' ),
		);
	}

	protected function get_sortable_columns() {
		return array(
			'tag'            => array( 'tag', false ),
			'classification' => array( 'classification', false ),
			'items'          => array( 'items', true ),
			'occurrences'    => array( 'occurrences', true ),
		);
	}

	protected function get_primary_column_name() {
		return 'tag';
	}

	public function prepare_items() {
		$per_page = $this->get_items_per_page( 'ics_per_page', 50 );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'occurrences';
		$order   = isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'desc';
		// phpcs:enable

		$result = Results_Store::query_summary( $this->scan_id, self::filters(), $orderby, $order, $per_page, $this->get_pagenum() );

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

	public function column_tag( $row ) {
		$url = add_query_arg(
			array(
				'page'    => Admin_Page::SLUG,
				'view'    => 'content',
				'ics_tag' => $row['tag'],
			),
			admin_url( 'tools.php' )
		);
		$out  = sprintf( '<strong><code>%s</code></strong>', esc_html( $row['tag'] ) );
		$out .= $this->row_actions( array( 'content' => sprintf( '<a href="%1$s">%2$s</a>', esc_url( $url ), esc_html__( 'Show content', 'impreza-compatibility-scanner' ) ) ) );
		return $out;
	}

	public function column_renders_without_wpb( $row ) {
		return esc_html( Verdict::fact_label( $row['renders_without_wpb'] ) );
	}

	public function column_lb_editable( $row ) {
		return esc_html( Verdict::fact_label( $row['lb_editable'] ) );
	}

	public function column_items( $row ) {
		return (int) $row['items'];
	}

	public function column_reason( $row ) {
		$out      = '<p class="ics-reason">' . esc_html( (string) $row['reason'] ) . '</p>';
		$evidence = json_decode( (string) $row['evidence'], true );
		if ( is_array( $evidence ) && $evidence ) {
			$out .= '<details class="ics-evidence"><summary>' . esc_html__( 'Evidence', 'impreza-compatibility-scanner' ) . '</summary><ul>';
			foreach ( $evidence as $line ) {
				$out .= '<li>' . esc_html( (string) $line ) . '</li>';
			}
			$out .= '</ul>';
			$out .= '<p class="description">' . esc_html(
				sprintf(
					/* translators: %s: rule id */
					__( 'Decided by rule: %s', 'impreza-compatibility-scanner' ),
					$row['rule_id']
				)
			) . '</p></details>';
		}
		return $out;
	}
}
