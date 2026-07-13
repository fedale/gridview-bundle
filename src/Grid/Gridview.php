<?php

namespace Fedale\GridviewBundle\Grid;

use Doctrine\Common\Collections\ArrayCollection;
use Fedale\GridviewBundle\Column\AbstractColumn;
use Fedale\GridviewBundle\Column\CheckboxColumn;
use Fedale\GridviewBundle\Column\ColumnFactory;
use Fedale\GridviewBundle\Column\DataColumn;
use Fedale\GridviewBundle\Contract\AggregatableInterface;
use Fedale\GridviewBundle\Contract\ChildCountResolverInterface;
use Fedale\GridviewBundle\Contract\ColumnInterface;
use Fedale\GridviewBundle\Contract\DataProviderInterface;
use Fedale\GridviewBundle\Contract\GridviewInterface;
use Fedale\GridviewBundle\Contract\GroupingCapableInterface;
use Fedale\GridviewBundle\Contract\PaginationConfiguringInterface;
use Fedale\GridviewBundle\Contract\ScopeVerifiableInterface;
use Fedale\GridviewBundle\Contract\SearchFormInterface;
use Fedale\GridviewBundle\Contract\SearchModelInterface;
use Fedale\GridviewBundle\Filter\FilterDefaultNormalizer;
use Fedale\GridviewBundle\Grid\State\GridviewUrlState;
use Fedale\GridviewBundle\Profiler\GridviewProfile;
use Fedale\GridviewBundle\Row\Row;
use Fedale\GridviewBundle\Service\GridviewService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Twig\Environment;

/**
 * @phpstan-import-type ColumnSpec from \Fedale\GridviewBundle\Column\ColumnFactory
 */
class Gridview implements GridviewInterface
{
    private ArrayCollection $columns;
    private DataProviderInterface $dataProvider;
    private GridviewUrlState $urlState;
    private Environment $twig;
    private SearchFormInterface $searchForm;
    private ?array $dataProviderOptions = null;
    private array $defaultFilterParams = [];
    private bool $dataProviderInitialized = false;
    private ?GroupingConfig $groupingConfig = null;

    /** @var array<int, ColumnInterface>|null */
    private ?array $childColumns = null;

    /**
     * Resolved footer summaries, keyed by the same column key the table sections
     * use (`attribute` or `col<index>`), each already rendered to a string/Markup.
     * Empty when no column declares a footer. Populated by renderGrid().
     *
     * @var array<string, \Twig\Markup|string>|null
     */
    private ?array $footerSummaries = null;

    protected ?string $key = null;
    protected ?string $id = null;
    protected string $prefix = 'grid_';
    protected static int $counter = 0;

    public string $emptyCell = '&nbsp;';

    public array $attr = [];
    public array $containerAttr = [];
    public array $headerAttr = [];
    public array $filterAttr = [];
    public array $rowAttr = [];
    public $rowOptions = [];

    /** Active framework theme name (e.g. 'default', 'bootstrap5'). */
    public string $theme = 'default';

    /**
     * Pre-resolved class map (class key => concrete classes) for the active
     * theme, injected by the builder from the ThemeRegistry. Templates read it
     * via {@see cls()}.
     *
     * @var array<string, string>
     */
    private array $classMap = [];

    public ?SearchModelInterface $searchModel = null;

    /**
     * `display` / `behavior` / `integration` are a readability grouping only —
     * see GridviewConfigRegistry — there is no semantic significance to the
     * split beyond making the tree easier to scan.
     */
    protected array $options = [
        'display' => [
            'caption' => null,
            'title' => null,
            // Data-renderer configuration. `default` is the initial/active strategy
            // (picks sections/dataview/{renderer}.html.twig); `map` holds one entry
            // per available renderer, keyed by name, whose value is that renderer's
            // option bag (e.g. template, min, gap). The runtime {viewSwitcher} offers
            // exactly the map keys — more than one entry surfaces it; empty/single =
            // single-view grid (the table strategy is the fallback). Built-in
            // renderers: 'table' (default), 'list', 'card'.
            'renderer' => [
                'default' => 'table',
                'map'     => [],
            ],
            'emptyText' => 'No records found',
            'showThead' => true,
            'showTfoot' => true,
            'addLabel' => 'Add',
            // Full-page CRUD wrapper template; ex `crud.pageTemplate`.
            'crudTemplate' => null,
            // Single source of truth — see GridviewConfigRegistry::LAYOUT_DEFAULTS.
            'layout' => GridviewConfigRegistry::LAYOUT_DEFAULTS,
        ],
        'behavior' => [
            'useTurbo' => true,
            'globalSearch' => [],
            'formName' => 'fedaleForm',
            'maxQueryLength' => 4000,
            // 'modal' | 'page' | 'custom'; ex `crud.mode`.
            'crudMode' => 'modal',
            'filterControls' => [
                // Render the per-column filters in the header (the funnel icon plus
                // the filter <thead> row). When false neither is emitted at all.
                'inHeader' => true,
                'inlineClear' => false,
                // Filter-bar auto-population. null → derived from the active renderer:
                // ON for non-table views (list/card have no header filters), OFF for
                // table. Explicit true/false overrides. See getFilterBarColumns().
                'autoBar' => null,
                // Default clear mode(s) for all columns that don't specify filter.clear
                // explicitly. Accepts the same format as filter.clear (string, array,
                // or extended form). When null, the default is ['header'] plus 'input'
                // if inlineClear is true (retro-compatible). Set to 'none' to hide all
                // clear affordances by default (per-column specs still override).
                'clear' => null,
                // Multi/relation filters: hide the search box and the
                // select/deselect/invert toolbar when a column has fewer than this
                // many options. Overridable per column via filter.options.controls_threshold.
                'choiceControlsThreshold' => 20,
            ],
            'pagination' => [
                'pageSelect' => true,
                'pageSelectThreshold' => 10,
            ],
            'reorderColumns' => false,
            'responsive' => false,
            'restriction' => false,
            // Row grouping: parent rows that expand to reveal a relation's
            // records as child rows. See GroupingConfig for the accepted keys
            // (enabled, mode, relation, columns, label, childKey, limit). Empty
            // = ungrouped. Only takes effect when the data provider can resolve
            // children (implements GroupingCapableInterface).
            'grouping' => [],
        ],
        'integration' => [
            'routeName' => null,
            'addRoute' => null,
        ],
    ];

    public function __construct(
        private GridviewService $gridviewService,
        private ColumnFactory $columnFactory
    ) {
        $this->columns = new ArrayCollection();
        $this->twig = $this->gridviewService->getEnvironment();
        $this->searchForm = $this->gridviewService->getSearchForm();
        $this->dataProvider = $this->gridviewService->getDataProvider();
    }

    public function getKey(): string
    {
        if ($this->key === null) {
            // Prefer the stable, per-grid id (entity short name by default) so the
            // key is unique and reproducible across requests. Fall back to the
            // positional counter only for grids built without an id — a bare
            // counter collides between single-grid pages (every page's first grid
            // is "grid_0"), which leaks column-visibility state across grids.
            $this->key = $this->id ?? ($this->prefix . static::$counter++);
        }

        return $this->key;
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function setId(string $id): void
    {
        $this->id = $id;
    }

    public function getColumns(): ArrayCollection
    {
        return $this->columns;
    }

    /**
     * Columns to render in the grid table (header, body, footer, filter row and
     * the "Columns" toggle): registered columns active in the `index` context.
     * Columns inactive in `index` only stay in {@see getColumns()} (filterable,
     * exportable, editable in forms) but produce no table cell.
     */
    public function getIndexColumns(): ArrayCollection
    {
        // Re-index to contiguous 0..n keys so the per-column `data-col` used by
        // the visibility/reorder JS (derived from the preserved keys here and
        // from loop.index0 in thead/tbody) stays aligned across sections.
        return new ArrayCollection(array_values(
            $this->columns->filter(static fn(ColumnInterface $c) => $c->isActiveIn('index'))->toArray()
        ));
    }

    public function addColumn(ColumnInterface $column): void
    {
        $this->columns->add($column);
    }

    /**
     * Size-variant class for the CRUD modal dialog (`modal.dialog.sm|md|lg|xl`),
     * chosen from the number of columns active in `create`/`update` — the
     * fields the add/edit form actually renders. Resolved once here (rather
     * than per add/edit/clone request) because `_modal.html.twig` renders the
     * modal shell a single time per grid, shared by every CRUD request for it.
     */
    public function crudModalSizeClass(): string
    {
        $fieldCount = $this->columns
            ->filter(static fn (ColumnInterface $c) => $c->isActiveIn('create') || $c->isActiveIn('update'))
            ->count();

        $size = match (true) {
            $fieldCount <= 4  => 'sm',
            $fieldCount <= 8  => 'md',
            $fieldCount <= 14 => 'lg',
            default           => 'xl',
        };

        return $this->cls('modal.dialog.' . $size);
    }

    /**
     * Resolved grouping configuration, built once from the `behavior.grouping`
     * option and the grid's entity (the parent class the relation hangs off).
     */
    public function getGroupingConfig(): GroupingConfig
    {
        return $this->groupingConfig ??= GroupingConfig::fromArray(
            $this->options['behavior']['grouping'] ?? [],
            $this->getDataClass(),
        );
    }

    /**
     * Whether this grid renders grouped parent/child rows: grouping is enabled
     * AND the data provider can actually resolve children. A provider without
     * the capability degrades to a plain, ungrouped grid.
     */
    public function isGrouped(): bool
    {
        if (!$this->getGroupingConfig()->isEnabled()) {
            return false;
        }

        return $this->dataProvider instanceof GroupingCapableInterface
            && $this->dataProvider->getChildResolver() !== null;
    }

    /**
     * The columns used to render each child row's sub-table, built from the
     * grouping `columns` specs through the same factory as the main columns.
     *
     * @return array<int, ColumnInterface>
     */
    public function getChildColumns(): array
    {
        if ($this->childColumns !== null) {
            return $this->childColumns;
        }

        $this->childColumns = [];
        foreach ($this->getGroupingConfig()->getChildColumns() as $key => $spec) {
            $column = $this->columnFactory->create($spec, $this, $key);
            $column->setGridview($this);
            // Child columns render in a nested sub-table; they are display-only,
            // so never sortable against the parent grid's Sort (a child header
            // sharing an attribute name would otherwise emit a parent sort link).
            $column->setSortable(false);
            $this->childColumns[] = $column;
        }

        return $this->childColumns;
    }

    /**
     * Per-column footer summaries for the current render, keyed by column key
     * (`attribute` or `col<index>`) — the same key the table footer template uses
     * to place each cell. Empty until renderGrid() has fetched the page data.
     *
     * @return array<string, \Twig\Markup|string>
     */
    public function getFooterSummaries(): array
    {
        return $this->footerSummaries ?? [];
    }

    /**
     * Resolve every column's footer cell. Dataset-scope aggregates are batched
     * into a single provider query (when the provider supports it); everything
     * else — page scope, custom closures, literal labels, or aggregates that
     * can't be expressed in the query — is resolved from the current page's rows.
     *
     * @param iterable<Row> $models
     */
    private function computeFooterSummaries(iterable $models): void
    {
        $columns = [];
        foreach ($this->getIndexColumns() as $i => $column) {
            if ($column instanceof AbstractColumn && $column->hasFooter()) {
                $columns[$column->getAttribute() ?? ('col' . $i)] = $column;
            }
        }

        if ($columns === []) {
            $this->footerSummaries = [];

            return;
        }

        $rows = \is_array($models) ? $models : iterator_to_array($models, false);

        // Batch every dataset-scope aggregate into one provider query.
        $aggregates = [];
        $aliasByKey = [];
        if ($this->dataProvider instanceof AggregatableInterface) {
            $rootAlias   = $this->dataProvider->getRootAlias();
            $expressions = [];
            foreach ($columns as $key => $column) {
                if (!$column instanceof DataColumn) {
                    continue;
                }
                $expr = $column->footerDatasetExpression($rootAlias);
                if ($expr === null) {
                    continue;
                }
                $alias = 'gvagg_' . \count($expressions);
                $expressions[$alias] = $expr;
                $aliasByKey[$key]    = $alias;
            }

            if ($expressions !== []) {
                $aggregates = $this->dataProvider->aggregate($expressions);
            }
        }

        $summaries = [];
        foreach ($columns as $key => $column) {
            $datasetValue = isset($aliasByKey[$key]) ? ($aggregates[$aliasByKey[$key]] ?? null) : null;
            $summaries[$key] = $column->renderFooter($datasetValue, $rows);
        }

        $this->footerSummaries = $summaries;
    }

    public function getDataProvider(): DataProviderInterface
    {
        return $this->dataProvider;
    }

    /** The Twig environment backing the grid, e.g. for columns rendering a cell template. */
    public function getTwig(): Environment
    {
        return $this->twig;
    }

    public function setDataProvider(DataProviderInterface $dataProvider): static
    {
        $this->dataProvider = $dataProvider;

        return $this;
    }

    public function setDataProviderOptions(array $dataProviderOptions): void
    {
        // Deferred: prepareModels() runs in initializeDataProvider() so that
        // filter defaults declared in setColumns() are known regardless of the
        // setDataProvider()/setColumns() call order.
        $this->dataProviderOptions = $dataProviderOptions;
    }

    /**
     * The entity FQCN backing this grid (the data provider `model` option),
     * used as the data_class for generated CRUD forms. Null when unset.
     */
    public function getDataClass(): ?string
    {
        return $this->dataProviderOptions['model'] ?? null;
    }

    /** All rows matching the current filters/sort, unpaginated (for export). */
    public function getExportRows(): iterable
    {
        $this->initializeDataProvider();

        return $this->dataProvider->getAllData();
    }

    /**
     * Columns to export: those flagged `exportable`, or — when none is flagged —
     * the visible data columns (excluding structural/action columns).
     *
     * @return ColumnInterface[]
     */
    public function getExportColumns(): array
    {
        $columns = $this->columns->toArray();

        $flagged = array_values(array_filter($columns, static fn($c) => $c->isExportable()));
        if ($flagged !== []) {
            return $flagged;
        }

        return array_values(array_filter(
            $columns,
            static fn($c) => $c->isToggleable() && $c->getAttribute() !== null && $c->isVisible()
        ));
    }

    private function initializeDataProvider(): void
    {
        if ($this->dataProviderInitialized) {
            return;
        }
        $this->dataProviderInitialized = true;

        // Forward the configured form param name so the provider reads the request
        // filters under it (e.g. a global `formName: myform` override) instead of
        // the default 'fedaleForm'. Must precede setDefaultParams()/prepareModels(),
        // which both key off the form param name.
        $this->dataProvider->setFormName($this->options['behavior']['formName']);

        if ($this->defaultFilterParams !== []) {
            $this->dataProvider->setDefaultParams($this->defaultFilterParams);
        }

        if ($this->dataProviderOptions === null) {
            return;
        }

        // Forwarded before prepareModels() so the fallback QueryBuilder (used for
        // entities whose repository has no search()) is built with the right
        // alias and declarative filters.
        if (!empty($this->dataProviderOptions['alias'])) {
            $this->dataProvider->setAlias($this->dataProviderOptions['alias']);
        }
        if (!empty($this->dataProviderOptions['searchFields'])) {
            $this->dataProvider->setSearchFields($this->dataProviderOptions['searchFields']);
        }

        $this->dataProvider->prepareModels($this->dataProviderOptions['model']);

        // Sort config is grouped under `sort`: `map` (attribute definitions),
        // `default` (initial order) and `multiSort` (multi-column toggle).
        $sortOptions = $this->dataProviderOptions['sort'] ?? [];
        if (!empty($sortOptions['map'])) {
            $this->dataProvider->getSort()->setAttributes($sortOptions['map']);
        }
        if (!empty($sortOptions['default'])) {
            $this->dataProvider->getSort()->setDefaultSort($sortOptions['default']);
        }
        if (!empty($sortOptions['multiSort'])) {
            $this->dataProvider->getSort()->setEnableMultiSort(true);
        }
        if (!empty($this->dataProviderOptions['pagination'])) {
            $this->dataProvider->getPagination()->setAttributes($this->dataProviderOptions['pagination']);
        }
        if (!empty($this->dataProviderOptions['ignoredAttributes'])) {
            $this->dataProvider->setIgnoredAttributes($this->dataProviderOptions['ignoredAttributes']);
        }

        // Pin sort/pagination/filter links to an explicit list route so the grid
        // renders correctly even when handled by a different route (e.g. a CRUD
        // POST returning a Turbo Stream). Falls back to the current _route.
        if (!empty($this->options['integration']['routeName'])) {
            $this->dataProvider->getPagination()->setRoute($this->options['integration']['routeName']);
        }
    }

    public function getDefaultFilterParams(): array
    {
        return $this->defaultFilterParams;
    }

    public function getSearchModel(): ?SearchModelInterface
    {
        return $this->searchModel;
    }

    public function setSearchModel(?SearchModelInterface $searchModel): static
    {
        $this->searchModel = $searchModel;

        return $this;
    }

    /**
     * @param array<int, string|ColumnSpec> $columns
     */
    public function setColumns(array $columns): static
    {
        foreach ($columns as $key => $spec) {
            $column = $this->columnFactory->create($spec, $this, $key);
            $column->setGridview($this);

            // Inactive columns are dropped here, before any wiring: they never
            // reach the grid, its filters, the export or the CRUD form. This is
            // the access-control switch (who may see a column), distinct from
            // `visible` which only hides a registered column with CSS.
            if (!$column->isActive()) {
                continue;
            }

            if (isset($this->searchModel) && $column->isFilterable() && isset($column->filter)) {
                $options = $column->filter['options'] ?? [];
                // Consumed at render time by the filter template (emitted as a
                // Stimulus value), not a Symfony form option — drop it so the
                // filter type's OptionsResolver doesn't reject it.
                unset($options['controls_threshold']);
                if (isset($column->filter['clientOptions'])) {
                    $options['client_options'] = $column->filter['clientOptions'];
                }
                if (array_key_exists('default', $column->filter)) {
                    $default = FilterDefaultNormalizer::normalize($column->filter['type'], $column->filter['default'], $options);
                    $options['data'] ??= $default;
                    // Key mangling must mirror SearchForm::addFilter(), so the
                    // default param key matches the submitted param key
                    $this->defaultFilterParams[str_replace('.', '_', $column->getAttribute())] = $default;
                }
                $this->searchForm->addFilter($column->getAttribute(), $column->filter['type'], $options);
            }

            $this->addColumn($column);
        }

        return $this;
    }

    public function setOptions(array $options): void
    {
        if (isset($options['display']['layout'])) {
            $options['display']['layout'] = array_replace(
                $this->options['display']['layout'] ?? [],
                $options['display']['layout']
            );
        }
        if (isset($options['display']['renderer'])) {
            // A scalar renderer (e.g. `renderer: 'card'`) is shorthand for the
            // active strategy; normalize it into the {default, map} structure so
            // it merges instead of overwriting the whole renderer config.
            if (!is_array($options['display']['renderer'])) {
                $options['display']['renderer'] = ['default' => $options['display']['renderer']];
            }
            // Keep the `default` when a controller supplies only `map`; the `map`
            // itself is replaced wholesale (the controller owns the renderer set).
            $options['display']['renderer'] = array_replace(
                $this->options['display']['renderer'] ?? [],
                $options['display']['renderer']
            );
        }
        if (isset($options['display'])) {
            $this->options['display'] = array_replace($this->options['display'], $options['display']);
            unset($options['display']);
        }

        if (isset($options['behavior']['filterControls'])) {
            $options['behavior']['filterControls'] = array_replace(
                $this->options['behavior']['filterControls'] ?? [],
                $options['behavior']['filterControls']
            );
        }
        if (isset($options['behavior']['pagination'])) {
            $options['behavior']['pagination'] = array_replace(
                $this->options['behavior']['pagination'] ?? [],
                $options['behavior']['pagination']
            );
        }
        if (isset($options['behavior'])) {
            $this->options['behavior'] = array_replace($this->options['behavior'], $options['behavior']);
            unset($options['behavior']);
        }

        if (isset($options['integration'])) {
            $this->options['integration'] = array_replace($this->options['integration'], $options['integration']);
        }
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Inject the pre-resolved class map for the active theme (called by the
     * builder from the ThemeRegistry).
     *
     * @param array<string, string> $classMap
     */
    public function setClassMap(array $classMap): void
    {
        $this->classMap = $classMap;
    }

    /**
     * Resolve a presentational class key (e.g. 'btn.primary') to the active
     * theme's CSS classes, optionally appending extra literal classes/hooks.
     * Used in templates as `{{ gridview.cls('btn.primary') }}`.
     */
    public function cls(string $key, string $extra = ''): string
    {
        $classes = $this->classMap[$key] ?? '';

        return $extra !== '' ? trim($classes . ' ' . $extra) : $classes;
    }

    public function setAttributes(array $attributes): void
    {
        $this->rowAttr = $attributes['row'] ?? [];
        $this->containerAttr = $attributes['container'] ?? [];
        $this->headerAttr = $attributes['header'] ?? [];
        $this->filterAttr = $attributes['filter'] ?? [];

        unset($attributes['row'], $attributes['container'], $attributes['header'], $attributes['filter']);

        $this->attr = $attributes;
    }

    public function getUrlState(): GridviewUrlState
    {
        return $this->urlState;
    }

    /**
     * Active "chip" filters to render outside the table: one entry per index
     * column that opted into the `chip` clear mode AND currently has an applied
     * filter. Consumed by the `filterChips` layout section; the chip's close
     * button reuses the generic `gridview-filter#clearFilter` Stimulus action.
     *
     * @return list<array{field: string, label: ?string, display: ?string, icon: ?string}>
     */
    public function activeFilterChips(): array
    {
        $applied = $this->urlState->getFilters();
        $chips   = [];

        foreach ($this->getIndexColumns() as $column) {
            if (!$column instanceof DataColumn || !$column->isFilterable() || empty($column->filter)) {
                continue;
            }

            $clear = $column->getFilterClear();
            if (!\in_array('chip', $clear['mode'], true)) {
                continue;
            }

            $field = str_replace('.', '_', (string) $column->getAttribute());
            if (!\array_key_exists($field, $applied)) {
                continue;
            }

            // Best-effort human value: scalars are shown as-is; complex filters
            // (range/relation arrays) render the label only.
            $value   = $applied[$field];
            $display = \is_scalar($value) ? (string) $value : null;

            $chips[] = [
                'field'   => $field,
                'label'   => $column->getLabel(),
                'display' => $display,
                'icon'    => $clear['chipIcon'],
            ];
        }

        return $chips;
    }

    public function hasCheckboxColumn(): bool
    {
        return $this->columns->exists(fn($k, $col) => $col instanceof CheckboxColumn);
    }

    public function hasHiddenColumns(): bool
    {
        return $this->columns->exists(fn($k, $col) => !$col->isVisible());
    }

    /**
     * Columns to render in the filter bar, resolving the tri-state `filterBar`
     * flag against {@see isAutoBar()}:
     *   - filterBar === true  → always included (explicit opt-in);
     *   - filterBar === false → always excluded (explicit exclusion);
     *   - filterBar === null  → included only when autoBar is active AND the
     *                           column is filterable with a filter defined.
     *
     * @return array<int, \Fedale\GridviewBundle\Column\DataColumn>
     */
    public function getFilterBarColumns(): array
    {
        $autoBar = $this->isAutoBar();

        return $this->columns
            ->filter(
                fn($col) =>
                $col instanceof \Fedale\GridviewBundle\Column\DataColumn
                    && (
                        $col->filterBar === true
                        || ($autoBar
                            && $col->filterBar !== false
                            && $col->isFilterable()
                            && !empty($col->filter))
                    )
            )
            ->toArray();
    }

    public function hasFilterBar(): bool
    {
        return !empty($this->getFilterBarColumns());
    }

    public function parseLayout(string $section): array
    {
        preg_match_all('/\{(\w+)(?:\s+[^}]+)?\}/', $this->resolveLayout($section), $matches);

        return $matches[1];
    }

    /**
     * Like parseLayout(), but also extracts an optional per-token width given
     * inline in the layout string, e.g. "{globalSearch 40%} {export 20%}".
     * The width sizes the layout slot; the rendered control keeps its natural
     * size inside it. Returns a list of ['token' => string, 'width' => ?string].
     *
     * @return array<int, array{token: string, width: ?string}>
     */
    public function layoutTokens(string $section): array
    {
        preg_match_all('/\{(\w+)(?:\s+([^}]+?))?\s*\}/', $this->resolveLayout($section), $matches, PREG_SET_ORDER);

        $tokens = [];
        foreach ($matches as $match) {
            $tokens[] = [
                'token' => $match[1],
                'width' => isset($match[2]) ? $this->normalizeWidth($match[2]) : null,
            ];
        }

        return $tokens;
    }

    private function resolveLayout(string $section): string
    {
        $layout = $this->options['display']['layout'][$section] ?? null;

        if ($layout === null && $section === 'dataview') {
            $tokens = [];
            if ($this->options['display']['showThead']) {
                $tokens[] = '{thead}';
            }
            $tokens[] = '{filter}';
            $tokens[] = '{tbody}';
            if ($this->options['display']['showTfoot']) {
                $tokens[] = '{tfoot}';
            }
            $layout = implode(' ', $tokens);
        }

        $layout = (string) $layout;

        // Switchable grids: ensure the toolbar carries the view switcher and the
        // header-less sort/filter affordances, without clobbering a custom
        // layout. Additive and idempotent — a token already present (with or
        // without an inline width) is left untouched, and each of these collapses
        // to nothing when not applicable, so the toolbar stays stable across a
        // runtime view switch (see docs/05_layout.md "Token dynamics per renderer").
        if ($section === 'toolbar' && \count($this->getRenderers()) > 1) {
            foreach (['viewSwitcher', 'sortBar', 'filterBar'] as $token) {
                if (str_contains($layout, '{' . $token . '}') || str_contains($layout, '{' . $token . ' ')) {
                    continue;
                }
                $layout = $token === 'viewSwitcher'
                    ? trim('{viewSwitcher} ' . $layout)
                    : trim($layout . ' {' . $token . '}');
            }
        }

        return $layout;
    }

    /** Validates an inline width spec, returning a safe CSS length or null. */
    private function normalizeWidth(string $raw): ?string
    {
        $raw = trim($raw);

        if (preg_match('/^\d+(?:\.\d+)?$/', $raw)) {
            return $raw . '%';
        }

        return preg_match('/^\d+(?:\.\d+)?(?:%|px|rem|em)$/', $raw) ? $raw : null;
    }

    public function layoutTemplate(string $token): string
    {
        return $this->options['display']['layout']['templates'][$token]
            ?? "@FedaleGridview/gridview/sections/{$token}.html.twig";
    }

    public function isSlot(string $token): bool
    {
        return isset($this->options['display']['layout']['slots'][$token]);
    }

    public function slotContent(string $token): string
    {
        return $this->options['display']['layout']['slots'][$token] ?? '';
    }

    /**
     * A token is a region (recursive container) when a layout key of that name
     * exists — even with a null/empty value. The reserved sub-maps
     * (`templates`/`slots`/`attrs`) are configuration, not regions. The engine
     * dispatch ({@see OptionsExtension::includeToken()}) uses this to decide
     * whether to recurse into children or render a leaf block template.
     */
    /**
     * The ACTIVE data region renderer strategy, selecting the dataview template
     * sections/dataview/{renderer}.html.twig. It is the `view` chosen via the URL
     * when that view is in the allowed {@see getRenderers()} set, else the
     * configured `renderer.default` (falling back to the first mapped renderer,
     * then 'table'). Falls back to the default before the URL state is built
     * (i.e. outside renderGrid()).
     */
    public function getRenderer(): string
    {
        $map = $this->options['display']['renderer']['map'] ?? [];
        $default = $this->options['display']['renderer']['default'] ?? (array_key_first($map) ?? 'table');

        if (!isset($this->urlState)) {
            return $default;
        }

        $requested = $this->urlState->getView();
        if ($requested !== null && \in_array($requested, $this->getRenderers(), true)) {
            return $requested;
        }

        return $default;
    }

    /**
     * The views the user may switch between at runtime — the keys of the
     * configured `renderer.map`, in declaration order. The {viewSwitcher} shows a
     * button per entry when there is more than one; empty/single = single-view grid.
     *
     * @return list<string>
     */
    public function getRenderers(): array
    {
        return array_keys($this->options['display']['renderer']['map'] ?? []);
    }

    /**
     * The per-renderer option bag (e.g. `template`, `min`, `gap`, `titleField`)
     * for the named renderer, or an empty array when the renderer is not mapped.
     *
     * @return array<string, mixed>
     */
    public function rendererOptions(string $name): array
    {
        return $this->options['display']['renderer']['map'][$name] ?? [];
    }

    /**
     * Whether the filter bar auto-includes every filterable column. Resolves the
     * `filterControls.autoBar` flag: an explicit bool wins, otherwise it is
     * derived from the active renderer (ON for non-table views, which have no
     * header filters; OFF for table).
     */
    public function isAutoBar(): bool
    {
        $flag = $this->options['behavior']['filterControls']['autoBar'] ?? null;

        return $flag ?? ($this->getRenderer() !== 'table');
    }

    public function isRegion(string $token): bool
    {
        if (in_array($token, ['templates', 'slots', 'attrs'], true)) {
            return false;
        }

        return array_key_exists($token, $this->options['display']['layout']);
    }

    /**
     * HTML attributes for a region/internal, applied by its wrapper template via
     * `gridview.regionAttr(T)`. The legacy fixed bags map to canonical region
     * names (container→shell, the table-level attr→dataview, header→the header
     * region, filter/row→the table strategy internals); any other region reads
     * from the `layout.attrs[T]` map, which also overrides the legacy bags.
     *
     * @return array<string, mixed>
     */
    public function regionAttr(string $region): array
    {
        $base = match ($region) {
            'shell'    => $this->containerAttr,
            'dataview' => $this->attr,
            'header'   => $this->headerAttr,
            'filter'   => $this->filterAttr,
            'row'      => $this->rowAttr,
            default    => [],
        };

        $extra = $this->options['display']['layout']['attrs'][$region] ?? [];

        return $extra !== [] ? array_replace($base, $extra) : $base;
    }

    public function renderGrid(string $view, array $parameters = []): Response
    {
        $this->initializeDataProvider();

        $request = $this->gridviewService->getRequest();

        // Lazy grouping: a single parent's children, fetched on demand. Handled
        // before pagination/sort/URL-state — the child sub-table doesn't need any
        // of it, and the main grid's own page isn't necessarily involved.
        if ($this->isGrouped() && $request->query->has('_children')) {
            return $this->renderGroupChildren($request->query->get('_children'));
        }

        $formName = $this->options['behavior']['formName'];

        $this->urlState = GridviewUrlState::fromRequest(
            $request,
            $formName,
            $this->dataProvider->getSort()->getSortParam(),
            $this->dataProvider->getPagination()->getPageParamName(),
            $this->dataProvider->getPagination()->getPageSizeParam()
        );

        $globalFields = $this->options['behavior']['globalSearch'];

        if (isset($this->searchModel)) {
            if (!empty($globalFields)) {
                $this->searchForm->addGlobalSearch();
            }
            $this->searchForm->getModelType()->handleRequest($request);
            $parameters['form'] = $this->searchForm->getModelType()->createView();
        }

        if (!empty($globalFields)) {
            $q = trim($request->query->all($formName)['_q'] ?? '');
            if ($q !== '') {
                $this->dataProvider->applyGlobalSearch($globalFields, $q);
            }
        }

        // Let the active paginator strategy influence data fetching before getData()
        // (e.g. virtual scroll disabling paging). Pure-presentation strategies
        // (numeric, infinite) don't implement the capability and are left untouched.
        $paginationOptions = $this->options['behavior']['pagination'] ?? [];
        $strategy = $this->gridviewService->getPaginatorStrategyRegistry()
            ->get($paginationOptions['mode'] ?? 'numeric');
        if ($strategy instanceof PaginationConfiguringInterface) {
            $strategy->configurePagination($this->dataProvider->getPagination(), $paginationOptions);
        }

        $models = $this->dataProvider->getData();
        $this->computeFooterSummaries($models);
        if ($this->isGrouped()) {
            $this->applyGrouping($models);
        }

        $parameters = array_merge($parameters, [
            'gridview' => $this,
            'columns' => $this->columns,
            'models' => $models,
            'pagination' => $this->dataProvider->getPagination(),
        ]);

        $this->recordProfile($request, $view, $parameters['models'], $parameters['pagination']);

        // Infinite scroll: rows-only Turbo Stream for ?_rows=1 (append rows + replace
        // the infinite section). The requested page already drives getData(), so the
        // models in $parameters are the next page's rows.
        if ($this->options['behavior']['useTurbo'] && $request->query->getBoolean('_rows')) {
            return new Response(
                $this->twig->render('@FedaleGridview/gridview/sections/_rows_stream.html.twig', $parameters),
                Response::HTTP_OK,
                ['Content-Type' => 'text/vnd.turbo-stream.html'],
            );
        }

        $template = ($this->options['behavior']['useTurbo'] && $request->headers->has('Turbo-Frame'))
            ? '@FedaleGridview/gridview/_grid.html.twig'
            : $view;

        return new Response($this->twig->render($template, $parameters));
    }

    /**
     * Renders a single parent's children as an HTML fragment, answering the
     * `?_children=<key>` request the lazy Stimulus controller fetches on first
     * expand. Reuses the grid's own route/action (no dedicated route), so it
     * inherits the same authorization guards as the index it's called from.
     * The key itself is still attacker-controlled, so it's checked against
     * the data provider's own row-level scope before being resolved (see
     * {@see ScopeVerifiableInterface}).
     */
    private function renderGroupChildren(string|int $key): Response
    {
        $config = $this->getGroupingConfig();

        // A raw key from the client must be checked against the same
        // filters/restrictions the main list query applies — otherwise a
        // caller could walk arbitrary ids and read children of parent rows
        // outside their scope (e.g. another tenant's), bypassing whatever
        // row-level restriction the data provider's repository enforces.
        if ($this->dataProvider instanceof ScopeVerifiableInterface
            && !$this->dataProvider->isKeyInScope($key, $config->getParentKey())) {
            throw new NotFoundHttpException();
        }

        $resolver = $this->dataProvider instanceof GroupingCapableInterface
            ? $this->dataProvider->getChildResolver()
            : null;

        $row = new Row(0, 0);
        $row->isParent = true;
        $row->children = $resolver?->resolveForParent($key, $config) ?? [];

        $html = $this->twig->render($config->getTemplate(), [
            'row' => $row,
            'gridview' => $this,
            'childColumns' => $this->getChildColumns(),
        ]);

        return new Response($html);
    }

    /**
     * Flag the page's rows as grouping parents and, in eager mode, attach their
     * children up front in a single resolver pass. Lazy mode leaves children
     * empty — they are fetched on demand when a parent is expanded.
     *
     * @param iterable<\Fedale\GridviewBundle\Row\Row> $models
     */
    private function applyGrouping(iterable $models): void
    {
        $config   = $this->getGroupingConfig();
        $resolver = $this->dataProvider instanceof GroupingCapableInterface
            ? $this->dataProvider->getChildResolver()
            : null;

        foreach ($models as $row) {
            $row->isParent = true;
        }

        if ($resolver === null) {
            return;
        }

        if (!$config->isEager()) {
            if ($resolver instanceof ChildCountResolverInterface) {
                $counts = $resolver->countForParents($models, $config);
                foreach ($models as $row) {
                    $key = $row->data[$config->getParentKey()] ?? null;
                    $row->childCount = $key !== null ? ($counts[$key] ?? 0) : 0;
                }
            }

            return;
        }

        $childMap = $resolver->resolveForParents($models, $config);
        foreach ($models as $row) {
            $key = $row->data[$config->getParentKey()] ?? null;
            $row->children = $key !== null ? ($childMap[$key] ?? []) : [];
        }
    }

    /**
     * Capture a serializable snapshot of this render for the WebProfiler. A no-op
     * unless the profiler is enabled (production builds no snapshot or DQL).
     */
    private function recordProfile(Request $request, string $view, iterable $models, $pagination): void
    {
        $registry = $this->gridviewService->getProfileRegistry();
        if ($registry === null || !$registry->isEnabled()) {
            return;
        }

        $useTurbo = (bool) ($this->options['behavior']['useTurbo'] ?? false);
        $responseType = match (true) {
            $useTurbo && $request->query->getBoolean('_rows') => '_rows stream',
            $useTurbo && $request->headers->has('Turbo-Frame') => '_grid frame',
            default => 'full page',
        };

        $provider = $this->dataProvider;
        $query = method_exists($provider, 'getDebugQuery')
            ? $provider->getDebugQuery()
            : ['dql' => null, 'sql' => null, 'params' => [], 'rootAlias' => null];
        $filterPath = method_exists($provider, 'getFilterPath') ? $provider->getFilterPath() : 'unknown';
        $rawParams = method_exists($provider, 'getParams') ? $provider->getParams() : [];

        $sort = $provider->getSort();
        $sortData = [
            'orders' => $sort->fetchOrders(),
            'urlSort' => $this->urlState->getSort(),
            'multiSort' => method_exists($sort, 'isMultiSortEnabled') ? $sort->isMultiSortEnabled() : null,
        ];

        $paginationData = [
            'mode' => $this->options['behavior']['pagination']['mode'] ?? 'numeric',
            'page' => $pagination->getCurrentPage(),
            'pageSize' => $pagination->getPageSize(),
            'offset' => $pagination->getOffset(),
            'totalCount' => $pagination->getTotalCount(),
            'pageCount' => $pagination->getPageCount(),
            'pageSizeOptions' => $pagination->getPageSizeOptions(),
        ];

        $columns = [];
        foreach ($this->columns as $column) {
            $columns[] = [
                'label' => $column->getLabel(),
                'attribute' => $column->getAttribute(),
                'type' => (new \ReflectionClass($column))->getShortName(),
                'activeIndex' => $column->isActiveIn('index'),
                'sortable' => $column->isSortable(),
                'filterable' => $column->isFilterable(),
                'exportable' => $column->isExportable(),
                'visible' => $column->isVisible(),
                'editable' => $column->isEditable(),
                'filterBar' => $column instanceof DataColumn ? $column->filterBar : null,
            ];
        }

        $filterBarColumns = array_map(
            static fn(DataColumn $column): string => (string) $column->getAttribute(),
            $this->getFilterBarColumns(),
        );

        $registry->record(new GridviewProfile(
            key: $this->getKey(),
            id: $this->getId(),
            responseType: $responseType,
            route: $this->options['integration']['routeName'] ?? null,
            template: match ($responseType) {
                '_rows stream' => '@FedaleGridview/gridview/sections/_rows_stream.html.twig',
                '_grid frame' => '@FedaleGridview/gridview/_grid.html.twig',
                default => $view,
            },
            theme: $this->theme,
            options: $this->options,
            formName: (string) ($this->options['behavior']['formName'] ?? 'fedaleForm'),
            filterPath: $filterPath,
            globalSearch: $this->urlState->getGlobalSearch(),
            rawParams: $rawParams,
            filters: $this->urlState->getFilters(),
            query: $query,
            sort: $sortData,
            pagination: $paginationData,
            renderer: $this->getRenderer(),
            renderers: $this->getRenderers(),
            autoBar: $this->isAutoBar(),
            filterBarColumns: array_values($filterBarColumns),
            rowsOnPage: is_countable($models) ? count($models) : iterator_count($models),
            columns: $columns,
        ));
    }
}
