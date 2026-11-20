<?php
/**
 * Value object: the classification of one shortcode tag, including the evidence behind it.
 *
 * @package ImprezaCompatibilityScanner
 */

namespace ImprezaCompatScanner;

defined( 'ABSPATH' ) || exit;

class Verdict {

	const COMPATIBLE   = 'compatible';
	const INCOMPATIBLE = 'incompatible';
	const UNKNOWN      = 'unknown';
	const UNREGISTERED = 'unregistered';

	const CLASSIFICATIONS = array( self::COMPATIBLE, self::INCOMPATIBLE, self::UNKNOWN, self::UNREGISTERED );

	/** Tri-state values for the two separate facts we report. */
	const YES     = 'yes';
	const NO      = 'no';
	const NOT_SURE = 'unknown';

	/** @var string One of the CLASSIFICATIONS. */
	public $classification = self::UNKNOWN;

	/** @var string One-sentence explanation shown in tables and CSV. */
	public $reason = '';

	/** @var string[] Individual facts that led to the decision. */
	public $evidence = array();

	/** @var string Does the shortcode render without WPBakery? yes / no / unknown. */
	public $renders_without_wpb = self::NOT_SURE;

	/** @var string Is it a native, editable Impreza Live Builder element? yes / no / unknown. */
	public $lb_editable = self::NOT_SURE;

	/** @var string Human readable handler description. */
	public $handler = '';

	/** @var string ID of the rule that decided. */
	public $rule_id = '';

	public function __construct( $classification, $reason, array $evidence = array() ) {
		$this->classification = in_array( $classification, self::CLASSIFICATIONS, true ) ? $classification : self::UNKNOWN;
		$this->reason         = (string) $reason;
		$this->evidence       = $evidence;
	}

	/**
	 * Fluent setter for the two facts.
	 */
	public function facts( $renders_without_wpb, $lb_editable ) {
		$this->renders_without_wpb = $renders_without_wpb;
		$this->lb_editable         = $lb_editable;
		return $this;
	}

	public static function from_row( array $row ) {
		$evidence = json_decode( (string) $row['evidence'], true );
		$v        = new self( $row['classification'], $row['reason'], is_array( $evidence ) ? $evidence : array() );

		$v->renders_without_wpb = $row['renders_without_wpb'];
		$v->lb_editable         = $row['lb_editable'];
		$v->handler             = $row['handler'];
		$v->rule_id             = $row['rule_id'];
		return $v;
	}

	/**
	 * Translated label for a classification key.
	 */
	public static function label( $classification ) {
		switch ( $classification ) {
			case self::COMPATIBLE:
				return __( 'Compatible', 'impreza-compatibility-scanner' );
			case self::INCOMPATIBLE:
				return __( 'Incompatible', 'impreza-compatibility-scanner' );
			case self::UNREGISTERED:
				return __( 'Unregistered', 'impreza-compatibility-scanner' );
			default:
				return __( 'Unknown', 'impreza-compatibility-scanner' );
		}
	}

	/**
	 * Translated label for a tri-state fact.
	 */
	public static function fact_label( $value ) {
		switch ( $value ) {
			case self::YES:
				return __( 'Yes', 'impreza-compatibility-scanner' );
			case self::NO:
				return __( 'No', 'impreza-compatibility-scanner' );
			default:
				return __( 'Not verified', 'impreza-compatibility-scanner' );
		}
	}
}
