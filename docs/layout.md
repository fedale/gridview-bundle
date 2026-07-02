# Layout & Responsive

## Layout System

The grid renders via a **token-based layout** resolved by a recursive engine. Each
token is one of three node types:

- **region** — a recursive container (it has a layout key of its own; the engine
  renders a wrapper and recurses into its children). Examples: `shell`, `header`,
  `toolbar`, `dataview`, `footer`.
- **block** — a leaf widget that resolves to a `sections/{token}.html.twig`
  template (no children). Examples: `globalSearch`, `addButton`, `pagination`.
- **slot** — inline ad-hoc content (see [Inline slots](#inline-slots)).

A region without a dedicated template renders through the shared generic wrapper
`sections/_region.html.twig` (`<div class="gv-region gv-region--{name}">…children…</div>`);
an empty region collapses to nothing. The presence of a layout key **promotes** a
token to a region (it wins over a block template of the same name), so region and
block names must stay disjoint.

#### How a token is resolved

The engine renders each token through a single dispatch, in this precedence:

1. **slot** → render the inline content (see [Inline slots](#inline-slots));
2. **region** (a `layout[token]` key exists) → render its template — the first that
   exists among `layout.templates[token]` → `sections/{token}.html.twig` →
   the generic `sections/_region.html.twig` — then recurse into the children;
3. **block** (no layout key, but a template resolves) → render `sections/{token}.html.twig`;
4. otherwise → empty string (unknown token).

Children are emitted by `gridview_children(region)`, which also applies the inline
widths (`{token NN%}`). Self/cyclic references are broken by a visited-set guard, so
a region can never recurse into itself.

> **Naming.** Tokens are `camelCase`, `[A-Za-z0-9_]` only — no dashes, no `gv-`
> prefix (scoping comes from the tree, not the name). The `gv-` prefix lives only on
> CSS classes and data-attributes.

> **Upgrading from the old vocabulary.** The root key `gridview` is now **`shell`**
> and the data region `table` is now **`dataview`**; the chrome widgets moved from
> `header` into `toolbar` (so `header` is `{heading} {toolbar}` by default). The
> table-internal tokens (`thead`/`filter`/`tbody`/`tfoot`/`empty`) are unchanged but
> are now internals of the `table` renderer (templates under `sections/dataview/`).
> Rewrite any `layout.gridview` / `layout.table` keys to `shell` / `dataview`.

### Default layout

```
shell:    "{header} {dataview} {footer}"        ← root region (the _grid template)
header:   "{heading} {toolbar}"                  ← chrome band; {heading} renders options.title (collapses when empty)
toolbar:  "{globalSearch} {filterSubmit}"        ← CRUD controllers default to
                                                   "{addButton} {globalSearch} {spacer} {savedSearch} {columnVisibility} {export}"
dataview: null                                   ← null → table strategy: "{thead} {filter} {tbody} {tfoot}"
footer:   "{pagination}"
tfoot:    ""
```

The data region (`dataview`) is **renderer-agnostic**: its template is the active
strategy `sections/dataview/{renderer}.html.twig`, selected by `options.renderer`.
Only `table` ships today (`card`/`list` are planned); `thead`/`filter`/`tbody`/`tfoot`/`empty`
are internals of the table strategy, not top-level tokens.

### Available tokens

| Token | Type / Template | Notes |
|-------|-----------------|-------|
| `{shell}` | region (`_grid.html.twig`) | Root region; turbo-frame / form / modal chrome |
| `{header}` | region (`_region.html.twig`) | Chrome band above the data; `{heading} {toolbar}` by default |
| `{toolbar}` | region (`_region.html.twig`) | Flex row of grid controls |
| `{dataview}` | region (`sections/dataview/{renderer}.html.twig`) | The data region; renderer-agnostic (the `table` strategy renders the `<table>`) |
| `{footer}` | region (`_region.html.twig`) | Area below the data |
| `{heading}` | block (`sections/heading.html.twig`) | Renders `options.title` (collapses when empty) |
| `{sort}` | block (`sections/sort.html.twig`) | Sort dropdown of the sortable columns; placeable anywhere |
| `{thead}` | table-strategy internal (`sections/dataview/thead.html.twig`) | Column header row |
| `{filter}` | table-strategy internal (`sections/dataview/filter.html.twig`) | Column filter inputs row (header) |
| `{filterBar}` | `sections/filterBar.html.twig` | Filters of columns with `filterBar: true`; placeable anywhere, even outside the grid (see [The filterBar](filtering.md#the-filterbar--placing-filters-anywhere)) |
| `{tbody}` | table-strategy internal (`sections/dataview/tbody.html.twig`) | Data rows |
| `{tfoot}` | table-strategy internal (`sections/dataview/tfoot.html.twig`) | Table footer row |
| `{empty}` | table-strategy internal (`sections/dataview/empty.html.twig`) | "No records found" row |
| `{globalSearch}` | `sections/globalSearch.html.twig` | Global search input |
| `{filterSubmit}` | `sections/filterSubmit.html.twig` | Filter submit button — visible only when `useTurbo: false` |
| `{pagination}` | `sections/pagination.html.twig` | Page navigation |
| `{addButton}` | `sections/addButton.html.twig` | "Add" link (requires `addRoute`, or `crud.addUrl` in a CRUD controller) |
| `{columnVisibility}` | `sections/columnVisibility.html.twig` | Column show/hide dropdown |
| `{export}` | `sections/export.html.twig` | Export menu (requires `options.export = { url, formats }`; auto-wired in CRUD controllers) |
| `{spacer}` | `sections/spacer.html.twig` | Elastic gap — see [Spacing tokens](#spacing-tokens) |

### Spacing tokens

Two mechanisms control how the controls in a layout section share the horizontal space.

**`{spacer}` — elastic gap.** Insert it between two groups of tokens: everything
before it stays left, everything after it is pushed to the right edge. The gap grows
and shrinks with the available width, so the layout adapts on its own.

```php
// add + search on the left, column-visibility + export pushed right
'toolbar' => '{addButton} {globalSearch} {spacer} {savedSearch} {columnVisibility} {export}',
```

This is the **default toolbar for CRUD controllers** (`AbstractCrudGridController`), so a
CRUD grid gets it without configuring `layout.toolbar`. Override `layout.toolbar` to change it.

**`{token NN%}` — fixed-width slot.** Append a width inside the braces to give a token
a fixed share of the row. The width sizes the *slot* (the track), while the control
inside keeps its natural size and stays left-aligned — it is **not** stretched to fill
the slot. Accepted units: `%` (a bare number is treated as `%`), `px`, `rem`, `em`.

```php
'toolbar' => '{addButton 20%} {globalSearch 40%} {columnVisibility 20%} {export 20%}',
```

Slots and `{spacer}` solve opposite problems: use slots when you want rigid proportional
columns, use `{spacer}` when you want left/right grouping with elastic space between.

### Customising the layout at runtime

Pass a `layout` key inside `setOptions()`:

```php
->setOptions([
    'title'    => 'Customers',     // text rendered by {heading}
    'layout'   => [
        'shell'    => '{header} {dataview} {footer}',
        'header'   => '{heading} {toolbar}',
        'toolbar'  => '{addButton} {globalSearch} {columnVisibility}',
        'footer'   => '{pagination}',
    ],
    'addRoute' => 'customer_new',
    'addLabel' => 'New Customer',
])
```

### Adding a title

The `{heading}` block renders the `options.title` text and **collapses when the
title is empty**, so a grid shows a heading only when you set one. It is a plain
block, so place it in whichever region you like — the default puts it at the start
of `header` (`{heading} {toolbar}`):

```php
->setOptions([
    'title'  => 'Customers',
    'layout' => [
        'header'  => '{heading} {toolbar}',
        'toolbar' => '{addButton} {globalSearch} {columnVisibility}',
    ],
])
```

To change how the title looks, override the `heading` block template
(`layout.templates.heading`); to change the text, set `options.title`.

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

### Per-region HTML attributes

Every region wrapper applies the attributes returned by `gridview.regionAttr(name)`.
Set them per region under `layout.attrs`:

```php
->setOptions([
    'layout' => [
        'attrs' => [
            'shell' => ['data-analytics' => 'customers-grid'],
            'thead' => ['class' => 'sticky-top'],
            'toolbar' => ['data-testid' => 'toolbar'],
        ],
    ],
])
```

Any region or table-internal name is accepted (`shell`, `header`,
`toolbar`, `footer`, `dataview`, `thead`, `filter`, `row`, …). The generic
`_region.html.twig` wrapper emits them automatically; a dedicated region template
emits them via `{{ gridview.regionAttr(region)|options }}`.

The legacy [`setAttributes()`](theming.md#attributes--styling) bag still works and maps onto
the same regions (`container → shell`, the table `class` → `dataview`,
`header → header`, `filter`, `row`); `layout.attrs[T]` overrides it per key.

### Choosing the data renderer

The `dataview` region is renderer-agnostic. `options.renderer` picks the strategy
template `sections/dataview/{renderer}.html.twig`:

```php
->setOptions(['renderer' => 'table'])   // the default and only built-in today
```

Only `table` ships today (it renders the `<table>` and its
`thead`/`filter`/`tbody`/`tfoot`/`empty` internals); `card` and `list` are planned.
An unknown renderer falls back to `table`. To fully replace the data markup, either
add a `sections/dataview/{name}.html.twig` strategy or override the template
directly with `layout.templates.dataview`.

### Hiding thead / tfoot without editing layout

Two boolean options control whether `{thead}` and `{tfoot}` are included when the `dataview`
layout is computed automatically (i.e. when `dataview` is `null`):

```php
->setOptions([
    'showThead' => true,   // default
    'showTfoot' => false,  // removes tfoot from the table
])
```

---

## Responsive (column collapse)

On narrow viewports a wide grid normally either overflows the page or squeezes every
column unreadably. The **responsive** mode solves this DataTables-style: when the table
no longer fits its container, the least important columns are hidden and folded into an
expandable **detail row** opened with a per-row toggle (`+` / `−`).

It is entirely **client-side** — every cell is already in the DOM (the body renders all
columns), so collapsing and expanding never hit the server. There is no extra query, no
AJAX, and it works the same with or without Turbo.

### Enabling it

Turn the grid option `responsive` on, then give the collapsible columns a `priority`:

```php
protected function viewConfig(): array
{
    return [
        'options' => ['responsive' => true],
    ];
}

protected function buildColumns(): array
{
    return [
        ['type' => 'checkbox'],         // priority 0 → pinned (never collapses)
        'id',                           // priority 0 → pinned
        'name',                         // priority 0 → pinned
        ['attribute' => 'type',     'priority' => 5],
        ['attribute' => 'roles',    'priority' => 10],
        ['attribute' => 'groups',   'priority' => 20],
        ['attribute' => 'locations','priority' => 30],  // drops first
        ['type' => 'action'],           // priority 0 → pinned
    ];
}
```

You can also enable it per grid in YAML, under the grid's `options`:

```yaml
fedale_gridview:
    gridviews:
        customer:
            options:
                responsive: true
```

### How `priority` works

`priority` is a per-column integer (default `0`):

| Value   | Meaning                                                                    |
|---------|----------------------------------------------------------------------------|
| `0`     | **Pinned** — the column is never collapsed (the default).                   |
| `N > 0` | **Collapsible** — folds into the detail row when space runs out.            |

When the table overflows, columns are collapsed in **descending priority order**, so a
**higher number drops first** (it is the *least* important). Ties drop right-to-left.
Structural columns (`action` / `checkbox` / `serial`) keep priority `0`, so they always
stay visible. The grid hides only as many columns as needed to make the table fit, then
stops — on a slightly-too-narrow screen only the highest-priority column collapses.

If no column is collapsible (all `0`) but the table is still wider than its container,
the responsive wrapper falls back to **horizontal scrolling**.

### Behaviour notes

- The recalculation runs on a `ResizeObserver` watching the grid container, so it reacts
  to window resizes and layout changes, not just the initial load.
- Collapsing uses a CSS class (`gv-resp-collapsed`), independent of the inline
  `display` style used by [column visibility](javascript.md#gridview-visibility): the two never fight.
  A column the user has manually hidden is skipped — it is neither collapsed nor shown in
  the detail row.
- The detail row reads its label/value pairs straight from the hidden `<th>`/`<td>`
  cells, so column types, formatters and HTML render exactly as in the table.
- Open detail rows close on recalculation (e.g. when the window is resized).

The markup is driven by the [`gridview-responsive`](javascript.md#gridview-responsive) Stimulus
controller and styled through the `--gv-*` tokens, so it follows light/dark mode and any
[token overrides](theming.md#overriding-tokens) automatically.
