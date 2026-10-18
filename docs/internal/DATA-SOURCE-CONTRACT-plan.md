# Plan: slim data-source contract (`GridDataSourceInterface`)

Status: **implemented** on branch `grid_data_source` (1.3.x-dev). It implements
[`DATA-SOURCE-CONTRACT-proposal.md`](DATA-SOURCE-CONTRACT-proposal.md); read the proposal first.
Follow `CLAUDE.md` for style, verification and repository conventions.

## Implementation notes (deviations from this plan)

- **`FilterOperator::Between`** added (`[low, high]`). The text `between` and the number ranges
  write `BETWEEN :a AND :b` today; splitting them into `Gte` + `Lte` would have changed the DQL of
  entity grids, which §3 forbids.
- **`IsNull` / `IsNotNull` dropped**: no builder produces them (as §2 allowed).
- **`ColumnAwareDataProviderInterface` has only `setFilterSpecs()`**. `Gridview` sets the identity
  sort map on the shared `Sort` itself, so the provider never needed `setSortableAttributes()`.
- **Sort is read through `Sort::fetchOrders()`**, not `fetchAttributesOrder()`: on a data-source
  grid the sort map's fields are source attributes (identity map, or an explicit `sort.map`), so
  expanding through the map is what honors an explicit `sort.map`.
- **`search.builders` reach the adapter through the factory** (`create($source, $gridId,
  $builders, $identifier)`), not through `SearchForm`: the adapter dies with its grid, so nothing
  per-grid lands on a shared service.
- **A filter type with no builder fails in `setFilterSpecs()`**, during `initializeDataProvider()`,
  not in `getData()`: `Gridview::renderGrid()` catches `getData()` failures and renders an empty
  grid with a logged error, which would have hidden a configuration error.
- **Applier options of a column filter** (`default_operator`, ...) come from an explicit
  `search.map` entry with the attribute in place of the DQL field; column specs carry none.
- **`EntityIdentifier::encodeParts()`** made public static, so data-source rows get tokens of the
  exact shape entity rows do.
- **Profiler**: the readable `GridQuery` is shown in the existing DQL slot; no template change.
- **Changelog**: the repository has no changelog file; the export `_q` fix is stated in
  `docs/10_export.md` instead.
- **Test baseline**: `main` already had 4 errors (`DoctrineChildRowResolverTest`) and 1 failure
  (`ThemeRegistryTest::testClassMapIsComplete`), 26 PHPStan errors outside the baseline and 46 files
  flagged by php-cs-fixer. The branch adds none; it removes 9 PHPStan baseline entries made obsolete
  by the applier refactor.

Revised on 2026-10-18 after a review against the code: the filter/sort hand-off from columns
(§4.1), the adapter construction (§4.2), the `dataSource`/`dataProvider` conflict rule and the host
entry point (§5), the operator vocabulary (§2), the "same DQL" criterion (§3) and the contract
obligations (§1) were made explicit.

Second revision on 2026-10-18 (consumer review): the search model a data-source grid needs to get
any filter (§5), the export entry point and the global search lost on export (§5), per-grid
criteria builders in place of DQL `search.appliers` (§4.2), the raw values of choice/relation
criteria (§2), the parameter type of boolean filters and the legacy `SearchForm::search()` /
`searchWithOperator()` left untouched (§3).

Third revision on 2026-10-18: the default search model set in `renderGridview()` came too late for
column filters, replaced by a fail-loud check (§5); the export `_q` fix is idempotent and declared
as the one intended behavior change for entity grids (§5, Done when); wording fixes in §2 and §4.2.
Then: how the check detects skipped filters (tracked by `Gridview`, columns and `search.fields`
alike, checked after `setDataProviderOptions()`), and a leftover "default" in Step 6.

## Why now: the trigger

The proposal is parked until "a grid is actually needed over a custom (non-Doctrine, non-JSON)
source". That case now exists: **`metadata-driven`** (sibling project, `../metadata-driven`) is a
Twenty-CRM-like meta-app whose objects are defined at runtime. Records live in physical PostgreSQL
tables (`data.c_<object>`) read with **DBAL**, not ORM entities: there is no entity class, no DQL,
no `QueryBuilder` of the ORM. It needs a grid per object, built at runtime from metadata.

Design `GridQuery` around that concrete consumer (requirements below), not for symmetry.

## Consumer requirements (metadata-driven)

- Rows are plain arrays keyed by field name; values are PHP typed: `string`, `int`, numeric
  `string` for decimals, `bool`, `\DateTimeImmutable` (date or date-time), `null`.
- Field types to display and filter: text, email, number (integer/decimal), boolean, date/date-time,
  select (stored value + label shown).
- Filters: text (contains and the operators the text filter already understands), number (exact or
  range), date range, boolean, choice (one or many values).
- Sort on any column (multi-sort welcome), global search over the text columns, page size selector,
  CSV export of the current filter + sort.
- Every row has a UUID `id`; rows link to the host's own edit page (CRUD forms are the host's, not
  the bundle's).
- One grid per object, created at runtime (grid id such as `record_pet`): nothing may require a
  per-grid YAML entry.
- The host resolves fields and SQL itself: the source receives **attribute names and normalized
  criteria**, never DQL or SQL.
- The consumer runs PHP 8.4 / Symfony 8.1; the bundle keeps its own floor (PHP 8.1, Symfony 6.4).

## Scope

In scope:

1. The new contract and its value objects.
2. A built-in adapter that lets any `GridDataSourceInterface` power a grid through the existing
   pipeline, with **no change to `Gridview`'s use of `DataProviderInterface`**; the only addition
   in `Gridview` is the optional column hand-off of §4.1, invisible to entity and JSON grids.
3. Neutral filter criteria, produced by the same parsing the DQL appliers use, so a filter means the
   same thing whatever the source.
4. Selecting a data source from `dataConfig()` at runtime.
5. Tests, docs, version bump.

Out of scope (state it in the docs): porting `EntityDataProvider` or `JsonDataProvider` onto the new
contract; grouping (`GroupingCapableInterface`), aggregates (`AggregatableInterface`), scope checks
(`ScopeVerifiableInterface`), Mercure, and the bundle CRUD/detail controllers for data-source grids
(they assume an entity `data_class`). They keep working exactly as today for entity grids.

## Design

### 1. Contract (`src/Contract/`)

```php
interface GridDataSourceInterface
{
    /** Number of rows matching the query filters and search, ignoring sort and window. */
    public function count(GridQuery $query): int;

    /**
     * Rows of the requested window (offset/limit; limit null = all rows, used by export),
     * filtered, searched and sorted. Each row is an array keyed by column attribute.
     *
     * @return iterable<array<string, mixed>>
     */
    public function fetch(GridQuery $query): iterable;
}
```

`count()` and `fetch()` are separate on purpose, instead of the proposal's single
`fetch(): GridResult`: `Pagination::getCurrentPage()` clamps the requested page against the total and
caches the result on first read (see the comment in `JsonDataProvider::getData()`), so the total must
be known **before** the offset is computed. A separate `count()` keeps that ordering inside the
adapter and keeps sources trivial. Record this deviation in the proposal file.

Obligations to state in the interface docblock (they are part of the contract, not advice):

- **Attribute whitelisting.** Every `attribute` in criteria, sort and search fields must be
  validated by the source against its own field definitions, and identifiers must be quoted
  (e.g. DBAL `quoteSingleIdentifier()`) before reaching SQL. The bundle only passes attributes it
  registered, but a source building SQL from runtime metadata is the last line of defence against
  injection through dynamic identifiers.
- **Global search semantics.** Case-insensitive "contains" of the term, OR-ed across the
  `searchFields`, AND-ed with the criteria: the same meaning as `EntityDataProvider::applyGlobalSearch()`
  (`LOWER(field) LIKE %term%`).
- **Criteria are AND-ed**, each one already normalized (see §2).

### 2. Value objects (`src/DataSource/`, immutable, PHP 8.1 compatible: `readonly` properties, no readonly classes)

- `GridQuery`
  - `string $gridId` (nullable if the grid has no id)
  - `mixed $source`: the `model` entry of `dataConfig()`, passed through untouched (the consumer uses
    it to carry the object name, e.g. `['object' => 'pet']`)
  - `list<FilterCriterion> $criteria`
  - `?string $searchTerm`, `list<string> $searchFields` (global search, from `behavior.globalSearch`)
  - `list<SortOrder> $sort` (attribute + direction, in priority order)
  - `int $offset`, `?int $limit`
  - `withWindow(int $offset, ?int $limit): self` (used by the adapter for count/page/export)
- `SortOrder`: `string $attribute`, `SortDirection $direction` (enum `Asc`/`Desc`).
- `FilterCriterion`: `string $attribute`, `FilterOperator $operator`, `mixed $value`,
  `bool $caseInsensitive = false` (list for `In`, `{from, to}` never: a range becomes two criteria
  `Gte` + `Lte`).
- `enum FilterOperator`: `Eq`, `Neq`, `Lt`, `Lte`, `Gt`, `Gte`, `Contains`, `NotContains`,
  `StartsWith`, `EndsWith`, `In`, `IsNull`, `IsNotNull`. Case sensitivity is the
  `caseInsensitive` flag, not extra enum cases. The mapping from `TextFilterApplier` must be
  complete and lossless, so the DQL appliers can be rebuilt on it (§3):

  | Text operator (after alias resolution) | Criterion |
  |---|---|
  | `eq` / `ieq` | `Eq`, ci = false / true |
  | `neq` / `ineq` | `Neq`, ci = false / true |
  | `gt` `gte` `lt` `lte` | same name, ci = false |
  | `like` / `ilike` (and the malformed-`between` fallback) | `Contains`, ci = false / true |
  | `nlike` / `nilike` | `NotContains`, ci = false / true |
  | `startwith` / `istartwith` | `StartsWith`, ci = false / true |
  | `endwith` | `EndsWith`, ci = false (there is no `iendwith` today; do not add one) |
  | `in` | `In`, value = trimmed non-empty list |
  | `between` | `Gte` + `Lte` on the trimmed bounds |
  | user edge wildcard (`%foo%`, `foo%`, `%foo`) | `Contains` / `StartsWith` / `EndsWith`, **always ci = true** |

  `IsNull`/`IsNotNull` have no producer today: keep them only if a builder emits them, otherwise
  drop them from v1 (no speculative cases).

Semantics to document on `FilterCriterion`: values are already parsed and typed (numbers as
`int|float|string` numeric, dates as `\DateTimeImmutable`, booleans as `bool`); a date `Lte` bound
already includes `end_of_day` when the filter asks for it, so sources never re-implement it.
Document the known limit of that `end_of_day` bound: `<= YYYY-MM-DD 23:59:59` misses timestamps
with fractional seconds after 23:59:59. Changing it to an exclusive `< next day` would alter entity
grids, so it stays as is in this plan; note it as a possible follow-up.

Exception, to state explicitly: choice and relation criteria (`Eq`/`In`) carry the **raw request
values** (strings, blanks removed), exactly what `ChoiceFilterApplier` binds today; casting them to
the column type (int, UUID, ...) is the source's job.

### 3. One parser, two outputs (no behaviour change for entity grids)

Today each `*FilterApplier::apply(QueryBuilder, dqlField, rawValue, options)` both **parses** the raw
request value and **writes DQL**. Split it:

- Add `FilterCriteriaBuilderInterface::build(string $attribute, mixed $rawValue, array $options): list<FilterCriterion>`,
  one implementation per `FilterType` (text, number, date, boolean, choice; relation → `In`/`Eq` on
  the raw identifiers), registered like the appliers (look at `FilterApplierRegistry` and
  `config/services.yaml`, and mirror it).
- Move the parsing (text operator prefixes and edge wildcards, number range separator and bounds,
  ISO date + `end_of_day`, boolean truthiness, blank handling of `AbstractFilterApplier::isBlank()`)
  into the builders, and make the **existing DQL appliers consume `build()`'s criteria** to write
  their DQL. The DQL produced for every case must stay the same: same expressions, same parameter
  values, same parameter **types**. Parameter *names* are not comparable (`uniqueParam()` uses
  `uniqid()`), so tests must normalize them, not compare raw DQL strings. The existing applier tests
  (today only Text and Date) are the safety net; add the missing cases, including Number, Boolean,
  Choice and Relation, **before** refactoring.
- Parameter types: `DateFilterApplier` binds `\DateTime`, and Doctrine infers a different parameter
  type for `\DateTimeImmutable`. Criteria carry `\DateTimeImmutable`; the DQL applier converts back
  with `\DateTime::createFromImmutable()` so entity grids bind exactly what they bind today.
  Likewise `BooleanFilterApplier` binds with an explicit `Types::BOOLEAN`: criteria carry no DBAL
  type, so the DQL applier keeps passing it.
- Out of scope: the legacy `SearchForm::search()` / `searchWithOperator()` (public API called by
  repositories' own `search()`, with its own operator parsing). Leave them untouched, quirks
  included, for BC; the single source of truth covers the applier path only.
- If moving the parsing out of an applier proves risky for one type, extract the parsing into a
  shared class used by both, rather than duplicating it: one source of truth is the goal.

### 4. Adapter: `DataSourceDataProvider` (`src/DataProvider/`)

Extends `AbstractDataProvider`, wraps a `GridDataSourceInterface`, implements the existing interface
so `Gridview`, exporters, profiler and paginator strategies are untouched.

- `setFormName()` / `setDefaultParams()`: read the filter params from the request under the form name,
  with the same "defaults only when the form is absent from the query string" rule as
  `EntityDataProvider::setDefaultParams()`. Extract that rule into a shared helper (trait on
  `AbstractDataProvider` or a small class) instead of copying it.
- `prepareModels($models)`: store `$models` as `GridQuery::$source`.
- `setSearchFields($map)`: accepts an explicit `search.map` if one is given (same keys, the
  `dqlField` slot read as the source attribute), but a data-source grid must not need one; see §4.1.
- `applyGlobalSearch($fields, $term)`: store them on the query.
- Sort: translate `Sort::fetchAttributesOrder()` (attribute → direction) to `SortOrder`s, in
  priority order. Read the attribute-level orders, not `fetchOrders()`, which returns the DQL
  fields of `sort.map`.

#### 4.1 Column hand-off (the one change in `Gridview`)

Today a column filter is only **added to the form** (`Gridview::setColumns()`, the
`searchForm->addFilter()` call): nothing reaches the provider, and the query applies it only
through a `search.map` entry or the repository's `search()`. Likewise a sortable column produces a
sort link only if `sort.map` declares it (`Sort::hasAttribute()`). A data-source grid has neither,
so the adapter needs the column specs from `Gridview`:

- Add an optional capability `Contract\ColumnAwareDataProviderInterface` with
  `setFilterSpecs(array $specs): void` (`paramKey => [type, attribute, options]`, the same shape as
  `search.map`) and `setSortableAttributes(array $attributes): void` (`list<string>`).
- In `Gridview::initializeDataProvider()`, before `prepareModels()`: if the provider implements the
  capability, pass it the filter specs collected from the filterable columns and from
  `search.fields` (the same loop data `registerSearchFields()` and `setColumns()` already have:
  collect them there into a private array), and the attributes of the columns marked sortable.
- When the provider implements the capability and `sort.map` is empty, `Gridview` sets the sort map
  to identity over those attributes (`Sort::setAttributes(['name', 'email', ...])`; a plain list is
  already accepted and expanded by `prepareSortAttributes()`). An explicit `sort.map` still wins.
- Param key vs attribute: `Gridview::filterParamKey()` turns dots into underscores; the spec keeps
  both, so a dotted attribute still works. Entity and JSON providers do not implement the
  capability: their behavior is untouched.

#### 4.2 Construction and per-request state

The adapter is **not** created with `new` in the builder: it needs the shared sort, pagination,
search model, search form (for the shared criteria-builder registry and the per-grid
`search.builders`), event dispatcher and request stack, which the entity provider gets from its `calls:` in
`config/services.yaml`.

- Register `fedale_gridview.data_source_provider_factory` (`DataSourceDataProviderFactory`) with
  those dependencies; `create(GridDataSourceInterface $source): DataSourceDataProvider` returns a
  fully wired adapter.
- The adapter is a per-grid object, not a container service: **do not** tag it `kernel.reset`. All
  its state lives on the instance and dies with the grid; the shared collaborators (sort,
  pagination, search form) are already reset by their own services. The factory itself is
  stateless.
- Criteria builders resolve like the appliers: per-grid first, then the shared registry. Per-grid
  `search.appliers` are DQL appliers and cannot serve a data-source grid, so add a
  `search.builders` key (filter type → `FilterCriteriaBuilderInterface`), mirroring
  `SearchForm::setAppliers()`. A data-source grid that declares `search.appliers` throws an
  `\InvalidArgumentException` naming the grid; a filter type with no builder throws too (never
  skipped silently).
- `getData()`: build the query, `count()` → `Pagination::setTotalCount()` **first**, then
  `fetch($query->withWindow($pagination->getOffset(), $pagination->getPageSize()))`.
- `getAllData()`: `fetch($query->withWindow(0, null))`, same filters, search and sort.
- Rows: wrap each array in `Row`, dispatch `RowEvent::BEFORE_ROW` / `AFTER_ROW` like the other
  providers, set `Row::identifierToken` and `data[EntityIdentifier::ROW_KEY]` from the
  `identifier` key of `dataConfig()` (default `'id'`), so links and checkboxes work.
- Profiler: implement `getParams()` and `getFilterPath()` (`'dataSource'`), and a `getDebugQuery()`
  that shows the `GridQuery` (criteria, sort, window) instead of DQL.

### 5. Selecting a data source at runtime

In `dataConfig()` (`GridviewBuilder::setDataProvider()` options):

```php
return [
    'dataSource' => $this->recordGridSource,   // GridDataSourceInterface instance, or a service id
    'model'      => ['object' => 'pet'],       // passed through as GridQuery::$source
    'identifier' => 'id',
    'pagination' => ['defaultPageSize' => 25],
];
```

- `GridviewBuilder::renderGridview()` (where the YAML `dataProvider` id is resolved today): when
  `dataSource` is present, build the adapter through the factory (§4.2) and set it on the grid. A
  service id is resolved through a locator of services tagged `fedale_gridview.data_source`
  (autoconfigure `GridDataSourceInterface`, mirroring how `fedale_gridview.data_provider` is
  registered in `FedaleGridviewBundle::loadExtension()`).
- Conflict rule: `dataSource` **overrides** `defaults.dataProvider` (a host may set a global
  default provider; that must not break every data-source grid). It is a configuration error only
  together with a `dataProvider` declared **for that grid** (`gridviews.<id>.dataProvider`, or a
  `dataProvider` key in the same `dataConfig()`): throw an `\InvalidArgumentException` naming the
  grid. `GridviewConfigRegistry` must therefore tell a per-grid value from the inherited default;
  add a method for it rather than comparing ids.
- No per-grid YAML is required; `defaults.*` still apply.
- `Gridview::getDataClass()` stays null for these grids (the `model` is not a class name).

Host entry point: `AbstractGridController` requires `getDataClass(): string` and derives the grid
id by reflection on that class, so it cannot serve a data-source grid. In scope: the host builds the
grid through `GridviewBuilderFactory` directly (document a complete controller example in
`docs/14_extending.md`, with both the list and the export action). Two traps that example and the
builder must close:

- **Search model.** Filters reach the form only when a search model is set (`Gridview::setColumns()`
  and `registerSearchFields()` both check `isset($this->searchModel)`); today
  `AbstractGridController` sets it. Without it the form is empty and §4.1 collects nothing, with no
  error. Setting a default search model in `renderGridview()` is **too late**:
  `GridviewBuilder::setColumns()` forwards the columns to `Gridview` immediately, and the column
  filters are registered right there, so by `renderGridview()` that loop has already run (only
  `search.fields` would still be picked up). Instead, fail loud: in `renderGridview()`, when
  `dataSource` is present, no search model is set and at least one filter was skipped for that
  reason, throw a `\LogicException` naming the grid (and the skipped filters) and saying to call
  `setSearchModel()` **before** `setColumns()`. The documented controller example does exactly
  that; `SearchModelInterface` is already an autowirable alias, so the host just injects it.
  - Detection: today both `Gridview::setColumns()` and `registerSearchFields()` skip a filter
    silently when no search model is set, leaving no trace `renderGridview()` could inspect. Make
    `Gridview` record them: a private list of the skipped filter names, appended in both places,
    exposed through a read-only getter (e.g. `getFiltersSkippedForMissingSearchModel(): list<string>`).
    Recording changes nothing for entity grids, which never read it.
  - Order of the check: `registerSearchFields()` runs inside `setDataProviderOptions()`, called by
    `renderGridview()`, so the check must run **after** `setDataProviderOptions()` to cover
    `search.fields` as well as the columns. (Deferring column-filter
  registration to `initializeDataProvider()` would remove the ordering constraint, but changes the
  build order of entity grids too: not in this plan.)
- **Export.** `AbstractGridController::export()` is the only export action, so the host writes its
  own (`getExportRows()` + `getExportColumns()` + exporter). `Gridview::getExportRows()` never calls
  `applyGlobalSearch()` (only `renderGrid()` does), so an export ignores `_q`, for entity grids too.
  Fix it in `getExportRows()` (read `_q` the same way as `renderGrid()`, extracted into one private
  method), since metadata-driven requires the export of the current filter, search and sort. The
  extracted method records that the search has been applied and is a no-op on a second call:
  `EntityDataProvider::applyGlobalSearch()` adds an `andWhere` on every call. This is a deliberate
  **behavior change for entity grids** (a bug fix): state it in the changelog.

Out of scope: a controller base class without an entity; list it as a follow-up if
`metadata-driven` ends up duplicating the same controller code per object.

Display of select fields ("stored value, label shown") is a column concern, not a source one: use
the existing column value callback / choice formatting; the source returns the stored value only,
so filters and sort work on it.

## Steps

1. Branch from `main`. Bump `extra.branch-alias.dev-main` to `1.3.x-dev` (new feature, BC kept).
2. Applier tests: add the missing cases for every filter type, run them green on the current code.
3. Value objects and `FilterOperator`; criteria builders; refactor appliers onto them. Tests green,
   DQL unchanged in the sense of §3 (expressions, values, parameter types).
4. `GridDataSourceInterface` (with the docblock obligations of §1), `DataSourceDataProvider`, its
   factory (§4.2), request/default-params helper shared with `EntityDataProvider`.
5. `ColumnAwareDataProviderInterface` and the hand-off in `Gridview` (§4.1).
6. Runtime selection in `GridviewBuilder`, conflict rule, locator + autoconfiguration,
   missing-search-model check (with the skipped-filter tracking in `Gridview`), `search.builders`, global search applied in `getExportRows()`
   (§4.2, §5).
7. Tests (below), `composer cs`, `composer test`, `composer phpstan` (no new baseline entries).
8. Docs: a "Custom data source" section in `docs/14_extending.md` (when to choose it over a
   `DataProviderInterface`, a full example with an in-memory source and a host controller built on
   `GridviewBuilderFactory`, the criteria semantics and source obligations, the
   out-of-scope list), a short pointer in `docs/agents.md`, and the status of the proposal file set
   to "implemented — see the plan" with the `count()`/`fetch()` deviation explained.

## Tests

- Criteria builders: one data-provider test per filter type covering every operator, blank values,
  ranges, `end_of_day`, wildcards; the DQL appliers' existing tests unchanged and green.
- `DataSourceDataProvider` with an in-memory test source (`tests/Fixtures/`): receives the right
  `GridQuery` (criteria, search, sort, window) for a given request; `count()` is called before the
  window is read; requesting a page past the end clamps to the last page; defaults apply only when
  the form is absent; export gets `limit: null`; rows carry `identifierToken`.
- Criteria mapping: one case per row of the operator table in §2, including the edge-wildcard rows
  (always case-insensitive) and the malformed-`between` fallback.
- DQL parity: compare expressions and parameter values/types with parameter names normalized; date
  filters still bind `\DateTime`.
- Column hand-off: a provider implementing `ColumnAwareDataProviderInterface` receives the specs of
  filterable columns and `search.fields` (dotted attribute included) and the sortable attributes;
  with no `sort.map` the sort links render; an explicit `sort.map` wins; entity grids never receive
  the calls.
- Factory: the created adapter shares the container's sort/pagination/search form.
- Builder: `dataSource` as instance and as service id; unknown id throws; `dataSource` + a per-grid
  `dataProvider` throws with the grid id in the message; `dataSource` + only
  `defaults.dataProvider` does **not** throw and uses the data source.
- Search model: a `dataSource` grid with no search model throws a `\LogicException` naming the
  grid both when a filterable column is skipped and when only a `search.fields` entry is skipped;
  with no filters at all it does not throw; with `setSearchModel()` called before `setColumns()`
  its column filters render and reach §4.1; an entity grid without a search model is unaffected.
- Per-grid builders: `search.builders` is used before the registry; `dataSource` +
  `search.appliers` throws; a filter type with no builder throws.
- Export: `getExportRows()` applies `_q` for both an entity grid and a data-source grid, and only
  once when the same grid has already been rendered (no duplicated `andWhere`).
- A rendering test of a whole grid over the in-memory source (filters, sort links, pagination
  render), following the existing functional/rendering test style of the repository.

## Done when

- Entity and JSON grids behave exactly as before (whole suite green, DQL unchanged as defined in §3),
  with one intended exception: exports now honor the global search `_q` (§5), noted in the
  changelog.
- A grid over an in-memory `GridDataSourceInterface` filters, searches, sorts, paginates and exports
  with only a `dataConfig()` and columns, no YAML.
- `composer cs`, `composer test`, `composer phpstan` pass; docs updated; branch alias `1.3.x-dev`.
- Report back: the final public API (class names, `dataConfig()` keys), anything that deviated from
  this plan and why, and the commit/branch to require from `metadata-driven`.
