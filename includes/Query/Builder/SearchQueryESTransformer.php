<?php

namespace CirrusSearch\Query\Builder;

use CirrusSearch\Search\SearchQuery;
use Elastica\Query\AbstractQuery;

interface SearchQueryESTransformer {
	public function transform( SearchQuery $query, QueryBuildingContext $buildingContext ): AbstractQuery;
}
