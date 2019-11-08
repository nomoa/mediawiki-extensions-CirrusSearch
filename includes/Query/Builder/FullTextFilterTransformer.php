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
use CirrusSearch\Search\SearchQuery;
use Elastica\Query\AbstractQuery;
use Elastica\Query\BoolQuery;
use Elastica\QueryBuilder;

class FullTextFilterTransformer implements SearchQueryESTransformer, Visitor {
	/**
	 * @var QueryBuilder\DSL\Query
	 */
	private $queryBuilder;
	/**
	 * @var string
	 */
	private $longestNonNegativeBagOfWord;

	private $currentBoolOccur;

	/**
	 * @var BoolQuery
	 */
	private $current;

	public function transform( SearchQuery $query, QueryBuildingContext $buildingContext ): AbstractQuery {
		$this->current = $this->queryBuilder->bool();
		$this->longestNonNegativeBagOfWord = null;
		$this->currentBoolOccur = BooleanClause::MUST;
	}

	private function append( AbstractQuery $query ): void {
		switch ( $this->currentBoolOccur ) {
			case BooleanClause::MUST:
				$this->current->addMust( $query );
			case BooleanClause::SHOULD:
				$this->current->addShould( $query );
			case BooleanClause::MUST_NOT:
				$this->current->addMustNot( $query );
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
	}

	/**
	 * @param PhraseQueryNode $node
	 */
	public function visitPhraseQueryNode( PhraseQueryNode $node ) {
		// TODO: Implement visitPhraseQueryNode() method.
	}

	/**
	 * @param PhrasePrefixNode $node
	 */
	public function visitPhrasePrefixNode( PhrasePrefixNode $node ) {
		// TODO: Implement visitPhrasePrefixNode() method.
	}

	/**
	 * @param NegatedNode $node
	 */
	public function visitNegatedNode( NegatedNode $node ) {
		// TODO: Implement visitNegatedNode() method.
	}

	/**
	 * @param FuzzyNode $node
	 */
	public function visitFuzzyNode( FuzzyNode $node ) {
		// TODO: Implement visitFuzzyNode() method.
	}

	/**
	 * @param PrefixNode $node
	 */
	public function visitPrefixNode( PrefixNode $node ) {
		// TODO: Implement visitPrefixNode() method.
	}

	/**
	 * @param WildcardNode $node
	 */
	public function visitWildcardNode( WildcardNode $node ) {
		// TODO: Implement visitWildcardNode() method.
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
