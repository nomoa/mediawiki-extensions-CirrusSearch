<?php

namespace CirrusSearch\Maintenance;

use CirrusSearch\CirrusTestCase;
use CirrusSearch\Connection;
use CirrusSearch\HashSearchConfig;
use CirrusSearch\Profile\SearchProfileService;
use Elastica\Client;
use Elastica\Response;

/**
 * @covers \CirrusSearch\Maintenance\IndexTemplateBuilder
 */
class IndexTemplateBuilderTest extends CirrusTestCase {
	public function test() {
		$testProfile = 'glent';
		$expected = CirrusTestCase::fixturePath( "indexTemplateBuilder/$testProfile.expected" );
		$client = $this->createMock( Client::class );
		$client->expects( $this->once() )
			->method( 'request' )
			->will( $this->returnCallback(
				function ( $path, $method, $data ) use( $expected ) {
					$fixture = [
						'path' => $path,
						'method' => $method,
						'data' => $data
					];
					$this->assertFileContains(
						$expected,
						CirrusTestCase::encodeFixture( $fixture ),
						CirrusTestCase::canRebuildFixture()
					);
					return new Response( [], 200 );
				} )
			);
		$config = new HashSearchConfig( [] );
		$connection = $this->createMock( Connection::class );
		$connection->method( 'getClient' )
			->willReturn( $client );

		$connection->method( 'getConfig' )
			->willReturn( $config );

		$profile = ( $config )->getProfileService()
			->loadProfileByName( SearchProfileService::INDEX_LOOKUP_FALLBACK, $testProfile );
		$this->assertArrayHasKey( 'index_template', $profile );
		$tmplBuilder = IndexTemplateBuilder::build( $connection, $profile['index_template'], [ 'analysis-icu' ] );
		$tmplBuilder->execute();
	}

	/**
	 * @expectedException \RuntimeException
	 */
	public function testFailure() {
		$testProfile = 'glent';
		$client = $this->createMock( Client::class );
		$client->expects( $this->once() )
			->method( 'request' )
			->willReturn( new Response( [], 400 ) );
		$config = new HashSearchConfig( [] );
		$connection = $this->createMock( Connection::class );
		$connection->method( 'getClient' )
			->willReturn( $client );

		$connection->method( 'getConfig' )
			->willReturn( $config );

		$profile = ( $config )->getProfileService()
			->loadProfileByName( SearchProfileService::INDEX_LOOKUP_FALLBACK, $testProfile );
		$this->assertArrayHasKey( 'index_template', $profile );
		$tmplBuilder = IndexTemplateBuilder::build( $connection, $profile['index_template'], [ 'analysis-icu' ] );
		$tmplBuilder->execute();
	}
}