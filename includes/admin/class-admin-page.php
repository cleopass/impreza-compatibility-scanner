<?php
/**
 * Tools → Impreza Compatibility Scanner.
 *
 * @package ImprezaCompatibilityScanner
 */

namespace ImprezaCompatScanner\Admin;

use ImprezaCompatScanner\Environment;
use ImprezaCompatScanner\Plugin;
use ImprezaCompatScanner\Registry;
use ImprezaCompatScanner\Scan_Manager;
use ImprezaCompatScanner\Verdict;

defined( 'ABSPATH' ) || exit;

class Admin_Page {

	const SLUG = 'impreza-compatibility-scanner';

	/** @var string */
	private static $hook = '';

	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function menu() {
		self::$hook = add_management_page(
			__( 'Impreza Compatibility Scanner', 'impreza-compatibility-scanner' ),
			__( 'Impreza Compatibility Scanner', 'impreza-compatibility-scanner' ),
			Plugin::capability(),
			self::SLUG,
			array( __CLASS__, 'render' )
		);
		if ( self::$hook ) {
			add_action( 'load-' . self::$hook, array( __CLASS__, 'screen_options' ) );
		}
	}

	public static function screen_options() {
		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Rows per page', 'impreza-compatibility-scanner' ),
				'default' => 50,
				'option'  => 'ics_per_page',
			)
		);
		add_filter(
			'set_screen_option_ics_per_page',
			static function ( $status, $option, $value ) {
				return max( 10, min( 500, (int) $value ) );
			},
			10,
			3
		);
	}

	public static function assets( $hook ) {
		if ( $hook !== self::$hook ) {
			return;
		}
		wp_enqueue_style( 'ics-admin', ICS_URL . 'assets/admin.css', array(), ICS_VERSION );
		wp_enqueue_script( 'ics-admin', ICS_URL . 'assets/admin.js', array(), ICS_VERSION, true );
		wp_localize_script(
			'ics-admin',
			'icsAdmin',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( Plugin::AJAX_NONCE ),
				'probeUrl' => Registry::probe_url(),
				'state'    => Plugin::progress_payload( Scan_Manager::state() ),
				'i18n'     => array(
					'probing'       => __( 'Capturing the front-end shortcode registry…', 'impreza-compatibility-scanner' ),
					'probeFailed'   => __( 'Could not capture the front-end shortcode registry (the scan continues with the wp-admin registry only; WPBakery shortcodes may then show as Unregistered).', 'impreza-compatibility-scanner' ),
					'starting'      => __( 'Starting scan…', 'impreza-compatibility-scanner' ),
					'cancelling'    => __( 'Cancelling…', 'impreza-compatibility-scanner' ),
					'cancelled'     => __( 'Scan cancelled. Partial results were discarded.', 'impreza-compatibility-scanner' ),
					'completed'     => __( 'Scan completed. Reloading results…', 'impreza-compatibility-scanner' ),
					'error'         => __( 'The scan stopped because of an error:', 'impreza-compatibility-scanner' ),
					'retrying'      => __( 'Request failed, retrying…', 'impreza-compatibility-scanner' ),
					'confirmCancel' => __( 'Cancel the running scan? Its partial results will be discarded.', 'impreza-compatibility-scanner' ),
					/* translators: 1: processed, 2: total */
					'progress'      => __( '%1$s of %2$s content items', 'impreza-compatibility-scanner' ),
				),
			)
		);
	}

	/**
	 * Page output.
	 */
	public static function render() {
		if ( ! Plugin::current_user_can_scan() ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'impreza-compatibility-scanner' ) );
		}

		$env   = Environment::summary();
		$state = Scan_Manager::state();
		$last  = Scan_Manager::last_scan();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view switch.
		$view = isset( $_GET['view'] ) && 'shortcodes' === $_GET['view'] ? 'shortcodes' : 'content';
		?>
		<div class="wrap ics-wrap">
			<h1><?php esc_html_e( 'Impreza Compatibility Scanner', 'impreza-compatibility-scanner' ); ?></h1>
			<p class="ics-intro">
				<?php esc_html_e( 'Read-only audit of the shortcodes used in your content. It shows which ones are native Impreza elements, which depend on WPBakery Page Builder, and which need a manual check. Nothing on the site is changed.', 'impreza-compatibility-scanner' ); ?>
			</p>

			<?php self::render_environment( $env ); ?>
			<?php self::render_controls( $state, $last ); ?>

			<?php if ( $last ) : ?>
				<?php self::render_summary( $last ); ?>
				<?php self::render_results( $last, $view ); ?>
			<?php else : ?>
				<div class="notice notice-info inline"><p><?php esc_html_e( 'No completed scan yet. Start a scan to see results.', 'impreza-compatibility-scanner' ); ?></p></div>
			<?php endif; ?>

			<?php self::render_legend(); ?>
		</div>
		<?php
	}

	private static function yes_no( $bool ) {
		return $bool ? __( 'Yes', 'impreza-compatibility-scanner' ) : __( 'No', 'impreza-compatibility-scanner' );
	}

	private static function render_environment( array $env ) {
		$fe = Registry::frontend_snapshot();
		?>
		<div class="ics-card">
			<h2><?php esc_html_e( 'Environment', 'impreza-compatibility-scanner' ); ?></h2>
			<table class="widefat striped ics-env">
				<tbody>
				<tr>
					<th><?php esc_html_e( 'Theme', 'impreza-compatibility-scanner' ); ?></th>
					<td>
						<?php echo esc_html( $env['theme_name'] . ' ' . $env['theme_version'] ); ?>
						<?php if ( $env['child_theme'] ) : ?>
							<span class="description">(<?php echo esc_html( sprintf( /* translators: %s: child theme */ __( 'child theme: %s', 'impreza-compatibility-scanner' ), $env['child_theme'] ) ); ?>)</span>
						<?php endif; ?>
						<?php if ( ! $env['is_impreza'] ) : ?>
							<strong class="ics-warn"><?php esc_html_e( 'Impreza is not the active parent theme.', 'impreza-compatibility-scanner' ); ?></strong>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'UpSolution Core', 'impreza-compatibility-scanner' ); ?></th>
					<td>
						<?php
						echo $env['us_core_active']
							? esc_html( $env['us_core_version'] )
							: '<strong class="ics-warn">' . esc_html__( 'Not active — Impreza elements cannot be identified.', 'impreza-compatibility-scanner' ) . '</strong>';
						?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'WPBakery Page Builder', 'impreza-compatibility-scanner' ); ?></th>
					<td>
						<?php
						if ( ! $env['wpb_installed'] ) {
							esc_html_e( 'Not installed', 'impreza-compatibility-scanner' );
						} else {
							echo esc_html(
								sprintf(
									/* translators: 1: version, 2: active/inactive */
									__( 'Installed %1$s — %2$s', 'impreza-compatibility-scanner' ),
									$env['wpb_version'],
									$env['wpb_active'] ? __( 'active', 'impreza-compatibility-scanner' ) : __( 'inactive', 'impreza-compatibility-scanner' )
								)
							);
						}
						?>
					</td>
				</tr>
				<?php if ( $env['ult_installed'] ) : ?>
				<tr>
					<th><?php esc_html_e( 'Ultimate Addons for WPBakery', 'impreza-compatibility-scanner' ); ?></th>
					<td>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: version, 2: active/inactive */
								__( 'Installed %1$s — %2$s', 'impreza-compatibility-scanner' ),
								$env['ult_version'],
								$env['ult_active'] ? __( 'active', 'impreza-compatibility-scanner' ) : __( 'inactive', 'impreza-compatibility-scanner' )
							)
						);
						?>
					</td>
				</tr>
				<?php endif; ?>
				<tr>
					<th><?php esc_html_e( 'Live Builder enabled (theme option)', 'impreza-compatibility-scanner' ); ?></th>
					<td><?php echo esc_html( self::yes_no( $env['live_builder'] ) ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Front-end shortcode registry', 'impreza-compatibility-scanner' ); ?></th>
					<td>
						<?php
						if ( $fe ) {
							echo esc_html(
								sprintf(
									/* translators: 1: date, 2: number of tags */
									__( 'Captured %1$s (%2$d registered tags)', 'impreza-compatibility-scanner' ),
									wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $fe['captured_at'] ),
									count( $fe['tags'] )
								)
							);
						} else {
							esc_html_e( 'Not captured yet (happens automatically when a scan starts).', 'impreza-compatibility-scanner' );
						}
						?>
					</td>
				</tr>
				</tbody>
			</table>
		</div>
		<?php
	}

	private static function render_controls( $state, $last ) {
		$running = $state && Scan_Manager::STATUS_RUNNING === $state['status'];
		?>
		<div class="ics-card ics-controls" id="ics-controls">
			<h2><?php esc_html_e( 'Scan', 'impreza-compatibility-scanner' ); ?></h2>
			<p>
				<button type="button" class="button button-primary" id="ics-start" <?php disabled( $running ); ?>>
					<?php echo $last ? esc_html__( 'Run a new scan', 'impreza-compatibility-scanner' ) : esc_html__( 'Start scan', 'impreza-compatibility-scanner' ); ?>
				</button>
				<button type="button" class="button" id="ics-resume" <?php echo $running ? '' : 'hidden'; ?>>
					<?php esc_html_e( 'Resume interrupted scan', 'impreza-compatibility-scanner' ); ?>
				</button>
				<button type="button" class="button" id="ics-restart" <?php echo $running ? '' : 'hidden'; ?>>
					<?php esc_html_e( 'Restart from the beginning', 'impreza-compatibility-scanner' ); ?>
				</button>
				<button type="button" class="button ics-cancel" id="ics-cancel" hidden>
					<?php esc_html_e( 'Cancel scan', 'impreza-compatibility-scanner' ); ?>
				</button>
			</p>
			<div class="ics-progress" id="ics-progress" <?php echo $running ? '' : 'hidden'; ?>>
				<div class="ics-progress-bar"><span id="ics-progress-fill" style="width:0%"></span></div>
				<p id="ics-progress-text" aria-live="polite">
					<?php
					if ( $running ) {
						esc_html_e( 'A scan was interrupted (for example, the page was closed). You can resume it or restart it.', 'impreza-compatibility-scanner' );
					}
					?>
				</p>
			</div>
			<p class="description">
				<?php esc_html_e( 'The scan runs in small batches while this page is open. It reads content only — it does not execute shortcodes or modify anything. Results of the previous completed scan stay visible until a new scan completes.', 'impreza-compatibility-scanner' ); ?>
			</p>
		</div>
		<?php
	}

	private static function render_summary( array $last ) {
		$t          = $last['totals'];
		$by         = $t['by_classification'];
		$assessment = Scan_Manager::assessment( $last );
		$format     = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		?>
		<div class="ics-assessment ics-assessment-<?php echo esc_attr( $assessment['level'] ); ?>">
			<h2><?php echo esc_html( $assessment['label'] ); ?></h2>
			<p><?php echo esc_html( $assessment['text'] ); ?></p>
			<p class="ics-disclaimer"><?php esc_html_e( 'This report never confirms that WPBakery can be removed safely. Always test with WPBakery deactivated on a staging copy and keep a backup.', 'impreza-compatibility-scanner' ); ?></p>
		</div>

		<div class="ics-card">
			<h2><?php esc_html_e( 'Last scan', 'impreza-compatibility-scanner' ); ?></h2>
			<div class="ics-stats">
				<?php
				$stats = array(
					__( 'Finished', 'impreza-compatibility-scanner' )                   => wp_date( $format, (int) $last['finished_at'] ),
					__( 'Content items scanned', 'impreza-compatibility-scanner' )      => number_format_i18n( $last['items_scanned'] ),
					__( 'Shortcode occurrences', 'impreza-compatibility-scanner' )      => number_format_i18n( $t['occurrences'] ),
					__( 'Unique shortcode types', 'impreza-compatibility-scanner' )     => number_format_i18n( $t['unique_tags'] ),
				);
				foreach ( $stats as $label => $value ) {
					printf( '<div class="ics-stat"><span class="ics-stat-value">%s</span><span class="ics-stat-label">%s</span></div>', esc_html( $value ), esc_html( $label ) );
				}
				foreach ( array( Verdict::INCOMPATIBLE, Verdict::UNKNOWN, Verdict::UNREGISTERED, Verdict::COMPATIBLE ) as $c ) {
					printf(
						'<div class="ics-stat ics-stat-%1$s"><span class="ics-stat-value">%2$s</span><span class="ics-stat-label">%3$s</span></div>',
						esc_attr( $c ),
						esc_html( number_format_i18n( $by[ $c ]['occurrences'] ) ),
						esc_html(
							sprintf(
								/* translators: 1: classification, 2: number of tags */
								_n( '%1$s occurrences (%2$d type)', '%1$s occurrences (%2$d types)', $by[ $c ]['tags'], 'impreza-compatibility-scanner' ),
								Verdict::label( $c ),
								$by[ $c ]['tags']
							)
						)
					);
				}
				?>
			</div>
			<p class="description">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: posts, 2: widgets, 3: templates, 4: post types */
						__( 'Scanned %1$d posts/pages/templates, %2$d widgets with shortcode-like text, %3$d saved templates. Post types: %4$s.', 'impreza-compatibility-scanner' ),
						$last['processed_posts'],
						$last['processed_widgets'],
						$last['processed_options'],
						implode( ', ', $last['post_types'] )
					)
				);
				?>
			</p>
			<?php if ( ! $last['frontend_registry'] ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'The front-end shortcode registry could not be captured for this scan. Shortcodes that plugins register only on the front end (including most WPBakery elements) may appear as Unregistered.', 'impreza-compatibility-scanner' ); ?></p></div>
			<?php endif; ?>
			<?php if ( ! empty( $t['legacy_design_css'] ) ) : ?>
				<div class="notice notice-warning inline"><p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number */
							__( '%d shortcode occurrences use legacy WPBakery Design Options (css=".vc_custom_…"). Impreza stores Design options in a different format, and the CSS for this legacy format is output by WPBakery, so this styling may not apply without WPBakery (not verified per element — check visually). Search the content view for "legacy_wpb_design_css" to find them.', 'impreza-compatibility-scanner' ),
							$t['legacy_design_css']
						)
					);
					?>
				</p></div>
			<?php endif; ?>
			<?php if ( ! empty( $last['warnings'] ) ) : ?>
				<details class="ics-warnings">
					<summary><?php echo esc_html( sprintf( /* translators: %d: count */ _n( '%d warning', '%d warnings', count( $last['warnings'] ), 'impreza-compatibility-scanner' ), count( $last['warnings'] ) ) ); ?></summary>
					<ul>
						<?php foreach ( $last['warnings'] as $warning ) : ?>
							<li><?php echo esc_html( $warning ); ?></li>
						<?php endforeach; ?>
					</ul>
				</details>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_results( array $last, $view ) {
		$base_url = admin_url( 'tools.php?page=' . self::SLUG );
		$csv      = wp_nonce_url( admin_url( 'admin-post.php?action=ics_export_csv&type=occurrences' ), Csv_Exporter::NONCE );
		$csv_sum  = wp_nonce_url( admin_url( 'admin-post.php?action=ics_export_csv&type=summary' ), Csv_Exporter::NONCE );
		?>
		<div class="ics-card ics-results" id="ics-results">
			<h2 class="nav-tab-wrapper">
				<a href="<?php echo esc_url( add_query_arg( 'view', 'content', $base_url ) . '#ics-results' ); ?>" class="nav-tab <?php echo 'content' === $view ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'By content', 'impreza-compatibility-scanner' ); ?></a>
				<a href="<?php echo esc_url( add_query_arg( 'view', 'shortcodes', $base_url ) . '#ics-results' ); ?>" class="nav-tab <?php echo 'shortcodes' === $view ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'By shortcode', 'impreza-compatibility-scanner' ); ?></a>
			</h2>
			<p class="ics-export">
				<a class="button" href="<?php echo esc_url( $csv ); ?>"><?php esc_html_e( 'Export all results (CSV)', 'impreza-compatibility-scanner' ); ?></a>
				<a class="button" href="<?php echo esc_url( $csv_sum ); ?>"><?php esc_html_e( 'Export shortcode summary (CSV)', 'impreza-compatibility-scanner' ); ?></a>
			</p>
			<form method="get" action="<?php echo esc_url( admin_url( 'tools.php' ) ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
				<input type="hidden" name="view" value="<?php echo esc_attr( $view ); ?>" />
				<?php
				$table = 'shortcodes' === $view ? new Summary_Table( $last['scan_id'] ) : new Results_Table( $last['scan_id'] );
				$table->prepare_items();
				$table->search_box( __( 'Search', 'impreza-compatibility-scanner' ), 'ics-search' );
				$table->display();
				?>
			</form>
		</div>
		<?php
	}

	private static function render_legend() {
		?>
		<div class="ics-card ics-legend">
			<h2><?php esc_html_e( 'How to read the classifications', 'impreza-compatibility-scanner' ); ?></h2>
			<dl>
				<dt><span class="ics-badge ics-badge-compatible"><?php echo esc_html( Verdict::label( Verdict::COMPATIBLE ) ); ?></span></dt>
				<dd><?php esc_html_e( 'Verified to render without WPBakery: a native Impreza element rendered by US Core, a third-party element with built-in Impreza Live Builder support, or a WordPress core shortcode. Check the "Native Live Builder element" column: WordPress core shortcodes render but are not editable in the Live Builder.', 'impreza-compatibility-scanner' ); ?></dd>
				<dt><span class="ics-badge ics-badge-incompatible"><?php echo esc_html( Verdict::label( Verdict::INCOMPATIBLE ) ); ?></span></dt>
				<dd><?php esc_html_e( 'Verified dependency on WPBakery: rendered by WPBakery, a known WPBakery element without handler, or an add-on that only works with WPBakery (e.g. Ultimate Addons).', 'impreza-compatibility-scanner' ); ?></dd>
				<dt><span class="ics-badge ics-badge-unknown"><?php echo esc_html( Verdict::label( Verdict::UNKNOWN ) ); ?></span></dt>
				<dd><?php esc_html_e( 'Registered, but there is not enough evidence either way (typically third-party plugin shortcodes). Test with WPBakery deactivated on staging.', 'impreza-compatibility-scanner' ); ?></dd>
				<dt><span class="ics-badge ics-badge-unregistered"><?php echo esc_html( Verdict::label( Verdict::UNREGISTERED ) ); ?></span></dt>
				<dd><?php esc_html_e( 'Nothing handles this shortcode right now, so it is shown as plain text. Often a removed plugin — not automatically WPBakery.', 'impreza-compatibility-scanner' ); ?></dd>
			</dl>
			<p class="description"><?php esc_html_e( 'Every classification lists its evidence in the "By shortcode" view and in the CSV export.', 'impreza-compatibility-scanner' ); ?></p>
		</div>
		<?php
	}

	/**
	 * HTML badge for a classification.
	 */
	public static function badge( $classification ) {
		return sprintf(
			'<span class="ics-badge ics-badge-%1$s">%2$s</span>',
			esc_attr( $classification ),
			esc_html( Verdict::label( $classification ) )
		);
	}
}
