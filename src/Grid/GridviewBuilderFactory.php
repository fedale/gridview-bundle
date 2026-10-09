<?php

namespace Fedale\GridviewBundle\Grid;

use Fedale\GridviewBundle\Column\ColumnFactory;
use Fedale\GridviewBundle\Service\GridviewService;
use Fedale\GridviewBundle\Theme\ThemeRegistry;
use Fedale\GridviewBundle\UiSettings\UiSettingsResolver;
use Psr\Container\ContainerInterface;

class GridviewBuilderFactory
{
    public function __construct(
        private GridviewService $gridviewService,
        private GridviewConfigRegistry $configRegistry,
        private ColumnFactory $columnFactory,
        private ThemeRegistry $themeRegistry,
        private ContainerInterface $dataProviderLocator,
        private ?UiSettingsResolver $uiSettings = null,
    ) {}

    public function createGridviewBuilder(): GridviewBuilder
    {
        return new GridviewBuilder(
            $this->gridviewService,
            $this->configRegistry,
            $this->columnFactory,
            $this->themeRegistry,
            $this->dataProviderLocator,
            $this->uiSettings,
        );
    }

    public function createDetailViewBuilder(): DetailViewBuilder
    {
        return new DetailViewBuilder($this->gridviewService, $this->configRegistry, $this->columnFactory);
    }
}
