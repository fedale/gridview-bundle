# Piano di implementazione — Infinite scroll (server-side)

> Documento di handoff per un'altra sessione. Autocontenuto: contiene contesto,
> decisioni già prese, file da toccare con ancore reali, protocollo, edge case e
> verifica. Repo: `fedale/gridview-bundle` (bundle Symfony). Lavorare su un branch
> dedicato, es. `feat/infinite-scroll`.

## 1. Obiettivo e decisioni già prese

Aggiungere il **caricamento progressivo server-side** come alternativa alla
paginazione numerica: quando l'utente arriva in fondo alla tabella, la pagina
successiva viene caricata e **le righe vengono appese** al `<tbody>` corrente.

Decisioni concordate (NON rimetterle in discussione):

- **Modello server-side**, non virtual scroll client-side. Si riusa l'offset/limit
  esistente (`page` query param). Niente "tutti i dati in pagina".
- **Progressive enhancement**: di default resta la paginazione numerica. L'infinite
  scroll è opt-in per griglia. Senza JS deve restare un fallback funzionante.
- **Protocollo via Turbo Stream `append`** (coerente con il resto del bundle, che è
  Turbo-first). La risposta "solo righe" è un `text/vnd.turbo-stream.html`.
- **Sempre presente un bottone "Carica altri"** come fallback reale e per
  accessibilità (tastiera/screen-reader); l'`IntersectionObserver` è l'enhancement
  che lo aziona automaticamente.

## 2. Come funziona oggi il rendering (fatti verificati)

- Action: `src/Controller/AbstractGridController.php` → `index()` (`#[Route('', name:'index', methods:['GET'])]`)
  chiama `buildGridview()->renderGrid($this->config('indexTemplate'))`.
- `src/Grid/Gridview.php` → `renderGrid()` (≈ riga 433):
  - costruisce `urlState`, il form filtri, e i parametri template:
    `gridview`, `columns`, `models` (= `dataProvider->getData()`, **la pagina corrente**,
    derivata dal `page` query param), `pagination`.
  - sceglie il template: se `useTurbo && request ha header 'Turbo-Frame'` →
    `@FedaleGridview/gridview/_grid.html.twig` (parziale), altrimenti il full
    `index.html.twig`.
  - **Punto chiave:** `getData()` rispetta già il `page` richiesto. Quindi una GET
    `?page=2` produce già i modelli di pagina 2 — basta renderizzarli come stream
    invece del frame intero.
- Paginazione: `src/Pagination/Pagination.php`. `page` param (`getPageParamName()`),
  pagina **zero-based** internamente (`getCurrentPage()`), `getPageCount()`,
  `getPageSize()`, `getOffset()/getLimit()`. Nei template si usa
  `gridview.urlState.withPage(n)` (1-based) e `pagination.pageCount`.
- Template stream esistente da imitare: `templates/gridview/sections/_stream.html.twig`
  (usa `<turbo-stream action="replace" target="gridview-{key}">` + `_grid.html.twig`).
- `templates/gridview/sections/tbody.html.twig`: oggi è `<tbody>` + loop righe. Il
  `<tbody>` **non ha id** e il loop riga contiene già la control-cell responsive e
  `data-priority` (vedi nota responsive in §6).
- `templates/gridview/sections/pagination.html.twig`: UI numerica; usa
  `pagination.pageCount`, `gridview.urlState.page`, `path(_currentRoute, urlState.withPage(i))`,
  `turboAction = useTurbo ? 'advance' : null`.
- Wiring controller JS: in `templates/gridview/_grid.html.twig` c'è l'array
  `gvControllers` con merge condizionali (pattern già usato per
  `gridview-column-order`, `gridview-responsive`). La demo registra ogni controller
  a mano in `repara-demo/app/assets/bootstrap.js` (Webpack Encore).

## 3. Opzione di attivazione

Nuova opzione di griglia, sotto `options.pagination`:

```php
'options' => [
    'pagination' => [
        'mode' => 'infinite',     // 'numeric' (default) | 'infinite'
        // opzionali:
        'infiniteRootMargin' => '300px',  // prefetch prima di toccare il fondo
    ],
],
```

Le opzioni di griglia sono un bag libero passato a `setOptions()` (vedi
`AbstractGridController::buildGridview()` ≈ riga 197: `array_replace([...defaults...],
crudOptions(), config('options'))`). Nei template si legge
`gridview.options.pagination.mode|default('numeric')`. Verificare i default di
`pagination` (già esistono `pageSelect`, `pageSelectThreshold`) e aggiungervi
`mode => 'numeric'` come default.

## 4. Implementazione — passi

### 4.1 Estrarre il loop righe in un partial riusabile
Creare `templates/gridview/sections/_rows.html.twig` con **solo** il `{% for row in models %}…{% endfor %}`
(le `<tr>`, comprese control-cell responsive e `data-priority` — spostare 1:1 da
`tbody.html.twig`, senza il branch `{% else %}` dell'empty state).

Modificare `tbody.html.twig` in:
```twig
<tbody id="gv-tbody-{{ gridview.key }}">
{% if models is empty %}
    {% include gridview.layoutTemplate('empty') %}
{% else %}
    {% include '@FedaleGridview/gridview/sections/_rows.html.twig' %}
{% endif %}
</tbody>
```
> L'`id` sul `<tbody>` è il **target** dell'append. Tenere in sync i due usi del
> partial (tabella iniziale + stream).

### 4.2 Template stream "solo righe"
Creare `templates/gridview/sections/_rows_stream.html.twig`:
```twig
<turbo-stream action="append" target="gv-tbody-{{ gridview.key }}">
    <template>
        {% include '@FedaleGridview/gridview/sections/_rows.html.twig' %}
    </template>
</turbo-stream>
{# Aggiorna lo stato del sentinel/footer: pagina corrente e se è l'ultima. #}
<turbo-stream action="replace" target="gv-infinite-{{ gridview.key }}">
    <template>
        {% include '@FedaleGridview/gridview/sections/infiniteScroll.html.twig' %}
    </template>
</turbo-stream>
```

### 4.3 Sezione infinite (sentinel + load-more + stato)
Creare `templates/gridview/sections/infiniteScroll.html.twig`. Renderizzata al posto
della paginazione numerica quando `mode == 'infinite'`. Contiene un contenitore con
`id="gv-infinite-{{ gridview.key }}"` che porta:
- i `data-*` per il controller: pagina corrente (`gridview.urlState.page`),
  ultima pagina (`pagination.pageCount`), URL prossima pagina
  (`path(_currentRoute, gridview.urlState.withPage(gridview.urlState.page + 1))`),
  rootMargin;
- un sentinella `<div data-gridview-infinite-scroll-target="sentinel">`;
- un `<button data-action="gridview-infinite-scroll#loadMore">Carica altri</button>`
  (fallback), nascosto se siamo all'ultima pagina;
- opzionale: "Mostrati X di Y" (da `pagination.totalCount`/`pageCount`).

In `pagination.html.twig` aggiungere un branch in cima:
```twig
{% if gridview.options.pagination.mode|default('numeric') == 'infinite' %}
    {% include '@FedaleGridview/gridview/sections/infiniteScroll.html.twig' %}
{% else %}
    {# … UI numerica esistente … #}
{% endif %}
```
> Così il token di layout resta `{pagination}` e non serve cambiare i layout.

### 4.4 Risposta server "solo righe"
In `src/Grid/Gridview.php::renderGrid()`, **prima** della scelta del template
(≈ riga 471), aggiungere il branch rows-only:
```php
$isRowsRequest = $request->query->getBoolean('_rows'); // o header 'X-Gridview-Rows'
if ($this->options['useTurbo'] && $isRowsRequest) {
    return new Response(
        $this->twig->render('@FedaleGridview/gridview/sections/_rows_stream.html.twig', $parameters),
        200,
        ['Content-Type' => 'text/vnd.turbo-stream.html'],
    );
}
```
`$parameters` contiene già `gridview/columns/models/pagination` per la pagina
richiesta (il `page` param guida `getData()`). Nessuna nuova action: si riusa
`index()` con `?page=N&_rows=1`.
> Decidere `_rows` query param vs header custom. Il query param è più semplice da
> generare lato Stimulus (`withPage` produce già l'URL) e da testare via curl.

### 4.5 Controller Stimulus
Creare `assets/controllers/gridview-infinite-scroll_controller.js`:
- `static targets = ['sentinel']`
- `static values = { url: String, page: Number, lastPage: Number, rootMargin: String }`
- `connect()`: se `page >= lastPage` non fare nulla (niente sentinel). Altrimenti
  crea un `IntersectionObserver(rootMargin)` sul `sentinelTarget` → `loadMore()`.
- `loadMore()`:
  - guard `this._loading` (no richieste concorrenti) e `page < lastPage`.
  - `fetch(url, { headers: { Accept: 'text/vnd.turbo-stream.html' } })` dove `url`
    è già `?page=(page+1)&_rows=1`.
  - `const html = await res.text(); window.Turbo.renderStreamMessage(html);`
    (lo stream fa append righe + replace della sezione infinite, che riporta la nuova
    `page` e il nuovo `url` → al prossimo giro si avanza da solo).
  - dopo il render, **ri-emettere** un evento per la responsività (vedi §6).
  - gestire errori (mostra il bottone "Carica altri", non rompere la pagina).
- `disconnect()`: `observer.disconnect()`.
> Nota: dopo il `renderStreamMessage`, la vecchia sezione `#gv-infinite-{key}` viene
> sostituita → il controller (che vive sul `[data-gridview]`, non sulla sezione)
> resta connesso. Il sentinel è dentro la sezione sostituita: ri-osservarlo nel
> callback dello stream o ricreare l'observer ad ogni `loadMore`. Valutare:
> mettere i `values` sul `[data-gridview]` o leggerli dal nuovo nodo dopo il replace.
> **Consiglio:** far leggere al controller `page/lastPage/url` dal DOM della sezione
> infinite ad ogni `loadMore` (query `#gv-infinite-{key}`), così lo stato segue il
> nodo ricreato senza dipendere dai Values cacheati.

### 4.6 Wiring
- In `templates/gridview/_grid.html.twig`, accanto agli altri merge:
  ```twig
  {% if gridview.options.pagination.mode|default('numeric') == 'infinite' %}{% set gvControllers = gvControllers|merge(['gridview-infinite-scroll']) %}{% endif %}
  ```
- Nella demo: registrare il controller in `repara-demo/app/assets/bootstrap.js`
  (import + `app.register('gridview-infinite-scroll', …)`) e ricompilare
  (`cd repara-demo/app && yarn build`).

### 4.7 CSS
In `assets/styles/gridview.scss` (in fondo): stile del sentinel (altezza ~1px,
invisibile), del bottone "Carica altri", e un eventuale spinner durante il fetch
(riusare `.gv-spinner` esistente). Usare i token `--gv-*`.

## 5. Protocollo richiesta/risposta (riassunto)

```
GET /gridview/<id>?page=2&_rows=1
Accept: text/vnd.turbo-stream.html
→ 200 text/vnd.turbo-stream.html
  <turbo-stream action="append"  target="gv-tbody-<id>"> … <tr>…</tr> … </turbo-stream>
  <turbo-stream action="replace" target="gv-infinite-<id>"> … nuova sezione (page=2) … </turbo-stream>
```

## 6. Edge case e interazioni (IMPORTANTI)

- **Sort/filtro**: il form filtri sostituisce l'intero turbo-frame
  (`_grid.html.twig`, `data-turbo-action=replace`) → riparte da pagina 1 con stato
  pulito. Il controller si ricollega (è dentro il frame) e rilegge `page=1` dal DOM.
  Nessun lavoro extra, ma **verificare** che dopo un filtro lo scroll riparta da 1.
- **URL/deep-link**: durante l'infinite scroll **non** avanzare il `page` nell'URL
  (sarebbe ambiguo). Filtri/sort continuano ad aggiornare l'URL via form. Documentare.
- **Ultima pagina**: quando `page >= pageCount`, rimuovere sentinel e nascondere il
  bottone (lo stream di replace rende la sezione senza sentinel).
- **Responsive (`gridview-responsive`)**: l'`_apply()` del responsive aggiunge la
  classe `gv-resp-collapsed` alle `th`/`td` per colonna; **le righe appese non hanno
  quella classe** e non risultano collassate. Integrazione necessaria: dopo
  l'append, far ri-eseguire `_apply()`. Opzioni:
  1. l'infinite controller dispatcha `this.dispatch('rows-appended')` /
     `window` CustomEvent `gridview:rows-appended`;
  2. `gridview-responsive` ascolta e richiama `_schedule()`.
  Aggiungere il listener in `gridview-responsive_controller.js`. (Se responsive è
  off, è irrilevante.)
- **Selezione (`gridview-selection`)**: usa `sessionStorage` per gridId; i checkbox
  delle righe appese funzionano, ma "seleziona visibili" cambia significato man mano
  che si caricano righe. Verificare e, se serve, ri-applicare lo stato di selezione
  alle nuove righe dopo l'append.
- **`maxQueryLength` / dimensione frame**: l'append via stream non soffre del limite
  del turbo-frame. OK.
- **Dati che cambiano tra una pagina e l'altra**: l'offset pagination può
  duplicare/saltare righe se vengono inserite/cancellate durante lo scroll.
  Accettabile per la v1; annotare come limite noto.
- **Turbo presente**: serve `window.Turbo` (la demo importa Turbo). Se `useTurbo` è
  false, l'infinite scroll non si attiva (il branch in `renderGrid` lo richiede).

## 7. Verifica

SSR / build (automatizzabile):
1. Abilitare `mode: 'infinite'` su una griglia demo (es. CustomerController:
   `'options' => ['pagination' => ['mode' => 'infinite']]`).
2. `cd repara-demo/app && yarn build` e `docker exec repara-demo-app-1 php bin/console cache:clear`.
3. `curl -sk https://localhost/gridview/customer` → la sezione paginazione deve
   contenere `id="gv-infinite-customer"`, il sentinel e il bottone; il `<tbody>` deve
   avere `id="gv-tbody-customer"`; `data-controller` deve includere
   `gridview-infinite-scroll`.
4. `curl -sk -H 'Accept: text/vnd.turbo-stream.html' 'https://localhost/gridview/customer?page=2&_rows=1'`
   → deve restituire `text/vnd.turbo-stream.html` con `<turbo-stream action="append"
   target="gv-tbody-customer">` contenente le `<tr>` di pagina 2, e il replace della
   sezione infinite con `page=2`.
5. Verificare che il controller sia nel bundle compilato (`grep` in
   `repara-demo/app/public/build/app.*.js`).

Runtime (manuale — non c'è browser headless installato): aprire la griglia,
scrollare in fondo → le righe si appendono; arrivati all'ultima pagina il sentinel
sparisce; il bottone "Carica altri" funziona anche disabilitando l'observer.

## 8. Fuori scope (v1) / possibili evoluzioni

- Keyset/cursor pagination per offset molto grandi (perf su dataset enormi).
- "Torna su" / ripristino posizione di scroll sul back.
- Conteggio live "Mostrati X di Y" con aggiornamento per-append (semplice da
  aggiungere nella sezione infinite).

## 9. Checklist file

- [ ] `templates/gridview/sections/_rows.html.twig` (nuovo)
- [ ] `templates/gridview/sections/tbody.html.twig` (id + include _rows)
- [ ] `templates/gridview/sections/_rows_stream.html.twig` (nuovo)
- [ ] `templates/gridview/sections/infiniteScroll.html.twig` (nuovo)
- [ ] `templates/gridview/sections/pagination.html.twig` (branch mode)
- [ ] `templates/gridview/_grid.html.twig` (merge controller)
- [ ] `src/Grid/Gridview.php` (branch rows-only in renderGrid)
- [ ] default `pagination.mode` (dove si definiscono i default options)
- [ ] `assets/controllers/gridview-infinite-scroll_controller.js` (nuovo)
- [ ] `assets/controllers/gridview-responsive_controller.js` (listener rows-appended)
- [ ] `assets/styles/gridview.scss` (stili sentinel/bottone/spinner)
- [ ] demo: `bootstrap.js` + enable su una griglia + `yarn build`
- [ ] docs: nuova sezione "Infinite scroll" + voce controller + opzione `pagination.mode`
```
