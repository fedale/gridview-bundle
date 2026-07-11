# DetailView (single record)

`DetailView` is the single-record sibling of the grid: same column DNA, but it
renders **one** model as a vertical **key/value table** (the "show" view of a
CRUD) instead of a list. It has **no** filters, sort, pagination, global search,
Turbo-Frame switching or token layout — none of those apply to one record.

You declare columns **exactly as for the grid** (same `buildColumns()` array): the
detail reuses each column's `label` and cell renderer, ignoring the filter/sort
side entirely.

## Quick start

```php
use Fedale\GridviewBundle\Grid\GridviewBuilderFactory;
use Fedale\GridviewBundle\Row\Row;

$detail = $factory->createDetailViewBuilder()
    ->setId('customer')                 // YAML lookup key (see below)
    ->setModel($row)                    // a Row whose ->data is the record (see "The model")
    ->setColumns($columns)              // the SAME definitions used by the grid
    ->setOptions(['onlyVisible' => false])
    ->setAttributes(['class' => 'table table-bordered'])
    ->renderDetailView();               // → DetailView

return $detail->render();               // → Response (Twig key/value table)
```

The factory method mirrors `createGridviewBuilder()`:

```php
GridviewBuilderFactory::createDetailViewBuilder(): DetailViewBuilder
```

## Using the controller base

`AbstractDetailController` packages the lookup + wrapping + rendering, so a host
controller declares only its entity and columns. It is **not** a subclass of
`AbstractGridController`: a show shares only the columns, not the list machinery —
so concrete controllers usually move `buildColumns()` into a shared trait used by
both the grid and the detail controller, keeping them in sync.

```php
#[Route('/customer', name: 'customer_')]
final class CustomerDetailController extends AbstractDetailController
{
    protected function getDataClass(): string { return Customer::class; }

    protected function buildColumns(): array
    {
        return ['id', 'code', ['attribute' => 'active', 'type' => 'boolean']];
    }
}
// → GET /customer/{id}  renders the record as a key/value table
```

`show/{id}` is inherited (route name `customer_show`), `id` defaults to the entity
short name (`customer`). Override `findModel()` for custom lookup and `toRow()` for
custom normalization.

## The model — what `setModel()` expects

Cells are rendered with `column->render($model, 0)`, and a `DataColumn` reads
`$model->data[<attribute>]` — the same `Row` shape the data provider produces for
the grid. So `setModel()` wants a `Row` whose `->data` is the **normalized** record
array (dotted/nested attributes work just like in the grid):

```php
$row = new Row(0, 1);
$row->data = $serializer->normalize($entity);  // ObjectNormalizer + DateTimeNormalizer
```

`AbstractDetailController::toRow()` does exactly this for you (mirroring the
`EntityDataProvider` normalizer setup), so DateTime fields serialize to ISO strings
and `twigFilter`s like `date('d/m/Y')` keep working unchanged.

## Which columns are shown

Only **data columns** (`getAttribute() !== null`) appear — action/structural
columns (`action`, `checkbox`, `serial`) are skipped. By default **every** data
column is rendered, *including ones hidden in the grid* (`visible: false`), because
a show page usually wants the full record. Flip `onlyVisible: true` to honour grid
visibility instead.

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `emptyText` | `string` | `'No data'` | Shown when there are no data columns |
| `onlyVisible` | `bool` | `false` | When true, skip columns hidden in the grid (`visible: false`) |
| `template` | `string` | `@FedaleGridview/detailview/detailview.html.twig` | Template used by `render()` |

## Rendering

`render(?string $view = null)` returns a `Response` (defaulting to the `template`
option). The shipped template emits a `<table>` (HTML attributes from
`setAttributes()`) with one `<tr><th>label</th><td>value</td></tr>` per data
column, replicating the grid's `tbody` cell escaping (including per-column
`twigFilter`). For non-Twig consumers, `DetailView::rows()` returns a plain
`[['label' => …, 'value' => …], …]` array (note: `rows()` does not apply
`twigFilter` — use the template for that).

## YAML configuration

Detail views read YAML **like the grid**, but from a dedicated, **separate**
section — `detailviews.<id>` / `defaults.detailview` — so the grid-only keys
(`pagination`, `realtime`, `globalSearch`, table `layout`) never leak in:

```yaml
fedale_gridview:
  defaults:
    detailview:                       # defaults for ALL detail views
      options:
        emptyText: "No data"
      attributes:
        class: "table table-bordered"
  detailviews:                        # per-id override (id = entity short name, lowercased)
    customer:
      options:
        onlyVisible: true
      attributes:
        class: "table table-sm"
```

> A grid and a detail for the same entity **share the `id`** but live in separate
> sections (`gridviews.<id>` vs `detailviews.<id>`) — no semantic collision. The
> merge precedence mirrors the grid: built-in detail defaults < `defaults.detailview`
> < `detailviews.<id>` < runtime `setOptions()`/`setAttributes()`.
