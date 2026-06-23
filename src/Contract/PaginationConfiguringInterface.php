<?php

namespace Fedale\GridviewBundle\Contract;

/**
 * Optional, segregated capability for a {@see PaginatorStrategyInterface} that needs
 * to influence how data is fetched before {@see DataProviderInterface::getData()}.
 *
 * Numeric and infinite scroll do NOT implement this (plain offset/limit). A virtual
 * scroll strategy of the "load everything" kind implements it to disable paging
 * (fetch-all), e.g. `$pagination->setPageSize(0)`.
 */
interface PaginationConfiguringInterface
{
    /**
     * Adapt the pagination right before the data fetch.
     *
     * @param array<string,mixed> $paginationOptions the grid's `options.pagination` bag
     */
    public function configurePagination(PaginationInterface $pagination, array $paginationOptions): void;
}
