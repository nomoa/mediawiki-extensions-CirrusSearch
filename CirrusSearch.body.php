<?php
/**
 * Implementation of core search features in Solr
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
class CirrusSearch extends SearchEngine {
	/**
	 * Singleton instance of the client
	 *
	 * @var \Elastica\Index
	 */
	private static $index = null;

	/**
	 * Fetch the Elastica Index.
	 *
	 * @return \Elastica\Index
	 */
	public static function getIndex() {
		if ( self::$index != null ) {
			return self::$index;
		}
		global $wgCirrusSearchServers, $wgCirrusSearchMaxRetries;

		// Setup the Elastica endpoints
		$servers = array();
		foreach ( $wgCirrusSearchServers as $server ) {
			$servers[] = array('host' => $server);
		}
		$client = new \Elastica\Client( array(
			'servers' => $servers
		) );
		self::$index = $client->getIndex( wfWikiId() );

		return self::$index;
	}

	/**
	 * Fetch the Elastica Type for pages.
	 *
	 * @ \Elastica\Type
	 */
	static function getPageType() {
		return CirrusSearch::getIndex()->getType('page');
	}

	public static function prefixSearch( $ns, $search, $limit, &$results ) {
		wfDebugLog( 'CirrusSearch', "Prefix searching:  $search" );
		// Boilerplate
		$nsNames = RequestContext::getMain()->getLanguage()->getNamespaces();
		$query = new Elastica\Query();
		$query->setFields( array( 'id', 'title', 'namespace' ) );

		// Query params
		$query->setLimit( $limit );
		$query->setFilter( CirrusSearch::buildNamespaceFilter( $ns ) );
		// TODO should prefix searches use term based edge ngrams like with did with solr?
		// TODO remove the strtolower and let elasticsearch do it because it is a tokenizing beast
		$query->setQuery( new \Elastica\Query\Prefix( array( 'title' => strtolower( $search ) ) ) );

		// Perform the search
		try {
			$result = CirrusSearch::getPageType()->search( $query );
			wfDebugLog( 'CirrusSearch', 'Search completed in ' . $result->getTotalTime() . ' millis' );
		} catch ( \Elastica\Exception\ResponseException $e ) {
			wfLogWarning( "Search backend error during title prefix search for '$search'." );
			return false;
		}

		// We only care about title results
		foreach( $result->getResults() as $r ) {
			$results[] = Title::makeTitle( $r->namespace, $r->title )->getPrefixedText();
		}

		return false;
	}

	public function searchText( $term ) {
		wfDebugLog( 'CirrusSearch', "Searching:  $term" );
		
		$originalTerm = $term;

		// Ignore leading ~ because it is used to force displaying search results but not to effect them
		if ( substr( $term, 0, 1 ) === '~' )  {
			$term = substr( $term, 1 );
		}

		// Boilerplate
		$query = new Elastica\Query();
		$query->setFields( array( 'id', 'title', 'namespace' ) );

		$filters = array();

		// Offset/limit
		if( $this->offset ) {
			$query->setFrom( $this->offset );
		}
		if( $this->limit ) {
			$query->setLimit( $this->limit );
		}
		$filters[] = CirrusSearch::buildNamespaceFilter( $this->namespaces );

		$term = preg_replace_callback(
			'/(?<key>[^ ]+):(?<value>(?:"[^"]+")|(?:[^ ]+)) ?/',
			function ( $matches ) use ( &$filters ) {
				$key = $matches['key'];
				$value = trim( $matches['value'], '"' );
				switch ( $key ) {
					case 'incategory':
						$filters[] = new \Elastica\Filter\Query( new \Elastica\Query\Field(
							'category', CirrusSearch::escapeQueryString( $value ) ) );
						return '';
					case 'prefix':
						// TODO should prefix searches use term based edge ngrams like with did with solr?
						$filter = new \Elastica\Filter\Bool();
						$filter->addShould( new \Elastica\Filter\Prefix( 'title', $value ) );
						$filter->addShould( new \Elastica\Filter\Prefix( 'text', $value ) );
						$filters[] = $filter;
						// TODO I used to add highlighting here for $value*
						return '';
					case 'intitle':
						$filters[] = new \Elastica\Filter\Query( new \Elastica\Query\Field(
							'title', CirrusSearch::escapeQueryString( $value ) ) );
						// TODO I used to add highlighting here for $value
						return '';
					default:
						return $matches[0];
				}
			},
			$term
		);

		// This seems out of the style of the rest of the Elastica....
		$query->setHighlight( array( 
			'pre_tags' => array( '<span class="searchmatch">' ),
			'post_tags' => array( '</span>' ),
			'fields' => array(
				'title' => array( 'number_of_fragments' => 0 ), // Don't fragment the title - it is too small.
				'text' => array( 'number_of_fragments' => 1 )
			)
		) );

		$filters = array_filter( $filters ); // Remove nulls from $fitlers
		if ( count( $filters ) > 1 ) {
			$mainFilter = new \Elastica\Filter\Bool();
			foreach ( $filters as $filter ) {
				$mainFilter->addMust( $filter );
			}
			$query->setFilter( $mainFilter );
		} else if ( count( $filters ) === 1 ) {
			$query->setFilter( $filters[0] );
		}

		// Actual text query
		if ( trim( $term ) !== '' ) {
			$queryStringQuery = new \Elastica\Query\QueryString( CirrusSearch::escapeQueryString( $term ) );
			$queryStringQuery->setFields( array( 'title^20.0', 'text^3.0' ) );
			$queryStringQuery->setAutoGeneratePhraseQueries( true );
			$queryStringQuery->setPhraseSlop( 3 );
			// TODO phrase match boosts?
			$query->setQuery( $queryStringQuery );
			// TODO spellcheck
		}

		// Perform the search and return a result set
		try {
			$result = CirrusSearch::getPageType()->search( $query );
			wfDebugLog( 'CirrusSearch', 'Search completed in ' . $result->getTotalTime() . ' millis' );
			return new CirrusSearchResultSet( $result );
		} catch ( \Elastica\Exception\ResponseException $e ) {
			$status = new Status();
			$status->warning( 'cirrussearch-backend-error' );
			wfLogWarning( "Search backend error during full text search for '$originalTerm'." );
			return $status;
		}
	}

	/**
	 * Filter a query to only return results in given namespace(s)
	 *
	 * @param array $ns Array of namespaces
	 */
	private static function buildNamespaceFilter( array $ns ) {
		if ( $ns !== null && count( $ns ) ) {
			return new \Elastica\Filter\Terms( 'namespace', $ns );
		}
		return null;
	}

	/**
	 * Escape some special characters that we don't want users to pass into query strings directly.
	 * These special characters _aren't_ escaped: * and ~
	 * *: Do a prefix or postfix search against the stemmed text which isn't strictly a good
	 * idea but this is so rarely used that adding extra code to flip prefix searches into
	 * real prefix searches isn't really worth it.  The same goes for postfix searches but
	 * doubly because we don't have a postfix index (backwards ngram.)
	 * ~: Do a fuzzy match against the stemmed text which isn't strictly a good idea but it
	 * gets the job done and fuzzy matches are a really rarely used feature to be creating an
	 * extra index for.
	 */
	public static function escapeQueryString( $string ) {
		return preg_replace ( '/(\+|-|&&|\|\||!|\(|\)|\{|}|\[|]|\^|"|\?|:|\\\)/', '\\\$1', $string );
	}

	public function update( $id, $title, $text ) {
		CirrusSearchUpdater::updateRevisions( array( array(
			'rev' => Revision::loadFromPageId( wfGetDB( DB_SLAVE ), $id ),
			'text' => $text
		) ) );
	}

	public function updateTitle( $id, $title ) {
		$this->update( $id, $title, null );
	}

	public function delete( $id, $title ) {
		CirrusSearchUpdater::deletePages( array( $id ) );
	}

	public function getTextFromContent( Title $t, Content $c = null ) {
		$text = parent::getTextFromContent( $t, $c );
		if( $c ) {
			switch ( $c->getModel() ) {
				case CONTENT_MODEL_WIKITEXT:
					// @todo Possibly strip other templates here?
					// See TODO document
					$article = new Article( $t, 0 );
					$text = $article->getParserOutput()->getText();
					break;
				default:
					$text = SearchUpdate::updateText( $text );
					break;
			}
		}
		return $text;
	}
}

/**
 * A set of results for Solr
 */
class CirrusSearchResultSet extends SearchResultSet {
	private $result, $docs, $hits, $totalHits, $suggestionQuery, $suggestionSnippet;

	public function __construct( $res ) {
		$this->result = $res;
		$this->hits = $res->count();
		$this->totalHits = $res->getTotalHits();
		// $spellcheck = $res->getSpellcheck();
		$this->suggestionQuery = null;
		$this->suggestionSnippet = null;
		// if ( $spellcheck !== null && !$spellcheck->getCorrectlySpelled()  ) {
		// 	$collation = $spellcheck->getCollation();
		// 	if ( $collation !== null ) {
		// 		$this->suggestionQuery = $collation->getQuery();
		// 		$keys = array();
		// 		$highlightedKeys = array();
		// 		foreach ( $collation->getCorrections() as $misspelling => $correction ) {
		// 			// Oddly Solr will sometimes claim that a word is misspelled and then not provide a better spelling for it.
		// 			if ( $misspelling === $correction ) {
		// 				continue;
		// 			}
		// 			// TODO escaping danger
		// 			$keys[] = "/$correction/";
		// 			$highlightedKeys[] = "<em>$correction</em>";
		// 		}
		// 		$this->suggestionSnippet = preg_replace( $keys, $highlightedKeys, $this->suggestionQuery );
		// 	}
		// }
	}

	public function hasResults() {
		return $this->totalHits > 0;
	}

	public function getTotalHits() {
		return $this->totalHits;
	}

	public function numRows() {
		return $this->hits;
	}

	public function hasSuggestion() {
		return $this->suggestionQuery !== null;
	}

	public function getSuggestionQuery() {
		return $this->suggestionQuery;
	}

	public function getSuggestionSnippet() {
		return $this->suggestionSnippet;
	}

	public function next() {
		$current = $this->result->current();
		if ( $current ) {
			$this->result->next();
			return new CirrusSearchResult( $current );	
		}
		return false;
	}
}

/**
 * An individual search result for Solr
 */
class CirrusSearchResult extends SearchResult {
	private $titleSnippet, $textSnippet;

	public function __construct( $result ) {
		$this->initFromTitle( Title::makeTitle( $result->namespace, $result->title ) );
		$highlights = $result->getHighlights();
		if ( isset( $highlights[ 'title' ] ) ) {
			// @todo: This should also show the namespace, we know it
			$this->titleSnippet = $highlights[ 'title' ][ 0 ];
		} else {
			$this->titleSnippet = '';
		}
		if ( isset( $highlights[ 'text' ] ) ) {
			$this->textSnippet = $highlights[ 'text' ][ 0 ];
		} else {
			list( $contextLines, $contextChars ) = SearchEngine::userHighlightPrefs();
			$this->initText();
			// This is kind of lame because it only is nice for space delimited languages
			$matches = array();
			$text = Sanitizer::stripAllTags( $this->mText );
			if ( preg_match( "/^((?:.|\n){0,$contextChars})[\\s\\.\n]/", $text, $matches ) ) {
				$text = $matches[1];
			}
			$this->textSnippet = implode( "\n", array_slice( explode( "\n", $text ), 0, $contextLines ) );
		}
	}

	public function getTitleSnippet( $terms ) {
		return $this->titleSnippet;
	}

	public function getTextSnippet( $terms ) {
		return $this->textSnippet;
	}
}
