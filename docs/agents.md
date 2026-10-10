# Gridview for AI agents

**Read this file before writing any code that uses `fedale/gridview-bundle`.**

It is a map, not a tutorial: each row points at the guide that holds the detail. What it prevents is
the expensive failure mode — code that *looks* right, renders without an error, and quietly
reimplements a third of the bundle by hand.

Working on the bundle itself rather than using it? Read [`AGENTS.md`](../AGENTS.md) at the repository
root instead.

---

## The model in 60 seconds

Your application owns the page. Gridview owns the data grid. It never takes over routing, layout or
security.

A grid is a controller that extends a base class and answers three questions:

```php
#[Route('/gridview/customer', name: 'gridview_customer_')]
class CustomerController extends AbstractGridController   // AbstractCrudGridController to write
{
    protected function getDataClass(): string { return Customer::class; }

    // where the rows come from: model, pagination, sort map
    protected function dataConfig(): array { return ['model' => Customer::class]; }

    // what the user sees: one entry per column
    protected function buildColumns(): array { return ['id', 'name', 'email']; }

    // how it is wrapped: templates, CRUD mode, export, layout
    protected function viewConfig(): array { return []; }
}
```

The `index` and `export` actions and their routes come from the base class. The grid renders itself
inside your template — there is no grid markup to write.

Columns are declared three interchangeable ways; **prefer the fluent builders**, they are the only
form an IDE and a type checker can see:

```php
use Fedale\GridviewBundle\Column\Config\{CheckboxColumn, TextColumn, MoneyColumn, ActionColumn};

return [
    CheckboxColumn::new(),
    TextColumn::new('title')->label('Title')->sortable()->filterText()->required(),
    MoneyColumn::new('price')->currency('EUR')->decimals(2)->sortable(),
    ActionColumn::new()->label(false),
];
```

A column carries **all** of its concerns at once: how it renders, whether it sorts, its filter input,
its form control, its export behaviour, its per-context visibility. One declaration, not five.

---

## Do not hand-roll: the capability map

| When you need… | Use this | Guide |
|---|---|---|
| A custom layout for the create/update form | `form.view` pointing at a Twig file with single-brace `{ fieldName }` tokens | [08_crud.md](08_crud.md#overriding-the-form-layout-with-a-twig-view) |
| A form field to render at all | a `control` on the column (`->required()`, `->control(...)`) | [08_crud.md](08_crud.md#declaring-a-control-on-a-column) |
| Required / unique validation | `control.required`, `control.unique` (emits `UniqueEntity`) | [08_crud.md](08_crud.md#validation-required--unique) |
| To move the search box, add button, export menu… | layout tokens — `'toolbar' => '{addButton} {globalSearch} {spacer} {export}'` | [05_layout.md](05_layout.md#available-tokens) |
| Edit / delete / custom row buttons | `ActionColumn` with `layout => '{edit} {clone} {delete}'` + `CrudButton` | [02_columns.md](02_columns.md#actioncolumn--token-based-actions) |
| A per-column filter input | the column's `filter` key (`->filterText()`, `->filterDate()`, …) | [04_filtering.md](04_filtering.md#declaring-filter-inputs-in-columns) |
| Filters outside the table (a sidebar, a modal) | `filterBar: true` + the `{filterBar}` token, placeable anywhere on the page | [04_filtering.md](04_filtering.md#the-filterbar--placing-filters-anywhere) |
| A filter with no column behind it | `search.fields` | [04_filtering.md](04_filtering.md#column-less-filters--searchfields) |
| One search box across several fields | `behavior.globalSearch` | [04_filtering.md](04_filtering.md#global-search) |
| Multi-field or alias-based ordering | `dataConfig.sort.map` (one sort key → several ORDER BY fields) | [03_sorting-pagination.md](03_sorting-pagination.md#sorting) |
| CSV / Excel / PDF download | `exportable` columns + the `{export}` token; auto-wired in CRUD controllers | [10_export.md](10_export.md#export) |
| The export to match the columns on screen | nothing — it already does; `export.followsUi: false` opts out | [10_export.md](10_export.md#the-file-matches-the-screen) |
| Cards or a list instead of a table | `display.renderer` (`table` / `card` / `list`) + `{viewSwitcher}` | [05_layout.md](05_layout.md#choosing-the-data-renderer) |
| A column in some views only (a teaser in cards, dates only in the table) | `->onlyInViews('card')` / `->hideInViews('card', 'list')` | [02_columns.md](02_columns.md#per-view-visibility-onlyinviews--hideinviews) |
| One column in the grid, several fields in the form (a full name, an address) | `VirtualColumn::new('fullName')->from(['firstName', 'lastName'])` | [02_columns.md](02_columns.md#virtual-columns--one-to-read-several-to-write) |
| Edit a cell in place | `->editable()` on the column | [08_crud.md](08_crud.md#inline-editing) |
| Select rows and act on them | `CheckboxColumn` + the `{bulkBar}` token | [08_crud.md](08_crud.md#bulk-actions-selection--batch-update) |
| A read-only single-record page | `DetailView` / `AbstractDetailController` | [09_detail-view.md](09_detail-view.md) |
| A UUID or composite primary key | nothing — the CRUD routes and the bulk actions already take any key | [08_crud.md](08_crud.md#keys-that-are-not-an-auto-increment-int) |
| Bootstrap or Tailwind classes on the chrome | the `bootstrap5` / `tailwind` theme | [06_theming.md](06_theming.md#framework-themes-real-framework-classes) |
| Colours, spacing, dark mode | CSS custom properties (design tokens) | [06_theming.md](06_theming.md#token-reference) |
| A column type the bundle lacks | register a `ColumnTypeInterface` service | [14_extending.md](14_extending.md#creating-a-custom-column) |
| Rows from an HTTP API instead of Doctrine | `JsonDataProvider`, or your own `DataProviderInterface` | [14_extending.md](14_extending.md#the-built-in-jsondataprovider) |
| Live updates when another user writes | Mercure signal + auto-refresh | [13_real-time.md](13_real-time.md) |
| End users changing grid options at runtime (all grids or one grid) | the UI settings modal, on fedale/setting-bundle; one `AbstractUiSetting` class per option | [18_ui-settings.md](18_ui-settings.md) |
| To scaffold a CRUD controller | `php bin/console make:gridview:crud --fluent` | [08_crud.md](08_crud.md#scaffolding-a-controller-with-makegridviewcrud) |

Everything in that table is configuration on a column, a token in a layout string, or a YAML key.
If you find yourself writing a `<table>`, a `form_row` loop, a filter form type, an export
controller or a URL string, stop and check the row above.

---

## Ten traps

### 1. Two config namespaces: `viewConfig()` keys vs grid options

A controller's `viewConfig()` has a small set of **top-level** keys — `id`, `template`, `export`,
`attributes`, `options`, plus `form` and `labels` on a CRUD controller. Everything else — every
`display.*`, `behavior.*` and `integration.*` grid option — is nested **under `options`**.

```php
protected function viewConfig(): array
{
    return [
        // controller-level keys, top level
        'template' => ['index' => '@FedaleGridview/gridview/index.html.twig'],
        'form'     => ['view' => 'customer/_form.html.twig', 'mode' => 'modal'],

        // grid options, under `options`
        'options'  => [
            'display'  => ['renderer' => ['default' => 'card']],
            'behavior' => ['globalSearch' => ['c.name', 'c.email']],
        ],
    ];
}
```

When a guide names a key as `display.renderer.default` or `behavior.globalSearch`, that is the path
*inside* `options` (and the same path a YAML preset uses). Guessing the nesting silently does
nothing — an unknown key is merged and ignored, with no error — so copy the snippet from the guide
rather than inferring the shape.

### 2. Callbacks receive arrays, not entities

Rows are normalized to arrays keyed by property name before they reach any callback. Relations are
nested arrays.

```php
// WRONG — TypeError on the first render
TextColumn::new('name')->value(fn (Customer $c) => $c->getName()),

// RIGHT
TextColumn::new('name')->value(fn (array $data) => $data['name']),
TextColumn::new('country')->value(fn (array $data) => $data['country']['code'] ?? null),
```

### 3. Custom form layouts use `{ field }` tokens, never `form_row`

To control how the create/update form is arranged, point `form.view` at a Twig **file** and
place a single-brace token per field. `GridCrudHandler` renders each referenced field and swaps it
in; fields with a control but no token still render via `form_end()`, so nothing silently vanishes.

```twig
{# templates/customer/_form.html.twig #}
<div class="row">
    <div class="col-md-6">{ code }</div>
    <div class="col-md-6">{ username }</div>
    <div class="col-12">{ groups }</div>
</div>
```

```php
protected function viewConfig(): array
{
    return ['form' => ['view' => 'customer/_form.html.twig']];
}
```

Do **not** call `form_row`/`form_widget` yourself, and do not build the form with a Symfony form
type: the controls, their validation, the live uniqueness check and the modal wiring all come from
the columns. Use a file template, never an inline string — an inline string would let data inject
Twig. See [08_crud.md](08_crud.md#overriding-the-form-layout-with-a-twig-view).

### 4. `active` and `visible` are different switches

`visible: false` hides a rendered column with CSS — the data is still in the DOM and in the export.
`active` decides whether the column is rendered **at all**, per context, and is the access-control
switch.

```php
TextColumn::new('salary')->active(false),        // nowhere: no header, no cell, no export, no form field
TextColumn::new('notes')->hideOnIndex(),         // in the form and detail view, not in the table
TextColumn::new('fullName')->onlyOnIndex(),      // in the table only
```

Contexts are `index`, `show`, `create`, `update` — hence `onlyOnIndex()`, `onlyOnForm()`,
`hideOnUpdate()` and friends. See [02_columns.md](02_columns.md#active-vs-visible--access-control).

A third axis scopes the `index` context per data renderer, because one column list
rarely suits a table and a card at once:

```php
TextColumn::new('summary')->onlyInViews('card'),        // cards only
DateColumn::new('createdAt')->hideInViews('card', 'list'), // table only
```

Excluded views draw nothing, while the filter and the CRUD control stay in place; the export follows
the screen, so the file of a view carries that view's columns. See
[Per-view visibility](02_columns.md#per-view-visibility-onlyinviews--hideinviews).

### 5. Insert layout tokens into the existing tree

The regions nest: `{shell}` contains `{header} {dataview} {footer}`, and `{header}` already expands
to `{heading} {toolbar}`. Adding a token beside a region that already contains it renders it twice.

```php
// WRONG — the toolbar appears twice
'shell' => '{header} {toolbar} {bulkBar} {dataview} {footer}',

// RIGHT
'shell' => '{header} {bulkBar} {dataview} {footer}',
```

### 6. Never hardcode CSS-framework classes

The bundle is framework-agnostic: structural hooks are `gv-*`, and every themable leaf goes through
a class key. In a template use `gridview.cls('btn.primary')`; to adopt a framework, set the theme
(`bootstrap5`, `tailwind`) or declare your own class map in YAML. Writing `class="btn btn-primary"`
into an overridden template locks the grid to one framework and breaks the others.

### 7. Column labels are translation keys when you want them switchable

Language switching is client-side and instant, but only for labels that exist as keys in the client
domain (`Gridview` by default). A label that is not a key renders verbatim and stays fixed.

```php
TextColumn::new('createdAt')->label('col.customer.createdAt'),   // switchable
TextColumn::new('createdAt')->label('Created at'),               // frozen literal
```

Bundle chrome lives in the `GridviewBundle` domain. Your own strings in templates and slots go
through `|trans` — never hardcode user-facing text. See [07_i18n.md](07_i18n.md#two-translation-domains).

### 8. `template.index` defaults to a host-app template

It defaults to `gridview/with_sidebar.html.twig`, which is a template **your app** is expected to
provide. Point it somewhere real, or you get a missing-template error on the first request:

```php
protected function viewConfig(): array
{
    return ['template' => ['index' => '@FedaleGridview/gridview/index.html.twig']];
}
```

### 9. CRUD URLs come from the route-name convention

Name the routes with the conventional suffixes on one shared prefix (`index`, `show`, `create`,
`update`, `clone`, `delete`, `inline`) and the action buttons, the add button and the modals wire
themselves up. Do not generate those URLs by hand and do not invent suffixes.
See [08_crud.md](08_crud.md#routing-convention).

---

### 10. A `choice` or `relation` filter needs its options

Both render a `<select>` the bundle cannot fill on its own — it never queries the database to build
a filter. Declared without options they render an empty dropdown, which looks like a broken grid
rather than a missing config key.

```php
// RIGHT — the enum fills the choice filter
SelectColumn::new('status')->enum(PostStatus::class),

// RIGHT — a relation filter is a plain option list: hand it one
RelationColumn::new('author')->relation(User::class, choiceLabel: 'fullName')
    ->filter(['type' => 'relation', 'options' => ['choices' => $authorChoices]]),

// WRONG — declares the filter, renders it empty
RelationColumn::new('author')->relation(User::class),
```

For a list too large to inline, give the relation filter an `ajax_url` instead. See
[04_filtering.md](04_filtering.md#choice).

## The guides

| File | What is in it |
|---|---|
| [01_getting-started.md](01_getting-started.md) | Controller hooks, the data-provider array, the first grid |
| [02_columns.md](02_columns.md) | Fluent builders, every column type, footers, ActionColumn, nested data, custom types |
| [03_sorting-pagination.md](03_sorting-pagination.md) | Sort map, default and multi-column sort, page sizes, jump-to-page |
| [04_filtering.md](04_filtering.md) | Per-column filters, filterBar, every filter type, `applyFilters()`, global search |
| [05_layout.md](05_layout.md) | Layout tokens, spacing, slots, region attributes, renderers, responsive collapse |
| [06_theming.md](06_theming.md) | Framework themes, dark mode, design tokens, per-element attribute bags |
| [07_i18n.md](07_i18n.md) | The two domains, instant switching, tagging your own strings |
| [08_crud.md](08_crud.md) | Controls, validation, form layouts, modal vs page, bulk, inline edit, base classes |
| [09_detail-view.md](09_detail-view.md) | Single-record rendering |
| [10_export.md](10_export.md) | Formats, per-grid limits, saved searches and selections |
| [11_configuration.md](11_configuration.md) | YAML defaults, per-grid presets, merge precedence |
| [12_javascript.md](12_javascript.md) | Every Stimulus controller and its data attributes |
| [13_real-time.md](13_real-time.md) | Mercure signals and auto-refresh |
| [14_extending.md](14_extending.md) | Public interfaces, custom columns, custom data providers, row events |
| [15_full-example.md](15_full-example.md) | A complete controller plus template, and the raw builder API |
| [18_ui-settings.md](18_ui-settings.md) | The end-user settings modal: enabling it, precedence, adding a setting |

---

## Before you call a grid done

- [ ] Columns declared with fluent builders, not string-keyed arrays.
- [ ] Every callback signature is `fn (array $data)`.
- [ ] `template.index` points at a template that exists.
- [ ] Sortable columns have an entry in `dataConfig.sort.map`.
- [ ] Filterable columns declare a `filter`; the repository applies them (`applyFilters()`).
- [ ] Write-side columns declare a `control`; required and unique fields say so.
- [ ] A custom form layout uses `{ field }` tokens in a file template, and every control has a token
      or is deliberately left to `form_end()`.
- [ ] No hardcoded framework CSS classes and no hardcoded user-facing strings.
- [ ] Columns that must never reach the client use `active`, not `visible`.
