<?php

namespace Fedale\GridviewBundle\DataSource;

/**
 * What a grid asks a {@see \Fedale\GridviewBundle\Contract\GridDataSourceInterface}
 * for: filters, global search, sort and the row window.
 */
final class GridQuery
{
    /**
     * @param mixed                 $source       the grid's dataConfig()['model'], passed through untouched
     *                                            (e.g. ['object' => 'pet'])
     * @param list<FilterCriterion> $criteria     AND-ed
     * @param list<string>          $searchFields the attributes the global search term is matched against
     * @param list<SortOrder>       $sort         in priority order
     * @param int|null              $limit        null = every row from $offset on (export)
     */
    public function __construct(
        public readonly ?string $gridId = null,
        public readonly mixed $source = null,
        public readonly array $criteria = [],
        public readonly ?string $searchTerm = null,
        public readonly array $searchFields = [],
        public readonly array $sort = [],
        public readonly int $offset = 0,
        public readonly ?int $limit = null,
    ) {
    }

    public function withWindow(int $offset, ?int $limit): self
    {
        return new self($this->gridId, $this->source, $this->criteria, $this->searchTerm, $this->searchFields, $this->sort, $offset, $limit);
    }
}
