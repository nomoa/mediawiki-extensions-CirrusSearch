<?php

namespace CirrusSearch;

interface InterwikiResolver {
	const SERVICE = 'CirrusSearchInterwikiresolver';
	/**
	 * @return string[] of wikiIds indexed by interwiki prefix
	 */
	public function getSisterProjectPrefixes();

	/**
	 * @return string|null the interwiki identified for this $wikiId or null if none found
	 */
	public function getInterwikiPrefix( $wikiId );

	/**
	 * Determine the proper interwiki_prefix <=> wikiId pair for a given language code.
	 * Most the time the language code is equals to interwiki prefix but in
	 * some rarer cases it's not true. Always use the interwiki prefix returned by this function
	 * to generate crosslanguage interwiki links.
	 *
	 * @return string[] a single elt array [ 'iw_prefix' => 'wikiId' ] or [] if none found
	 */
	public function getSameProjectWikiByLang( $lang );
}
