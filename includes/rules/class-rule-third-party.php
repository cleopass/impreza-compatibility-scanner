<?php
/**
 * Shortcodes registered by other plugins / themes, without verified evidence either way.
 *
 * Always Unknown: registration alone does not prove WPBakery independence (Ultimate Addons is a
 * counter-example) nor Live Builder editability. The evidence lists the positive and negative signals.
 *
 * @package ImprezaCompatibilityScanner
 */

namespace ImprezaCompatScanner\Rules;

use ImprezaCompatScanner\Registry;
use ImprezaCompatScanner\Tag_Context;
use ImprezaCompatScanner\Verdict;

defined( 'ABSPATH' ) || exit;

class Rule_Third_Party implements Rule {

	public function id() {
		return 'third_party';
	}

	public function priority() {
		return 60;
	}

	public function evaluate( Tag_Context $ctx ) {
		if ( ! $ctx->is_registered() ) {
			return null;
		}

		$origin   = $ctx->origin();
		$slug     = $ctx->handler['slug'];
		$evidence = array(
			sprintf(
				/* translators: 1: origin type, 2: slug, 3: handler */
				__( 'Registered by %1$s "%2$s": %3$s.', 'impreza-compatibility-scanner' ),
				$origin,
				$slug ? $slug : '?',
				$ctx->handler_text()
			),
			__( 'Not an Impreza theme element: Live Builder keeps it in place but shows "Editing of this element is not supported".', 'impreza-compatibility-scanner' ),
			$ctx->registry_evidence(),
		);

		$renders = Verdict::NOT_SURE;
		if ( ! $ctx->wpb_active_effective() ) {
			$evidence[] = __( 'Registered while WPBakery is inactive — positive sign that rendering does not need WPBakery (output and styling not verified).', 'impreza-compatibility-scanner' );
			$reason     = __( 'Third-party shortcode registered without WPBakery. Likely independent, but the output was not verified and it is not a Live Builder element. Check the page visually.', 'impreza-compatibility-scanner' );
		} else {
			$reason = __( 'Third-party shortcode. WPBakery is active, so independence from WPBakery could not be tested. Not a Live Builder element. Test with WPBakery deactivated on staging.', 'impreza-compatibility-scanner' );
		}
		if ( true === $ctx->wpb_mapped ) {
			$evidence[] = __( 'Mapped into the WPBakery editor (vc_map). This is an editor integration; it does not by itself prove a rendering dependency.', 'impreza-compatibility-scanner' );
		}
		if ( in_array( $origin, array( Registry::ORIGIN_THEME, Registry::ORIGIN_CHILDTHEME ), true ) ) {
			$evidence[] = __( 'Registered by the active theme or child theme.', 'impreza-compatibility-scanner' );
		}

		$v          = new Verdict( Verdict::UNKNOWN, $reason, $evidence );
		$v->handler = $ctx->handler_text();
		return $v->facts( $renders, Verdict::NO );
	}
}
