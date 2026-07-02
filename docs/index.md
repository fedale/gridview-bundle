# FedaleGridviewBundle — Documentation

A Symfony bundle for rendering configurable data grids, inspired by the Yii 2 GridView widget.
The grid is not automagic: you configure a data source and a column list, the bundle does the rest.

---

## Guides

New here? Start with **[Getting Started](getting-started.md)**, then reach for the
guide you need. Every page is self-contained.

### Core

- **[Getting Started](getting-started.md)** — overview, quick start, and the data provider.
- **[Columns](columns.md)** — string shorthand, full definitions, column types, the
  `media` type, ActionColumn, custom column types, nested data, and raw HTML.
- **[Sorting & Pagination](sorting-pagination.md)** — default/multi-attribute sorting,
  page navigation, and jump-to-page.
- **[Filtering & Search](filtering.md)** — per-column filters, the filterBar, filter
  types, applying filters in the repository, permission-based row hiding, and global search.

### Layout & presentation

- **[Layout System](layout.md)** — layout tokens, spacing, runtime customisation, slots,
  per-region attributes, and responsive column collapse.
- **[Theming](theming.md)** — framework themes, light/dark mode, design tokens, and the
  per-element attribute/styling bags.
- **[Internationalization (i18n)](i18n.md)** — instant client-side language switching,
  translation domains, and localizing your own strings.

### CRUD & detail

- **[CRUD forms](crud.md)** — forms generated from columns, validation, bulk actions,
  inline editing, and the controller base classes.
- **[DetailView](detail-view.md)** — rendering a single record.
- **[Export](export.md)** — export formats, per-grid limits, and saved searches & selections.

### Integration & extension

- **[YAML Configuration](configuration.md)** — global defaults, per-grid presets, and
  merge precedence.
- **[JavaScript Controllers](javascript.md)** — the Stimulus controllers shipped with the bundle.
- **[Real-time updates (Mercure)](real-time.md)** — signal-based auto-refresh.
- **[Extending the Bundle](extending.md)** — public interfaces, custom columns, and row events.
- **[Full Example](full-example.md)** — a complete controller + template walkthrough.
