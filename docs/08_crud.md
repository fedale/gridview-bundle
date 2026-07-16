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
| `tel` | `TelType` | `<input type="tel">` |
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
    ['attribute' => 'price',    'type' => 'money',     // display formats the amount…
     'control' => true],                               // …auto-inherits the money control (MoneyType)
    ['attribute' => 'currency', 'type' => 'currency',   // display shows "Euro (EUR)"…
     'control' => true],                               // …auto-inherits the currency control (code picker)
    ['attribute' => 'priority', 'control' => ['type' => 'enum', 'options' => ['class' => Priority::class]]],
];
```

When a column declares a `control` *without* an explicit `type`, it inherits the column's display data
type if that name doubles as a control type (`text`, `number`, `date`, `boolean`, `relation`, `choice`,
`email`, `url`, `tel`, `percent`, `datetime`, `time`, `money`, `currency`, `color`, `country`, `language`,
`locale`, `timezone`, `hidden`) — otherwise it falls back to `text`.

> **`money` ≠ `currency`.** `money` (MoneyType) edits an **amount**; `currency` (CurrencyType) is a
> picker of ISO currency **codes** (EUR/USD/…). Kept as separate entries on purpose — mirroring
> Symfony's own naming (and EasyAdmin's `MoneyField`/`CurrencyField` split). Each has its own display
> type of the same name, so both auto-inherit correctly: `type: 'money'` → `money` control, `type:
> 'currency'` → `currency` control. Use an explicit `control` to mix them (e.g. a `money` display paired
> with a `currency` control that edits a sibling ISO-code property).

> **Filter ≠ control.** A `relation` *filter* uses scalar ids (ChoiceType); a `relation` *control*
> uses `EntityType` and binds managed entities. They are separate registry entries on purpose.
> A column's `value` closure is display-only and never used to populate the form.

> **`media` control = file upload.** It is *unmapped*: the bundle receives the upload, your
> app stores it and populates the entity through an `upload` callable in the control spec.
> See [The `media` type — file uploads](02_columns.md#the-media-type--file-uploads).

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

Required fields are marked with a red asterisk after the label — Symfony's form layout already adds
a `required` class to the `<label>`; the bundle styles it via `.gv-crud-form label.required::after`.

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

### Scaffolding a controller with `make:gridview:crud`

Generate a full CRUD grid controller for a Doctrine entity straight from its
metadata:

```bash
$ php bin/console make:gridview:crud Post
```

The wizard derives the columns, filters, controls and sort map from the entity
and writes `App\Controller\Gridview\PostController`. Common options:

| Option | Purpose |
|--------|---------|
| `--controller-class` | Controller class name (default `<Entity>Controller`) |
| `--route-prefix` | Route path prefix (default `/gridview/<plural>`) |
| `--fields` | Comma-separated fields to expose as columns |
| `--sort` | Default sort field (prefix `-` for descending) |
| `--page-size` | Default page size (default `20`) |
| `--checkbox` | Add a row-selection checkbox column |
| `--fluent` | Scaffold `buildColumns()` with the fluent builders (`MoneyColumn::new(...)`) instead of array specs |
| `--advanced` | Ask the extra per-column / sort / page-size questions |

With `--fluent` the generated `buildColumns()` uses the typed column builders and
the controller imports the `Fedale\GridviewBundle\Column\Config\*` classes it
needs. Without it, the maker emits the array-spec form. Both produce the same
grid — see [Columns](02_columns.md) for the two styles.

To keep the scaffold clean, the maker **refuses to overwrite** an existing
controller — pick a different name, or use the update mode below.

#### Updating an existing controller: `--set-footer`

Re-run the maker on a controller you already generated to set its **layout
footer region** — the `{footer}` area below the grid (results summary,
pagination, page-size selector):

```bash
$ php bin/console make:gridview:crud --controller-class=PostController --set-footer
```

Passing `--set-footer` switches the maker into update mode: instead of
scaffolding, it targets the existing controller and adds a `viewConfig()` method
setting `options.display.layout.footer`. Omit the value to use the bundle
default (`{resultsSummary} {pagination} {pageSize}`), or pass your own tokens:

```bash
$ php bin/console make:gridview:crud --controller-class=PostController --set-footer='{pagination}'
```

The edit is done in place with the same AST tooling Symfony's own makers use, so
the rest of the file is untouched. One safety limit: automatic editing only
applies when the controller doesn't already define an active `viewConfig()`. If
it does, the maker leaves your hand-written config alone and prints the
`'footer' => '...'` snippet to paste under `options.display.layout` yourself.

### Wiring the routes (host app owns them)

> **Shortcut:** most apps don't need to write these actions by hand — extend
> `AbstractCrudGridController` (see [Controller base classes](#controller-base-classes))
> and the routes/actions below are inherited. The manual wiring here is the
> low-level reference, useful when you need a fully custom action set.

#### Routing convention

Routes are **app-owned**: you put a single class-level `#[Route]` on your
controller, and every CRUD route is derived from it by convention. The full
route name for an action is the class-level name prefix + the action's suffix,
resolved by `routeName()`. The canonical list of suffixes lives in one place —
the `Fedale\GridviewBundle\Routing\GridAction` enum.

Given `#[Route('/gridview/users', name: 'gridview_user_')]` on your controller:

| Action | Route name suffix | Default path | Method | Provided by |
| --- | --- | --- | --- | --- |
| index | `index` | `/gridview/users` | GET | `AbstractGridController` |
| export | `export` | `/gridview/users/export` | GET | `AbstractGridController` |
| create | `create` | `/gridview/users/new` | GET, POST | `AbstractCrudGridController` |
| update | `update` | `/gridview/users/update/{id}` | GET, POST | `AbstractCrudGridController` |
| clone | `clone` | `/gridview/users/clone/{id}` | GET, POST | `AbstractCrudGridController` |
| delete | `delete` | `/gridview/users/{id}/delete` | GET, POST | `AbstractCrudGridController` |
| bulk delete | `bulk_delete` | `/gridview/users/bulk/delete` | GET, POST | `AbstractCrudGridController` |
| bulk update | `bulk_update` | `/gridview/users/bulk/update` | GET, POST | `AbstractCrudGridController` |
| inline | `inline` | `/gridview/users/inline/{id}/{field}` | GET, POST | `AbstractCrudGridController` |
| exists | `exists` | `/gridview/users/exists` | GET | `AbstractCrudGridController` |
| show | `show` | `/gridview/users/{id}` | GET | `AbstractDetailController` |

Two things to know:

- The action `#[Route]` attributes live on the base controllers and are
  **inherited**, so the full set materialises from Symfony's normal
  attribute-routing under your one class-level prefix — no route loader, no
  route files.
- **`show` is one name everywhere** — the `{show}` action-column token, the
  `show` button, and the `show` route/operation (Sylius/REST convention:
  `index / show / new / create / edit / update`). The `{show}` button auto-wires
  to `routeName('show')` when that route exists (guarded by `routeExists()`).
  The route itself is **not** on the CRUD controller — it ships on
  `AbstractDetailController` (see [DetailView](09_detail-view.md)), a separate
  controller because a detail page shares only the columns, not the list
  machinery. Give the detail controller the **same name prefix** as the grid
  (`gridview_user_`) and the `{show}` button lights up with zero extra code. To
  point it elsewhere — e.g. an external detail route — override the `show`
  button instead:

  ```php
  'show' => fn($row) => CrudButton::show(
      $this->generateUrl('admin_user_detail', ['id' => $row['id']])
  ),
  ```

`make:gridview:crud` scaffolds the class-level `#[Route]` with a **pluralized path**
(`/gridview/users`) and a **singular name prefix** (`gridview_user_`) — the same
split Sylius uses (`/admin/suppliers` ↔ `app_admin_supplier_index`). Unlike
Sylius, which registers routes from resource metadata via a route loader served
by one shared generic controller, gridview keeps the routes on your own
controller subclass so per-action overrides and security stay trivially yours.

The bundle ships the services; the app provides thin actions that delegate to
`GridCrudHandlerInterface`. `AbstractCrudGridController` builds the grid once (shared
by index + form + delete) and **auto-wires** `integration.routeName` to the list route
(so sort/pagination/filter links stay pinned even while a CRUD POST re-renders the grid),
along with the CRUD URLs and the export menu — a subclass sets none of these. You
typically only customise the layout, through `viewConfig()`:

```php
protected function viewConfig(): array
{
    return [
        'options' => [
            'display' => [
                'layout' => [
                    'shell'   => '{toolbar} {header} {dataview} {footer}',
                    'toolbar' => '{addButton}',
                ],
            ],
        ],
    ];
}
```

Use semantic routes — `new` / `update/{id}` / `clone/{id}` — each delegating to one private handler
with an explicit mode (cleaner URLs; `/gridview/user/update/2` opens the edit form directly):

```php
#[Route('/new', name: 'create', methods: ['GET','POST'])]
public function create(Request $r): Response { return $this->handleForm($r, 'add', null); }

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

The action buttons and the `{addButton}` token open the modal (or navigate, per `form.mode`). Use the
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

`form.mode` (set in the controller's `viewConfig()`, or via the YAML `behavior.crudMode`
default — see [Configuration](11_configuration.md#behavior)) chooses how the form is presented:

| Mode | Buttons | Form endpoint | Submit |
|------|---------|---------------|--------|
| `modal` (default) | open the dialog (real `href` as no-JS fallback) | XHR → partial | Turbo Stream |
| `page` | plain links to the form page | direct → full page (`@FedaleGridview/crud/page.html.twig`, extends `pageBase`) | redirect |
| `custom` | plain links | direct → **your** template (`template.page`, or YAML `display.crudTemplate`) which prints `formHtml` | redirect |

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

### Form theme (`form.theme`)

By default every CRUD form renders through the bundle's own form theme
(`@FedaleGridview/form/gv_form_theme.html.twig`) — it just adds `gv-*` class hooks (`gv-form-row`,
`gv-form-label`, `gv-form-control`, `gv-form-help`, `gv-form-errors`) on top of Symfony's stock
`form_div_layout.html.twig` blocks, giving fields sane spacing/typography with no CSS-framework
dependency (consistent with the rest of the bundle — see [CSS theming](11_configuration.md)).

The theme is resolved most-specific-first: the controller's `viewConfig()` wins, then the YAML
`behavior.formTheme`, then the built-in gv theme. So a Bootstrap app can switch **every** CRUD form
to Symfony's Bootstrap 5 theme once, with no per-controller code:

```yaml
# config/packages/gridview.yaml
fedale_gridview:
    defaults:
        behavior:
            formTheme: 'bootstrap_5_layout.html.twig'   # string or a list of themes
```

Override it per grid from the controller's `viewConfig()` (this wins over the YAML default):

```php
protected function viewConfig(): array
{
    return [
        'form' => ['theme' => ['bootstrap_5_layout.html.twig']], // or your own theme(s)
    ];
}
```

Accepts anything Twig's `{% form_theme %}` tag does — a single template path or an array of paths
(the last one wins per block, same as Symfony's own theme stacking).

> **Already set a project-wide `twig: form_themes: [...]`?** By default gridview applies its own
> theme with an explicit per-form `{% form_theme %}` tag, which always wins for that form — so it
> silently overrides your project-wide choice for gridview's CRUD forms. To let a grid follow your
> global theme instead, set `form.theme` (or the YAML `behavior.formTheme`) to **`false`**: that is
> an explicit opt-out, so no per-form theme is applied and the form falls straight through to your
> app's own `twig.form_themes`. A `null` (or unset) value means "inherit the next level", **not**
> "opt out": `viewConfig()` null falls to the YAML default, and a null YAML value falls to the gv
> theme.

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

The tokens compose with the [form theme](#form-theme-formtheme): each `{ attribute }` is rendered
through the resolved theme, so with the Bootstrap 5 theme the widgets inside your layout pick up
`.form-control`/`.form-select` while your wrapper markup uses the framework's grid/cards. This is how
you reproduce an EasyAdmin-style two-column form with grouped fieldsets:

```twig
{# templates/gridview/post_form.html.twig #}
<div class="row g-4">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">Content</div>
            <div class="card-body">{ title }{ slug }{ content }{ summary }{ featuredImage }</div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">Meta</div>
            <div class="card-body">{ status }{ isFeatured }{ author }{ category }</div>
        </div>
    </div>
</div>
```

The view applies in every presentation mode (modal, page, custom).

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
(sessionStorage, with an all-records mode). The bulk delete/update URLs are **auto-wired** by
`AbstractCrudGridController`, so you only add the `{bulkBar}` layout token to get a bulk action bar
(count + buttons) that opens the CRUD modal with the selected ids:

```php
protected function viewConfig(): array
{
    return [
        'options' => [
            'display' => [
                'layout' => ['shell' => '{header} {bulkBar} {dataview} {footer}'],
            ],
        ],
    ];
}
```

> Insert `{bulkBar}` into the **existing** shell tree — do not add `{toolbar}` alongside `{header}`.
> The default `header` region already expands to `{heading} {toolbar}`, so listing both renders the
> toolbar (and its global-search field) twice, which throws *"Field `_q` has already been rendered"*.
> If the grid drops the header, just add the bar on its own: `'{bulkBar} {dataview} {footer}'`.

**Choosing which bulk buttons show** — by default both built-ins (`update`, `delete`) render when
their auto-derived URL exists. To restrict the set, or add your own action, use the `bulkActions`
map under `integration.crud` (`viewConfig().options.integration.crud` is deep-merged over the
auto-derived URLs, so you set only this key — the URLs/title are preserved):

```php
'options' => [
    'integration' => [
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

There's no route or config to add: `AbstractCrudGridController` already ships the
`/inline/{id}/{field}` endpoint and auto-wires `integration.crud.inlineUrl` to it.
One endpoint serves both GET (the editor) and POST (the save), and edits **only**
columns flagged `editable` — marking the columns (above) is all a subclass does.

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
  [DetailView](09_detail-view.md#detailview-single-record).

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
`gridview_user_index`, `gridview_user_create`, … automatically. The route loader only
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

### How your configuration reaches the grid

Whichever base you extend, the controller feeds your hooks into a `GridviewBuilder`.
Each hook maps one-to-one onto a builder method, so the two styles hold the **same**
payload:

| In a controller (hook) | Shape you return | Builder method it feeds | Builder shape |
|------------------------|------------------|-------------------------|---------------|
| `getDataClass()` / `viewConfig()['id']` | FQCN / id string | `setId()` | id string |
| `buildColumns()` | list of column specs | `setColumns()` | same list |
| `dataConfig()` | `['model', 'alias', 'pagination', 'search', 'sort', 'eager']` | `setDataProvider()` | same array |
| `viewConfig()['options']` | `['display' => …, 'behavior' => …, 'integration' => …]` | `setOptions()` | **identical** array |
| `viewConfig()['attributes']` | attributes bag | `setAttributes()` | same array |

The value of `viewConfig()`'s `options` key is exactly what `setOptions()` takes.
Moving a snippet between a controller and a raw builder ([Full Example](15_full-example.md#full-example))
only means adding or removing that outer `options` wrapper — the grouped
`display` / `behavior` / `integration` payload is the same on both sides.

### Eager-loading relations (`eager`)

Rows are normalized to arrays without ever triggering Doctrine lazy-loading: an
association that the list query didn't fetch is serialized as `null`, not loaded.
So when a column reads a relation (e.g. `author` or `category`), fetch-join it up
front — otherwise the column is empty, and relying on lazy-loading would issue one
query per row (a classic N+1).

The `eager` key lists the to-one associations to fetch-join into the list query:

    protected function dataConfig(): array
    {
        return [
            'model' => Post::class,
            'eager' => ['author', 'category'],
            // ...
        ];
    }

Each name is left-joined and added to the SELECT, so the relation is hydrated with
its parent row in the **same** query. Names are root-level associations of the grid
entity. This applies to the built-in query builder; a repository that provides its
own `search()` owns its joins instead (add the `leftJoin()`/`addSelect()` there).

### The `viewConfig()` array

`viewConfig()` returns only the keys you want to change; they are merged over the
defaults. The live-uniqueness whitelist (`exists`) and the clear-on-clone fields
are **derived automatically** from the columns flagged `control.unique` — no extra
config needed.

Keys are **nested**, and `config()` reads them by dotted path (`template.index`,
`labels.heading`, …) — a flat top-level key the code never reads (e.g.
`indexTemplate`) is silently ignored. A `viewConfig()` need only list the keys it
changes; associative sub-arrays are deep-merged, so setting one sub-key keeps the
rest of its group.

| Key | Default | Applies to | Description |
|-----|---------|------------|-------------|
| `id` | entity short name (`User`→`user`) | both | Grid id + YAML config lookup |
| `template.index` | `gridview/with_sidebar.html.twig` | both | Template rendered by `index` |
| `export.filename` | `null` → falls back to `id` | both | Export file name (no extension) |
| `export.formats` | `null` → all registered | both | Allow-list of exporter keys (e.g. `['csv', 'pdf']`); also fixes the menu order |
| `attributes` | `['class' => 'table']` | both | Table-level HTML attributes |
| `options` | `[]` | both | Builder options, grouped `display` / `behavior` / `integration` (see the mapping table above) |
| `labels.heading` | `null` → `{id}.label` | CRUD | Modal / page title (becomes `display.title`) |
| `labels.add` | `null` → `{id}.add` | CRUD | Label of the add toolbar button |
| `labels.edit` | `null` → `labels.heading` | CRUD | Edit form title |
| `form.mode` | `null` → `'modal'` | CRUD | `'modal'` \| `'page'` \| `'custom'` |
| `form.view` | `null` | CRUD | Custom form layout (null = auto) |
| `form.theme` | `null` → YAML `behavior.formTheme` → gv theme | CRUD | Form theme(s); `false` opts out to the app's global themes |
| `form.actions` | inline | CRUD | Form action buttons: `placement` / `layout` / `buttons` |
| `form.filterName` | `'fedaleForm'` | CRUD | Query key of the filter form (for "all" bulk ids) |
| `template.page` | `null` | CRUD | Full-page wrapper for page/custom mode |
| `options.actionLayout` | `null` → `'{show} {edit} {delete}'` | CRUD | Token layout auto-wired into a bare `action` column (see [Default action buttons](02_columns.md#default-action-buttons-auto-wired)) |

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
            'labels'  => ['heading' => 'User', 'add' => 'New user'],
            'form'    => ['view' => 'gridview/user/_form.html.twig'],
            'options' => ['display' => ['layout' => ['toolbar' => '{addButton} {savedSearch} {export}']]],
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
