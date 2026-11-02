<?php
namespace Fedale\GridviewBundle\Grid;

use Fedale\GridviewBundle\Column\ColumnFactory;
use Fedale\GridviewBundle\Column\Config\ColumnConfigInterface;
use Fedale\GridviewBundle\Contract\GridDataSourceInterface;
use Fedale\GridviewBundle\Contract\GridviewBuilderInterface;
use Fedale\GridviewBundle\Contract\SearchModelInterface;
use Fedale\GridviewBundle\DataProvider\DataSourceDataProviderFactory;
use Fedale\GridviewBundle\Grid\Options\GridOptionsValidator;
use Fedale\GridviewBundle\Service\GridviewService;
use Fedale\GridviewBundle\Theme\ThemeRegistry;
use Fedale\GridviewBundle\UiSettings\UiSettingsResolver;
use Psr\Container\ContainerInterface;

/**
 * @phpstan-import-type ColumnSpec from \Fedale\GridviewBundle\Column\ColumnFactory
 */
class GridviewBuilder implements GridviewBuilderInterface
{
    private SearchModelInterface $searchModel;

    private Gridview $gridview;

    private array $runtimeOptions = [];

    private array $runtimeAttributes = [];

    private array $runtimeDataProviderOptions = [];

    public function __construct(
        private GridviewService $gridviewService,
        private GridviewConfigRegistry $configRegistry,
        private ColumnFactory $columnFactory,
        private ThemeRegistry $themeRegistry,
        private ContainerInterface $dataProviderLocator,
        private ?UiSettingsResolver $uiSettings = null,
        private ?ContainerInterface $dataSourceLocator = null,
        private ?DataSourceDataProviderFactory $dataSourceProviderFactory = null,
        // Strict mode only (see `strict_options`): null skips validation.
        private ?GridOptionsValidator $optionsValidator = null,
    ) {
        $this->reset();
    }

    public function reset(): void
    {
        $this->gridview = new Gridview($this->gridviewService, $this->columnFactory);
        $this->runtimeOptions = [];
        $this->runtimeAttributes = [];
        $this->runtimeDataProviderOptions = [];
    }

    public function setId(string $id): static
    {
        $this->gridview->setId($id);
        return $this;
    }

    /**
     * @param array<int, string|ColumnSpec|ColumnConfigInterface> $columns
     */
    public function setColumns(array $columns): static
    {
        $this->gridview->setColumns($columns);

        return $this;
    }

    public function setSearchModel(SearchModelInterface $searchModel): static
    {
        $this->gridview->setSearchModel($searchModel);

        return $this;
    }

    public function setDataProvider(array $dataProviderOptions): static
    {
        // Buffered rather than pushed straight to the Gridview: `pagination`
        // still needs to be merged with the YAML-resolved bundle/per-grid
        // defaults, which requires the id — only known once renderGridview()
        // runs.
        $this->runtimeDataProviderOptions = $dataProviderOptions;

        return $this;
    }

    public function setOptions(array $options): static
    {
        $this->runtimeOptions = array_replace($this->runtimeOptions, $options);

        return $this;
    }

    public function setAttributes(array $attributes)
    {
        $this->runtimeAttributes = array_replace($this->runtimeAttributes, $attributes);

        return $this;
    }

    public function renderGridview(): Gridview
    {
        $id = $this->gridview->getId();

        $yamlOptions    = $this->configRegistry->resolveOptions($id);
        $yamlAttributes = $this->configRegistry->resolveAttributes($id);

        // The controller's own dataConfig()['pagination'] (defaultPageSize/
        // pageSizeOptions/maxPageSize) wins per-key over the YAML-resolved
        // bundle/per-grid defaults — same precedence as display/behavior below.
        // Guarded on setDataProvider() having actually been called: Gridview
        // treats a null dataProviderOptions as "no data provider at all" and
        // skips initialization entirely, a distinction an unconditional push
        // here would erase.
        if ($this->runtimeDataProviderOptions !== []) {
            $paginationDefaults = $this->configRegistry->resolveDataProviderPagination($id);
            $dataProviderOptions = $this->runtimeDataProviderOptions;
            $dataProviderOptions['pagination'] = array_replace($paginationDefaults, $dataProviderOptions['pagination'] ?? []);
            $this->gridview->setDataProviderOptions($dataProviderOptions);

            if (isset($dataProviderOptions['dataSource'])) {
                $this->useDataSource($id, $dataProviderOptions);
            } else {
                $this->useConfiguredDataProvider($id);
            }
        }

        // Merge group-by-group (display/behavior/integration), not with a single
        // top-level array_replace: the latter would let a runtime override of
        // e.g. just `behavior.pagination` wholesale-replace the entire `behavior`
        // group — silently dropping unrelated YAML-resolved keys like `useTurbo`
        // or `formName`. Gridview::setOptions() then does its own finer-grained
        // merge for the nested sub-keys that need it (layout, filterControls, ...).
        $merged = $yamlOptions;
        foreach (['display', 'behavior', 'integration'] as $group) {
            if (isset($this->runtimeOptions[$group])) {
                $merged[$group] = array_replace($yamlOptions[$group] ?? [], $this->runtimeOptions[$group]);
            }
        }
        // End-user UI settings (global, then per grid) win over code and YAML,
        // leaf by leaf, and only where the grid can honour them.
        if ($this->uiSettings !== null && $id !== null) {
            $merged = $this->uiSettings->apply($id, $merged);
        }
        // Checked before the merge into the grid: Gridview::setOptions() keeps
        // only the three groups, so a misplaced top-level key would be gone by
        // then, and everything else it would keep without complaint.
        $this->optionsValidator?->assertValid($merged, $id);
        $this->gridview->setOptions($merged);
        $this->gridview->setAttributes($this->mergeAttributes($yamlAttributes, $this->runtimeAttributes));

        // Resolve the framework theme: pre-build its class map for cls() and
        // expose the name on the container ([data-gv-framework]) for CSS presets.
        $theme = $this->gridview->getOptions()['display']['theme'] ?? 'default';
        $this->gridview->theme = $theme;
        $this->gridview->setClassMap($this->themeRegistry->classMap($theme));
        if ($theme !== 'default') {
            $this->gridview->containerAttr['data-gv-framework'] = $theme;
        }

        return $this->gridview;
    }

    /**
     * Swap the default (Doctrine-backed) provider for a config-declared one,
     * resolved by service id — no controller code needed. Any service
     * implementing DataProviderInterface qualifies, built-in or host-app
     * custom, as long as it's reachable through the locator (autoconfigured
     * services get the required tag automatically, see
     * FedaleGridviewBundle::loadExtension()).
     */
    private function useConfiguredDataProvider(?string $id): void
    {
        $dataProviderId = $this->configRegistry->resolveDataProvider($id);
        if ($dataProviderId === null) {
            return;
        }

        if (!$this->dataProviderLocator->has($dataProviderId)) {
            throw new \InvalidArgumentException(sprintf(
                'Gridview "%1$s" is configured with dataProvider "%2$s", but no service with that id implements DataProviderInterface (it must be autoconfigured, or tagged "fedale_gridview.data_provider"). Check "defaults.dataProvider" / "gridviews.%1$s.dataProvider".',
                $id,
                $dataProviderId,
            ));
        }
        $this->gridview->setDataProvider($this->dataProviderLocator->get($dataProviderId));
    }

    /**
     * Power the grid from the GridDataSourceInterface named by
     * dataConfig()['dataSource'] (an instance, or a service id), through a
     * DataSourceDataProvider built for this grid alone.
     *
     * Runs after setDataProviderOptions(), so the `search.fields` filters have
     * been registered (or skipped) by then and the search-model check below
     * sees them as well as the column filters.
     */
    /**
     * @param array<string, mixed> $dataProviderOptions
     */
    private function useDataSource(?string $id, array $dataProviderOptions): void
    {
        $grid = $id ?? '?';

        // dataSource overrides an inherited defaults.dataProvider (a host may
        // set a global one), but contradicts a provider declared for this grid.
        if (isset($dataProviderOptions['dataProvider']) || $this->configRegistry->resolveGridDataProvider($id) !== null) {
            throw new \InvalidArgumentException(sprintf(
                'Gridview "%s" declares both a dataSource and a dataProvider. Keep one: a dataSource is wrapped in its own data provider.',
                $grid,
            ));
        }

        // search.appliers write DQL, which a data source never runs: they
        // would be ignored without a word. search.builders is their twin here.
        if (!empty($dataProviderOptions['search']['appliers'])) {
            throw new \InvalidArgumentException(sprintf(
                'Gridview "%s" uses a dataSource, which cannot run the DQL appliers of "search.appliers". Declare criteria builders in "search.builders" instead.',
                $grid,
            ));
        }

        // Too late to set a default search model here: the column filters were
        // registered (or dropped) when setColumns() ran. Refuse the grid rather
        // than render it with its filters silently missing.
        $skipped = $this->gridview->getFiltersSkippedForMissingSearchModel();
        if ($skipped !== []) {
            throw new \LogicException(sprintf(
                'Gridview "%s" declares filters (%s) but has no search model, so they were dropped. Call setSearchModel() before setColumns().',
                $grid,
                implode(', ', $skipped),
            ));
        }

        if ($this->dataSourceProviderFactory === null) {
            throw new \LogicException(sprintf('Gridview "%s" uses a dataSource, but the builder was created without a DataSourceDataProviderFactory.', $grid));
        }

        $this->gridview->setDataProvider($this->dataSourceProviderFactory->create(
            $this->resolveDataSource($grid, $dataProviderOptions['dataSource']),
            $id,
            $dataProviderOptions['search']['builders'] ?? [],
            $dataProviderOptions['identifier'] ?? 'id',
        ));
    }

    private function resolveDataSource(string $grid, mixed $dataSource): GridDataSourceInterface
    {
        if ($dataSource instanceof GridDataSourceInterface) {
            return $dataSource;
        }

        if (!\is_string($dataSource) || $this->dataSourceLocator === null || !$this->dataSourceLocator->has($dataSource)) {
            throw new \InvalidArgumentException(sprintf(
                'Gridview "%s" is configured with dataSource "%s", which is neither a GridDataSourceInterface instance nor the id of a service implementing it (it must be autoconfigured, or tagged "fedale_gridview.data_source").',
                $grid,
                \is_string($dataSource) ? $dataSource : get_debug_type($dataSource),
            ));
        }

        return $this->dataSourceLocator->get($dataSource);
    }

    private function mergeAttributes(array $yaml, array $runtime): array
    {
        if (isset($runtime['class'])) {
            $yaml['class'] = $runtime['class'];
        }
        foreach (['row', 'container', 'header', 'filter'] as $key) {
            if (!empty($runtime[$key])) {
                $yaml[$key] = array_replace($yaml[$key] ?? [], $runtime[$key]);
            }
        }
        $knownKeys = ['class', 'row', 'container', 'header', 'filter'];
        foreach ($runtime as $k => $v) {
            if (!in_array($k, $knownKeys, true)) {
                $yaml[$k] = $v;
            }
        }
        // Don't pass null class to setAttributes
        if (array_key_exists('class', $yaml) && $yaml['class'] === null) {
            unset($yaml['class']);
        }
        return $yaml;
    }
}
