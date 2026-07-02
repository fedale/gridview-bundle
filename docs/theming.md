# Theming & Styling

## Theming

The bundle ships a **framework-agnostic** stylesheet
(`assets/styles/gridview.scss`, compiled into the `gridview` Encore entry — no
CDN dependency). All visuals are driven by **CSS custom properties** scoped under
`[data-gridview]`, so you restyle the grid without touching the bundle.

### Framework themes (real framework classes)

Beyond token overriding, the grid can **emit a CSS framework's real classes** so
its leaf elements (buttons, …) are styled by the host's framework CSS directly.
Pick a theme from YAML — `default` keeps the agnostic `gv-*` look:

```yaml
fedale_gridview:
    theme: bootstrap5          # default | bootstrap5 | tailwind | <custom>
    gridviews:
        users: { options: { theme: tailwind } }   # per-grid override
```

A `<button class="gv-btn gv-btn-primary">` then renders as `btn btn-primary`
(Bootstrap) or Tailwind utilities — same markup, resolved at runtime via
`{{ gridview.cls('btn.primary') }}` in the templates.

**Class keys** (the closed, themable set): `btn`, `btn.primary`, `btn.danger`,
`btn.icon`, `pagination`, `pagination.item`, `pagination.link`,
`pagination.active`, `pagination.disabled`. Any key a theme omits falls back to
the `default` (`gv-*`) class — e.g. Tailwind has no pagination component, so the
pagination keys fall back to the structural `gv-pagination` (tinted by tokens).

**Inputs** are not class-keyed: the bundle styles them by element selector
(`[data-gridview] input/select`), so they follow the theme automatically through
the `--gv-input-*` tokens (the presets remap these to the framework's palette).
Hosts using a framework form theme (e.g. Bootstrap's `form-control`) can override
per their form theme; the grid does not force input classes.

**Custom themes** are declared entirely from YAML — no PHP. Map class keys to
your own classes; `extends` starts from a built-in and overrides only some keys:

```yaml
fedale_gridview:
    theme: mycss
    themes:
        mycss:
            extends: bootstrap5        # optional base
            classes:
                'btn.primary': 'c-button c-button--primary'
                'btn':         'c-button'
```

What the theme is — and isn't:

- **Presentation only.** `class=` carries style; JS hooks live on `data-*`
  (Stimulus actions/targets). Switching theme never changes behaviour.
- **The framework's CSS is the host's job.** The bundle emits class *names*; load
  Bootstrap/Tailwind yourself. The bundle never bundles a framework's CSS.
- **No framework JS is ever required.** Dropdown/modal/inline-edit stay on the
  bundle's own Stimulus controllers (it does *not* emit `data-bs-toggle`).
- **Structural elements stay `gv-*`** (dropdown panel, overlay, responsive rows,
  modal). They're tinted to the framework via optional token presets, activated
  by the `data-gv-framework="<theme>"` attribute the bundle puts on the grid:
  `assets/styles/presets/_bootstrap5.scss` (maps `--gv-*` → `var(--bs-*)`, so
  dark mode follows Bootstrap for free) and `_tailwind.scss`. Both ship in
  `gridview.scss` and are inert until a framework theme is active.

> Buttons inside the CRUD modal partials (delete/bulk-delete/batch/inline) are
> themed too: the CRUD controller forwards the already-built grid into the
> partial, so they use the same `gridview.cls()` and follow the per-grid theme.

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

Feedback / semantic (info banner, inline-edit, bulk bar, validation, modal
backdrop): `--gv-info-bg`, `--gv-info-color`, `--gv-info-border`,
`--gv-success-color`, `--gv-success-flash-bg`, `--gv-danger-color`,
`--gv-danger-flash-bg`, `--gv-accent-bg`, `--gv-accent-border`,
`--gv-accent-color`, `--gv-backdrop`.

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

| Key | Region | Target element |
|-----|--------|---------------|
| `class` | `dataview` | `<table>` element |
| `container` | `shell` | Div wrapping the entire grid |
| `header` | `header` | The header region div |
| `filter` | `filter` | `<tr>` containing filter inputs |
| `row` | `row` | Every `<tr>` in the tbody |

This bag is shorthand for the most common targets; under the hood it feeds the same
per-region attribute map as [`layout.attrs[T]`](layout.md#per-region-html-attributes), which
can attach attributes to **any** region or table-internal (e.g. `thead`, `toolbar`,
`header`) and overrides the bag per key.
