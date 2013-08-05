<?php
/**
 * Builds elasticsearch analysis config arrays.
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
class CirrusSearchAnalysisConfigBuilder {
	private $language;

	public static function build() {
		$builder = new CirrusSearchAnalysisConfigBuilder();
		return $builder->buildConfig();
	}

	public function __construct() {
		global $wgLanguageCode;
		$this->language = $wgLanguageCode;
	}

	/**
	 * Build the analysis config.
	 * @return array the analysis config
	 */
	public function buildConfig() {
		$config = $this->defaults();
		$this->customize( $config );
		return $config;
	}

	/**
	 * Build an analysis config with sane defaults.
	 */
	private function defaults() {
		return array(
			'analyzer' => array(
				'text' => array(
					'type' => $this->getDefaultTextAnalyzerType(),
				),
				'suggest' => array(
					'type' => 'custom',
					'tokenizer' => 'standard',
					'filter' => array( 'standard', 'lowercase', 'suggest_shingle' ),
				),
				'prefix' => array(
					'type' => 'custom',
					'tokenizer' => 'prefix',
					'filter' => array( 'lowercase' )
				),
				'prefix_query' => array(
					'type' => 'custom',
					'tokenizer' => 'no_splitting',
					'filter' => array( 'lowercase' )
				),
			),
			'filter' => array(
				'suggest_shingle' => array(
					'type' => 'shingle',
					'min_shingle_size' => 2,
					'max_shingle_size' => 5,
					'output_unigrams' => true,
				),
				'lowercase' => array(
					'type' => 'lowercase',
				)
			),
			'tokenizer' => array(
				'prefix' => array(
					'type' => 'edgeNGram',
					'max_gram' => CirrusSearch::MAX_PREFIX_SEARCH,
				),
				'no_splitting' => array( // Just grab the whole term.
					'type' => 'keyword',
				)
			)
		);
	}

	/**
	 * Customize the default config for the language.
	 */
	private function customize( $config ) {
		$analyzers = $config[ 'analyzer' ];
		$filters = $config[ 'filter' ];
		switch ( $this->language ) {
		// Please add languages in alphabetical order.
		case 'el':
			$filters[ 'lowercase' ][ 'language' ] = 'greek';
			break;
		case 'en':
			// Replace the default english analyzer with a rebuilt copy with asciifolding tacked on the end
			$analyzers[ 'text' ] = array(
				'type' => 'custom',
				'tokenizer' => 'standard',
				'filter' => array( 'standard', 'possessive_english', 'lowercase', 'stop', 'porter_stem', 'asciifolding' )
			);
			$filters[ 'possessive_english' ] = array(
				'type' => 'stemmer',
				'language' => 'possessive_english',
			);
			// Add asciifolding to the prefix queries
			$analyzers[ 'prefix' ][ 'filter' ][] = 'asciifolding';
			$analyzers[ 'prefix_query' ][ 'filter' ][] = 'asciifolding';
			break;
		case 'tr':
			$filter[ 'lowercase' ][ 'language' ] = 'turkish';
			break;
		}
	}

	/**
	 * Pick the appropriate default analyzer based on the language.  Rather than think of
	 * this as per language customization you should think of this as an effort to pick a
	 * reasonably default in case CirrusSearch isn't customized for the language.
	 * @return string the analyzer type
	 */
	private function getDefaultTextAnalyzerType() {
		if ( array_key_exists( $this->language, $this->elasticsearchLanguages ) ) {
			return $this->elasticsearchLanguages[ $this->language ];
		} else {
			return 'default';
		}
	}
	/**
	 * Languages for which elasticsearch provides a built in analyzer.  All
	 * other languages default to the default analyzer which isn't too good.  Note
	 * that this array is sorted alphabetically by value and sourced from
	 * http://www.elasticsearch.org/guide/reference/index-modules/analysis/lang-analyzer/
	 */
	private $elasticsearchLanguages = array(
		'ar' => 'arabic',
		'hy' => 'armenian',
		'eu' => 'basque',
		'pt-br' => 'brazilian',
		'bg' => 'bulgarian',
		'ca' => 'catalan',
		'zh' => 'chinese',
		// 'cjk', - we don't use this because we don't have a wiki with all three
		'cs' => 'czech',
		'da' => 'danish',
		'nl' => 'dutch',
		'en' => 'english',
		'fi' => 'finnish',
		'fr' => 'french',
		'gl' => 'galician',
		'de' => 'german',
		'el' => 'greek',
		'hi' => 'hindi',
		'hu' => 'hungarian',
		'id' => 'indonesian',
		'it' => 'italian',
		'nb' => 'norwegian',
		'nn' => 'norwegian',
		'fa' => 'persian',
		'pt' => 'portuguese',
		'ro' => 'romanian',
		'ru' => 'russian',
		'es' => 'spanish',
		'sv' => 'swedish',
		'tr' => 'turkish',
		'th' => 'thai'
	);
}
