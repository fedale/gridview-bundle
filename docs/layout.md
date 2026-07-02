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
> are now internals of the `table` renderer (templates under `sections/dataview/table/`).
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
| `{sortBar}` | block (`sections/sortBar.html.twig`) | Sort dropdown of the sortable columns; placeable anywhere. The sort affordance for card/list renderers (which have no column headers). `{sort}` is a back-compat alias |
| `{viewSwitcher}` | block (`sections/viewSwitcher.html.twig`) | Runtime renderer switch; collapses unless `options.renderers` lists more than one view (see [Switching views at runtime](#switching-views-at-runtime)) |
| `{thead}` | table-strategy internal (`sections/dataview/table/thead.html.twig`) | Column header row |
| `{filter}` | table-strategy internal (`sections/dataview/table/filter.html.twig`) | Column filter inputs row (header) |
| `{filterBar}` | `sections/filterBar.html.twig` | Filters of columns with `filterBar: true`; placeable anywhere, even outside the grid (see [The filterBar](filtering.md#the-filterbar--placing-filters-anywhere)) |
| `{tbody}` | table-strategy internal (`sections/dataview/table/tbody.html.twig`) | Data rows |
| `{tfoot}` | table-strategy internal (`sections/dataview/table/tfoot.html.twig`) | Table footer row |
| `{empty}` | table-strategy internal (`sections/dataview/table/empty.html.twig`) | "No records found" row |
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
template `sections/dataview/{renderer}.html.twig`. Three renderers ship built-in:

| Renderer | Markup | Internals |
|----------|--------|-----------|
| `table` (default) | `<table>` | `sections/dataview/table/{thead,filter,tbody,tfoot,empty,_rows}.html.twig` |
| `list` | `<ul class="gv-list">` of `<li>` items | `sections/dataview/list/{_item,empty}.html.twig` |
| `card` | responsive CSS-grid of `<article class="gv-card">` boxes | `sections/dataview/card/{_item,empty}.html.twig` |

```php
->setOptions(['renderer' => 'card'])   // 'table' (default) | 'list' | 'card'
```

An unknown renderer falls back to `table`. To add your own, drop a
`sections/dataview/{name}.html.twig` strategy (it receives `gridview`, `models`,
`columns`, `pagination`, `form`) or override `layout.templates.dataview`.

The `list` and `card` strategies iterate `gridview.indexColumns` and reuse the same
`column.render()` pipeline as the table (via the shared
`sections/dataview/_cell.html.twig` partial), placing each column by its
`getKind()`: `checkbox` → selection slot, `action` → actions slot, `data`/`serial`
→ label/value pair. Per-row attributes from the `RowSubscriber`/`Row` (even/odd,
custom classes) apply to the `<li>`/`<article>` just as they do to `<tr>`.

**CardView layout.** Cards flow in a CSS-grid `repeat(auto-fill, minmax(--gv-card-min, 1fr))`,
so the column count adapts to the container width (down to one column on mobile).
Tune it per grid with `options.card`:

```php
->setOptions([
    'renderer' => 'card',
    'card'     => [
        'min'        => '18rem',   // → --gv-card-min (min card width)
        'gap'        => '1rem',    // → --gv-card-gap
        'titleField' => 'name',    // column rendered as the card title (no label)
    ],
])
```

#### Custom item template (full control over the layout)

The built-in `card`/`list` item renders every column as a label/value pair — a
sensible default, but it can't express a specific arrangement (a title, a badge,
an image, a per-row background from a field, actions in a precise spot). For that,
point `options.card.template` (or `options.list.template`) at your own Twig
template for the item:

```php
->setOptions([
    'renderer' => 'card',
    'card'     => ['template' => 'gridview/category_card.html.twig'],
])
```

The item template receives a stable context: **`gridview`**, **`row`**,
**`rowIndex`**, **`columns`** (plus the grid's `models`, `pagination`, `form`).
Read raw record fields from `row.data.*` for free-form markup, and reuse the
shared `sections/dataview/_cell.html.twig` partial to render **any** column with
its full pipeline (formatter, `value` closure, inline edit) — it only needs
`column`, `row` and `rowIndex` in scope:

```twig
{# templates/gridview/category_card.html.twig #}
<article class="gv-card" style="--card-accent: {{ row.data.color|default('#ccc') }}">
    <header class="gv-card__header" style="background: {{ row.data.color }}; color: #fff;">
        <h3 class="gv-card__title">{{ row.data.name }}</h3>
        {# edit/show actions, top-right — rendered via the shared cell partial #}
        {% for column in gridview.indexColumns|filter(c => c.kind == 'action') %}
            {% include '@FedaleGridview/gridview/sections/dataview/_cell.html.twig' %}
        {% endfor %}
    </header>
    <div class="gv-card__body">
        {{ row.data.description }}
        <small>{{ row.data.postCount }} posts</small>
    </div>
</article>
```

`column.kind` (`data`/`checkbox`/`action`/`serial`) lets you pick columns by role;
`gv_header_label(column.label)` gives a column's label. A missing template path
raises a clear Twig error rather than failing silently. Omit `template` to keep
the default item. The empty state is overridable separately via
`layout.templates.empty`.

#### Switching views at runtime

Declare `renderers` (the allowed set) to let the **user** switch views with a
button. When it holds more than one entry the toolbar shows a `{viewSwitcher}`
(and, since list/card have no column headers, the header-less `{sortBar}` and
`{filterBar}` are added automatically — additively, so a custom `layout.toolbar`
is respected). The active view is stored in the `view` query param and preserved
across sort/filter/pagination; it defaults to `renderer`. Omit `renderers` (or
give a single entry) for a fixed single-view grid — the default, no switcher.

```php
->setOptions([
    'renderer'  => 'table',                    // initial view
    'renderers' => ['table', 'card', 'list'],  // views offered by the switcher
])
```

The layout stays a stable **superset**: switching only swaps the `{dataview}`
strategy; renderer-inappropriate controls (e.g. `{columnVisibility}` on card/list)
collapse to nothing on their own. See
[Token dynamics per renderer](#token-dynamics-per-renderer).

**Filters on list/card (`autoBar`).** Without column headers there is no per-column
filter row, so filters surface in the `{filterBar}`. By default (`filterControls.autoBar`
is `null`) the bar auto-includes **every filterable column** when the active
renderer is not `table`; on `table` it keeps the opt-in behaviour (only columns
with `filterBar: true`). Set `filterControls.autoBar` to `true`/`false` to force
it. A column excludes itself from the bar with `filterBar: false`, even under
autoBar.

**What does not carry to list/card.** `caption`, `showThead`/`showTfoot`, the
`responsive` column-collapse (+ column `priority`), `reorderColumns`, the header
filter funnel (`filterControls.inHeader`), the column-visibility toggle and
infinite scroll are table-only; they are silently ignored on the other renderers.

#### Token dynamics per renderer

Rather than swapping the whole layout tree per renderer, the layout is a single
stable superset and each token decides whether to render. A token that returns an
empty string is skipped by the engine (no stray wrapper), so a runtime view switch
only swaps `{dataview}` while the surrounding chrome stays put. `{viewSwitcher}`
collapses unless more than one renderer is allowed; `{sortBar}`/`{filterBar}`
collapse when there is nothing to show; `{columnVisibility}` collapses off `table`;
the `{thead}`/`{filter}`/`{tbody}`/`{tfoot}`/`{empty}` tokens are table-strategy
internals and never exist as top-level tokens on list/card.

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
