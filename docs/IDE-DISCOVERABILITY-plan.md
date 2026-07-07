# IDE discoverability for the array column API — plan

## Problem

Columns (and their options) are authored as string-keyed arrays:

```php
$columns = [
    ['attribute' => 'title', 'type' => 'text', 'format' => ['truncate' => 30]],
];
```

The keys (`type`, `format`, `control`, `filter`, ...) and the type names
(`'text'`, `'badge'`, ...) are plain strings inside an associative array, so an
IDE has nothing to complete or check. Today the bundle ships **no** discoverability
aid — verified absent: no `.phpstorm.meta.php`, no JSON Schema, no type-name
enum/constants, no fluent builder, no PHPStan array shapes. Users discover options
from the docs only, and typos surface at runtime.

Contrast: EasyAdmin gets full autocomplete because its fields **are** objects
(`TextField::new('title')->setMaxLength(255)`). You cannot get that from arrays
for free — you either become objects, or add editor/static metadata.

## Decisions already taken (do not relitigate)

- The **array / YAML spec stays the canonical API.** It backs the YAML config and
  the dynamic/mergeable use cases; we are not deprecating it.
- Every layer below is **additive and backward compatible** — plain strings keep
  working everywhere.
- Ship **cheap-but-real** layers first (enum + static shapes); treat the fluent
  builder as opt-in, on-demand, not a goal for its own sake.

## What exists today (anchors reused below)

- `src/Column/ColumnFactory.php` — the single place a column spec is normalized
  into a `DataColumn`. String shorthand at `:64`, array spec at `:87`, filter type
  inheritance at `:95` (`$columnType->inferFilterType()`). **This is where any new
  accepted value type (e.g. a `BackedEnum` for `type`) must be unwrapped.**
- `src/Grid/GridviewBuilder.php:42` — `setColumns(array $columns)`, the public
  authoring entry point. Mirror entries: `src/Grid/Gridview.php:401`,
  `src/Grid/DetailViewBuilder.php:69`.
- `src/Column/Type/ColumnTypeRegistry.php` — auto-collects every
  `ColumnTypeInterface` service, maps `getName()` → instance, plus aliases
  (`data`→`text`, `choice`→`select`, `richtext`→`richText`). **Source of truth for
  the set of valid type names.**
- `src/Column/Type/ColumnTypeInterface.php` — `getName()`, `getParent()`,
  `getDefaultOptions()`, `inferFilterType()`, `inferControlType()`. The per-type
  option surface lives behind `getDefaultOptions()`.
- `src/Filter/Applier/FilterApplierRegistry.php` — valid filter types
  (`text`, `boolean`, `date`, `number`, `choice`, `relation`).
- `src/Form/Control/ControlTypeRegistry.php` — valid control types
  (`text`, `money`, `date`, `choice`, ...).

## Phase 1 — Type-name enums (cheap, partial win)

Give autocomplete + typo-safety + go-to-definition on the **names** (not the
option keys).

**New files**

- `src/Column/Type/ColumnType.php` — string-backed enum, one case per registered
  type (`Text = 'text'`, `Badge = 'badge'`, ...). Values must equal the registry
  `getName()`s.
- `src/Filter/FilterType.php` — enum mirroring `FilterApplierRegistry`.
- `src/Form/Control/ControlType.php` — enum mirroring `ControlTypeRegistry`.

**Wiring**

- In `ColumnFactory.php` where `$spec['type']` is read (around `:87`–`:95`), accept
  `string|ColumnType` and unwrap `->value` when it is a `BackedEnum`. Same treatment
  for `filter.type` and `control.type` where those specs are normalized
  (`normalizeFilter()` at `ColumnFactory.php:128`; control resolution in
  `src/Form/Control/ControlResolver.php`).

Usage after:

```php
['attribute' => 'title', 'type' => ColumnType::Text, 'filter' => ['type' => FilterType::Text]]
```

**Risk / mitigation:** the enum duplicates names → drift. Add a parity test
(below). **Payoff:** covers only the names, not `format`/`control` option keys.
**Effort:** ~half a day incl. tests.

## Phase 2 — PHPStan array shapes (CI validation, some PhpStorm completion)

Describe the column spec as a shape and annotate the entry points, so wrong keys
and mistyped values are flagged by static analysis (and PhpStorm offers partial
shape completion).

**Where**

- Add a `@phpstan-type ColumnSpec array{...}` to a central docblock (e.g. above
  `GridviewBuilderInterface` or in `ColumnFactory`), listing the real keys:
  `attribute`, `type` (`string|ColumnType`), `label` (`string|bool`), `value`,
  `valueGetter`, `formatter`, `renderer` (callables), `format` (`array<string,mixed>`),
  `filter` (`array|bool|null`), `control` (`array<string,mixed>`), `sortable`,
  `visible`/`active`, `twigFilter`, `filterBar`, `editable`.
- Annotate `GridviewBuilder::setColumns()`, `Gridview::setColumns()`,
  `DetailViewBuilder::setColumns()` with `array<int, string|ColumnSpec>`.
- Reuse the same `@phpstan-import-type ColumnSpec` in the other two entry points to
  keep one definition.

**Risk / mitigation:** `format`/`control` are open (`array<string,mixed>`), so
per-type option keys still aren't validated — acceptable at this phase. Keep the
shape in one place and import it to avoid three copies drifting. **Payoff:** real
CI safety with near-zero runtime cost. **Effort:** ~half a day; then fix whatever
`phpstan` newly reports across `tests/` fixtures.

## Phase 3 — JSON Schema for the YAML config (helps the declarative crowd)

Since grids can be configured in YAML, ship a JSON Schema so editors give full
autocomplete + validation + inline docs on the YAML side.

**Prereq (verify first):** confirm the YAML config surface and where it is loaded.
`src/DependencyInjection/Configuration.php` currently does **not** describe a grid
`columns` tree (it holds unrelated nodes), so map the real YAML entry against
`docs/configuration.md` before writing the schema — do not assume the semantic DI
config is the source.

**New files**

- `resources/schema/gridview.schema.json` — columns, types (enum sourced from
  Phase 1), pagination, sort, filter, per-region attributes.
- Document the opt-in header in `docs/configuration.md`:
  `# yaml-language-server: $schema=vendor/fedale/gridview-bundle/resources/schema/gridview.schema.json`.

**Risk:** only helps YAML authors, not the PHP array API; schema must track the
type enum (add a generation/parity test). **Effort:** ~1 day incl. verifying the
YAML surface.

## Phase 4 — Optional fluent builder (only real EA-parity, on demand)

The only layer that matches EA ergonomics for the PHP API:

```php
$columns = [
    Column::text('title')->truncate(30)->sortable(),
    Column::badge('status')->filter(FilterType::Choice),
];
```

**Shape**

- `src/Column/Column.php` (builder) with static factories `text()`, `badge()`,
  `number()`, ... each returning a typed builder whose `toArray()` (or a `Column`
  DTO) produces exactly the spec `ColumnFactory` already consumes — so no change to
  the resolution pipeline, `setColumns()` just accepts `string|array|Column`.
- Per-type option methods only where they add value (`truncate()`, `decimals()`);
  generic passthrough (`format(array)`, `control(...)`) for the rest.

**Risk:** this **adds the surface the array API was meant to avoid** — one factory
per type + per-option methods, and a second way to do everything (docs/tests
double). Do it **only if** hand-writing grids in PHP becomes a recurring user
complaint. **Effort:** 2–4 days + ongoing upkeep as types grow.

## Non-goals

- No removal/deprecation of the array or YAML API.
- No editor-specific-only solution as the primary path (`.phpstorm.meta.php` is a
  possible extra for value completion, but not a phase on its own).
- No runtime validation layer beyond what already errors on unknown types.

## Recommended sequencing

1. **Phase 1 + Phase 2 together** — highest payoff per effort; covers type names
   (autocomplete) and spec validation (CI). ~1 day.
2. **Phase 3** only if YAML config is a real, used path for the audience.
3. **Phase 4** only on demonstrated demand.

## Verification per phase

- **P1:** a demo grid with `type: ColumnType::Badge` renders identically to
  `'badge'`; parity test asserts `ColumnType` cases == `ColumnTypeRegistry` names
  (and same for filter/control enums vs their registries); existing functional
  tests stay green.
- **P2:** `phpstan analyse` is clean on the baseline, then introducing a bogus key
  (`'typ' => ...`) or wrong value type is reported.
- **P3:** open a YAML config with the `$schema` header in VSCode/PhpStorm and
  confirm completion + a red squiggle on an invalid type; schema-vs-enum parity
  test.
- **P4:** a `Column::text(...)` grid produces byte-identical resolved columns to
  its array equivalent (assert on the built `DataColumn` set).
