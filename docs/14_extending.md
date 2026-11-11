# Extending the Bundle

## Public interfaces

All extension points are backed by PHP interfaces. Depend on these when building integrations
rather than on the concrete classes:

| Interface | Namespace | Stable |
|-----------|-----------|--------|
| `GridviewInterface` | `Fedale\GridviewBundle\Contract` | ✓ |
| `GridviewBuilderInterface` | `Fedale\GridviewBundle\Contract` | ✓ |
| `ColumnInterface` | `Fedale\GridviewBundle\Contract` | ✓ |
| `DataProviderInterface` | `Fedale\GridviewBundle\Contract` | ✓ |
| `SortInterface` | `Fedale\GridviewBundle\Contract` | ✓ |
| `PaginationInterface` | `Fedale\GridviewBundle\Contract` | ✓ |
| `SearchFormInterface` | `Fedale\GridviewBundle\Contract` | ✓ |
| `SearchModelInterface` | `Fedale\GridviewBundle\Contract` | ✓ |
| `GridDataSourceInterface` | `Fedale\GridviewBundle\Contract` | ✓ |
| `FilterCriteriaBuilderInterface` | `Fedale\GridviewBundle\Contract` | ✓ |
| `ColumnAwareDataProviderInterface` | `Fedale\GridviewBundle\Contract` | ✓ |

> **Upgrading from 1.3.0 or earlier.** `PaginationInterface` now declares
> `getDefaultPageSize(): int`, the page size a data provider falls back to when
> `getPageSize()` returns `null`. The bundle's `Pagination` class already had it, so
> nothing changes if you use or extend that class. If your app implements
> `PaginationInterface` directly, add the method — PHP refuses to load a class that
> implements the interface without it:
>
> ```php
> public function getDefaultPageSize(): int
> {
>     return 20; // or the default page size your pagination is configured with
> }
> ```

## Request-scoped services

Several of the bundle's services hold state that belongs to one request: the filter
form, the sort attributes, the page number, the query builder and the rows built from
it. They are all shared Symfony services, so that state has to be read and discarded
per request rather than captured once.

Two rules follow, and they matter if you extend `Sort`, `Pagination`,
`EntityDataProvider`, `SearchModel` or `GridviewService`.

### Read the request through `request()`, never store it

These classes keep the `RequestStack` and read the current request on every access:

```php
// In a subclass of Sort or Pagination
$page = $this->request()?->query->get('page');
```

`request()` is `protected` in `Sort` and `Pagination`, and returns `null` outside a
request. `GridviewService::getRequest()` is the `public` equivalent and is also
nullable, so check the result instead of dereferencing it:

```php
$request = $gridviewService->getRequest();
if (null === $request) {
    return;
}
```

Do not capture the request in a constructor or a setter:

```php
// Wrong: this pins one request for the lifetime of the service
public function __construct(RequestStack $requestStack)
{
    $this->request = $requestStack->getCurrentRequest();
}
```

Under PHP-FPM that works, because the container is rebuilt for every request. Under a
runtime that keeps the container alive — FrankenPHP worker mode, RoadRunner, Swoole —
the captured request is whichever one happened to build the container, and every later
request reads its query string instead of its own. The grid then sorts and pages by the
wrong request while the result count, which is recomputed each time, stays correct — so
the symptom reads as a data bug rather than a caching one.

> **Upgrading from 1.2.1 or earlier.** `Sort` and `Pagination` used to expose a
> `protected $request` property holding a `Request`. It is gone: replace
> `$this->request` with `$this->request()` in any subclass.
> `GridviewService::getRequest()` also changed from `Request` to `?Request`, which
> static analysis reports at any dereference that assumes non-null.

### Reset what your subclass adds

Each of these services is tagged `kernel.reset`, so Symfony calls `reset()` between
requests. If your subclass adds request-scoped state of its own, override `reset()` and
clear it, calling the parent:

```php
final class TenantAwareSort extends Sort
{
    private ?string $tenant = null;

    public function reset(): void
    {
        parent::reset();

        $this->tenant = null;
    }
}
```

Without this the leftover value is served to the next request on a long-running worker.
Under PHP-FPM `reset()` runs on a process that is ending anyway, so it costs nothing and
changes nothing — you never have to choose between the two runtimes.

A custom data provider registered as a service is worth the same treatment: if it caches
a query, a result set or the parsed request params, tag it `kernel.reset` and clear them.

## Creating a custom column

1. Implement `ColumnInterface` (or extend `AbstractColumn` for convenience).
2. Register the type with `ColumnFactory`.

```php
// src/Column/StatusBadgeColumn.php
namespace App\Column;

use Fedale\GridviewBundle\Column\AbstractColumn;

class StatusBadgeColumn extends AbstractColumn
{
    public function __construct(
        private \Fedale\GridviewBundle\Grid\Gridview $gridview,
        private string $attribute,
        protected ?string $twigFilter = 'raw',
        protected ?string $label = null,
        protected ?array $options = [],
    ) {
        $this->sortable = false;
    }

    public function getAttribute(): string { return $this->attribute; }

    public function render(mixed $row, int $_index): mixed
    {
        $value = $row->data[$this->attribute] ?? null;
        $class = match ($value) {
            'active'   => 'bg-success',
            'inactive' => 'bg-secondary',
            default    => 'bg-warning',
        };
        return sprintf('<span class="badge %s">%s</span>', $class, htmlspecialchars((string) $value));
    }
}
```

Register via a compiler pass, then use in a controller:

```php
// config/services.yaml
services:
    App\Column\StatusBadgeColumn:
        tags:
            - { name: fedale_gridview.column, type: status_badge }

// In a controller:
$columns = [
    ['type' => 'status_badge', 'attribute' => 'status', 'label' => 'Status'],
];
```

Or register directly via `ColumnFactory::register()`:

```php
$columnFactory->register('status_badge', StatusBadgeColumn::class);
```

### An optional fluent builder for your type

The built-in [fluent builders](02_columns.md#fluent-builder-api) are plain
classes that assemble the column spec, so you can ship one for a custom type too.
Extend `AbstractColumnConfig`, return your type from `columnType()` (or set
`$this->spec['type']` when the type has no `ColumnType` case), and add any
type-specific sugar:

```php
use Fedale\GridviewBundle\Column\Config\AbstractColumnConfig;

class StatusBadgeColumn extends AbstractColumnConfig
{
    protected static function columnType(): ColumnType
    {
        return ColumnType::Badge;
    }

    public function palette(string $name): static
    {
        return $this->format(['palette' => $name]);
    }
}
```

`StatusBadgeColumn::new('status')->palette('traffic')` then works anywhere an
array spec does, because `ColumnFactory::create()` accepts any
`ColumnConfigInterface`.

## The built-in JsonDataProvider

Before writing your own, check whether the ready-made `JsonDataProvider` already fits. It backs a
grid with any JSON-over-HTTP endpoint — including one behind an authorization token — configured
entirely from the grid's `dataConfig()['model']`, so a plain token-authenticated API needs **no
provider class at all**.

It needs `symfony/http-client` (an optional dependency of the bundle):

```bash
$ composer require symfony/http-client
```

Once installed, the service `Fedale\GridviewBundle\DataProvider\JsonDataProvider` is registered
automatically. Select it per grid and describe the endpoint in the controller's `dataConfig()`:

```php
// src/Controller/Gridview/ProductController.php
namespace App\Controller\Gridview;

use App\Model\Product;
use Fedale\GridviewBundle\Controller\AbstractGridController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class ProductController extends AbstractGridController
{
    public function __construct(
        // The token stays out of the codebase: it comes from the environment,
        // and only the controller composes it into a request header.
        #[Autowire('%env(INTERNAL_API_TOKEN)%')]
        private readonly string $apiToken,
    ) {
    }

    protected function getDataClass(): string
    {
        return Product::class;
    }

    protected function dataConfig(): array
    {
        return [
            'model' => [
                'baseUri'   => 'https://api.example.com',
                'resource'  => 'products',
                'listPath'  => 'products',   // where the row list lives in the body
                'totalPath' => 'total',      // where the total count lives
                'headers'   => ['Authorization' => 'Bearer ' . $this->apiToken],
            ],
            'pagination' => ['defaultPageSize' => 20],
        ];
    }

    protected function buildColumns(): array
    {
        return ['id', 'title', ['attribute' => 'price', 'type' => 'number']];
    }
}
```

Then point the grid at the provider from config — no other wiring needed:

```yaml
# config/packages/gridview.yaml
fedale_gridview:
    gridviews:
        product: # id of the grid backed by App\Model\Product
            dataProvider: Fedale\GridviewBundle\DataProvider\JsonDataProvider
```

### The `model` keys

Every key lives under `dataConfig()['model']`. Only `baseUri` and `resource` are required.

| Key | Default | Purpose |
|-----|---------|---------|
| `baseUri` | *(required)* | API root, e.g. `https://api.example.com`. |
| `resource` | *(required)* | Path appended to `baseUri`, e.g. `products`. |
| `searchResource` | `resource` | Path used instead of `resource` while a global-search term is active (some APIs expose a separate search endpoint). |
| `listPath` | *(body)* | Dot-path to the row list in the response. Omit when the body is itself a JSON array. |
| `totalPath` | `null` | Dot-path to the total count. When set, paging is pushed to the API; when omitted, the full list is fetched once and paged in memory. |
| `headers` | `[]` | Static request headers sent on every call — this is where an `Authorization` token goes. |
| `query` | `[]` | Static query params sent on every call (e.g. an API key or a fixed filter). |
| `params` | *(see below)* | Renames the emitted query params. Set any value to `null` to omit that param entirely. |

`params` defaults map the logical name to the query-string name the API expects:

```php
'params' => [
    'limit'  => 'limit',   // page size
    'offset' => 'skip',    // row offset
    'search' => 'q',       // global-search term
    'sort'   => 'sortBy',  // sort field
    'order'  => 'order',   // sort direction (asc/desc)
],
```

Dot-paths let you read nested payloads, for example `listPath: data.items` and
`totalPath: meta.total`. Sorting pushes the grid's first active sort to `sort`/`order`; searching
pushes the term to `search` (switching to `searchResource` if set).

If the endpoint is unreachable or answers non-2xx (for example a `401` from a wrong or missing
token), the `HttpClient` call throws. The grid catches that failure so it never takes down the
host page: the page (menu, sidebar, layout) renders as usual and the grid body shows an
explanatory message (`display.dataErrorText`, localized) in place of the rows. The exception
detail is shown only in debug and is logged (when a logger is available) so the failure stays
discoverable in production. This applies to any `DataProviderInterface`, not just this one.

> **Read-only.** Like every non-Doctrine source, a JSON-backed grid has no write path — extend
> `AbstractGridController`, not `AbstractCrudGridController`. See the note at the end of the next
> section.

## Feeding a grid from a custom data source

When the rows are neither Doctrine entities nor a JSON API — plain SQL through DBAL, a domain
service, records whose fields are defined at runtime — implement `GridDataSourceInterface`. It's
the shortest path to a non-Doctrine grid: the bundle still parses the filters, the global search,
the sort and the page from the request, and hands your source one normalized `GridQuery`. Your
source only runs it.

Write a full `DataProviderInterface` (see the next section) only when you need control over the
pipeline itself — your own request parsing, your own row building.

### The contract

```php
use Fedale\GridviewBundle\DataSource\GridQuery;

interface GridDataSourceInterface
{
    /** Rows matching the criteria and search, ignoring sort and window. */
    public function count(GridQuery $query): int;

    /** The window of rows (limit null = every row, for the export), filtered, searched, sorted. */
    public function fetch(GridQuery $query): iterable; // of arrays keyed by column attribute
}
```

`count()` is always called first: the pagination needs the total to clamp the requested page
before it computes the offset.

A `GridQuery` carries:

| Property | What it holds |
|---|---|
| `gridId` | the grid's id (`record_pet`) |
| `source` | the grid's `dataConfig()['model']`, passed through untouched (`['object' => 'pet']`) |
| `criteria` | `list<FilterCriterion>`, AND-ed |
| `searchTerm`, `searchFields` | the global search term and the attributes it's matched against |
| `sort` | `list<SortOrder>` (attribute + `SortDirection::Asc`/`Desc`), in priority order |
| `offset`, `limit` | the row window; `limit` is null for the export |

Each `FilterCriterion` is an `attribute`, a `FilterOperator`, a `value` and a `caseInsensitive`
flag. The operators are `Eq`, `Neq`, `Lt`, `Lte`, `Gt`, `Gte`, `Contains`, `NotContains`,
`StartsWith`, `EndsWith`, `In` (a non-empty list) and `Between` (a `[low, high]` pair, inclusive).
Values arrive already parsed: the filter syntax users type ("`>= 10`", "`btw 1 and 5`",
"`%rex`") is the same on a data-source grid as on an entity grid, because both are built by the
same criteria builders.

- Text values are strings, without wildcard characters.
- Number values are `int` or `float`.
- Date values are `\DateTimeImmutable`. The upper bound of a date range already sits at 23:59:59
  (the filter's `end_of_day` option), so it misses timestamps with fractional seconds after that.
- Booleans are `bool`.
- Choice and relation values are the raw submitted strings, blanks removed: cast them to the
  field's type (int, UUID) yourself.

**Your source must whitelist attributes.** Validate every attribute in the criteria, the sort and
the search fields against your own field definitions, and quote identifiers before they reach SQL.
The bundle only passes attributes the grid declared, but a source that builds SQL from runtime
metadata is the last line of defense against injection through dynamic identifiers.

The global search means a case-insensitive "contains" of the term, OR-ed across `searchFields` and
AND-ed with the criteria — what `LOWER(field) LIKE '%term%'` does on an entity grid.

### A source over DBAL

```php
// src/Grid/RecordGridSource.php
namespace App\Grid;

use App\Metadata\ObjectRegistry;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Fedale\GridviewBundle\Contract\GridDataSourceInterface;
use Fedale\GridviewBundle\DataSource\FilterOperator;
use Fedale\GridviewBundle\DataSource\GridQuery;

final class RecordGridSource implements GridDataSourceInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ObjectRegistry $objects,
    ) {}

    public function count(GridQuery $query): int
    {
        return (int) $this->filtered($query)->select('COUNT(*)')->fetchOne();
    }

    public function fetch(GridQuery $query): iterable
    {
        $qb = $this->filtered($query)->select('*')->setFirstResult($query->offset);
        if ($query->limit !== null) {
            $qb->setMaxResults($query->limit);
        }
        foreach ($query->sort as $order) {
            $qb->addOrderBy($this->column($query, $order->attribute), $order->direction->value);
        }

        return $qb->fetchAllAssociative();
    }

    private function filtered(GridQuery $query): QueryBuilder
    {
        $object = $this->objects->get($query->source['object']);
        $qb = $this->connection->createQueryBuilder()
            ->from($this->connection->quoteSingleIdentifier($object->table()));

        foreach ($query->criteria as $i => $criterion) {
            $column = $this->column($query, $criterion->attribute);
            if ($criterion->caseInsensitive) {
                $column = 'LOWER(' . $column . ')';
            }
            $param = 'c' . $i;
            $value = $criterion->value;

            match ($criterion->operator) {
                FilterOperator::Eq => $qb->andWhere("$column = :$param"),
                FilterOperator::Gte => $qb->andWhere("$column >= :$param"),
                FilterOperator::Lte => $qb->andWhere("$column <= :$param"),
                FilterOperator::Contains => $qb->andWhere("$column LIKE :$param"),
                FilterOperator::In => $qb->andWhere("$column IN (:$param)"),
                // ... the other operators
            };

            if ($criterion->operator === FilterOperator::Contains) {
                $value = '%' . $value . '%';
            }
            if ($criterion->caseInsensitive) {
                $value = is_array($value) ? array_map('mb_strtolower', $value) : mb_strtolower($value);
            }
            $qb->setParameter($param, $value, is_array($value) ? ArrayParameterType::STRING : null);
        }

        if ($query->searchTerm !== null && $query->searchFields !== []) {
            $search = [];
            foreach ($query->searchFields as $field) {
                $search[] = 'LOWER(' . $this->column($query, $field) . ') LIKE :q';
            }
            $qb->andWhere('(' . implode(' OR ', $search) . ')')
                ->setParameter('q', '%' . mb_strtolower($query->searchTerm) . '%');
        }

        return $qb;
    }

    /** The whitelist: only a field the object defines ever reaches the SQL, quoted. */
    private function column(GridQuery $query, string $attribute): string
    {
        $object = $this->objects->get($query->source['object']);
        if (!$object->hasField($attribute)) {
            throw new \InvalidArgumentException(sprintf('Unknown field "%s".', $attribute));
        }

        return $this->connection->quoteSingleIdentifier($object->field($attribute)->column());
    }
}
```

A class implementing `GridDataSourceInterface` is autoconfigured with the
`fedale_gridview.data_source` tag, so a grid can also name it by service id.

### Selecting the source for a grid

Put the source under the `dataSource` key of the data config, as an instance or a service id. No
YAML entry is needed, so grids created at runtime work out of the box:

```php
$gridview = $builderFactory->createGridviewBuilder()
    ->setId('record_pet')
    ->setSearchModel($searchModel)                 // before setColumns(), see below
    ->setDataProvider([
        'dataSource' => $this->recordGridSource,   // or 'App\Grid\RecordGridSource'
        'model'      => ['object' => 'pet'],       // passed through as GridQuery::$source
        'identifier' => 'id',                      // the row key links and checkboxes use
        'pagination' => ['defaultPageSize' => 25],
    ])
    ->setColumns($columns)
    ->setOptions(['behavior' => ['globalSearch' => ['name', 'email']]])
    ->renderGridview();
```

- **Filters come from the columns.** Each filterable column (and each `search.fields` entry) is
  applied on its own attribute; no `search.map` is needed. To pass applier options to a column
  filter — `default_operator`, `end_of_day`, `range_separator` — add a `search.map` entry with the
  attribute in place of the DQL field: `'name' => ['text', 'name', ['default_operator' => 'eq']]`.
- **Sorting comes from the columns too.** Without a `sort.map`, every sortable column sorts on its
  own attribute. An explicit `sort.map` still wins, and its fields are source attributes.
- **Global search fields** are attribute names (`'name'`), not DQL fields (`'c.name'`).
- **`identifier`** is the row key (or a list of keys, for a composite one) the grid turns into the
  token of each row — the same token format an entity grid uses. It defaults to `'id'`.
- **Your own filter types** go in `search.builders` (filter type → a
  `FilterCriteriaBuilderInterface`), consulted before the built-in builders. `search.appliers` write
  DQL, so a data-source grid that declares them is refused, and so is a filter type with no builder.
- **`dataSource` overrides `defaults.dataProvider`**, but declaring a `dataProvider` for the same
  grid (`gridviews.<id>.dataProvider`) throws.

> **Call `setSearchModel()` before `setColumns()`.** The column filters are registered on the
> filter form when `setColumns()` runs, and only if the grid has a search model by then. A
> data-source grid whose filters were dropped this way throws a `LogicException` naming them,
> rather than rendering without them. `SearchModelInterface` is autowirable: inject it into your
> controller.

### A controller for runtime grids

`AbstractGridController` derives everything from an entity class, so a data-source grid is built
from `GridviewBuilderFactory` directly. One controller can serve every object:

```php
// src/Controller/RecordGridController.php
namespace App\Controller;

use App\Grid\RecordGridSource;
use App\Metadata\ObjectRegistry;
use Fedale\GridviewBundle\Contract\SearchModelInterface;
use Fedale\GridviewBundle\Export\GridExporterRegistry;
use Fedale\GridviewBundle\Grid\Gridview;
use Fedale\GridviewBundle\Grid\GridviewBuilderFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/records/{object}', name: 'record_')]
final class RecordGridController extends AbstractController
{
    public function __construct(
        private readonly GridviewBuilderFactory $builders,
        private readonly SearchModelInterface $searchModel,
        private readonly RecordGridSource $source,
        private readonly ObjectRegistry $objects,
    ) {}

    #[Route('', name: 'index')]
    public function index(string $object): Response
    {
        return $this->grid($object)->renderGrid('record/index.html.twig');
    }

    #[Route('/export', name: 'export')]
    public function export(string $object, Request $request, GridExporterRegistry $exporters): Response
    {
        $format = (string) $request->query->get('format', 'csv');
        if (!$exporters->has($format)) {
            throw $this->createNotFoundException();
        }

        // The same filters, global search and sort as the list, every row.
        $gridview = $this->grid($object);

        return $exporters->get($format)->export(
            $gridview->getExportRows(),
            $gridview->getExportColumns(),
            ['filename' => $object],
        );
    }

    private function grid(string $object): Gridview
    {
        $definition = $this->objects->get($object);

        return $this->builders->createGridviewBuilder()
            ->setId('record_' . $object)
            ->setSearchModel($this->searchModel)
            ->setDataProvider(['dataSource' => $this->source, 'model' => ['object' => $object]])
            ->setColumns($definition->gridColumns())
            ->setOptions([
                'behavior' => ['globalSearch' => $definition->textFields()],
                'integration' => ['routeName' => 'record_index'],
            ])
            ->renderGridview();
    }
}
```

The rows reach column callbacks as arrays. To show a select field's label while the source returns
its stored value, map it in the column's value callback: filters and sort keep working on the
stored value.

### What a data-source grid doesn't do

The bundle's CRUD and detail controllers, grouping (`GroupingCapableInterface`), footer aggregates
(`AggregatableInterface`), the children scope check (`ScopeVerifiableInterface`) and Mercure
auto-refresh assume Doctrine entities, and a data-source grid doesn't get them. Link the rows to
your own edit pages through the row token. Entity grids keep all of these, unchanged.

In the WebProfiler, a data-source grid shows the `GridQuery` it sent (criteria, search, sort,
window) where an entity grid shows its DQL.

## Creating a custom data provider

When neither `JsonDataProvider` nor a [data source](#feeding-a-grid-from-a-custom-data-source)
fits — bespoke request parsing, your own row building, POST requests — write your own
implementation instead.

The default `DataProviderInterface` implementation, `EntityDataProvider`, reads from Doctrine.
To back a grid with something else — an HTTP API, a CLI tool's output, anything iterable — write
your own implementation and select it **per grid, from config**: `gridviews.<id>.dataProvider:
<service id>` (or `defaults.dataProvider` for every grid in the app). Every other grid keeps the
bundle's default (Doctrine-backed) provider untouched.

1. Extend `AbstractDataProvider`, which already implements the boilerplate setters
   (`setDefaultParams`, `setAlias`, `setSearchFields`, `setIgnoredAttributes`,
   `setPagination`/`getPagination`, `setSort`/`getSort`). You only need to implement:
   `prepareModels()`, `setFormName()`, `getData()`, `getAllData()`, `applyGlobalSearch()`.

```php
// src/DataProvider/HttpApiDataProvider.php
namespace App\DataProvider;

use Doctrine\Common\Collections\ArrayCollection;
use Fedale\GridviewBundle\DataProvider\AbstractDataProvider;
use Fedale\GridviewBundle\Event\RowEvent;
use Fedale\GridviewBundle\Row\Row;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class HttpApiDataProvider extends AbstractDataProvider
{
    private string $resource;
    private ?string $searchTerm = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly string $baseUri,
    ) {}

    public function prepareModels(string|array $models): void
    {
        $this->resource = is_string($models) ? $models : ($models['resource'] ?? '');
    }

    public function setFormName(string $formName): void
    {
        // No-op unless you build a filter form keyed under this param.
    }

    public function applyGlobalSearch(array $fields, string $term): void
    {
        $this->searchTerm = $term;
    }

    public function getData()
    {
        // IMPORTANT: set totalCount before reading getOffset(). Pagination::
        // getCurrentPage() clamps the requested page against the page count on
        // its first call and caches the result — reading the offset first
        // clamps against a stale totalCount of 0 and yields a negative offset
        // on any page beyond the first. EntityDataProvider does the same:
        // it runs getTotalCount() before setFirstResult()/setMaxResults().
        $this->pagination->setTotalCount($this->fetchTotalCount());

        $limit  = $this->pagination->getPageSize() ?? $this->pagination->getDefaultPageSize();
        $offset = $this->pagination->getOffset();

        $payload = $this->fetch($limit, $offset);
        $this->models = $this->buildRows($payload['items'] ?? [], $limit, $offset);

        return $this->models;
    }

    public function getAllData()
    {
        $payload = $this->fetch(0, 0); // 0 = "no limit", if your API supports it
        return $this->buildRows($payload['items'] ?? [], 0, 0);
    }

    private function fetchTotalCount(): int
    {
        return (int) ($this->fetch(1, 0)['total'] ?? 0);
    }

    private function buildRows(array $items, int $pageSize, int $offset): ArrayCollection
    {
        $rows = new ArrayCollection();
        foreach ($items as $key => $item) {
            $row = new Row($key, $pageSize, $offset);
            $row->data = $item;

            $event = new RowEvent();
            $event->row = $row;
            $this->eventDispatcher->dispatch($event, RowEvent::BEFORE_ROW);
            $rows->add($row);
            $this->eventDispatcher->dispatch($event, RowEvent::AFTER_ROW);
        }
        return $rows;
    }

    private function fetch(int $limit, int $offset): array
    {
        $query = ['limit' => $limit, 'offset' => $offset];
        if ($this->searchTerm) {
            $query['q'] = $this->searchTerm;
        }
        foreach ($this->getSort()->fetchOrders() as $field => $direction) {
            $query['sortBy'] = $field;
            $query['order'] = $direction;
            break; // single-field sort, adapt if your API supports multi-sort
        }

        return $this->httpClient
            ->request('GET', $this->baseUri . '/' . $this->resource, ['query' => $query])
            ->toArray();
    }
}
```

2. Wire `setSort`/`setPagination` explicitly — they're setter injection, not constructor args, so
   plain autowiring won't call them. Mirror the bundle's own wiring of
   `fedale_gridview.entity_data_provider` in `vendor/fedale/gridview-bundle/config/services.yaml`:

```yaml
# config/services.yaml
services:
    App\DataProvider\HttpApiDataProvider:
        arguments:
            $baseUri: 'https://api.example.com'
        calls:
            - [setSort, ['@fedale_gridview.sort']]
            - [setPagination, ['@fedale_gridview.pagination']]
```

   A class implementing `DataProviderInterface` is auto-tagged `fedale_gridview.data_provider` as
   soon as it's autoconfigured (the default for any app service under Symfony's `services.yaml`
   `App\` resource) — no manual tag needed, just the `calls:` above.

3. Select it for one grid via `gridviews.<id>.dataProvider`, or for every grid via
   `defaults.dataProvider`. The id is the grid's own id — `strtolower((new
   ReflectionClass($this->getDataClass()))->getShortName())` unless a controller overrides it via
   `viewConfig()['id']` (see `AbstractGridController::defaultConfig()`):

```yaml
# config/packages/gridview.yaml
fedale_gridview:
    gridviews:
        customer: # id of the grid backed by App\Model\Customer
            dataProvider: App\DataProvider\HttpApiDataProvider
```

   No controller code is needed: `GridviewBuilder::renderGridview()` resolves the configured
   service id through a locator of every tagged `DataProviderInterface` implementation and swaps
   it in before the grid renders. An unknown/mistyped service id throws `InvalidArgumentException`
   at render time, naming the grid id and the id it couldn't resolve.

> **Read-only.** `GridCrudHandlerInterface` (add/edit/delete/batch/inline) is hardwired to
> Doctrine's `EntityManagerInterface` — there is no pluggable write path today. Extend
> `AbstractGridController`, not `AbstractCrudGridController`, for a non-Doctrine data source.

### Alternative: swapping the provider at runtime

`gridviews.<id>.dataProvider` is a *static* choice, resolved once from config. For a provider
picked dynamically per request (a feature flag, a per-tenant setting, an A/B test), override
`buildGridview()` in that one controller instead — same effect, decided in PHP instead of YAML:

```php
use Fedale\GridviewBundle\Grid\Gridview;

class ApiBackedController extends AbstractGridController
{
    protected function buildGridview(): Gridview
    {
        $gridview = parent::buildGridview();
        $gridview->setDataProvider($this->container->get(HttpApiDataProvider::class));

        return $gridview;
    }

    public static function getSubscribedServices(): array
    {
        return array_merge(parent::getSubscribedServices(), [HttpApiDataProvider::class]);
    }
}
```

## Describing and validating grid configuration

Code that writes grid configuration rather than a person typing it (an app builder, a code
generator, an MCP server an AI agent calls) needs two things the guides can't give it: the list of
what this application has registered, and a way to check what it produced. The bundle provides both
as services.

### What a grid can be configured with: `GridCapabilities`

`GridCapabilities::describe()` returns plain data, read from the registries at call time, so the
types and formats your application registers are included:

| Key | Content |
|---|---|
| `columnTypes`, `filterTypes`, `filterOperators`, `controlTypes` | registered type names |
| `exportFormats` | `[{key, label}]` of every exporter |
| `themes`, `paginationModes`, `crudModes`, `renderers` | the valid values of those options |
| `layout` | `regions` (with their default children), `blocks` (the block tokens), `tableTokens` |
| `optionsSchema` | the JSON Schema of the grid options |

```php
use Fedale\GridviewBundle\Capabilities\GridCapabilities;

final class GridToolbox
{
    public function __construct(private readonly GridCapabilities $capabilities)
    {
    }

    public function describeGrids(): array
    {
        return $this->capabilities->describe();
    }
}
```

The same data is available from the console, whole or one section at a time:

```bash
php bin/console gridview:capabilities
php bin/console gridview:capabilities optionsSchema
```

### Checking options before they reach a grid: `GridOptionsValidator`

`GridOptionsValidator::validate()` runs the checks of [strict mode](11_configuration.md#strict-options)
on any options array and returns every violation, each with a `path` and a `message`, instead of
throwing:

```php
use Fedale\GridviewBundle\Grid\Options\GridOptionsValidator;

$violations = $this->optionsValidator->validate([
    'display'  => ['renderer' => 'cards'],
    'behavior' => ['globalSearch' => 'c.name'],
]);

foreach ($violations as $violation) {
    // display.renderer: Unknown renderer "cards"; the grid would fall back to the table. Did you mean "card"? ...
    // behavior.globalSearch: Expected list of string, got string.
    echo $violation, "\n";
}
```

`assertValid($options, $gridId)` throws an `InvalidGridOptionsException` instead; its
`getViolations()` returns the same list. Use `validate()` in a tool loop: give the violations back
to whoever wrote the options, let them fix the options, and check again.

The validator covers the grid **options** (`display`, `behavior`, `integration`, `actionLayout`).
Columns are covered by their fluent builders, whose typed methods are the schema; the
`dataConfig()` array is not validated.

## Listening to row events

`RowEvent` is dispatched twice for every data row — before and after it is added to the
collection. Use a Symfony event subscriber to modify row data or HTML attributes:

```php
// src/EventSubscriber/MyRowSubscriber.php
use Fedale\GridviewBundle\Event\RowEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class MyRowSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            RowEvent::BEFORE_ROW => 'onBeforeRow',
        ];
    }

    public function onBeforeRow(RowEvent $event): void
    {
        // Highlight overdue rows
        if (($event->row->data['due_date'] ?? null) < new \DateTimeImmutable()) {
            $event->row->setAttr('class', 'table-danger');
        }
    }
}
```

Tag the subscriber with `kernel.event_subscriber` or rely on Symfony's autoconfigure.

> **Not for hiding rows.** Row events fire *after* the paginator has sliced the
> current page, while the total count is computed at the DB level — so skipping a
> row here would leave short or empty pages and a wrong total. To make entire rows
> visible only to some users, filter in the query instead — see
> [Hiding rows by permission](04_filtering.md#hiding-rows-by-permission-filter-in-the-query).

---

[← Previous: Real-time updates (Mercure)](13_real-time.md) · [Documentation index](index.md) · [Next: Full Example →](15_full-example.md)
