# Export & Saved Searches

## Export

Exports respect the **current filters/sort** (the data provider is re-run without
pagination) and the **export columns** (those flagged `exportable`, else the visible data columns).
Built-in formats (all native PHP, no extra dependency): **CSV** (`csv`), **Excel** (`xlsx`, a real
Office Open XML file), **PDF** (`pdf`, a paginated Helvetica table) and **JSON** (`json`). The set is
**extensible** — implement `ExporterInterface` and the service is auto-registered (no config),
appearing in the export menu and selectable via `?format=<key>`.

| Format | `?format=` | Estensione | Note |
| --- | --- | --- | --- |
| CSV | `csv` | `.csv` | UTF-8 con BOM (Excel apre l'UTF-8 correttamente); valori HTML appiattiti a testo |
| Excel | `xlsx` | `.xlsx` | File Office Open XML reale (via `ZipArchive`), riga di intestazione in grassetto, celle numeriche dove il valore è numerico. Senza l'estensione `zip` ripiega su CSV |
| PDF | `pdf` | `.pdf` | PDF minimale scritto a mano (A4 orizzontale, font core Helvetica), tabella paginata con troncamento delle colonne. Per report complessi usa un exporter host-app (dompdf, wkhtmltopdf, …) |
| JSON | `json` | `.json` | Array di oggetti, una chiave per attributo di colonna (fallback alla label) |

Tutti i valori delle celle vengono **appiattiti a testo semplice** (HTML rimosso), coerentemente
fra i formati: una colonna currency viene esportata con la sua stringa renderizzata.

Per aggiungere un formato basta una classe che implementa `ExporterInterface`:

```php
// app/src/Export/XmlExporter.php
class XmlExporter implements \Fedale\GridviewBundle\Export\ExporterInterface
{
    public function getKey(): string   { return 'xml'; }
    public function getLabel(): string { return 'XML'; }
    public function export(iterable $rows, iterable $columns, array $context = []): Response { /* … */ }
}
```

Wire it: add the `{export}` token and pass the menu (`url` + `formats` from the registry); the export
action delegates to the chosen exporter:

```php
->setOptions([
    'export' => [
        'url'     => $this->generateUrl('gridview_user_export'),
        'formats' => array_map(fn($e) => ['key' => $e->getKey(), 'label' => $e->getLabel()],
                               array_values($exporters->all())),  // GridExporterRegistry
    ],
    'layout' => ['toolbar' => '{addButton} {export}'],
])

#[Route('/export', name: 'export', methods: ['GET'])]
public function export(Request $request, GridExporterRegistry $exporters): Response
{
    $format = (string) $request->query->get('format', 'csv');
    if (!$exporters->has($format)) { throw $this->createNotFoundException(); }
    $g = $this->buildGridview();
    return $exporters->get($format)->export($g->getExportRows(), $g->getExportColumns(), ['filename' => 'utenti']);
}
```

The `{export}` link carries the current querystring, so the download reflects the active filters.
Mark columns with `exportable => true` to restrict the export to a subset.

### Limitare i formati per-griglia

Di default ogni griglia offre **tutti** gli exporter registrati. Per limitarli a una griglia
specifica (e fissarne anche l'ordine nel menu) imposta la config `exportFormats` nel controller con
una allow-list di key — le chiavi sconosciute vengono ignorate, `null` significa "tutti":

```php
final class CustomerController extends AbstractGridController
{
    protected function viewConfig(): array
    {
        return [
            'exportFormats' => ['csv', 'pdf'],  // solo CSV e PDF, in quest'ordine
        ];
    }
}
```

L'allow-list vale sia per il **menu** sia per la **action** `export`: un formato escluso non è
raggiungibile nemmeno forzando `?format=<key>` a mano (risponde 404).

Per logiche più dinamiche (es. formati diversi per utente/ruolo) sovrascrivi direttamente
`exportFormats()`, che ritorna la lista ordinata di `ExporterInterface`:

```php
protected function exportFormats(): array
{
    $all = $this->exporters()->all();              // ['csv' => …, 'xlsx' => …, 'pdf' => …, 'json' => …]

    return $this->isGranted('ROLE_ADMIN')
        ? array_values($all)                        // admin: tutti
        : array_values(array_intersect_key($all, array_flip(['csv'])));  // altri: solo CSV
}
```

Se invece monti il menu a mano (controller custom, fuori da `AbstractGridController`), filtra tu
l'array `formats` passato nelle `options.export` con lo stesso criterio.

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

**Saved selections** — with a `checkbox` column the header dropdown gains *Salva selezione…* and a
list of saved sets. `gridview-selection` stores the selected ids (bucket `selections`,
`{ name, ids }`, max 5000) and reloads them into the selection on demand.

Both are scoped by `window.location.pathname` and need no new backend endpoints.

**Naming** — instead of `window.prompt`, a small built-in modal (`assets/prompt-modal.js`, a
Promise-based `promptModal({title, label, value})`) collects the name, pre-filled with a sensible
default: `ricerca <date> (<n>)` for searches (n = next index) and `selezione <date> (<n>)` for
selections (n = number of selected rows). Enter confirms, Escape / backdrop cancels.

**Column reorder** — set `reorderColumns => true` to make toggleable column headers draggable
(native HTML5 drag-and-drop). `gridview-column-order` reorders the `<th>` and every row's `<td>` by
their `data-col-key` (the column attribute) and persists the order via the preference provider
(bucket `columnOrder`), re-applying it on connect — so it survives Turbo refreshes. Purely cosmetic
(client-side); structural columns (checkbox/actions) stay put.
