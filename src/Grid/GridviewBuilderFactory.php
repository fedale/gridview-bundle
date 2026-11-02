<?php

namespace Fedale\GridviewBundle\Grid;

use Fedale\GridviewBundle\Column\ColumnFactory;
use Fedale\GridviewBundle\DataProvider\DataSourceDataProviderFactory;
use Fedale\GridviewBundle\Grid\Options\GridOptionsValidator;
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
        private ?ContainerInterface $dataSourceLocator = null,
        private ?DataSourceDataProviderFactory $dataSourceProviderFactory = null,
        private ?GridOptionsValidator $optionsValidator = null,
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
            $this->dataSourceLocator,
            $this->dataSourceProviderFactory,
            $this->optionsValidator,
        );
    }

    public function createDetailViewBuilder(): DetailViewBuilder
    {
        return new DetailViewBuilder($this->gridviewService, $this->configRegistry, $this->columnFactory);
    }
}
