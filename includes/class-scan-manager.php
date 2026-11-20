<?php
/**
 * Scan orchestration: start, batched processing, cancel, resume, finalize.
 *
 * State lives in the non-autoloaded option "ics_scan_state". A batch is driven by an AJAX request
 * from the admin page and is bounded by both a post count and a time budget, so no single request
 * runs long enough to hit PHP time limits. Every batch first deletes rows above its cursor, which
 * makes a batch that was interrupted half-way safe to repeat (resume).
 *
 * @package ImprezaCompatibilityScanner
 */

namespace ImprezaCompatScanner;

defined( 'ABSPATH' ) || exit;

class Scan_Manager {

	const STATE_OPTION = 'ics_scan_state';
	const LAST_OPTION  = 'ics_last_scan';
	const LOCK_OPTION  = 'ics_batch_lock';

	const STATUS_RUNNING   = 'running';
	const STATUS_COMPLETED = 'completed';
	const STATUS_CANCELLED = 'cancelled';

	const PHASE_POSTS   = 'posts';
	const PHASE_WIDGETS = 'widgets';
	const PHASE_OPTIONS = 'options';
	const PHASE_DONE    = 'done';

	const MAX_WARNINGS = 50;

	/**
	 * Current scan state (running, cancelled or completed) or null.
	 */
	public static function state() {
		$state = get_option( self::STATE_OPTION );
		return is_array( $state ) ? $state : null;
	}

	/**
	 * Summary of the last completed scan or null.
	 */
	public static function last_scan() {
		$last = get_option( self::LAST_OPTION );
		return is_array( $last ) ? $last : null;
	}

	public static function is_running() {
		$state = self::state();
		return $state && self::STATUS_RUNNING === $state['status'];
	}

	/**
	 * Start a new scan (any running scan is discarded first).
	 *
	 * @return array New state.
	 */
	public static function start() {
		$current = self::state();
		if ( $current && self::STATUS_RUNNING === $current['status'] ) {
			Results_Store::delete_scan( $current['scan_id'] );
		}

		$types    = Content_Discovery::post_types();
		$statuses = Content_Discovery::post_statuses();
		$registry = Registry::merged( HOUR_IN_SECONDS );

		$state = array(
			'scan_id'           => strtolower( wp_generate_password( 16, false, false ) ),
			'status'            => self::STATUS_RUNNING,
			'phase'             => self::PHASE_POSTS,
			'cursor'            => 0,
			'post_types'        => $types,
			'post_statuses'     => $statuses,
			'total_posts'       => Content_Discovery::count_posts( $types, $statuses ),
			'processed_posts'   => 0,
			'processed_widgets' => 0,
			'processed_options' => 0,
			'started_at'        => time(),
			'updated_at'        => time(),
			'finished_at'       => 0,
			'user_id'           => get_current_user_id(),
			'frontend_registry' => $registry['frontend'],
			'frontend_at'       => $registry['frontend_at'],
			'environment'       => Environment::summary(),
			'warnings'          => array(),
		);
		update_option( self::STATE_OPTION, $state, false );
		return $state;
	}

	/**
	 * Cancel the running scan and discard its partial results. The last completed scan stays.
	 */
	public static function cancel() {
		$state = self::state();
		if ( ! $state || self::STATUS_RUNNING !== $state['status'] ) {
			return $state;
		}
		Results_Store::delete_scan( $state['scan_id'] );
		$state['status']     = self::STATUS_CANCELLED;
		$state['updated_at'] = time();
		update_option( self::STATE_OPTION, $state, false );
		return $state;
	}

	/**
	 * Process one batch.
	 *
	 * @return array|\WP_Error Updated state.
	 */
	public static function run_batch() {
		$state = self::state();
		if ( ! $state || self::STATUS_RUNNING !== $state['status'] ) {
			return new \WP_Error( 'ics_not_running', __( 'No scan is running.', 'impreza-compatibility-scanner' ) );
		}
		if ( ! self::acquire_lock() ) {
			return new \WP_Error( 'ics_locked', __( 'Another batch is still being processed. Retrying…', 'impreza-compatibility-scanner' ) );
		}

		try {
			/** Seconds of work per request. */
			$time_budget = (float) apply_filters( 'ics_batch_time_budget', 8 );
			/** Posts per database query. */
			$chunk_size = max( 1, (int) apply_filters( 'ics_batch_size', 25 ) );

			$start      = microtime( true );
			$registry   = Registry::merged( $state['frontend_registry'] ? 0 : HOUR_IN_SECONDS );
			if ( $state['frontend_registry'] && ! $registry['frontend'] ) {
				self::warn( $state, __( 'The front-end shortcode registry disappeared during the scan; remaining items were classified with the wp-admin registry only.', 'impreza-compatibility-scanner' ) );
			}
			$classifier = new Classifier( $registry );
			$extractor  = new Shortcode_Extractor( array( $classifier, 'is_known' ) );
			$seen_tags  = array();

			if ( self::PHASE_POSTS === $state['phase'] ) {
				// Idempotency: remove anything a previous, interrupted batch wrote above the cursor.
				Results_Store::delete_posts_after( $state['scan_id'], $state['cursor'] );

				do {
					$posts = Content_Discovery::next_posts( $state['cursor'], $chunk_size, $state['post_types'], $state['post_statuses'] );
					if ( empty( $posts ) ) {
						$state['phase'] = self::PHASE_WIDGETS;
						break;
					}
					$meta = Content_Discovery::meta_texts( wp_list_pluck( $posts, 'ID' ) );
					$rows = array();
					foreach ( $posts as $post ) {
						$pid  = (int) $post['ID'];
						$base = array(
							'scan_id'        => $state['scan_id'],
							'source_type'    => 'post',
							'object_id'      => $pid,
							'title'          => '' !== $post['post_title'] ? $post['post_title'] : sprintf( '#%d', $pid ),
							'content_type'   => $post['post_type'],
							'content_status' => $post['post_status'],
						);

						$sources = array(
							'post_content' => array( $post['post_content'] ),
							'post_excerpt' => array( $post['post_excerpt'] ),
						);
						if ( isset( $meta[ $pid ] ) ) {
							foreach ( $meta[ $pid ] as $key => $texts ) {
								$sources[ 'meta:' . $key ] = $texts;
							}
						}
						foreach ( $sources as $location => $texts ) {
							$rows = array_merge( $rows, self::rows_for_texts( $texts, $base, $location, $extractor, $classifier, $state, $seen_tags ) );
						}
						$state['cursor'] = $pid;
						$state['processed_posts']++;
					}
					Results_Store::insert_occurrences( $rows );
					// Persist progress after every chunk.
					$state['updated_at'] = time();
					update_option( self::STATE_OPTION, $state, false );
				} while ( ( microtime( true ) - $start ) < $time_budget );
			} elseif ( self::PHASE_WIDGETS === $state['phase'] ) {
				Results_Store::delete_source( $state['scan_id'], 'widget' );
				$rows = array();
				foreach ( Content_Discovery::widget_items() as $item ) {
					$base = array(
						'scan_id'        => $state['scan_id'],
						'source_type'    => 'widget',
						'object_id'      => 0,
						'title'          => $item['title'],
						'content_type'   => 'widget',
						'content_status' => $item['status'],
					);
					$rows = array_merge( $rows, self::rows_for_texts( $item['texts'], $base, 'widget:' . $item['object_key'], $extractor, $classifier, $state, $seen_tags ) );
					$state['processed_widgets']++;
				}
				Results_Store::insert_occurrences( $rows );
				$state['phase'] = self::PHASE_OPTIONS;
			} elseif ( self::PHASE_OPTIONS === $state['phase'] ) {
				Results_Store::delete_source( $state['scan_id'], 'option' );
				$rows = array();
				foreach ( Content_Discovery::option_items() as $item ) {
					$base = array(
						'scan_id'        => $state['scan_id'],
						'source_type'    => 'option',
						'object_id'      => 0,
						'title'          => $item['title'],
						'content_type'   => 'option',
						'content_status' => '-',
					);
					$rows = array_merge( $rows, self::rows_for_texts( $item['texts'], $base, 'option:' . $item['option_name'], $extractor, $classifier, $state, $seen_tags ) );
					$state['processed_options']++;
				}
				Results_Store::insert_occurrences( $rows );
				$state = self::finalize( $state );
			}

			$state['updated_at'] = time();
			update_option( self::STATE_OPTION, $state, false );
			return $state;
		} finally {
			self::release_lock();
		}
	}

	/**
	 * Extract, classify and build table rows for the texts of one location.
	 */
	private static function rows_for_texts( array $texts, array $base, $location, Shortcode_Extractor $extractor, Classifier $classifier, array &$state, array &$seen_tags ) {
		$merged = array();
		foreach ( $texts as $text ) {
			$found = $extractor->extract( (string) $text );
			if ( $extractor->errors ) {
				self::warn(
					$state,
					sprintf(
						/* translators: 1: item title, 2: location, 3: error code */
						__( 'Could not fully parse "%1$s" (%2$s): %3$s. Results for this item may be incomplete.', 'impreza-compatibility-scanner' ),
						$base['title'],
						$location,
						implode( ', ', array_unique( $extractor->errors ) )
					)
				);
			}
			foreach ( $found as $tag => $info ) {
				if ( ! isset( $merged[ $tag ] ) ) {
					$merged[ $tag ] = $info;
					continue;
				}
				$merged[ $tag ]['count']  += $info['count'];
				$merged[ $tag ]['parents'] += $info['parents'];
				$merged[ $tag ]['notes']   += $info['notes'];
			}
		}

		$rows = array();
		foreach ( $merged as $tag => $info ) {
			$verdict = $classifier->classify( $tag );
			if ( ! isset( $seen_tags[ $tag ] ) ) {
				Results_Store::save_tag_verdict( $state['scan_id'], $tag, $verdict );
				$seen_tags[ $tag ] = true;
			}
			$rows[] = array_merge(
				$base,
				array(
					'location'       => substr( $location, 0, 191 ),
					'tag'            => substr( $tag, 0, 191 ),
					'classification' => $verdict->classification,
					'occurrences'    => (int) $info['count'],
					'parents'        => substr( implode( ',', array_keys( $info['parents'] ) ), 0, 255 ),
					'notes'          => substr( implode( ',', array_keys( $info['notes'] ) ), 0, 255 ),
					'sample'         => $info['sample'],
				)
			);
		}
		return $rows;
	}

	private static function warn( array &$state, $message ) {
		if ( in_array( $message, $state['warnings'], true ) ) {
			return;
		}
		if ( count( $state['warnings'] ) < self::MAX_WARNINGS ) {
			$state['warnings'][] = $message;
		} elseif ( count( $state['warnings'] ) === self::MAX_WARNINGS ) {
			$state['warnings'][] = __( 'More warnings were suppressed.', 'impreza-compatibility-scanner' );
		}
	}

	/**
	 * Mark complete, compute the summary, delete older scans.
	 */
	private static function finalize( array $state ) {
		$state['status']      = self::STATUS_COMPLETED;
		$state['phase']       = self::PHASE_DONE;
		$state['finished_at'] = time();

		$totals = Results_Store::get_totals( $state['scan_id'] );
		$last   = array(
			'scan_id'           => $state['scan_id'],
			'started_at'        => $state['started_at'],
			'finished_at'       => $state['finished_at'],
			'items_scanned'     => $state['processed_posts'] + $state['processed_widgets'] + $state['processed_options'],
			'processed_posts'   => $state['processed_posts'],
			'processed_widgets' => $state['processed_widgets'],
			'processed_options' => $state['processed_options'],
			'post_types'        => $state['post_types'],
			'post_statuses'     => $state['post_statuses'],
			'frontend_registry' => $state['frontend_registry'],
			'environment'       => $state['environment'],
			'warnings'          => $state['warnings'],
			'totals'            => $totals,
		);
		update_option( self::LAST_OPTION, $last, false );
		Results_Store::delete_other_scans( array( $state['scan_id'] ) );
		return $state;
	}

	/**
	 * Overall assessment of a completed scan. Never states that WPBakery is safe to remove.
	 *
	 * @return array{level:string,label:string,text:string}
	 */
	public static function assessment( array $last ) {
		$by = $last['totals']['by_classification'];

		if ( $by[ Verdict::INCOMPATIBLE ]['occurrences'] > 0 ) {
			return array(
				'level' => 'error',
				'label' => __( 'Confirmed incompatible elements found', 'impreza-compatibility-scanner' ),
				'text'  => __( 'Some content uses shortcodes that are rendered by WPBakery or by add-ons that require it. Deactivating WPBakery would break this content until it is rebuilt with Impreza elements.', 'impreza-compatibility-scanner' ),
			);
		}
		if ( $by[ Verdict::UNKNOWN ]['occurrences'] > 0 || $by[ Verdict::UNREGISTERED ]['occurrences'] > 0 || ! $last['frontend_registry'] || ! empty( $last['warnings'] ) || ! empty( $last['totals']['legacy_design_css'] ) || empty( $last['environment']['us_core_active'] ) ) {
			return array(
				'level' => 'warning',
				'label' => __( 'Manual review required', 'impreza-compatibility-scanner' ),
				'text'  => __( 'No verified WPBakery dependency was found, but some shortcodes or scan conditions could not be verified. Review the Unknown and Unregistered items and any warnings before changing anything.', 'impreza-compatibility-scanner' ),
			);
		}
		return array(
			'level' => 'success',
			'label' => __( 'No known incompatibilities found', 'impreza-compatibility-scanner' ),
			'text'  => __( 'The scan found no known WPBakery dependency in the scanned content. This is not a guarantee that WPBakery can be removed safely: theme files, custom code, page builder CSS, content outside the scanned sources, and attribute-level features are not covered. Test on a staging copy first.', 'impreza-compatibility-scanner' ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Lock (prevents two tabs from processing the same batch at once)
	 * ------------------------------------------------------------------ */

	private static function acquire_lock() {
		$ttl = 120;
		if ( add_option( self::LOCK_OPTION, time(), '', false ) ) {
			return true;
		}
		$since = (int) get_option( self::LOCK_OPTION );
		if ( $since && ( time() - $since ) > $ttl ) {
			update_option( self::LOCK_OPTION, time(), false );
			return true;
		}
		return false;
	}

	private static function release_lock() {
		delete_option( self::LOCK_OPTION );
	}

	/**
	 * Remove all plugin data (uninstall).
	 */
	public static function delete_all_data() {
		delete_option( self::STATE_OPTION );
		delete_option( self::LAST_OPTION );
		delete_option( self::LOCK_OPTION );
		delete_option( Registry::FRONTEND_OPTION );
		delete_option( 'ics_db_version' );
	}
}
