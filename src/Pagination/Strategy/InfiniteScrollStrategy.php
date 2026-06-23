<?php

namespace Fedale\GridviewBundle\Pagination\Strategy;

/**
 * Server-side progressive loading: as the user reaches the bottom, the next page
 * (plain offset/limit, same as numeric) is fetched and its rows are appended to the
 * current <tbody> via a Turbo Stream. Presentation-only — data fetching is unchanged,
 * so it does NOT implement PaginationConfiguringInterface.
 *
 * Progressive enhancement: a real "Load more" button is the no-JS fallback; the
 * Stimulus controller adds an IntersectionObserver that triggers it automatically.
 */
class InfiniteScrollStrategy extends AbstractPaginatorStrategy
{
    public function getName(): string
    {
        return 'infinite';
    }

    public function getTemplate(): string
    {
        return '@FedaleGridview/gridview/sections/paginators/infinite.html.twig';
    }

    public function getControllers(): array
    {
        return ['gridview-infinite-scroll'];
    }
}
