<?php
/**
 * Known WPBakery element tags that currently have no handler (e.g. WPBakery is inactive).
 *
 * Evidence: tag is in the WPBakery-owned element list (data/wpbakery-elements.php, extracted from
 * WPBakery's own element configuration) and is not an Impreza theme element.
 *
 * @package ImprezaCompatibilityScanner
 */

namespace ImprezaCompatScanner\Rules;

use ImprezaCompatScanner\Tag_Context;
use ImprezaCompatScanner\Verdict;

defined( 'ABSPATH' ) || exit;

class Rule_Known_Wpbakery_Unregistered implements Rule {

	public function id() {
		return 'known_wpbakery_unregistered';
	}

	public function priority() {
		return 40;
	}

	public function evaluate( Tag_Context $ctx ) {
		if ( $ctx->is_registered() || ! $ctx->known_wpb_tag || null !== $ctx->us_element ) {
			return null;
		}

		$evidence = array(
			sprintf(
				/* translators: %s: WPBakery version */
				__( 'Tag belongs to WPBakery\'s own element set (extracted from WPBakery %s).', 'impreza-compatibility-scanner' ),
				$ctx->known_wpb_source_version
			),
			__( 'Not listed in US Core theme_elements — Impreza has no renderer for it.', 'impreza-compatibility-scanner' ),
			__( 'No handler is registered in the inspected runtime(s).', 'impreza-compatibility-scanner' ),
			$ctx->registry_evidence(),
		);
		if ( $ctx->us_disabled ) {
			$evidence[] = __( 'Listed in US Core shortcodes config "disabled" (WPBakery elements Impreza does not support).', 'impreza-compatibility-scanner' );
		}

		// Without US Core we cannot tell which vc_* tags Impreza would render itself (vc_row, vc_column, …).
		if ( ! $ctx->us_core_active ) {
			$evidence[] = __( 'US Core is not active, so the Impreza element list could not be read. Impreza renders several vc_* layout shortcodes itself when US Core is active.', 'impreza-compatibility-scanner' );
			$v = new Verdict(
				Verdict::UNREGISTERED,
				__( 'WPBakery element tag without a handler. Activate US Core and scan again to find out whether Impreza renders it.', 'impreza-compatibility-scanner' ),
				$evidence
			);
			return $v->facts( Verdict::NO, Verdict::NOT_SURE );
		}

		if ( $ctx->wpb_active_effective() ) {
			$reason = __( 'WPBakery element that Impreza does not render. It is not registered as a regular shortcode right now: either it is disabled (in content it shows as plain text) or WPBakery only uses it in its own contexts, such as Grid Builder templates (vc_gitem*).', 'impreza-compatibility-scanner' );
		} else {
			$reason = __( 'WPBakery element without a handler: only WPBakery can render it, and it is currently output as plain shortcode text.', 'impreza-compatibility-scanner' );
		}
		$v = new Verdict( Verdict::INCOMPATIBLE, $reason, $evidence );
		return $v->facts( Verdict::NO, Verdict::NO );
	}
}
