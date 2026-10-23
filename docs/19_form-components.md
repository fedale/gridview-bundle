# Form components

The bundle's form theme and stylesheet are not limited to the grid's CRUD forms. Any Symfony
form of the host application can use them, and get the same look as the grid: rows, labels,
controls, errors, required markers, and a few components forms often need: an on/off
**switch**, a choice drawn as **buttons side by side**, a **password strength meter** and
**show/hide eye**, and **repeatable rows** added and removed in the page.

Everything is styled through the `--gv-*` design tokens, so it follows the theme, the dark mode
and the framework presets of the grid ([Theming](06_theming.md)).

## Using them in a host form

1. **Render the form with the bundle's form theme**, for the whole application or one template:

   ```yaml
   # config/packages/twig.yaml
   twig:
       form_themes: ['@FedaleGridview/form/gv_form_theme.html.twig']
   ```

   ```twig
   {% form_theme form '@FedaleGridview/form/gv_form_theme.html.twig' %}
   ```

   Every row gets `gv-form-row`, every label `gv-form-label`, every text-like input, select and
   textarea `gv-form-control`, the help `gv-form-help` and the errors `gv-form-errors`.

2. **Put the form inside `[data-gv-scope]`.** The design tokens live on the grid
   (`[data-gridview]`) and on any element carrying `data-gv-scope`. Mark the page or the
   layout's main element once:

   ```twig
   <main data-gv-scope> … </main>
   ```

   Dark mode follows the same rules as the grid (OS preference, `data-bs-theme`,
   `data-gv-theme`). Inside the scope, `gv-btn`, `gv-btn-primary` and `gv-btn-danger` buttons
   work too.

3. **Load the stylesheet** (`gridview.scss`, see [Building the stylesheet](06_theming.md#building-the-stylesheet)):
   the components are in its `_forms.scss` partial.

## Expanded choices

An expanded `ChoiceType` (radio buttons, or checkboxes with `multiple`) gets the `gv-choices`
class from the theme: one option per line, the box before its label, no asterisk on the options
of a required group. Add `gv-segmented` for buttons side by side instead (below).

## Required fields

A required field's label carries `required` (Symfony adds it); the theme adds `gv-form-label`, and
the stylesheet draws a red asterisk after it. To explain the asterisk, put a note in the form:

```twig
<p class="gv-required-note"><span class="gv-required-note__mark">*</span> Required field</p>
```

It stays hidden until the form actually has a required field.

## Switch

A checkbox drawn as an on/off switch: give the widget the `gv-switch` class (and `role="switch"`
for screen readers). The theme then gives the row `gv-form-row--switch`: the label first, the
switch after it.

```php
$builder->add('active', CheckboxType::class, [
    'required' => false,
    'attr' => ['class' => 'gv-switch', 'role' => 'switch'],
]);
```

## Buttons side by side (segmented choice)

An expanded `ChoiceType` (radio buttons) with the `gv-segmented` class is drawn as a row of
buttons, the chosen one filled with the primary colour. Suits a few short options.

```php
$builder->add('gender', ChoiceType::class, [
    'choices' => ['Male' => 'm', 'Female' => 'f', 'Non-binary' => 'x'],
    'expanded' => true,
    'placeholder' => false,
    'attr' => ['class' => 'gv-segmented'],
]);
```

## Password: strength meter and show/hide eye

Two Stimulus controllers on a password input, alone or together:

| Controller | What it does | Values |
|---|---|---|
| `gridview-password-strength` | Four bars and a word under the input, updated while typing (pair it with `data-action="gridview-password-strength#update"`) | `min-length` (8), `labels` (`["Too short","Weak","Fair","Good","Strong"]`) |
| `gridview-password-reveal` | An eye inside the input switches it between hidden and clear text | `show-label` ("Show password"), `hide-label` ("Hide password") |

```php
$builder->add('password', PasswordType::class, ['attr' => [
    'data-controller' => 'gridview-password-strength gridview-password-reveal',
    'data-action' => 'gridview-password-strength#update',
    'data-gridview-password-strength-min-length-value' => 8,
]]);
```

The meter is a hint computed in the browser: below `min-length` it is level 0; every kind of
character beyond the first (lower case, upper case, digit, symbol) and every 4 characters beyond
`min-length` add a level, up to 4. Server-side constraints decide what is accepted. With a
`RepeatedType`, put the controllers on `first_options` only.

The labels are values, so translate them where the form is built
(`'data-gridview-password-strength-labels-value' => json_encode([...])`).

## Repeatable rows (collection)

The `gridview-collection` controller adds and removes the rows of a `CollectionType`
(`allow_add`, `allow_delete`, a `prototype`). It works with nested collections: each one has its
own controller, and the outer one ignores the rows of the inner ones.

```twig
<div class="gv-collection" data-controller="gridview-collection"
     data-gridview-collection-prototype-value="{{ include('_phone_row.html.twig', {row: form.phones.vars.prototype})|e('html_attr') }}"
     data-gridview-collection-prototype-name-value="__name__"
     data-gridview-collection-index-value="{{ form.phones|length }}"
     data-gridview-collection-min-value="1"
     data-gridview-collection-max-value="0">
    <div class="gv-collection__rows" data-gridview-collection-target="list">
        {% for row in form.phones %}{{ include('_phone_row.html.twig', {row: row}) }}{% endfor %}
    </div>
    <button type="button" class="gv-btn gv-collection__add" data-gridview-collection-target="add"
            data-action="gridview-collection#add">+ Phone</button>
</div>
```

```twig
{# _phone_row.html.twig #}
<fieldset class="gv-collection__row gv-collection__row--inline" data-gridview-collection-target="row">
    <div class="gv-collection__fields">{{ form_widget(row) }}</div>
    <button type="button" class="gv-btn gv-collection__remove" aria-label="Remove"
            data-action="gridview-collection#remove">×</button>
</fieldset>
```

| Value | Meaning |
|---|---|
| `prototype` | The HTML of a new row, its key replaced by `prototype-name` |
| `prototype-name` | The placeholder of the key (`prototype_name` of the `CollectionType`, `__name__` by default) |
| `index` | The first key no row uses; each new row takes the next one |
| `min` / `max` | Rows required / allowed (`0`: no limit). At the minimum the remove buttons hide; at the maximum "add" is disabled. Check both on the server too |

| Class | Element |
|---|---|
| `gv-collection` | The container |
| `gv-collection__rows` | The rows (target `list`) |
| `gv-collection__row` | One row (target `row`), a dashed frame |
| `gv-collection__row--inline` | A row whose fields sit side by side on one line; their labels are kept for screen readers only, so give the inputs placeholders |
| `gv-collection__header` | A row's heading line (a title and its remove button) |
| `gv-collection__fields` | The fields of an inline row |
| `gv-collection__remove` | The remove button of a row |
| `gv-collection__add` | The add button (target `add`), full width, dashed |
