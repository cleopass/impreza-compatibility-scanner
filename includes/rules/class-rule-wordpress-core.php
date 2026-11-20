<?php
/**
 * Shortcodes registered by WordPress core (caption, audio, video, playlist, embed, …).
 *
 * WordPress core cannot depend on WPBakery, so rendering is independent. They are not Impreza
 * theme elements, so the Live Builder keeps them in place but cannot edit them natively
 * (USBuilder_Shortcode::normalize_content() leaves non-theme shortcodes unchanged).
 *
 * @package ImprezaCompatibilityScanner
 */

namespace ImprezaCompatScanner\Rules;

use ImprezaCompatScanner\Registry;
use ImprezaCompatScanner\Tag_Context;
use ImprezaCompatScanner\Verdict;

defined( 'ABSPATH' ) || exit;

class Rule_Wordpress_Core implements Rule {

	public function id() {
		return 'wordpress_core';
	}

	public function priority() {
		return 50;
	}

	public function evaluate( Tag_Context $ctx ) {
		if ( ! $ctx->is_registered() || Registry::ORIGIN_WORDPRESS !== $ctx->origin() || null !== $ctx->us_element ) {
			return null;
		}
		$v = new Verdict(
			Verdict::COMPATIBLE,
			__( 'WordPress core shortcode: renders without WPBakery. The Live Builder keeps it in place but cannot edit it as an element (edit it in the backend editor).', 'impreza-compatibility-scanner' ),
			array(
				sprintf(
					/* translators: %s: handler */
					__( 'Handler is part of WordPress core: %s.', 'impreza-compatibility-scanner' ),
					$ctx->handler_text()
				),
				__( 'Not an Impreza theme element: Live Builder shows "Editing of this element is not supported" for it.', 'impreza-compatibility-scanner' ),
			)
		);
		return $v->facts( Verdict::YES, Verdict::NO );
	}
}
