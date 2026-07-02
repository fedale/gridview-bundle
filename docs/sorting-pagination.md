# Sorting & Pagination

## Sorting

Sorting is declared in the `sort` key of the data provider array. `sort` is a **grouped**
config holding three sub-keys: `map` (the attribute map), `default` (the initial ordering)
and `multiSort` (multi-attribute toggle). Each entry of `map` maps a **sort name** (used in the
URL query string) to the Doctrine ORDER BY fields.

```php
$dataProvider = [
    'model' => Customer::class,
    'sort'  => [
        'map' => [
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
    ],
];
```

A `DataColumn` whose `label` (or `attribute`) matches a key in the sort map automatically
renders its header as a clickable sort link. Clicking toggles `asc` ↔ `desc`. The current
direction is reflected in the `?sort=` query parameter.

### Default sort

When the request carries no (valid) `?sort=` parameter, the grid is unsorted by default. Set
an initial ordering with the `default` key **nested inside** `sort` (alongside `map`). It maps a
**sort name** (a key declared in `sort.map`) to a direction:

```php
$dataProvider = [
    'model' => Customer::class,
    'sort'  => [
        'map' => [
            'name'  => ['asc' => ['c.name'],  'desc' => ['c.name'],  'default' => 'asc'],
            'email' => ['asc' => ['c.email'], 'desc' => ['c.email'], 'default' => 'asc'],
        ],
        'default' => ['name' => 'asc'],    // ORDER BY c.name ASC on first visit
    ],
];
```

The default applies only until the user clicks a header: an explicit `?sort=` in the URL always
takes precedence. Note that `default` (inside a `sort.map` attribute entry) and `sort.default` are
different things — the attribute-level `default` is the direction used the *first* time you click
that column's header, while `sort.default` is the grid-level ordering applied when *nothing* has
been clicked yet.

### Multi-attribute sorting

By default the grid sorts by a **single** attribute: clicking a header replaces the current sort.
Set `sort.multiSort` to `true` to let the grid order by several attributes at once:

```php
$dataProvider = [
    'model' => Customer::class,
    'sort'  => [
        'map' => [
            'name'  => ['asc' => ['c.name'],  'desc' => ['c.name']],
            'email' => ['asc' => ['c.email'], 'desc' => ['c.email']],
        ],
        'multiSort' => true,
        'default'   => ['name' => 'asc', 'email' => 'desc'],
    ],
];
```

With multi-sort on, the `?sort=` query string carries a **comma-separated** list and a leading `-`
marks a descending attribute — e.g. `?sort=name,-email` means `ORDER BY c.name ASC, c.email DESC`.
With multi-sort off (the default) only the first attribute of such a list is applied, and clicking
a header **replaces** the current sort instead of adding to it.

`sort.multiSort` is independent of `sort.default`: a multi-column `sort.default` is always applied
in full, even when multi-sort is off — it only governs *interactive* sorting by the user.

---

## Pagination

Pagination attributes are passed inside the data provider:

```php
$dataProvider = [
    'model'      => Customer::class,
    'pagination' => [
        'defaultPageSize' => 25,
        'pageSizeOptions' => [25, 50, 100],   // optional footer page-size selector
    ],
];
```

`pageSizeOptions` is an optional array of ints sitting next to `defaultPageSize` and
`maxPageSize`. When set, the footer renders a page-size `<select>` offering exactly those
values; only the listed sizes are honoured (a request for any other size falls back to
`defaultPageSize`). Omit the key to hide the selector entirely.

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
rebuilding — see the [`gridview-page-jump`](javascript.md#gridview-page-jump) controller.
