<?php

namespace CirrusSearch;

/**
 * Factory class used to create InterwikiResolver
 */
class InterwikiResolverFactory {
	const SERVICE = 'CirrusSearchInterwikiResolverFactory';

	private function __construct() {
		$this->resolvers = new \MapCacheLRU( 100 );
	}

	/**
	 * @return InterwikiResolverFactory
	 */
	public static function newFactory() {
		return new InterwikiResolverFactory();
	}

	/**
	 * @param SearchConfig $config
	 * @return InterwikiResolver
	 */
	public function getResolver( SearchConfig $config ) {
		return $this->buildNewResolver( $config );
	}

	/**
	 * @param SearchConfig $config
	 * @return InterwikiResolver
	 */
	private function buildNewResolver( SearchConfig $config ) {
		if ( CirrusConfigInterwikiResolver::accepts( $config ) ) {
			return new CirrusConfigInterwikiResolver( $config );
		}
		if ( SiteMatrixInterwikiResolver::accepts( $config ) ) {
			return new SiteMatrixInterwikiResolver( $config );
		}
		return new EmptyInterwikiResolver();
	}
}
