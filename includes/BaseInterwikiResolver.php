<?php

namespace CirrusSearch;

/**
 * InterwikiResolver suited for WMF context and uses SiteMatrix.
 */

abstract class BaseInterwikiResolver implements InterwikiResolver {
	private $matrix;

	/**
	 * @return string[]
	 */
	public function getSisterProjectPrefixes() {
		$matrix = $this->getMatrix();
		return isset ( $matrix['sister_projects'] ) ? $matrix['sister_projects'] : [];
	}

	/**
	 * @return string|null
	 */
	public function getInterwikiPrefix( $wikiId ) {
		$matrix = $this->getMatrix();
		return isset ( $matrix['prefixes_by_wiki'][$wikiId] ) ? $matrix['prefixes_by_wiki'][$wikiId] : null;
	}

	/**
	 * @return string[]
	 */
	public function getSameProjectWikiByLang( $lang ) {
		$matrix = $this->getMatrix();
		// Most of the time the language is equal to the interwiki prefix.
		// But it's not always the case, use the language_map to identify the interwiki prefix first.
		$lang = isset( $matrix['language_map'][$lang] ) ? $matrix['language_map'][$lang] : $lang;
		return isset( $matrix['cross_language'][$lang] ) ? [ $lang => $matrix['cross_language'][$lang] ] : [];
	}

	private function getMatrix() {
		if ( $this->matrix === null ) {
			$this->matrix = $this->loadMatrix();

		}
		return $this->matrix;
	}

	/**
	 * Load the interwiki matric information
	 * The returned array must include the following keys:
	 * - sister_projects: an array with the list of sister wikis indexed by
	 *   interwiki prefix
	 * - cross_language: an array with the list of wikis running the same
	 *   project/site indexed by interwiki prefix
	 * - language_map: an array with the list of interwiki prefixes where
	 *   where the language code of the wiki does not match the prefix
	 * - prefixes_by_wiki: an array with the list of interwiki indexed
	 *   by wikiID
	 *
	 * The result of this method is stored in the current InterwikiResolver instance
	 * so it can be called only once per request.
	 *
	 * return array[]
	 */
	protected abstract function loadMatrix();
}
