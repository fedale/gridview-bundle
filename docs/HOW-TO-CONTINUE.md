# Come continuare il lavoro su un altro account/macchina

Questo file serve a riavviare il lavoro sulla roadmap di FedaleGridView con un nuovo
Claude Code (o un nuovo collaboratore), eventualmente su un altro account.

## Prerequisito (una volta sola)

Sull'altro account deve esserci il repo `repara` con dentro `FedaleGridviewBundle/` e la
cartella `app/`. Clonando/sincronizzando il repo, la roadmap è già presente in
`FedaleGridviewBundle/docs/ROADMAP-wt-crud-parity.md`. **Non serve il progetto `wt-crud`.**

## Messaggio iniziale da dare a Claude (copia-incolla)

> Leggi `FedaleGridviewBundle/docs/ROADMAP-wt-crud-parity.md`: è una roadmap autosufficiente
> per portare il bundle FedaleGridView verso una griglia CRUD avanzata, in Symfony/Twig +
> Hotwired. Stiamo lavorando sullo stesso repo `repara` citato nel documento. Non hai bisogno
> del progetto `wt-crud`: le specifiche delle feature sono già dentro il documento.
>
> Prima di toccare codice: leggi i file del bundle citati nella roadmap per allinearti
> all'implementazione attuale, poi entra in plan mode e proponimi un piano dettagliato solo
> per la Fase 0 (Fondamenta asset & Turbo). Procediamo una fase alla volta, e io approvo prima
> di passare alla successiva.

## Note utili da passare a Claude quando servono

- **Procedere una fase alla volta.** La roadmap ha 8 fasi (0→7) con priorità. La Fase 0
  (Fondamenta asset & Turbo) è il punto di partenza perché abilita tutto il resto.
- **Dipendenze tra fasi:** il "batch update" delle Fasi 2 e 3 si appoggia al meccanismo
  dialog della Fase 4. Se si anticipa il bulk, potrebbe servire prima la parte modale.
- **Scelta architetturale Fase 3 (inline editing):** richiede di aggiungere
  `symfony/ux-live-component` a `composer.json`, oppure in alternativa Stimulus + endpoint
  PATCH. È una decisione da confermare con te.
- **Ambiente di sviluppo:**
  - App via Docker: `docker-compose up`
  - Build asset: `cd app && yarn encore dev` (stack Encore/Stimulus già configurato)
  - Rotta demo per testare: `/bundle/gridview` (controller `app/src/Controller/BundleController.php`)
- **Vincolo trasversale:** mantenere il *progressive enhancement* — la griglia deve restare
  navigabile anche senza JavaScript; Turbo è un miglioramento, non un requisito.
