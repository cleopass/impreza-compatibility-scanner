<?php
/**
 * Impreza / US Core theme elements.
 *
 * Evidence (docs/FINDINGS.md §1–3):
 *  - US_Shortcodes::init() registers every entry of us_config('shortcodes.theme_elements')
 *    with its own handler, independently of WPBakery.
 *  - USBuilder::get_config() builds the Live Builder's "elms_supported" list from the same
 *    theme_elements list (minus elements whose place_if is false).
 *
 * @package ImprezaCompatibilityScanner
 */

namespace ImprezaCompatScanner\Rules;

use ImprezaCompatScanner\Registry;
use ImprezaCompatScanner\Tag_Context;
use ImprezaCompatScanner\Verdict;

defined( 'ABSPATH' ) || exit;

class Rule_Us_Native_Element implements Rule {

	public function id() {
		return 'us_native_element';
	}

	public function priority() {
		return 10;
	}

	public function evaluate( Tag_Context $ctx ) {
		if ( null === $ctx->us_element || ! $ctx->is_registered() ) {
			return null;
		}

		$evidence = array(
			sprintf(
				/* translators: %s: element name */
				__( 'Listed in US Core shortcodes config "theme_elements" as "%s".', 'impreza-compatibility-scanner' ),
				$ctx->us_element
			),
			sprintf(
				/* translators: %s: handler */
				__( 'Current handler: %s.', 'impreza-compatibility-scanner' ),
				$ctx->handler_text()
			),
			$ctx->registry_evidence(),
		);

		$origin = $ctx->origin();

		// Case A: rendered by US Core itself.
		if ( Registry::ORIGIN_US_CORE === $origin ) {
			if ( Verdict::YES === $ctx->us_in_live_builder ) {
				$evidence[] = __( 'Included in the Live Builder list of editable elements (elms_supported).', 'impreza-compatibility-scanner' );
				$v = new Verdict(
					Verdict::COMPATIBLE,
					__( 'Native Impreza element: rendered by US Core (not WPBakery) and editable in the Live Builder.', 'impreza-compatibility-scanner' ),
					$evidence
				);
				return $v->facts( Verdict::YES, Verdict::YES );
			}

			$evidence[] = __( 'The element config has a place_if condition that is currently false, so the Live Builder does not offer it for editing.', 'impreza-compatibility-scanner' );
			$v = new Verdict(
				Verdict::UNKNOWN,
				__( 'Impreza element rendered by US Core, but currently not available in the Live Builder (its availability condition is false, e.g. a required plugin is inactive). Review whether the content is still needed.', 'impreza-compatibility-scanner' ),
				$evidence
			);
			return $v->facts( Verdict::YES, Verdict::NO );
		}

		// Case B: "override_config_only" — third-party shortcode (Gravity Forms, CF7, WooCommerce)
		// rendered by its own plugin, with Live Builder settings provided by US Core.
		if ( $ctx->us_override_config_only && Registry::ORIGIN_WPBAKERY !== $origin ) {
			$evidence[] = __( 'Element config sets override_config_only: US Core only provides the builder settings; the shortcode is rendered by its own plugin.', 'impreza-compatibility-scanner' );

			if ( Verdict::YES === $ctx->us_in_live_builder ) {
				$v = new Verdict(
					Verdict::COMPATIBLE,
					__( 'Third-party shortcode with built-in Impreza Live Builder support (settings provided by US Core). Rendered by its own plugin, not by WPBakery.', 'impreza-compatibility-scanner' ),
					$evidence
				);
				return $v->facts( Verdict::YES, Verdict::YES );
			}
			$v = new Verdict(
				Verdict::UNKNOWN,
				__( 'Third-party shortcode known to US Core, but the Live Builder currently does not offer it (availability condition is false).', 'impreza-compatibility-scanner' ),
				$evidence
			);
			return $v->facts( Verdict::YES, Verdict::NO );
		}

		// Case C: theme element name, but another handler won. Unusual — needs a human look.
		$v = new Verdict(
			Verdict::UNKNOWN,
			__( 'The tag is an Impreza element name, but it is currently handled by different code than US Core. Check which plugin overrides it.', 'impreza-compatibility-scanner' ),
			$evidence
		);
		return $v->facts( Registry::ORIGIN_WPBAKERY === $origin ? Verdict::NO : Verdict::NOT_SURE, Verdict::NOT_SURE );
	}
}
