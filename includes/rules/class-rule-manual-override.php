<?php
/**
 * Manual overrides for tags a developer/administrator has verified by hand.
 *
 * Example (in a site plugin or child theme):
 *
 *   add_filter( 'ics_tag_classification_overrides', function ( $overrides ) {
 *       $overrides['my_plugin_box'] = array(
 *           'classification' => 'compatible',
 *           'reason'         => 'Verified on staging with WPBakery deactivated on 2026-11-20.',
 *           'lb_editable'    => 'no',
 *       );
 *       return $overrides;
 *   } );
 *
 * @package ImprezaCompatibilityScanner
 */

namespace ImprezaCompatScanner\Rules;

use ImprezaCompatScanner\Tag_Context;
use ImprezaCompatScanner\Verdict;

defined( 'ABSPATH' ) || exit;

class Rule_Manual_Override implements Rule {

	public function id() {
		return 'manual_override';
	}

	public function priority() {
		return 5;
	}

	public function evaluate( Tag_Context $ctx ) {
		$overrides = (array) apply_filters( 'ics_tag_classification_overrides', array() );
		if ( empty( $overrides[ $ctx->tag ] ) || ! is_array( $overrides[ $ctx->tag ] ) ) {
			return null;
		}
		$o = $overrides[ $ctx->tag ];
		$v = new Verdict(
			isset( $o['classification'] ) ? $o['classification'] : Verdict::UNKNOWN,
			isset( $o['reason'] ) ? (string) $o['reason'] : __( 'Classification set manually.', 'impreza-compatibility-scanner' ),
			array( __( 'Set via the ics_tag_classification_overrides filter (manual verification, not detected automatically).', 'impreza-compatibility-scanner' ) )
		);
		return $v->facts(
			isset( $o['renders_without_wpb'] ) ? $o['renders_without_wpb'] : Verdict::NOT_SURE,
			isset( $o['lb_editable'] ) ? $o['lb_editable'] : Verdict::NOT_SURE
		);
	}
}
