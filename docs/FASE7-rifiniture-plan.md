# Fase 7 — Rifiniture: tipi colonna + pipeline render, theming, i18n

> **Piano implementativo autosufficiente** per l'ultima fase della roadmap
> [ROADMAP-wt-crud-parity.md](ROADMAP-wt-crud-parity.md) (§ Fase 7, righe 236-245). Le Fasi 0-6
> risultano ✅ implementate. Tutti i path sono relativi alla root del repo `repara`.
>
> Stesso stile delle fasi precedenti: prima lo **stato reale del codice** (verificato), poi le
> **spec target**, poi gli **step eseguibili** e la **verifica**. Leggere i file citati prima di
> iniettare codice: l'implementazione corrente può divergere dalle spec storiche della roadmap.

---

## 0. Stato reale verificato (vs roadmap)

La roadmap originale tratta theming e asset come "da fare". **Non è più così.** Stato effettivo:

| Filone Fase 7 | Roadmap diceva | Stato reale | Lavoro residuo |
|---|---|---|---|
| Tipi colonna | mancano number/date/image/html/list | `number`/`date`/`relation`/`choice` esistono come *type* ma **senza rendering dedicato** (solo `boolean` formatta ✓/✗); mancano gli altri | **Alto** |
| Pipeline render | solo `value` closure + `twigFilter` | confermato: [DataColumn::render()](../src/Column/DataColumn.php) fa `value` closure → dot-path resolve → caso speciale `boolean` | **Alto** |
| Theming | CSS via CDN, asset fuori dal bundle | **già fatto**: [gridview.scss](../assets/styles/gridview.scss) usa token `--gv-*`, temi light/dark (`prefers-color-scheme` + `data-bs-theme`/`data-gv-theme`), framework-agnostic, asset nel bundle | ✅ **fatto** (token+classi nuovi tipi, audit CDN: nessuna, docs Theming) |
| i18n | `translations/` quasi inutilizzata | confermato: solo `label.true`/`label.false`; **nessun** `\|trans` nei template | ✅ **fatto** (UI chrome tradotta it/en; boolean resta glifo neutro) |

**Conseguenza sulla priorità:** il grosso del valore è in **Workstream A** (tipi colonna +
pipeline). Theming (**B**) è rifinitura. i18n (**C**) è meccanico ma trasversale.

**Scope concordato:** tutta la Fase 7. Il **catalogo dei tipi prende come riferimento i field type
di Twenty CRM** (vedi A.1), modellati come **gerarchia con ereditarietà** (un tipo "estende" un
altro e sovrascrive solo ciò che cambia: es. `currency` extends `number`, `rating` extends
`select`).

> **Decisione — tipi compositi trattati come scalari (per ora).** Il modello dati attuale **non ha
> campi money/currency**; i campi tipo fullName/address/phone esistono come colonne separate (es.
> [UserProfile](../../app/src/Entity/User/UserProfile.php) `firstname/lastname`,
> [CustomerLocation](../../app/src/Entity/Customer/CustomerLocation.php) `address/zipcode/city/country`,
> [CustomerContact](../../app/src/Entity/Customer/CustomerContact.php) `phone`) e vengono già composti
> a mano via `value` closure (pattern `profile_fullname`). Quindi: **`currency` è un formatter su un
> campo scalare** (`format.currency`), e **fullName/address/phone non vengono implementati come tipi
> compositi** in questa fase. L'architettura li prevede (trait `CompositeValueTrait`, A.2/A.3) ma
> restano *progettati e rimandati*: si abilitano quando/se nascerà un campo composito reale, senza
> rifattorizzare il core.

---

## Workstream A — Type system a ereditarietà + pipeline render

### A.0 Stato attuale (file da leggere)

- [DataColumn.php](../src/Column/DataColumn.php): `render($model, $index)` →
  1. se `$this->value` è impostato (string o closure `($data,$index,$column)`) lo usa **come cella intera**;
  2. altrimenti risolve `$data[$attribute]` (con supporto dot-path `a.b.c` via `resolve()`);
  3. se `dataType === 'boolean'` → `renderBoolean()` (✓/✗ hardcoded).
- [ColumnFactory.php](../src/Column/ColumnFactory.php):
  - `DATA_TYPES = ['data','text','boolean','date','number','relation','choice']`
  - `FILTER_TYPES = ['text','boolean','date','number','relation','choice']`
  - per i data-type costruisce `DataColumn`, setta `value` e `dataType`, e fa ereditare il
    `filter`/`control` dal root type (`normalizeFilter()` / `ControlResolver`).
- [tbody.html.twig](../templates/gridview/sections/tbody.html.twig): applica `twigFilter` **dopo**
  `column.render()` via `template_from_string`, poi stampa con `|raw`.

**Vincolo di back-compat (non negoziabile):** `value` closure e `twigFilter` sono usati ovunque
(es. [app/src/Controller/Gridview/UserController.php](../../app/src/Controller/Gridview/UserController.php),
demo BundleController). Devono continuare a funzionare **identici**. I `dataType` attuali
(`text/boolean/date/number/relation/choice/data`) restano validi come **alias** dei nuovi tipi.

---

### A.1 Riferimento: i field type di Twenty CRM

Fonte: doc Twenty "Prepare your CSV files — Step 4". Lista canonica e formato CSV atteso (utile
anche per import/export futuri):

| Twenty field | Formato CSV | Note di modello |
|---|---|---|
| Text | testo libero | scalare base |
| Email(s) | `name@domain.com`; multipli `["a@x.com","b@x.com"]` | collezione di link `mailto:` |
| Domain / Links | label + URL; extra `[{"url":..,"label":..}]` | scalare/collezione di link |
| Phone(s) | numero + country code + calling code | **composito** (sotto-campi) |
| Address | street1/street2/city/state/country/postalCode | **composito** |
| Date / Date-Time | `YYYY-MM-DD` / ISO 8601 | scalare |
| Number | `1234.56` (punto decimale, no separatori migliaia) | scalare numerico |
| Currency | amount `1234.56` + currency code `USD` | **composito**, *estende number* |
| Boolean | `TRUE` / `FALSE` | scalare |
| Select | **API name** dell'opzione (`OPTION_1`), non la label | enum a valore singolo |
| Multi-Select | `["VALUE1","VALUE2"]` | *estende select* (array) |
| Array | `["value1","value2"]` | collezione generica |
| Rating | `RATING_1` … `RATING_5` | *estende select* (opzioni fisse, render a stelle) |
| JSON (Raw JSON) | `{"key":"value"}` | scalare strutturato |
| Rich Text | HTML/markdown | *estende text* (render raw) |
| Relation | riferimento entità | link a entità correlata |
| Full Name | first + last | **composito** |
| ID (UUID) | UUID | *estende text* |

---

### A.2 Architettura: type system con ereditarietà reale (PHP)

Il punto chiave richiesto: **i tipi formano una gerarchia** e un tipo deriva il comportamento dal
genitore, sovrascrivendo solo i metodi che cambiano. Mappiamo questo **1:1 sull'ereditarietà di
classe PHP** — il modo più naturale di esprimere "currency *è un* number".

```
interface ColumnTypeInterface
    getName(): string                          // 'currency'
    getParent(): ?string                       // 'number'  (solo doc/introspezione)
    getRawValue(array $data, ColumnInterface $c): mixed         // stage 1 — valueGetter
    format(mixed $raw, array $data, ColumnInterface $c): mixed  // stage 2 — formatter
    render(mixed $display, array $data, ColumnInterface $c): string  // stage 3 — renderer (HTML escaped salvo override)
    inferFilterType(): ?string                 // null = non filtrabile di default
    inferControlType(): ?string                // write-side (CRUD/inline), null = nessuno
    getDefaultOptions(): array                 // es. decimals, pattern, currency, ...
```

**Pipeline a 3 stadi** (sostituisce il rendering monolitico, opt-in e retro-compatibile):

```
valueGetter(row)  →  formatter(value, row)  →  renderer(value, row)  →  [twigFilter]
   (raw value)         (display value)          (HTML)                  (post-filtro Twig, invariato)
```

Ogni stadio ha tre livelli di risoluzione, in ordine di precedenza:
1. **closure per-colonna** (`valueGetter`/`formatter`/`renderer` nella config) — se presente vince;
2. **metodo del ColumnType** (eventualmente ereditato dal parent);
3. fallback base (escape + cast a string).

**Regole di compatibilità (non rompere nulla):**
- Se la colonna ha `value` (API attuale) → **short-circuit totale**: comportamento identico a oggi
  (cella intera, poi `twigFilter`), la pipeline non entra. `value` resta documentato come
  scorciatoia/override legacy.
- `twigFilter` resta applicato in [tbody.html.twig](../templates/gridview/sections/tbody.html.twig),
  **invariato**.
- **Escape di default**: il `render()` base restituisce HTML **escaped** (`htmlspecialchars`); i
  tipi "raw" (`html`/`richText`) e le vie esplicite (`twigFilter:'raw'`, `value`) sono le uniche
  che emettono HTML non escaped. Così tbody può continuare con `|raw` senza XSS.

**Registro tipi.** `ColumnTypeRegistry` (servizio, `tagged_iterator` come già fatto per gli
exporter in [services.xml](../config/services.xml)) mappa `name → ColumnType`. Le costanti
`DATA_TYPES`/`FILTER_TYPES` in [ColumnFactory](../src/Column/ColumnFactory.php) **vengono derivate
dal registro** invece di essere hardcoded; la `inferFilterType()`/`inferControlType()` del tipo
sostituisce `normalizeFilter()`/`ControlResolver` per la parte di *default inheritance* (il
`filter.type`/`control.type` esplicito continua a vincere). Un tipo custom dell'app si registra
implementando l'interfaccia (zero config, come gli exporter).

> **Perché classi e non solo dati:** `CurrencyType extends NumberType { override format() }` è
> letteralmente "currency estende number". Riuso reale, niente switch giganti, estendibile
> dall'app. Il `DataColumn` non cambia struttura: delega gli stadi al type risolto.

---

### A.3 Catalogo tipi con gerarchia (extends)

Gerarchia proposta (`▸` = estende). Twenty in **grassetto**, extra suggeriti in _corsivo_ (vedi A.6).

```
AbstractColumnType
├─ TextType ........................ Twenty Text  (escape, passthrough)
│  ├─ UuidType ..................... Twenty ID
│  ├─ HtmlType / RichTextType ...... Twenty Rich Text   (render RAW)
│  ├─ JsonType .................... Twenty JSON         (pretty-print, escaped)
│  ├─ LinkType / UrlType .......... Twenty Domain/Links (<a href>)
│  │  ├─ EmailType ................ Twenty Email        (mailto:)
│  │  ├─ ImageType ................ (richiesto)         (<img>)
│  │  └─ _PhoneSimpleType_ ........ (vedi PhoneType composito sotto)
│  └─ _CodeType_ .................. extra (monospace)
├─ NumberType ..................... Twenty Number
│  ├─ CurrencyType ................ Twenty Currency  (number + simbolo/codice; composito amount+code)
│  ├─ _PercentType_ ............... extra            (valore × 100 + "%")
│  ├─ _DurationType_ .............. extra            ("2h 30m")
│  ├─ _FileSizeType_ .............. extra            ("1.2 MB")
│  └─ _ProgressType_ .............. extra            (barra di avanzamento)
├─ BooleanType ................... Twenty Boolean  (✓/✗ traducibili)
├─ DateType ...................... Twenty Date / Date-Time
├─ SelectType .................... Twenty Select   (API name → label opzione)
│  ├─ MultiSelectType ............ Twenty Multi-Select (array di valori)
│  ├─ RatingType ................. Twenty Rating   (opzioni fisse 1..5, render a stelle)
│  ├─ _BadgeType / StatusType_ ... extra           (chip colorato per stati)
│  └─ _ColorType_ ................ extra           (swatch colore)
├─ ListType / ArrayType .......... Twenty Array    (collezione → <ul> o inline)
├─ RelationType .................. Twenty Relation (link a entità correlata)
└─ AbstractCompositeType ......... base per tipi multi-campo — ⏸ PROGETTATO, RIMANDATO
   ├─ FullNameType .............. Twenty Full Name (first + last)
   ├─ AddressType ............... Twenty Address
   └─ PhoneType ................. Twenty Phone (number + countryCode + callingCode)
```

> **⏸ Compositi rimandati (vedi Decisione in §0).** In questa fase `currency` è solo
> `CurrencyType extends NumberType` su **campo scalare** (`format.currency`); FullName/Address/Phone
> **non** vengono implementati come tipi compositi (si continuano a comporre via `value` closure).
> Il `CompositeValueTrait` e `AbstractCompositeType` restano nel disegno per abilitarli in futuro
> senza toccare il core: "composito" è una *capability* (trait), non un ramo esclusivo dell'albero.

**Comportamenti chiave per tipo** (solo override rispetto al parent):

- **TextType** (base): `getRawValue` = dot-path resolve attuale; `render` = escaped string.
- **Html/RichText** ▸ Text: `render` = raw (HTML fidato). Via "raw" esplicita.
- **Json** ▸ Text: `format` = `json_encode(PRETTY)`; `render` = `<pre>` escaped.
- **Link/Url** ▸ Text: `render` = `<a href target rel>`. Opts: `label`, `target`, `rel`.
- **Email** ▸ Link: `render` = `<a href="mailto:…">`. Collezione → join.
- **Image** ▸ Url: `render` = `<img src class="gv-img" loading=lazy alt>`. Opts: `width/height/alt/fallback`.
- **Number** (base num): `format` = `number_format` localizzato. Opts: `decimals`,`thousandsSep`,`decimalSep`. CSS `gv-num` (allineamento a destra).
- **Currency** ▸ Number: campo **scalare** + `format.currency`; `format` aggiunge simbolo/codice via `\NumberFormatter::CURRENCY` se `intl` disponibile, altrimenti fallback `number_format` + mappa simboli. Opts: `currency` (def `EUR`), `decimals` (def 2). _(Variante composita amount+code: rimandata, §0.)_
- **Boolean**: `format` → `label.true`/`label.false` **tradotte** (vedi C); opts override `true`/`false`.
- **Date**: `format` = `DateTimeInterface → string`. Opts: `pattern` (def `d/m/Y`) o ICU se `intl`. Null-safe.
- **Select** ▸ base: `getRawValue` = API name; `format` = label dall'elenco opzioni (`options.choices` riusando il pattern del filtro `choice`/`relation`). `render` escaped.
- **MultiSelect** ▸ Select: value = array → mappa ogni API name a label → chip/lista.
- **Rating** ▸ Select: opzioni fisse `RATING_1..5`; `render` = stelle (`★★★☆☆`) o icone, `gv-rating`.
- **Badge/Status** ▸ Select: come Select ma `render` = `<span class="gv-badge gv-badge--{value}">`; colore da `options.colors[value]`.
- **List/Array**: `format` normalizza ad array; `render` = `<ul class="gv-list">` (li escaped) o inline con `separator`.
- **Relation**: `render` = link all'entità (route + label). Riusa la logica relation già usata nelle closure.
- **FullName/Address/Phone** _(⏸ rimandati, §0)_: `AbstractCompositeType` leggerebbe i sotto-campi (mappa `format.fields`) e li comporrebbe; Phone `render` = `<a href="tel:">`. Per ora si compongono via `value` closure.

---

### A.4 Step di implementazione

1. **Contratto + base**: `src/Column/Type/ColumnTypeInterface.php` + `AbstractColumnType.php`
   (implementa i 3 stadi con fallback escape, `getParent()` per introspezione/doc).
2. **Tipi Twenty (priorità)**: Text, Number, Boolean, Date, Select, Json, Link, Email, Relation,
   List/Array, MultiSelect, Rating, Currency (scalare), Html/RichText, Uuid. Estendono il parent,
   override minimale. _(Compositi FullName/Address/Phone + `CompositeValueTrait`: ⏸ rimandati, §0.)_
3. **Registro**: `src/Column/Type/ColumnTypeRegistry.php` (tagged_iterator). Tag in
   [services.xml](../config/services.xml) + `registerForAutoconfiguration(ColumnTypeInterface)`
   nella [classe bundle](../src/FedaleGridviewBundle.php) (stesso schema exporter).
4. **DataColumn**: aggiungere setter `setValueGetter/setFormatter/setRenderer` (closure per-colonna)
   + `setFormat(array)` (opts del tipo). `render()` diventa: short-circuit `value` → altrimenti
   risolvi il `ColumnType` dal `dataType` e applica i 3 stadi con precedenza closure>type>base.
   Il type è iniettato in `DataColumn` dal `ColumnFactory` (che è servizio e riceve il registry).
5. **ColumnFactory**: `DATA_TYPES`/`FILTER_TYPES` derivate dal registry; `normalizeFilter()`/
   control inheritance delegano a `type->inferFilterType()/inferControlType()` (il valore esplicito
   continua a vincere). Alias retro-compat: `data`→text(raw-ish legacy), `choice`→select.
6. **ControlResolver**: control per i nuovi tipi che lo prevedono (currency=number, image/email/url
   =text, select/multiselect/rating=choice). Compositi: fuori scope per ora (edit via control dedicati futuri).
7. **Filtri**: currency→number, rating/multiselect/badge→choice, email/url/image/html/json/list→text
   o non filtrabili (`inferFilterType()` ritorna null dove non ha senso).

### A.5 Verifica Workstream A

- `currency` mostra `1.234,56 €` allineata a destra, filtro numerico (campo scalare).
- `rating` mostra stelle e filtra come choice (1..5).
- `select`/`multiSelect` mostrano la **label** (non l'API name); `email`/`url` sono link;
  `image` una `<img>`; `list`/`array` una lista; `html`/`richText` HTML raw; `json` pretty.
- **Ereditarietà**: definire un type app `MoneyType extends CurrencyType` con solo override valuta →
  funziona senza altro codice; il registry lo raccoglie via autoconfigure (vedi A.7).
- **Regressione**: colonne esistenti con `value`/`twigFilter` (User/Customer + demo) **identiche**;
  XSS: `text` con `<script>` escaped salvo `html`/`raw`/`value`.
- Test (`tests/`): per ogni type il triplo stadio; precedenza closure>type>base; alias legacy;
  `intl` presente/assente; registrazione di un type custom.

### A.6 Tipi extra suggeriti (oltre Twenty)

Proposti perché ricorrenti in una griglia gestionale; tutti **derivati** quindi a basso costo:

- **`percent`** ▸ number — valore frazione/100 + `%` (opts `scale`).
- **`badge` / `status`** ▸ select — chip colorato per stati (workflow, priorità). Molto utile.
- **`progress`** ▸ number — barra 0-100% inline.
- **`color`** ▸ select/text — swatch + codice.
- **`duration`** ▸ number — secondi/minuti → `2h 30m`.
- **`fileSize`** ▸ number — byte → `1.2 MB`.
- **`code`** ▸ text/html — monospace (`<code>`), per ID tecnici/SKU.
- **`icon`** ▸ text — mappa valore→icona.
- **`datetime`** distinto da **`date`** (Twenty ha sia DATE che DATE_TIME): stesso parent, pattern
  con ora di default.
- **`relationCount`** ▸ number — conteggio relazioni con link al filtro.

> Da decidere in implementazione quali extra includere subito (suggeriti minimi: `percent`,
> `badge/status`, `datetime`). Gli altri sono aggiungibili in seguito senza modifiche al core,
> proprio grazie al registry + ereditarietà.

---

### A.7 Tipi di dato custom definiti dall'app (estensibilità lato client)

**Sì: è un requisito di primo livello del design, non un extra.** L'intera architettura del type
system è pensata perché l'app ospite (il "client" del bundle) aggiunga tipi propri **senza
modificare il bundle**. Tre livelli di estensione, dal più semplice al più potente:

**1) Per-colonna, senza creare un type** — già possibile con la pipeline (A.2): nella config della
colonna passi le closure `valueGetter`/`formatter`/`renderer` (che vincono sul type). Adatto a un
rendering una-tantum; nessuna classe da scrivere. È l'evoluzione strutturata dell'attuale `value`.

**2) Nuovo type per ereditarietà** (il caso tipico) — l'app crea una classe che **estende un type
esistente** e override solo ciò che serve:

```php
// app/src/Gridview/Type/MoneyType.php
namespace App\Gridview\Type;

use Fedale\GridviewBundle\Column\Type\CurrencyType;

final class MoneyType extends CurrencyType
{
    public function getName(): string { return 'money'; }
    public function getDefaultOptions(): array {
        return ['currency' => 'EUR', 'decimals' => 2] + parent::getDefaultOptions();
    }
}
```

**3) Type from-scratch** — implementa `ColumnTypeInterface` (o estende `AbstractColumnType`) per un
comportamento totalmente nuovo (es. uno sparkline, una mappa, un QR).

**Registrazione = zero config.** Grazie a `registerForAutoconfiguration(ColumnTypeInterface)` nella
[classe bundle](../src/FedaleGridviewBundle.php) (stesso schema già usato per gli
`ExporterInterface`, vedi Fase 5), **qualsiasi servizio dell'app che implementa l'interfaccia viene
auto-taggato e raccolto dal `ColumnTypeRegistry`**. L'app non tocca `services.xml` del bundle: gli
basta che la classe sia un servizio (autowiring di default) e usarla:

```php
['attribute' => 'price', 'type' => 'money']   // 'money' risolto dal registry
```

**Requisiti perché funzioni** (da garantire in fase A.3/A.4):
- `getName()` è la chiave pubblica usata in `type =>`; il registry valida le collisioni (un type app
  può **sovrascrivere** un built-in con lo stesso nome — utile, ma da loggare/documentare).
- I `DATA_TYPES`/`FILTER_TYPES` del `ColumnFactory` sono **derivati dal registry** (A.4 step 5), così
  un type custom è automaticamente accettato dal factory e dall'inferenza filtro/control.
- Il type custom può dichiarare `inferFilterType()`/`inferControlType()` per agganciarsi a
  filtri/CRUD esistenti senza codice extra.

**Verifica (in A.5):** registrare un `MoneyType extends CurrencyType` nell'app e usarlo in una
colonna senza modifiche al bundle → rendering/filtro corretti. Documentare il pattern in
[index.md](index.md) (sezione "Custom column types").

---

## Workstream B — Theming (residuo)

### B.0 Stato attuale

[gridview.scss](../assets/styles/gridview.scss) **già**: token `--gv-*` (colori, input, bottoni),
dark mode automatica + manuale, commento d'uso per Bootstrap/Tailwind/custom. Entry Encore
`gridview` caricata da [app/templates/base.html.twig](../../app/templates/base.html.twig)
(`encore_entry_link_tags('gridview')`). **Nessuna** dipendenza CDN trovata nei template del bundle
(la roadmap §244 è obsoleta su questo punto — *verificare comunque*).

### B.1 Step

1. **Audit CDN** (chiudere il punto roadmap): `grep -rn "cdn\|jsdelivr\|unpkg\|bootstrap@\|http"`
   in `FedaleGridviewBundle/templates/`. Se emergono `<link>`/`<script>` a CDN, rimuoverli e
   spostare l'asset nell'entry Encore del bundle. (Atteso: nessuno.)
2. **Token/classi per i nuovi tipi** (da Workstream A) — aggiungere a `[data-gridview]`:
   - `.gv-num { text-align: right; font-variant-numeric: tabular-nums; }`
   - `.gv-img { max-height: var(--gv-img-max-h, 40px); width: auto; border-radius: var(--gv-img-radius, 3px); }`
   - `.gv-list { margin: 0; padding-left: 1.1em; }`
   - `.gv-rating` (stelle), `.gv-badge`/`.gv-badge--*` (chip stati con `--gv-badge-*`), `.gv-progress`.
   - relativi token `--gv-img-*`, `--gv-badge-*`, `--gv-rating-color`, ecc.
3. **Documentare** i punti di override theme: sezione "Theming" in [index.md](index.md) con le 3
   ricette (Bootstrap5 / Tailwind / custom) e l'elenco completo dei token `--gv-*`.
4. *(Opzionale)* toggle tema dedicato `data-gv-theme` documentato come alternativa a `data-bs-theme`
   per app non-Bootstrap (lo scss lo supporta già).

### B.2 Verifica

- I nuovi tipi (number/currency/image/list/rating/badge) rispettano i token in light e dark.
- Override di un token (es. `--gv-color-primary`) dall'app si propaga senza toccare il bundle.
- Nessuna richiesta di rete a CDN (Network tab) caricando la griglia.

---

## Workstream C — i18n

### C.0 Stato attuale

- [translations/GridviewBundle.it.yaml](../translations/GridviewBundle.it.yaml) /
  [.en.yaml](../translations/GridviewBundle.en.yaml): **solo** `label.true`/`label.false`.
- **Nessun** `|trans` nei template (`grep` su `templates/` vuoto). Le stringhe UI sono hardcoded
  nelle sezioni (`pagination`, `bulkBar`, `export`, `savedSearch`, `addButton`, `empty`) e nei
  template `crud/*`. Dominio di traduzione esistente: **`GridviewBundle`**.
- `emptyText`, `addLabel` sono già config (Bundle `Configuration`) → vanno resi *traducibili*, non
  duplicati.

### C.1 Spec target

Tutte le stringhe UI del bundle passano da `|trans({...}, 'GridviewBundle')`, con chiavi in `it` ed
`en`. Le label di colonna e i testi configurabili (`emptyText`, `addLabel`, `applyLabel`, titoli
dialog) accettano una **chiave di traduzione** come valore. Anche le label delle **opzioni Select**
(A.3) e i valori `boolean` sono traducibili.

### C.2 Step

1. **Censire le stringhe** hardcoded:
   `grep -rnoE ">[A-Za-zÀ-ÿ ]{2,}<" FedaleGridviewBundle/templates/` e ispezione delle sezioni
   `templates/gridview/sections/*.html.twig` + `templates/crud/*.html.twig`. Aree note:
   pagination (Previous/Next/page/of), bulkBar (selezionati/azioni), export (Export/formati),
   savedSearch (Salva/Applica/nome), addButton, empty text, CRUD (Add/Edit/Clone/Delete/Save/
   Cancel/conferma delete/recap), filtri (placeholder, Clear, Filter).
2. **Definire le chiavi** (namespacing per area), es.:
   ```yaml
   pagination.previous, pagination.next, pagination.page, pagination.of, pagination.perPage
   bulk.selected, bulk.selectAll, bulk.clear, bulk.apply
   export.label, export.format.csv
   saved.search.save, saved.search.apply, saved.search.name, saved.selection.save
   crud.add, crud.edit, crud.clone, crud.delete, crud.save, crud.cancel, crud.deleteConfirm
   filter.clear, filter.apply, filter.placeholder
   label.true, label.false, grid.empty
   ```
3. **Sostituire nei template**: `{{ 'pagination.next'|trans({}, 'GridviewBundle') }}`. Per stringhe
   con conteggio usare parametri (`{ '%count%': n }`) o plurals ICU (es. `bulk.selected`).
4. **Popolare** `GridviewBundle.it.yaml` e `.en.yaml` con tutte le chiavi.
5. **Boolean i18n**: `BooleanType` (A.3) usa `label.true`/`label.false` tradotte → iniettare
   `TranslatorInterface` nei type che ne hanno bisogno (Boolean, Select per le label) — sono servizi
   nel registry, quindi banale.
6. **Testi configurabili traducibili**: `emptyText`, `addLabel`, ecc. passano da `|trans` (se il
   valore è una chiave del catalogo viene tradotto, altrimenti passa invariato — comportamento
   standard del translator).
7. **Label colonna / opzioni select come chiave**: documentare che `label` e le label di opzione
   possono essere chiavi del dominio `GridviewBundle` (o dominio app); `|trans` nel rendering header
   ([thead.html.twig](../templates/gridview/sections/thead.html.twig) /
   [DataColumn::renderHeader()](../src/Column/DataColumn.php)) e nei SelectType. Default: passthrough.

### C.3 Verifica

- Cambiando `locale` (it/en) cambiano le stringhe UI (pagination, bulk, export, CRUD, empty,
  boolean, label opzioni select).
- Un'app senza traduzioni vede i default (en) senza errori.
- Test: catalogo caricato / snapshot sezione it vs en.

---

## Ordine di esecuzione consigliato

1. **A** (type system + pipeline) — cuore della fase; abilita i token CSS di B e la i18n di boolean/select in C.
   Sotto-ordine interno: contratto+base → tipi Twenty scalari → registry/factory → extra. _(Compositi: rimandati, §0.)_
2. **B** (token nuovi tipi + audit + docs) — piccolo, segue A.
3. **C** (i18n trasversale) — per ultimo, include anche le stringhe introdotte da A/B.

A, B, C sono comunque **indipendenti** e committabili separatamente.

---

## File coinvolti (riepilogo)

**Backend**
- `src/Column/Type/ColumnTypeInterface.php` + `AbstractColumnType.php` + i tipi concreti *(nuovi)*.
- `src/Column/Type/ColumnTypeRegistry.php` *(nuovo)* — tagged_iterator.
- `src/Column/CompositeValueTrait.php` *(nuovo, ⏸ rimandato §0)* — lettura sotto-campi (fullName/address/phone).
- [src/Column/DataColumn.php](../src/Column/DataColumn.php) — pipeline 3 stadi, setter closure, delega al type.
- [src/Column/ColumnFactory.php](../src/Column/ColumnFactory.php) — type da registry, filtro/control inference delegata.
- [src/Form/Control/ControlResolver.php](../src/Form/Control/ControlResolver.php) — control per i nuovi tipi.
- [config/services.xml](../config/services.xml) — registrare registry + tipi (tag `fedale_gridview.column_type`), `translator` ai type che serve.
- [src/FedaleGridviewBundle.php](../src/FedaleGridviewBundle.php) — `registerForAutoconfiguration(ColumnTypeInterface)`; eventuali default config.

**Template**
- [tbody.html.twig](../templates/gridview/sections/tbody.html.twig) — invariato sul flusso `twigFilter`/`|raw` (escape lato renderer).
- [thead.html.twig](../templates/gridview/sections/thead.html.twig) + sezioni + `crud/*` — `|trans`.

**Asset / theming**
- [assets/styles/gridview.scss](../assets/styles/gridview.scss) — `.gv-num`/`.gv-img`/`.gv-list`/`.gv-rating`/`.gv-badge`/`.gv-progress` + token.

**i18n**
- [translations/GridviewBundle.it.yaml](../translations/GridviewBundle.it.yaml) / [.en.yaml](../translations/GridviewBundle.en.yaml).

**Demo / riferimento**
- [app/src/Controller/Gridview/UserController.php](../../app/src/Controller/Gridview/UserController.php) — colonne currency/select/rating/email/image/list per test end-to-end.

**Docs**
- [docs/index.md](index.md) — "Column types" (catalogo + gerarchia + pipeline), "Theming", "i18n".
- [docs/ROADMAP-wt-crud-parity.md](ROADMAP-wt-crud-parity.md) — marcare Fase 7 ✅ a fine lavori.

---

## Verifica end-to-end (come le altre fasi)

1. Build asset: `cd app && yarn encore dev`.
2. Avvio: stack Docker, aprire la griglia User (`gridview_user_index`).
3. Controllare: currency/number formattati e allineati; select/rating con label e stelle; email/url
   link; image renderizzata; list/array in lista; html raw; un type **custom** dell'app risolto dal
   registry; cambio locale it/en; dark/light coerenti; **nessuna** regressione su `value`/`twigFilter`;
   nessun XSS sui value `text`.
4. `cd app && vendor/bin/phpunit` (+ eventuale `vendor/bin/phpstan`) verdi.
