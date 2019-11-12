<?php

namespace CirrusSearch\Search;

use CirrusSearch\CirrusTestCase;
use Elastica\Query\AbstractQuery;
use Elastica\Query\BoolQuery;
use Elastica\Query\Script;
use Elastica\Query\Term;
use InvalidArgumentException;

/**
 * Test for filter utilities.
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
 *
 * @group CirrusSearch
 * @covers \CirrusSearch\Search\Filters
 */
class FiltersTest extends CirrusTestCase {
	/**
	 * @dataProvider unifyTestCases
	 */
	public function testUnify( $expected, $mustFilters, $mustNotFilters ) {
		if ( !is_array( $mustFilters ) ) {
			$mustFilters = [ $mustFilters ];
		}
		if ( !is_array( $mustNotFilters ) ) {
			$mustNotFilters = [ $mustNotFilters ];
		}
		$this->assertEquals( $expected, Filters::unify( $mustFilters, $mustNotFilters ) );
	}

	public static function unifyTestCases() {
		$scriptOne = new Script( 'dummy1' );
		$scriptTwo = new Script( 'dummy2' );
		$scriptThree = new Script( 'dummy3' );
		$foo = new Term( [ 'test' => 'foo' ] );
		$bar = new Term( [ 'test' => 'bar' ] );
		$baz = new Term( [ 'test' => 'baz' ] );

		$notScriptOne = new BoolQuery();
		$notScriptOne->addMustNot( $scriptOne );
		$notScriptThree = new BoolQuery();
		$notScriptThree->addMustNot( $scriptThree );
		$notFoo = new BoolQuery();
		$notFoo->addMustNot( $foo );

		return [
			'empty input gives empty output' => [ null, [], [] ],
			'a single must script returns itself' => [ $scriptOne, $scriptOne, [] ],
			'a single must not script returns bool mustNot' => [ $notScriptOne, [], $scriptOne ],
			'a single must query returns itself' => [ $foo, $foo, [] ],
			'a single must not query return bool mustNot' => [ $notFoo, [], $foo ],
			'multiple must return bool must' => [
				self::newBool( [ $foo, $bar ], [] ),
				[ $foo, $bar ],
				[]
			],
			'multiple must not' => [
				self::newBool( [], [ $foo, $bar ] ),
				[],
				[ $foo, $bar ],
			],
			'must and multiple must not' => [
				self::newBool( [ $baz ], [ $foo, $bar ] ),
				[ $baz ],
				[ $foo, $bar ],
			],
			'must and multiple must not with a filtered script' => [
				self::newAnd(
					self::newBool( [ $baz ], [ $foo, $bar ] ),
					$scriptOne
				),
				[ $scriptOne, $baz ],
				[ $foo, $bar ],
			],
			'must and multiple must not with multiple filtered scripts' => [
				self::newAnd(
					self::newBool( [ $baz ], [ $foo, $bar ] ),
					$scriptOne,
					$scriptTwo,
					$notScriptThree
				),
				[ $scriptOne, $baz, $scriptTwo ],
				[ $foo, $scriptThree, $bar ],
			],
		];
	}

	/**
	 * Convenient helper for building bool filters.
	 * @param AbstractQuery|AbstractQuery[] $must must filters
	 * @param AbstractQuery|AbstractQuery[] $mustNot must not filters
	 * @return BoolQuery a bool filter containing $must and $mustNot
	 */
	private static function newBool( $must, $mustNot ) {
		$bool = new BoolQuery();
		if ( is_array( $must ) ) {
			foreach ( $must as $m ) {
				$bool->addMust( $m );
			}
		} else {
			$bool->addMust( $must );
		}
		if ( is_array( $mustNot ) ) {
			foreach ( $mustNot as $m ) {
				$bool->addMustNot( $m );
			}
		} else {
			$bool->addMustNot( $mustNot );
		}

		return $bool;
	}

	private static function newAnd( /* args */ ) {
		$and = new BoolQuery();
		foreach ( func_get_args() as $query ) {
			$and->addFilter( $query );
		}
		return $and;
	}

	/**
	 * @covers \CirrusSearch\Search\Filters::bagOfWordsFilter
	 * @covers \CirrusSearch\Search\Filters::bagOfWordsFilterOverAllField
	 * @covers \CirrusSearch\Search\Filters::titleConstrainedBagOfWordsFilterOverAllField
	 * @dataProvider provideTesBagOfWordsFilter
	 */
	public function testBagOfWordsFilter( array $profile, string $query, array $expectedQuery ) {
		$this->assertEquals( $expectedQuery, Filters::bagOfWordsFilter( $profile, $query )->toArray() );
	}

	public function provideTesBagOfWordsFilter(): array {
		return [
			'simple all filter' => [
				[ 'type' => 'default' ],
				'foo bar',
				[
					'bool' => [
						'should' => [
							[ 'match' => [
								'all' => [
									'query' => 'foo bar',
									'operator' => 'AND'
								]
							] ],
							[ 'match' => [
								'all.plain' => [
									'query' => 'foo bar',
									'operator' => 'AND'
								]
							] ],
						]
					]
				]
			],
			'simple all filter with min_should_match' => [
				[
					'type' => 'default',
					'settings' => [ 'all.plain' => [ 'minimum_should_match' => '80%' ], 'all' => [ 'minimum_should_match' => '90%' ] ]
				],
				'foo bar',
				[
					'bool' => [
						'should' => [
							[ 'match' => [
								'all' => [
									'query' => 'foo bar',
									'minimum_should_match' => '90%',
								]
							] ],
							[ 'match' => [
								'all.plain' => [
									'query' => 'foo bar',
									'minimum_should_match' => '80%',
								]
							] ],
						]
					]
				]
			],
			'simple title constrained all filter' => [
				[ 'type' => 'constrain_title' ],
				'foo bar',
				[
					'bool' => [
						'must' => [
							[ 'bool' => [
								'should' => [
									[ 'match' => [
										'all' => [
											'query' => 'foo bar',
											'operator' => 'AND'
										]
									] ],
									[ 'match' => [
										'all.plain' => [
											'query' => 'foo bar',
											'operator' => 'AND'
										]
									] ],
								]
							] ],
							[ 'bool' => [
								'should' => [
									[ 'match' => [
										'title' => [
											'query' => 'foo bar',
											'minimum_should_match' => '3<80%'
										]
									] ],
									[ 'match' => [
											'redirect.title' => [
												'query' => 'foo bar',
												'minimum_should_match' => '3<80%'
											]
									] ]
								]
							] ]
						]
					]
				]
			],
			'simple title constrained all filter (tuned)' => [
				[
					'type' => 'constrain_title',
					'settings' => [
						'minimum_should_match' => '70%',
						'all.plain' => [ 'minimum_should_match' => '80%' ],
						'all' => [ 'minimum_should_match' => '90%' ]
					]
				],
				'foo bar',
				[
					'bool' => [
						'must' => [
							[ 'bool' => [
								'should' => [
									[ 'match' => [
										'all' => [
											'query' => 'foo bar',
											'minimum_should_match' => '90%'
										]
									] ],
									[ 'match' => [
										'all.plain' => [
											'query' => 'foo bar',
											'minimum_should_match' => '80%'
										]
									] ],
								]
							] ],
							[ 'bool' => [
								'should' => [
									[ 'match' => [
										'title' => [
											'query' => 'foo bar',
											'minimum_should_match' => '70%'
										]
									] ],
									[ 'match' => [
										'redirect.title' => [
											'query' => 'foo bar',
											'minimum_should_match' => '70%'
										]
									] ]
								]
							] ]
						]
					]
				]
			]
		];
	}

	public function testBagOfWordsFilterInvalidProfile() {
		try {
			Filters::bagOfWordsFilter( [], 'foo' );
			$this->fail( "Expecting InvalidArgumentException when 'type' is omitted" );
		} catch ( InvalidArgumentException $e ) {
		}

		try {
			Filters::bagOfWordsFilter( [ 'type' => 'unknown' ], 'foo' );
			$this->fail( "Expecting InvalidArgumentException when 'type' is unknown" );
		} catch ( InvalidArgumentException $e ) {
		}
	}

	/**
	 * @dataProvider provideTestPhrase
	 * @covers \CirrusSearch\Search\Filters::phrase
	 */
	public function testPhrase( $query, $useStem, $slop, array $expected ) {
		$this->assertEquals( $expected, Filters::phrase( $query, $useStem, $slop )->toArray() );
	}

	public function provideTestPhrase() {
		return [
			'simple' => [
				'foo bar', false, 1,
				[
					'match_phrase' => [
						'all.plain' => [
							'query' => 'foo bar',
							'slop' => 1
						]
					]
				]
			],
			'stemmed (' => [
				'foo bar', true, 2,
				[
					'match_phrase' => [
						'all' => [
							'query' => 'foo bar',
							'slop' => 2
						]
					]
				]
			],
		];
	}

	/**
	 * @dataProvider provideTestPhrasePrefix
	 * @covers \CirrusSearch\Search\Filters::phrasePrefix
	 */
	public function testPhrasePrefix( string $query, array $expected ) {
		$this->assertEquals( $expected, Filters::phrasePrefix( $query )->toArray() );
	}

	/**
	 * @return array
	 */
	public function provideTestPhrasePrefix() {
		return [
			'simple' => [
				'foo bar',
				[
					'match_phrase_prefix' => [
						'all.plain' => [
							'query' => 'foo bar',
						]
					]
				]
			]
		];
	}

	/**
	 * @dataProvider provideTestFuzzyQuery
	 * @covers \CirrusSearch\Search\Filters::fuzzy
	 */
	public function testFuzzyQuery( string $query, ?int $fuzziness, array $expected ) {
		$this->assertEquals( $expected, Filters::fuzzy( $query, $fuzziness )->toArray() );
	}

	public function provideTestFuzzyQuery() {
		return [
			'simple' => [
				'foo', null,
				[
					'fuzzy' => [
						'all.plain' => [
							'fuzziness' => 'AUTO',
							'prefix_length' => 2,
							'value' => 'foo'
						],
					]
				],
				'foo', 2,
				[
					'fuzzy' => [
						'all.plain' => [
							'fuzziness' => '2',
							'prefix_length' => 2,
							'value' => 'foo'
						]
					],
				]
			]
		];
	}

	/**
	 * @dataProvider provideTestPrefixQuery
	 * @covers \CirrusSearch\Search\Filters::prefix
	 */
	public function testPrefixQuery( string $query, ?string $field, array $expected ) {
		$actual = $field !== null ? Filters::prefix( $query, $field ) : Filters::prefix( $query );
		$this->assertEquals( $expected, $actual->toArray() );
	}

	public function provideTestPrefixQuery() {
		return [
			'simple' => [
				'foo', null,
				[
					'prefix' => [
						'all.plain' => [
							'value' => 'foo',
							'rewrite' => 'top_terms_boost_1024'
						],
					]
				],
				'foo', 'title.plain',
				[
					'prefix' => [
						'title.plain' => [
							'value' => 'foo',
							'rewrite' => 'top_terms_boost_1024'
						],
					]
				],
			]
		];
	}
}
