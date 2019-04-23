<?php

namespace CirrusSearch\Fallbacks;

use CirrusSearch\Search\ResultSet;

/**
 * Context storing the states of the FallbackRunner.
 * This object is populated/maintained by the FallbackRunner itself and read
 * by the FallbackMethod.
 */
interface FallbackRunnerContext {

	/**
	 * The initial resultset as returned by the main search query.
	 * @return ResultSet
	 */
	public function getInitialResultSet();

	/**
	 * The resultset as rewritten by the previous fallback method.
	 * It may be equal to getInitialResultSet() if this is accessed by the
	 * first fallback method or if it was not rewritten yet.
	 * Technically this method returns the value of the previous FallbackMethod::rewrite()
	 * @return ResultSet
	 * @see FallbackMethod::rewrite()
	 */
	public function getPreviousResultSet();
}