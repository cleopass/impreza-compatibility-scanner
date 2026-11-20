<?php
/**
 * Shortcode extraction — parses text with WordPress' own shortcode grammar, never executes anything.
 *
 * Approach:
 *  1. Collect candidate tag names with the same character class WordPress uses in
 *     get_shortcode_tags_in_content(), restricted to sane names.
 *  2. Build get_shortcode_regex( $candidates ) — WordPress' parser — so that registered AND
 *     unregistered tags are found with identical rules (enclosing, self-closing, [[escaped]]).
 *  3. Recurse into the content of enclosing shortcodes to find nested shortcodes.
 *
 * Unknown candidates (not registered and not in any known tag list) are only counted when they
 * look like a shortcode (attributes, self-closing slash or closing tag), the same rule US Core's
 * Live Builder uses in USBuilder_Shortcode::normalize_content(). This keeps "[Note]" in plain
 * text out, while known tags such as [vc_empty_space] are always counted.
 *
 * @package ImprezaCompatibilityScanner
 */

namespace ImprezaCompatScanner;

defined( 'ABSPATH' ) || exit;

class Shortcode_Extractor {

	const MAX_DEPTH  = 40;
	const SAMPLE_LEN = 200;

	/** @var callable(string):bool Returns true for registered or otherwise known tags. */
	private $is_registered;

	/** @var string[] Parse problems of the last extract() call. */
	public $errors = array();

	/**
	 * @param callable $is_registered Receives a tag name, returns true if it is registered or known.
	 */
	public function __construct( callable $is_registered ) {
		$this->is_registered = $is_registered;
	}

	/**
	 * Extract shortcode occurrences from a text.
	 *
	 * @param string $text
	 * @return array<string,array{count:int,parents:array,notes:array,sample:string}> keyed by tag.
	 */
	public function extract( $text ) {
		$this->errors = array();
		$found        = array();

		if ( ! is_string( $text ) || false === strpos( $text, '[' ) ) {
			return $found;
		}

		$candidates = $this->candidate_tags( $text );
		if ( empty( $candidates ) ) {
			return $found;
		}

		$regex = '/' . get_shortcode_regex( $candidates ) . '/';
		$this->walk( $text, $regex, array(), 0, $found );

		return $found;
	}

	/**
	 * Candidate tag names in a text.
	 *
	 * @return string[]
	 */
	public function candidate_tags( $text ) {
		// Same character class as WordPress' get_shortcode_tags_in_content().
		if ( ! preg_match_all( '@\[([^<>&/\[\]\x00-\x20=]++)@', $text, $matches ) ) {
			return array();
		}
		$tags = array();
		foreach ( array_unique( $matches[1] ) as $tag ) {
			// Restrict to names a shortcode realistically uses: starts with a letter, then letters,
			// digits, underscore or hyphen. Excludes JSON ("[{"), footnotes ("[1]") and similar.
			if ( strlen( $tag ) <= 100 && preg_match( '/^[A-Za-z][A-Za-z0-9_\-]*$/', $tag ) ) {
				$tags[] = $tag;
			}
		}
		/**
		 * Filter candidate shortcode tags found in a text before parsing.
		 *
		 * @param string[] $tags
		 * @param string   $text
		 */
		return (array) apply_filters( 'ics_candidate_tags', $tags, $text );
	}

	/**
	 * Recursive walk.
	 */
	private function walk( $text, $regex, array $parents, $depth, array &$found ) {
		if ( $depth > self::MAX_DEPTH ) {
			$this->errors[] = 'max_depth';
			return;
		}

		$result = preg_match_all( $regex, $text, $matches, PREG_SET_ORDER );
		if ( false === $result ) {
			// PCRE limits (backtrack / recursion) on very large or malformed content.
			$this->errors[] = 'pcre_error_' . preg_last_error();
			return;
		}

		foreach ( $matches as $m ) {
			// Escaped shortcode: [[tag]] — WordPress outputs it literally. Not counted, not descended.
			if ( '[' === $m[1] && isset( $m[6] ) && ']' === $m[6] ) {
				continue;
			}

			$tag          = $m[2];
			$atts         = isset( $m[3] ) ? $m[3] : '';
			$self_closing = isset( $m[4] ) && '/' === $m[4];
			$content      = isset( $m[5] ) ? $m[5] : '';
			$enclosing    = false !== strpos( $m[0], '[/' . $tag . ']' );

			if ( ! call_user_func( $this->is_registered, $tag ) && ! $this->looks_like_shortcode( $atts, $self_closing, $enclosing ) ) {
				continue;
			}

			if ( ! isset( $found[ $tag ] ) ) {
				$found[ $tag ] = array(
					'count'   => 0,
					'parents' => array(),
					'notes'   => array(),
					'sample'  => $this->sample( $tag, $atts, $self_closing ),
				);
			}
			$found[ $tag ]['count']++;

			if ( $parents ) {
				$found[ $tag ]['parents'][ end( $parents ) ] = true;
			}
			foreach ( $this->attribute_notes( $atts ) as $note ) {
				$found[ $tag ]['notes'][ $note ] = true;
			}

			if ( $enclosing && '' !== $content && false !== strpos( $content, '[' ) ) {
				$this->walk( $content, $regex, array_merge( $parents, array( $tag ) ), $depth + 1, $found );
			}
		}
	}

	/**
	 * Same heuristic as US Core's Live Builder for unregistered tags.
	 */
	private function looks_like_shortcode( $atts, $self_closing, $enclosing ) {
		return $self_closing || $enclosing || false !== strpos( $atts, '=' );
	}

	/**
	 * Notes derived from attributes (do not affect the classification).
	 *
	 * @return string[]
	 */
	private function attribute_notes( $atts ) {
		$notes = array();
		// Legacy WPBakery Design Options: css=".vc_custom_123{...}" — CSS printed by WPBakery from
		// the _wpb_shortcodes_custom_css post meta (US Core uses URL-encoded JSON instead).
		if ( '' !== $atts && preg_match( '/\bcss\s*=\s*["\']\s*\.vc_custom_\d+/', $atts ) ) {
			$notes[] = 'legacy_wpb_design_css';
		}
		return (array) apply_filters( 'ics_attribute_notes', $notes, $atts );
	}

	private function sample( $tag, $atts, $self_closing ) {
		$sample = '[' . $tag . $atts . ( $self_closing ? ' /' : '' ) . ']';
		if ( strlen( $sample ) > self::SAMPLE_LEN ) {
			$sample = substr( $sample, 0, self::SAMPLE_LEN - 4 ) . ' …]';
		}
		// Make sure the stored sample is valid UTF-8 after truncation.
		return function_exists( 'wp_check_invalid_utf8' ) ? wp_check_invalid_utf8( $sample, true ) : $sample;
	}
}
