<?php

namespace CirrusSearch\ML;

use Elastica\Query\AbstractQuery;

class FeatureCollector {
	/** @var AbstractQuery $filter */
	private $filter;

	/** @var AbstractQuery[] $queries indexed by feature name */
	private $queries = [];

	/** @var AbstractQuery[] $qirescores indexed by feature name */
	private $qirescores = [];

	/** @var AbstractQuery[] $qdrescores indexed by feature name */
	private $qdrescores = [];

	/** @param AbstractQuery $filter */
	public function setFilter( AbstractQuery $filter ) {
		$this->filter = $filter;
	}

	/**
	 * @param string $feature
	 * @param AbstractQuery $query
	 */
	public function addQuery( $feature, AbstractQuery $query ) {
		$this->queries[$feature] = $query;
	}

	/**
	 * @param string $feature
	 * @param AbstractQuery $query
	 */
	public function addQIRescore( $index, $functionType, $functionParams, $filter = null, $weight = null );
		$field = isset( $functionParams['field'] ) ? $functionParams['field'] : 'UNK';
		$this->qirescores["$index-$functionParams-$field"] = [
			'function_type' => $functionType,
			'function_params' => $functionParams,
			'filter' => $filter,
			'weight' => $weight,
		];
	}

	/**
	 * @param string $feature
	 * @param AbstractQuery $query
	 */
	public function addQDRescore( $feature, AbstractQuery $query ) {
		$this->qdrescores[$feature] = $query;
	}
}
