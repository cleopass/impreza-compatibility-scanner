<?php
/**
 * Bootstrap: hooks, capability checks, AJAX endpoints.
 *
 * @package ImprezaCompatibilityScanner
 */

namespace ImprezaCompatScanner;

defined( 'ABSPATH' ) || exit;

class Plugin {

	const AJAX_NONCE = 'ics_admin';

	public static function boot() {
		load_plugin_textdomain( 'impreza-compatibility-scanner', false, dirname( plugin_basename( ICS_FILE ) ) . '/languages' );

		// Front-end registry probe (only reacts to its own nonce-protected query var).
		add_action( 'template_redirect', array( Registry::class, 'maybe_handle_probe' ), 9999 );

		if ( is_admin() ) {
			Results_Store::maybe_upgrade();
			Admin\Admin_Page::register();

			add_action( 'wp_ajax_ics_start', array( __CLASS__, 'ajax_start' ) );
			add_action( 'wp_ajax_ics_batch', array( __CLASS__, 'ajax_batch' ) );
			add_action( 'wp_ajax_ics_cancel', array( __CLASS__, 'ajax_cancel' ) );
			add_action( 'wp_ajax_ics_status', array( __CLASS__, 'ajax_status' ) );
			add_action( 'admin_post_ics_export_csv', array( Admin\Csv_Exporter::class, 'handle' ) );
		}
	}

	/**
	 * Capability required to run scans and export reports.
	 */
	public static function capability() {
		/**
		 * Filter the capability required to use the scanner. Default: manage_options.
		 *
		 * @param string $capability
		 */
		return (string) apply_filters( 'ics_required_capability', 'manage_options' );
	}

	public static function current_user_can_scan() {
		return current_user_can( self::capability() );
	}

	/**
	 * Shared AJAX guard.
	 */
	private static function guard() {
		if ( ! check_ajax_referer( self::AJAX_NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed. Reload the page.', 'impreza-compatibility-scanner' ) ), 403 );
		}
		if ( ! self::current_user_can_scan() ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to run the scanner.', 'impreza-compatibility-scanner' ) ), 403 );
		}
	}

	/**
	 * Data sent to the browser about progress.
	 */
	public static function progress_payload( $state ) {
		if ( ! $state ) {
			return array( 'status' => 'none' );
		}
		$total     = max( 0, (int) $state['total_posts'] );
		$processed = (int) $state['processed_posts'];
		$phases    = array(
			Scan_Manager::PHASE_POSTS   => __( 'Scanning posts, pages and templates', 'impreza-compatibility-scanner' ),
			Scan_Manager::PHASE_WIDGETS => __( 'Scanning widgets', 'impreza-compatibility-scanner' ),
			Scan_Manager::PHASE_OPTIONS => __( 'Scanning saved templates', 'impreza-compatibility-scanner' ),
			Scan_Manager::PHASE_DONE    => __( 'Done', 'impreza-compatibility-scanner' ),
		);
		$percent = Scan_Manager::PHASE_POSTS === $state['phase']
			? ( $total ? min( 95, (int) floor( $processed / $total * 95 ) ) : 95 )
			: ( Scan_Manager::PHASE_DONE === $state['phase'] ? 100 : 97 );

		return array(
			'status'    => $state['status'],
			'phase'     => $state['phase'],
			'phaseText' => isset( $phases[ $state['phase'] ] ) ? $phases[ $state['phase'] ] : $state['phase'],
			'processed' => $processed,
			'total'     => $total,
			'percent'   => $percent,
			'warnings'  => count( $state['warnings'] ),
		);
	}

	public static function ajax_start() {
		self::guard();
		$state = Scan_Manager::start();
		wp_send_json_success( self::progress_payload( $state ) );
	}

	public static function ajax_batch() {
		self::guard();
		// Keep long batches alive where the host allows it; the time budget keeps us well below limits.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 60 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$result = Scan_Manager::run_batch();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				),
				'ics_locked' === $result->get_error_code() ? 409 : 400
			);
		}
		wp_send_json_success( self::progress_payload( $result ) );
	}

	public static function ajax_cancel() {
		self::guard();
		wp_send_json_success( self::progress_payload( Scan_Manager::cancel() ) );
	}

	public static function ajax_status() {
		self::guard();
		wp_send_json_success( self::progress_payload( Scan_Manager::state() ) );
	}
}
