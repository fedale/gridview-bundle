# YAML Configuration

Global defaults and per-grid presets live in `config/packages/gridview.yaml`.
Runtime calls to `setOptions()` and `setAttributes()` override these values — they are merged,
not replaced.

## Global defaults

```yaml
# config/packages/gridview.yaml
fedale_gridview:
  defaults:
    options:
      emptyText:  "No records found"
      useTurbo:   true
      showThead:  true
      showTfoot:  true
      layout:
        shell:    "{header} {dataview} {footer}"
        toolbar:  "{globalSearch} {filterSubmit}"
        footer:   "{pagination}"
    attributes:
      class: "table table-striped"
      container:
        class: "table-responsive"
```

## Per-grid presets

Register a named preset under `gridviews`, then pass the matching ID to the builder:

```yaml
fedale_gridview:
  gridviews:
    customer_list:
      options:
        globalSearch: ["c.name", "c.email"]
        layout:
          toolbar: "{addButton} {columnVisibility}"
          shell: "{toolbar} {header} {dataview} {footer}"
      attributes:
        class: "table table-dark"
        row:
          class: "customer-row"
```

```php
// In the controller — the 'customer_list' preset is merged automatically
$gridview = $this->createGridviewBuilder()
    ->setId('customer_list')
    ->setDataProvider($dataProvider)
    ->setColumns($columns)
    ->renderGridview();
```

## All available options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `emptyText` | `string` | `'No records found'` | Text shown when there are no data rows |
| `title` | `string\|null` | `null` | Grid title text rendered by the `{heading}` block (`{heading}` collapses when empty) |
| `renderer` | `string` | `'table'` | Data region strategy → `sections/dataview/{renderer}.html.twig` (`table` only today; `card`/`list` planned) |
| `useTurbo` | `bool` | `true` | Wrap the grid in a Turbo Frame and respond with partial HTML on frame requests |
| `showThead` | `bool` | `true` | Include `{thead}` in the auto-computed table layout |
| `showTfoot` | `bool` | `true` | Include `{tfoot}` in the auto-computed table layout |
| `globalSearch` | `string[]` | `[]` | DQL fields searched by the global search input |
| `addRoute` | `string\|null` | `null` | Route name for the `{addButton}` token |
| `addLabel` | `string` | `'Add'` | Label for the `{addButton}` link |
| `routeName` | `string\|null` | `null` | List route used for sort/pagination/filter links instead of the current `_route` — required so the grid renders correctly from a CRUD POST (Turbo Stream) |
| `crud` | `array` | `[]` | CRUD modal config: `title`, `addUrl` (enables the `{addButton}` modal trigger) |
| `formName` | `string` | `'fedaleForm'` | Name of the filter form; change this to support multiple grids with filters on the same page |
| `caption` | `string\|null` | `null` | Optional `<caption>` text for the table |
| `pagination.pageSelect` | `bool` | `true` | Show the jump-to-page `<select>` in the pagination |
| `pagination.pageSelectThreshold` | `int` | `10` | Minimum page count before the `<select>` appears |
| `realtime.enabled` | `bool` | `false` | Enable real-time updates over Mercure (see [Real-time updates](real-time.md#real-time-updates-mercure)) |
| `realtime.topicPrefix` | `string` | `'gridview/'` | Prefix for the per-grid Mercure topic (`<prefix><id>`) |
| `reorderColumns` | `bool` | `false` | Enable drag-and-drop column reordering on the header |
| `responsive` | `bool` | `false` | Collapse low-priority columns into a detail row on narrow screens (see [Responsive](layout.md#responsive-column-collapse)) |
| `layout` | `array` | see above | Layout token strings, template overrides, and inline slots |

## Detail-view presets

Single-record [DetailViews](detail-view.md#detailview-single-record) use their own YAML sections
— `defaults.detailview` and `detailviews.<id>` — kept separate from `gridviews` so
grid-only keys never leak in. See [DetailView → YAML configuration](detail-view.md#yaml-configuration).

## Multiple grids with filters on the same page

When you render two grids that both have column filters, each must use a unique `formName`
so their filter query parameters do not collide:

```php
// First grid
$this->createGridviewBuilder()
    ->setOptions(['formName' => 'order_filters'])
    ->setSearchModel($orderSearchModel)
    ->setColumns([...])
    ...

// Second grid on the same page
$this->createGridviewBuilder()
    ->setOptions(['formName' => 'product_filters'])
    ->setSearchModel($productSearchModel)
    ->setColumns([...])
    ...
```

> **Note:** the `SearchForm` builds its Symfony form with the configured `formName`.
> Each grid instance receives its own `SearchForm`, so their form submissions are independent.

## Merge precedence (lowest → highest)

1. Built-in code defaults (`Gridview::$options`)
2. `fedale_gridview.defaults` (YAML)
3. `fedale_gridview.gridviews.<id>` (YAML)
4. `setOptions()` / `setAttributes()` calls (runtime)
