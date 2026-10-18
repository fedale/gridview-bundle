<?php

namespace Fedale\GridviewBundle\Contract;

use Fedale\GridviewBundle\DataSource\GridQuery;

/**
 * Feeds a grid from any source: plain SQL, a domain service, a file, data
 * computed at runtime. Selected per grid with the `dataSource` key of
 * dataConfig(); the bundle parses filters, search, sort and pagination from
 * the request and hands the source a normalized {@see GridQuery}, so the source
 * only runs it. Prefer it over a hand-written {@see DataProviderInterface}
 * whenever the data is neither Doctrine entities nor a JSON API.
 *
 * Obligations of an implementation (they are part of the contract):
 *
 * - Attribute whitelisting. Every attribute in the criteria, the sort and the
 *   search fields must be validated against the source's own field
 *   definitions, and quoted (e.g. DBAL quoteSingleIdentifier()) before it
 *   reaches SQL. The bundle only passes attributes the grid declared, but a
 *   source building SQL from runtime metadata is the last line of defense
 *   against injection through dynamic identifiers.
 * - Criteria are AND-ed, each already normalized (see
 *   {@see \Fedale\GridviewBundle\DataSource\FilterCriterion}).
 * - Global search: a case-insensitive "contains" of the search term, OR-ed
 *   across the search fields, AND-ed with the criteria — the meaning
 *   LOWER(field) LIKE '%term%' has on an entity grid.
 */
interface GridDataSourceInterface
{
    /**
     * Number of rows matching the query criteria and search, ignoring sort and window.
     *
     * Called before fetch(): the pagination needs the total to clamp the
     * requested page before the window is computed.
     */
    public function count(GridQuery $query): int;

    /**
     * Rows of the requested window (offset/limit; limit null = all rows, used by
     * the export), filtered, searched and sorted. Each row is an array keyed by
     * column attribute.
     *
     * @return iterable<array<string, mixed>>
     */
    public function fetch(GridQuery $query): iterable;
}
