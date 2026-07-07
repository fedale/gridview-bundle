<?php
namespace Fedale\GridviewBundle\Grid;

use Fedale\GridviewBundle\Column\ColumnFactory;
use Fedale\GridviewBundle\Contract\GridviewBuilderInterface;
use Fedale\GridviewBundle\Contract\SearchModelInterface;
use Fedale\GridviewBundle\Service\GridviewService;
use Fedale\GridviewBundle\Theme\ThemeRegistry;

/**
 * @phpstan-import-type ColumnSpec from \Fedale\GridviewBundle\Column\ColumnFactory
 */
class GridviewBuilder implements GridviewBuilderInterface
{
    private SearchModelInterface $searchModel;

    private Gridview $gridview;

    private array $runtimeOptions = [];

    private array $runtimeAttributes = [];

    public function __construct(
        private GridviewService $gridviewService,
        private GridviewConfigRegistry $configRegistry,
        private ColumnFactory $columnFactory,
        private ThemeRegistry $themeRegistry,
    ) {
        $this->reset();
    }

    public function reset(): void
    {
        $this->gridview = new Gridview($this->gridviewService, $this->columnFactory);
        $this->runtimeOptions = [];
        $this->runtimeAttributes = [];
    }

    public function setId(string $id): static
    {
        $this->gridview->setId($id);
        return $this;
    }

    /**
     * @param array<int, string|ColumnSpec> $columns
     */
    public function setColumns(array $columns)
    {
        $this->gridview->setColumns($columns);

        return $this;
    }

    public function setSearchModel(SearchModelInterface $searchModel)
    {
        $this->gridview->setSearchModel($searchModel);

        return $this;
    }

    public function setDataProvider(array $dataProviderOptions): GridviewBuilderInterface
    {
        $this->gridview->setDataProviderOptions($dataProviderOptions);

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
