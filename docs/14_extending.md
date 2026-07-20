# Extending the Bundle

## Public interfaces

All extension points are backed by PHP interfaces. Depend on these when building integrations
rather than on the concrete classes:

| Interface | Namespace | Stable |
|-----------|-----------|--------|
| `GridviewInterface` | `Fedale\GridviewBundle\Grid` | ✓ |
| `GridviewBuilderInterface` | `Fedale\GridviewBundle\Grid` | ✓ |
| `ColumnInterface` | `Fedale\GridviewBundle\Column` | ✓ |
| `DataProviderInterface` | `Fedale\GridviewBundle\DataProvider` | ✓ |
| `SortInterface` | `Fedale\GridviewBundle\Component` | ✓ |
| `PaginationInterface` | `Fedale\GridviewBundle\Component` | ✓ |
| `SearchFormInterface` | `Fedale\GridviewBundle\Service` | ✓ |
| `SearchModelInterface` | `Fedale\GridviewBundle\Service` | ✓ |

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

## Creating a custom data provider

When `JsonDataProvider` doesn't fit — a non-JSON source, a CLI tool's output, bespoke pagination,
multi-field sort, POST requests — write your own implementation instead.

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

        $limit  = $this->pagination->getPageSize() ?? 10;
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
