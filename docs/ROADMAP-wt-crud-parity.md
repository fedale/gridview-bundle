# FedaleGridView → parità feature: Roadmap implementativa (Symfony/Twig + Hotwired)

> **Documento autosufficiente.** È scritto per essere **eseguito da un Claude Code (o uno
> sviluppatore) su un altro account/macchina che NON ha accesso al progetto di riferimento
> `wt-crud`**. Tutte le specifiche delle feature target sono **incorporate nel testo** (non
> rimandano mai a "vedi wt-crud"). Si lavora sullo **stesso repo `repara`** (cartella
> `FedaleGridviewBundle/` + cartella `app/` dell'app Symfony ospite), quindi tutti i path ai
> file citati sono validi. **`wt-crud` non serve essere copiato.**
>
> I path nei link sono relativi alla root del repo `repara`.

---

## Context

`FedaleGridView` (`FedaleGridviewBundle/`) è un bundle Symfony 6.x che genera GridView in
stile Yii2 (paginazione, sort, filtri, colonne tipizzate) renderizzando **HTML server-side
puro**: ogni interazione (cambio pagina, ordinamento, filtro) è un link che ricarica l'intera
pagina. **Zero JavaScript, zero Hotwired.**

Il **target di parità** è una griglia CRUD enterprise-grade (ispirata a `wt-crud`, una libreria
Angular analizzata a parte) con: inline editing, batch update, dialog CRUD generati, export,
salvataggio ricerche/selezioni, reorder/visibilità colonne con persistenza, relation-select,
custom renderer, theming. **Le specifiche concrete di ciascuna feature sono incorporate nelle
fasi sotto**, così il piano è eseguibile senza accedere al progetto di riferimento.

**Obiettivo:** portare FedaleGridView il più vicino possibile a quel target **rimanendo in
Symfony/Twig**, aggiungendo l'interattività oggi assente.

**Scoperta chiave dell'analisi:** l'app ospite (`app/`) ha **già tutto lo stack Hotwired
pronto** — `@hotwired/turbo`, `@hotwired/stimulus`, `@symfony/stimulus-bridge` in
[app/package.json](../../app/package.json), `symfony/ux-twig-component` +
`symfony/webpack-encore-bundle` in [app/composer.json](../../app/composer.json), con
[app/assets/bootstrap.js](../../app/assets/bootstrap.js) che auto-registra i controller Stimulus.
Manca solo l'integrazione **dentro il bundle**.

**Decisioni di progetto:**
- Roadmap implementativa completa (gap analysis come premessa).
- Priorità: **Interattività Hotwired**, **Inline editing + bulk**, **Export + preferenze utente**.
- Architettura: **Symfony UX nativo** (Turbo Frames + Live/Twig Component), idiomatico Symfony 6.4.

---

## Gap analysis (sintesi della "distanza")

La colonna "Target di parità" descrive il comportamento da raggiungere (non richiede di
consultare codice esterno).

| Area | FedaleGridView (oggi) | Target di parità | Gap |
|---|---|---|---|
| Interattività | Full page reload (link) | Aggiornamenti parziali senza reload (Turbo Frame) | **Alto** |
| Tipi colonna | data, action, boolean, checkbox, serial | + text, html, number, date, image, json, list, selection, component | Medio |
| Sort / Paginazione | ✅ ([Sort.php](../src/Component/Sort.php), [Pagination.php](../src/Component/Pagination.php)) | ✅ (riuso, +parziale via Turbo) | Basso |
| Filtri | text/boolean/choice + operatori ricchi ([SearchForm.php](../src/Service/SearchForm.php)) | per-colonna + global search + relation | Medio |
| Custom render | `value` closure + `twigFilter` | pipeline `valueGetter → formatter → renderer` | Medio |
| Inline editing | ✅ click/dblclick → editor (dal control) → save + validazione | click/dblclick → editor inline → autosave + feedback | ✅ **Fatto** (Fase 3) |
| Selezione multipla / bulk | ✅ select-all/all-mode + barra azioni + batch update dialog | select-all + barra azioni + batch update dialog | ✅ **Fatto** (Fase 2) |
| CRUD dialog (add/edit/clone/delete) | ✅ form generati da config colonne, modale Turbo, validazione | form generati da config colonne + delete con recap | ✅ **Fatto** (Fase 4) |
| Export CSV/Excel | ✅ CSV nativo + registry estensibile (ExporterInterface) | ✅ | ✅ **Fatto** (Fase 5) |
| Salva ricerche/selezioni | ❌ | ✅ provider pluggable (localStorage) | **Alto** |
| Show/hide + reorder colonne | ✅ show/hide UI + reorder drag-drop + persistenza | ✅ UI + drag-drop + persistenza | ✅ **Fatto** (Fase 6) |
| Relation select | gestito a mano in closure | ✅ componente dedicato | Medio |
| Theming | classi Bootstrap via CDN, no asset | CSS custom properties, temi light/dark | Medio |
| i18n | cartella `translations/` quasi inutilizzata | label/messaggi configurabili | Medio |
| Architettura | builder + service + dataprovider (accoppiato a Doctrine) | core UI-free + adapter | Medio |

**Stima distanza complessiva:** le fondamenta (builder, sort, paginazione, filtri, dataprovider
Doctrine) sono solide e ben strutturate; mancano interamente i layer "interattivo" e
"CRUD/preferenze". La distanza è quindi **media-alta**, ma colmabile per incrementi senza
riscrivere il core.

---

## Approccio architetturale

Il flusso attuale renderizza una pagina intera ([index.html.twig](../templates/gridview/index.html.twig)
estende `base.html.twig` con CSS/JS da CDN). Lo trasformiamo in modo **non-distruttivo**:

1. **Turbo Frame** che avvolge la griglia → paginazione/sort/filtri aggiornano solo il frame,
   niente full reload. Nessuna modifica al backend logico: il controller risponde già con HTML.
2. **Stimulus controllers** del bundle (in `FedaleGridviewBundle/assets/`) registrabili dall'app
   ospite tramite `app.register(...)` in [app/assets/bootstrap.js](../../app/assets/bootstrap.js).
3. **Symfony UX TwigComponent / LiveComponent** per le parti che richiedono stato server-side
   reattivo (inline edit, filtri live, batch).
4. Il bundle deve **distribuire i propri asset** (oggi `public/` è vuoto): aggiungere
   `assets/controllers/*.js` + `package.json` del bundle e documentare la registrazione lato app.

> Nota: i template del bundle estendono `base.html.twig` — per essere riusabili come frame
> andranno separati i partial "frame-only" dal layout completo.

---

## Roadmap a fasi

### Fase 0 — Fondamenta asset & Turbo (abilita tutto il resto) — *priorità 1*
- Creare `FedaleGridviewBundle/assets/` con `package.json` e cartella `controllers/`.
- Aggiornare [services.xml](../config/services.xml) e la classe bundle per esporre gli asset
  (AssetMapper/Encore).
- Avvolgere la griglia in `<turbo-frame id="gridview-{{ key }}">` e far sì che i link di
  sort/paginazione abbiano `data-turbo-frame`. Refactor
  [index.html.twig](../templates/gridview/index.html.twig): estrarre `_grid.html.twig` (solo
  tabella+paginatore, no layout) renderizzabile sia standalone sia dentro il frame.
- Endpoint controller che, su richiesta Turbo, ritorna solo il partial del frame (il
  `GridviewService` sa già renderizzare un template arbitrario via `renderGrid()`).
- **Verifica:** cambio pagina/sort aggiorna solo il frame; URL aggiornato via Turbo; fallback
  no-JS funzionante.

### Fase 1 — Filtri live & global search — *priorità 1*
- Stimulus controller `gridview-filter` (debounce input → submit nel turbo-frame). Riusare il
  pattern di [filter-form_controller.js](../../app/assets/controllers/filter-form_controller.js)
  e [modal-form_controller.js](../../app/assets/controllers/modal-form_controller.js) già nell'app.
- Refactor [_filter.html.twig](../templates/gridview/_filter.html.twig) (attualmente HTML
  malformato: chiusura `</form>` manuale vs `form_end`) per integrarsi col frame.
- Aggiungere global search (un singolo campo che applica OR sui campi searchable via
  [SearchForm.php](../src/Service/SearchForm.php), che già supporta `orFilterWhere`).
- **Verifica:** digitando in un filtro la griglia si aggiorna senza reload pagina.

### Fase 2 — Selezione multipla + bulk actions — *priorità 1* — ✅ **IMPLEMENTATA**

> **Stato:** completata e verificata. Implementazione: `CheckboxColumn` + `gridview-selection`
> (selezione cross-pagina, all-mode, header indeterminate, conteggio, barra `{bulkBar}`); bulk delete
> e batch update via modale (riusa `gridview-crud`); `GridFormBuilder::buildBatchForm` +
> `GridCrudHandler::bulkDelete/renderBulkDeleteConfirm/createBatchForm/renderBatchForm/applyBatch`;
> colonne `batchUpdate:true`. All-mode (`all=1`) risolto server-side ri-eseguendo il search filtrato.
> Reference: `app/src/Controller/Gridview/UserController.php` (azioni `bulk/delete`, `bulk/update`).

Spec originali (riferimento):
- Potenziare [CheckboxColumn.php](../src/Column/CheckboxColumn.php): emettere checkbox con
  valore PK + checkbox "select-all" in header.
- Stimulus controller `gridview-selection`: gestione select-all/indeterminate, conteggio
  selezionati, barra azioni bulk.
- Backend: endpoint bulk (ids[] → azione). Modellare nel builder una config `bulkActions`:
  - **Spec target — batch update dialog:** dato un set di PK selezionate, una dialog mostra i
    campi modificabili (risolti dalle colonne marcate `batchUpdate: true`, con type inference:
    testo→`<input>`, relazione→`<select>`, boolean→`<checkbox>`). Submit → callback
    `onSubmit(ids[], changes{})` che persiste e ritorna esito. Config opzionale `title`,
    `applyLabel`.
- Dialog "batch update" (vedi Fase 4 per il meccanismo dialog).
- **Verifica:** selezione righe, select-all, esecuzione azione bulk su un set.

### Fase 3 — Inline editing — *priorità 1* — ✅ **IMPLEMENTATA**

> **Stato:** completata e verificata. Scelta: **Stimulus `gridview-inline-edit` + endpoint** (no
> LiveComponent). Colonna `editable: true|['trigger'=>...]` (richiede un `control`); la cella mostra
> un editor a campo singolo costruito dal `control` → **riusa la validazione** (NotBlank/UniqueEntity).
> Endpoint unico `/inline/{id}/{field}` (GET editor / POST save), **solo colonne editable** (404
> altrimenti). `GridCrudHandler::renderInlineEditor/saveInline`; Enter salva, Escape annulla, una
> cella per volta. Display post-save via `stringifyValue`. Reference:
> `app/src/Controller/Gridview/UserController.php`.

Spec originali (riferimento):
- **LiveComponent** (`symfony/ux-live-component`, da aggiungere) per la cella editabile: click →
  input → submit → persiste via Doctrine → re-render cella. In alternativa Stimulus
  `gridview-inline-edit` + endpoint PATCH se si vuole evitare LiveComponent.
- Estendere [AbstractColumn.php](../src/Column/AbstractColumn.php) con una config `editable`.
  - **Spec target — `editable`:** o `true` o un oggetto
    `{ edit: bool, type: 'text'|'number'|'date'|'boolean'|'select', cssClass?, msgOK?, msgFail?, spinner?: bool }`.
    Trigger configurabile `'click' | 'dblclick'` (default `click`). Una sola cella attiva per
    volta. Enter = salva, Escape = annulla. Boolean editabile = toggle che salva al click.
- Feedback ottimistico (spinner durante save + messaggio esito ~2s), gate per-riga
  `canEditRow(row): bool`.
- **Verifica:** doppio click su cella editabile, modifica persistita, errore gestito.

### Fase 4 — CRUD dialog (add/edit/clone/delete) — *priorità 2* — ✅ **IMPLEMENTATA**

> **Stato:** completata e verificata end-to-end. Implementazione effettiva (può divergere dalle spec
> sotto, che restano come riferimento storico):
> - Form generata dai `control` delle colonne (`ControlTypeRegistry` + `GridFormBuilder`), dialog via
>   `gridview-crud` Stimulus controller + Turbo Stream. Modalità add/edit/clone.
> - **Validazione**: `control.required`→NotBlank, `control.unique`→UniqueEntity (messaggi
>   personalizzabili), `constraints` escape hatch, rete sicurezza su Unique/ForeignKey violation;
>   validazione live opzionale (`gridview-form-validate`) con check univocità async.
> - **Per-mode**: `control.modes`. **Layout override**: view Twig con token `{ attribute }`.
> - **Delete con recap**: `showInDeleteConfirm` + `renderDeleteConfirm` (svuota le M2M owning prima
>   del remove). **Clone**: deep-copy delle collection to-many.
> - Servizi bundle: `GridFormBuilder`, `GridCrudHandler` (+ `existsWithValue`, `deleteTokenId`),
>   `CrudButton`. Opzione `routeName` per i link sort/paginazione sotto POST CRUD.
> - Reference: `app/src/Controller/Gridview/UserController.php`. Doc: sezione "CRUD forms" in
>   [index.md](index.md).

Spec originali (riferimento):
- TwigComponent + Turbo Frame `modal`: riusare il pattern di
  [modal-form_controller.js](../../app/assets/controllers/modal-form_controller.js) (già fa fetch
  del form in modale).
- **Spec target — form generato:** ogni colonna può avere una config `control`
  `{ type: 'text'|'number'|'date'|'boolean'|'relation'|'image'|'html'|'hidden', required?, args? }`
  da cui il dialog costruisce un Symfony FormType. Modalità: `add` (form vuoto, POST), `edit`
  (precompilato, PUT), `clone` (precompilato ma POST come nuovo, con callback opzionale
  `onCloneRow(row)` per ripulire campi unici).
- **Spec target — delete con recap:** prima di cancellare, la dialog mostra un riepilogo dei
  campi della riga; ogni colonna può dichiarare `showInDeleteConfirm: true | { label, order }`.
  Fallback: prime colonne testuali visibili.
- **Verifica:** add/edit/clone/delete da modale senza lasciare la pagina; la griglia si aggiorna
  via Turbo Stream.

### Fase 5 — Export — *priorità 2* — ✅ **IMPLEMENTATA**

> **Stato:** completata. Architettura **estensibile**: `ExporterInterface` + `GridExporterRegistry`
> (tagged_iterator + `registerForAutoconfiguration` → il client aggiunge un export implementando
> l'interfaccia, zero config). Built-in `CsvExporter` (nativo). Rispetta filtri/sort (riusa
> `EntityDataProvider::getAllData`, no paginazione) e colonne `exportable`/visibili
> (`Gridview::getExportColumns`). Token `{export}` (menu formati) + rotta app che delega al registry
> via `?format=<key>`. XLSX non incluso (basta un exporter app con phpspreadsheet). Demo:
> `app/src/Export/JsonExporter.php`.

Spec originali (riferimento):
- Servizio `GridviewExporter` (CSV nativo PHP; XLSX via `phpoffice/phpspreadsheet` se accettata
  la dipendenza).
- Rispetta colonne visibili/`exportable` (flag già presente in
  [AbstractColumn.php](../src/Column/AbstractColumn.php)) e i filtri/sort correnti (riusa il
  `DataProvider` senza paginazione).
- Pulsante export nella toolbar.
- **Verifica:** export del dataset filtrato in CSV/XLSX.

### Fase 6 — Preferenze utente (colonne + ricerche/selezioni salvate) — *priorità 2* — ✅ **IMPLEMENTATA**

> **Fatto:** salva ricerche (querystring) e salva selezioni (set di PK); **reorder colonne**
> drag-drop nativo (`gridview-column-order`, opzione `reorderColumns`) con persistenza; show/hide
> colonne già presente (`gridview-visibility`). Persistenza client-side pluggable
> (`assets/preferences.js`, default localStorage, override via `window.gridviewPreferenceProvider`),
> scope per-rotta, buckets `searches`/`selections`/`columnOrder`. Controller `gridview-saved-search`
> + estensione di `gridview-selection`; token `{savedSearch}` e voci nel dropdown del CheckboxColumn.
> **In più (fuori spec originale):** CRUD anche in pagina intera con `crud.mode` modal/page/custom +
> URL semantiche (new/update/clone); modale di naming custom.

Spec originali (riferimento):
- **Show/hide + reorder colonne:** Stimulus `gridview-column-selector` (dropdown checkbox +
  drag-drop). Persistenza: localStorage di default + interfaccia `PreferenceProviderInterface`
  (metodi `load(scope)`, `save(scope, prefs)`) per consentire backend custom (es. DB).
- **Salva ricerche:** serializzare lo stato filtri/sort (già esprimibile in querystring) sotto un
  nome; UI per salva/lista/applica. Provider pluggable (localStorage default), scope per-rotta.
- **Salva selezioni:** set di PK persistito sotto un nome e ri-applicato al reload (con limite
  max-id configurabile, default ~5000). Provider pluggable, scope per-rotta.
- **Verifica:** nascondi colonna/riordina → persiste tra reload; salva ed applica una ricerca.

### Fase 7 — Rifiniture: tipi colonna, theming, i18n — *priorità 3* — ✅ **IMPLEMENTATA**

> **Stato:** completata. Piano di dettaglio in [FASE7-rifiniture-plan.md](FASE7-rifiniture-plan.md).
> - **A — type system + pipeline:** catalogo tipi ispirato a Twenty CRM con ereditarietà reale PHP
>   (`currency` extends `number`, `rating`/`multiSelect`/`badge` extend `select`, ...): text/uuid/
>   html/richText/json/link/url/email/image, number/currency/percent, boolean, date/datetime,
>   select/multiSelect/rating/badge, list, relation (`src/Column/Type/`). Pipeline a 3 stadi
>   `getRawValue → format → render` (HTML-safe via `Twig\Markup`), `ColumnTypeRegistry` con
>   `registerForAutoconfiguration` → **tipi custom dell'app a zero config**. Back-compat: `value`
>   closure e `twigFilter` invariati. Compositi (fullName/address/phone) progettati ma rimandati.
> - **B — theming:** token/classi `--gv-*` per i nuovi tipi (`.gv-num/.gv-img/.gv-list/.gv-rating/
>   .gv-badge/.gv-json`), light/dark; audit CDN (nessuna dipendenza). Docs "Theming" in
>   [index.md](index.md).
> - **C — i18n:** UI chrome tradotta via dominio `GridviewBundle` (it/en); boolean glifo neutro.
>   Docs "Internationalization" in [index.md](index.md).

Spec originali (riferimento):
- Nuovi tipi colonna per parità: `number`, `date`, `image`, `html`, `list` (estendendo
  [AbstractColumn.php](../src/Column/AbstractColumn.php), pattern di
  [DataColumn.php](../src/Column/DataColumn.php)).
- Pipeline render a 3 stadi `valueGetter → formatter → renderer` (oggi solo `value` closure +
  `twigFilter`): `valueGetter(row)` estrae il valore grezzo, `formatter(value, row)` lo trasforma
  per display, `renderer` produce l'HTML (template Twig / closure / componente).
- Theming via CSS custom properties (`--gv-*`) + temi light/dark; rimuovere dipendenza CDN da
  [index.html.twig](../templates/gridview/index.html.twig), spostare gli asset nel bundle.
- Completare i18n usando la cartella `translations/` esistente.

---

## File chiave coinvolti

**Bundle — backend**
- [GridviewBuilder.php](../src/Grid/GridviewBuilder.php) / [Gridview.php](../src/Grid/Gridview.php)
  — aggiungere config per editable/bulk/export/preferenze.
- [AbstractColumn.php](../src/Column/AbstractColumn.php) + classi colonna — nuovi flag e tipi.
- [SearchForm.php](../src/Service/SearchForm.php) — global search.
- [EntityDataProvider.php](../src/DataProvider/EntityDataProvider.php) — riuso per export (no
  paginazione).
- [services.xml](../config/services.xml) + [FedaleGridviewBundle.php](../src/FedaleGridviewBundle.php)
  — nuovi servizi, config asset, nuove opzioni di
  [Configuration.php](../src/DependencyInjection/Configuration.php).
- [composer.json](../composer.json) — dipendenze UX (live-component, eventuale phpspreadsheet);
  allineare il vincolo PHP/Symfony con l'app (PHP 8.3 / Symfony 6.4).

**Bundle — templates**
- [index.html.twig](../templates/gridview/index.html.twig) → split layout/frame; nuovo
  `_grid.html.twig`.
- [_filter.html.twig](../templates/gridview/_filter.html.twig) (fix HTML),
  [_header.html.twig](../templates/gridview/_header.html.twig),
  [_body.html.twig](../templates/gridview/_body.html.twig),
  [paginator.html.twig](../templates/gridview/paginator.html.twig) — attributi Turbo.

**Bundle — asset (nuovi)**
- `FedaleGridviewBundle/assets/package.json` +
  `assets/controllers/{gridview-filter,gridview-selection,gridview-inline-edit,gridview-column-selector}_controller.js`.

**App ospite — riferimenti/integrazione**
- [app/assets/bootstrap.js](../../app/assets/bootstrap.js) — registrazione controller del bundle.
- Pattern riusabili: [filter-form_controller.js](../../app/assets/controllers/filter-form_controller.js),
  [modal-form_controller.js](../../app/assets/controllers/modal-form_controller.js).
- [BundleController.php](../../app/src/Controller/BundleController.php) — controller demo da
  estendere per testare ogni fase.

---

## Verifica end-to-end

1. **Build asset:** `cd app && yarn encore dev` (lo stack Encore/Stimulus è già configurato).
2. **Avvio app:** stack Docker (`docker-compose up`), poi aprire `/bundle/gridview` (rotta
   `app_gridview` in [BundleController.php](../../app/src/Controller/BundleController.php)).
3. **Per fase, controllare manualmente:**
   - Fase 0/1: sort/pagina/filtro aggiornano solo il `turbo-frame` (Network tab → richiesta
     Turbo, non full document); funziona anche con JS disattivato (fallback link).
   - Fase 2: selezione multipla + bulk su un sottoinsieme.
   - Fase 3: inline edit persiste e gestisce errori.
   - Fase 4: modali add/edit/clone/delete + refresh griglia.
   - Fase 5: file CSV/XLSX coerente con filtri attivi.
   - Fase 6: preferenze colonne e ricerche salvate sopravvivono al reload.
4. **Regressione no-JS:** la griglia base resta navigabile senza JavaScript (progressive
   enhancement), garantendo che Turbo sia un miglioramento, non un requisito.
5. Aggiungere test (la cartella `tests/` esiste) almeno per: builder con nuove opzioni, export, e
   serializzazione preferenze.

---

## Nota per chi esegue questo piano su un altro account

Questo documento è **autosufficiente**: descrive sia lo stato attuale di FedaleGridView (con path
reali) sia il comportamento-target di ogni feature. **Non serve accedere al progetto `wt-crud`.**
Tutti i path citati (`../src/...`, `../../app/...`) si riferiscono al repo `repara`, che deve
essere presente. Prima di iniziare una fase, leggere i file del bundle indicati per allinearsi
all'implementazione corrente; le "Spec target" nelle fasi definiscono cosa costruire.
