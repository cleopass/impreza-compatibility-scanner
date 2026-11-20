<?php
/**
 * Shortcodes of add-ons with a verified runtime dependency on WPBakery (data/wpbakery-dependent-addons.php),
 * e.g. Ultimate Addons for WPBakery, which registers nothing when WPB_VC_VERSION is undefined.
 *
 * @package ImprezaCompatibilityScanner
 */

namespace ImprezaCompatScanner\Rules;

use ImprezaCompatScanner\Tag_Context;
use ImprezaCompatScanner\Verdict;

defined( 'ABSPATH' ) || exit;

class Rule_Wpbakery_Dependent_Addon implements Rule {

	public function id() {
		return 'wpbakery_dependent_addon';
	}

	public function priority() {
		return 30;
	}

	public function evaluate( Tag_Context $ctx ) {
		if ( empty( $ctx->dependent_addon ) || null !== $ctx->us_element ) {
			return null;
		}
		$addon = $ctx->dependent_addon;

		$evidence = array(
			sprintf(
				/* translators: 1: add-on name, 2: version, 3: evidence text */
				__( '%1$s (verified in version %2$s): %3$s', 'impreza-compatibility-scanner' ),
				$addon['name'],
				$addon['verified_version'],
				$addon['evidence']
			),
		);
		if ( $ctx->is_registered() ) {
			$evidence[] = sprintf(
				/* translators: %s: handler */
				__( 'Current handler: %s.', 'impreza-compatibility-scanner' ),
				$ctx->handler_text()
			);
		} else {
			$evidence[] = __( 'No handler is registered right now; the tag is in the add-on\'s known tag list.', 'impreza-compatibility-scanner' );
		}
		$evidence[] = $ctx->registry_evidence();

		// Contradiction check: registered while WPBakery is inactive → the add-on version behaves
		// differently from the verified one. Do not claim a dependency we cannot see.
		if ( $ctx->is_registered() && ! $ctx->wpb_active_effective() ) {
			$evidence[] = __( 'The tag is registered although WPBakery is inactive, which contradicts the verified behaviour. The installed version may differ.', 'impreza-compatibility-scanner' );
			$v = new Verdict(
				Verdict::UNKNOWN,
				sprintf(
					/* translators: %s: add-on name */
					__( '%s shortcode registered without WPBakery — behaviour differs from the verified version. Test manually.', 'impreza-compatibility-scanner' ),
					$addon['name']
				),
				$evidence
			);
			return $v->facts( Verdict::NOT_SURE, Verdict::NO );
		}

		$reason = $ctx->is_registered()
			? __( 'Add-on element that requires WPBakery: %s does not register any shortcode when WPBakery is inactive. Not editable in the Live Builder.', 'impreza-compatibility-scanner' )
			: __( 'Add-on element that requires WPBakery: %s does not register any shortcode when WPBakery is inactive. It currently has no handler and is output as plain text.', 'impreza-compatibility-scanner' );

		$v = new Verdict( Verdict::INCOMPATIBLE, sprintf( $reason, $addon['name'] ), $evidence );
		return $v->facts( Verdict::NO, Verdict::NO );
	}
}
