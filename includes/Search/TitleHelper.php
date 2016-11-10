<?php

namespace CirrusSearch\Search;

use Elastica\Result;
use Elastica\ResultSet;
use Title;
use LinkBatch;
use CirrusSearch\InterwikiResolver;
use CirrusSearch\SearchConfig;
use MediaWiki\MediaWikiServices;

/**
 * Trait to build MW Title from elastica Result/ResultSet classes
 * This trait can be used in all classes that need to build a Title
 * by reading the elasticsearch output.
 */
trait TitleHelper {
	/**
	 * Create a title. When making interwiki titles we should be providing the
	 * namespace text as a portion of the text, rather than a namespace id,
	 * because namespace id's are not consistent across wiki's. This
	 * additionally prevents the local wiki from localizing the namespace text
	 * when it should be using the localized name of the remote wiki.
	 *
	 * @param Result $r int $namespace
	 * @param string $text
	 * @return Title
	 */
	public function makeTitle( Result $r ) {
		$iwPrefix = $this->identifyInterwikiPrefix( $r );
		if ( ! empty( $iwPrefix ) ) {
			$nsPrefix = $r->namespace_text ? $r->namespace_text . ':' : '';
			return Title::makeTitle( 0, $nsPrefix . $r->title, '', $iwPrefix );
		} else {
			return Title::makeTitle( $r->namespace, $r->title );
		}
	}

	/**
	 * Build a Title to a redirect, this always works for internal titles.
	 * For external titles we need to use the namespace_text which is only
	 * valid if the redirect namespace is equals to the target title namespace.
	 * If the namespaces do not match we return null.
	 *
	 * @param Result $r
	 * @param string $redirectText
	 * @param int $redirNamespace
	 * @return Title|null the Title to the Redirect or null if we can't build it
	 */
	public function makeRedirectTitle( Result $r, $redirectText, $redirNamespace ) {
		$iwPrefix = $this->identifyInterwikiPrefix( $r );
		if ( !empty( $iwPrefix ) ) {
			if ( $redirNamespace === $r->namespace ) {
				$nsPrefix = $r->namespace_text ? $r->namespace_text . ':' : '';
				return Title::makeTitle(
					0,
					$nsPrefix . $redirectText,
					'',
					$iwPrefix
				);
			} else {
				// redir namespace does not match, we can't
				// build this title.
				// The caller should fallback to the target title.
				return null;
			}
		} else {
			return Title::makeTitle( $redirNamespace, $redirectText );
		}
	}

	/**
	 * @return bool true if this result refers to an external Title
	 */
	public function isExternal( Result $r ) {
		if ( isset ( $r->wiki ) && $r->wiki !== wfWikiID() ) {
			return true;
		}
		// TODO: replace by return false when wiki is populated
		return !empty( $this->getConfig()->getWikiCode() );
	}

	/**
	 * @return string|null the interwiki prefix for this result or null or
	 * empty if local.
	 */
	public function identifyInterwikiPrefix( $r ) {
		if ( isset ( $r->wiki ) && $r->wiki !== wfWikiID() ) {
			return MediaWikiServices::getInstance()
				->getService( InterwikiResolver::SERVICE )
				->getInterwikiPrefix( $r->wiki );
		}
		// TODO: replace by return false when wiki is populated
		return $this->getConfig()->getWikiCode();
	}

	/**
	 * Loads the result set into the mediawiki LinkCache via a
	 * batch query. By pre-caching this we ensure methods such as
	 * Result::isMissingRevision() don't trigger a query for each and
	 * every search result.
	 *
	 * @param \Elastica\ResultSet $resultSet Result set from which the titles come
	 */
	protected function preCacheContainedTitles( \Elastica\ResultSet $resultSet ) {
		// We can only pull in information about the local wiki
		$lb = new LinkBatch;
		foreach ( $resultSet->getResults() as $result ) {
			if ( !$this->isExternal( $result ) ) {

				$lb->add( $result->namespace, $result->title );
			}
		}
		if ( !$lb->isEmpty() ) {
			$lb->setCaller( __METHOD__ );
			$lb->execute();
		}
	}

	/**
	 * TODO: remove when getWikiCode is removed
	 * @return SearchConfig
	 */
	public abstract function getConfig();
}
