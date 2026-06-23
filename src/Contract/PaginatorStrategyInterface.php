<?php

namespace Fedale\GridviewBundle\Contract;

/**
 * A paginator strategy is the presentation/UX of the `{pagination}` layout token:
 * it picks the Twig partial that replaces the token and the Stimulus controllers
 * to wire on the grid container. Strategies are collected by name in
 * {@see \Fedale\GridviewBundle\Pagination\Strategy\PaginatorStrategyRegistry} and
 * selected per grid via `options.pagination.mode`.
 *
 * Data fetching (offset/limit) stays in {@see PaginationInterface}; a strategy that
 * needs to influence it (e.g. virtual scroll fetching the whole dataset) also
 * implements {@see PaginationConfiguringInterface}.
 */
interface PaginatorStrategyInterface
{
    /** Stable key used in `options.pagination.mode` (e.g. 'numeric', 'infinite'). */
    public function getName(): string;

    /** Twig template rendered in place of the `{pagination}` token. */
    public function getTemplate(): string;

    /**
     * Stimulus controllers merged into the grid container when this strategy is
     * active (empty for purely server-rendered strategies like numeric).
     *
     * @return list<string>
     */
    public function getControllers(): array;
}
