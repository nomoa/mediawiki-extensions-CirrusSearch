<?php

namespace CirrusSearch\Query;

use CirrusSearch\CrossSearchStrategy;
use CirrusSearch\HashSearchConfig;
use CirrusSearch\Parser\AST\KeywordFeatureNode;
use CirrusSearch\Parser\AST\NegatedNode;
use CirrusSearch\Parser\AST\ParseWarning;
use CirrusSearch\Parser\QueryStringRegex\KeywordParser;
use CirrusSearch\Parser\QueryStringRegex\OffsetTracker;
use CirrusSearch\Search\Escaper;
use CirrusSearch\Search\SearchContext;
use CirrusSearch\SearchConfig;
use Elastica\Query\AbstractQuery;

/**
 * Assertions method for testing KeywordFeature implementation.
 */
class KeywordFeatureAssertions {
	/**
	 * @var \PHPUnit\Framework\TestCase
	 */
	private $testCase;

	/**
	 * KeywordFeatureAssertions constructor.
	 * @param \MediaWikiTestCase $testCase
	 */
	public function __construct( \PHPUnit\Framework\TestCase $testCase ) {
		$this->testCase = $testCase;
	}

	/**
	 * @return SearchContext
	 */
	private function mockContext() {
		$context = $this->testCase->getMockBuilder( SearchContext::class )
			->disableOriginalConstructor()
			->getMock();
		$context->expects( $this->testCase->any() )->method( 'getConfig' )->willReturn( new SearchConfig() );
		$context->expects( $this->testCase->any() )->method( 'escaper' )->willReturn( new Escaper( 'en', true ) );

		return $context;
	}
	/**
	 * @param null $expectedQuery
	 * @param array|callback|null $warnings
	 * @param bool $negated
	 * @return SearchContext
	 */
	private function mockContextExpectingAddFilter( $expectedQuery = null, array $warnings = null, $negated = false ) {
		$context = $this->mockContext();

		if ( $expectedQuery === null ) {
			$context->expects( $this->testCase->never() )
				->method( 'addFilter' );
			$context->expects( $this->testCase->never() )
				->method( 'addNotFilter' );
		} else {
			if ( is_callable( $expectedQuery ) ) {
				$filterCallback = $expectedQuery;
			} else {
				if ( $expectedQuery instanceof AbstractQuery ) {
					$expectedQuery = $expectedQuery->toArray();
				}
				$filterCallback = function ( AbstractQuery $query ) use ( $expectedQuery ) {
					$this->testCase->assertEquals( $expectedQuery, $query->toArray() );
					return true;
				};
			}

			$context->expects( $this->testCase->once() )
				->method( $negated ? 'addNotFilter' : 'addFilter' )
				->with( $this->testCase->callback( $filterCallback ) );
		}
		if ( $warnings !== null ) {
			$context->expects( $this->testCase->any() )
				->method( 'addWarning' )
				->will( $this->testCase->returnCallback( function () use ( &$warnings ) {
					$warnings[] = array_filter( func_get_args() );
				} ) );
		}

		return $context;
	}

	/**
	 * @param KeywordFeature $feature
	 * @param array $expected
	 * @param string $term
	 */
	public function assertWarnings( KeywordFeature $feature, $expected, $term ) {
		$warnings = [];
		$context = $this->mockContext();
		$context->expects( $this->testCase->any() )
			->method( 'addWarning' )
			->will( $this->testCase->returnCallback( function () use ( &$warnings ) {
				$warnings[] = array_filter( func_get_args() );
			} ) );
		$feature->apply( $context, $term );
		$this->testCase->assertEquals( $expected, $warnings );
	}

	/**
	 * Assert the value returned by KeywordFeature::getParsedValue
	 * @param KeywordFeature $feature
	 * @param string $term
	 * @param array|null $expected
	 * @param array|null $expectedWarnings (null to disable warnings check)
	 */
	public function assertParsedValue( KeywordFeature $feature, $term, $expected, $expectedWarnings = null ) {
		$parser = new KeywordParser();
		$node = $this->getParsedKeyword( $term, $feature, $parser );
		if ( $expected === null ) {
			$this->testCase->assertNull( $node->getParsedValue() );
		} else {
			$this->testCase->assertNotNull( $node->getParsedValue() );
			$this->testCase->assertEquals( $node->getParsedValue(), $expected );
		}
		if ( $expectedWarnings !== null ) {
			$actualWarnings = array_map(
				function ( ParseWarning $warning ) {
					return array_merge( [ $warning->getMessage() ], $warning->getMessageParams() );
				},
				$parser->getWarnings()
			);
			$this->testCase->assertEquals( $expectedWarnings, $actualWarnings );
		}
	}
	/**
	 * @param KeywordFeature $feature
	 * @param string $term
	 * @param array $expected
	 * @param array|null $expectedWarnings (null to disable warnings check)
	 * @param SearchConfig|null $config (if null will run with an empty SearchConfig)
	 */
	public function assertExpandedData( KeywordFeature $feature, $term, array $expected, array $expectedWarnings = null, SearchConfig $config = null ) {
		$node = $this->getParsedKeyword( $term, $feature );
		if ( $config === null ) {
			$config = new HashSearchConfig( [] );
		}

		$parser = new KeywordParser();
		$this->testCase->assertEquals( $expected, $feature->expand( $node, $config, $parser ) );
		if ( $expectedWarnings !== null ) {
			// Use KeywordParser as a WarningCollector
			$actualWarnings = array_map(
				function ( ParseWarning $warning ) {
					return array_merge( [ $warning->getMessage() ], $warning->getMessageParams() );
				},
				$parser->getWarnings()
			);
			$this->testCase->assertEquals( $expectedWarnings, $actualWarnings );
		}
	}

	/**
	 * @param KeywordFeature $feature
	 * @param string $term
	 * @param CrossSearchStrategy $expected
	 */
	public function assertCrossSearchStrategy( KeywordFeature $feature, $term, CrossSearchStrategy $expected ) {
		$parser = new KeywordParser();
		$nodes = $parser->parse( $term, $feature, new OffsetTracker() );
		$this->testCase->assertCount( 1, $nodes, "A single keyword expression must be provided for this test" );
		$node = $nodes[0];
		if ( $node instanceof NegatedNode ) {
			$node = $node->getChild();
		}
		$this->testCase->assertEquals( $expected, $feature->getCrossSearchStrategy( $node ) );
	}

	/**
	 * @param KeywordFeature $feature
	 * @param string $term
	 * @param array|callable|null $filter
	 * @param array|null $warnings
	 */
	public function assertFilter( KeywordFeature $feature, $term, $filter = null, array $warnings = null ) {
		$context = $this->mockContextExpectingAddFilter( $filter, $warnings, $this->isNegated( $feature, $term ) );
		if ( $filter !== null ) {
			$context->expects( $this->testCase->never() )->method( 'setResultsPossible' );
		}
		$feature->apply( $context, $term );
	}

	/**
	 * @param KeywordFeature $feature
	 * @param string $term
	 */
	public function assertNoResultsPossible( KeywordFeature $feature, $term ) {
		$context = $this->mockContext();
		$context->expects( $this->testCase->atLeastOnce() )->method( 'setResultsPossible' )->with( false );
		$feature->apply( $context, $term );
	}

	/**
	 * @param KeywordFeature $feature
	 * @param string $term
	 * @param array|string|null $highlightField
	 * @param array|null $higlightQuery
	 */
	public function assertHighlighting( KeywordFeature $feature, $term, $highlightField = null, array $higlightQuery = null ) {
		$context = $this->mockContext();

		if ( $highlightField !== null && $highlightField !== [] && !$this->isNegated( $feature, $term ) ) {
			if ( is_string( $highlightField ) ) {
				$context->expects( $this->testCase->once() )
					->method( 'addHighlightField' )
					->with( $highlightField, $higlightQuery );
			} else {
				$this->testCase->assertTrue( is_array( $highlightField ) );
				$this->testCase->assertEquals( count( $highlightField ), count( $higlightQuery ),
					'must have the same number of highlightFields than $higlightQueries' );

				$calls = [];
				$mi = new \MultipleIterator();
				$mi->attachIterator( new \ArrayIterator( $highlightField ) );
				$mi->attachIterator( new \ArrayIterator( $higlightQuery ) );
				foreach ( $mi as $value ) {
					$calls[] = $value;
				}

				$mock = $context->expects( $this->testCase->exactly( count( $highlightField ) ) )
					->method( 'addHighlightField' );
				call_user_func_array( [ $mock, 'withConsecutive' ], $calls );
			}
		} else {
			$context->expects( $this->testCase->never() )
				->method( 'addHighlightField' );
		}
		$feature->apply( $context, $term );
	}

	/**
	 * Historical test to make sure that the keyword does not consume unrelated values
	 * @param KeywordFeature $feature
	 * @param string $term
	 */
	public function assertNotConsumed( KeywordFeature $feature, $term ) {
		$context = $this->mockContext();
		$this->testCase->assertEquals( $term, $feature->apply( $context, $term ) );
		$parser = new KeywordParser();
		$nodes = $parser->parse( $term, $feature, new OffsetTracker() );
		$this->testCase->assertEmpty( $nodes );
	}

	/**
	 * Historical test to make sure that the keyword does not consume unrelated values
	 * @param KeywordFeature $feature
	 * @param string $term
	 * @param string $remaining
	 */
	public function assertRemaining( KeywordFeature $feature, $term, $remaining ) {
		$context = $this->mockContext();
		$this->testCase->assertEquals( $remaining, $feature->apply( $context, $term ) );
	}

	/**
	 * @param KeywordParser $parser
	 * @param string $term
	 * @return KeywordFeatureNode
	 */
	private function getParsedKeyword( $term, KeywordFeature $feature, KeywordParser $parser = null ) {
		if ( $parser === null ) {
			$parser = new KeywordParser();
		}
		$nodes = $parser->parse( $term, $feature, new OffsetTracker() );
		$this->testCase->assertCount( 1, $nodes, "A single keyword expression must be provided for this test" );
		$node = $nodes[0];
		if ( $node instanceof NegatedNode ) {
			$node = $node->getChild();
		}
		$this->testCase->assertInstanceOf( KeywordFeatureNode::class, $node );
		return $node;
	}

	/**
	 * @param KeywordFeature $feature
	 * @param string $term
	 * @return bool
	 */
	private function isNegated( KeywordFeature $feature, $term ) {
		$parser = new KeywordParser();
		$nodes = $parser->parse( $term, $feature, new OffsetTracker() );
		$this->testCase->assertCount( 1, $nodes, "A single keyword expression must be provided for this test" );
		$node = $nodes[0];
		return $node instanceof NegatedNode;
	}
}