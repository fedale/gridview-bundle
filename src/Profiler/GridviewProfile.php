<?php

namespace Fedale\GridviewBundle\Profiler;

/**
 * Immutable snapshot of a single grid render, captured at the end of
 * {@see \Fedale\GridviewBundle\Grid\Gridview::renderGrid()} and consumed by the
 * WebProfiler data collector. It carries only serializable scalars/arrays so the
 * collector can store it without cloning live services.
 */
final class GridviewProfile
{
    /**
     * @param array<string, mixed>                   $options    fully merged grid options
     * @param array<string, mixed>                   $rawParams  raw submitted filter-form params
     * @param array<string, mixed>                   $filters    active (non-empty) filters
     * @param array<string, mixed>                   $query      dql/sql/params/rootAlias
     * @param array<string, mixed>                   $sort       orders/urlSort/multiSort
     * @param array<string, mixed>                   $pagination mode/page/pageSize/offset/total/pages/options
     * @param list<string>                           $renderers  available view names
     * @param list<string>                           $filterBarColumns filter-bar column attributes
     * @param list<array<string, mixed>>             $columns    per-column flag rows
     */
    public function __construct(
        public readonly string $key,
        public readonly ?string $id,
        public readonly string $responseType,
        public readonly ?string $route,
        public readonly string $template,
        public readonly string $theme,
        public readonly array $options,
        public readonly string $formName,
        public readonly string $filterPath,
        public readonly ?string $globalSearch,
        public readonly array $rawParams,
        public readonly array $filters,
        public readonly array $query,
        public readonly array $sort,
        public readonly array $pagination,
        public readonly string $renderer,
        public readonly array $renderers,
        public readonly bool $autoBar,
        public readonly array $filterBarColumns,
        public readonly int $rowsOnPage,
        public readonly array $columns,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
