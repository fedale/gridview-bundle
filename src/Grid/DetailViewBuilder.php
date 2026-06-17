<?php

namespace Fedale\GridviewBundle\Grid;

use Fedale\GridviewBundle\Column\ColumnFactory;
use Fedale\GridviewBundle\Contract\DetailViewBuilderInterface;
use Fedale\GridviewBundle\Service\GridviewService;

/**
 * Stateful, single-use fluent builder for {@see DetailView}, mirroring
 * {@see GridviewBuilder}. Obtain one per request from
 * {@see GridviewBuilderFactory::createDetailViewBuilder()}.
 */
class DetailViewBuilder implements DetailViewBuilderInterface
{
    private DetailView $detailview;

    /**
     * Throwaway Gridview used solely as the ColumnFactory's required owner when
     * instantiating columns (a DetailView is not a Gridview). Created lazily,
     * only when columns are actually set, so a detail can be built without a
     * fully-wired GridviewService when no columns are involved. Detail rendering
     * never touches it: {@see \Fedale\GridviewBundle\Column\DataColumn::render()}
     * uses the model, not the gridview — only sort-header rendering would, and a
     * detail view has neither headers nor sort.
     */
    private ?Gridview $columnContext = null;

    private array $runtimeOptions = [];

    private array $runtimeAttributes = [];

    public function __construct(
        private GridviewService $gridviewService,
        private GridviewConfigRegistry $configRegistry,
        private ColumnFactory $columnFactory,
    ) {
        $this->reset();
    }

    public function reset(): void
    {
        $this->detailview        = new DetailView($this->gridviewService, $this->columnFactory);
        $this->columnContext     = null;
        $this->runtimeOptions    = [];
        $this->runtimeAttributes = [];
    }

    public function setId(string $id): static
    {
        $this->detailview->setId($id);

        return $this;
    }

    public function setModel(object $model): static
    {
        $this->detailview->setModel($model);

        return $this;
    }

    /**
     * Reuses the SAME column definitions as the grid (same `buildColumns()`
     * array), instantiated through the ColumnFactory. Unlike
     * {@see Gridview::setColumns()} it wires NO filters/sort/searchForm: a detail
     * view only renders cells.
     */
    public function setColumns(array $columns): static
    {
        foreach ($columns as $key => $spec) {
            $this->detailview->addColumn(
                $this->columnFactory->create($spec, $this->columnContext(), $key)
            );
        }

        return $this;
    }

    public function setOptions(array $options): static
    {
        $this->runtimeOptions = array_replace($this->runtimeOptions, $options);

        return $this;
    }

    public function setAttributes(array $attributes): static
    {
        $this->runtimeAttributes = array_replace($this->runtimeAttributes, $attributes);

        return $this;
    }

    public function renderDetailView(): DetailView
    {
        $id = $this->detailview->getId();

        // Same merge contract as the grid: YAML sits UNDER the runtime layer.
        $yamlOptions    = $this->configRegistry->resolveDetailOptions($id);
        $yamlAttributes = $this->configRegistry->resolveDetailAttributes($id);

        $this->detailview->setOptions(array_replace($yamlOptions, $this->runtimeOptions));
        $this->detailview->setAttributes(array_replace($yamlAttributes, $this->runtimeAttributes));

        return $this->detailview;
    }

    private function columnContext(): Gridview
    {
        return $this->columnContext ??= new Gridview($this->gridviewService, $this->columnFactory);
    }
}
