<?php

class FTQueryParser {
	private $scanner;
	private $keywords;
	private $default_operator = 'AND';

	/**
	 * @Return CirrusQuery
	 */
	public function parse( $term ) {
		$this->scanner = new Scanner( $term );
		$left = null;
		while( !$this->scanner->is_eof() ) {
			$kw = $this->parse_node();
		}
	}

	/** Query ::= (NegationAndLeaf) ( (AND|OR)? (NegationAndLeaf) )* EOF */
	private function parse_query() {
		$this->scanner->consume_whitespaces();
		if ( $this->is_eof() ) {
			return null;
		}
		$left = $this->negation_and_leaf();
		$boolNode = new CirrusBool();
		$boolNode->add( $left );
		while( !$this->is_eof() ) {
			$op = $this->or();
			if ( $op != null ) {
				$op = $this->and();
			}
			// Query ends with a boolean operator
			// we consider the last one as a word...
			if ( $this->is_eof() ) {
				// TODO: suspicious query: issue a warning?
				$right = new CirrusWord( $op );
				$op = null;
			} else {
				$right = $this->negation_and_leaf();
			}
			// We don't support operator precedence
			// Like lucene QueryString the last always wins e.g :
			// one OR two AND three => one OR (two AND three)
			// one AND two OR three => one AND (two OR three)
			// Similar to QueryString + is overridden by the boolean
			// eg. +one OR +two => one OR tow
			if ( $op == 'AND' ) {
				$left->setMust( true );
				$right->setMust( true );
				$right->setExplicitConnection( true );
			} else if ( $op == 'OR' ) {
				$left->setMust( false );
				$right->setMust( false );
				$right->setExplicitConnection( true );
			} else if ( $this->default_operator == 'AND' ) {
				// If the operator is implicit it does not override
				// the previous node
				$right->setMust( true );
			}
			$boolNode->add( $right );
			$left = $right;
		}

		if ( $boolNode->size() == 1 ) {
			return new CirrusQuery( $bool->getClause( 0 ), $hints );
		}

		return new CirrusQuery( $bool, $hints );
	}

	/**
	 * NegationAndLeaf ::= (NOT Space)? (-|!|+)? (Leaf)
	 * @return CirrusQLeaf
	 */
	private function negation_and_leaf() {
		$negative = false;
		$explicit = false;
		$must = false;
		if ( $this->not() ) {
			$explicit = true;
			$negative = !$negative;
		}

		if ( $this->dash_not() ) {
			$explicit = true;
			$negative = !$negative;
		} else if ( $this->excl_mark_not() ) {
			$explicit = true;
			$negative = !$negative;
		} else if ( $this->must_with_plus() ) {
			$explicit = true;
			$must;
		}

		$leaf = $this->leaf();
		$leaf->setNegative( $negative );
		$leaf->setExplicitConnection( $explicit );
		$leaf->setMust( $must );
		return $leaf;
	}

	/**
	 * Leaf ::= (Keyword|Phrase|Word)
	 * @return CirrusQLeaf
	 */
	private function leaf() {
		$kw = $this->keyword();
		if ( $kw != null ) {
			return $kw;
		}

		$phrase = $this->phrase();
		if ( $phrase != null ) {
			return $phrase;
		}

		// this one stops on " and spaces
		// phrase must be run before so that it
		// consumes any " because it accepts
		// empty phrases and non terminated phrases
		$word = $this->scanner->word();
		if ( $word != null ) {
			return $word;
		}
		// A bug needs to be fixed
		$this->scanner->throw_err( "Bug : reached leaf end without any node." );
	}

	/**
	 * Keyword ::= (AsciiLCLetter) (AsciiLCLetter|-)* (AsciiLCLetter)
	 *             (:) ( Whitespace* ) ( SimpleKeywordValue|AnyChar* )
	 * @return CirrusQRegex|CirrusQKeyword|null
	 */
	public function keyword() {
		$state = $this->scanner->capture();
		// Keywords always start with a letter, dash would have probably been consumed
		// by dash_not
		if ( !$this->scanner->is_ascii_lc_letter() ) {
			$this->scanner->restore( $state );
			return null;
		}
		$last_is_letter = false;
		while( !$this->scanner->is_eof() ) {
			if ( $this->scanner->is_char( '-' ) ) {
				$last_is_letter = false;
			} else if( $this->scanner->is_ascii_lc_letter() ) {
				$last_is_letter = true;
			} else {
				$this->scanner->restore( $state );
				return null;
			}
		}
		if ( !$last_is_letter ) {
			$this->scanner->restore( $state );
			return null;
		}
		$this->scanner->capture_token( $state );
		if ( !$this->scanner->is_char( ':' ) ) {
			$this->scanner->restore( $state );
			return null;
		}
		if ( $this->scanner->is_eof() ) {
			$this->scanner->restore( $state );
			return null;
		}

		$tokenImage = $this->scanner->token_image();
		$feature = $this->lookupKeyword( $tokenImage );

		// special case same keyword triggers different behaviors
		// Detects a regex before consuming whitespaces
		if ( $tokenImage == 'insource' ) {
			if ( $this->is_char( '/', true ) ) {
				$regex = $this->insource_regex();
				if ( $regex != null ) {
					return $regex;
				}
			}
		}

		$this->scanner->consume_whitespaces();
		if ( $this->scanner->is_eof() ) {
			$this->scanner->restore( $state );
			return null;
		}

		if ( $feature instanceof SimpleKeywordFeature ) {
			$value = $this->simple_keyword_value();
			if( $value === null ) {
				$this->scanner->restore( $state );
				return null;
			}
			return new CirrusKWNode( $feature, $tokenImage, $value );
		}
		// Greedy keyword we consume everything until end
		$value_start = $this->scanner->capture();
		$this->scanner->consume_until_end();
		$this->scanner->capture_token( $value_start );
		$value = $this->scanner->token_image();
		if ( $feature->acceptValue( $value ) ) {
			return new CirrusKWNode( $feature, $tokenImage, $value );
		}
		$this->restore( $state );
		return null;
	}

	/**
	 * Word ::= (! " Space EOF)+ (Fuzziness)? (Space|EOF)
	 * @return CirrusQWildcard|CirrusQFuzzy|CirrusQWord|null
	 */
	public function word() {
		$state = $this->scanner->capture();
		$wildcard = false;
		$fuzziness = null;
		$escape_next = false;
		while ( !$this->scanner->is_eof() ) {
			if ( $this->scanner->is_char( '\\' ) ) {
				$escape_next = true;
				continue;
			}

			$this->scanner->capture_token( $state );
			// We dont escape spaces
			if ( $this->scanner->is_whitespace( true ) ) {
				break;
			}

			if( !$escape_next ) {
				if ( $this->is_char( '"' ) ) {
					break;
				}
				// fuzziness is valid only if followed by a space or EOF
				$fuzziness = $this->fuzziness();
				if ( $fuzziness != null ) {
					break;
				}
				if ( $this->scanner->is_char( '*' ) || $this->scanner->is_char( '?' ) ) {
					$wildcard = true;
					continue;
				}
			}
			$this->scanner->advance();
		}

		if ( $wildcard ) {
			// Wildcard always wins
			// TODO: issue a warning if $last_is_tilde is true?
			// leading wildcards should be handled later
			if( $this->scanner->token_length() <= 0 ) {
				// bug
				throw new \Exception( "Token length cannot be 0 if wildcard is detected." );
			}
			return $this->build_wildcard( $this->scanner->token_image() );
		}
		if ( $fuzziness ) {
			if( $this->scanner->token_length() <= 0 ) {
				// TODO: should we isssue a warning, is it a suspicious query?
				// It's just a word with ~ or ~(0|1|2)
				// Here we fallback to a word by recapturing the token from the beginning
				$this->scanner->capture_token( $state );
				return $this->build_word( $this->scanner->token_image() );
			}
			return $this->build_fuzzy_word( $this->scanner->token_image(), $fuzziness );
		}

		if( $this->scanner->token_length() <= 0 ) {
			// bug, it's likely that called word without consuming whitespaces,
			// checking a phrase before or eof, it's a bug.
			$this->scanner->throw_err( "Expected a word but found an empty token." );
		}
		return $this->build_word( $this->scanner->token_image() );
	}

	/**
	 * Fuzziness ::= ~ (0|1|2)? ( Space | EOF )
	 * @return string|null
	 */
	public function fuzziness() {
		$state = $this->scanner->capture();
		if ( $this->scanner->is_char( '~' ) ) {
			if ( $this->scanner->is_eof() || $this->scanner->is_whitespace( true ) ) {
				return 'DEFAULT';
			}
			if ( $this->scanner->is_whitespace( true ) ) {
				return 'DEFAULT';
			}
			foreach( array( '0', '1', '2' ) as $fuz ) {
				if ( $this->scanner->is_char( $fuz ) &&
					( $this->scanner->is_eof() || $this->scanner->is_whitespace( true ) )
				) {
					return $fuz;
				}
			}
		}
		$this->scanner->restore( $state );
		return null;
	}

	/**
	 * RegexValue ::= (/) ( AnyChar )* (/) (i)+
	 * @return CirrusQRegex|null
	 */
	public function insource_regex() {
		$state = $this->scanner->capture();
		// We accept empty regexes but we refuse unterminated ones
		$value = $this->consume_bounded_part( '/', '\\', true, false );
		if ( $value == null ) {
			$this->scanner->restore( $state );
			return null;
		}
		$case_insensitive = false;
		if( !$this->scanner->is_eof() && $this->is_char( 'i' ) != null ) {
			$case_insensitive = true;
		}

		return $this->build_insource_regex( $value, $case_insensitive );
	}

	/**
	 * SimpleKeywordValue ::= (QuotedString|UnquotedValue)
	 * @return string|null
	 */
	public function simple_keyword_value() {
		$state = $this->scanner->capture();
		$this->scanner->capture_token();
		while( !$this->is_whitespace() ) {}
		if ( $this->scanner->token_length() > 0 ) {
			return $this->scanner->token_image();
		}
		$this->scanner->restore_state();
		return null;
	}

	public function quoted_string() {
		return $this->consume_bounded_part( '"', '\\' );
	}

	/**
	 * Phrase ::= (") ([^"] | \")* (*)* ("|EOF) (~)? (num)? (~)?
	 * @return CirrusQPhrase|null
	 */
	public function parse_phrase() {
		$state = $this->scanner->capture();
		if ( !$this->scanner->is_char( $boundaryChar) ) {
			return false;
		}
		$valueState = $this->scanner->capture();
		$last_is_wildcard = false;
		$escape_next = false;
		while( true ) {
			if( $this->scanner->is_eof() ) {
				// We accept unterminated phrases
				// TODO: emit a warning?
				break;
			}
			if ( !$escape_next ) {
				if( $this->is_char( '\\' ) ) {
					$escape_next = true;
					continue;
				}
				// Capture now so if a trailing wildcard or the
				// boundary is present we do not have them in
				// the token image
				$this->scanner->capture_token( $valueState );
				if( $this->is_char( $boundaryChar ) ) {
					break;
				}
				// Detect trailing wildcards to detect phrase prefix
				if ( $this->is_char( '*' ) ) {
					$last_is_wildcard = true;
					continue;
				}
			}
			$escape_next = false;
			$last_is_wildcard = false;
			$this->advance();
			// Capture again just in case next is eof
			$this->scanner->capture_token( $valueState );
		}

		$phrase = $this->scanner->token_image();

		$slop = null;
		$useStems = false;
		// Parse :
		// sloppiness + stems: ~SLOP~
		// sloppiness: ~SLOP
		// stems : ~
		if ( $this->scanner->is_char( '~' ) ) {
			$slopStart = $this->scanner->capture();
			while( $this->scanner->is_numeric() ) {
				$this->scanner->capture_token();
			}
			if ( $this->scanner->token_length() > 0 ) {
				$slop = intval( $num );
				if ( $this->is_char( '~' ) ) {
					$useStems = true;
				}
			} else {
				$useStems = true;
			}
		}

		$this->build_phrase( $phrase, $last_is_wildcard, $slop, $useStems );
	}

	/**
	 * @param string $boundaryChar
	 * @param string $escapeChar
	 * @param bool $acceptEmpty
	 * @param bool $acceptEof
	 * @return string|null the encapsulated value
	 */
	public function consume_bounded_part( $boundaryChar, $escapeChar, $acceptEmpty = false, $acceptEof = false ) {
		$state = $this->scanner->capture();
		if ( !$this->scanner->is_char( $boundaryChar) ) {
			return false;
		}
		$valueState = $this->scanner->capture();
		// True if the previous char was a $escapeChar
		$escape_next = false;
		while( true ) {
			if( $this->is_eof() ) {
				if( $acceptEof ) {
					break;
				} else {
					$this->restore( $state );
					return false;
				}
			}
			if ( !$escape_next ) {
				if( $this->scanner->is_char( $escapeChar ) ) {
					// XXX: We don't capture the token
					// Which means that a trailing escape char will be ignored
					// if next is_eof
					$escape_next = true;
					continue;
				}
				// Capture now just in case we reach boundary
				// we don't want the boundary to be part of the
				// token_image
				$this->scanner->capture_token( $valueState );
				if( $this->is_char( $boundaryChar ) ) {
					break;
				}
			}
			$this->advance();
			$this->scanner->capture_token( $valueState );
		}

		if ( $acceptEmpty || $this->scanner->token_length() > 0 ) {
			return $this->scanner->token_image();
		}
		$this->scanner->restore( $state );
		return null;
	}

	/** Not: "NOT" Space+ (!EOF) */
	private function not() {
		$state = $this->scanner->capture();
		$this->scanner->is_str( 'NOT' );
		if( $this->scanner->is_whitespace() ) {
			$this->scanner->consume_whitespaces();
			if ( $this->scanner->is_eof() ) {
				$this->scanner->restore( $state );
				return null;
			}
			return 'NOT';
		}
		$this->scanner->restore( $state );
		return null;
	}

	/** DashNot ::= - (!Space|!-|!EOF)  */
	private function dash_not() {
		$state = $this->scanner->capture();
		$this->scanner->is_char( '-' );
		if ( $this->scanner->is_eof() || $this->is_whitespace() || $this->scanner->is_char( '-' ) ) {
			$this->scanner->restore( $state );
			return null;
		}
		return '-';
	}

	/** BoolAnd ::= AND (Space|EOF) (Space)* */
	private function bool_and() {
		return $this->bool_op( 'AND' );
	}

	/** BoolOr ::= OR (Space|EOF) (Space)* */
	private function bool_or() {
		return $this->bool_op( 'OR' );
	}


	private function bool_op( $op ) {
		$state = $this->scanner->capture();
		if ( !$this->scanner->is_str( $op ) ) {
			$this->scanner->restore( $state );
			return null;
		}
		if ( !$this->is_eof() || !$this->scanner->is_whitespace() ) {
			$this->scanner->restore( $state );
			return;
		}
		$this->consume_whitespaces();
		return $op;
	}


	/**
	 * @param string $word_token
	 * @return CirrusQWord
	 */
	private function build_word( $word_token ) {
		// unescape everything this is consitent with query string
		// a\word => aword
		// but unconsistent with cirrus regex extractor
		// a\word => a\word
		// It's unclear if we need to do selective unescaping
		// For words we would have to unescape " and \ only
		return new CirrusQWord( $this->unescape_backslashes( $word ) );
	}

	/**
	 * @param string $word
	 * @param string $fuzziness
	 * @return CirrusQFuzzy
	 */
	private function build_fuzzy_word( $word, $fuzziness ) {
		// We unescape everything for the same reason described in build_word
		return new CirrusQFuzzy( $this->unescape_backslashes( $word ), $fuzziness );
	}

	/**
	 * @param string $wildcard_token
	 * @return CirrusQWildcard
	 */
	private function build_wildcard( $wildcard_token ) {
		// Here we keep escaping provided by the user.
		// lucene wildcard supports the same escape
		// sequence with backslashes.
		// It's unclear if we need to munge the wildcard
		// here when leading wildcards are disabled.
		// The purpose of this option is mainly to
		// prevent fullscans so it makes sense to handle
		// this just before creating the backend query
		return new CirrusQWildcard( $wildcard_token );
	}

	/**
	 * @param string $phrase
	 * @param $is_phrase_prefix bool
	 * @param $slop int
	 * @param $useStems bool
	 * @return CirrusQPhrase
	 */
	private function build_phrase( $phrase, $is_phrase_prefix, $slop, $useStems ) {
		// We escape everyting
		$phrase = $this->unescape_backslashes( $phrase );
		return new CirrusQPhrase( $phrase, $is_phrase_prefix, $slop, $useStems );
	}

	/**
	 * @param string $regex
	 * @param bool $case_insensitive
	 * @return CirrusQRegex
	 */
	private function build_insource_regex( $regex, $case_insensitive ) {
		// No escaping here, we keep user provided excape sequence as is.
		return new CirrusQRegex( $regex, $case_insensitive );
	}

	/**
	 * @param string $token_length
	 * @return string unescaped string
	 */
	private function unescape_backslashes( $token ) {
		return preg_replace( '/\\\\(.)/', "$1", $token );
	}
}

class Scanner {
	private $query;
	private $offset;
	private $token_start;
	private $token_end;

	private static $WHITESPACES = array( 'a' );

	public function __construct( $query ) {
		$this->query = $query;
		$this->offset = 0;
	}


	private function consume_whitespaces() {
		while( !$this->is_eof() ) {
			if ( !$this->is_whitespace() ) {
				return;
			}
		}
	}

	private function is_whitespace( $lookahead = false ) {
		foreach( $this->$WHITESPACES as $ws ) {
			if ( $this->is_char( $ws, $lookahead ) ) {
				return true;
			}
		}
		return false;

	}


	private function capture() {
		return $this->offset;
	}

	private function restore( $state ) {
		$this->offset = $state;
	}

	private function advance() {
		$this->offset += $this->codePointLength( $this->query[$offset] );
	}

	private function is_eof() {
		return $this->offset >= strlen( $this->query );
	}

	private function codePointLength( $str ) {
		$char = ord( $str );
		if( $char < 128 ) {
			return 1;
		}
		if( $char < 224 ) {
			return 2;
		}
		if( $char < 240 ) {
			return 3;
		}
		return 4;
	}

	private function is_char( $char, $lookahead = false ) {
		$len = $this->codePointLength( $char );
		if ( $len + $this->offset > strlen( $this->query ) ) {
			return false;
		}
		if ( substr_compare( $this->query, $char, $this->offset, $len ) == 0 ) {
			if ( !$lookahead ) {
				$this->offset += $len;
			}
			return true;
		}
	}

	private function is_str( $str, $lookahead = false ) {
		$len = strlen( $str );
		if ( $len + $this->offset > strlen( $this->query ) ) {
			return false;
		}
		if ( substr_compare( $this->query, $char, $this->offset, $len ) == 0 ) {
			if ( !$lookahead ) {
				$this->offset += $len;
			}
			return true;
		}
	}

	/** [a-z] */
	private function is_ascii_lc_letter() {
		if ( substr_compare( $this->query, 'a', $this->offset, 1 ) >= 0 &&
			substr_compare( $this->query, 'z', $this->offset, 1 ) <= 0
		) {
			$this->offset++;
			return true;
		}
	}

	private function capture_token( $state ) {
		if( $state <= 0 || $this->offset < $state  ) {
			throw new \Exception( "Nothing to capture" );
		}
		$this->token_start = $state;
		$this->token_end = $this->offset;
	}

	private function throw_err( $msg ) {
		throw new FTQueryParseError( $msg . " Current offset: {$this->offset}, query: {$this->query} " );
	}
}
