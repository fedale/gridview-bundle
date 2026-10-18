# Feedback from the first data-source consumer (metadata-driven)

Status: **1 and 3 fixed** on branch `fix_metadata_feedback`; **2 and 4 planned** below, each with
a decision to take first; 5 deferred. Found on 2026-10-18 while integrating 1.3.x-dev (`GridDataSourceInterface`) into
`../metadata-driven` (runtime grids at `/o/{object}`, built with `GridviewBuilderFactory`, fed by a
DBAL `GridDataSourceInterface`). Every item has a workaround on the consumer side, quoted below so
the fix can be checked against it. Follow `CLAUDE.md` for style and verification.

## 1. `_grid.html.twig` drops the route parameters (bug)

`templates/gridview/_grid.html.twig` lines 32 and 38 call `path(gridview.options.integration.routeName)`
with no parameters. On a route with placeholders (`/o/{object}`, name `record_index`) rendering
throws "Some mandatory parameters are missing ("object")". Sort and pagination links already use
`GridviewUrlState::$routeParams`; the filter form action and the grouping URL don't.

Fix: pass the current route parameters (`gridview.urlState.routeParams` or equivalent) to both
`path()` calls. Test: render a grid whose `integration.routeName` has a placeholder.

Consumer workaround: no `integration.routeName` at all (the form action falls back to the current
URL).

**Fixed.** `GridviewUrlState::getRouteParams()` exposes the current route's own parameters; the
filter form action, the lazy grouping URL and — same bug, not reported — the `addRoute` link of
`sections/addButton.html.twig` pass them to `path()`. Tests: `tests/Grid/IntegrationRouteParamsTest`
renders the add button on `/o/{object}/new` and guards `_grid.html.twig`'s source (that template
needs most of the bundle to render in isolation). Known limit, shared with the sort and pagination
links: when `routeName` points at a route with *different* placeholders, the extra parameters end up
in the query string. The consumer can now drop its workaround and set `integration.routeName`.

## 2. Runtime grids cannot be UI-settings scopes (gap)

`UiSettings\GridRegistry` only discovers `AbstractGridController` services. A grid created at
runtime (`setId('record_pet')`) is not a scope, so opening the modal with
`data-gridview-ui-settings-grid-value="record_pet"` answers 404 ("Content missing" in the modal).

Proposal: a `GridDescriptorProviderInterface` (autoconfigured, tagged) whose descriptors
`GridRegistry` merges with the controller ones, so a host can describe its runtime grids (id,
label, renderer map). Keep `AbstractGridController` discovery as one built-in provider.

Consumer workaround: the modal is opened on the global scope only (`grid-value=""`).

**Plan (not implemented).**

1. `Contract\GridDescriptorProviderInterface` with `getGridDescriptors(): iterable<GridDescriptor>`,
   autoconfigured with a `fedale_gridview.grid_descriptor_provider` tag in
   `FedaleGridviewBundle::loadExtension()`, inside the same "setting-bundle installed" guard as
   the rest of the UI settings wiring.
2. `ControllerGridDescriptorProvider`: today's discovery (the `fedale_gridview.grid` locator of
   `AbstractGridController` services, `describeGrid()`), moved out of `GridRegistry`.
3. `GridRegistry` takes `iterable<GridDescriptorProviderInterface>` and merges their descriptors,
   first id wins as today, sorted by id. Keep the lazy build: providers are only asked when the
   modal is opened.
4. Docs: `docs/18_ui-settings.md`, a "Grids built at runtime" section with a provider listing one
   descriptor per object (id `record_<object>`, label, the `options` whose renderer map the modal
   reads); a pointer in `docs/agents.md`.
5. Tests: two providers merged and sorted; duplicate id keeps the first; a runtime id resolves
   through `UiSettingsController` (no more 404).

**Decision first:** a runtime grid's options are not known until the host builds it. Either the
descriptor carries a copy of the options the host will pass to `setOptions()` (simple, can drift
from the real grid), or the provider gets a hook to build the grid's options once and the host
reuses it in its controller (no drift, more API). Recommendation: the copy, documented as "keep it
next to the code that builds the grid" — the modal only reads the renderer map from it.

## 3. Missing or too narrow types in the builder API (DX)

Found with PHPStan at level max in the consumer:

- `GridviewBuilder::setSearchModel()` and `setColumns()` declare no return type, so a fluent chain
  is `mixed` after them. Declare `: static` (or `GridviewBuilderInterface`, as `setDataProvider()`).
- `GridviewBuilder::setColumns()` documents `array<int, string|ColumnSpec>`, but `ColumnFactory`
  accepts `ColumnConfigInterface` and the docs recommend the fluent builders. Add
  `ColumnConfigInterface` to the phpdoc (`Gridview::setColumns()` too, if it has the same doc).
- `Gridview::getExportRows()` returns a plain `iterable`, while `ExporterInterface::export()`
  expects `iterable<Row>`: document `@return iterable<Row>`.
- `ActionButton`'s `$content` closure is typed `Closure(mixed, int): string`, while trap 2 of
  `docs/agents.md` says callbacks receive `array $data`. If rows are always arrays there, type it
  `Closure(array<string, mixed>, int): string`.

Consumer workarounds: no chaining after those two setters; a generator that filters `Row`
instances; a closure taking `mixed`; one `@phpstan-ignore argument.type` on `setColumns()`.

**Fixed.** `GridviewBuilder::setColumns()`, `setSearchModel()` and `setDataProvider()` return
`static` (`setDataProvider()` returned the interface, which has no `setColumns()`, so a chain was
untyped right after it — `AbstractGridController` hit it too). `setColumns()` on the builder and on
`Gridview` accept `ColumnConfigInterface` in their phpdoc; `getExportRows()` is `iterable<Row>`;
`ActionButton`'s closures are `Closure(array<string, mixed>, int)`, which is what `ActionColumn`
passes. Three PHPStan baseline entries went away with them. Note: `: static` on a non-final class
breaks a subclass that overrides those methods without a return type; none exists in the bundle or
the demos. The consumer can drop its four workarounds.

## 4. The stylesheet needs flatpickr to compile (DX)

`assets/styles/gridview.scss` does `@use 'flatpickr/dist/flatpickr'`, but flatpickr is not a
dependency, so `sass:build` fails in a fresh app until the host adds an empty stub on its load path
(the demo does the same, see its `assets/styles/stubs`). Either make the import optional (a
separate `gridview-flatpickr.scss` entry the host opts into) or document the stub in
`docs/06_theming.md`, together with the AssetMapper + sass-bundle setup (namespace path for
`@fedale/gridview-bundle`, `sass_options.load_path`): today that guide only describes Encore.

**Plan (not implemented).** The JavaScript side already treats flatpickr as a host dependency
(`assets/package.json` lists it as an importmap peer, and `gridview-date-filter_controller.js`
imports it); only the stylesheet hard-wires its CSS.

1. Remove `@use 'flatpickr/dist/flatpickr'` from `gridview.scss`.
2. Add `assets/styles/gridview-flatpickr.scss` (`@use 'flatpickr/dist/flatpickr';`) for hosts that
   want the bundle to pull the date-picker CSS through Sass (Encore, `node_modules` on the path).
3. `docs/06_theming.md`: a "Building the stylesheet" section with both setups — Encore (as today,
   plus the opt-in entry) and AssetMapper + symfonycasts/sass-bundle (`root_sass`,
   `sass_options.load_path` on `vendor/fedale/gridview-bundle/assets/styles`, flatpickr's CSS via
   `importmap:require flatpickr/dist/flatpickr.min.css`), with no stub needed in either.
4. `gridview-demo`: drop `assets/styles/stubs` and its load path once the bundle no longer needs it.

**Decision first:** this is a breaking change for Encore hosts that get flatpickr's CSS today
through `gridview.scss` without doing anything: after it, their date filters are unstyled until
they add the opt-in entry. Either accept it for 1.3 with an upgrade note in the guide (recommended:
there is no changelog file, so the note goes in `docs/06_theming.md` and the release notes), or keep
the import and only document the stub (no break, but every AssetMapper host keeps the stub).

## 5. Not a bundle change — for the record

- The export action of a runtime grid is hand-written in the host (format check, `cols=` parsing,
  `getExportColumns()`), mirroring `AbstractGridController::export()`. A small public helper
  (e.g. `Gridview::export(Request, GridExporterRegistry)` or a `GridExportResponder` service) would
  spare every data-source host that copy. Optional.
