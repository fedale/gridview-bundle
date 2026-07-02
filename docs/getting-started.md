# Getting Started

## Overview

FedaleGridviewBundle renders paginated, sortable, filterable HTML tables inside a Symfony application.
It integrates with:

- **Doctrine ORM** (entity-based data providers)
- **Symfony Forms** (filter forms via `SearchModel`)
- **Turbo / Hotwired** (frame-based partial reloads, zero full-page refreshes by default)
- **Stimulus** (JS controllers for filters, row selection, column visibility)

The entry point is always a `GridviewBuilder` chain called from a controller action.

---

## Quick Start

The recommended way to expose a grid is to extend one of the bundle's
[controller base classes](crud.md#controller-base-classes): you declare the entity, the
columns and the data-provider config, and the `index` / `export` actions (plus
their routes) come for free. No constructor, no manual builder wiring.

### 1. Create a controller

```php
use App\Entity\Customer\Customer;
use Fedale\GridviewBundle\Controller\AbstractGridController;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/gridview/customer', name: 'gridview_customer_')]
class CustomerController extends AbstractGridController
{
    protected function getDataClass(): string
    {
        return Customer::class;
    }

    protected function dataConfig(): array
    {
        return [
            'model'      => Customer::class,
            'pagination' => ['defaultPageSize' => 25],
            'sort'       => [
                'map' => [
                    'name'  => ['asc' => ['c.name'], 'desc' => ['c.name'], 'default' => 'asc'],
                    'email' => ['asc' => ['c.email'], 'desc' => ['c.email'], 'default' => 'asc'],
                ],
            ],
        ];
    }

    protected function buildColumns(): array
    {
        return ['id', 'name', 'email'];
    }
}
```

That's it: the single `#[Route]` prefix yields `gridview_customer_index` and
`gridview_customer_export`, and the grid id defaults to the entity short name
(`customer`). For write operations (`new`, `update`, `delete`, bulk, inline, …)
extend `AbstractCrudGridController` instead — see
[Controller base classes](crud.md#controller-base-classes).

### 2. The index template

`index` renders the template named by the `indexTemplate` config key, which
defaults to `gridview/with_sidebar.html.twig` (a host-app template). Point it at
the bundle's bare layout, or your own, via [`viewConfig()`](crud.md#the-viewconfig-array):

```php
protected function viewConfig(): array
{
    return ['indexTemplate' => '@FedaleGridview/gridview/index.html.twig'];
}
```

The grid renders itself inside that template — no extra Twig is needed. When a
Turbo-Frame request arrives, the bundle automatically switches to the internal
`_grid.html.twig` partial so only the table content is reloaded.

> **Lower-level API.** Under the hood these controllers drive a `GridviewBuilder`
> obtained from `GridviewBuilderFactory`. You can use that builder directly from a
> plain controller when you need a fully custom action — the
> [Full Example](full-example.md#full-example) shows the manual `createGridviewBuilder()` chain,
> and most sections below illustrate options through it.

---

## Data Provider

The `dataProvider` array is passed to `setDataProvider()` and controls *where* data comes from,
*how many* rows to show, and *how* they can be sorted. In a controller this is exactly the array
returned by [`dataConfig()`](crud.md#what-a-subclass-implements); the builder calls `setDataProvider()` with it
for you.

```php
$dataProvider = [
    'model'      => Customer::class,   // Doctrine entity class (full namespace)
    'pagination' => ['defaultPageSize' => 25],
    'sort'       => ['map' => [...]],  // see Sorting section
];
```

| Key | Type | Description |
|-----|------|-------------|
| `model` | `string` | Fully-qualified entity class name |
| `pagination` | `array` | Pagination options — `defaultPageSize`, `maxPageSize`, and the optional `pageSizeOptions` selector (see [Pagination](sorting-pagination.md#pagination)) |
| `sort` | `array` | Grouped sort config: `map` (the attribute → ORDER BY fields map), `default` (initial ordering when no `?sort=` is present) and `multiSort` (allow ordering by several attributes at once). See [Sorting](sorting-pagination.md#sorting). |
