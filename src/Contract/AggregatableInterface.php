<?php

namespace Fedale\GridviewBundle\Contract;

/**
 * A data provider that can compute aggregate values over the whole filtered
 * result set (footer summaries), independent of pagination. Providers that
 * don't implement it degrade to page-scope aggregation (computed in PHP over
 * the current page's rows).
 */
interface AggregatableInterface
{
    /**
     * The root alias of the underlying query, so callers can qualify plain field
     * names into valid aggregate expressions (e.g. 'qty' -> 'e.qty').
     */
    public function getRootAlias(): string;

    /**
     * Run every footer aggregate in a single query over the filtered dataset,
     * ignoring the current page's limit and order. Keys are opaque aliases chosen
     * by the caller and echoed back in the result.
     *
     * @param array<string, string> $expressions alias => DQL expression (e.g. 'a0' => 'SUM(e.qty)')
     *
     * @return array<string, int|float|string|null>
     */
    public function aggregate(array $expressions): array;
}
