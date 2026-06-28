<?php

namespace Fedale\GridviewBundle\Grid;

use Fedale\GridviewBundle\Column\ColumnFactory;
use Fedale\GridviewBundle\Service\GridviewService;
use Fedale\GridviewBundle\Theme\ThemeRegistry;

class GridviewBuilderFactory
{
    public function __construct(
        private GridviewService $gridviewService,
        private GridviewConfigRegistry $configRegistry,
        private ColumnFactory $columnFactory,
        private ThemeRegistry $themeRegistry,
    ) {}

    public function createGridviewBuilder(): GridviewBuilder
    {
        return new GridviewBuilder($this->gridviewService, $this->configRegistry, $this->columnFactory, $this->themeRegistry);
    }

    public function createDetailViewBuilder(): DetailViewBuilder
    {
        return new DetailViewBuilder($this->gridviewService, $this->configRegistry, $this->columnFactory);
    }
}
