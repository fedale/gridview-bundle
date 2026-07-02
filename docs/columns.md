# Columns

Each item in the `$columns` array can be a **string shorthand** or a **full array definition**.

## String shorthand

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

## Full array definition

```php
$columns = [
    [
        'attribute' => 'email',
        'label'     => 'E-Mail',
        'value'     => function (array $data, int $index, ColumnInterface $column): string {
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
| `value` | `Closure\|string\|null` | `null` | Full-cell override; closure receives `($data, int $index, $column)`. **Short-circuits the whole pipeline** — `type`, `valueGetter`, `formatter` and `renderer` are all ignored when set. Return a `Twig\Markup` for raw HTML, or pair a plain-string return with `twigFilter: 'raw'` |
| `valueGetter` | `Closure\|null` | `null` | Stage-1 raw-value extractor `($data, int $index, $column)`. Unlike `value` it keeps the rest of the pipeline (`formatter`, the type's `render`, escaping), so it is the way to feed a `type` (e.g. `type: 'html'`) |
| `formatter` | `Closure\|null` | `null` | Stage-2 display formatter `($rawValue, $data, $column)`; overrides the type's `format` step |
| `renderer` | `Closure\|null` | `null` | Stage-3 cell renderer `($displayValue, $data, $column)`; overrides the type's `render` step. Return a `Twig\Markup` for raw HTML |
| `format` | `array` | `[]` | Per-column options passed to the data type's pipeline stages (e.g. `['decimals' => 2]` for `number`/`currency`) |
| `twigFilter` | `string\|null` | `null` | Any Twig filter applied to the rendered value (e.g. `raw`, `upper`, `date('d/m/Y')`) |
| `active` | `bool\|Closure` | `true` | Whether the column is registered on the grid **at all**. An inactive (`false`) column is dropped before any wiring: no header, body cell, filter, export entry or CRUD form field — as if it were never declared. Use it for access control (deciding *who* may see a column). Contrast with `visible`, which keeps the column but hides it. A closure is evaluated once at build time |
| `visible` | `bool\|Closure` | `true` | Whether a (registered) column is shown; `false` columns are still rendered in the DOM and data — just hidden with CSS and toggleable via the UI. To remove a column entirely, use `active` instead |
| `filter` | `array\|bool\|null` | `null` | Column filter definition (requires a `SearchModel`). `true` enables a filter whose type is inherited from the column `type`; an array may set its own `type` to override it. The optional `clear` key chooses the clear affordance(s) — funnel icon, inline ✕ or external chip (see [Clearing a single column's filter](filtering.md#clearing-a-single-columns-filter--filterclear)) |
| `sortable` | `bool` | `true` | Whether clicking the header sorts the grid |
| `filterable` | `bool` | `true` | Whether the column shows a filter input |
| `filterBar` | `bool` | `false` | Render this column's filter in the `{filterBar}` section instead of inline in the header row |
| `headerMirror` | `bool` | `false` | Only with `filterBar: true` (text/number filters): also render a synced "mirror" input in the column header. Off by default → the filter lives **only** in the filterBar |
| `priority` | `int` | `0` | [Responsive](layout.md#responsive-column-collapse) collapse priority (needs the `responsive` grid option). `0` pins the column; a higher number collapses *first* on narrow screens |

### `active` vs `visible` — access control

`visible: false` still ships the column (DOM + data); it is only hidden with CSS
and can be re-enabled from the UI. `active: false` removes it entirely — it is
never registered, so it has no header, no cell, no filter, no export column and
no field in the generated CRUD form. That makes `active` the right switch for
**per-user / per-role column access**:

```php
protected function buildColumns(): array
{
    $canSeeSalary = $this->isGranted('ROLE_HR');

    return [
        ['attribute' => 'name'],
        [
            'attribute' => 'salary',
            'type'      => 'money',
            'active'    => $canSeeSalary,        // absent for everyone else
            'control'   => ['type' => 'money'],
        ],
        // a closure is also accepted (evaluated once at build time):
        ['attribute' => 'ssn', 'active' => fn() => $this->isGranted('ROLE_ADMIN')],
    ];
}
```

Because inactive columns never reach the CRUD form, a user who cannot see a
column also cannot edit its value through the grid's add/update form.

## Column types

The root `type` of a column has **two flavours**:

1. **Data types** — describe the *kind of data* the column holds. They render via
   `DataColumn` and, crucially, set the **default filter type** (see
   [Inheriting the filter type from the column](filtering.md#inheriting-the-filter-type-from-the-column)).
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
| `text` | Raw scalar / closure value, Twig-escaped (**default** when `type` is omitted) | `text` |
| `html` | Trusted HTML rendered raw (wrapped in `Twig\Markup`) — the explicit "raw" path, alternative to `twigFilter: 'raw'` | — (none) |
| `richText` | Same as `html` (alias `richtext`) | — (none) |
| `uuid` | Raw value | `text` |
| `json` | Raw value | `text` |
| `boolean` | `✓` / `✗` for truthy / falsy values | `boolean` |
| `number` | Raw value | `number` |
| `currency` | Number formatted as currency (see `format` options) | `number` |
| `percent` | Number formatted as a percentage | `number` |
| `rating` | Numeric rating | `number` |
| `date` | Raw value (format it with `twigFilter`) | `date` |
| `datetime` | Raw value | `date` |
| `link` | `<a>` link | `text` |
| `url` | `<a>` link to the value | `text` |
| `email` | `mailto:` link | `text` |
| `select` | Raw value mapped through choices (alias `choice`) | `choice` |
| `multiSelect` | Multiple selected choices | `choice` |
| `badge` | Value rendered as a styled badge | `text` |
| `list` | Array rendered as a list | `text` |
| `relation` | Raw value (use `value`/`valueGetter` to render the related label) | `relation` |
| `media` | Inline `<img>` for images, otherwise a download link (see [The `media` type](#the-media-type--file-uploads)) | — (none) |
| `data` | Raw value (legacy alias of `text`) | `text` |

**Structural types** (dedicated classes):

| Type | Class | Description |
|------|-------|-------------|
| `checkbox` | `CheckboxColumn` | Row selection with header toggle; not sortable or filterable |
| `serial` | `SerialColumn` | Auto-incrementing row index |
| `action` | `ActionColumn` | View / update / delete action links |

> A `value` closure always wins over the data type's built-in rendering — set
> `type: 'boolean'` for the ✓/✗ default, or supply your own `value` to override it.

## The `media` type — file uploads

The `media` type handles **binary files** (images, PDFs, SVGs, …). It has two sides:

- **Read (grid):** the column value is a **URL/path** to the file. When the value looks
  like an image it renders an inline `<img class="gv-img">`, otherwise an `<a class="gv-file" download>`
  link. The decision is by extension/mime in `display: 'auto'` mode and can be forced.
- **Write (create/update form):** a `media` **control** renders a file-upload button
  (a Symfony `FileType`). The control is **unmapped** — it is *not* tied to an entity
  property. The bundle handles *phase 1* (receiving and validating the upload); your
  app handles *phase 2* (storing the bytes and populating the entity) through an
  **`upload` callable** in the control spec. This keeps the bundle storage-agnostic:
  use the local filesystem, S3/Flysystem, or anything else.

```php
[
    'attribute' => 'filename',          // also the upload field name (unmapped)
    'label'     => 'File',
    'type'      => 'media',
    // Read side: build the public URL the grid links to / shows.
    'value'     => fn (array $d) => $d['filename'] ? '/uploads/'.$d['path'].'/'.$d['filename'] : null,
    // Write side: the upload button + your phase-2 storage callback.
    'control' => [
        'type'     => 'media',
        'required' => true,
        'modes'    => ['create'],       // optional: only show the upload on create
        'upload'   => function (UploadedFile $file, object $entity): void {
            // Read size/mime BEFORE move(): the temp file is gone afterwards.
            $size = (int) $file->getSize();
            $name = uniqid('', true).'-'.$file->getClientOriginalName();
            $file->move($projectDir.'/public/uploads/asset', $name);

            $entity->setPath('uploads/asset');
            $entity->setFilename($name);
            $entity->setSize($size);
            $entity->setType($file->getClientMimeType());
        },
    ],
]
```

The `upload` callable receives `(UploadedFile $file, object $entity, FormInterface $form)`
and runs on `POST_SUBMIT` only when a file was actually uploaded (so editing other fields
without re-uploading leaves the file untouched). Add a `File`/`Image` constraint via
`control.constraints` to validate size/mime in phase 1.

**Display options** (passed via the column's `format`):

| Option | Default | Description |
|--------|---------|-------------|
| `display` | `'auto'` | `'auto'` decides by extension/mime; `'image'` always `<img>`; `'download'` always a link |
| `mimeType` | `null` | Explicit mime used by `'auto'` when the URL has no telling extension |
| `imageExtensions` | `jpg,jpeg,png,gif,svg,webp,avif` | Extensions treated as images in `'auto'` mode |
| `alt`, `width`, `height`, `fallback` | — | Image rendering (`fallback` shown when the value is empty) |
| `downloadLabel` | basename of the URL | Link text for non-image files |

> The `media` type has **no filter** (`inferFilterType()` returns `null`). It supersedes
> the former `image` type.

## ActionColumn — token-based actions

`ActionColumn` renders per-row action buttons (view, edit, delete, or anything custom).
Which buttons appear is controlled by a **layout string** of `{token}` placeholders — the same
concept used for the grid layout system.

### Default behaviour

```php
['type' => 'action']
```

What it renders depends on the controller:

- **Under `AbstractCrudGridController`** the column is **auto-wired**: a bare
  `['type' => 'action']` (no explicit `buttons`) gets working `{view} {edit} {delete}`
  triggers pointing at the convention CRUD routes — no hand-written closures. Each
  token is only emitted if its route actually exists (`view`→`show`, `edit`→`update`,
  `clone`→`clone`, `delete`→`delete`), so missing routes render nothing instead of dead
  links. See [Default action buttons (auto-wired)](#default-action-buttons-auto-wired).
- **Under the read-only `AbstractGridController`** (or a standalone `ActionColumn` with
  no `buttons`) the cell renders **empty** — declare `buttons` to populate it.

> Out of the box `{view}` needs a `show` route with the same prefix (e.g. an
> `AbstractDetailController`); a plain `AbstractCrudGridController` has no such route, so
> `{view}` stays empty until you add one. Set `actionLayout => '{edit} {delete}'` if you
> don't use a detail view.

### Default action buttons (auto-wired)

A CRUD controller fills a bare `action` column's `buttons` for you, using the same
`CrudButton` helpers you'd write by hand (the auto-wiring is skipped entirely the moment
you declare your own `buttons`). The token layout comes from `actionLayout`:

```php
// viewConfig() — PHP override (wins over YAML)
protected function viewConfig(): array
{
    return ['actionLayout' => '{edit} {delete}'];   // drop {view} if there's no show route
}
```

```yaml
# config/packages/gridview.yaml — per-grid override
fedale_gridview:
    gridviews:
        customer:
            options:
                actionLayout: '{edit} {clone} {delete}'
```

Resolution order: `viewConfig()['actionLayout']` → YAML `gridviews.<id>.options.actionLayout`
→ built-in `'{view} {edit} {delete}'`. You can also keep the auto buttons but reorder/limit
them per column with a `layout` on the spec (`['type' => 'action', 'layout' => '{edit}']`) —
the spec `layout` wins over `actionLayout`.

### Controlling which buttons appear

Set `layout` to any combination of built-in or custom token names:

```php
['type' => 'action', 'layout' => '{view}']

['type' => 'action', 'layout' => '{edit} {delete}']

['type' => 'action', 'layout' => '{view} {archive} {delete}']
```

### Custom button content

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

### Role-based visibility

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

### Conditional visibility per row

Use the `visible` parameter (bool or closure) to show or hide a button based on row data:

```php
'edit' => new ActionButton(
    fn(array $row) => '<a href="/customers/' . $row['id'] . '/edit">Edit</a>',
    visible: fn(array $row, int $i) => $row['active'] === true,
),
```

### Array shorthand (no import needed)

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

### Adding a completely custom action

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

### Summary of `ActionButton` constructor

```php
new ActionButton(
    content: string|\Closure,   // HTML string or fn(mixed $row, int $index): string
    roles:   string[],          // Symfony roles required (empty = always shown)
    visible: bool|\Closure,     // fn(mixed $row, int $index): bool, or plain bool
)
```

### ActionColumn options reference

These keys are specific to `['type' => 'action']` and have no meaning for data columns
(`id`, `name`, `email`, …).

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `layout` | `string` | `'{view} {edit} {delete}'` | Token string controlling which buttons appear and in what order |
| `buttons` | `array` | built-in icon placeholders | Map of token name → `ActionButton`, callable, HTML string, or array spec |
| `label` | `string` | `'Actions'` | Column header text |

### YAML configuration

Column definitions — including `layout` and `buttons` — **cannot be set from YAML**.
The YAML config (`fedale_gridview` in `gridview.yaml`) only covers grid-level `options`
(layout tokens, globalSearch, useTurbo, …) and `attributes`.

Columns must always be declared in PHP because they routinely contain closures (for `value`,
`visible`, `buttons`), which YAML cannot represent.

---

## Registering custom column types

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

## Dot-notation for nested data

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
    'value'     => function (array $data, int $index, ColumnInterface $column): string {
        return $data['profile']['firstname'] . ' ' . $data['profile']['lastname'];
    },
],
```

## Returning arrays from `value`

When `value` returns an array, combine it with a compound `twigFilter`:

```php
[
    'attribute'  => 'locations',
    'label'      => 'Locations',
    'value'      => function (array $data, int $index, ColumnInterface $column): array {
        return array_map(
            fn($loc) => '<a href="/location/' . $loc['id'] . '">' . $loc['zipcode'] . '</a>',
            $data['locations']
        );
    },
    'twigFilter' => "join(', ', ' and ')|raw",
],
```

## Rendering raw HTML

Body cells are printed **without** an automatic `|raw`, so Twig escapes the
output by default. There are three ways to emit trusted HTML, all equivalent in
output — pick by where you want the safety to live:

```php
// 1. value closure returning Twig\Markup — marks the string as already-safe
'value' => fn (array $data, int $index, ColumnInterface $column): Markup =>
    new Markup('<a href="mailto:' . $data['email'] . '">' . $data['email'] . '</a>', 'UTF-8'),

// 2. value closure returning a plain string + twigFilter: 'raw'
'value'      => fn (array $data): string => '<a href="mailto:' . $data['email'] . '">' . $data['email'] . '</a>',
'twigFilter' => 'raw',

// 3. valueGetter (plain string) + type: 'html' — the html type wraps it in Markup for you
'type'        => 'html',
'valueGetter' => fn (array $data): string => '<a href="mailto:' . $data['email'] . '">' . $data['email'] . '</a>',
```

Use `valueGetter` (option 3) rather than `value` whenever you want the `type` to
handle escaping — `value` short-circuits before the type runs, so `type: 'html'`
has no effect when paired with `value`.

### Cell templates with `renderTemplate()`

The closure receives the column as its **last argument**, and the column exposes
`renderTemplate($name, $context)` which renders a Twig template with the grid's
environment — handy for non-trivial cell markup:

```php
'type'        => 'html',
'valueGetter' => fn (array $data, int $index, ColumnInterface $column): string =>
    $column->renderTemplate('gridview/_posts_popularity.html.twig', [
        'count'     => (int) ($data['postCount'] ?? 0),
        'published' => (int) ($data['publishedCount'] ?? 0),
    ]),
```
