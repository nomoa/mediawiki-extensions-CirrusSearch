<?php
// 7 shards for enwiki
$wgCirrusSearchShardCount = array( 'content' => 7, 'general' => 4, 'titlesuggest' => 4 );
$wgLanguagecode = 'en';
$wgCirrusSearchIndexBaseName = 'enwikibm25wp10normalsugg';
$wgCirrusSearchPhraseSuggestReverseField['use'] = false;

$wgCirrusSearchCompletionSuggesterUseDefaultSort = false;

$wgCirrusSearchCompletionSuggesterSubphrases = [
	'use' => false,
	'build' => false,
	'type' => 'subpages',
	'limit' => 3,
];

$wgHooks['CirrusSearchMappingConfig'][] = function( array &$config, $mappingConfigBuilder ) {
        $config['page']['properties']['wp10'] = [
                'type' => 'double',
        ];
};
