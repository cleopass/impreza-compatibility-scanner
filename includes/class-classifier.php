<?php
/**
 * Runs the classification rules for a tag. Results are cached per request.
 *
 * Extending:
 *  - 'ics_classification_rules'          add/remove/reorder Rule objects.
 *  - 'ics_tag_classification_overrides'  manual per-tag verdicts (see Rule_Manual_Override).
 *  - 'ics_tag_verdict'                   last chance to adjust a verdict.
 *
 * @package ImprezaCompatibilityScanner
 */

namespace ImprezaCompatScanner;

use ImprezaCompatScanner\Rules\Rule;

defined( 'ABSPATH' ) || exit;

class Classifier {

	/** @var array Result of Registry::merged(). */
	private $registry;

	/** @var Rule[] */
	private $rules;

	/** @var Verdict[] */
	private $cache = array();

	/** @var array|null Flipped list of known tags. */
	private $known_tags = null;

	public function __construct( array $registry ) {
		$this->registry = $registry;
		$this->rules    = self::rules();
	}

	/**
	 * Default rule set, filterable.
	 *
	 * @return Rule[]
	 */
	public static function rules() {
		$rules = array(
			new Rules\Rule_Manual_Override(),
			new Rules\Rule_Us_Native_Element(),
			new Rules\Rule_Wpbakery_Handler(),
			new Rules\Rule_Wpbakery_Dependent_Addon(),
			new Rules\Rule_Known_Wpbakery_Unregistered(),
			new Rules\Rule_Wordpress_Core(),
			new Rules\Rule_Third_Party(),
			new Rules\Rule_Unregistered(),
		);

		/**
		 * Filter the classification rules.
		 *
		 * @param Rule[] $rules
		 */
		$rules = array_filter(
			(array) apply_filters( 'ics_classification_rules', $rules ),
			static function ( $rule ) {
				return $rule instanceof Rule;
			}
		);

		usort(
			$rules,
			static function ( Rule $a, Rule $b ) {
				return $a->priority() <=> $b->priority();
			}
		);
		return $rules;
	}

	/**
	 * Is a tag registered in any inspected runtime?
	 */
	public function is_registered( $tag ) {
		return isset( $this->registry['tags'][ $tag ] );
	}

	/**
	 * Is a tag known at all — registered, an Impreza element, a known WPBakery element or a known
	 * WPBakery-dependent add-on tag? Known tags are counted even without attributes, e.g. [vc_empty_space].
	 */
	public function is_known( $tag ) {
		if ( $this->is_registered( $tag ) ) {
			return true;
		}
		if ( null === $this->known_tags ) {
			$known = array_merge(
				array_keys( Environment::us_theme_element_tags() ),
				Environment::known_wpbakery_tags()['tags']
			);
			foreach ( Environment::wpbakery_dependent_addons() as $addon ) {
				$known = array_merge( $known, isset( $addon['tags'] ) ? (array) $addon['tags'] : array() );
			}
			$this->known_tags = array_flip( $known );
		}
		return isset( $this->known_tags[ $tag ] );
	}

	/**
	 * Classify one tag.
	 */
	public function classify( $tag ) {
		if ( isset( $this->cache[ $tag ] ) ) {
			return $this->cache[ $tag ];
		}

		$ctx     = Tag_Context::build( $tag, $this->registry );
		$verdict = null;

		foreach ( $this->rules as $rule ) {
			$result = $rule->evaluate( $ctx );
			if ( $result instanceof Verdict ) {
				$verdict          = $result;
				$verdict->rule_id = $rule->id();
				break;
			}
		}

		if ( ! $verdict ) {
			$verdict          = new Verdict(
				Verdict::UNKNOWN,
				__( 'No rule could classify this shortcode reliably.', 'impreza-compatibility-scanner' ),
				array( $ctx->registry_evidence() )
			);
			$verdict->rule_id = 'fallback';
		}

		if ( '' === $verdict->handler ) {
			$verdict->handler = $ctx->handler_text();
		}

		/**
		 * Filter a tag verdict.
		 *
		 * @param Verdict     $verdict
		 * @param Tag_Context $ctx
		 */
		$filtered = apply_filters( 'ics_tag_verdict', $verdict, $ctx );
		if ( $filtered instanceof Verdict ) {
			$verdict = $filtered;
		}

		$this->cache[ $tag ] = $verdict;
		return $verdict;
	}
}
