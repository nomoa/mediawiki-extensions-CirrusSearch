<?php

namespace CirrusSearch\Test;

use MediaWiki\MediaWikiServices;
use CirrusSearch\CirrusTestCase;
use CirrusSearch\CirrusConfigInterwikiResolver;
use CirrusSearch\SiteMatrixInterwikiResolver;
use CirrusSearch\InterwikiResolverFactory;
use CirrusSearch\InterwikiResolver;

/**
 * @group CirrusSearch
 */
class InterwikiResolverTest extends CirrusTestCase {
	public function testCirrusConfigInterwikiResolver() {
		$resolver = $this->getCirrusConfigInterwikiResolver();

		// Test wikiId => prefix map
		$this->assertEquals( 'fr', $resolver->getInterwikiPrefix( 'frwiki' ) );
		$this->assertEquals( 'no', $resolver->getInterwikiPrefix( 'nowiki' ) );
		$this->assertEquals( 'b', $resolver->getInterwikiPrefix( 'enwikibooks' ) );
		$this->assertEquals( null, $resolver->getInterwikiPrefix( 'simplewiki' ) );

		// Test sister projects
		$this->assertArrayHasKey( 'voy', $resolver->getSisterProjectPrefixes() );
		$this->assertArrayHasKey( 'b', $resolver->getSisterProjectPrefixes() );
		$this->assertEquals( 'enwikivoyage', $resolver->getSisterProjectPrefixes()['voy'] );
		$this->assertArrayNotHasKey( 'commons', $resolver->getSisterProjectPrefixes() );

		// Test by-language lookup
		$this->assertEquals(
			[ 'fr' => 'frwiki' ],
			$resolver->getSameProjectWikiByLang( 'fr' )
		);
		$this->assertEquals(
			[ 'no' => 'nowiki' ],
			$resolver->getSameProjectWikiByLang( 'no' )
		);
		$this->assertEquals(
			[ 'no' => 'nowiki' ],
			$resolver->getSameProjectWikiByLang( 'nb' )
		);
		$this->assertEquals(
			[],
			$resolver->getSameProjectWikiByLang( 'ccc' )
		);
	}

	/**
	 * @dataProvider provideSiteMatrixTestCases
	 */
	public function testSiteMatrixResolver( $wiki, $what, $arg, $expected ) {
		$resolver = $this->getSiteMatrixInterwikiResolver( $wiki );
		switch( $what ) {
		case 'sisters':
			asort( $expected );
			$actual = $resolver->getSisterProjectPrefixes();
			asort( $actual );

			$this->assertEquals(
				$expected,
				$actual
			);
			break;
		case 'interwiki':
			$this->assertEquals(
				$expected,
				$resolver->getInterwikiPrefix( $arg )
			);
			break;
		case 'crosslang':
			$this->assertEquals(
				$expected,
				$resolver->getSameProjectWikiByLang( $arg )
			);
			break;
		default: throw new \Exception( "Invalid op $what" );
		}
	}

	public static function provideSiteMatrixTestCases() {
		return [
			'enwiki sisters' => [
				'enwiki',
				'sisters', null,
				[
					'wikt' => 'enwiktionary',
					'b' => 'enwikibooks',
					'n' => 'enwikinews',
					'q' => 'enwikiquote',
					's' => 'enwikisource',
					'v' => 'enwikiversity',
					'voy' => 'enwikivoyage'
				]
			],
			'enwikibook sisters' => [
				'enwikibooks',
				'sisters', null,
				[
					'wikt' => 'enwiktionary',
					'w' => 'enwiki',
					'n' => 'enwikinews',
					'q' => 'enwikiquote',
					's' => 'enwikisource',
					'v' => 'enwikiversity',
					'voy' => 'enwikivoyage'
				]
			],
			'mywiki sisters load only open projects' => [
				'mywiki',
				'sisters', null,
				[
					'wikt' => 'mywiktionary'
				],
			],
			'enwiki interwiki can find sister projects project enwikibooks' => [
				'enwiki',
				'interwiki', 'enwikibooks',
				'b'
			],
			'enwiki interwiki can find same project other lang: frwiki' => [
				'enwiki',
				'interwiki', 'frwiki',
				'fr'
			],
			'enwiki interwiki cannot find other project other lang: frwiktionary' => [
				'enwiki',
				'interwiki', 'frwiktionary',
				null
			],
			'enwiki interwiki can find project with non default lang: nowiki' => [
				'enwiki',
				'interwiki', 'nowiki',
				'no'
			],
			'enwiki interwiki ignore closed projects: mowiki' => [
				'enwiki',
				'interwiki', 'mowiki',
				null
			],
			'frwikinews interwiki ignore inexistent projects: mywikinews' => [
				'frwikinews',
				'interwiki', 'mywikinews',
				null
			],
			'enwiki cross lang lookup finds frwiki' => [
				'enwiki',
				'crosslang', 'fr',
				['fr' => 'frwiki'],
			],
			'enwiki cross lang lookup finds nowiki' => [
				'enwiki',
				'crosslang', 'nb',
				['no' => 'nowiki'],
			],
			'enwikinews cross lang lookup finds frwikinews' => [
				'enwikinews',
				'crosslang', 'fr',
				['es' => 'frwikinews'],
			],
			'enwikinews cross lang lookup finds frwikinews' => [
				'enwikinews',
				'crosslang', 'fr',
				['fr' => 'frwikinews'],
			],
		];
	}

	private function getCirrusConfigInterwikiResolver() {
		$this->setMwGlobals( [
			'wgCirrusSearchInterwikiSources' => [
				'voy' => 'enwikivoyage',
				'wikt' => 'enwiktionary',
				'b' => 'enwikibooks',
			],
			'wgCirrusSearchLanguageToWikiMap' => [
				'fr' => 'fr',
				'nb' => 'no',
			],
			'wgCirrusSearchWikiToNameMap' => [
				'fr' => 'frwiki',
				'no' => 'nowiki',
			]
		] );
		$resolver = MediaWikiServices::getInstance()
			->getService( InterwikiResolver::SERVICE );
		$this->assertEquals( CirrusConfigInterwikiResolver::class, get_class( $resolver ) );
		return $resolver;
	}

	private function getSiteMatrixInterwikiResolver( $wikiId ) {
		$conf = new \SiteConfiguration;
		$conf->settings = include( __DIR__ . '/resources/wmf/SiteMatrix_SiteConf_IS.php' );
		$conf->suffixes = include( __DIR__ . '/resources/wmf/suffixes.php' );
		$conf->wikis = self::readDbListFile( __DIR__ . '/resources/wmf/all.dblist' );

		$myGlobals = [
			'wgConf' => $conf,
			// Used directly by SiteMatrix
			'wgLocalDatabases' => $conf->wikis,
			// Used directly by SiteMatrix & SiteMatrixInterwikiResolver
			'wgSiteMatrixSites' => include( __DIR__ . '/resources/wmf/SiteMatrixProjects.php' ),
			// Used by SiteMatrix
			'wgSiteMatrixFile' => __DIR__ . '/resources/wmf/langlist',
			// Used by SiteMatrix
			'wgSiteMatrixClosedSites' => self::readDbListFile( __DIR__ . '/resources/wmf/closed.dblist' ),
			// Used by SiteMatrix
			'wgSiteMatrixPrivateSites' => self::readDbListFile( __DIR__ . '/resources/wmf/private.dblist' ),
			// Used by SiteMatrix
			'wgSiteMatrixFishbowlSites' => self::readDbListFile( __DIR__ . '/resources/wmf/fishbowl.dblist' ),

			// XXX: for the purpose of the test we need
			// to have wfWikiID() without DBPrefix so we can reuse
			// the wmf InterwikiCache which is built against WMF config
			// where no wgDBprefix is set.
			'wgDBprefix' => null,
			'wgDBname' => $wikiId,
			// Used by ClassicInterwikiLookup & SiteMatrixInterwikiResolver
			'wgInterwikiCache' => include( __DIR__ . '/resources/wmf/interwiki.php' ),
			// Reset values so that SiteMatrixInterwikiResolver is used
			'wgCirrusSearchInterwikiSources' => [],
			'wgCirrusSearchLanguageToWikiMap' => [],
			'wgCirrusSearchWikiToNameMap' => [],
		];
		$this->setMwGlobals( $myGlobals );
		$myGlobals['_wikiID'] = $wikiId;
		// We need to reset this service so it can load wgInterwikiCache
		MediaWikiServices::getInstance()
			->resetServiceForTesting( 'InterwikiLookup' );
		$config = new HashSearchConfig( $myGlobals, ['inherit'] );
		$resolver = MediaWikiServices::getInstance()
			->getService( InterwikiResolverFactory::SERVICE )
			->getResolver( $config );
		$this->assertEquals( SiteMatrixInterwikiResolver::class, get_class( $resolver ) );
		return $resolver;
	}

	private static function readDbListFile( $fileName ) {
		return @file( $fileName, FILE_IGNORE_NEW_LINES );
	}
}
