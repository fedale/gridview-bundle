# Feedback from the first data-source consumer (metadata-driven)

Status: **1–4 and 6 fixed**; 5 deferred. Found on 2026-10-18 while integrating 1.3.x-dev (`GridDataSourceInterface`) into
`../metadata-driven` (runtime grids at `/o/{object}`, built with `GridviewBuilderFactory`, fed by a
DBAL `GridDataSourceInterface`). Every item has a workaround on the consumer side, quoted below so
the fix can be checked against it. Follow `CLAUDE.md` for style and verification.

## Decisions (2026-10-18, by the maintainer)

- **2 — the copy.** The descriptor carries a copy of the options the host passes to
  `setOptions()`; no build hook. Implement the plan of §2 as written. Consumer side,
  metadata-driven will extract one `options(ObjectDefinitionDto)` method used both by its grid
  factory and by its provider, so the copy cannot drift there.
- **4 — accept the break in 1.3.** Remove the `@use` from `gridview.scss`, add the opt-in
  `gridview-flatpickr.scss`, write the upgrade note in `docs/06_theming.md` and the release notes,
  and drop the demo stub. metadata-driven drops its stub once this is merged.

Workarounds of 1 and 3 are removed in metadata-driven (`integration.routeName` set, fluent builder
chain, `array $row` action closure, `getExportRows()` passed as is); its suite is green on `main`.

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

**Done** (the copy, as decided). `Contract\GridDescriptorProviderInterface`, autoconfigured with
`fedale_gridview.grid_descriptor_provider`; `UiSettings\ControllerGridDescriptorProvider` carries
the controller discovery and is registered first (priority 256), so a controller's grid wins a
shared id; `GridRegistry` merges the providers lazily. The autoconfiguration is unconditional, like
the existing `fedale_gridview.grid` tag, rather than inside the setting-bundle guard: the registry
itself is always defined. Docs: `docs/18_ui-settings.md#grids-built-at-runtime`, `docs/agents.md`.
Tests: `tests/UiSettings/GridRegistryTest`; no controller-level test (the scope lookup is
`GridRegistry::get()`, covered there), the demo's ten controller scopes checked on a booted kernel.

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

**Done** (the break accepted, as decided). `gridview.scss` no longer imports flatpickr;
`assets/styles/gridview-flatpickr.scss` is the opt-in entry; `docs/06_theming.md` gains "Building
the stylesheet" (Encore and AssetMapper + sass-bundle) with the 1.3 upgrade note — there is no
changelog file, so the release notes should repeat it. Checked: the demo compiles its stylesheet
with `sass:build` after its stub and load path are removed, and the opt-in entry compiles against a
`node_modules`-like path holding flatpickr's CSS. The demo-side removal is in `gridview-demo`'s own
working tree. metadata-driven can drop its stub.

## 6. The date filter needs an import nobody lists (bug, found 2026-10-20)

Verified in a browser on metadata-driven after 2 and 4 were merged (both work: the modal opens on
`record_<object>`, the stylesheet compiles with no stub). With a date column on the page, the
console shows:

```
Error loading controller "gridview-date-filter": TypeError: Failed to resolve module specifier
"flatpickr/dist/l10n/it.js".
```

`assets/controllers/gridview-date-filter_controller.js` imports `flatpickr/dist/l10n/it.js`, but
neither `assets/package.json` (`symfony.importmap`) nor the `importmap:require` line of
`docs/06_theming.md` lists it, so an AssetMapper host's importmap lacks it and the date filters fall
back to plain text inputs, silently. Related: the picker is always Italian (`locale: Italian`
hard-wired), whatever the page or grid locale.

Fix: either load the locale dynamically from the grid's i18n locale (and list the locales the
bundle supports), or at least add `flatpickr/dist/l10n/it.js` to the importmap peers and to the
documented `importmap:require` command. Test: a JS-level or functional check that every bare
module specifier imported by `assets/controllers/*.js` is declared in `assets/package.json`.

Consumer workaround: `importmap:require flatpickr/dist/l10n/it.js`, and
`import 'flatpickr/dist/flatpickr.min.css'` in `app.js` — the guide's `importmap:require` adds the
CSS to the importmap but a CSS entry only loads when something imports it; say so in the guide.

**Fixed** on branch `fix_date_filter_locale`, the dynamic option:

- `gridview-date-filter_controller.js` no longer imports a locale statically. The picker opens in
  flatpickr's built-in English and swaps in the locale when `import()` of
  `flatpickr/dist/l10n/<code>.js` resolves; a locale the importmap doesn't map logs a warning and
  leaves the picker in English instead of failing the controller.
- The locale is the `locale` client option, else the page's `<html lang>` (`it-IT` → `it`):
  `FilterDateType` no longer defaults it to `'it'`. Behavior change for a page without
  `lang="it"`, stated as an upgrade note in `docs/04_filtering.md`.
- The locale lookup was checked against both real shapes of `it.js`: the jsDelivr ESM bundle
  AssetMapper downloads (metadata-driven's `assets/vendor`) and the npm UMD file Encore resolves.
- `tests/Assets/ControllerImportsTest`: every static bare import of `assets/*.js` and
  `assets/controllers/*.js` is a `symfony.importmap` peer of `assets/package.json` (fails on the
  old controller). Dynamic imports are left out on purpose.
- `docs/06_theming.md`: the `importmap:require` line includes the locale, and the guide says the
  CSS entry must be imported from `app.js`.

Not covered by an automated test: the picker in a browser (no JS test runner in the bundle).
metadata-driven can keep `importmap:require flatpickr/dist/l10n/it.js` and the CSS import, and
should set `<html lang>` (or `clientOptions.locale`) to get the language it wants.

## 7. Two more defaults of the date filter (found 2026-10-20, while verifying 6)

6 is verified in a browser on metadata-driven: `<html lang="en">` gives an English picker with a
clean console; switching `lang` to `it` and reconnecting the controller gives Italian weekdays, and
the disconnect cleans the previous instance (one display input left).

Two defaults of `src/Filter/FilterDateType.php` still surprise a host:

- **Italian placeholders.** `from_placeholder` / `to_placeholder` default to `'Da'` / `'A'`
  (lines 42–43), whatever the locale: the fields read "Da"/"A" on an English page. Make them
  translation keys of the `GridviewBundle` domain (like the rest of the chrome), resolved in the
  form's translation domain.
- **±1 year range.** `minDate` / `maxDate` default to one year before and after today (lines
  58–59), so the picker of a birth date, a contract start or any historical date cannot select
  most of the values the column holds — and nothing tells the user why. Default to no bounds, and
  keep `minDate` / `maxDate` as opt-in options.

Consumer side: no workaround yet (the grid of metadata-driven builds its date filters with
`filterDate()`, so both defaults apply to every date field).

**Fixed** on branch `fix/date-and-relation-filters`:

- `from_placeholder` / `to_placeholder` default to `filter.date_from` / `filter.date_to`, keys of
  the `GridviewBundle` domain (en "From"/"To", it "Da"/"A"); the from/to inputs carry that
  translation domain, so a host's own plain-text placeholder still shows as it is. The picker's
  display input, which had no placeholder at all, now shows "From – To" ("From" in single mode).
- No `minDate` / `maxDate` by default; `clientOptions` still bounds a column.
- `tests/Filter/FilterDateTypeTest`; upgrade note in `docs/04_filtering.md`.

## 8. A single-select AJAX relation filter reconnects forever (bug, found 2026-10-20)

Found on metadata-driven with a relation column filtered as
`['type' => 'relation', 'options' => ['ajax_url' => …, 'searchable' => true]]` (single select).
The console logged 3068 Stimulus lifecycle events of `gridview-relation-filter` on the same
`<select>` in 16 seconds, and every cycle fetched `ajax_url` again.

Cause, in `assets/controllers/gridview-relation-filter_controller.js`: for a single select,
`_buildSearchableSelect()` moves the `<select>` inside the new wrapper. Stimulus sees the element
leave and re-enter the DOM, so it calls `disconnect()` — whose `_teardown()` puts the select back
before the wrapper and removes it — then `connect()` again, which loads the options and moves the
select again. The multi-select path leaves the select where it is, and does not loop.

Fix: do not move the controller's own element. Keep the `<select>` in place and insert the wrapper
next to it (as the multi-select path does), or let the controller live on a wrapper element that
the template renders. Make `connect()` idempotent too (no fetch when the options are already
loaded). Test: a single-select relation filter with `ajax_url` stays connected (one connect, one
fetch).

Consumer workaround: `'multiple' => true` on the relation filters (useful anyway: several records
at once).

**Fixed** on branch `fix/date-and-relation-filters`: `_buildSearchableSelect()` inserts the search
box right before the `<select>`, which no longer moves; `_teardown()` only removes what was built.
`connect()` skips the fetch when the AJAX options are already loaded (`data-gv-options-loaded`), so
a reconnect costs nothing. The two hard-wired "Cerca..." placeholders use `search.placeholder` of
the catalog. Checked in a browser (no JS runner in the bundle) on a page with the controller and a
stubbed `ajax_url`: before, 43 connects and 43 fetches in half a second; after, one connect and one
fetch, a reconnect adds no fetch and no second search box, and the search still hides options.

## 5. Not a bundle change — for the record

- The export action of a runtime grid is hand-written in the host (format check, `cols=` parsing,
  `getExportColumns()`), mirroring `AbstractGridController::export()`. A small public helper
  (e.g. `Gridview::export(Request, GridExporterRegistry)` or a `GridExportResponder` service) would
  spare every data-source host that copy. Optional.
