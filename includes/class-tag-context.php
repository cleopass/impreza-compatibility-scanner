<?php
/**
 * All facts about one shortcode tag, collected once and passed to every classification rule.
 *
 * @package ImprezaCompatibilityScanner
 */

namespace ImprezaCompatScanner;

defined( 'ABSPATH' ) || exit;

class Tag_Context {

	/** @var string */
	public $tag;

	/** @var array|null Handler description from Registry::describe_callback(), null if unregistered. */
	public $handler;

	/** @var bool Front-end registry was available for this scan. */
	public $frontend_registry;

	/** @var bool|null Was WPBakery active in the front-end registry snapshot? */
	public $frontend_wpb_active;

	/** @var bool WPBakery active in the current (admin) runtime. */
	public $wpb_active;

	/** @var bool US Core is active. */
	public $us_core_active;

	/** @var string|null US element (config) name when the tag is an Impreza theme element. */
	public $us_element;

	/** @var bool Element config says override_config_only (rendered by its own plugin). */
	public $us_override_config_only = false;

	/** @var string Live Builder availability: yes / no / unknown. */
	public $us_in_live_builder = Verdict::NOT_SURE;

	/** @var bool Listed in US Core's "disabled" WPBakery elements. */
	public $us_disabled = false;

	/** @var bool Listed in US Core's "added_design_options". */
	public $us_added_design_options = false;

	/** @var bool US Core option "Disable extra features of WPBakery" is on. */
	public $us_disable_extra_vc = false;

	/** @var bool|null Mapped into WPBakery editor (WPBMap::exists). Null = WPBakery not loaded. */
	public $wpb_mapped;

	/** @var bool In the WPBakery-owned element list (data file). */
	public $known_wpb_tag = false;

	/** @var string */
	public $known_wpb_source_version = '';

	/** @var array|null Matching WPBakery-dependent add-on definition. */
	public $dependent_addon;

	/** @var string|null Key of the matching add-on. */
	public $dependent_addon_key;

	/**
	 * Build the context.
	 *
	 * @param string $tag
	 * @param array  $registry Result of Registry::merged().
	 */
	public static function build( $tag, array $registry ) {
		$ctx      = new self();
		$ctx->tag = $tag;

		$ctx->handler             = isset( $registry['tags'][ $tag ] ) ? $registry['tags'][ $tag ] : null;
		$ctx->frontend_registry   = ! empty( $registry['frontend'] );
		$ctx->frontend_wpb_active = isset( $registry['frontend_wpb_active'] ) ? $registry['frontend_wpb_active'] : null;
		$ctx->wpb_active          = defined( 'WPB_VC_VERSION' );
		$ctx->us_core_active      = Environment::us_core()['active'];

		$us_tags    = Environment::us_theme_element_tags();
		$us_config  = Environment::us_shortcodes_config();
		if ( isset( $us_tags[ $tag ] ) ) {
			$ctx->us_element              = $us_tags[ $tag ];
			$element_config               = Environment::us_element_config( $ctx->us_element );
			$ctx->us_override_config_only = ! empty( $element_config['override_config_only'] );
			$ctx->us_in_live_builder      = Environment::us_element_in_live_builder( $ctx->us_element );
		}
		$ctx->us_disabled             = in_array( $tag, $us_config['disabled'], true );
		$ctx->us_added_design_options = in_array( $tag, $us_config['added_design_options'], true );
		$ctx->us_disable_extra_vc     = (bool) Environment::us_option( 'disable_extra_vc', 1 );

		$ctx->wpb_mapped = Environment::wpbakery_mapped( $tag );

		$known                         = Environment::known_wpbakery_tags();
		$ctx->known_wpb_tag            = in_array( $tag, $known['tags'], true );
		$ctx->known_wpb_source_version = $known['source_version'];

		foreach ( Environment::wpbakery_dependent_addons() as $key => $addon ) {
			$by_tag  = ! empty( $addon['tags'] ) && in_array( $tag, (array) $addon['tags'], true );
			$by_file = $ctx->handler && ! empty( $addon['plugin_dir'] ) && Registry::ORIGIN_PLUGIN === $ctx->handler['origin'] && $addon['plugin_dir'] === $ctx->handler['slug'];
			if ( $by_tag || $by_file ) {
				$ctx->dependent_addon     = $addon;
				$ctx->dependent_addon_key = $key;
				break;
			}
		}

		return $ctx;
	}

	/**
	 * Was WPBakery active where content renders? Prefers the front-end snapshot.
	 */
	public function wpb_active_effective() {
		return null !== $this->frontend_wpb_active ? (bool) $this->frontend_wpb_active : (bool) $this->wpb_active;
	}

	public function is_registered() {
		return null !== $this->handler;
	}

	public function origin() {
		return $this->handler ? $this->handler['origin'] : '';
	}

	/**
	 * Short handler text for tables.
	 */
	public function handler_text() {
		if ( ! $this->handler ) {
			return '';
		}
		$text = $this->handler['label'];
		if ( ! empty( $this->handler['file'] ) ) {
			$text .= ' (' . $this->handler['file'] . ')';
		}
		return $text;
	}

	/**
	 * Evidence line about where the registry came from.
	 */
	public function registry_evidence() {
		if ( $this->frontend_registry ) {
			return sprintf(
				/* translators: %s: yes/no */
				__( 'Shortcode registry captured on the front end (WPBakery active there: %s) and in wp-admin.', 'impreza-compatibility-scanner' ),
				$this->frontend_wpb_active ? __( 'yes', 'impreza-compatibility-scanner' ) : __( 'no', 'impreza-compatibility-scanner' )
			);
		}
		return __( 'Only the wp-admin shortcode registry was available. Some plugins (including WPBakery) register shortcodes only on the front end.', 'impreza-compatibility-scanner' );
	}
}
