<?php

namespace CirrusSearch\Search;

use Elastica\Query\AbstractQuery;
use Elastica\Query\BoolQuery;
use Elastica\Query\Fuzzy;
use Elastica\Query\MatchAll;
use Elastica\Query\MatchPhrase;
use Elastica\Query\MatchPhrasePrefix;
use Elastica\Query\Prefix;
use Elastica\Query\Wildcard;
use Wikimedia\Assert\Assert;
use function Eris\Generator\string;

/**
 * Utilities for dealing with filters.
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
class Filters {
	/**
	 * Used by multiterm queries (prefix, wildcard and fuzzy)
	 * see https://www.elastic.co/guide/en/elasticsearch/reference/current/query-dsl-multi-term-rewrite.html
	 * (beware to only choose options that do not explode on the max boolean clauses exception)
	 */
	const MULTITERM_QUERY_REWRITE = 'top_terms_boost_1024';

	/**
	 * Turns a list of queries into a boolean OR, requiring only one
	 * of the provided queries to match.
	 *
	 * @param AbstractQuery[] $queries
	 * @param bool $matchAll When true (default) function never returns null,
	 *  when no queries are provided a MatchAll is returned.
	 * @return AbstractQuery|null The resulting OR query. Only returns null when
	 *  no queries are passed and $matchAll is false.
	 */
	public static function booleanOr( array $queries, $matchAll = true ) {
		if ( !$queries ) {
			return $matchAll ? new MatchAll() : null;
		} elseif ( count( $queries ) === 1 ) {
			return reset( $queries );
		} else {
			$bool = new BoolQuery();
			foreach ( $queries as $query ) {
				$bool->addShould( $query );
			}
			return $bool;
		}
	}

	/**
	 * Merges lists of include/exclude filters into a single filter that
	 * Elasticsearch will execute efficiently.
	 *
	 * @param AbstractQuery[] $mustFilters filters that must match all returned documents
	 * @param AbstractQuery[] $mustNotFilters filters that must not match all returned documents
	 * @return null|AbstractQuery null if there are no filters or one that will execute
	 *     all of the provided filters
	 */
	public static function unify( array $mustFilters, array $mustNotFilters ) {
		// We want to make sure that we execute script filters last.  So we do these steps:
		// 1.  Strip script filters from $must and $mustNot.
		// 2.  Unify the non-script filters.
		// 3.  Build a BoolAnd filter out of the script filters if there are any.
		$scriptFilters = [];
		$nonScriptMust = [];
		$nonScriptMustNot = [];
		foreach ( $mustFilters as $must ) {
			if ( $must->hasParam( 'script' ) ) {
				$scriptFilters[] = $must;
			} else {
				$nonScriptMust[] = $must;
			}
		}
		$scriptMustNotFilter = new BoolQuery();
		foreach ( $mustNotFilters as $mustNot ) {
			if ( $mustNot->hasParam( 'script' ) ) {
				$scriptMustNotFilter->addMustNot( $mustNot );
			} else {
				$nonScriptMustNot[] = $mustNot;
			}
		}
		if ( $scriptMustNotFilter->hasParam( 'must_not' ) ) {
			$scriptFilters[] = $scriptMustNotFilter;
		}

		$nonScript = self::unifyNonScript( $nonScriptMust, $nonScriptMustNot );
		$scriptFiltersCount = count( $scriptFilters );
		if ( $scriptFiltersCount === 0 ) {
			return $nonScript;
		}

		$bool = new BoolQuery();
		if ( $nonScript === null ) {
			if ( $scriptFiltersCount === 1 ) {
				return $scriptFilters[ 0 ];
			}
		} else {
			$bool->addFilter( $nonScript );
		}
		foreach ( $scriptFilters as $scriptFilter ) {
			$bool->addFilter( $scriptFilter );
		}
		return $bool;
	}

	/**
	 * Unify non-script filters into a single filter.
	 *
	 * @param AbstractQuery[] $mustFilters filters that must be found
	 * @param AbstractQuery[] $mustNotFilters filters that must not be found
	 * @return null|AbstractQuery null if there are no filters or one that will execute
	 *     all of the provided filters
	 */
	private static function unifyNonScript( array $mustFilters, array $mustNotFilters ) {
		$mustFilterCount = count( $mustFilters );
		$mustNotFilterCount = count( $mustNotFilters );
		if ( $mustFilterCount + $mustNotFilterCount === 0 ) {
			return null;
		}
		if ( $mustFilterCount === 1 && $mustNotFilterCount == 0 ) {
			return $mustFilters[ 0 ];
		}
		$bool = new BoolQuery();
		foreach ( $mustFilters as $must ) {
			$bool->addMust( $must );
		}
		foreach ( $mustNotFilters as $mustNot ) {
			$bool->addMustNot( $mustNot );
		}
		return $bool;
	}

	/**
	 * Create a query for insource: queries. This function is pure, deferring
	 * state changes to the reference-updating return function.
	 *
	 * @param Escaper $escaper
	 * @param string $value
	 * @return AbstractQuery
	 */
	public static function insource( Escaper $escaper, $value ) {
		return self::insourceOrIntitle( $escaper, $value, function () {
			return [ 'source_text.plain' ];
		} );
	}

	/**
	 * Create a query for intitle: queries.
	 *
	 * @param Escaper $escaper
	 * @param string $value
	 * @return AbstractQuery
	 */
	public static function intitle( Escaper $escaper, $value ) {
		return self::insourceOrIntitle( $escaper, $value, function ( $queryString ) {
			if ( preg_match( '/[?*]/u', $queryString ) ) {
				return [ 'title.plain', 'redirect.title.plain' ];
			} else {
				return [ 'title', 'title.plain', 'redirect.title', 'redirect.title.plain' ];
			}
		} );
	}

	/**
	 * @param Escaper $escaper
	 * @param string $value
	 * @param callable $fieldF
	 * @return AbstractQuery
	 */
	private static function insourceOrIntitle( Escaper $escaper, $value, $fieldF ) {
		$queryString = $escaper->fixupWholeQueryString(
			$escaper->fixupQueryStringPart( $value ) );
		$field = $fieldF( $queryString );
		$query = new \Elastica\Query\QueryString( $queryString );
		$query->setFields( $field );
		$query->setDefaultOperator( 'AND' );
		$query->setAllowLeadingWildcard( $escaper->getAllowLeadingWildcard() );
		$query->setFuzzyPrefixLength( 2 );
		$query->setRewrite( self::MULTITERM_QUERY_REWRITE );

		return $query;
	}

	/**
	 * Builds a simple filter on all and all.plain when all terms must match
	 *
	 * @param array[] $options array containing filter options
	 * @param string $query
	 * @return AbstractQuery
	 */
	public static function bagOfWordsFilterOverAllField( array $options, string $query ): AbstractQuery {
		$filter = new \Elastica\Query\BoolQuery();
		// FIXME: We can't use solely the stem field here
		// - Depending on languages it may lack stopwords,
		// A dedicated field used for filtering would be nice
		foreach ( [ 'all', 'all.plain' ] as $field ) {
			$m = new \Elastica\Query\Match();
			$m->setFieldQuery( $field, $query );
			$minShouldMatch = '100%';
			if ( isset( $options['settings'][$field]['minimum_should_match'] ) ) {
				$minShouldMatch = $options['settings'][$field]['minimum_should_match'];
			}
			if ( $minShouldMatch === '100%' ) {
				$m->setFieldOperator( $field, 'AND' );
			} else {
				$m->setFieldMinimumShouldMatch( $field, $minShouldMatch );
			}
			$filter->addShould( $m );
		}
		return $filter;
	}

	/**
	 * Builds a simple filter based on buildSimpleAllFilter + a constraint
	 * on title/redirect :
	 * (all:query OR all.plain:query) AND (title:query OR redirect:query)
	 * where the filter on title/redirect can be controlled by setting
	 * minimum_should_match to relax the constraint on title.
	 * (defaults to '3<80%')
	 *
	 * @param array[] $options array containing filter options
	 * @param string $query the user query
	 * @return AbstractQuery
	 */
	public static function titleConstrainedBagOfWordsFilterOverAllField( array $options, string $query ): AbstractQuery {
		$filter = new \Elastica\Query\BoolQuery();
		$filter->addMust( self::bagOfWordsFilterOverAllField( $options, $query ) );
		$minShouldMatch = '3<80%';
		if ( isset( $options['settings']['minimum_should_match'] ) ) {
			$minShouldMatch = $options['settings']['minimum_should_match'];
		}
		$titleFilter = new \Elastica\Query\BoolQuery();

		foreach ( [ 'title', 'redirect.title' ] as $field ) {
			$m = new \Elastica\Query\Match();
			$m->setFieldQuery( $field, $query );
			$m->setFieldMinimumShouldMatch( $field, $minShouldMatch );
			$titleFilter->addShould( $m );
		}
		$filter->addMust( $titleFilter );
		return $filter;
	}

	/**
	 * @param array $filterDef
	 * @param string $query
	 * @return AbstractQuery
	 */
	public static function bagOfWordsFilter( array $filterDef, string $query ): AbstractQuery {
		if ( !isset( $filterDef['type'] ) ) {
			throw new \InvalidArgumentException( "Cannot configure the filter clause, 'type' must be defined." );
		}
		$type = $filterDef['type'];

		switch ( $type ) {
			case 'default':
				$filter = self::bagOfWordsFilterOverAllField( $filterDef, $query );
				break;
			case 'constrain_title':
				$filter = self::titleConstrainedBagOfWordsFilterOverAllField( $filterDef, $query );
				break;
			default:
				throw new \InvalidArgumentException( "Cannot build the filter clause: unknown filter type $type" );
		}

		return $filter;
	}

	/**
	 * Builds a phrase query on top of the all field
	 * @param string $phrase
	 * @param bool $useStem
	 * @param int $slop
	 * @return AbstractQuery
	 */
	public static function phrase( string $phrase, bool $useStem, int $slop ): AbstractQuery {
		$field = $useStem ? 'all' : 'all.plain';
		return new MatchPhrase( $field, [ 'query' => $phrase, 'slop' => $slop ] );
	}

	/**
	 * Build a phrase_prefix query
	 * @param string $phrase
	 * @return AbstractQuery
	 */
	public static function phrasePrefix( string $phrase ): AbstractQuery {
		return new MatchPhrasePrefix( "all.plain", [ 'query' => $phrase ] );
	}

	/**
	 * Build a fuzzy match query
	 * @param string $query
	 * @param int|null $fuzziness between 0 and 2 included, null for 'AUTO'
	 * @return AbstractQuery
	 */
	public static function fuzzy( string $query, ?int $fuzziness ): AbstractQuery {
		Assert::parameter( $fuzziness == null || ( $fuzziness >= 0 && $fuzziness <= 2 ),
			'$fuzziness', 'must be null, 0, 1 or 2' );
		// TODO: verify if the change in behavior vs query_string fuzzy clause is acceptable:
		// - query_string may normalize (lowercase the term) prior to generating a fyzzy term query
		// - we may have send to all AND all.plain, here we only send to all.plain
		return ( new Fuzzy( 'all.plain', $query ) )
			->setFieldOption( 'prefix_length', 2 )
			->setFieldOption( 'fuzziness', (string)( $fuzziness ?: 'AUTO' ) );
	}

	/**
	 * @param string $prefix
	 * @param string $field
	 * @return AbstractQuery
	 */
	public static function prefix( string $prefix, string $field = 'all.plain' ): AbstractQuery {
		// TODO: verify if the change in behavior vs query_string wildcard clause is acceptable:
		// - query_string may normalize (lowercase the term) prior to generating a wildcard term query
		return new Prefix( [ $field => [
			'value' => $prefix,
			'rewrite' => self::MULTITERM_QUERY_REWRITE
		] ] );
	}

	/**
	 * @param string $wildcard
	 * @param string $field
	 * @return AbstractQuery
	 */
	public static function wildcard( string $wildcard, string $field = 'all.plain' ): AbstractQuery {
		return ( new Wildcard() )
			->setParams( [ $field => [ 'value' => $wildcard, 'rewrite' => self::MULTITERM_QUERY_REWRITE ] ] );
	}
}
