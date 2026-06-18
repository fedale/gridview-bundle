<?php

namespace Fedale\GridviewBundle\Contract;

interface DataProviderInterface
{
    public function prepareModels(string|array $models);

    public function setDefaultParams(array $defaults): void;

    /** Root alias for the fallback QueryBuilder built when the repository has no search(). */
    public function setAlias(string $alias): void;

    /**
     * Declarative filter map ([param => [type, dqlField, options?]]) applied to
     * the fallback QueryBuilder. Ignored when the repository implements search().
     *
     * @param array<string, array> $searchFields
     */
    public function setSearchFields(array $searchFields): void;

    /**
     * Entity attribute names to skip when normalizing rows.
     *
     * @param string[] $attributes
     */
    public function setIgnoredAttributes(array $attributes): void;

    public function getData();

    /** All rows matching the current filters/sort, without pagination (for export). */
    public function getAllData();

    public function getSort();

    public function getPagination();

    public function applyGlobalSearch(array $fields, string $term): void;
}
