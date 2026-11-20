<?php
/**
 * CSV export of the complete result set (admin-post.php?action=ics_export_csv).
 *
 * - UTF-8 with BOM (Excel opens it with the right encoding), RFC 4180 quoting via fputcsv().
 * - Formula-injection protection: cells beginning with = + - @ TAB CR are prefixed with an apostrophe.
 * - Capability + nonce checked; rows are streamed in chunks (no full result set in memory).
 *
 * @package ImprezaCompatibilityScanner
 */

namespace ImprezaCompatScanner\Admin;

use ImprezaCompatScanner\Plugin;
use ImprezaCompatScanner\Results_Store;
use ImprezaCompatScanner\Scan_Manager;
use ImprezaCompatScanner\Verdict;

defined( 'ABSPATH' ) || exit;

class Csv_Exporter {

	const NONCE = 'ics_export_csv';

	public static function handle() {
		if ( ! Plugin::current_user_can_scan() ) {
			wp_die( esc_html__( 'You are not allowed to export this report.', 'impreza-compatibility-scanner' ), 403 );
		}
		check_admin_referer( self::NONCE );

		$last = Scan_Manager::last_scan();
		if ( ! $last ) {
			wp_die( esc_html__( 'There is no completed scan to export.', 'impreza-compatibility-scanner' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked above.
		$type     = isset( $_GET['type'] ) && 'summary' === $_GET['type'] ? 'summary' : 'occurrences';
		$filename = sprintf( 'impreza-compatibility-%s-%s.csv', $type, wp_date( 'Y-m-d-His', (int) $last['finished_at'] ) );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'X-Content-Type-Options: nosniff' );

		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // UTF-8 BOM.

		if ( 'summary' === $type ) {
			self::write_summary( $out, $last['scan_id'] );
		} else {
			self::write_occurrences( $out, $last['scan_id'] );
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	private static function write_occurrences( $out, $scan_id ) {
		self::put(
			$out,
			array( 'Content title', 'Content ID', 'Content type', 'Content status', 'Source type', 'Source location', 'Shortcode', 'Occurrences', 'Classification', 'Explanation', 'Renders without WPBakery', 'Native Live Builder element', 'Handler', 'Rule', 'Evidence', 'Nested inside', 'Notes', 'Example', 'Edit URL' )
		);

		Results_Store::each_occurrence(
			$scan_id,
			static function ( array $row ) use ( $out ) {
				$links    = Results_Table::links_for_row( $row );
				$evidence = json_decode( (string) $row['evidence'], true );
				self::put(
					$out,
					array(
						$row['title'],
						'post' === $row['source_type'] ? (int) $row['object_id'] : '',
						$row['content_type'],
						$row['content_status'],
						$row['source_type'],
						$row['location'],
						$row['tag'],
						(int) $row['occurrences'],
						Verdict::label( $row['classification'] ),
						$row['reason'],
						Verdict::fact_label( $row['renders_without_wpb'] ),
						Verdict::fact_label( $row['lb_editable'] ),
						$row['handler'],
						$row['rule_id'],
						is_array( $evidence ) ? implode( ' | ', $evidence ) : '',
						$row['parents'],
						$row['notes'],
						$row['sample'],
						$links ? reset( $links ) : '',
					)
				);
			}
		);
	}

	private static function write_summary( $out, $scan_id ) {
		self::put(
			$out,
			array( 'Shortcode', 'Classification', 'Explanation', 'Renders without WPBakery', 'Native Live Builder element', 'Content items', 'Occurrences', 'Handler', 'Rule', 'Evidence' )
		);
		$page = 1;
		do {
			$result = Results_Store::query_summary( $scan_id, array(), 'tag', 'ASC', 500, $page );
			foreach ( $result['rows'] as $row ) {
				$evidence = json_decode( (string) $row['evidence'], true );
				self::put(
					$out,
					array(
						$row['tag'],
						Verdict::label( $row['classification'] ),
						$row['reason'],
						Verdict::fact_label( $row['renders_without_wpb'] ),
						Verdict::fact_label( $row['lb_editable'] ),
						(int) $row['items'],
						(int) $row['occurrences'],
						$row['handler'],
						$row['rule_id'],
						is_array( $evidence ) ? implode( ' | ', $evidence ) : '',
					)
				);
			}
			$page++;
		} while ( count( $result['rows'] ) === 500 );
	}

	/**
	 * Write one CSV line with formula-injection protection.
	 */
	private static function put( $out, array $cells ) {
		fputcsv( $out, array_map( array( __CLASS__, 'safe_cell' ), $cells ), ',', '"', '' );
	}

	/**
	 * Neutralise spreadsheet formulas (OWASP CSV injection guidance).
	 *
	 * @param mixed $value
	 * @return string|int
	 */
	public static function safe_cell( $value ) {
		if ( is_int( $value ) ) {
			return $value;
		}
		$value = (string) $value;
		// Normalise line breaks inside cells.
		$value = str_replace( array( "\r\n", "\r" ), "\n", $value );
		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			$value = "'" . $value;
		}
		return $value;
	}
}
