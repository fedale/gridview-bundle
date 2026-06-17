# FedaleGridviewBundle — Documentation

A Symfony bundle for rendering configurable data grids, inspired by the Yii 2 GridView widget.
The grid is not automagic: you configure a data source and a column list, the bundle does the rest.

---

## Table of Contents

1. [Overview](#overview)
2. [Quick Start](#quick-start)
3. [Data Provider](#data-provider)
4. [Columns](#columns) — [ActionColumn](#actioncolumn--token-based-actions)
5. [Sorting](#sorting)
6. [Pagination](#pagination)
7. [Filtering & Search](#filtering--search)
8. [Layout System](#layout-system)
9. [CRUD forms (generated from columns)](#crud-forms-generated-from-columns)
10. [Controller base classes](#controller-base-classes)
11. [DetailView (single record)](#detailview-single-record)
12. [Export](#export)
13. [Attributes & Styling](#attributes--styling)
14. [YAML Configuration](#yaml-configuration)
15. [JavaScript Controllers](#javascript-controllers)
16. [Real-time updates (Mercure)](#real-time-updates-mercure)
17. [Extending the Bundle](#extending-the-bundle)

---

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
[controller base classes](#controller-base-classes): you declare the entity, the
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

    protected function getDataProviderConfig(): array
    {
        return [
            'models'     => Customer::class,
            'pagination' => ['defaultPageSize' => 25],
            'sort'       => [
                'name'  => ['asc' => ['c.name'], 'desc' => ['c.name'], 'default' => 'asc'],
                'email' => ['asc' => ['c.email'], 'desc' => ['c.email'], 'default' => 'asc'],
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
[Controller base classes](#controller-base-classes).

### 2. The index template

`index` renders the template named by the `indexTemplate` config key, which
defaults to `gridview/with_sidebar.html.twig` (a host-app template). Point it at
the bundle's bare layout, or your own, via [`configure()`](#the-configure-array):

```php
protected function configure(): array
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
> [Full Example](#full-example) shows the manual `createGridviewBuilder()` chain,
> and most sections below illustrate options through it.

---

## Data Provider

The `dataProvider` array is passed to `setDataProvider()` and controls *where* data comes from,
*how many* rows to show, and *how* they can be sorted.

```php
$dataProvider = [
    'models'     => Customer::class,   // Doctrine entity class (full namespace)
    'pagination' => ['defaultPageSize' => 25],
    'sort'       => [...],             // see Sorting section
];
```

| Key | Type | Description |
|-----|------|-------------|
| `models` | `string` | Fully-qualified entity class name |
| `pagination` | `array` | Pagination options (see [Pagination](#pagination)) |
| `sort` | `array` | Sort attribute map (see [Sorting](#sorting)) |

---

## Columns

Each item in the `$columns` array can be a **string shorthand** or a **full array definition**.

### String shorthand

```php
$columns = [
    'id',           // renders $data['id'], header label = "id"
    'name',
    'email',
];
```

The shorthand format also accepts `attribute:twigFilter:label`:

```php
$columns = [
    'code:raw:Product Code',   // attribute=code, twigFilter=raw, label="Product Code"
];
```

### Full array definition

```php
$columns = [
    [
        'attribute' => 'email',
        'label'     => 'E-Mail',
        'value'     => function (array $data, string $key, ColumnInterface $column): string {
            return '<a href="mailto:' . $data['email'] . '">' . $data['email'] . '</a>';
        },
        'twigFilter' => 'raw',
        'visible'    => true,
        'filter'     => ['type' => 'text'],
    ],
];
```

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `attribute` | `string` | — | Field name in the data row (supports dot-notation: `profile.fullname`) |
| `label` | `string` | Same as `attribute` | Column header text |
| `value` | `Closure\|string\|null` | `null` | Custom cell value; closure receives `($data, $key, $column)` |
| `twigFilter` | `string\|null` | `null` | Any Twig filter applied to the rendered value (e.g. `raw`, `upper`, `date('d/m/Y')`) |
| `visible` | `bool` | `true` | Whether the column is rendered; `false` columns are hidden but toggleable via the UI |
| `filter` | `array\|bool\|null` | `null` | Column filter definition (requires a `SearchModel`). `true` enables a filter whose type is inherited from the column `type`; an array may set its own `type` to override it |
| `sortable` | `bool` | `true` | Whether clicking the header sorts the grid |
| `filterable` | `bool` | `true` | Whether the column shows a filter input |
| `filterBar` | `bool` | `false` | Render this column's filter in the `{filterBar}` section instead of inline in the header row |
| `headerMirror` | `bool` | `false` | Only with `filterBar: true` (text/number filters): also render a synced "mirror" input in the column header. Off by default → the filter lives **only** in the filterBar |

### Column types

The root `type` of a column has **two flavours**:

1. **Data types** — describe the *kind of data* the column holds. They render via
   `DataColumn` and, crucially, set the **default filter type** (see
   [Inheriting the filter type from the column](#inheriting-the-filter-type-from-the-column)).
   When `type` is omitted it defaults to **`text`**.
2. **Structural types** — dedicated column classes for non-data concerns (selection,
   numbering, actions).

```php
$columns = [
    ['type' => 'checkbox'],                       // structural: row selection
    ['type' => 'serial'],                         // structural: row numbers
    ['attribute' => 'email'],                     // data: text (the default)
    ['attribute' => 'active', 'type' => 'boolean'], // data: renders ✓/✗
    ['type' => 'action'],                         // structural: action links
];
```

**Data types** (rendered by `DataColumn`):

| Type | Renders as | Default filter |
|------|-----------|----------------|
| `text` | Raw scalar / closure value (**default** when `type` is omitted) | `text` |
| `boolean` | `✓` / `✗` for truthy / falsy values | `boolean` |
| `date` | Raw value (format it with `twigFilter`) | `date` |
| `number` | Raw value | `number` |
| `relation` | Raw value (use `value` to render the related label) | `relation` |
| `choice` | Raw value | `choice` |
| `data` | Raw value (legacy alias of `text`) | `text` |

**Structural types** (dedicated classes):

| Type | Class | Description |
|------|-------|-------------|
| `checkbox` | `CheckboxColumn` | Row selection with header toggle; not sortable or filterable |
| `serial` | `SerialColumn` | Auto-incrementing row index |
| `action` | `ActionColumn` | View / update / delete action links |

> A `value` closure always wins over the data type's built-in rendering — set
> `type: 'boolean'` for the ✓/✗ default, or supply your own `value` to override it.

### ActionColumn — token-based actions

`ActionColumn` renders per-row action buttons (view, edit, delete, or anything custom).
Which buttons appear is controlled by a **layout string** of `{token}` placeholders — the same
concept used for the grid layout system.

#### Default behaviour

```php
['type' => 'action']
// renders: {view} {edit} {delete}  — three placeholder <a> links
```

#### Controlling which buttons appear

Set `layout` to any combination of built-in or custom token names:

```php
['type' => 'action', 'layout' => '{view}']

['type' => 'action', 'layout' => '{edit} {delete}']

['type' => 'action', 'layout' => '{view} {archive} {delete}']
```

#### Custom button content

The `buttons` key maps token names to their rendering specification.
You can mix `ActionButton` objects, closures, plain HTML strings, or arrays:

```php
use Fedale\GridviewBundle\Column\ActionButton;

$columns = [
    [
        'type'    => 'action',
        'layout'  => '{view} {edit} {delete}',
        'buttons' => [
            // Closure — full control, receives the row data and row index
            'view' => new ActionButton(
                fn(array $row, int $i) => sprintf(
                    '<a href="/customers/%d" class="btn btn-sm btn-outline-primary">View</a>',
                    $row['id']
                )
            ),

            // Plain HTML string — static content
            'edit' => new ActionButton(
                '<a href="#" class="btn btn-sm btn-outline-secondary">Edit</a>'
            ),

            // Array shorthand — no need to import ActionButton
            'delete' => [
                'content' => fn(array $row) => sprintf(
                    '<a href="/customers/%d/delete" class="btn btn-sm btn-danger">Delete</a>',
                    $row['id']
                ),
            ],
        ],
    ],
];
```

#### Role-based visibility

Pass a `roles` array to hide a button from users who lack **all listed roles**.
Only one role needs to match (OR logic). Requires the Symfony Security component.

```php
use Fedale\GridviewBundle\Column\ActionButton;

'buttons' => [
    'view' => new ActionButton(
        fn(array $row) => '<a href="/customers/' . $row['id'] . '">View</a>',
    ),

    // Shown only to ROLE_EDITOR or ROLE_ADMIN
    'edit' => new ActionButton(
        fn(array $row) => '<a href="/customers/' . $row['id'] . '/edit">Edit</a>',
        roles: ['ROLE_EDITOR', 'ROLE_ADMIN'],
    ),

    // Shown only to ROLE_ADMIN
    'delete' => new ActionButton(
        fn(array $row) => '<a href="/customers/' . $row['id'] . '/delete">Delete</a>',
        roles: ['ROLE_ADMIN'],
    ),
],
```

When the security component is not installed, the `roles` check is skipped and all
buttons are shown regardless.

#### Conditional visibility per row

Use the `visible` parameter (bool or closure) to show or hide a button based on row data:

```php
'edit' => new ActionButton(
    fn(array $row) => '<a href="/customers/' . $row['id'] . '/edit">Edit</a>',
    visible: fn(array $row, int $i) => $row['active'] === true,
),
```

#### Array shorthand (no import needed)

All `ActionButton` constructor options are available as array keys:

```php
'buttons' => [
    'delete' => [
        'content' => fn(array $row) => '<a href="/customers/' . $row['id'] . '/delete">Delete</a>',
        'roles'   => ['ROLE_ADMIN'],
        'visible' => fn(array $row) => $row['deletable'] === true,
    ],
],
```

#### Adding a completely custom action

Any token name works — just add a matching entry in `buttons`:

```php
[
    'type'    => 'action',
    'layout'  => '{view} {impersonate}',
    'buttons' => [
        'view'        => new ActionButton(fn($row) => '<a href="/customers/' . $row['id'] . '">View</a>'),
        'impersonate' => new ActionButton(
            fn($row) => '<a href="/?_switch_user=' . $row['email'] . '">Impersonate</a>',
            roles: ['ROLE_ALLOWED_TO_SWITCH'],
        ),
    ],
]
```

#### Summary of `ActionButton` constructor

```php
new ActionButton(
    content: string|\Closure,   // HTML string or fn(mixed $row, int $index): string
    roles:   string[],          // Symfony roles required (empty = always shown)
    visible: bool|\Closure,     // fn(mixed $row, int $index): bool, or plain bool
)
```

#### ActionColumn options reference

These keys are specific to `['type' => 'action']` and have no meaning for data columns
(`id`, `name`, `email`, …).

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `layout` | `string` | `'{view} {edit} {delete}'` | Token string controlling which buttons appear and in what order |
| `buttons` | `array` | built-in icon placeholders | Map of token name → `ActionButton`, callable, HTML string, or array spec |
| `label` | `string` | `'Actions'` | Column header text |

#### YAML configuration

Column definitions — including `layout` and `buttons` — **cannot be set from YAML**.
The YAML config (`fedale_gridview` in `gridview.yaml`) only covers grid-level `options`
(layout tokens, globalSearch, useTurbo, …) and `attributes`.

Columns must always be declared in PHP because they routinely contain closures (for `value`,
`visible`, `buttons`), which YAML cannot represent.

---

### Registering custom column types

Third-party code can register new column types through `ColumnFactory::register()`.
The typical place is a Symfony `CompilerPass` or a bundle's `boot()` method:

```php
// src/MyBundle/DependencyInjection/RegisterColumnsPass.php
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;

class RegisterColumnsPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $factory = $container->findDefinition('fedale_gridview.column_factory');
        $factory->addMethodCall('register', ['badge', BadgeColumn::class]);
    }
}
```

Or at runtime before calling `setColumns()`:

```php
$factory->register('badge', BadgeColumn::class);
```

`BadgeColumn` must implement `Fedale\GridviewBundle\Column\ColumnInterface`.

### Dot-notation for nested data

When the data row contains nested arrays (e.g. from a JOIN), use dot-notation in `attribute`:

```php
[
    'attribute' => 'profile.fullname',
    'label'     => 'Full Name',
],
```

Or use a `value` closure for full control:

```php
[
    'attribute' => 'profile_fullname',
    'label'     => 'Full Name',
    'value'     => function (array $data, string $key, ColumnInterface $column): string {
        return $data['profile']['firstname'] . ' ' . $data['profile']['lastname'];
    },
],
```

### Returning arrays from `value`

When `value` returns an array, combine it with a compound `twigFilter`:

```php
[
    'attribute'  => 'locations',
    'label'      => 'Locations',
    'value'      => function (array $data, string $key, ColumnInterface $column): array {
        return array_map(
            fn($loc) => '<a href="/location/' . $loc['id'] . '">' . $loc['zipcode'] . '</a>',
            $data['locations']
        );
    },
    'twigFilter' => "join(', ', ' and ')|raw",
],
```

---

## Sorting

Sorting is declared in the `sort` key of the data provider array. Each entry maps a **sort name**
(used in the URL query string) to the Doctrine ORDER BY fields.

```php
$dataProvider = [
    'models' => Customer::class,
    'sort'   => [
        'name' => [
            'asc'     => ['c.name'],           // ORDER BY c.name ASC
            'desc'    => ['c.name'],            // ORDER BY c.name DESC
            'default' => 'asc',
            'label'   => 'Customer Name',       // optional, overrides column label in the link
        ],
        'email' => [
            'asc'     => ['c.email'],
            'desc'    => ['c.email'],
            'default' => 'asc',
        ],
        'fullname' => [                         // sort by multiple fields
            'asc'     => ['p.firstname', 'p.lastname'],
            'desc'    => ['p.firstname', 'p.lastname'],
            'default' => 'asc',
            'label'   => 'Full Name',
        ],
    ],
];
```

A `DataColumn` whose `label` (or `attribute`) matches a key in the sort map automatically
renders its header as a clickable sort link. Clicking toggles `asc` ↔ `desc`. The current
direction is reflected in the `?sort=` query parameter.

---

## Pagination

Pagination attributes are passed inside the data provider:

```php
$dataProvider = [
    'models'     => Customer::class,
    'pagination' => [
        'defaultPageSize' => 25,
    ],
];
```

The `{pagination}` token in the footer layout renders the page navigation links.
To remove pagination entirely, omit the token from the footer layout:

```php
->setOptions(['layout' => ['footer' => '']])
```

### Navigation UI

The pagination renders a **sliding window** of page numbers rather than every page, so a
50-page list never prints 50 buttons. Around the current page it shows `_window` pages per
side (2 by default → 5 numbers), framed by first / previous / next / last icon buttons and
ellipses when the window does not reach an edge:

```
« ‹ … 8 9 10 11 12 … › »
```

First/previous are disabled on page 1, next/last on the last page. Each piece carries a
dedicated CSS class so it can be targeted independently:

| Class | Element |
|-------|---------|
| `gv-pagination` | the `<ul>` wrapper |
| `gv-page-item` | every `<li>` |
| `gv-page-first` / `gv-page-prev` / `gv-page-next` / `gv-page-last` | icon buttons |
| `gv-page-number` | a numbered page |
| `gv-page-ellipsis` | the `…` separator (non-interactive) |
| `gv-page-jump` | the "jump to page" `<select>` wrapper |
| `gv-page-link` | the `<a>`/`<span>`/`<select>` inside each item |
| `gv-active` | the current page |
| `gv-disabled` | a disabled control |

### Jump-to-page select

For long lists a `<select>` lets the user jump directly to any page. It is controlled by the
`pagination` options:

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `pagination.pageSelect` | `bool` | `true` | Show the jump-to-page `<select>` |
| `pagination.pageSelectThreshold` | `int` | `10` | Minimum page count before the `<select>` appears |

```php
// Disable the select for this grid
->setOptions(['pagination' => ['pageSelect' => false]])

// Or only show it from 20 pages up
->setOptions(['pagination' => ['pageSelectThreshold' => 20]])
```

Each `<option>` value is the fully-built page URL, so navigation needs no client-side query
rebuilding — see the [`gridview-page-jump`](#gridview-page-jump) controller.

---

## Filtering & Search

Column filters require a **SearchModel** — a class that extends
`Fedale\GridviewBundle\Service\SearchModel`.

### Enabling filters

```php
// In the controller
$gridview = $this->createGridviewBuilder()
    ->setSearchModel($this->customerSearchModel)  // inject via constructor or autowiring
    ->setDataProvider($dataProvider)
    ->setColumns($columns)
    ->renderGridview();
```

### Declaring filter inputs in columns

A filterable column needs a `filter` key. Additional options are passed under `options`
and forwarded directly to the underlying Symfony Form type.

```php
$columns = [
    [
        'attribute' => 'name',
        'filter'    => ['type' => 'text'],
    ],
    [
        'attribute' => 'active',
        'filter'    => ['type' => 'boolean'],
    ],
];
```

### Inheriting the filter type from the column

You rarely need to repeat the type: the filter **inherits the column's root `type`** by
default. Set `filter: true` (or an array without `type`) and the filter takes the column
type; the column `type` itself defaults to `text` when omitted.

```php
$columns = [
    // type defaults to "text" → text filter
    ['attribute' => 'name',   'filter' => true],

    // boolean cell (✓/✗) AND boolean filter — declared once
    ['attribute' => 'active', 'type' => 'boolean', 'filter' => true],

    // date filter inherited; options still allowed on the array form
    ['attribute' => 'createdAt', 'type' => 'date', 'filter' => true],

    // relation filter inherited, only the options are given
    ['attribute' => 'type', 'type' => 'relation',
     'filter' => ['options' => ['choices' => $choices, 'multiple' => true]]],
];
```

**A `filter.type` set explicitly always wins** over the column type. The two axes are
independent — the cell renders according to the column `type`, the filter according to its
resolved type. So a column left at the default `text` type but given a `boolean` filter
renders its cell as plain text while filtering as a boolean:

```php
// cell rendered as text, filter behaves as boolean
['attribute' => 'active', 'filter' => ['type' => 'boolean']]
```

### Default filter values

A filter can declare a `default` value so the grid opens **already filtered** on first
visit, with the filter input pre-filled accordingly:

```php
$columns = [
    [
        'attribute' => 'active',
        'filter'    => ['type' => 'boolean', 'default' => '1'],   // open on active rows
    ],
    [
        'attribute' => 'createdAt',
        'filter'    => ['type' => 'date', 'default' => ['from' => '2025-01-01', 'to' => null]],
    ],
];
```

**Accepted shapes per type** (validated at configuration time — an invalid shape throws
`InvalidArgumentException`):

| Filter type | `default` shape |
|-------------|-----------------|
| `text` | scalar, e.g. `'abc'` |
| `boolean` | `'1'`/`'0'` (also `1`, `0`, `true`, `false`) |
| `date` | `['from' => 'YYYY-MM-DD', 'to' => 'YYYY-MM-DD']` (either bound nullable) or the string shorthand `'YYYY-MM-DD'` (= `from`) |
| `number` | `['from' => 10, 'to' => 20]` (either bound nullable) or a numeric shorthand (= `from`) |
| `choice` / `relation` | scalar value, or an array of values when the filter has `'multiple' => true` |

**Semantics:**

- Defaults apply **only when the request carries no `myform` parameter at all** (first
  visit). A submitted GET form always sends every field — even empty ones — so a
  present-but-empty `myform` means *the user cleared the filter*, and the default is
  **not** reapplied.
- Sort and pagination links generated from a first visit carry no `myform` params, so
  defaults keep applying consistently while navigating.
- The default value also pre-fills the form input (via the form `data` option), so what
  the user sees always matches the applied query.
- For columns with dotted attributes (e.g. `t.name`) the default is keyed by the mangled
  param name (`t_name`), matching the submitted form field name.

### The filterBar — placing filters anywhere

By default a column filter is rendered inline in the table header row. Set
`filterBar: true` to render it in the dedicated `{filterBar}` section instead:

```php
$columns = [
    [
        'attribute' => 'profile_fullname',
        'filter'    => ['type' => 'text'],
        'filterBar' => true,           // → rendered in {filterBar}, not in the header
    ],
];
```

**The `{filterBar}` section can live anywhere on the page**, with whatever CSS you
like — including outside the grid itself (e.g. a page sidebar). The form and the
`<turbo-frame>` do **not** need to wrap the whole page: the filterBar widgets are
associated to the grid's form by id (`form="gv-form-{key}"`), so they belong to the
form even when rendered far from it. `FormData` / `requestSubmit` / `reset` include
them, and the debounced auto-submit-as-you-type still fires (a delegated listener
handles inputs rendered outside the controller's DOM).

Render it wherever you want via the token in the layout, or directly in a host
template:

```twig
{# In a page sidebar, outside the grid container #}
<aside class="my-sidebar">
    {{ gridview_include(gridview, 'filterBar') }}
</aside>
```

When the filterBar is placed outside the grid, drop `{filterBar}` from the grid's
internal `layout` so it is not rendered twice.

#### `headerMirror` — also show the filter in the column header

For `text` / `number` filters in the filterBar you can opt to **also** render a
synced "mirror" input in the column header, so users can type from either place:

```php
[
    'attribute'    => 'code',
    'filter'       => ['type' => 'text'],
    'filterBar'    => true,
    'headerMirror' => true,   // filterBar + a mirror input in the header
],
```

`headerMirror` is **off by default**: a `filterBar` filter lives only in the
filterBar. It has no effect on non-text/number filters (relation, boolean, date),
which are never mirrored.

### Filter types reference

#### `text`

A plain text input. Supports any operator prefix (e.g. `>= 100`, `like foo%`) and a
client-driven **wildcard** (see below).

```php
'filter' => ['type' => 'text']
```

**PHP form options** (`options` key — Symfony form type):

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `placeholder` | `string` | `''` | Placeholder shown in the input (injected into `attr.placeholder`) |

```php
'filter' => ['type' => 'text', 'options' => ['placeholder' => 'Search by name…']]
```

**Applier options** (third tuple element of the `applyFilters()` map — query-side behavior):

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `default_operator` | `string` | `'ilike'` | Operator used when the term has no prefix (case-insensitive contains) |
| `trim` | `bool` | `true` | Trim the submitted value before matching |
| `wildcard` | `string` | `'%'` | The char the **end user** types; its position drives the match |

**Client-driven wildcard.** When the user has not typed an explicit operator prefix, the
position of the wildcard char in their input shapes the query (case-insensitive `LIKE`):

| User types (wildcard `%`) | Match | SQL pattern |
|---------------------------|-------|-------------|
| `foo` | contains | `%foo%` |
| `%foo%` | contains | `%foo%` |
| `foo%` | starts-with | `foo%` |
| `%foo` | ends-with | `%foo` |
| `%%` (only wildcards) | no constraint | — |

The wildcard char(s) are stripped before matching; the SQL pattern always uses `%`. An
explicit operator prefix (`eq foo`, `like foo`, …) takes precedence — the wildcard char is
then kept verbatim. Change the char per column via the applier option, e.g. so users type
`*`:

```php
// In the repository applyFilters() map:
'code' => ['text', 'c.code', ['wildcard' => '*']],   // user types  cod*  → starts-with
'name' => ['text', 'p.name', ['trim' => false, 'default_operator' => 'eq']],
```

---

#### `boolean`

A `<select>` with two choices and an empty "show all" placeholder.
Submits `'1'` (true) or `'0'` (false) as string values.

```php
'filter' => ['type' => 'boolean']
```

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `true_label` | `string` | `'Yes'` | Label for the truthy option |
| `false_label` | `string` | `'No'` | Label for the falsy option |
| `placeholder` | `string` | `'–'` | Label for the empty "show all" option |

**Custom labels example:**

```php
[
    'attribute' => 'active',
    'label'     => 'Status',
    'filter'    => [
        'type'    => 'boolean',
        'options' => [
            'true_label'  => 'Active',
            'false_label' => 'Inactive',
        ],
    ],
    'value' => fn(array $data) => $data['active'] ? 'Active' : 'Inactive',
],
```

**Repository filter example** — the `boolean` applier casts `'1'`/`'0'` to a typed
Doctrine boolean parameter:

```php
$this->searchForm->applyFilters($qb, $params, [
    'active' => ['boolean', 'c.active'],
]);
```

<details><summary>Under the hood (manual equivalent)</summary>

```php
if (isset($params['active']) && $params['active'] !== '') {
    $qb->andWhere('c.active = :active')
       ->setParameter('active', $params['active'] === '1', \Doctrine\DBAL\Types\Types::BOOLEAN);
}
```
</details>

---

#### `choice`

A `<select>` built from a static choices array.

```php
'filter' => [
    'type'    => 'choice',
    'options' => [
        'choices' => ['Active' => 'active', 'Inactive' => 'inactive'],
    ],
]
```

Accepts all standard Symfony `ChoiceType` options under `options`.

---

#### `relation`

A multi-select (or single-select) for relation fields. Supports a built-in searchable
search input and optional AJAX loading.

```php
// Static choices + searchable input
'filter' => [
    'type'    => 'relation',
    'options' => [
        'choices'    => $locationChoices,   // ['Label' => id, ...]
        'multiple'   => true,
        'searchable' => true,
    ],
]

// AJAX loading
'filter' => [
    'type'    => 'relation',
    'options' => [
        'ajax_url'     => '/api/filter-options/locations',
        'option_label' => 'name',
        'option_value' => 'id',
        'multiple'     => true,
    ],
]
```

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `choices` | `array` | `[]` | Static `['Label' => value]` map |
| `multiple` | `bool` | `false` | Allow multiple selections |
| `searchable` | `bool` | `false` | Show a live-filter search input above the options |
| `ajax_url` | `string\|null` | `null` | URL that returns `[{"id":1,"name":"…"},…]` |
| `option_label` | `string` | `'name'` | JSON key used as option label (AJAX mode) |
| `option_value` | `string` | `'id'` | JSON key used as option value (AJAX mode) |

**Repository filter example** — the `relation` applier handles both single values (`=`)
and multi-select arrays (`IN`):

```php
$this->searchForm->applyFilters($qb, $params, [
    'locations' => ['relation', 'l.id'],
]);
```

<details><summary>Under the hood (manual equivalent)</summary>

```php
$this->searchForm->andFilterWhere($qb, ['in', 'l.id', $params['locations'] ?? []]);
```
</details>

---

#### `number`

Two text inputs rendered side by side as a from/to range.
Submits as `myform[field][from]` and `myform[field][to]`.

```php
'filter' => ['type' => 'number']
```

**PHP form options** (`options` key — Symfony form type):

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `from_placeholder` | `string` | `'Min'` | Placeholder for the lower bound input |
| `to_placeholder` | `string` | `'Max'` | Placeholder for the upper bound input |

**Hybrid operator / range syntax.** Each bound is a plain text input, so besides a plain
number it also accepts an operator expression or a range — same spirit as the `text`
filter. A plain number keeps the range semantics (`from` → `>=`, `to` → `<=`); an operator
expression applies as-is. Bounds AND-combine.

| You type in a bound | Result |
|---------------------|--------|
| `10` (in `from`) | `>= 10` |
| `10` (in `to`) | `<= 10` |
| `>5`, `>=5`, `<5`, `<=5` | `>` / `>=` / `<` / `<=` 5 |
| `=10` | `= 10` |
| `!=10` / `<>10` | `<> 10` |
| `1-5` | `BETWEEN 1 AND 5` (bounds auto-sorted) |
| `>=-5` | `>= -5` (negative lower bound) |

Decimal commas are accepted (`2,5` → `2.5`). Range bounds must be non-negative (the default
`-` separator would clash with a leading minus — use `>=-5` for negatives). The example
`from = ">5"`, `to = "20"` yields `(5, 20]`.

**Applier options** (third tuple element of the `applyFilters()` map):

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `range_separator` | `string` | `'-'` | Character splitting a `a<sep>b` range expression |

**Repository filter example** — the `number` applier validates both bounds, parses any
operator/range expression and applies the matching comparison(s):

```php
$this->searchForm->applyFilters($qb, $params, [
    'price' => ['number', 'p.price'],
    // custom range separator, so users can type "1:5":
    // 'price' => ['number', 'p.price', ['range_separator' => ':']],
]);
```

<details><summary>Under the hood (plain-number equivalent)</summary>

```php
$from = ($params['price']['from'] ?? '') !== '' ? (float)$params['price']['from'] : null;
$to   = ($params['price']['to']   ?? '') !== '' ? (float)$params['price']['to']   : null;
$this->searchForm->andFilterWhere($qb, ['gte', 'p.price', $from]);
$this->searchForm->andFilterWhere($qb, ['lte', 'p.price', $to]);
```
</details>

---

#### `date`

A **Flatpickr** calendar popup that replaces the two native `<input type="date">` fields.
Supports both single-date and date-range selection. Always submits ISO `YYYY-MM-DD` values
as `myform[field][from]` and `myform[field][to]`.

```php
'filter' => ['type' => 'date']   // range mode, Italian locale, d/m/Y display
```

**PHP form options** (`options` key — Symfony form type):

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `from_placeholder` | `string` | `'Da'` | Placeholder on the underlying from input (shown before JS loads) |
| `to_placeholder` | `string` | `'A'` | Placeholder on the underlying to input |

**Client options** (`clientOptions` key — passed verbatim to Flatpickr):

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `mode` | `string` | `'range'` | `'single'` or `'range'` |
| `locale` | `string` | `'it'` | Locale code; currently `'it'` (Italian) is bundled |
| `altFormat` | `string` | `'d/m/Y'` | Display format shown to the user |
| `dateFormat` | `string` | `'Y-m-d'` | Value format sent to the server (keep ISO) |
| `minDate` | `string` | today − 1 year | Earliest selectable date (e.g. `'2020-01-01'` or `'today'`) |
| `maxDate` | `string` | today + 1 year | Latest selectable date |

`minDate`/`maxDate` default to a one-year window around today (mirroring the NG DateFilter);
pass your own values via `clientOptions` to widen, narrow, or remove the bounds. ISO
(`YYYY-MM-DD`) bounds are accepted even though the display format is `d/m/Y` — the Stimulus
controller converts them to `Date` objects before handing them to Flatpickr.

Any other [Flatpickr option](https://flatpickr.js.org/options/) can be passed via `clientOptions`.

**Examples:**

```php
// Default — range, Italian, d/m/Y
'filter' => ['type' => 'date'],

// Single date
'filter' => [
    'type'          => 'date',
    'clientOptions' => ['mode' => 'single'],
],

// Range with min/max and custom display format
'filter' => [
    'type'          => 'date',
    'clientOptions' => [
        'mode'      => 'range',
        'minDate'   => '2020-01-01',
        'maxDate'   => 'today',
        'altFormat' => 'd MMMM Y',
    ],
],
```

**Repository filter example** — the `date` applier validates ISO bounds, converts them to
`DateTime` and extends the upper bound to end of day (`23:59:59`):

```php
$this->searchForm->applyFilters($qb, $params, [
    'createdAt' => ['date', 'c.createdAt'],
]);

// Pass applier options as an optional third tuple element:
// 'createdAt' => ['date', 'c.createdAt', ['end_of_day' => false]],
```

<details><summary>Under the hood (manual equivalent)</summary>

```php
$fromDate = ($params['createdAt']['from'] ?? '') !== ''
    ? new \DateTime($params['createdAt']['from'])
    : null;
$toDate = ($params['createdAt']['to'] ?? '') !== ''
    ? new \DateTime($params['createdAt']['to'] . ' 23:59:59')
    : null;
$this->searchForm->andFilterWhere($qb, ['gte', 'c.createdAt', $fromDate]);
$this->searchForm->andFilterWhere($qb, ['lte', 'c.createdAt', $toDate]);
```
</details>

> **DateTime serialization:** the bundle serializes entity `DateTime` fields to ISO 8601
> strings (e.g. `2024-01-15T10:30:00+01:00`) using `DateTimeNormalizer`. Twig's `|date()`
> filter accepts this format directly:
> ```php
> 'twigFilter' => "date('d/m/Y')",
> ```

### Applying filters in the repository — `applyFilters()`

`SearchForm::applyFilters()` centralizes the per-type filter logic (operator parsing for
text, boolean cast, date-range validation and end-of-day handling, `IN` for relations)
that would otherwise be re-implemented by hand in every repository `search()` method:

```php
public function search(array $params = [])
{
    $qb = $this->createQueryBuilder('c')
        ->select('c', 'p', 'l')
        ->join('c.profile', 'p')
        ->join('c.locations', 'l');

    $this->searchForm->applyFilters($qb, $params, [
        'code'      => ['text',     'c.code'],
        'email'     => ['text',     'c.email'],
        'active'    => ['boolean',  'c.active'],
        'createdAt' => ['date',     'c.createdAt'],
        'locations' => ['relation', 'l.id'],
    ]);

    // Genuinely custom conditions still use andFilterWhere():
    $this->searchForm->andFilterWhere($qb, 'or',
        ['ilike', 'p.firstname', $params['fullname'] ?? null],
        ['ilike', 'p.lastname',  $params['fullname'] ?? null],
    );

    return $qb;
}
```

**Map format:** `param key => [type, dqlField]` with an optional third element of
applier options (e.g. `['date', 'c.createdAt', ['end_of_day' => false]]`).
Map keys are the **submitted param names**, i.e. the column attribute with dots replaced
by underscores (`t.name` → `t_name`).

**Built-in types:** `text`, `boolean`, `date`, `number`, `choice`, `relation`.

**Semantics:**

- Blank values (`null`, `''`, `[]`, all-empty range arrays) are skipped silently —
  filter-when-set, like `andFilterWhere()`. Note that `'0'` is a *valid* value.
- Every condition is `AND`-combined and uses **bound parameters** with unique names
  (never string-concatenated literals).
- The `text` applier supports the operator-prefix syntax (`= foo`, `>= 10`, `in a,b`,
  `btw 1 AND 9`, ...) and defaults to case-insensitive contains (`ilike`); override with
  the `default_operator` option. It also honors a client-driven, positional `wildcard`
  char (default `%`) and a `trim` toggle (default `true`) — see the [`text` filter
  reference](#text).
- The `number` applier accepts a from/to range and, in either bound, an operator/range
  expression (`>5`, `=10`, `1-5`, …); the range separator is configurable via
  `range_separator` — see the [`number` filter reference](#number).
- An unknown type in the map throws `InvalidArgumentException` (configuration error).

**Custom appliers:** implement `Fedale\GridviewBundle\Contract\FilterApplierInterface`
and register the instance on the `fedale_gridview.filter_applier_registry` service:

```php
$searchForm->getApplierRegistry()->register('money', new MoneyFilterApplier());
```

### Global search

Global search adds a single text input that queries multiple fields at once.
Declare the DQL fields to search and add the `{globalSearch}` token to the header layout:

```php
->setOptions([
    'globalSearch' => ['c.name', 'c.email', 'c.code'],
    'layout' => [
        'header' => '{globalSearch}',
    ],
])
```

The search field auto-submits with a 300 ms debounce via the `gridview-filter` Stimulus
controller. Matched text is highlighted in the rendered rows with a `<mark>` element.

When `useTurbo: false`, the auto-submit is disabled and a **Filter** button (`{filterSubmit}`)
appears in the header so the user can submit the form manually.

---

## Layout System

The grid renders via a **token-based layout**. Each section is a string of `{token}`
placeholders that resolve to a Twig template file.

### Default layout

```
gridview: "{header} {table} {footer}"
header:   "{globalSearch} {filterSubmit}"
table:    "{thead} {filter} {tbody} {tfoot}"    ← computed from showThead/showTfoot
footer:   "{pagination}"
toolbar:  ""                                    ← opt-in, empty by default
tfoot:    ""
```

### Available tokens

| Token | Template | Notes |
|-------|----------|-------|
| `{header}` | `sections/header.html.twig` | Wrapper above the table |
| `{toolbar}` | `sections/toolbar.html.twig` | Opt-in toolbar area |
| `{table}` | `sections/table.html.twig` | The `<table>` element |
| `{footer}` | `sections/footer.html.twig` | Wrapper below the table |
| `{thead}` | `sections/thead.html.twig` | Column header row |
| `{filter}` | `sections/filter.html.twig` | Column filter inputs row (header) |
| `{filterBar}` | `sections/filterBar.html.twig` | Filters of columns with `filterBar: true`; placeable anywhere, even outside the grid (see [The filterBar](#the-filterbar--placing-filters-anywhere)) |
| `{tbody}` | `sections/tbody.html.twig` | Data rows |
| `{tfoot}` | `sections/tfoot.html.twig` | Table footer row |
| `{globalSearch}` | `sections/globalSearch.html.twig` | Global search input |
| `{filterSubmit}` | `sections/filterSubmit.html.twig` | Filter submit button — visible only when `useTurbo: false` |
| `{pagination}` | `sections/pagination.html.twig` | Page navigation |
| `{addButton}` | `sections/addButton.html.twig` | "Add" link (requires `addRoute`) |
| `{columnVisibility}` | `sections/columnVisibility.html.twig` | Column show/hide dropdown |
| `{empty}` | `sections/empty.html.twig` | "No records found" row |

### Customising the layout at runtime

Pass a `layout` key inside `setOptions()`:

```php
->setOptions([
    'layout' => [
        'gridview' => '{toolbar} {header} {table} {footer}',
        'toolbar'  => '{addButton} {columnVisibility}',
        'header'   => '{globalSearch}',
        'footer'   => '{pagination}',
    ],
    'addRoute' => 'customer_new',
    'addLabel' => 'New Customer',
])
```

### Overriding individual section templates

Point a token to a custom Twig template:

```php
->setOptions([
    'layout' => [
        'templates' => [
            'header' => '@App/gridview/custom_header.html.twig',
            'empty'  => '@App/gridview/no_results.html.twig',
        ],
    ],
])
```

### Inline slots

For small snippets that do not justify a separate template file, use **slots**:

```php
->setOptions([
    'layout' => [
        'toolbar' => '{addButton} {recordCount}',
        'slots'   => [
            'recordCount' => '<span class="badge bg-secondary">{{ models|length }} records</span>',
        ],
    ],
])
```

Slot content is rendered as a Twig template with full access to the grid context
(`gridview`, `models`, `columns`, `pagination`, `form`).

### Hiding thead / tfoot without editing layout

Two boolean options control whether `{thead}` and `{tfoot}` are included when `table` layout
is computed automatically (i.e. when `table` is `null`):

```php
->setOptions([
    'showThead' => true,   // default
    'showTfoot' => false,  // removes tfoot from the table
])
```

---

## CRUD forms (generated from columns)

The grid can generate **add / edit / clone / delete** forms directly from the columns'
configuration — no hand-written `FormType`. The form is built from a per-column `control` spec
(the write-side twin of `filter`), bound to the data provider's entity (`models`), persisted by a
bundle service, and shown in a Bootstrap modal that refreshes the grid via Turbo Stream.

### Declaring a control on a column

Add a `control` key. Its shape mirrors `filter`: an explicit `type` wins, otherwise it inherits the
column's root data type (falling back to `text`).

```php
$columns = [
    ['attribute' => 'code',     'control' => ['type' => 'text', 'required' => true]],
    ['attribute' => 'active',   'type' => 'boolean', 'control' => ['required' => false]],
    // relation control binds a MANAGED entity → needs options.class (+ choice_label, multiple)
    ['attribute' => 'type',     'type' => 'relation',
     'control' => ['options' => ['class' => UserType::class, 'choice_label' => 'name']]],
    ['attribute' => 'groups',   'type' => 'relation',
     'control' => ['options' => ['class' => UserGroup::class, 'choice_label' => 'name', 'multiple' => true]]],
    // a write-only control that is never shown in the grid
    ['attribute' => 'plainPassword', 'visible' => false, 'control' => ['type' => 'text']],
];
```

Control types map to Symfony FormTypes via `ControlTypeRegistry`:
`text→TextType`, `number→NumberType`, `date→DateType`, `boolean→CheckboxType`,
`relation→EntityType`, `choice→ChoiceType`, `hidden→HiddenType`, `html→TextareaType`.

> **Filter ≠ control.** A `relation` *filter* uses scalar ids (ChoiceType); a `relation` *control*
> uses `EntityType` and binds managed entities. They are separate registry entries on purpose.
> A column's `value` closure is display-only and never used to populate the form.

### Validation: required & unique

Constraints are declared on the control and expanded by the bundle (they also stack with any
`#[Assert]`/`#[UniqueEntity]` already on the entity). A violation re-renders the form inline — never a
500.

```php
['attribute' => 'code', 'control' => [
    'type' => 'text',
    'required' => true,  'requiredMessage' => 'The code is required.',   // → NotBlank
    'unique'   => true,  'uniqueMessage'   => 'Code already exists.',     // → UniqueEntity
]],
// composite uniqueness / explicit form
['attribute' => 'code', 'control' => ['unique' => ['fields' => ['code', 'companyId'], 'message' => '…']]],
// arbitrary constraints escape hatch
['attribute' => 'email', 'control' => ['constraints' => [new Assert\Email()]]],
```

- `required: true` adds `NotBlank` (server-side; the HTML `required` alone is not enough). For
  text/textarea controls the bundle also sets `empty_data: ''` so a blank submit reports NotBlank
  instead of breaking a non-nullable typed setter.
- `unique` becomes a root-level `UniqueEntity` (excludes the current row on edit). `true` = this
  attribute; a list / `['fields'=>…]` = composite.
- As a last resort a DB `UniqueConstraintViolationException` is caught in `save()` (which then returns
  `null`) so even undeclared DB UNIQUE constraints don't 500 — handle the `null` to re-render:
  ```php
  if ($crud->save($form, $mode) !== null) { /* success → Turbo Stream */ }
  // else: fall through to renderForm() with the error
  ```

Required fields are marked with a red asterisk after the label (the Bootstrap form theme adds a
`required` class; the bundle styles `.gv-crud-form label.required::after`).

### Live validation (Stimulus, optional)

Progressive enhancement over the server-side validation. Pass a `validate` context to
`renderForm()` and the form gets the `gridview-form-validate` controller, which validates
required/format on input/blur (HTML5 Constraint Validation API) and checks uniqueness with a
debounced fetch:

```php
$crud->renderForm($form, $columns, $view, [
    'action'   => $request->getRequestUri(),
    'validate' => [
        'checkUrl' => $this->generateUrl('gridview_user_exists'),
        'unique'   => ['code', 'username', 'email'],
        'id'       => $mode === 'edit' ? $id : null, // exclude self on edit only
    ],
]);
```

The uniqueness endpoint delegates to `GridCrudHandlerInterface::existsWithValue()` (which only
queries real mapped fields); whitelist the exposed fields in the action:

```php
#[Route('/exists', name: 'exists', methods: ['GET'])]
public function exists(Request $request): JsonResponse
{
    $field = (string) $request->query->get('field');
    if (!in_array($field, ['code', 'username', 'email'], true)) {
        return new JsonResponse(['exists' => false]);
    }
    return new JsonResponse(['exists' => $crud->existsWithValue(
        User::class, $field, $request->query->get('value'), $request->query->get('id')
    )]);
}
```

Register the controller once in `assets/bootstrap.js` (like the others). The server-side
NotBlank/UniqueEntity remain the source of truth — the live layer is purely UX.

### Per-mode controls (`modes`)

Limit a control to specific CRUD modes — e.g. a password required only when creating:

```php
['attribute' => 'plainPassword', 'visible' => false,
 'control' => ['type' => 'text', 'modes' => ['add', 'clone'], 'required' => true]],
```

In `edit` the field is simply not added to the form.

### Relations with a non-standard accessor (`getter`/`setter`)

When the entity getter doesn't return the bound entities (e.g. `User::getRoles()` returns role codes
for the Security contract), pass Symfony's field `getter`/`setter` through `control.options`:

```php
['attribute' => 'roles', 'type' => 'relation', 'control' => ['options' => [
    'class' => UserRole::class, 'choice_label' => 'name', 'multiple' => true,
    'getter' => fn(User $u) => $u->getRoleEntities(),
    'setter' => function (User $u, $roles) {
        $u->getRoleEntities()->clear();
        foreach ($roles as $r) { $u->addRole($r); }
    },
]]],
```

### Wiring the routes (host app owns them)

> **Shortcut:** most apps don't need to write these actions by hand — extend
> `AbstractCrudGridController` (see [Controller base classes](#controller-base-classes))
> and the routes/actions below are inherited. The manual wiring here is the
> low-level reference, useful when you need a fully custom action set.

The bundle ships the services; the app provides thin actions that delegate to
`GridCrudHandlerInterface`. Build the grid once (shared by index + form + delete) and set
`routeName` so sort/pagination/filter links stay pinned to the list route even while a CRUD POST is
rendering the refreshed grid:

```php
->setOptions([
    'routeName' => 'gridview_user_index',
    'crud'   => ['title' => 'User', 'addUrl' => $this->generateUrl('gridview_user_new')],
    'layout' => ['gridview' => '{toolbar} {header} {table} {footer}', 'toolbar' => '{addButton}'],
])
```

Use semantic routes — `new` / `update/{id}` / `clone/{id}` — each delegating to one private handler
with an explicit mode (cleaner URLs; `/gridview/user/update/2` opens the edit form directly):

```php
#[Route('/new', name: 'new', methods: ['GET','POST'])]
public function new(Request $r): Response { return $this->handleForm($r, 'add', null); }

#[Route('/update/{id}', name: 'update', methods: ['GET','POST'], requirements: ['id' => '\d+'])]
public function update(Request $r, int $id): Response { return $this->handleForm($r, 'edit', $id); }

#[Route('/clone/{id}', name: 'clone', methods: ['GET','POST'], requirements: ['id' => '\d+'])]
public function cloneRecord(Request $r, int $id): Response { return $this->handleForm($r, 'clone', $id); }

private function handleForm(Request $request, string $mode, ?int $id): Response
{
    $entity = $id !== null ? ($repo->find($id) ?? throw $this->createNotFoundException()) : null;
    $form = $crud->createForm(User::class, $columns, $mode, $entity, $request);
    $form->handleRequest($request);

    $isXhr = $request->isXmlHttpRequest();
    if ($form->isSubmitted() && $form->isValid() && $crud->save($form, $mode) !== null) {
        return $isXhr ? $turboStream : $this->redirectToRoute('gridview_user_index'); // modal vs page
    }
    return $isXhr
        ? new Response($crud->renderForm($form, $columns, $view, ['action' => $request->getRequestUri()]))
        : new Response($crud->renderFormPage($form, $columns, $view, $pageTemplate, [...]));
}
```

The action buttons and the `{addButton}` token open the modal (or navigate, per `crud.mode`). Use the
`CrudButton` helper inside an `action` column so the URLs (route-owned by the app) get the right hooks:

```php
['type' => 'action', 'layout' => '{edit} {clone} {delete}', 'buttons' => [
    'edit'   => fn($row) => CrudButton::edit($this->generateUrl('gridview_user_update', ['id' => $row['id']]), $mode),
    'clone'  => fn($row) => CrudButton::clone($this->generateUrl('gridview_user_clone', ['id' => $row['id']]), $mode),
    'delete' => fn($row) => CrudButton::delete(
        $this->generateUrl('gridview_user_delete', ['id' => $row['id']]),
        $csrf->getToken('gridcrud_delete_' . $row['id'])->getValue()
    ),
]]
```

Register the Stimulus controller once (app `assets/bootstrap.js`):

```js
import GridviewCrudController from '.../FedaleGridviewBundle/assets/controllers/gridview-crud_controller.js';
app.register('gridview-crud', GridviewCrudController);
```

### Presentation mode: modal / page / custom

`crud.mode` (set by the host app) chooses how the form is presented:

| Mode | Buttons | Form endpoint | Submit |
|------|---------|---------------|--------|
| `modal` (default) | open the dialog (real `href` as no-JS fallback) | XHR → partial | Turbo Stream |
| `page` | plain links to the form page | direct → full page (`@FedaleGridview/crud/page.html.twig`, extends `pageBase`) | redirect |
| `custom` | plain links | direct → **your** template (`crud.pageTemplate`) which prints `formHtml` | redirect |

The endpoint itself is mode-agnostic — it branches on `Request::isXmlHttpRequest()` (the modal
fetches with `X-Requested-With`), so direct navigation always yields a full page (a no-JS fallback
even in modal mode). The controller renders the page with `renderFormPage()` and redirects on a
non-XHR submit:

```php
$isXhr = $request->isXmlHttpRequest();
if ($form->isSubmitted() && $form->isValid() && $crud->save($form, $mode) !== null) {
    return $isXhr ? $turboStream : $this->redirectToRoute('gridview_user_index');
}
return $isXhr
    ? new Response($crud->renderForm($form, $columns, $view, $ctx))
    : new Response($crud->renderFormPage($form, $columns, $view,
        $crud_page_template ?? '@FedaleGridview/crud/page.html.twig', $ctx + ['pageTitle' => '…']));
```

`CrudButton::edit($url, $mode)` / the `{addButton}` token render the modal trigger only when
`mode === 'modal'`; otherwise a plain navigation link.

### Overriding the form layout with a Twig view

By default the fields render automatically. To control the layout, point `crud.form.view` at a Twig
template (passed as the `$view` argument to `renderForm()`) and place **single-brace tokens**
`{ attribute }` — consistent with the layout tokens (`{toolbar}`, `{header}`…). Each token is
replaced by that attribute's generated widget; CSRF and any unplaced fields are appended by
`form_end()`.

```twig
{# templates/gridview/user/_form.html.twig #}
<div class="row g-3">
    <div class="col-md-6">{ code }</div>
    <div class="col-md-6">{ username }</div>
    <div class="col-12">{ groups }</div>
</div>
```

> Tokens are plain text replaced after Twig renders (no `template_from_string`), so a custom layout
> cannot inject Twig code. Use a **file** template, not an inline string. A control with **no token**
> in the view still renders — it falls through to `form_end()` at the bottom — so fields are never
> silently lost.

### Delete with recap

`delete()` is split into GET (recap) + POST (delete). The GET branch renders a confirmation summary
into the modal via `renderDeleteConfirm()`; columns flagged `showInDeleteConfirm` drive the recap
(fallback: the first few visible columns):

```php
['attribute' => 'code', 'showInDeleteConfirm' => true, /* … */],

#[Route('/{id}/delete', name: 'delete', methods: ['GET', 'POST'])]
public function delete(Request $request, int $id): Response
{
    $entity = $repo->find($id) ?? throw $this->createNotFoundException();
    if ($request->isMethod('GET')) {
        return new Response($crud->renderDeleteConfirm(
            $entity, $this->buildGridview()->getColumns(),
            $this->generateUrl('gridview_user_delete', ['id' => $id]),
            $csrf->getToken($crud->deleteTokenId($entity))->getValue(),
        ));
    }
    $crud->delete($entity, $request->request->get('_token'), $crud->deleteTokenId($entity));
    // … return the Turbo Stream
}
```

`delete()` clears owning-side ManyToMany collections before removing the entity (so join-table rows
don't block the DELETE) and catches `ForeignKeyConstraintViolationException` (returns `false`, resets
the EM) when the row is still referenced elsewhere — no 500.

### Bulk actions (selection + batch update)

With a `checkbox` column the `gridview-selection` controller tracks the selection across pages
(sessionStorage, with an all-records mode). Add the `{bulkBar}` layout token and the bulk URLs to
`crud` to get a bulk action bar (count + buttons) that opens the CRUD modal with the selected ids:

```php
'crud' => [
    'bulkDeleteUrl' => $this->generateUrl('gridview_user_bulk_delete'),
    'bulkUpdateUrl' => $this->generateUrl('gridview_user_bulk_update'),
],
'layout' => ['gridview' => '{toolbar} {bulkBar} {header} {table} {footer}'],
```

Columns editable in the batch dialog declare `batchUpdate => true`; the dialog renders an "apply"
checkbox + the control per such column, and only checked fields are applied. Endpoints resolve the
target ids from `ids[]`, or — in all-records mode — from `all=1` plus the current filters
(re-running the repository search server-side):

```php
#[Route('/bulk/delete', name: 'bulk_delete', methods: ['GET', 'POST'])]
public function bulkDelete(Request $request): Response
{
    $ids = $this->resolveBulkIds($request);            // ids[] or all=1 + filters
    if ($request->isMethod('GET')) {
        return new Response($crud->renderBulkDeleteConfirm(count($ids), $request->getRequestUri(),
            $csrf->getToken('gridcrud_bulk_delete')->getValue()));
    }
    if ($this->isCsrfTokenValid('gridcrud_bulk_delete', $request->request->get('_token'))) {
        $crud->bulkDelete(User::class, $ids);
    }
    return $this->turboStream();
}

#[Route('/bulk/update', name: 'bulk_update', methods: ['GET', 'POST'])]
public function bulkUpdate(Request $request): Response
{
    $columns = $gridview->getColumns();
    $form = $crud->createBatchForm($columns);
    $form->handleRequest($request);
    if ($form->isSubmitted() && $form->isValid()) {
        $crud->applyBatch(User::class, $this->resolveBulkIds($request), $form, $columns);
        return $this->turboStream();
    }
    return new Response($crud->renderBatchForm($form, count($ids), $request->getRequestUri()));
}
```

> Constrain id routes (`requirements: ['id' => '\d+']`) so `/bulk/delete` isn't captured by
> `/{id}/delete`. Batch update uses PropertyAccess; collection associations (ManyToMany) need a
> custom apply and are best left out of `batchUpdate` for now.

### Inline editing

A column with a `control` becomes inline-editable with `editable => true` (or
`['trigger' => 'click'|'dblclick']`, default `click`). The cell value is wrapped in a
`.gv-editable` span; on the trigger the `gridview-inline-edit` controller fetches a single-field
editor (built from the column's control, so it reuses validation incl. NotBlank/UniqueEntity),
submits it via fetch (OK button or Enter), and swaps in the new value with a ✓ flash. ✕ or Escape cancels, one cell at a time.

```php
['attribute' => 'code',   'editable' => true, 'control' => ['type' => 'text', 'unique' => true, ...]],
['attribute' => 'active', 'editable' => true, 'type' => 'boolean', 'control' => ['required' => false]],
['attribute' => 'type',   'editable' => true, 'type' => 'relation', 'control' => ['options' => [...]]],
```

Set the base URL in `crud.inlineUrl`; the controller appends `/{id}/{field}`. One endpoint serves
both GET (editor) and POST (save), and **must only edit columns flagged editable**:

```php
'crud' => ['inlineUrl' => $this->generateUrl('gridview_user_index') . '/inline'],

#[Route('/inline/{id}/{field}', name: 'inline', methods: ['GET', 'POST'],
        requirements: ['id' => '\d+', 'field' => '[a-zA-Z_]+'])]
public function inline(Request $request, int $id, string $field): Response
{
    $entity = $repo->find($id) ?? throw $this->createNotFoundException();
    $column = null;
    foreach ($this->buildGridview()->getColumns() as $c) {
        if ($c->getAttribute() === $field && $c->isEditable()) { $column = $c; break; }
    }
    if ($column === null) { throw $this->createNotFoundException(); }   // editable-only

    $action = $this->generateUrl('gridview_user_inline', ['id' => $id, 'field' => $field]);
    if ($request->isMethod('GET')) {
        return new Response($crud->renderInlineEditor(User::class, $column, $entity, $action));
    }
    $r = $crud->saveInline(User::class, $column, $entity, $request, $action); // ['ok','body']
    return new Response($r['body'], $r['ok'] ? 200 : 422);
}
```

The new cell display after save is produced by the handler's value stringifier (scalar / DateTime /
`getName()` / collection-join), so relations show their label.

### Clone semantics

`clone` copies the entity, nulls the identifier, and gives each **to-many association its own new
collection** (same related entities, independent of the source). Use `cloneCallback(object $clone,
object $source)` only to reset unique scalar fields or further customize:

```php
$crud->createForm(User::class, $columns, $mode, $entity, $request, [
    'cloneCallback' => fn(User $c) => $c->setCode('')->setUsername('')->setEmail(''),
]);
```

---

## Controller base classes

The bundle ships two abstract controllers that package everything above (grid
building plus the `index` / `export` / CRUD actions and their route wiring), so a
host controller only declares its entity, its columns and a small config array.
They live in `Fedale\GridviewBundle\Controller`:

- **`AbstractGridController`** — read-only grid: `index` + `export`.
- **`AbstractCrudGridController`** — extends it with `new`, `update/{id}`,
  `clone/{id}`, `exists`, `{id}/delete`, `bulk/delete`, `bulk/update`,
  `inline/{id}/{field}`.
- **`AbstractDetailController`** — read-only single-record "show" (`show/{id}`)
  that reuses the same `buildColumns()` to render a key/value table. See
  [DetailView](#detailview-single-record).

### How the routes work

The `#[Route]` attributes sit on the base methods and are **inherited** by every
concrete controller; each picks up that controller's own class-level prefix. So a
single `#[Route('/gridview/user', name: 'gridview_user_')]` on the subclass yields
`gridview_user_index`, `gridview_user_new`, … automatically. The route loader only
scans the app's `src/Controller/`, so the abstract bases never register routes on
their own. To customise one route, override that method in the subclass with a new
`#[Route]`.

Services (builder factory, CRUD handler, exporter registry, search model, entity
manager, CSRF manager) are pulled lazily via `getSubscribedServices()`, so a
subclass needs **no constructor** unless it has extra dependencies of its own.

### What a subclass implements

| Member | Required | Purpose |
|--------|----------|---------|
| `getDataClass(): string` | yes | Entity FQCN backing the grid |
| `buildColumns(): array` | yes | Column definitions |
| `getDataProviderConfig(): array` | yes | `models` / `pagination` / `sort` |
| `configure(): array` | no | Scalar config overrides (see below) |
| `beforeSave(FormInterface, string $mode): void` | no (CRUD) | Hook before persist (e.g. password hashing) |
| `onClone(object $clone): void` | no (CRUD) | Extra mutation of a clone (unique fields are cleared automatically) |

### The `configure()` array

`configure()` returns only the keys you want to change; they are merged over the
defaults. The live-uniqueness whitelist (`exists`) and the clear-on-clone fields
are **derived automatically** from the columns flagged `control.unique` — no extra
config needed.

| Key | Default | Applies to | Description |
|-----|---------|------------|-------------|
| `id` | entity short name (`User`→`user`) | both | Grid id + YAML config lookup |
| `indexTemplate` | `gridview/with_sidebar.html.twig` | both | Template rendered by `index` |
| `exportFilename` | `null` → falls back to `id` | both | Export file name (no extension) |
| `attributes` | `['class' => 'table']` | both | Table-level HTML attributes |
| `options` | `[]` | both | Extra builder options (layout, `reorderColumns`, …) |
| `title` | `''` | CRUD | Modal / page title |
| `mode` | `'modal'` | CRUD | `'modal'` \| `'page'` \| `'custom'` |
| `formView` | `null` | CRUD | Custom form layout (null = auto) |
| `pageTemplate` | `null` | CRUD | Full-page wrapper for page/custom mode |
| `addLabel` | `'New'` | CRUD | Label of the add toolbar button |
| `filterFormName` | `'myform'` | CRUD | Query key of the filter form (for "all" bulk ids) |

### Read-only example

```php
#[Route('/gridview/customer', name: 'gridview_customer_')]
class CustomerController extends AbstractGridController
{
    protected function getDataClass(): string { return Customer::class; }

    protected function getDataProviderConfig(): array
    {
        return [
            'models' => Customer::class,
            'pagination' => ['defaultPageSize' => 20],
            'sort' => ['id' => ['asc' => ['c.id'], 'desc' => ['c.id'], 'default' => 'desc']],
        ];
    }

    protected function buildColumns(): array
    {
        return ['id', ['attribute' => 'code', 'label' => 'Code', 'filter' => ['type' => 'text']]];
    }
}
```

`id` defaults to `customer`, so no `configure()` is needed; `index` and `export`
come for free.

### Full-CRUD example

```php
#[Route('/gridview/user', name: 'gridview_user_')]
class UserController extends AbstractCrudGridController
{
    public function __construct(private UserPasswordHasherInterface $passwordHasher) {}

    protected function getDataClass(): string { return User::class; }

    protected function configure(): array
    {
        return [
            'title'    => 'User',
            'addLabel' => 'New user',
            'formView' => 'gridview/user/_form.html.twig',
            'options'  => ['layout' => ['toolbar' => '{addButton} {savedSearch} {export}']],
        ];
    }

    protected function getDataProviderConfig(): array { /* models / pagination / sort */ }

    protected function buildColumns(): array
    {
        return [
            ['type' => 'checkbox'],
            'id',
            ['attribute' => 'code', 'label' => 'Code', 'editable' => true,
             'control' => ['type' => 'text', 'required' => true, 'unique' => true]],
            ['type' => 'action', 'layout' => '{edit} {clone} {delete}', 'buttons' => [
                'edit'   => fn($r) => CrudButton::edit($this->generateUrl($this->routeName('update'), ['id' => $r['id']]), $this->config('mode')),
                'clone'  => fn($r) => CrudButton::clone($this->generateUrl($this->routeName('clone'), ['id' => $r['id']]), $this->config('mode')),
                'delete' => fn($r) => CrudButton::delete($this->generateUrl($this->routeName('delete'), ['id' => $r['id']])),
            ]],
        ];
    }

    // Hash the plaintext password on add/clone only.
    protected function beforeSave(FormInterface $form, string $mode): void
    {
        if (in_array($mode, ['add', 'clone'], true)) {
            $user = $form->getData();
            $user->setPassword($this->passwordHasher->hashPassword($user, (string) $form->get('plainPassword')->getData()));
        }
    }
}
```

`routeName('update')` builds the route name from this controller's own prefix, so
the action buttons keep working whatever the prefix is. The manual
[route wiring](#wiring-the-routes-host-app-owns-them) remains available for
controllers that need a fully custom action set.

---

## DetailView (single record)

`DetailView` is the single-record sibling of the grid: same column DNA, but it
renders **one** model as a vertical **key/value table** (the "show" view of a
CRUD) instead of a list. It has **no** filters, sort, pagination, global search,
Turbo-Frame switching or token layout — none of those apply to one record.

You declare columns **exactly as for the grid** (same `buildColumns()` array): the
detail reuses each column's `label` and cell renderer, ignoring the filter/sort
side entirely.

### Quick start

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

### Using the controller base

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

### The model — what `setModel()` expects

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

### Which columns are shown

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

### Rendering

`render(?string $view = null)` returns a `Response` (defaulting to the `template`
option). The shipped template emits a `<table>` (HTML attributes from
`setAttributes()`) with one `<tr><th>label</th><td>value</td></tr>` per data
column, replicating the grid's `tbody` cell escaping (including per-column
`twigFilter`). For non-Twig consumers, `DetailView::rows()` returns a plain
`[['label' => …, 'value' => …], …]` array (note: `rows()` does not apply
`twigFilter` — use the template for that).

### YAML configuration

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

---

## Export

Exports respect the **current filters/sort** (the data provider is re-run without
pagination) and the **export columns** (those flagged `exportable`, else the visible data columns).
Built-in **CSV**; the format set is **extensible** — implement `ExporterInterface` and the service is
auto-registered (no config), appearing in the export menu and selectable via `?format=<key>`:

```php
// app/src/Export/JsonExporter.php
class JsonExporter implements \Fedale\GridviewBundle\Export\ExporterInterface
{
    public function getKey(): string   { return 'json'; }
    public function getLabel(): string { return 'JSON'; }
    public function export(iterable $rows, iterable $columns, array $context = []): Response { /* … */ }
}
```

Wire it: add the `{export}` token and pass the menu (`url` + `formats` from the registry); the export
action delegates to the chosen exporter:

```php
->setOptions([
    'export' => [
        'url'     => $this->generateUrl('gridview_user_export'),
        'formats' => array_map(fn($e) => ['key' => $e->getKey(), 'label' => $e->getLabel()],
                               array_values($exporters->all())),  // GridExporterRegistry
    ],
    'layout' => ['toolbar' => '{addButton} {export}'],
])

#[Route('/export', name: 'export', methods: ['GET'])]
public function export(Request $request, GridExporterRegistry $exporters): Response
{
    $format = (string) $request->query->get('format', 'csv');
    if (!$exporters->has($format)) { throw $this->createNotFoundException(); }
    $g = $this->buildGridview();
    return $exporters->get($format)->export($g->getExportRows(), $g->getExportColumns(), ['filename' => 'utenti']);
}
```

The `{export}` link carries the current querystring, so the download reflects the active filters.
Mark columns with `exportable => true` to restrict the export to a subset.

## Saved searches & selections

Users can save the current **filters** (querystring) and **row selections** under a name and
re-apply them. Persistence is client-side and **pluggable** via `assets/preferences.js`:

```js
// Default: localStorage (persistent, per-browser), scoped per route.
// To back it with your API instead, set this before the controllers connect:
window.gridviewPreferenceProvider = {
    load(scope, bucket) { /* return Array */ },
    save(scope, bucket, items) { /* persist */ },
};
```

**Saved searches** — add the `{savedSearch}` token (e.g. in the toolbar). The
`gridview-saved-search` controller saves `window.location.search` under a name and re-applies it
with `Turbo.visit`. Bucket `searches`, items `{ name, query }`.

**Saved selections** — with a `checkbox` column the header dropdown gains *Salva selezione…* and a
list of saved sets. `gridview-selection` stores the selected ids (bucket `selections`,
`{ name, ids }`, max 5000) and reloads them into the selection on demand.

Both are scoped by `window.location.pathname` and need no new backend endpoints.

**Naming** — instead of `window.prompt`, a small built-in modal (`assets/prompt-modal.js`, a
Promise-based `promptModal({title, label, value})`) collects the name, pre-filled with a sensible
default: `ricerca <date> (<n>)` for searches (n = next index) and `selezione <date> (<n>)` for
selections (n = number of selected rows). Enter confirms, Escape / backdrop cancels.

**Column reorder** — set `reorderColumns => true` to make toggleable column headers draggable
(native HTML5 drag-and-drop). `gridview-column-order` reorders the `<th>` and every row's `<td>` by
their `data-col-key` (the column attribute) and persists the order via the preference provider
(bucket `columnOrder`), re-applying it on connect — so it survives Turbo refreshes. Purely cosmetic
(client-side); structural columns (checkbox/actions) stay put.

---

## Attributes & Styling

HTML attributes for the table and its surrounding elements are set via `setAttributes()`:

```php
->setAttributes([
    'class'     => 'table table-striped table-hover',  // <table> class
    'container' => [
        'class'     => 'table-responsive',
        'data-type' => 'my-grid',
    ],
    'header'    => ['class' => 'gridview-header'],
    'filter'    => ['class' => 'gridview-filter'],
    'row'       => ['class' => 'clickable-row'],
])
```

| Key | Target element |
|-----|---------------|
| `class` | `<table>` element |
| `container` | Div wrapping the entire grid |
| `header` | Div wrapping the header section |
| `filter` | `<tr>` containing filter inputs |
| `row` | Every `<tr>` in the tbody |

---

## YAML Configuration

Global defaults and per-grid presets live in `config/packages/gridview.yaml`.
Runtime calls to `setOptions()` and `setAttributes()` override these values — they are merged,
not replaced.

### Global defaults

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
        gridview: "{header} {table} {footer}"
        header:   "{globalSearch} {filterSubmit}"
        footer:   "{pagination}"
    attributes:
      class: "table table-striped"
      container:
        class: "table-responsive"
```

### Per-grid presets

Register a named preset under `gridviews`, then pass the matching ID to the builder:

```yaml
fedale_gridview:
  gridviews:
    customer_list:
      options:
        globalSearch: ["c.name", "c.email"]
        layout:
          toolbar: "{addButton} {columnVisibility}"
          gridview: "{toolbar} {header} {table} {footer}"
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

### All available options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `emptyText` | `string` | `'No records found'` | Text shown when there are no data rows |
| `useTurbo` | `bool` | `true` | Wrap the grid in a Turbo Frame and respond with partial HTML on frame requests |
| `showThead` | `bool` | `true` | Include `{thead}` in the auto-computed table layout |
| `showTfoot` | `bool` | `true` | Include `{tfoot}` in the auto-computed table layout |
| `globalSearch` | `string[]` | `[]` | DQL fields searched by the global search input |
| `addRoute` | `string\|null` | `null` | Route name for the `{addButton}` token |
| `addLabel` | `string` | `'Add'` | Label for the `{addButton}` link |
| `routeName` | `string\|null` | `null` | List route used for sort/pagination/filter links instead of the current `_route` — required so the grid renders correctly from a CRUD POST (Turbo Stream) |
| `crud` | `array` | `[]` | CRUD modal config: `title`, `addUrl` (enables the `{addButton}` modal trigger) |
| `formName` | `string` | `'myform'` | Name of the filter form; change this to support multiple grids with filters on the same page |
| `caption` | `string\|null` | `null` | Optional `<caption>` text for the table |
| `pagination.pageSelect` | `bool` | `true` | Show the jump-to-page `<select>` in the pagination |
| `pagination.pageSelectThreshold` | `int` | `10` | Minimum page count before the `<select>` appears |
| `realtime.enabled` | `bool` | `false` | Enable real-time updates over Mercure (see [Real-time updates](#real-time-updates-mercure)) |
| `realtime.topicPrefix` | `string` | `'gridview/'` | Prefix for the per-grid Mercure topic (`<prefix><id>`) |
| `layout` | `array` | see above | Layout token strings, template overrides, and inline slots |

### Detail-view presets

Single-record [DetailViews](#detailview-single-record) use their own YAML sections
— `defaults.detailview` and `detailviews.<id>` — kept separate from `gridviews` so
grid-only keys never leak in. See [DetailView → YAML configuration](#yaml-configuration-1).

### Multiple grids with filters on the same page

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

### Merge precedence (lowest → highest)

1. Built-in code defaults (`Gridview::$options`)
2. `fedale_gridview.defaults` (YAML)
3. `fedale_gridview.gridviews.<id>` (YAML)
4. `setOptions()` / `setAttributes()` calls (runtime)

---

## JavaScript Controllers

The bundle ships four Stimulus controllers located in
`FedaleGridviewBundle/assets/controllers/`.

Register them in your app's `controllers.json` (or import them in `bootstrap.js`).

### `gridview-filter`

Auto-submits the filter form after a 300 ms debounce on every `input` event.
Also restores focus to the last active input after a Turbo-Frame swap, and highlights
matched search terms in the rendered rows.

**Connects to:** the `<form>` element wrapping the grid.

**Values:**

| Value | Type | Default | Description |
|-------|------|---------|-------------|
| `delay` | `Number` | `300` | Debounce delay in milliseconds |

**Usage (auto-applied by the bundle):**

```html
<form data-controller="gridview-filter" data-turbo-action="replace">
  ...
</form>
```

---

### `gridview-selection`

Manages row selection state across paginated pages using `sessionStorage`.
Supports three selection modes: current page, visible rows, and all records.

**Connects to:** the container div wrapping the grid (applied when a `CheckboxColumn` is present).

**Values:**

| Value | Type | Description |
|-------|------|-------------|
| `gridId` | `String` | Unique grid key (set automatically) |

**Targets:**

| Target | Element | Description |
|--------|---------|-------------|
| `checkbox` | `<input type="checkbox">` in each data row | Row checkbox |
| `headerCheckbox` | `<input type="checkbox">` in the header | Select-all checkbox |
| `bulkBar` | the `{bulkBar}` wrapper | Bulk action bar; shown when ≥1 row is selected |
| `count` | element inside the bulk bar | Selected-count display (or "Tutti i record" in all-mode) |

**Actions available in templates:**

| Action | Description |
|--------|-------------|
| `gridview-selection#toggle` | Toggle a single row (exits all-mode) |
| `gridview-selection#togglePage` | Toggle all visible rows on the current page |
| `gridview-selection#selectAll` | Enter all-mode (all pages, all records) |
| `gridview-selection#selectVisible` | Add all visible rows to selection |
| `gridview-selection#deselectAll` | Clear selection completely |
| `gridview-selection#bulk` | Open the CRUD modal for a bulk action; appends the selected ids (or `all=1` + current filters) to the button's `url` param. Dispatches `gridview-selection:open` (caught by `gridview-crud#openFromEvent`) |
| `gridview-selection#saveSelection` | Save the current selected ids under a name (preference provider) |
| `gridview-selection#loadSelection` | Re-apply a saved selection (`index` param) |
| `gridview-selection#removeSelection` | Delete a saved selection (`index` param) |

Extra targets: `bulkBar`, `count` (bulk bar); `savedList` (saved-selections list, filled by JS).

---

### `gridview-saved-search`

Saves the current querystring (filters + sort) under a name and re-applies it (`Turbo.visit`).
Persisted via the pluggable preference provider, scoped per route.

**Connects to:** the `{savedSearch}` widget.

**Actions:** `#save` (save current), `#apply` (`query` param), `#remove` (`index` param).
**Target:** `list` (saved-searches list, filled by JS).

---

### `gridview-column-order`

Drag-and-drop column reorder (enabled by `reorderColumns => true`). Reorders `<th>`/`<td>` by
`data-col-key` and persists the order via the preference provider (`columnOrder`), re-applied on
connect. No template actions — it binds native drag events on the header.

**Session storage keys:**

| Key | Content |
|-----|---------|
| `gv-sel-{gridId}` | JSON array of selected row IDs |
| `gv-sel-{gridId}-all` | `"1"` when in all-mode |

---

### `gridview-visibility`

Toggles column visibility client-side and persists the state in `sessionStorage`.
Hidden columns retain their DOM nodes with `display:none` so the column count stays
consistent for colspan calculations.

**Connects to:** the `{columnVisibility}` section template.

**Values:**

| Value | Type | Description |
|-------|------|-------------|
| `gridId` | `String` | Unique grid key (set automatically) |

**Targets:**

| Target | Element |
|--------|---------|
| `menu` | The dropdown `<ul>` |

**Session storage key:** `gv-vis-{gridId}`

**Scope selectors used internally:**

```
table[data-gv="{gridId}"] [data-col="{colIndex}"]
```

Every cell (`<th>`, `<td>`) rendered by the bundle carries `data-col="{colIndex}"` and the
`<table>` element carries `data-gv="{gridId}"`, which is how the controller targets cells
for a specific column without touching other tables on the page.

**Columns that are not toggleable** (`CheckboxColumn`, `ActionColumn`) are excluded from the
dropdown automatically because their `isToggleable()` method returns `false`.

#### Declaring a column hidden by default

```php
$columns = [
    [
        'attribute' => 'internal_notes',
        'label'     => 'Notes',
        'visible'   => false,   // hidden on load, toggleable in the dropdown
    ],
];
```

---

### `gridview-page-jump`

Navigates to the page chosen in the pagination's jump-to-page `<select>`. Each `<option>`
value is the target page URL, so the controller just visits it on `change` — using
`Turbo.visit(url, { action: 'advance' })` when Turbo is active, otherwise `window.location`.

**Connects to:** the `{pagination}` `<select>` wrapper (rendered automatically when
`pagination.pageSelect` is on and the page count reaches the threshold).

**Values:**

| Value | Type | Description |
|-------|------|-------------|
| `turbo` | `Boolean` | Use `Turbo.visit` instead of a full navigation (mirrors the `useTurbo` option) |

**Action available in templates:**

| Action | Description |
|--------|-------------|
| `gridview-page-jump#jump` | Navigate to the URL of the selected `<option>` |

---

### `gridview-crud`

Drives the CRUD modal: fetches add/edit/clone/delete forms into a Bootstrap modal and submits them
via `fetch`. A `text/vnd.turbo-stream.html` response refreshes the grid frame and closes the modal;
an HTML response (validation errors) is re-injected into the modal.

**Connects to:** the grid container (applied automatically when `crud` options are set).

**Actions available in templates** (emitted by `CrudButton` / the `{addButton}` token):

| Action | Description |
|--------|-------------|
| `gridview-crud#open` | Open the modal and load the form/recap from `data-gridview-crud-url-param` |
| `gridview-crud#submit` | Intercept the modal form / inline form submit (handles the Turbo Stream) |

---

### `gridview-inline-edit`

Inline cell editing. On an editable cell's trigger it fetches the editor from
`${base}/${id}/${field}`, submits via fetch (server validation is authoritative), and swaps the cell
with the new value. OK/Enter saves (✓ flash), ✕/Escape cancels, one cell at a time.

**Connects to:** the grid container (applied when `crud.inlineUrl` is set).

**Values:** `base` (String) — inline endpoint base; the controller appends `/{id}/{field}`.

**Actions** (emitted on `.gv-editable` cells / the injected editor):

| Action | Description |
|--------|-------------|
| `gridview-inline-edit#edit` | Open the editor for the clicked cell |
| `gridview-inline-edit#submit` | Submit the editor form (fetch) |
| `gridview-inline-edit#key` | Enter = save, Escape = cancel |

---

### `gridview-form-validate`

Optional live validation for the generated CRUD form (see *Live validation* above). Validates
required/format on `input`/`blur` and checks uniqueness with a debounced fetch. Server-side
validation stays authoritative.

**Connects to:** the CRUD form (applied when a `validate` context is passed to `renderForm()`).

**Values:**

| Value | Type | Description |
|-------|------|-------------|
| `checkUrl` | `String` | Endpoint returning `{exists: bool}` for the uniqueness check |
| `unique` | `Array` | Field names (bare, e.g. `code`) checked for uniqueness |
| `id` | `String` | Current row id to exclude (edit only; empty for add/clone) |

---

## Real-time updates (Mercure)

When a user creates, edits or deletes a record, **everyone else viewing the same grid**
can see the change on-the-fly. The grid auto-refreshes and shows a short toast — no manual
reload needed.

This is **opt-in per grid** and requires [`symfony/mercure-bundle`](https://symfony.com/doc/current/mercure.html).
Without it, the bundle still boots and the feature is simply inert.

### How it works (signal + auto-refresh)

```
user-2: POST /gridview/user/update/42
   └─ AbstractCrudGridController → save() OK
        └─ publish a PRIVATE Mercure update to topic "gridview/user"
             payload = {"gridId":"user","action":"update"}   ← no row data
user-1 (already on the grid):
   gridview-mercure Stimulus controller (EventSource, withCredentials)
     └─ on message → re-submits the filter form (debounced) + shows a toast
```

The published message carries **only** `gridId` + `action`, never row HTML or field
values. Each observer reacts by **refetching the grid through its own request**, so the
server re-applies that user's filters, pagination and authorization. Consequences:

- No data a user isn't allowed to see can leak through the stream.
- The refreshed rows always match the observer's **current filter/page**, not the editor's.

### Topic per grid

Events are scoped to one topic per grid: `<topicPrefix><gridId>` (default
`gridview/<id>`). The `<id>` is the grid id — the entity short name lowercased
(e.g. `User` → `user`), or whatever a controller sets via `configure()`'s `id`.
Subscriptions are therefore limited to the single grid.

### Private topics

Topics are **private**: on render, the controller sets a `mercureAuthorization` cookie
(JWT) granting `subscribe` to **only the current grid's topic**, and only for a user who
could open the grid (same firewall / access control as the index route). The browser sends
that cookie when opening the `EventSource` (`withCredentials`).

> A misconfigured hub never takes the grid down: the cookie call is guarded, so on failure
> the grid renders normally with real-time simply off.

### Enabling it

1. Install the hub support:

   ```bash
   composer require symfony/mercure-bundle
   ```

2. Turn it on for the grids that need it, in `config/packages/gridview.yaml`:

   ```yaml
   fedale_gridview:
     gridviews:
       user:                    # the grid id (entity short name, lowercased)
         options:
           realtime:
             enabled: true
             # topicPrefix: "gridview/"   # optional, this is the default
   ```

   Only **CRUD grids publish** (a controller extending `AbstractCrudGridController`).
   A read-only grid can still subscribe, but nothing will publish to its topic.

3. Register the Stimulus controller once (app `assets/bootstrap.js`):

   ```js
   import GridviewMercureController from '.../assets/controllers/gridview-mercure_controller.js';
   app.register('gridview-mercure', GridviewMercureController);
   ```

4. Configure the hub URLs. **`MERCURE_PUBLIC_URL` must be the same host as the app**, or
   Mercure refuses to create the subscription cookie ("different second-level domain").
   The simplest setup is to serve the hub **same-origin** via a reverse-proxy on
   `/.well-known/mercure`:

   ```dotenv
   # app/.env(.local)
   MERCURE_URL=http://mercure/.well-known/mercure                 # internal: app → hub (publish)
   MERCURE_PUBLIC_URL=https://your-app.example.com/.well-known/mercure   # browser → hub (subscribe)
   MERCURE_JWT_SECRET="<change-me>"
   ```

   ```nginx
   # reverse-proxy the hub same-origin (SSE: buffering off, long timeout)
   location /.well-known/mercure {
       resolver 127.0.0.11 valid=10s;
       set $upstream http://mercure;
       proxy_pass $upstream$request_uri;
       proxy_http_version 1.1;
       proxy_set_header Connection "";
       proxy_buffering off;
       proxy_read_timeout 24h;
   }
   ```

### The `gridview-mercure` controller

| Value | Type | Description |
|-------|------|-------------|
| `hub` | `String` | Public hub URL (from `MERCURE_PUBLIC_URL`) |
| `topic` | `String` | The grid's topic, e.g. `gridview/user` |
| `form` | `String` | Id of the filter form to re-submit on a change (`gv-form-<key>`) |
| `delay` | `Number` | Debounce window in ms (default `400`) — coalesces bursts / bulk ops |

It opens the `EventSource`, debounces incoming signals, re-submits the grid's filter form
(reusing `gridview-filter`'s loading overlay; falls back to `turbo-frame.reload()`), and
shows a `.gv-info-banner` toast. On disconnect it closes the stream.

> The editor also receives the signal and sees the toast — harmless, their grid is already
> fresh from their own action. To suppress it, include a client id in the payload and
> ignore your own — not done by default.

---

## Full Example

A complete controller action combining the most common features:

```php
use Fedale\GridviewBundle\Column\ActionButton;

#[Route('/customers', name: 'customer_list', methods: ['GET'])]
public function list(Request $request): Response
{
    $dataProvider = [
        'models'     => Customer::class,
        'pagination' => ['defaultPageSize' => 25],
        'sort'       => [
            'name'  => ['asc' => ['c.name'],  'desc' => ['c.name'],  'default' => 'asc'],
            'email' => ['asc' => ['c.email'], 'desc' => ['c.email'], 'default' => 'asc'],
        ],
    ];

    $columns = [
        ['type' => 'checkbox'],
        'id',
        [
            'attribute' => 'name',
            'label'     => 'Full Name',
            'filter'    => ['type' => 'text'],
        ],
        [
            'attribute'  => 'email',
            'label'      => 'E-Mail',
            'value'      => fn(array $data) => '<a href="mailto:' . $data['email'] . '">' . $data['email'] . '</a>',
            'twigFilter' => 'raw',
            'filter'     => ['type' => 'text'],
        ],
        [
            'attribute' => 'internal_notes',
            'label'     => 'Notes',
            'visible'   => false,
        ],
        [
            'type'    => 'action',
            'layout'  => '{view} {edit} {delete}',
            'buttons' => [
                'view' => new ActionButton(
                    fn(array $row) => sprintf('<a href="/customers/%d">View</a>', $row['id'])
                ),
                'edit' => new ActionButton(
                    fn(array $row) => sprintf('<a href="/customers/%d/edit">Edit</a>', $row['id']),
                    roles: ['ROLE_EDITOR', 'ROLE_ADMIN'],
                ),
                'delete' => new ActionButton(
                    fn(array $row) => sprintf('<a href="/customers/%d/delete">Delete</a>', $row['id']),
                    roles: ['ROLE_ADMIN'],
                ),
            ],
        ],
    ];

    $gridview = $this->createGridviewBuilder()
        ->setId('customer_list')                          // uses YAML preset if defined
        ->setSearchModel($this->customerSearchModel)
        ->setOptions([
            'globalSearch' => ['c.name', 'c.email'],
            'addRoute'     => 'customer_new',
            'addLabel'     => 'New Customer',
            'layout'       => [
                'gridview' => '{toolbar} {header} {table} {footer}',
                'toolbar'  => '{addButton} {columnVisibility}',
                'header'   => '{globalSearch}',
                'footer'   => '{pagination}',
            ],
        ])
        ->setAttributes([
            'class'     => 'table table-striped',
            'container' => ['class' => 'table-responsive'],
        ])
        ->setDataProvider($dataProvider)
        ->setColumns($columns)
        ->renderGridview();

    return $gridview->renderGrid('@FedaleGridview/gridview/index.html.twig', []);
}
```

---

## Extending the Bundle

### Public interfaces

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

### Creating a custom column

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

### Listening to row events

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

## Theming

The bundle ships a **framework-agnostic** stylesheet
(`assets/styles/gridview.scss`, compiled into the `gridview` Encore entry — no
CDN dependency). All visuals are driven by **CSS custom properties** scoped under
`[data-gridview]`, so you restyle the grid without touching the bundle.

### Light / dark mode

Dark mode is automatic and also togglable, in this order of precedence:

- `@media (prefers-color-scheme: dark)` — follows the OS.
- `html[data-bs-theme="dark"]` — Bootstrap's theme toggle (used by this app).
- `html[data-gv-theme="dark"]` — generic toggle for non-Bootstrap apps.

A matching `…="light"` selector forces light mode back on.

### Overriding tokens

Three equivalent recipes — pick the one matching your stack:

```css
/* 1) Plain CSS / custom — scope to the grid */
[data-gridview] {
  --gv-color-primary: #d6336c;
  --gv-th-bg: #faf0f3;
}
```

```scss
// 2) Bootstrap 5 — bridge gridview tokens to Bootstrap variables
[data-gridview] {
  --gv-color-primary: var(--bs-primary);
  --gv-color-border:  var(--bs-border-color);
  --gv-th-bg:         var(--bs-tertiary-bg);
}
```

```css
/* 3) Tailwind — set tokens in a base layer */
@layer base {
  [data-gridview] { --gv-color-primary: theme('colors.indigo.600'); }
}
```

### Token reference

Core: `--gv-color-primary`, `--gv-color-bg`, `--gv-color-bg-subtle`,
`--gv-color-bg-hover`, `--gv-color-border`, `--gv-color-text`,
`--gv-color-text-muted`, `--gv-color-link`, `--gv-color-link-hover`,
`--gv-color-highlight-bg`, `--gv-color-highlight-txt`.

Inputs/buttons: `--gv-input-bg`, `--gv-input-border`, `--gv-input-border-focus`,
`--gv-input-radius`, `--gv-btn-bg`, `--gv-btn-bg-hover`, `--gv-btn-border`,
`--gv-btn-primary-bg`, `--gv-btn-primary-hover`, `--gv-btn-radius`.

Table/toolbar/pagination/dropdown: `--gv-th-bg`, `--gv-th-color`,
`--gv-table-border`, `--gv-tr-hover-bg`, `--gv-sort-color`,
`--gv-sort-active-color`, `--gv-page-bg`, `--gv-page-bg-active`,
`--gv-page-border`, `--gv-dropdown-bg`, `--gv-dropdown-border`,
`--gv-dropdown-shadow`.

**Column types** (Fase 7 — emitted by the render pipeline):

| Token | Used by | Default |
|---|---|---|
| `--gv-img-max-h` / `--gv-img-radius` | `image` (`.gv-img`) | `40px` / `3px` |
| `--gv-rating-color` | `rating` (`.gv-rating`) | `#f59e0b` |
| `--gv-badge-bg` / `--gv-badge-color` | `badge` (`.gv-badge`) | grey chip |
| `--gv-badge-radius` / `--gv-badge-padding` / `--gv-badge-font-size` | `badge` shape | pill |
| `--gv-json-bg` / `--gv-json-color` / `--gv-json-border` | `json` (`.gv-json`) | subtle box |

`number`/`currency`/`percent` cells carry `.gv-num` (right-aligned, tabular
figures); `list`/`array` carry `.gv-list`. Per-value badge colours can also be
passed inline from the column config (`format.colors: {VALUE: '#0a0'}`), which
emit a `gv-badge--<value>` modifier class for finer CSS targeting.

> After changing the SCSS, rebuild assets: `cd app && yarn encore dev`
> (or `yarn watch`).

## Internationalization (i18n)

All built-in UI chrome (pagination, bulk bar, export, saved searches, CRUD
dialogs, empty text, filter reset, search placeholder) is translated through the
**`GridviewBundle`** translation domain. The bundle ships `it` and `en`
catalogs in `translations/GridviewBundle.<locale>.yaml`; the grid follows the
request locale automatically.

Override or add a language from the host app by creating
`translations/GridviewBundle.<locale>.yaml` (app translations win over the
bundle's). Keys are dotted (`pagination.next`, `bulk.delete`, `crud.cancel`, …);
count-bearing messages take a `%count%` parameter.

Two config defaults use the **source string as key** so they localize out of the
box while letting custom values pass through untranslated:

- `options.emptyText` (default `No records found`)
- `options.addLabel` (default `Add`)

Set either to one of your own translation keys (or any literal) to override.

**Booleans** render as language-neutral glyphs (`✓` / `✗`) by default — no
translation needed. For textual booleans, set the column's `format` options:
`{'true': 'Yes', 'false': 'No'}` (pass already-translated strings from your
controller if you need them localized).

> Column **labels** and **select option labels** are passed through your app's
> templates/config as-is; use translation keys there if you want them localized.
