<?php

namespace Fedale\GridviewBundle\Grid;

use Doctrine\Common\Collections\ArrayCollection;
use Fedale\GridviewBundle\Column\CheckboxColumn;
use Fedale\GridviewBundle\Column\ColumnFactory;
use Fedale\GridviewBundle\Contract\ColumnInterface;
use Fedale\GridviewBundle\Contract\DataProviderInterface;
use Fedale\GridviewBundle\Contract\GridviewInterface;
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
    protected ?string $id  = null;
    protected string $prefix = 'grid_';
    protected static int $counter = 0;

    public string $emptyCell = '&nbsp;';

    public array $attr         = [];
    public array $containerAttr = [];
    public array $headerAttr   = [];
    public array $filterAttr   = [];
    public array $rowAttr      = [];
    public $rowOptions         = [];

    public ?SearchModelInterface $searchModel = null;

    protected array $options = [
        'caption'      => null,
        'emptyText'    => 'No records found',
        'showThead'    => true,
        'showTfoot'    => true,
        'useTurbo'     => true,
        'globalSearch' => [],
        'routeName'    => null,
        'addRoute'     => null,
        'addLabel'     => 'Add',
        'formName'     => 'myform',
        'layout'       => [
            'gridview'  => '{header} {table} {footer}',
            'header'    => '{globalSearch} {filterSubmit}',
            'toolbar'   => '',
            'table'     => null,
            'footer'    => '{pagination}',
            'tfoot'     => '',
            'templates' => [],
            'slots'     => [],
        ],
        'filterControls' => [
            'headerIcon'  => true,
            'inlineClear' => false,
        ],
        'pagination' => [
            'pageSelect'          => true,
            'pageSelectThreshold' => 10,
        ],
        'maxQueryLength' => 4000,
    ];

    public function __construct(
        private GridviewService $gridviewService,
        private ColumnFactory $columnFactory
    ) {
        $this->columns      = new ArrayCollection();
        $this->twig         = $this->gridviewService->getEnvironment();
        $this->searchForm   = $this->gridviewService->getSearchForm();
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

    public function addColumn(ColumnInterface $column): void
    {
        $this->columns->add($column);
    }

    public function getDataProvider(): DataProviderInterface
    {
        return $this->dataProvider;
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

        $flagged = array_values(array_filter($columns, static fn ($c) => $c->isExportable()));
        if ($flagged !== []) {
            return $flagged;
        }

        return array_values(array_filter(
            $columns,
            static fn ($c) => $c->isToggleable() && $c->getAttribute() !== null && $c->isVisible()
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

        $this->dataProvider->prepareModels($this->dataProviderOptions['models']);

        if (!empty($this->dataProviderOptions['sort'])) {
            $this->dataProvider->getSort()->setAttributes($this->dataProviderOptions['sort']);
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

            if (isset($this->searchModel) && $column->isFilterable() && isset($column->filter)) {
                $options = $column->filter['options'] ?? [];
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
        $this->options = array_merge($this->options, $options);
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    public function setAttributes(array $attributes): void
    {
        $this->rowAttr       = $attributes['row']       ?? [];
        $this->containerAttr = $attributes['container'] ?? [];
        $this->headerAttr    = $attributes['header']    ?? [];
        $this->filterAttr    = $attributes['filter']    ?? [];

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
            ->filter(fn($col) =>
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
        $layout = $this->options['layout'][$section] ?? null;

        if ($layout === null && $section === 'table') {
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

        preg_match_all('/\{(\w+)\}/', (string) $layout, $matches);

        return $matches[1];
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

    public function renderGrid(string $view, array $parameters = []): Response
    {
        $this->initializeDataProvider();

        $request  = $this->gridviewService->getRequest();
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

        $parameters = array_merge($parameters, [
            'gridview'   => $this,
            'columns'    => $this->columns,
            'models'     => $this->dataProvider->getData(),
            'pagination' => $this->dataProvider->getPagination(),
        ]);

        $template = ($this->options['useTurbo'] && $request->headers->has('Turbo-Frame'))
            ? '@FedaleGridview/gridview/_grid.html.twig'
            : $view;

        return new Response($this->twig->render($template, $parameters));
    }
}
