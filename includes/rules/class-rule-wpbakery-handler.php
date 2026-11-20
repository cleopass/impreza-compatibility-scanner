<?php
/**
 * Shortcodes currently rendered by WPBakery itself.
 *
 * Evidence: the handler is 'vc_do_shortcode', an instance of WPBakeryShortCode(FishBones), or code
 * inside the WPBakery plugin folder; and the tag is not an Impreza theme element. The handler
 * disappears when WPBakery is deactivated. The Live Builder does not list such tags as editable.
 *
 * @package ImprezaCompatibilityScanner
 */

namespace ImprezaCompatScanner\Rules;

use ImprezaCompatScanner\Registry;
use ImprezaCompatScanner\Tag_Context;
use ImprezaCompatScanner\Verdict;

defined( 'ABSPATH' ) || exit;

class Rule_Wpbakery_Handler implements Rule {

	public function id() {
		return 'wpbakery_handler';
	}

	public function priority() {
		return 20;
	}

	public function evaluate( Tag_Context $ctx ) {
		if ( ! $ctx->is_registered() || Registry::ORIGIN_WPBAKERY !== $ctx->origin() || null !== $ctx->us_element ) {
			return null;
		}

		$evidence = array(
			sprintf(
				/* translators: %s: handler */
				__( 'Rendered by WPBakery: %s.', 'impreza-compatibility-scanner' ),
				$ctx->handler_text()
			),
			__( 'Not listed in US Core theme_elements, so the Live Builder shows "Editing of this element is not supported".', 'impreza-compatibility-scanner' ),
			$ctx->registry_evidence(),
		);
		if ( $ctx->us_added_design_options ) {
			$evidence[] = __( 'US Core only adds its Design options to this element inside the WPBakery editor (shortcodes config "added_design_options"); rendering stays with WPBakery.', 'impreza-compatibility-scanner' );
		}
		if ( $ctx->us_disabled ) {
			$evidence[] = __( 'Listed in US Core shortcodes config "disabled" (WPBakery elements Impreza hides from the editor).', 'impreza-compatibility-scanner' );
		}

		$v = new Verdict(
			Verdict::INCOMPATIBLE,
			__( 'WPBakery element: rendered by WPBakery, not by Impreza. It will stop rendering when WPBakery is deactivated and cannot be edited in the Live Builder.', 'impreza-compatibility-scanner' ),
			$evidence
		);
		$v->handler = $ctx->handler_text();
		return $v->facts( Verdict::NO, Verdict::NO );
	}
}
