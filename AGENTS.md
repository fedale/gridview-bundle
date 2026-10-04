# Contributing to fedale/gridview-bundle — guidelines for AI assistants

> `CLAUDE.md` is a symlink to this file: one set of guidelines, served under both names. Edit this
> file, never the link.

## What this repository is

`fedale/gridview-bundle` is a **reusable Symfony bundle**: an embeddable, application-grade data
grid (filtering, sorting, CRUD, export, real-time, i18n) that a host application drops into its own
pages. It is a library, not an application, and it is **not** an admin generator: the host app keeps
its routes, layout, security and controllers.

It is framework-agnostic on the CSS side. The bundle ships its own `gv-*` styles and its own Stimulus
controllers, and adopts Bootstrap or Tailwind only through an opt-in theme. Nothing in `src/` or
`templates/` may assume a CSS framework.

**Building an app that *uses* the bundle rather than changing the bundle?** Read
[`docs/agents.md`](docs/agents.md) instead — it is the usage primer, and it will stop you
reimplementing features that already exist.

**Requirements:** PHP 8.1+, Symfony 6.4 / 7.x / 8.x, Doctrine ORM 2.12+ / 3.x, Twig 3.

## Verifying a change

```bash
composer cs         # php-cs-fixer, dry run with a diff
composer cs-fix     # apply the fixes
composer test       # phpunit (suite = tests/)
composer phpstan    # level 6 over src/, with phpstan-baseline.neon
```

Run the linter and the tests before reporting a change as done. PHPStan has a baseline: fixing a
baselined error is welcome, **adding a new one is not** — do not append to the baseline to silence
your own change.

There is no `twig-cs-fixer` and no `simple-phpunit` in this repository; do not invent commands for
tools that are not in `composer.json`.

## Repository map

| Path | What lives there |
|---|---|
| `src/Grid/Gridview.php` | The grid object: option resolution, column pipeline, the layout-token engine (`parseLayout`, `layoutTokens`, `resolveLayout`), renderer selection |
| `src/Grid/GridviewBuilder.php` | The public authoring entry point (`setDataProvider`, `setColumns`, …) |
| `src/Grid/GridviewConfigRegistry.php` | Layout and option defaults |
| `src/Column/ColumnFactory.php` | **The single place** a column spec becomes a column object — every newly accepted spec key or value type is unwrapped here |
| `src/Column/AbstractColumn.php`, `DataColumn.php` | Runtime column behaviour: `active` contexts, sort state, footers, controls |
| `src/Column/Config/*` | Fluent builders (`TextColumn::new()->…`), one per type; they only assemble the spec array |
| `src/Column/Type/*` | Data types: rendering, default filter (`inferFilterType`), default control (`inferControlType`) |
| `src/Filter/`, `src/Filter/Applier/` | Filter form types, and the appliers that turn them into query constraints |
| `src/Form/` | The write side: `GridFormBuilder` (form from columns), `SearchForm`, control resolution |
| `src/Crud/GridCrudHandler.php` | Form rendering with `{ field }` token substitution, delete recaps, clone semantics |
| `src/Controller/` | `AbstractGridController`, `AbstractCrudGridController`, `AbstractDetailController`, and the `ResolvesViewConfig` trait |
| `src/DataProvider/` | `EntityDataProvider` (Doctrine), `JsonDataProvider` (HTTP), the abstract base |
| `src/Contract/` | Every public interface — the extension surface |
| `src/Theme/ThemeRegistry.php` | Class-key → CSS class maps; `default` is the canonical, complete key set |
| `src/Export/`, `src/Serializer/`, `src/Mercure/`, `src/Pagination/`, `src/Sort/` | One concern each |
| `src/Maker/MakeGridCrud.php` | `make:gridview:crud` scaffolding |
| `templates/` | Twig: `gridview/sections/*` are the layout tokens, `sections/dataview/{table,card,list}` the renderers, `crud/*` the forms and modals |
| `assets/controllers/` | Stimulus controllers (`gridview-filter`, `gridview-visibility`, …) |
| `translations/` | `GridviewBundle.{en,it}.yaml` — the system domain |
| `config/services.yaml`, `config/columns.yaml` | Service wiring |
| `docs/` | The numbered user guides, plus `agents.md` (the usage primer) |
| `docs/internal/` | Working plans and roadmaps, not user documentation |

Registries are the source of truth for which names are valid: `ColumnTypeRegistry`,
`FilterApplierRegistry`, `ControlTypeRegistry`, `GridExporterRegistry`, `ThemeRegistry`. When you add
a type, add it to its registry, its enum (`ColumnType`, `FilterType`, `ControlType`) **and** its
fluent builder — all three, or it is only half-discoverable.

## PHP style

`.php-cs-fixer.dist.php` is the source of truth, and it documents the deliberate departures from
`@Symfony`. Match the surrounding code:

- `@Symfony` + `@Symfony:risky`, with these intentional choices: **non-Yoda** comparisons
  (`$value === null`, not `null === $value`), unqualified global calls (`count()`, not `\count()`),
  `fn(...)` / `function(...)` with no extra space, and the bundle's own operator spacing.
- **No `declare(strict_types=1);`** — no file in `src/` has it.
- PHP 8.1 is the floor: no 8.2+ syntax (no readonly classes, no DNF types). Constructor property
  promotion is used throughout.
- Single-quoted strings, straight quotes only, trailing commas in multi-line arrays, braces on every
  control structure, blank line before `return` unless it is the only statement.
- Naming: `camelCase` methods, `snake_case` in config/routes/Twig, `Abstract*`, `*Interface`,
  `*Trait`, `*Exception`; classes carry the suffix of their kind (`*Controller`, `*Column`, `*Type`,
  `*Applier`, `*Provider`, `*Subscriber`).
- Avoid `else`/`elseif` after a `return` or `throw`. Handle exceptions explicitly — no silent catches.
- Exception messages: capital letter, final period, `sprintf()` with `get_debug_type()` for class
  names, no backticks.
- Enums for fixed sets of values, not constants.
- Services are autowired; do not register them explicitly unless the wiring genuinely requires it
  (see `config/columns.yaml` for the exceptions that do).

### Comments

Comments carry the *why*, in **English**, and they are a real asset in this codebase — the existing
ones explain non-obvious invariants (why a normalizer is registered first, why a column is dropped
before wiring, why the request is read per access). Match that density: explain a decision that is
not evident from the code, and skip restating what the line already says. When you change behaviour
that a comment describes, update the comment in the same edit.

## Tests

`tests/` mirrors `src/` and holds mostly unit tests — that is the convention here (the bundle has no
application kernel of its own to boot). PHPUnit 9.6, helpers in `tests/Support/`.

- One test class per unit under test, `void` return types on test methods, descriptive names without
  a redundant `test` prefix in the sentence.
- Test the bundle's own behaviour, not Symfony's or Doctrine's.
- A bug fix comes with the test that fails before it.
- Cover both branches of anything with a fallback (with and without `ext-intl`, Doctrine 2 and 3, a
  grid with and without a search model).

## Templates and assets

- Twig: modern syntax, `snake_case` template names, `|trans` for every user-facing string (system
  strings in the `GridviewBundle` domain), `aria-*` and semantic tags.
- **No CSS-framework classes.** Structural hooks are `gv-*`; presentational leaves go through
  `gridview.cls('btn.primary')` so a theme can remap them. If a new element needs a themable class,
  add the key to the `default` map in `ThemeRegistry` (the canonical key set) and to any framework
  theme that has a real equivalent.
- Styles are **SCSS** under `assets/styles/`, composed with `@use`: `_tokens.scss` holds
  the `--gv-*` design tokens, `_dark.scss` the dark-mode overrides, `presets/` the
  per-framework token bridges, `gridview.scss` the components. Every component style
  reads a token rather than a literal colour, so a host app can re-skin the grid by
  overriding the custom properties alone. 4-space indent, `kebab-case` `gv-*` classes,
  logical properties (`margin-block-end`); nesting is used sparingly, for state and
  modifiers rather than to mirror the DOM.
- JavaScript: ES6+, 4-space indent, `camelCase`, one Stimulus controller per file under
  `assets/controllers/`, cleaning up its listeners in `disconnect()`. Anything that touches table
  cells must keep working across Turbo frame renders.
- Translations: when you add a key, add it to **every** locale file (English as the placeholder if
  you are unsure of the translation).

## Documentation

When you change behaviour, update the matching numbered guide in `docs/` — Markdown, not
reStructuredText. When you add or rename a **capability**, also update the capability map in
[`docs/agents.md`](docs/agents.md): that file is what a coding agent reads before using the bundle,
and a feature missing from it gets reimplemented by hand in host applications.

Config keys in the docs must name the real path. A controller's `viewConfig()` has top-level keys
(`id`, `template`, `export`, `attributes`, `options`, plus `form` and `labels` for CRUD); every
`display.*` / `behavior.*` / `integration.*` grid option lives under `options`. Renaming a key
without updating its guide is how host applications end up hand-rolling a feature that exists.

Writing style: American English, second person, gender-neutral (they/them), contractions welcome.
Avoid "just", "simply", "obviously", "easy". Realistic examples, no `foo`/`bar`. Separate link text
from URLs rather than inlining them mid-sentence. Show config as PHP first, then YAML.

## Git

- Commit subjects in **English**, imperative mood, ~50 characters, no trailing period
  ("Add search.fields for filters that have no column").
- Branch names: lowercase with underscores — a short description, or `fix_<issue>`.
- Do not commit, push, or open a pull request unless you were asked to.
- Never edit `vendor/`, `var/`, or `composer.lock` by hand.

## Anti-patterns

- Hardcoding Bootstrap or Tailwind classes anywhere in `src/` or `templates/`.
- Hardcoding user-facing text instead of a translation key.
- Adding a column type, filter type or control type without its registry entry, enum case and fluent
  builder.
- Normalizing a column spec anywhere other than `ColumnFactory`.
- Storing the `Request` (or anything request-derived) on a long-lived service — the grid's services
  are reused across requests in a worker; read the request per access and reset per-request state.
  See `docs/14_extending.md`, "Request-scoped services".
- Growing `phpstan-baseline.neon` to accommodate a new change.
- Assuming a row is an entity: rows reach callbacks as normalized arrays.
- Rewriting a whole template to change one part — use regions, slots, per-region attributes or a
  per-renderer item template.
