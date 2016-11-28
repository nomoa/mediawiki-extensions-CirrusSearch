<?php
namespace CirrusSearch\Query\KeywordService;

class KeywordService {
	private static $GREEDY_KEYWORDS = [
		MoreLikeFeature::class,
		PrefixFeature::class,
		LocalFeature::class,
	];

	private static $CIRRUS_KEYWORDS = [
		PreferRecentFeature::class,
	];
	/** @var SearchConfig */
	private $config;

	/** @var KeywordFeature[] lazy loaded list of keywords */
	private $keywords;

	/** @var SimpleKeywordFeature[] indexed by keyword */
	private $keywordMap;

	public function __construct( SearchConfig $config ) {
		$this->config = $config;
	}

	/** @return KeywordFeature[] */
	public function getKeywpords() {
		if ( $this->keywords === null ) {
			$this->loadKeywords();
		}
	}

	private function loadKeywords() {
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
				new Query\SimpleInSourceFeature(),
				// Handle intitle keyword
				new Query\InTitleFeature(),
				// inlanguage keyword
				new Query\LanguageFeature(),
				// File types
				new Query\FileTypeFeature(),
				// File numeric characteristics - size, resolution, etc.
				new Query\FileNumericFeature(),

	}
}
