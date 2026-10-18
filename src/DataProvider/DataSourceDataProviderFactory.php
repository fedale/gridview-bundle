<?php

namespace Fedale\GridviewBundle\DataProvider;

use Fedale\GridviewBundle\Contract\FilterCriteriaBuilderInterface;
use Fedale\GridviewBundle\Contract\GridDataSourceInterface;
use Fedale\GridviewBundle\Contract\PaginationInterface;
use Fedale\GridviewBundle\Contract\SearchModelInterface;
use Fedale\GridviewBundle\Contract\SortInterface;
use Fedale\GridviewBundle\Filter\Criteria\FilterCriteriaBuilderRegistry;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Builds the {@see DataSourceDataProvider} of one grid, wired to the same
 * sort, pagination and search model the entity provider shares. Stateless:
 * the per-request state lives on those shared services, which reset
 * themselves, and on the adapter, which dies with its grid.
 */
class DataSourceDataProviderFactory
{
    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly RequestStack $requestStack,
        private readonly FilterCriteriaBuilderRegistry $criteriaBuilderRegistry,
        private readonly SortInterface $sort,
        private readonly PaginationInterface $pagination,
        private readonly SearchModelInterface $searchModel,
    ) {
    }

    /**
     * @param array<string, FilterCriteriaBuilderInterface> $criteriaBuilders the grid's `search.builders`
     * @param string|list<string>                           $identifier
     */
    public function create(GridDataSourceInterface $dataSource, ?string $gridId = null, array $criteriaBuilders = [], string|array $identifier = 'id'): DataSourceDataProvider
    {
        $provider = new DataSourceDataProvider(
            $dataSource,
            $this->eventDispatcher,
            $this->requestStack,
            $this->criteriaBuilderRegistry,
            $gridId,
            $criteriaBuilders,
            $identifier,
        );

        $provider->setSort($this->sort);
        $provider->setPagination($this->pagination);
        $provider->setSearchModel($this->searchModel);

        return $provider;
    }
}
