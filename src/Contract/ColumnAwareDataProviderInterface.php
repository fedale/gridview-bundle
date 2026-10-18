<?php

namespace Fedale\GridviewBundle\Contract;

/**
 * A data provider that applies the grid's filters and sort itself, from the
 * columns, instead of from a repository's search() or a DQL `search.map` /
 * `sort.map`. The grid hands it what its columns declare before
 * prepareModels().
 *
 * A grid whose provider implements this and declares no `sort.map` sorts on
 * the sortable columns' own attributes: the grid sets that identity map on the
 * Sort itself, so the provider reads it back from getSort() as usual.
 */
interface ColumnAwareDataProviderInterface
{
    /**
     * The filters the grid renders (filterable columns and `search.fields`),
     * keyed by request param key: [filter type, attribute, applier options].
     * The same shape as a `search.map` entry, with the attribute in place of
     * the DQL field.
     *
     * @param array<string, array{0: string, 1: string, 2?: array<string, mixed>}> $specs
     */
    public function setFilterSpecs(array $specs): void;
}
