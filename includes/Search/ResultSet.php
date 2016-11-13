<?php

namespace CirrusSearch\Search;

use CirrusSearch\Searcher;
use SearchResultSet;

/**
 * A set of results from Elasticsearch.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 */
class ResultSet extends SearchResultSet {
	use TitleHelper;

	/**
	 * @var \Elastica\ResultSet
	 */
	private $result;

	/**
	 * @var int
	 */
	private $hits;

	/**
	 * @var int
	 */
	private $totalHits;

	/**
	 * @var string|null
	 */
	private $suggestionQuery;

	/**
	 * @var string|null
	 */
	private $suggestionSnippet;

	/**
	 * @var bool
	 */
	private $searchContainedSyntax;

	/**
	 * @var array
	 */
	private $interwikiResults = [];

	/**
	 * @var string|null
	 */
	private $rewrittenQuery;

	/**
	 * @var string|null
	 */
	private $rewrittenQuerySnippet;

	/**
	 * @param string[] $suggestPrefixes
	 * @param string[] $suggestSuffixes
	 * @param \Elastica\ResultSet $res
	 * @param bool $searchContainedSyntax
	 */
	public function __construct( array $suggestPrefixes, array $suggestSuffixes, \Elastica\ResultSet $res, $searchContainedSyntax ) {
		$this->result = $res;
		$this->searchContainedSyntax = $searchContainedSyntax;
		$this->hits = $res->count();
		$this->totalHits = $res->getTotalHits();
		$this->preCacheContainedTitles( $this->result );
		$suggestion = $this->findSuggestion();
		if ( $suggestion && ! $this->resultContainsFullyHighlightedMatch() ) {
			$this->suggestionQuery = $suggestion[ 'text' ];
			$this->suggestionSnippet = $this->escapeHighlightedSuggestion( $suggestion[ 'highlighted' ] );
			if ( $suggestPrefixes ) {
				$suggestPrefix = implode( ' ', $suggestPrefixes );
				// No need to escape suggestionQuery because Linker will escape it.
				$this->suggestionQuery = $suggestPrefix . $this->suggestionQuery;
				$this->suggestionSnippet = htmlspecialchars( $suggestPrefix ) . $this->suggestionSnippet;
			}
			if ( $suggestSuffixes ) {
				$suggestSuffix = implode( ' ', $suggestSuffixes );
				// No need to escape suggestionQuery because Linker will escape it.
				$this->suggestionQuery = $this->suggestionQuery . $suggestSuffix;
				$this->suggestionSnippet = $this->suggestionSnippet . htmlspecialchars( $suggestSuffix );
			}
		}
	}

	/**
	 * Is rewriting this query OK?
	 *
	 * @param int $threshold Minimum number of results to reach before rewriting is not allowed.
	 * @return bool True when rewriting this query is allowed
	 */
	public function isQueryRewriteAllowed( $threshold = 1 ) {
		if ( $this->numRows() >= $threshold || $this->searchContainedSyntax() ) {
			return false;
		}
		foreach ( $this->getInterwikiResults( SearchResultSet::SECONDARY_RESULTS ) as $resultSet ) {
			if ( $resultSet->numRows() >= $threshold ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @return string|null
	 */
	private function findSuggestion() {
		// TODO some kind of weighting?
		$suggest = $this->result->getResponse()->getData();
		if ( !isset( $suggest[ 'suggest' ] ) ) {
			return null;
		}
		$suggest = $suggest[ 'suggest' ];
		// Elasticsearch will send back the suggest element but no sub suggestion elements if the wiki is empty.
		// So we should check to see if they exist even though in normal operation they always will.
		if ( isset( $suggest[ 'suggest' ] ) ) {
			// Now just grab the first one it sent back.
			foreach ( $suggest[ 'suggest' ][ 0 ][ 'options' ] as $option ) {
				return $option;
			}
		}
		return null;
	}

	/**
	 * Escape a highlighted suggestion coming back from Elasticsearch.
	 *
	 * @param string $suggestion suggestion from elasticsearch
	 * @return string $suggestion with html escaped _except_ highlighting pre and post tags
	 */
	private function escapeHighlightedSuggestion( $suggestion ) {
		static $suggestionHighlightPreEscaped = null,
			$suggestionHighlightPostEscaped = null;
		if ( $suggestionHighlightPreEscaped === null ) {
			$suggestionHighlightPreEscaped =
				htmlspecialchars( Searcher::SUGGESTION_HIGHLIGHT_PRE );
			$suggestionHighlightPostEscaped =
				htmlspecialchars( Searcher::SUGGESTION_HIGHLIGHT_POST );
		}
		return str_replace( [ $suggestionHighlightPreEscaped, $suggestionHighlightPostEscaped ],
			[ Searcher::SUGGESTION_HIGHLIGHT_PRE, Searcher::SUGGESTION_HIGHLIGHT_POST ],
			htmlspecialchars( $suggestion ) );
	}

	/**
	 * @return bool
	 */
	private function resultContainsFullyHighlightedMatch() {
		foreach ( $this->result->getResults() as $result ) {
			$highlights = $result->getHighlights();
			// If the whole string is highlighted then return true
			if ( isset( $highlights[ 'title' ] ) &&
					!trim( preg_replace( Searcher::HIGHLIGHT_REGEX, '', $highlights[ 'title' ][ 0 ] ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @return int
	 */
	public function getTotalHits() {
		return $this->totalHits;
	}

	/**
	 * @return int
	 */
	public function numRows() {
		return $this->hits;
	}

	/**
	 * @return bool
	 */
	public function hasSuggestion() {
		return $this->suggestionQuery !== null;
	}

	/**
	 * @return string|null
	 */
	public function getSuggestionQuery() {
		return $this->suggestionQuery;
	}

	/**
	 * @return string
	 */
	public function getSuggestionSnippet() {
		return $this->suggestionSnippet;
	}

	/**
	 * @return Result|false
	 */
	public function next() {
		$current = $this->result->current();
		if ( $current ) {
			$this->result->next();
			$result = new Result( $this->result, $current );
			$this->augmentResult( $result );
			return $result;
		}
		return false;
	}

	public function rewind() {
		$this->result->rewind();
	}

	/**
	 * @param ResultSet $res
	 * @param int $type One of SearchResultSet::* constants
	 * @param string $interwiki
	 */
	public function addInterwikiResults( ResultSet $res, $type, $interwiki ) {
		$this->interwikiResults[$type][$interwiki] = $res;
	}

	/**
	 * @param int $type
	 * @return SearchResultSet[]
	 */
	public function getInterwikiResults( $type = SearchResultSet::SECONDARY_RESULTS ) {
		return isset($this->interwikiResults[$type]) ? $this->interwikiResults[$type] : [];
	}

	/**
	 * @param int $type
	 * @return bool
	 */
	public function hasInterwikiResults( $type = SearchResultSet::SECONDARY_RESULTS ) {
		return isset($this->interwikiResults[$type]);
	}

	/**
	 * @return bool
	 */
	public function searchContainedSyntax() {
		return $this->searchContainedSyntax;
	}

	/**
	 * @param string $newQuery
	 * @param string|null $newQuerySnippet
	 */
	public function setRewrittenQuery($newQuery, $newQuerySnippet=null) {
		$this->rewrittenQuery = $newQuery;
		$this->rewrittenQuerySnippet = $newQuerySnippet ?: htmlspecialchars( $newQuery );
	}

	/**
	 * @return bool
	 */
	public function hasRewrittenQuery() {
		return $this->rewrittenQuery !== null;
	}

	/**
	 * @return string|null
	 */
	public function getQueryAfterRewrite() {
		return $this->rewrittenQuery;
	}

	/**
	 * @return string|null
	 */
	public function getQueryAfterRewriteSnippet() {
		return $this->rewrittenQuerySnippet;
	}
}
