# Filtering & Search

Column filters require a **SearchModel** — a class that extends
`Fedale\GridviewBundle\Service\SearchModel`.

## Enabling filters

```php
// In the controller
$gridview = $this->createGridviewBuilder()
    ->setSearchModel($this->customerSearchModel)  // inject via constructor or autowiring
    ->setDataProvider($dataProvider)
    ->setColumns($columns)
    ->renderGridview();
```

## Declaring filter inputs in columns

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

## Inheriting the filter type from the column

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

## Default filter values

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

- Defaults apply **only when the request carries no `fedaleForm` parameter at all** (first
  visit). A submitted GET form always sends every field — even empty ones — so a
  present-but-empty `fedaleForm` means *the user cleared the filter*, and the default is
  **not** reapplied.
- Sort and pagination links generated from a first visit carry no `fedaleForm` params, so
  defaults keep applying consistently while navigating.
- The default value also pre-fills the form input (via the form `data` option), so what
  the user sees always matches the applied query.
- For columns with dotted attributes (e.g. `t.name`) the default is keyed by the mangled
  param name (`t_name`), matching the submitted form field name.

## The filterBar — placing filters anywhere

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

### `headerMirror` — also show the filter in the column header

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

## Clearing a single column's filter — `filter.clear`

Each column decides **how** its active filter can be removed via the `clear` key of
its `filter` spec. → **[Tutorial: Filter clear affordances](tutorial-filter-clear-affordances.md)**

The available affordances (modes) are:

| Mode | Affordance | Where |
| --- | --- | --- |
| `header` | Funnel icon next to the column label (appears only when the filter is active) | Column header |
| `input` | An inline **✕** button inside the filter input | Filter row |
| `chip` | A removable chip (`Label: value` + **✕**) | The `{filterChips}` section, outside the table |
| `none` | No clear affordance | — |

All of them clear the column's filter and re-submit the grid; no custom JS is needed.

```php
// Shorthand — a single mode:
'filter' => ['type' => 'text', 'clear' => 'chip'],

// Several affordances at once (funnel icon AND an external chip):
'filter' => ['type' => 'text', 'clear' => ['header', 'chip']],

// Extended form with custom icons (raw SVG/HTML):
'filter' => ['type' => 'text', 'clear' => [
    'mode'     => ['header', 'chip'],
    'icon'     => '<svg …>…</svg>',   // header/input clear glyph
    'chipIcon' => '<svg …>…</svg>',   // chip close glyph
]],
```

**Default** (when `clear` is omitted): `['header']`, plus `input` when the grid-level
`filterControls.inlineClear` is `true`. An explicit `clear` always wins and never has
`input` appended implicitly.

**Chips** are rendered by the `{filterChips}` layout section, which must be placed in
the layout (it is opt-in — not part of the default layout). It renders one chip per
column that opted into the `chip` mode **and** currently has an applied filter:

```php
'options' => [
    // Give the chips their own row under the toolbar:
    'layout' => ['header' => '{heading} {toolbar} {filterChips}'],
],
```

### Grid-level default clear mode

All columns inherit a **default** clear mode when they don't specify `filter.clear`
explicitly. Control it via `filterControls.clear`:

```php
'options' => [
    'filterControls' => [
        'clear' => ['chip'],  // all columns show only chip affordance by default
    ],
    'layout' => ['header' => '{heading} {toolbar} {filterChips}'],
],
```

Resolution order for each column:
1. **Explicit** `filter.clear` always wins (if the column specifies it).
2. **Grid-level** `filterControls.clear` (if set).
3. **Fallback**: `['header']` + `'input'` iff `filterControls.inlineClear` is `true`.

This makes it easy to pick a global style (e.g. chips for the whole grid) and only
override individual columns when needed. Use `'clear' => 'none'` on any column to
disable all clear affordances for that column specifically.

## Filter types reference

### `text`

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

### `boolean`

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

### `choice`

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

### `relation`

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
| `controls_threshold` | `int` | *(grid `filterControls.choiceControlsThreshold`, 20)* | Hide the search box and the select/deselect/invert toolbar when the column has fewer than this many options. Per-column override of the grid-level default. AJAX lists always keep their controls. |

The grid-level default lives in `options.filterControls.choiceControlsThreshold` (default `20`),
alongside the other filter-UI knobs:

```php
// Raise the threshold for the whole grid (controls appear only from 30 options up)
->setOptions(['filterControls' => ['choiceControlsThreshold' => 30]])

// …or per column, overriding the grid default
'filter' => ['type' => 'relation', 'options' => [
    'choices' => $typeChoices, 'multiple' => true, 'controls_threshold' => 5,
]]
```

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

### `number`

Two text inputs rendered side by side as a from/to range.
Submits as `fedaleForm[field][from]` and `fedaleForm[field][to]`.

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

### `date`

A **Flatpickr** calendar popup that replaces the two native `<input type="date">` fields.
Supports both single-date and date-range selection. Always submits ISO `YYYY-MM-DD` values
as `fedaleForm[field][from]` and `fedaleForm[field][to]`.

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

## Applying filters in the repository — `applyFilters()`

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

## Hiding rows by permission (filter in the query)

To make **entire rows** visible only to some users (authorization, multi-tenancy,
soft delete, …), apply the rule as a **query condition** in the repository
`search()` (or in `prepareModels()`), right next to the other filters. Doing it
at the query level keeps pagination and the total-row count coherent: the
database returns only the visible rows, so page sizes stay full, the totals are
correct and no "phantom" pages appear.

```php
use Symfony\Bundle\SecurityBundle\Security;

class CustomerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry, private Security $security)
    {
        parent::__construct($registry, Customer::class);
    }

    public function search(array $params = [])
    {
        $qb = $this->createQueryBuilder('c')
            ->join('c.company', 'company');

        $this->searchForm->applyFilters($qb, $params, [
            'name'  => ['text', 'c.name'],
            'email' => ['text', 'c.email'],
        ]);

        // Non-commercial users never see Acme's rows.
        if (!$this->security->isGranted('ROLE_COMMERCIAL')) {
            $qb->andWhere('company.name != :hidden')
               ->setParameter('hidden', 'acme');
        }

        return $qb;
    }
}
```

Inject Symfony's `Security` (`Symfony\Bundle\SecurityBundle\Security`) into the
repository, or build the pre-filtered `QueryBuilder` in the controller — where
`isGranted()` is already available — and hand it to the data provider.

> **Why not do this in a row event?** A `RowSubscriber` runs *after* the paginator
> has already sliced the current page, and the total count is computed at the DB
> level — so dropping rows there would leave short or empty pages and a wrong
> total. Row events are for **styling and value overrides**; query conditions are
> for **visibility**. See [Listening to row events](extending.md#listening-to-row-events).

### Telling the user the list is restricted

Because the hidden rows never reach the grid, the user sees a shorter list with
no hint that anything is missing. Set the `restriction` grid option to render a
notice banner at the top of the grid whenever the query is filtered by the user's
permissions:

```php
// In the controller — viewConfig()/options, where isGranted() is available:
protected function viewConfig(): array
{
    return [
        'options' => [
            'restriction' => !$this->isGranted('ROLE_COMMERCIAL'),
        ],
    ];
}
```

`restriction: true` shows a **default, translated** message
(`grid.restriction`, localized in it/en). Pass a **string** to override it with
your own text:

```php
'options' => ['restriction' => 'You are only seeing your own customers.'],
```

A falsy value (the default) renders nothing — no banner, no wrapper markup. The
banner is emitted by the `{restrictionNotice}` layout token, added to the default
`shell` layout, so it appears automatically once the flag is set. Move it
elsewhere (or drop it) by editing the layout:

```php
// Render it inside the header instead of at the very top:
'options' => ['layout' => ['header' => '{restrictionNotice} {heading} {toolbar}']],
```

> Keep the flag in sync with the query condition: it's a **presentational** hint,
> not the enforcement — the actual filtering must still live in the query (above).

## Global search

Global search adds a single text input that queries multiple fields at once.
Declare the DQL fields to search and add the `{globalSearch}` token to the toolbar layout:

```php
->setOptions([
    'globalSearch' => ['c.name', 'c.email', 'c.code'],
    'layout' => [
        'toolbar' => '{globalSearch}',
    ],
])
```

The search field auto-submits with a 300 ms debounce via the `gridview-filter` Stimulus
controller. Matched text is highlighted in the rendered rows with a `<mark>` element.

When `useTurbo: false`, the auto-submit is disabled and a **Filter** button (`{filterSubmit}`)
appears in the toolbar so the user can submit the form manually.

> 💡 **Want a fully custom filter UI?** The filterBar and header filters are just one UI
> over a query layer that's decoupled from presentation. See the step-by-step tutorial
> **[Filter clear affordances](tutorial-filter-clear-affordances.md)** — choose how to remove
> filters (funnel icon, inline ✕, external chip, or custom icons). Grid-level defaults and
> per-column overrides.

> **[A custom, EasyAdmin-style filter modal](tutorial-custom-filter-modal.md)** — a
> Filter button + modal with comparison operators and a reset, built entirely in the
> client app with no bundle changes.
