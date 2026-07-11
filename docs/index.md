# FedaleGridviewBundle — Documentation

A Symfony bundle for rendering configurable data grids, inspired by the Yii 2 GridView widget.
The grid is not automagic: you configure a data source and a column list, the bundle does the rest.

---

## Guides

New here? Start with **[Getting Started](01_getting-started.md)**, then reach for the
guide you need. Every page is self-contained.

### Core

- **[Getting Started](01_getting-started.md)** — overview, quick start, and the data provider.
- **[Columns](02_columns.md)** — string shorthand, full definitions, column types, the
  `media` type, ActionColumn, custom column types, nested data, and raw HTML.
- **[Sorting & Pagination](03_sorting-pagination.md)** — default/multi-attribute sorting,
  page navigation, and jump-to-page.
- **[Filtering & Search](04_filtering.md)** — per-column filters, the filterBar, filter
  types, applying filters in the repository, permission-based row hiding, and global search.

### Layout & presentation

- **[Layout System](05_layout.md)** — layout tokens, spacing, runtime customisation, slots,
  per-region attributes, and responsive column collapse.
- **[Theming](06_theming.md)** — framework themes, light/dark mode, design tokens, and the
  per-element attribute/styling bags.
- **[Internationalization (i18n)](07_i18n.md)** — instant client-side language switching,
  translation domains, and localizing your own strings.

### CRUD & detail

- **[CRUD forms](08_crud.md)** — forms generated from columns, validation, bulk actions,
  inline editing, and the controller base classes.
- **[DetailView](09_detail-view.md)** — rendering a single record.
- **[Export](10_export.md)** — export formats, per-grid limits, and saved searches & selections.

### Integration & extension

- **[YAML Configuration](11_configuration.md)** — global defaults, per-grid presets, and
  merge precedence.
- **[JavaScript Controllers](12_javascript.md)** — the Stimulus controllers shipped with the bundle.
- **[Real-time updates (Mercure)](13_real-time.md)** — signal-based auto-refresh.
- **[Extending the Bundle](14_extending.md)** — public interfaces, custom columns, and row events.
- **[Full Example](15_full-example.md)** — a complete controller + template walkthrough.
