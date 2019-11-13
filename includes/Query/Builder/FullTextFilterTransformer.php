<?php

namespace CirrusSearch\Query\Builder;

use CirrusSearch\Parser\AST\BooleanClause;
use CirrusSearch\Parser\AST\EmptyQueryNode;
use CirrusSearch\Parser\AST\FuzzyNode;
use CirrusSearch\Parser\AST\KeywordFeatureNode;
use CirrusSearch\Parser\AST\NamespaceHeaderNode;
use CirrusSearch\Parser\AST\NegatedNode;
use CirrusSearch\Parser\AST\ParsedBooleanNode;
use CirrusSearch\Parser\AST\PhrasePrefixNode;
use CirrusSearch\Parser\AST\PhraseQueryNode;
use CirrusSearch\Parser\AST\PrefixNode;
use CirrusSearch\Parser\AST\Visitor\Visitor;
use CirrusSearch\Parser\AST\WildcardNode;
use CirrusSearch\Parser\AST\WordsQueryNode;
use CirrusSearch\Search\Filters;
use CirrusSearch\Search\SearchQuery;
use Elastica\Query\AbstractQuery;
use Elastica\Query\BoolQuery;
use Elastica\QueryBuilder;
use InvalidArgumentException;

class FullTextFilterTransformer implements SearchQueryESTransformer, Visitor {
	/**
	 * @var array
	 */
	private $bagOfWordFilterProfile;

	/**
	 * @var int
	 */
	private $defaultPhraseQuerySlop;

	/**
	 * @var int
	 */

	private $maxPhraseSlop;

	/**
	 * @var QueryBuilder\DSL\Query
	 */
	private $queryBuilder;

	/**
	 * @var string
	 */
	private $longestNonNegativeBagOfWord;

	/**
	 * @var string
	 */
	private $currentBoolOccur;

	/**
	 * @var BoolQuery
	 */
	private $current;

	public function __construct( array $bagOfWordsFilterProfile, int $defaultPhraseQuerySlop, int $maxPhraseSlop ) {
		$this->bagOfWordFilterProfile = $bagOfWordsFilterProfile;
		$this->defaultPhraseQuerySlop = $defaultPhraseQuerySlop;
		$this->maxPhraseSlop = $maxPhraseSlop;
		$this->queryBuilder = QueryBuilder::query();
	}

	public function transform( SearchQuery $query, QueryBuildingContext $buildingContext ): AbstractQuery {
		$this->current = $this->queryBuilder->bool();
		$this->longestNonNegativeBagOfWord = null;
		$this->currentBoolOccur = BooleanClause::MUST;
	}

	private function append( AbstractQuery $query ): void {
		switch ( $this->currentBoolOccur ) {
			case BooleanClause::MUST:
				$this->current->addMust( $query );
				break;
			case BooleanClause::SHOULD:
				$this->current->addShould( $query );
				break;
			case BooleanClause::MUST_NOT:
				$this->current->addMustNot( $query );
				break;
			default: throw new InvalidArgumentException( "Unsupported boolean occur: {$this->currentBoolOccur}" );
		}
	}

	/**
	 * @param ParsedBooleanNode $node
	 */
	public function visitParsedBooleanNode( ParsedBooleanNode $node ) {
		$previous = $this->current;
		$newBool = $this->queryBuilder->bool();
		$this->append( $newBool );
		$this->current = $newBool;
		foreach ( $node->getClauses() as $clause ) {
			$this->visitBooleanClause( $clause );
		}
		$this->current = $previous;
	}

	/**
	 * @param BooleanClause $clause
	 */
	public function visitBooleanClause( BooleanClause $clause ) {
		$this->currentBoolOccur = $clause->getOccur();
		$clause->getNode()->accept( $this );
	}

	/**
	 * @param WordsQueryNode $node
	 */
	public function visitWordsQueryNode( WordsQueryNode $node ) {
		$this->append( Filters::bagOfWordsFilter( $this->bagOfWordFilterProfile, $node->getWords() ) );
	}

	/**
	 * @param PhraseQueryNode $node
	 */
	public function visitPhraseQueryNode( PhraseQueryNode $node ) {
		$slop = $node->getSlop() < 0 ? $this->defaultPhraseQuerySlop : $node->getSlop();
		$this->append( Filters::phrase( $node->getPhrase(), $node->isStem(), $node->getSlop() ) );
	}

	/**
	 * @param PhrasePrefixNode $node
	 */
	public function visitPhrasePrefixNode( PhrasePrefixNode $node ) {
		$this->append( Filters::phrasePrefix( $node->getPhrase() ) );
	}

	/**
	 * @param NegatedNode $node
	 */
	public function visitNegatedNode( NegatedNode $node ) {
		throw new InvalidArgumentException( "Negated node should have been rewritten as MUST_NOT boolean clauses" );
	}

	/**
	 * @param FuzzyNode $node
	 */
	public function visitFuzzyNode( FuzzyNode $node ) {
		$fuzziness = $node->getFuzziness() < 0 ? null : $node->getFuzziness();
		$this->append( Filters::fuzzy( $node->getWord(), $fuzziness ) );
	}

	/**
	 * @param PrefixNode $node
	 */
	public function visitPrefixNode( PrefixNode $node ) {
		$this->append( Filters::prefix( $node->getPrefix() ) );
	}

	/**
	 * @param WildcardNode $node
	 */
	public function visitWildcardNode( WildcardNode $node ) {
	}

	/**
	 * @param EmptyQueryNode $node
	 */
	public function visitEmptyQueryNode( EmptyQueryNode $node ) {
		// TODO: Implement visitEmptyQueryNode() method.
	}

	/**
	 * @param KeywordFeatureNode $node
	 */
	public function visitKeywordFeatureNode( KeywordFeatureNode $node ) {
		// TODO: Implement visitKeywordFeatureNode() method.
	}

	/**
	 * @param NamespaceHeaderNode $node
	 */
	public function visitNamespaceHeader( NamespaceHeaderNode $node ) {
		// TODO: Implement visitNamespaceHeader() method.
	}
}
