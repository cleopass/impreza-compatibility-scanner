<?php
/**
 * Results storage: two plugin-owned tables.
 *
 *  {prefix}ics_occurrences — one row per (content item, location, shortcode tag).
 *  {prefix}ics_tags        — one row per (scan, shortcode tag) with the classification verdict.
 *
 * The plugin never writes to any WordPress core table.
 *
 * @package ImprezaCompatibilityScanner
 */

namespace ImprezaCompatScanner;

defined( 'ABSPATH' ) || exit;

class Results_Store {

	/**
	 * Columns that may be used for ORDER BY in the content view.
	 */
	const SORTABLE_OCCURRENCE_COLUMNS = array( 'title', 'content_type', 'content_status', 'tag', 'occurrences', 'classification', 'location', 'object_id' );

	/**
	 * Columns that may be used for ORDER BY in the shortcode summary view.
	 */
	const SORTABLE_SUMMARY_COLUMNS = array( 'tag', 'classification', 'items', 'occurrences' );

	public static function occurrences_table() {
		global $wpdb;
		return $wpdb->prefix . 'ics_occurrences';
	}

	public static function tags_table() {
		global $wpdb;
		return $wpdb->prefix . 'ics_tags';
	}

	/**
	 * Create / upgrade tables. Runs on activation and when ICS_DB_VERSION changes.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$occ     = self::occurrences_table();
		$tags    = self::tags_table();

		// Note: dbDelta() requires two spaces after PRIMARY KEY and one field per line.
		$sql_occ = "CREATE TABLE {$occ} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			scan_id varchar(32) NOT NULL,
			source_type varchar(20) NOT NULL,
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			location varchar(191) NOT NULL,
			title text NOT NULL,
			content_type varchar(64) NOT NULL,
			content_status varchar(32) NOT NULL,
			tag varchar(191) NOT NULL,
			classification varchar(20) NOT NULL,
			occurrences int(10) unsigned NOT NULL DEFAULT 0,
			parents varchar(255) NOT NULL DEFAULT '',
			notes varchar(255) NOT NULL DEFAULT '',
			sample text NOT NULL,
			PRIMARY KEY  (id),
			KEY scan_tag (scan_id, tag),
			KEY scan_object (scan_id, source_type, object_id),
			KEY scan_class (scan_id, classification),
			KEY scan_type (scan_id, content_type)
		) {$charset};";

		$sql_tags = "CREATE TABLE {$tags} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			scan_id varchar(32) NOT NULL,
			tag varchar(191) NOT NULL,
			classification varchar(20) NOT NULL,
			rule_id varchar(64) NOT NULL DEFAULT '',
			reason text NOT NULL,
			evidence longtext NOT NULL,
			renders_without_wpb varchar(10) NOT NULL DEFAULT 'unknown',
			lb_editable varchar(10) NOT NULL DEFAULT 'unknown',
			handler varchar(255) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY scan_tag (scan_id, tag)
		) {$charset};";

		dbDelta( $sql_occ );
		dbDelta( $sql_tags );

		update_option( 'ics_db_version', ICS_DB_VERSION, false );
	}

	/**
	 * Install tables if the stored DB version differs (e.g. plugin updated without re-activation).
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'ics_db_version' ) !== ICS_DB_VERSION ) {
			self::install();
		}
	}

	/* ---------------------------------------------------------------------
	 * Writing (only plugin tables)
	 * ------------------------------------------------------------------ */

	/**
	 * Insert occurrence rows in one multi-row INSERT.
	 *
	 * @param array[] $rows Each row: keys matching the table columns (without id).
	 */
	public static function insert_occurrences( array $rows ) {
		global $wpdb;
		if ( empty( $rows ) ) {
			return;
		}
		$table        = self::occurrences_table();
		$columns      = array( 'scan_id', 'source_type', 'object_id', 'location', 'title', 'content_type', 'content_status', 'tag', 'classification', 'occurrences', 'parents', 'notes', 'sample' );
		$formats      = array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' );
		$placeholders = array();
		$values       = array();

		foreach ( $rows as $row ) {
			$placeholders[] = '(' . implode( ',', $formats ) . ')';
			foreach ( $columns as $col ) {
				$values[] = isset( $row[ $col ] ) ? $row[ $col ] : '';
			}
		}

		// Chunk to keep packets small on hosts with a low max_allowed_packet.
		$chunk_size = 100;
		$total      = count( $rows );
		$per_row    = count( $columns );
		for ( $offset = 0; $offset < $total; $offset += $chunk_size ) {
			$ph  = array_slice( $placeholders, $offset, $chunk_size );
			$val = array_slice( $values, $offset * $per_row, count( $ph ) * $per_row );
			$sql = "INSERT INTO {$table} (" . implode( ',', $columns ) . ') VALUES ' . implode( ',', $ph );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->query( $wpdb->prepare( $sql, $val ) );
		}
	}

	/**
	 * Save (insert or replace) a tag verdict for a scan.
	 */
	public static function save_tag_verdict( $scan_id, $tag, Verdict $verdict ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->replace(
			self::tags_table(),
			array(
				'scan_id'             => $scan_id,
				'tag'                 => $tag,
				'classification'      => $verdict->classification,
				'rule_id'             => $verdict->rule_id,
				'reason'              => $verdict->reason,
				'evidence'            => wp_json_encode( array_values( $verdict->evidence ) ),
				'renders_without_wpb' => $verdict->renders_without_wpb,
				'lb_editable'         => $verdict->lb_editable,
				'handler'             => substr( (string) $verdict->handler, 0, 255 ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Load all verdicts of a scan, keyed by tag.
	 *
	 * @return Verdict[]
	 */
	public static function get_tag_verdicts( $scan_id ) {
		global $wpdb;
		$table = self::tags_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE scan_id = %s", $scan_id ), ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ $row['tag'] ] = Verdict::from_row( $row );
		}
		return $out;
	}

	/**
	 * Remove post rows above a cursor (makes a resumed batch idempotent).
	 */
	public static function delete_posts_after( $scan_id, $cursor_id ) {
		global $wpdb;
		$table = self::occurrences_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE scan_id = %s AND source_type = 'post' AND object_id > %d", $scan_id, $cursor_id ) );
	}

	/**
	 * Remove rows of one non-post source type (widgets / options) for a scan.
	 */
	public static function delete_source( $scan_id, $source_type ) {
		global $wpdb;
		$table = self::occurrences_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE scan_id = %s AND source_type = %s", $scan_id, $source_type ) );
	}

	/**
	 * Delete everything belonging to a scan.
	 */
	public static function delete_scan( $scan_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( self::occurrences_table(), array( 'scan_id' => $scan_id ), array( '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( self::tags_table(), array( 'scan_id' => $scan_id ), array( '%s' ) );
	}

	/**
	 * Delete all scans except the given ones.
	 *
	 * @param string[] $keep Scan IDs to keep.
	 */
	public static function delete_other_scans( array $keep ) {
		global $wpdb;
		$keep = array_values( array_filter( $keep ) );
		foreach ( array( self::occurrences_table(), self::tags_table() ) as $table ) {
			if ( empty( $keep ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "DELETE FROM {$table}" );
				continue;
			}
			$in = implode( ',', array_fill( 0, count( $keep ), '%s' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE scan_id NOT IN ({$in})", $keep ) );
		}
	}

	/* ---------------------------------------------------------------------
	 * Reading
	 * ------------------------------------------------------------------ */

	/**
	 * Aggregate counts for the dashboard.
	 *
	 * @return array
	 */
	public static function get_totals( $scan_id ) {
		global $wpdb;
		$table = self::occurrences_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$by_class = $wpdb->get_results(
			$wpdb->prepare( "SELECT classification, SUM(occurrences) AS occ, COUNT(DISTINCT tag) AS tags FROM {$table} WHERE scan_id = %s GROUP BY classification", $scan_id ),
			ARRAY_A
		);
		$overall  = $wpdb->get_row(
			$wpdb->prepare( "SELECT COALESCE(SUM(occurrences),0) AS occ, COUNT(DISTINCT tag) AS tags, COUNT(DISTINCT source_type, object_id, CASE WHEN source_type = 'post' THEN '' ELSE location END) AS items FROM {$table} WHERE scan_id = %s", $scan_id ),
			ARRAY_A
		);
		$legacy   = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COALESCE(SUM(occurrences),0) FROM {$table} WHERE scan_id = %s AND notes LIKE %s", $scan_id, '%legacy_wpb_design_css%' )
		);
		// phpcs:enable

		$totals = array(
			'occurrences'        => (int) $overall['occ'],
			'unique_tags'        => (int) $overall['tags'],
			'items_with_codes'   => (int) $overall['items'],
			'legacy_design_css'  => $legacy,
			'by_classification'  => array(),
		);
		foreach ( Verdict::CLASSIFICATIONS as $c ) {
			$totals['by_classification'][ $c ] = array( 'occurrences' => 0, 'tags' => 0 );
		}
		foreach ( (array) $by_class as $row ) {
			$totals['by_classification'][ $row['classification'] ] = array(
				'occurrences' => (int) $row['occ'],
				'tags'        => (int) $row['tags'],
			);
		}
		return $totals;
	}

	/**
	 * Build the WHERE clause from filters.
	 *
	 * @param string $scan_id
	 * @param array  $filters tag, content_type, classification, content_status, search.
	 * @return array{0:string,1:array}
	 */
	private static function where( $scan_id, array $filters ) {
		global $wpdb;
		$where  = array( 'o.scan_id = %s' );
		$params = array( $scan_id );

		foreach ( array( 'tag', 'content_type', 'classification', 'content_status' ) as $key ) {
			if ( isset( $filters[ $key ] ) && '' !== $filters[ $key ] ) {
				$where[]  = "o.{$key} = %s";
				$params[] = $filters[ $key ];
			}
		}
		if ( ! empty( $filters['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
			$where[]  = '(o.title LIKE %s OR o.tag LIKE %s OR o.location LIKE %s OR o.notes LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}
		return array( implode( ' AND ', $where ), $params );
	}

	/**
	 * Paginated content view rows (joined with the tag verdict for the explanation).
	 */
	public static function query_occurrences( $scan_id, array $filters, $orderby, $order, $per_page, $page ) {
		global $wpdb;
		$occ  = self::occurrences_table();
		$tags = self::tags_table();

		list( $where, $params ) = self::where( $scan_id, $filters );

		$orderby = in_array( $orderby, self::SORTABLE_OCCURRENCE_COLUMNS, true ) ? $orderby : 'title';
		$order   = 'DESC' === strtoupper( (string) $order ) ? 'DESC' : 'ASC';
		$offset  = max( 0, ( (int) $page - 1 ) * (int) $per_page );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$occ} o WHERE {$where}", $params ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT o.*, t.reason, t.renders_without_wpb, t.lb_editable FROM {$occ} o
				LEFT JOIN {$tags} t ON t.scan_id = o.scan_id AND t.tag = o.tag
				WHERE {$where} ORDER BY o.{$orderby} {$order}, o.id ASC LIMIT %d OFFSET %d",
				array_merge( $params, array( (int) $per_page, $offset ) )
			),
			ARRAY_A
		);
		// phpcs:enable

		return array( 'total' => $total, 'rows' => (array) $rows );
	}

	/**
	 * Paginated shortcode summary rows.
	 */
	public static function query_summary( $scan_id, array $filters, $orderby, $order, $per_page, $page ) {
		global $wpdb;
		$occ  = self::occurrences_table();
		$tags = self::tags_table();

		list( $where, $params ) = self::where( $scan_id, $filters );

		$orderby = in_array( $orderby, self::SORTABLE_SUMMARY_COLUMNS, true ) ? $orderby : 'occurrences';
		$order   = 'ASC' === strtoupper( (string) $order ) ? 'ASC' : 'DESC';
		$offset  = max( 0, ( (int) $page - 1 ) * (int) $per_page );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT o.tag) FROM {$occ} o WHERE {$where}", $params ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.*, t.reason, t.evidence, t.renders_without_wpb, t.lb_editable, t.handler, t.rule_id FROM (
					SELECT o.tag, MAX(o.classification) AS classification,
						COUNT(DISTINCT o.source_type, o.object_id, CASE WHEN o.source_type = 'post' THEN '' ELSE o.location END) AS items,
						SUM(o.occurrences) AS occurrences
					FROM {$occ} o WHERE {$where} GROUP BY o.tag
				) s
				LEFT JOIN {$tags} t ON t.scan_id = %s AND t.tag = s.tag
				ORDER BY s.{$orderby} {$order}, s.tag ASC LIMIT %d OFFSET %d",
				array_merge( $params, array( $scan_id, (int) $per_page, $offset ) )
			),
			ARRAY_A
		);
		// phpcs:enable

		return array( 'total' => $total, 'rows' => (array) $rows );
	}

	/**
	 * Distinct values for filter dropdowns.
	 *
	 * @return string[]
	 */
	public static function distinct_values( $scan_id, $column ) {
		global $wpdb;
		if ( ! in_array( $column, array( 'tag', 'content_type', 'content_status', 'classification' ), true ) ) {
			return array();
		}
		$table = self::occurrences_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT {$column} FROM {$table} WHERE scan_id = %s ORDER BY {$column} ASC", $scan_id ) );
	}

	/**
	 * Iterate over all occurrences of a scan in id order, in chunks (for CSV export).
	 *
	 * @param string   $scan_id
	 * @param callable $callback Receives one row (array) at a time.
	 * @param int      $chunk
	 */
	public static function each_occurrence( $scan_id, callable $callback, $chunk = 500 ) {
		global $wpdb;
		$occ     = self::occurrences_table();
		$tags    = self::tags_table();
		$last_id = 0;
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT o.*, t.reason, t.renders_without_wpb, t.lb_editable, t.handler, t.rule_id, t.evidence FROM {$occ} o
					LEFT JOIN {$tags} t ON t.scan_id = o.scan_id AND t.tag = o.tag
					WHERE o.scan_id = %s AND o.id > %d ORDER BY o.id ASC LIMIT %d",
					$scan_id,
					$last_id,
					$chunk
				),
				ARRAY_A
			);
			foreach ( (array) $rows as $row ) {
				$last_id = (int) $row['id'];
				$callback( $row );
			}
		} while ( ! empty( $rows ) && count( $rows ) === $chunk );
	}

	/**
	 * Drop the plugin tables (uninstall only).
	 */
	public static function drop_tables() {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::occurrences_table() );
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::tags_table() );
		// phpcs:enable
	}
}
