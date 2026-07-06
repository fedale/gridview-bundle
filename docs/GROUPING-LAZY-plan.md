# Row grouping — Phase B (lazy) plan

Phase A (eager grouping) is implemented and verified: parent rows expand to a
nested child sub-table, children fetched up front and hidden client-side. This
plan covers Phase B — lazy children, fetched on demand when a parent is expanded
— building on the seams already in place. **Everything here is additive; nothing
from Phase A needs redesigning.**

## What already exists (Phase A seams reused by B)

- `GroupingConfig` (`src/Grid/GroupingConfig.php`) — `mode: 'eager' | 'lazy'`,
  with `isLazy()`. The single switch. Already parsed from
  `behavior.grouping.mode`.
- `ChildRowResolverInterface::resolveForParent(key, config)`
  (`src/Contract/ChildRowResolverInterface.php`) — the single-parent lazy
  resolver. **Already implemented** in `DoctrineChildRowResolver`.
- `Gridview::applyGrouping()` — in lazy mode it flags rows `isParent` and
  returns early (children left empty).
- Templates already emit the lazy placeholder: `_group_children.html.twig` sets
  `data-gv-group-lazy` on the inner when `lazy && children empty`;
  `_group_table.html.twig` renders a `.gv-group-loading` spinner.
- Stimulus `gridview-grouping_controller.js` — `_loadChildren(inner)` fetches
  `urlValue + ?_children=<key>` and injects the returned HTML into the inner,
  once (marks `data-gv-group-loaded`). Wired via `data-gridview-grouping-url-value`,
  set in `_grid.html.twig` when `lazy && integration.routeName`.

So the client → fetch → inject loop is already coded. **Phase B is mostly the
server endpoint that answers `?_children=<key>`, plus a count badge.**

## Steps

### 1. Server endpoint for `?_children=<key>` (required)

Return the rendered child sub-table HTML for a single parent.

**Where:** a branch in `Gridview::renderGrid()`, symmetric to the existing
`?_rows=1` branch. Add near the top of `renderGrid()`, after
`initializeDataProvider()` and after `$request` is fetched, before the URL-state
/ getData work (children don't need the main grid's pagination/sort):

```php
if ($this->isGrouped() && $request->query->has('_children')) {
    return $this->renderGroupChildren($request->query->get('_children'));
}
```

**New method** on `Gridview`:

```php
private function renderGroupChildren(string|int $key): Response
{
    $config   = $this->getGroupingConfig();
    $resolver = $this->dataProvider instanceof GroupingCapableInterface
        ? $this->dataProvider->getChildResolver()
        : null;

    $row = new Row(0, 0);
    $row->isParent = true;
    $row->children = $resolver?->resolveForParent($key, $config) ?? [];

    $html = $this->twig->render($config->getTemplate(), [
        'row'          => $row,
        'gridview'     => $this,
        'childColumns' => $this->getChildColumns(),
    ]);

    return new Response($html); // text/html; the JS injects it via innerHTML
}
```

Notes:
- Runs inside the controller's `index()` action, so it inherits the same
  authorization/route guards. No new route needed — the controller's index
  route is reused (that's the `routeName` the JS points at).
- `resolveForParent()` does its own query; it does not depend on the main grid
  QueryBuilder, pagination, filters or sort.
- Response is a plain HTML fragment. The controller already injects it with
  `inner.innerHTML = await res.text()`. It is our own server-rendered grid
  fragment (same-origin, trusted) — same trust model as Turbo stream HTML.

### 2. Split the loading placeholder from the sub-table body (required)

Today `_group_table.html.twig` shows the spinner when `children empty && lazy`.
That is correct for the **initial** render but wrong for the **fetched fragment**:
an empty lazy result must show "No related records", not a spinner forever.

Refactor:
- Move the spinner into the wrapper `_group_children.html.twig`: when
  `lazy && children empty` (not yet loaded), render the `.gv-group-loading`
  placeholder inside the inner (keeping `data-gv-group-lazy`), and do **not**
  include `_group_table`.
- Simplify `_group_table.html.twig` to only "table-or-empty" (drop the
  `&& lazy` spinner branch). This is what both eager render and the fragment
  endpoint produce, so an empty fetched result correctly shows the empty message.

### 3. Child count badge + correct toggle gating in lazy (recommended)

In lazy mode children aren't loaded, so the grid can't know whether a parent has
any — today `_rows.html.twig` always shows the toggle in lazy. Add a per-page
batched count so the toggle appears only when count > 0 and can show "Posts (N)".

- Add a batched count to the resolver, e.g.
  `DoctrineChildRowResolver::countForParents(iterable $parentRows, GroupingConfig): array<key,int>`
  running one query for the page:
  `SELECT parent.id AS k, COUNT(child) AS n ... GROUP BY parent.id`
  (or, for a to-many owned on the child side, `WHERE child.<inverse> IN (:keys)
  GROUP BY child.<inverse>`). Consider adding `countForParents()` to
  `ChildRowResolverInterface` as an optional capability (or a separate
  `ChildCountResolverInterface`) so non-Doctrine providers can skip it.
- In `Gridview::applyGrouping()` lazy branch, call `countForParents()` and store
  the count on each `Row` — e.g. `$row->childCount = $counts[$key] ?? 0;`
  (add `public int $childCount = 0;` to `Row`).
- In `_rows.html.twig`, gate the toggle on the count in lazy mode and render the
  badge:
  `{% set gvHasChildren = (gridview.groupingConfig.lazy and row.childCount > 0) or row.children is not empty %}`
  and show `row.childCount` in/next to the toggle cell.

Cost: one extra count query per page (cheap), no N+1.

### 4. Config / usage

No new config keys. Flip the demo (or any grid) to lazy:

```php
'grouping' => [
    'enabled'  => true,
    'mode'     => 'lazy',   // ← the only change vs Phase A
    'relation' => 'posts',
    'columns'  => [ /* same child column specs */ ],
    'label'    => 'Posts',
],
```

`_grid.html.twig` already emits `data-gridview-grouping-url-value` for lazy grids,
so the client knows where to fetch.

### 5. Tests

- Unit: `DoctrineChildRowResolver::resolveForParent()` returns the expected child
  rows for a known parent; `countForParents()` returns correct counts.
- Functional (gridview-demo): request `GET /gridview/users?_children=<id>` and
  assert the response contains that user's post titles and no parent-grid chrome.
- Regression: an eager grid and a non-grouped grid still render unchanged.

## Verify on gridview-demo (host)

Runtime checks go on **gridview-demo** (host), not repara-demo. Two asset gotchas
because the bundle assets are symlinked into `vendor/`:

- **SCSS**: after editing `assets/styles/*.scss`, run
  `php bin/console sass:build` (the sass-bundle auto-rebuild does not follow the
  symlink), then hard refresh.
- **New Stimulus controller**: enabling it in the bundle's `assets/package.json`
  is not enough — also enable it in the **app's** `assets/controllers.json`
  (as `grouping` already is), then `cache:clear`. (Phase B adds no new
  controller, so this only matters if you split one out.)

Manual check:

```bash
cd /home/danilo/sp/fedale/gridview-demo
php bin/console cache:clear --no-warmup
curl -sk 'https://127.0.0.1:8000/gridview/users?_children=10'   # → post rows fragment
```

Then in the browser: expand a parent → spinner → children load once; collapse
and re-expand → no refetch.

## Files touched (Phase B)

| File | Change |
|---|---|
| `src/Grid/Gridview.php` | `?_children` branch + `renderGroupChildren()` |
| `templates/.../table/_group_children.html.twig` | move spinner here (lazy, not-loaded) |
| `templates/.../table/_group_table.html.twig` | table-or-empty only (drop spinner) |
| `src/Grid/DoctrineChildRowResolver.php` | `countForParents()` (step 3) |
| `src/Contract/ChildRowResolverInterface.php` | optional `countForParents()` (step 3) |
| `src/Row/Row.php` | `public int $childCount` (step 3) |
| `templates/.../table/_rows.html.twig` | count-based toggle gating + badge (step 3) |
| demo `UserController` | flip `mode` to `lazy` to try it |
| `tests/` | resolver + endpoint tests |
