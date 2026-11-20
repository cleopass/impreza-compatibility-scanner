<?php
/**
 * Tags with no handler in any inspected runtime and no known owner.
 *
 * Potential problem (the shortcode is output as text), but not proof of a WPBakery dependency.
 *
 * @package ImprezaCompatibilityScanner
 */

namespace ImprezaCompatScanner\Rules;

use ImprezaCompatScanner\Tag_Context;
use ImprezaCompatScanner\Verdict;

defined( 'ABSPATH' ) || exit;

class Rule_Unregistered implements Rule {

	public function id() {
		return 'unregistered';
	}

	public function priority() {
		return 70;
	}

	public function evaluate( Tag_Context $ctx ) {
		if ( $ctx->is_registered() ) {
			return null;
		}
		$evidence = array(
			__( 'No handler is registered for this tag in the inspected runtime(s).', 'impreza-compatibility-scanner' ),
			$ctx->registry_evidence(),
		);
		if ( null !== $ctx->us_element && $ctx->us_override_config_only ) {
			$evidence[] = __( 'US Core provides Live Builder settings for this shortcode, but it is rendered by its own plugin, which is not active.', 'impreza-compatibility-scanner' );
		} elseif ( null !== $ctx->us_element ) {
			$evidence[] = __( 'The tag is an Impreza element name, but US Core did not register it (is US Core active?).', 'impreza-compatibility-scanner' );
		}
		if ( true === $ctx->wpb_mapped ) {
			$evidence[] = __( 'Mapped into the WPBakery editor (vc_map) but not registered as a shortcode.', 'impreza-compatibility-scanner' );
		}

		$v = new Verdict(
			Verdict::UNREGISTERED,
			__( 'No plugin or theme handles this shortcode right now, so visitors see it as plain text. It may belong to a deactivated or removed plugin — not necessarily WPBakery.', 'impreza-compatibility-scanner' ),
			$evidence
		);
		return $v->facts( Verdict::NO, Verdict::NO );
	}
}
