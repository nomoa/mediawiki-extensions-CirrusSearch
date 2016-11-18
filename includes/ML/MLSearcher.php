<?php

namespace CirrusSearch/ML;

use CirrusSearch\Util;
use CirrusSearch\FullTextQueryBuilder;
use CirrusSearch\FullTextSimpleMatchQueryBuilder;

class MLSearcher {
	private $config;
	private $connection;

	public function __construct( Connection $conn, SearchConfig $config ) {
		$this->connection = $conn;
		$this->config = $config;
	}

	/**
	 * @var string $query
	 * @var array $docs 
	 * @return array[] features
	 */
	public function extractFeatures( $query, $topN ) {
		$collector = new FeatureCollector();
		$term = Util::stripQuestionMarks( $term, $this->config->get( 'CirrusSearchStripQuestionMarks' ) );
		$builderProfile = $this->config->get( 'CirrusSearchFullTextQueryBuilderProfile' );
		$builderSettings = $this->config->getElement( 'CirrusSearchFullTextQueryBuilderProfiles', $builderProfile );
		if ( $builderSettings['builder_class'] === FullTextSimpleMatchQueryBuilder::class ) {
			throw new \RuntimeException( "Only FullTextSimpleMatchQueryBuilder is supported" );
		}

		$escaper = new Escaper( $this->config->get( 'LanguageCode' ), $this->config->get( 'CirrusSearchAllowLeadingWildcard' ) );
		$qb = new $builderSettings['builder_class'](
			$this->config,
			$this->escaper,
			[
				// Handle morelike keyword (greedy). This needs to be the
				// very first item until combining with other queries
				// is worked out.
				new Query\MoreLikeFeature( $this->config, [$this, 'get'] ),
				// Handle title prefix notation (greedy)
				new Query\PrefixFeature(),
				// Handle prefer-recent keyword
				new Query\PreferRecentFeature( $this->config ),
				// Handle local keyword
				new Query\LocalFeature(),
				// Handle insource keyword using regex
				new Query\RegexInSourceFeature( $this->config ),
				// Handle neartitle, nearcoord keywords, and their boosted alternates
				new Query\GeoFeature(),
				// Handle boost-templates keyword
				new Query\BoostTemplatesFeature(),
				// Handle hastemplate keyword
				new Query\HasTemplateFeature(),
				// Handle linksto keyword
				new Query\LinksToFeature(),
				// Handle incategory keyword
				new Query\InCategoryFeature( $this->config ),
				// Handle non-regex insource keyword
				new Query\SimpleInSourceFeature( $this->escaper ),
				// Handle intitle keyword
				new Query\InTitleFeature( $this->escaper ),
				// inlanguage keyword
				new Query\LanguageFeature(),
				// File types
				new Query\FileTypeFeature(),
				// File numeric characteristics - size, resolution, etc.
				new Query\FileNumericFeature(),
			],
			$builderSettings['settings'],
			$collector
		);

		// Hardcode to ns 0, we rarely track namespace in logs assuming users
		// always use defaults, this is not true but probably a reasonable
		// approx.
		$searchContext = new SearchContext( $this->config, [0] );
		$qb->build( $searchContext, $term, $showSuggestion );


		if ( $collector->getFilter() === null ) {
			// We certainly switched to QueryString and
			// we can't really extract query string feature.
			return [];
		}
		$rescoreBuilder = new RescoreBuilder( $searchContext );
		$rescoreBuilder->build( $collector );
	}
}
