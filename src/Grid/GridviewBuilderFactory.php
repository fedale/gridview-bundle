<?php

namespace Fedale\GridviewBundle\Grid;

use Fedale\GridviewBundle\Column\ColumnFactory;
use Fedale\GridviewBundle\Service\GridviewService;

class GridviewBuilderFactory
{
    public function __construct(
        private GridviewService $gridviewService,
        private GridviewConfigRegistry $configRegistry,
        private ColumnFactory $columnFactory,
    ) {}

    public function createGridviewBuilder(): GridviewBuilder
    {
        return new GridviewBuilder($this->gridviewService, $this->configRegistry, $this->columnFactory);
    }

    public function createDetailViewBuilder(): DetailViewBuilder
    {
        return new DetailViewBuilder($this->gridviewService, $this->configRegistry, $this->columnFactory);
    }
}
