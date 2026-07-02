# CRUD Forms & Controllers

## CRUD forms (generated from columns)

The grid can generate **add / edit / clone / delete** forms directly from the columns'
configuration — no hand-written `FormType`. The form is built from a per-column `control` spec
(the write-side twin of `filter`), bound to the data provider's entity (`models`), persisted by a
bundle service, and shown in a self-contained modal (no Bootstrap) that refreshes the grid via Turbo Stream.

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

Control types map to Symfony FormTypes via `ControlTypeRegistry`. Each entry is a thin alias, so
the control inherits that FormType's rendering **and** its data transformer for free — the submitted
value round-trips back into the entity as the right PHP type (a `\DateTime`, an enum case, a managed
entity, a normalised URL…) with no custom code.

| Control type | Symfony FormType | Notes |
|---|---|---|
| `text` | `TextType` | |
| `html` | `TextareaType` | |
| `email` | `EmailType` | `<input type="email">` + validation |
| `url` | `UrlType` | normalises the URL on submit |
| `password` | `PasswordType` | |
| `color` | `ColorType` | native colour picker |
| `number` | `NumberType` | |
| `integer` | `IntegerType` | |
| `money` | `MoneyType` | an **amount**; pass `options.currency` (defaults to `EUR`) |
| `percent` | `PercentType` | handles the ×100 transform |
| `range` | `RangeType` | slider |
| `date` | `DateType` | |
| `datetime` | `DateTimeType` | binds a `\DateTime` with time |
| `time` | `TimeType` | |
| `boolean` | `CheckboxType` | |
| `choice` | `ChoiceType` | inline values via `options.choices` |
| `enum` | `EnumType` | **requires** `options.class` (the PHP enum FQCN); binds the enum case |
| `relation` | `EntityType` | **requires** `options.class`; binds managed entities |
| `country` / `language` / `locale` / `timezone` | `CountryType` / `LanguageType` / `LocaleType` / `TimezoneType` | localised ISO lists, bind the code |
| `currency` | `CurrencyType` | the ISO currency-**code** picker (≠ `money`) |
| `hidden` | `HiddenType` | |
| `media` | `FileType` | file upload (unmapped, see below) |

```php
$columns = [
    ['attribute' => 'email',    'type' => 'email'],   // display + control both EmailType (inherited)
    ['attribute' => 'website',  'control' => 'url'],
    ['attribute' => 'price',    'type' => 'currency',  // display formats the amount…
     'control' => ['type' => 'money', 'options' => ['currency' => 'EUR']]], // …write side is MoneyType
    ['attribute' => 'priority', 'control' => ['type' => 'enum', 'options' => ['class' => Priority::class]]],
];
```

When a column declares a `control` *without* an explicit `type`, it inherits the column's display data
type if that name doubles as a control type (`text`, `number`, `date`, `boolean`, `relation`, `choice`,
`email`, `url`, `percent`, `datetime`) — otherwise it falls back to `text`.

> **`money` ≠ `currency`.** `money` (MoneyType) edits an **amount**; `currency` (CurrencyType) is a
> picker of ISO currency **codes** (EUR/USD/…). Kept as separate entries on purpose — mirroring
> Symfony's own naming — so the display type `currency` (a formatted amount) does **not** auto-inherit
> a `currency` control; pair it with an explicit `money` control instead.

> **Filter ≠ control.** A `relation` *filter* uses scalar ids (ChoiceType); a `relation` *control*
> uses `EntityType` and binds managed entities. They are separate registry entries on purpose.
> A column's `value` closure is display-only and never used to populate the form.

> **`media` control = file upload.** It is *unmapped*: the bundle receives the upload, your
> app stores it and populates the entity through an `upload` callable in the control spec.
> See [The `media` type — file uploads](columns.md#the-media-type--file-uploads).

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
    'setter' => function (User $u, iterable $roles) {
        // Snapshot BEFORE clear(): with a multiple EntityType the $roles passed
        // here is the *same* Collection instance returned by the getter (Doctrine's
        // MergeDoctrineCollectionListener mutates it in place), so clearing the
        // entity's collection would also empty $roles and the loop would add nothing.
        $new = $roles instanceof \Doctrine\Common\Collections\Collection
            ? $roles->toArray() : iterator_to_array($roles);
        $u->getRoleEntities()->clear();
        foreach ($new as $r) { $u->addRole($r); }
    },
]]],
```

> ⚠️ **Footgun.** Never iterate `$roles` after clearing the entity's own collection
> without snapshotting first — for a `multiple` relation they are the same object.
> The symptom is subtle: the edit appears to work but the relation is saved empty.

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
    'layout' => ['shell' => '{toolbar} {header} {dataview} {footer}', 'toolbar' => '{addButton}'],
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
'layout' => ['shell' => '{header} {bulkBar} {dataview} {footer}'],
```

> Insert `{bulkBar}` into the **existing** shell tree — do not add `{toolbar}` alongside `{header}`.
> The default `header` region already expands to `{heading} {toolbar}`, so listing both renders the
> toolbar (and its global-search field) twice, which throws *"Field `_q` has already been rendered"*.
> If the grid drops the header, just add the bar on its own: `'{bulkBar} {dataview} {footer}'`.

**Choosing which bulk buttons show** — by default both built-ins (`update`, `delete`) render when
their auto-derived URL exists. To restrict the set, or add your own action, use the `bulkActions`
map under `crud` (a `viewConfig().options.crud` here is deep-merged over the auto-derived URLs, so
you set only this key — the URLs/title are preserved):

```php
'crud' => [
    'bulkActions' => [
        'delete' => true,                  // built-in: url + label + variant auto
        // 'update' omitted → not rendered  (keeps only Delete)
        'archive' => [                      // custom action
            'url'     => $this->generateUrl('gridview_user_bulk_archive'),
            'label'   => 'bulk.archive',    // GridviewBundle translation key
            'variant' => 'danger',          // '' (base) | 'primary' | 'danger'
        ],
    ],
],
```

Buttons render in map order. The JS (`gridview-selection#bulk`) is generic — it appends the ids
(or `all=1` + filters) to the action's `url` and opens the CRUD modal — so a **custom** action only
needs its own server endpoint returning a modal partial (a confirm like `_bulk_delete` or a form
like `_batch`) and processing the ids, exactly like the built-in `bulkDelete`/`bulkUpdate` below.

> **EA-style selection mode (client-only).** The selection controller drops the `hidden` attribute
> from `.gv-bulk-bar` whenever ≥1 row is selected, so you can collapse the page chrome (title,
> filters, add button) into a bare "N selected + actions" toolbar with CSS alone — no JS. Scope a
> `:has()` rule to a common ancestor of the header and the grid:
> ```css
> .content:has(.gv-bulk-bar:not([hidden])) .content-header { display: none; }
> ```
> Elements *outside* the `[data-gridview]` element (e.g. an add button in the page header) need such
> a shared-ancestor rule; `:has()` scoped to `[data-gridview]` alone can't reach them.

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
  [DetailView](detail-view.md#detailview-single-record).

> **Extending `AbstractCrudGridController` is necessary but not sufficient.** It
> registers the CRUD *routes/actions*, but the modal opens from a button you still
> have to emit. To make a grid editable you need **three** things:
> 1. extend `AbstractCrudGridController` (not `AbstractGridController`);
> 2. add `buttons` + `layout` (e.g. `{edit} {delete}`) to the `action` column — a bare
>    `['type' => 'action']` renders an empty cell with nothing to click;
> 3. add a `control` to every column you want to edit in the form.
>
> No client-side work is required: the Stimulus controllers ship with the bundle and
> are registered once in `assets/bootstrap.js`. See the [Full-CRUD example](#full-crud-example).

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
| `dataConfig(): array` | yes | `model` / `pagination` / `sort` |
| `viewConfig(): array` | no | Scalar config overrides (see below) |
| `beforeSave(FormInterface, string $mode): void` | no (CRUD) | Hook before persist (e.g. password hashing) |
| `onClone(object $clone): void` | no (CRUD) | Extra mutation of a clone (unique fields are cleared automatically) |

### The `viewConfig()` array

`viewConfig()` returns only the keys you want to change; they are merged over the
defaults. The live-uniqueness whitelist (`exists`) and the clear-on-clone fields
are **derived automatically** from the columns flagged `control.unique` — no extra
config needed.

| Key | Default | Applies to | Description |
|-----|---------|------------|-------------|
| `id` | entity short name (`User`→`user`) | both | Grid id + YAML config lookup |
| `indexTemplate` | `gridview/with_sidebar.html.twig` | both | Template rendered by `index` |
| `exportFilename` | `null` → falls back to `id` | both | Export file name (no extension) |
| `exportFormats` | `null` → all registered | both | Allow-list di key exporter (es. `['csv','pdf']`); fissa anche l'ordine del menu |
| `attributes` | `['class' => 'table']` | both | Table-level HTML attributes |
| `options` | `[]` | both | Extra builder options (layout, `reorderColumns`, …) |
| `title` | `''` | CRUD | Modal / page title |
| `mode` | `'modal'` | CRUD | `'modal'` \| `'page'` \| `'custom'` |
| `formView` | `null` | CRUD | Custom form layout (null = auto) |
| `pageTemplate` | `null` | CRUD | Full-page wrapper for page/custom mode |
| `addLabel` | `'New'` | CRUD | Label of the add toolbar button |
| `filterFormName` | `'fedaleForm'` | CRUD | Query key of the filter form (for "all" bulk ids) |
| `actionLayout` | `null` → `'{view} {edit} {delete}'` | CRUD | Token layout auto-wired into a bare `action` column (see [Default action buttons](columns.md#default-action-buttons-auto-wired)) |

### Read-only example

```php
#[Route('/gridview/customer', name: 'gridview_customer_')]
class CustomerController extends AbstractGridController
{
    protected function getDataClass(): string { return Customer::class; }

    protected function dataConfig(): array
    {
        return [
            'model' => Customer::class,
            'pagination' => ['defaultPageSize' => 20],
            'sort' => ['map' => ['id' => ['asc' => ['c.id'], 'desc' => ['c.id'], 'default' => 'desc']]],
        ];
    }

    protected function buildColumns(): array
    {
        return ['id', ['attribute' => 'code', 'label' => 'Code', 'filter' => ['type' => 'text']]];
    }
}
```

`id` defaults to `customer`, so no `viewConfig()` is needed; `index` and `export`
come for free.

### Full-CRUD example

```php
#[Route('/gridview/user', name: 'gridview_user_')]
class UserController extends AbstractCrudGridController
{
    public function __construct(private UserPasswordHasherInterface $passwordHasher) {}

    protected function getDataClass(): string { return User::class; }

    protected function viewConfig(): array
    {
        return [
            'title'    => 'User',
            'addLabel' => 'New user',
            'formView' => 'gridview/user/_form.html.twig',
            'options'  => ['layout' => ['toolbar' => '{addButton} {savedSearch} {export}']],
        ];
    }

    protected function dataConfig(): array { /* model / pagination / sort */ }

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
