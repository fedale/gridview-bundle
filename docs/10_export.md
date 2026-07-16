# Export & Saved Searches

## Export

Exports respect the **current filters/sort** (the data provider is re-run without
pagination) and the **export columns** (those flagged `exportable`, else the visible data columns).
Built-in formats (all native PHP, no extra dependency): **CSV** (`csv`), **Excel** (`xlsx`, a real
Office Open XML file), **PDF** (`pdf`, a paginated Helvetica table) and **JSON** (`json`). The set is
**extensible** — implement `ExporterInterface` and the service is auto-registered (no config),
appearing in the export menu and selectable via `?format=<key>`.

| Format | `?format=` | Extension | Notes |
| --- | --- | --- | --- |
| CSV | `csv` | `.csv` | UTF-8 with BOM (so Excel reads the UTF-8 correctly); HTML values flattened to text |
| Excel | `xlsx` | `.xlsx` | A real Office Open XML file (via `ZipArchive`), bold header row, numeric cells where the value is numeric. Without the `zip` extension it falls back to CSV |
| PDF | `pdf` | `.pdf` | A minimal hand-written PDF (A4 landscape, Helvetica core font), paginated table with column truncation. For complex reports use a host-app exporter (dompdf, wkhtmltopdf, …) |
| JSON | `json` | `.json` | Array of objects, one key per column attribute (falling back to the label) |

All cell values are **flattened to plain text** (HTML stripped), consistently across
formats: a currency column is exported with its rendered string.

To add a format, all you need is a class implementing `ExporterInterface`:

```php
// app/src/Export/XmlExporter.php
class XmlExporter implements \Fedale\GridviewBundle\Export\ExporterInterface
{
    public function getKey(): string   { return 'xml'; }
    public function getLabel(): string { return 'XML'; }
    public function export(iterable $rows, iterable $columns, array $context = []): Response { /* … */ }
}
```

Wire it: there's nothing to register. The exporter is auto-discovered, so it shows
up in the menu on its own, and `AbstractGridController` already ships the `/export`
action and auto-wires the menu URL and format list (`integration.export`). You only
need the `{export}` token in your toolbar — CRUD grids include it by default:

```php
protected function viewConfig(): array
{
    return ['options' => ['display' => ['layout' => ['toolbar' => '{addButton} {export}']]]];
}
```

The `{export}` link carries the current querystring, so the download reflects the active filters.
Mark columns with `exportable => true` to restrict the export to a subset.

### Limiting the formats per grid

By default every grid offers **all** registered exporters. To limit them for a specific
grid (and also fix their order in the menu), set the `export.formats` config in the
controller with an allow-list of keys — unknown keys are ignored, and `null` means "all":

```php
final class CustomerController extends AbstractGridController
{
    protected function viewConfig(): array
    {
        return [
            'export' => ['formats' => ['csv', 'pdf']],  // CSV and PDF only, in this order
        ];
    }
}
```

The allow-list applies to both the **menu** and the `export` **action**: an excluded
format isn't reachable even by forcing `?format=<key>` by hand (it returns a 404).

For more dynamic logic (e.g. different formats per user/role), override
`exportFormats()` directly, which returns the ordered list of `ExporterInterface`:

```php
protected function exportFormats(): array
{
    $all = $this->exporters()->all();              // ['csv' => …, 'xlsx' => …, 'pdf' => …, 'json' => …]

    return $this->isGranted('ROLE_ADMIN')
        ? array_values($all)                        // admin: all of them
        : array_values(array_intersect_key($all, array_flip(['csv'])));  // others: CSV only
}
```

If instead you build the menu by hand (a custom controller, outside
`AbstractGridController`), filter the `formats` array passed in `options.integration.export`
yourself with the same criterion.

## Saved searches & selections

Users can save the current **filters** (querystring) and **row selections** under a name and
re-apply them. Persistence is client-side and **pluggable** via `assets/preferences.js`:

```js
// Default: localStorage (persistent, per-browser), scoped per route.
// To back it with your API instead, set this before the controllers connect:
window.gridviewPreferenceProvider = {
    load(scope, bucket) { /* return Array */ },
    save(scope, bucket, items) { /* persist */ },
};
```

**Saved searches** — add the `{savedSearch}` token (e.g. in the toolbar). The
`gridview-saved-search` controller saves `window.location.search` under a name and re-applies it
with `Turbo.visit`. Bucket `searches`, items `{ name, query }`.

**Saved selections** — with a `checkbox` column the header dropdown gains *Save selection…* and a
list of saved sets. `gridview-selection` stores the selected ids (bucket `selections`,
`{ name, ids }`, max 5000) and reloads them into the selection on demand.

Both are scoped by `window.location.pathname` and need no new backend endpoints.

**Naming** — instead of `window.prompt`, a small built-in modal (`assets/prompt-modal.js`, a
Promise-based `promptModal({title, label, value})`) collects the name, pre-filled with a sensible
default built from the translated label prefix, the localized date and an index —
`<saved.label> <date> (<n>)` for searches (n = next index) and `<selection.label> <date> (<n>)` for
selections (n = number of selected rows). Enter confirms, Escape / backdrop cancels.

**Column reorder** — set `reorderColumns => true` to make toggleable column headers draggable
(native HTML5 drag-and-drop). `gridview-column-order` reorders the `<th>` and every row's `<td>` by
their `data-col-key` (the column attribute) and persists the order via the preference provider
(bucket `columnOrder`), re-applying it on connect — so it survives Turbo refreshes. Purely cosmetic
(client-side); structural columns (checkbox/actions) stay put.
