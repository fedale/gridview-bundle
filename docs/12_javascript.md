# JavaScript Controllers

The bundle ships four Stimulus controllers located in
`FedaleGridviewBundle/assets/controllers/`.

Register them in your app's `controllers.json` (or import them in `bootstrap.js`).

## `gridview-filter`

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

## `gridview-selection`

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

## `gridview-saved-search`

Saves the current querystring (filters + sort) under a name and re-applies it (`Turbo.visit`).
Persisted via the pluggable preference provider, scoped per route.

**Connects to:** the `{savedSearch}` widget.

**Actions:** `#save` (save current), `#apply` (`query` param), `#remove` (`index` param).
**Target:** `list` (saved-searches list, filled by JS).

---

## `gridview-column-order`

Drag-and-drop column reorder (enabled by `reorderColumns => true`). Reorders `<th>`/`<td>` by
`data-col-key` and persists the order via the preference provider (`columnOrder`), re-applied on
connect. No template actions — it binds native drag events on the header.

**Session storage keys:**

| Key | Content |
|-----|---------|
| `gv-sel-{gridId}` | JSON array of selected row IDs |
| `gv-sel-{gridId}-all` | `"1"` when in all-mode |

---

## `gridview-responsive`

Collapses the least important columns into an expandable detail row when the table
overflows its container, and restores them when it fits again. Enabled by the
`responsive => true` grid option; columns opt in with a `priority > 0`. Pure client-side
(every cell is already in the DOM) — see [Responsive (column collapse)](05_layout.md#responsive-column-collapse)
for the full behaviour and `priority` semantics.

**Connects to:** the `.gv-resp-wrap` table wrapper and the per-row `.gv-resp-toggle`
buttons (`toggle` action). Recomputes on a `ResizeObserver`; uses the `gv-resp-collapsed`
class so it never collides with `gridview-visibility`.

---

## `gridview-visibility`

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
table[data-gv="{gridId}"] [data-col-key="{columnAttribute}"]
```

State is keyed by the column's stable `data-col-key` (its attribute, e.g. `name`, falling
back to `col{index}` for columns without one) rather than by positional index, so the saved
visibility survives column reordering and keeps its meaning across grids. Every cell
(`<th>`, `<td>`) rendered by the bundle carries both `data-col="{colIndex}"` (used by the
responsive controller) and `data-col-key="{columnAttribute}"`, and the `<table>` element
carries `data-gv="{gridId}"`, which is how the controller targets cells for a specific
column without touching other tables on the page.

**Columns that are not toggleable** (`CheckboxColumn`, `ActionColumn`) are excluded from the
dropdown automatically because their `isToggleable()` method returns `false`.

### Declaring a column hidden by default

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

## `gridview-export`

Appends the current column state to the export links, so the downloaded file has the
columns the grid is showing, in the order it shows them. On click it rewrites the
link's `href` with `cols=<key>,<key>,…`; `AbstractGridController::export()` reads it
back (see [The file matches the screen](10_export.md#the-file-matches-the-screen)).

**Connects to:** the `{export}` section template, unless `export.followsUi` is false.

**Values:**

| Value | Type | Description |
|-------|------|-------------|
| `gridId` | `String` | Unique grid key (set automatically) |

The keys are read from the rendered DOM — every `[data-col-key]` inside
`[data-gv="{gridId}"]`, skipping the ones computed to `display: none`, first
occurrence wins — not from the stored preferences. The markup is what the user is
looking at whatever wrote it, and the three renderers key their cells the same way
(the table on its `<th>`, the card and list on each field), so one query covers all
of them. Structural keys (`_selection`, the action column) travel along and the
server ignores them, as it ignores any key that matches no column.

To act on the column state yourself — a custom "download" button, say — read the
same selectors; there is no bundle API to call.

---

## `gridview-page-jump`

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

## `gridview-crud`

Drives the CRUD modal: fetches add/edit/clone/delete forms into a self-contained modal (no Bootstrap) and submits them
via `fetch`. A `text/vnd.turbo-stream.html` response refreshes the grid frame and closes the modal;
an HTML response (validation errors) is re-injected into the modal.

**Connects to:** the grid container (applied automatically when `crud` options are set).

**Actions available in templates** (emitted by `CrudButton` / the `{addButton}` token):

| Action | Description |
|--------|-------------|
| `gridview-crud#open` | Open the modal and load the form/recap from `data-gridview-crud-url-param` |
| `gridview-crud#submit` | Intercept the modal form / inline form submit (handles the Turbo Stream) |

---

## `gridview-inline-edit`

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

## `gridview-form-validate`

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

## `gridview-collection`

Adds and removes the rows of a `CollectionType` in any form: "add" appends the prototype under a
fresh key, "remove" drops a row; `min` hides the remove buttons, `max` disables "add". Nested
collections each have their own controller. See [Form components](19_form-components.md#repeatable-rows-collection).

**Connects to:** the `.gv-collection` container.

**Targets:** `list` (the rows' container), `row` (each row), `add` (the add button).

**Actions:** `gridview-collection#add`, `gridview-collection#remove` (on a button inside a row).

**Values:**

| Value | Type | Description |
|-------|------|-------------|
| `prototype` | `String` | The HTML of a new row |
| `prototypeName` | `String` | The placeholder of the row key in the prototype (`__name__` by default) |
| `index` | `Number` | The first key no row uses |
| `min` | `Number` | Rows required; at the minimum the remove buttons hide |
| `max` | `Number` | Rows allowed; `0` for no limit |

## `gridview-password-strength`

A strength meter under a password input: four bars and a word, updated on
`gridview-password-strength#update` (bind it to `input`). A hint only. See
[Form components](19_form-components.md#password-strength-meter-and-showhide-eye).

**Values:** `minLength` (`Number`, 8), `labels` (`Array`, the five level names).

## `gridview-password-reveal`

A show/hide eye inside a password input. The input is never moved: its row becomes a
`gv-form-row--password` grid holding the `.gv-password-reveal` button. Leaving the page always
hides the password again.

**Values:** `showLabel`, `hideLabel` (`String`, the button's accessible name).

## `gridview-conditional`

Shows a form row only while another field of the form holds some values. The deciding row is
the one marked `data-gv-field="<field>"` among the row's siblings, or anywhere in the form. See
[Form components](19_form-components.md#conditional-rows).

**Connects to:** the dependent row (`.gv-form-row`).

**Values:** `field` (`String`, the deciding field's name), `values` (`Array`, the values for which
the row shows).
