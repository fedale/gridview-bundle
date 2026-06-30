<?php

namespace Fedale\GridviewBundle\Grid;

use Doctrine\Common\Collections\ArrayCollection;
use Fedale\GridviewBundle\Column\CheckboxColumn;
use Fedale\GridviewBundle\Column\ColumnFactory;
use Fedale\GridviewBundle\Contract\ColumnInterface;
use Fedale\GridviewBundle\Contract\DataProviderInterface;
use Fedale\GridviewBundle\Contract\GridviewInterface;
use Fedale\GridviewBundle\Contract\PaginationConfiguringInterface;
use Fedale\GridviewBundle\Contract\SearchFormInterface;
use Fedale\GridviewBundle\Contract\SearchModelInterface;
use Fedale\GridviewBundle\Filter\FilterDefaultNormalizer;
use Fedale\GridviewBundle\Grid\State\GridviewUrlState;
use Fedale\GridviewBundle\Service\GridviewService;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

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

    protected array $options = [
        'caption' => null,
        'title' => null,
        'renderer' => 'table',
        'emptyText' => 'No records found',
        'showThead' => true,
        'showTfoot' => true,
        'useTurbo' => true,
        'globalSearch' => [],
        'routeName' => null,
        'addRoute' => null,
        'addLabel' => 'Add',
        'formName' => 'fedaleForm',
        // Single source of truth — see GridviewConfigRegistry::LAYOUT_DEFAULTS.
        'layout' => GridviewConfigRegistry::LAYOUT_DEFAULTS,
        'filterControls' => [
            'headerIcon' => true,
            'inlineClear' => false,
            // Multi/relation filters: hide the search box and the
            // select/deselect/invert toolbar when a column has fewer than this
            // many options. Overridable per column via filter.options.controls_threshold.
            'choiceControlsThreshold' => 20,
        ],
        'pagination' => [
            'pageSelect' => true,
            'pageSelectThreshold' => 10,
        ],
        'maxQueryLength' => 4000,
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
            $this->key = $this->prefix . static::$counter++;
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
     * The entity FQCN backing this grid (the data provider `models` option),
     * used as the data_class for generated CRUD forms. Null when unset.
     */
    public function getDataClass(): ?string
    {
        return $this->dataProviderOptions['models'] ?? null;
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

        $this->dataProvider->prepareModels($this->dataProviderOptions['models']);

        if (!empty($this->dataProviderOptions['sort'])) {
            $this->dataProvider->getSort()->setAttributes($this->dataProviderOptions['sort']);
        }
        if (!empty($this->dataProviderOptions['defaultSort'])) {
            $this->dataProvider->getSort()->setDefaultSort($this->dataProviderOptions['defaultSort']);
        }
        if (!empty($this->dataProviderOptions['enableMultiSort'])) {
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
        if (!empty($this->options['routeName'])) {
            $this->dataProvider->getPagination()->setRoute($this->options['routeName']);
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
        if (isset($options['layout'])) {
            $options['layout'] = array_replace($this->options['layout'] ?? [], $options['layout']);
        }
        if (isset($options['filterControls'])) {
            $options['filterControls'] = array_replace($this->options['filterControls'] ?? [], $options['filterControls']);
        }
        if (isset($options['pagination'])) {
            $options['pagination'] = array_replace($this->options['pagination'] ?? [], $options['pagination']);
        }
        $this->options = array_merge($this->options, $options);
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

    public function hasCheckboxColumn(): bool
    {
        return $this->columns->exists(fn($k, $col) => $col instanceof CheckboxColumn);
    }

    public function hasHiddenColumns(): bool
    {
        return $this->columns->exists(fn($k, $col) => !$col->isVisible());
    }

    public function getFilterBarColumns(): array
    {
        return $this->columns
            ->filter(
                fn($col) =>
                $col instanceof \Fedale\GridviewBundle\Column\DataColumn
                    && $col->isInFilterBar()
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
        $layout = $this->options['layout'][$section] ?? null;

        if ($layout === null && $section === 'dataview') {
            $tokens = [];
            if ($this->options['showThead']) {
                $tokens[] = '{thead}';
            }
            $tokens[] = '{filter}';
            $tokens[] = '{tbody}';
            if ($this->options['showTfoot']) {
                $tokens[] = '{tfoot}';
            }
            $layout = implode(' ', $tokens);
        }

        return (string) $layout;
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
        return $this->options['layout']['templates'][$token]
            ?? "@FedaleGridview/gridview/sections/{$token}.html.twig";
    }

    public function isSlot(string $token): bool
    {
        return isset($this->options['layout']['slots'][$token]);
    }

    public function slotContent(string $token): string
    {
        return $this->options['layout']['slots'][$token] ?? '';
    }

    /**
     * A token is a region (recursive container) when a layout key of that name
     * exists — even with a null/empty value. The reserved sub-maps
     * (`templates`/`slots`/`attrs`) are configuration, not regions. The engine
     * dispatch ({@see OptionsExtension::includeToken()}) uses this to decide
     * whether to recurse into children or render a leaf block template.
     */
    /**
     * The data region renderer strategy ('table' today; 'card'/'list' planned).
     * Selects the dataview template sections/dataview/{renderer}.html.twig.
     */
    public function getRenderer(): string
    {
        return $this->options['renderer'] ?? 'table';
    }

    public function isRegion(string $token): bool
    {
        if (in_array($token, ['templates', 'slots', 'attrs'], true)) {
            return false;
        }

        return array_key_exists($token, $this->options['layout']);
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

        $extra = $this->options['layout']['attrs'][$region] ?? [];

        return $extra !== [] ? array_replace($base, $extra) : $base;
    }

    public function renderGrid(string $view, array $parameters = []): Response
    {
        $this->initializeDataProvider();

        $request = $this->gridviewService->getRequest();
        $formName = $this->options['formName'];

        $this->urlState = GridviewUrlState::fromRequest(
            $request,
            $formName,
            $this->dataProvider->getSort()->getSortParam(),
            $this->dataProvider->getPagination()->getPageParamName()
        );

        $globalFields = $this->options['globalSearch'];

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
        $paginationOptions = $this->options['pagination'] ?? [];
        $strategy = $this->gridviewService->getPaginatorStrategyRegistry()
            ->get($paginationOptions['mode'] ?? 'numeric');
        if ($strategy instanceof PaginationConfiguringInterface) {
            $strategy->configurePagination($this->dataProvider->getPagination(), $paginationOptions);
        }

        $parameters = array_merge($parameters, [
            'gridview' => $this,
            'columns' => $this->columns,
            'models' => $this->dataProvider->getData(),
            'pagination' => $this->dataProvider->getPagination(),
        ]);

        // Infinite scroll: rows-only Turbo Stream for ?_rows=1 (append rows + replace
        // the infinite section). The requested page already drives getData(), so the
        // models in $parameters are the next page's rows.
        if ($this->options['useTurbo'] && $request->query->getBoolean('_rows')) {
            return new Response(
                $this->twig->render('@FedaleGridview/gridview/sections/_rows_stream.html.twig', $parameters),
                Response::HTTP_OK,
                ['Content-Type' => 'text/vnd.turbo-stream.html'],
            );
        }

        $template = ($this->options['useTurbo'] && $request->headers->has('Turbo-Frame'))
            ? '@FedaleGridview/gridview/_grid.html.twig'
            : $view;

        return new Response($this->twig->render($template, $parameters));
    }
}
