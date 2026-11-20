<?php
/**
 * Classification rule contract.
 *
 * A rule inspects a Tag_Context and either returns a Verdict (it decided) or null (not applicable).
 * Rules run in ascending priority order; the first verdict wins.
 *
 * Register custom rules with the 'ics_classification_rules' filter.
 *
 * @package ImprezaCompatibilityScanner
 */

namespace ImprezaCompatScanner\Rules;

use ImprezaCompatScanner\Tag_Context;
use ImprezaCompatScanner\Verdict;

defined( 'ABSPATH' ) || exit;

interface Rule {

	/**
	 * Unique, stable ID (stored with each verdict).
	 *
	 * @return string
	 */
	public function id();

	/**
	 * Lower runs first.
	 *
	 * @return int
	 */
	public function priority();

	/**
	 * @param Tag_Context $ctx
	 * @return Verdict|null
	 */
	public function evaluate( Tag_Context $ctx );
}
