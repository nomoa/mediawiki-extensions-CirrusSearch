<?php

class CirrusQuery {
	/** @var CirrusQNode */
	private $node;
}

/**
 * A node
 */
abstract class CirrusQNode {
	/**
	 * @var bool: true if this node is explicitely connected to its sibling (left)
	 */
	private $explicitConnection;

	/**
	 * @var bool: true if this node is negated, only supported on leaves for now
	 */
	private $negative;

	/**
	 * @var bool: true if this node is not optional
	 */
	private $must;

	public function setExplicitConnection( $explicitConnection ) {
		$this->explicitConnection = $explicitConnection;
	}

	public function isExplicitConnection() {
		return $this->explicitConnection;
	}

	public function isNegative() {
		return $this->negative;
	}

	public function setNegative( $negative ) {
		$this->negative = $negative;
	}

	public function setMust( $must ) {
		$this->must = $must;
	}

	public function isMust( $must ) {
		return $this->must;
	}

	public function accept( $visitor ) {
		$visitor->visit( $this );
	}

	/** @return array[] serialize this query to an array */
	public abstract function toArray();

	/** @return bool true if this is a leaf node */
	public abstract function isLeaf();
}

/**
 * A leaf node
 */
abstract class CirrusQLeaf extends CirrusQNode {
	/** @return bool: always true */
	public function isLeaf() {
		return true;
	}
}

/**
 * A boolean container
 */
class CirrusQBool extends CirrusQNode {
	/** @var CirrusQNode[]: list of nodes */
	private $nodes = array();

	/**
	 * @param CirrusQNode $node
	 */
	public function add( CirrusQNode $node ) {
		$this->nodes[] = $leaf;
	}

	/** @return int */
	public function size() {
		return count( $this->nodes );
	}

	/** @return CirrusQNode */
	public function getClauseAt( $idx ) {
		return $this->nodes[$idx];
	}

	/** @return bool: always false */
	public function isLeaf() {
		return false;
	}
}

/**
 * A phrase
 */
class CirrusQPhrase extends CirrusQLeaf {
	/** @var bool: true if it's a phrase prefix, usually "foo bar*" */
	private $phrasePrefix;

	/** @var string: the phrase */
	private $phrase;

	/** @var int|null: phrase slop or null which means use default */
	private $phraseSlop;

	/** @var bool: true to enable phrase on stem fields */
	private $useStems;

	/**
	 * @param string $phrase: the phrase
	 * @param bool $phrasePrefix is it a phrase prefix "foo bar*" ?
	 * @param int $phraseSlop
	 * @param $useStems: should the phrase query run on stem fields
	 */
	public function __construct( $phrase, $phrasePrefix = false, $phraseSlop = null, $useStems = false ) {
		$this->phrase = $phrase;
		$this->phrasePrefix = $phrasePrefix;
		$this->phraseSlop = $phraseSlop;
		$this->useStems = $useStems;
	}

	/** @return bool */
	public function isPhrasePrefix() {
		return $this->phrasePrefix;
	}

	/** @return string */
	public function getPhrase() {
		return $this->phrase;
	}

	/** @return bool */
	public function isUseStems() {
		return $this->useStems;
	}
}

/**
 * A keyword
 */
class CirrusQKeyword extends CirrusQLeaf {
	/** @var KeywordFeature */
	private $feature;

	/** @var string */
	private $keyword;

	/** @var string */
	private $value;

	/**
	 * @param KeywordFeature $feature
	 * @param string $keyword
	 * @param string $value
	 */
	public function __construct( KeywordFeature $feature, $keyword, $value ) {
		$this->feature = $feature;
		$this->keyword = $keyword;
		$this->value = $value;
	}

	/** @return KeywordFeature */
	public function getFeature() {
		return $this->feature;
	}

	/** @return string */
	public function getKeyword() {
		return $this->keyword;
	}

	/** @return string */
	public function getValue() {
		return $this->value;
	}
}

class CirrusQWord extends CirrusQLeaf {
	/** @var string */
	private $word;

	/**
	 * @param string $word
	 */
	private function __construct( $word ) {
		$this->word = $word;
	}

	/**
	 * @return string
	 */
	public function getWord() {
		return $this->word;
	}
}

class CirrusQRegex extends CirrusQFilter {
	/** @var string: the regex */
	private $regex;
	/** @var bool: true if case insensitive */
	private $caseInsensitive;

	/**
	 * @param string $regex
	 * @param bool $caseInsensitive
	 */
	public function __construct( $regex, $caseInsensitive ) {
		$this->regex = $regex;
		$this->caseInsensitive = $caseInsensitive;
	}

	/** @return string */
	public function getRegex() {
		return $this->regex;
	}

	/** @return bool */
	public function isCaseInsensitive() {
		return $this->caseInsensitive;
	}
}

abstract class CirrusQMultiTerm extends CirrusQLeaf {
}

class CirrusQWildcard extends CirrusQMultiTerm {
	/** @var string */
	private $wildcard;

	public function __construct( $wildcard ) {
		$this->wildcard = $wildcard;
	}

	public function getWildcard() {
		return $this->wildcard;
	}
}

class CirrusQFuzzy extends CirrusQMultiTerm {
	/** @var string */
	private $fuzzyTerm;

	public function __construct( $fuzzyTerm ) {
		$this->fuzzyTerm = $fuzzyTerm;
	}

	public function getFuzzyTerm() {
		return $this->fuzzyTerm;
	}
}
