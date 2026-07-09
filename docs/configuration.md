# YAML Configuration

Global defaults and per-grid presets live in `config/packages/gridview.yaml`.
Runtime calls to `setOptions()` and `setAttributes()` override these values — they are merged,
not replaced.

## Global defaults

Options are grouped by topic — `display` (look & text), `behavior` (interaction),
`integration` (routes/CRUD wiring) — as direct siblings of `attributes`:

```yaml
# config/packages/gridview.yaml
fedale_gridview:
  defaults:
    display:
      emptyText:  "No records found"
      showThead:  true
      showTfoot:  true
      layout:
        shell:    "{header} {dataview} {footer}"
        toolbar:  "{globalSearch} {filterSubmit}"
        footer:   "{resultsSummary} {pagination} {pageSize}"
    behavior:
      useTurbo: true
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
      behavior:
        globalSearch: ["c.name", "c.email"]
      display:
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

Options are grouped under three top-level YAML keys — `display` (look & text),
`behavior` (interaction), `integration` (routes/CRUD wiring) — direct siblings of
`attributes` under `defaults` and `gridviews.<id>`. `Gridview`'s own runtime option
bag (`getOptions()`, `setOptions()`) mirrors the same three-way nesting, e.g.
`$gridview->getOptions()['behavior']['pagination']['mode']`.

The **Set via** column tells you where a value can actually come from:

- **YAML + runtime** — settable in `defaults.<group>`, `gridviews.<id>.<group>`, and
  overridable with `setOptions()` / `viewConfig()['options']` (using the same
  `['<group>' => [...]]` shape).
- **YAML (`defaults` only)** — the per-grid `gridviews.<id>` node doesn't whitelist
  this key; only the global default and runtime overrides work.
- **YAML (`gridviews.<id>` only)** — the reverse: no `defaults` node, only a per-grid
  override (or the top-level `theme` key, for the one case where that applies).
- **Runtime only** — not part of the YAML schema at all; settable only via
  `setOptions()` / `viewConfig()['options']`, or entirely derived by the framework.

### Display

What the grid looks like: text, chrome, and which template renders it.

| Option | Type | Default | Set via | Description |
|--------|------|---------|---------|-------------|
| `caption` | `string\|null` | `null` | YAML + runtime | Optional `<caption>` text for the table |
| `title` | `string\|null` | `null` | YAML + runtime | Grid title text rendered by the `{heading}` block (`{heading}` collapses when empty); also the CRUD modal/page heading |
| `theme` | `string` | `'default'` | YAML (`gridviews.<id>` only) | Per-grid framework theme override; the global default is the top-level `theme` key, not `defaults.display.theme` — see [Theming](theming.md) |
| `renderer` | `array` | `['default' => 'table', 'map' => []]` | YAML + runtime (defaults only) | Data-renderer config: `default` picks the active strategy → `sections/dataview/{renderer}.html.twig`; `map` keys are the available renderers (values are per-renderer option bags) and drive the runtime `{viewSwitcher}` (shown automatically when `map` has more than one entry). Built-in: `table`, `list`, `card`. `gridviews.<id>.renderer` only accepts a scalar shorthand — you can't set a per-grid `map` from YAML |
| `emptyText` | `string` | `'No records found'` | YAML + runtime | Text shown when there are no data rows |
| `showThead` | `bool` | `true` | YAML + runtime | Include `{thead}` in the auto-computed table layout |
| `showTfoot` | `bool` | `true` | YAML + runtime | Include `{tfoot}` in the auto-computed table layout |
| `addLabel` | `string` | `'Add'` | YAML + runtime | Label for the `{addButton}` link |
| `crudTemplate` | `string\|null` | `null` | YAML + runtime | Full-page CRUD wrapper template (`page`/`custom` mode); PHP `viewConfig()['template']['page']` wins when set, else this YAML default, else the bundle's own page template — see [CRUD](crud.md) |
| `layout` | `array` | see [Layout](layout.md) | YAML + runtime | Layout token strings (`shell`, `header`, `toolbar`, `dataview`, `footer`, `tfoot`), plus the `templates` / `slots` / `attrs` sub-maps for overrides and inline content |

### Behavior

How the grid reacts to input: search, filters, pagination, real-time, responsiveness.

| Option | Type | Default | Set via | Description |
|--------|------|---------|---------|-------------|
| `useTurbo` | `bool` | `true` | YAML + runtime | Wrap the grid in a Turbo Frame and respond with partial HTML on frame requests |
| `globalSearch` | `string[]` | `[]` | YAML + runtime | DQL fields searched by the global search input |
| `formName` | `string` | `'fedaleForm'` | YAML (`defaults` only) | Name of the filter form; change this to support multiple grids with filters on the same page. No per-grid override — set it in `viewConfig()['options']['behavior']` instead |
| `maxQueryLength` | `int` | `4000` | YAML + runtime | Safety cap on the generated DQL/SQL query length |
| `crudMode` | `'modal'\|'page'\|'custom'` | `'modal'` | YAML + runtime | How the CRUD form is presented; PHP `viewConfig()['form']['mode']` wins when set, else this YAML default, else `'modal'` — see [CRUD](crud.md) |
| `filterControls.inHeader` | `bool` | `true` | YAML + runtime | Render the per-column filters in the header (funnel icon + filter row) |
| `filterControls.inlineClear` | `bool` | `false` | YAML + runtime | Show an inline "clear" affordance on filtered columns |
| `filterControls.clear` | `mixed\|null` | `null` | YAML (`defaults` only) | Default clear-affordance mode(s) for columns that don't set their own `filter.clear` — see [Filtering](filtering.md) |
| `filterControls.autoBar` | `bool\|null` | `null` | YAML + runtime | Auto-populate the `{filterBar}` for non-table renderers; `null` derives it from the active renderer |
| `filterControls.choiceControlsThreshold` | `int` | `20` | YAML + runtime | Hides the search/select-all toolbar on choice filters below this option count |
| `pagination.mode` | `string` | `'numeric'` | YAML + runtime | Paginator strategy key (registry name); host apps can register their own via `PaginatorStrategyInterface` |
| `pagination.pageSelect` | `bool` | `true` | YAML + runtime | Show the jump-to-page `<select>` in the pagination |
| `pagination.pageSelectThreshold` | `int` | `10` | YAML + runtime | Minimum page count before the `<select>` appears |
| `pagination.options` | `array` | — | YAML + runtime | Free-form extra options for a custom paginator strategy (e.g. `infiniteRootMargin`) |
| `realtime.enabled` | `bool` | `false` | YAML + runtime | Enable real-time updates over Mercure (see [Real-time updates](real-time.md#real-time-updates-mercure)) |
| `realtime.topicPrefix` | `string` | `'gridview/'` | YAML + runtime | Prefix for the per-grid Mercure topic (`<prefix><id>`) |
| `reorderColumns` | `bool` | `false` | Runtime only | Enable drag-and-drop column reordering on the header; not in the YAML schema, set via `setOptions()` / `viewConfig()['options']` |
| `responsive` | `bool` | `false` | Runtime only | Collapse low-priority columns into a detail row on narrow screens (see [Responsive](layout.md#responsive-column-collapse)); not in the YAML schema |
| `restriction` | `bool\|string` | `false` | YAML + runtime | Shows the `{restrictionNotice}` banner when the list is filtered by permissions; a string overrides the default message |

### Integration

How the grid wires into routes and CRUD actions.

| Option | Type | Default | Set via | Description |
|--------|------|---------|---------|-------------|
| `addRoute` | `string\|null` | `null` | YAML + runtime | Route name for the `{addButton}` token |
| `routeName` | `string\|null` | `null` | Runtime only | List route used for sort/pagination/filter links instead of the current `_route`; the CRUD controller sets this automatically, so you rarely need to. Not in the YAML schema |
| `export.url` / `export.formats` | `string` / `array` | — | Runtime only | Export endpoint URL and the available format list; derived by `AbstractGridController::buildGridview()` from the exporter registry |
| `crud` | `array` | `[]` | Runtime only | CRUD wiring URLs only (`addUrl`, `bulkDeleteUrl`, `bulkUpdateUrl`, `inlineUrl`) — assembled by `AbstractCrudGridController::crudOptions()`, request-derived so it's never in the YAML schema. The presentation mode and template live in `behavior.crudMode` / `display.crudTemplate` above, and the modal/page title reuses `display.title` — see [CRUD](crud.md) |

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
    ->setOptions(['behavior' => ['formName' => 'order_filters']])
    ->setSearchModel($orderSearchModel)
    ->setColumns([...])
    ...

// Second grid on the same page
$this->createGridviewBuilder()
    ->setOptions(['behavior' => ['formName' => 'product_filters']])
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
