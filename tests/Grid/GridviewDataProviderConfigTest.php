<?php

namespace Fedale\GridviewBundle\Tests\Grid;

use Fedale\GridviewBundle\Column\ColumnFactory;
use Fedale\GridviewBundle\Contract\DataProviderInterface;
use Fedale\GridviewBundle\Form\SearchForm;
use Fedale\GridviewBundle\Grid\GridviewBuilder;
use Fedale\GridviewBundle\Grid\GridviewConfigRegistry;
use Fedale\GridviewBundle\Service\GridviewService;
use Fedale\GridviewBundle\Theme\ThemeRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

class GridviewDataProviderConfigTest extends TestCase
{
    public function testResolveDataProviderIsNullByDefault(): void
    {
        $registry = new GridviewConfigRegistry([]);

        self::assertNull($registry->resolveDataProvider(null));
        self::assertNull($registry->resolveDataProvider('customer'));
    }

    public function testResolveDataProviderViaDefaults(): void
    {
        $registry = new GridviewConfigRegistry([
            'defaults' => ['dataProvider' => 'app.rules_provider'],
        ]);

        self::assertSame('app.rules_provider', $registry->resolveDataProvider('customer'));
    }

    public function testResolveDataProviderPerGridOverridesDefault(): void
    {
        $registry = new GridviewConfigRegistry([
            'defaults' => ['dataProvider' => 'app.default_provider'],
            'gridviews' => [
                'customer' => ['dataProvider' => 'app.customer_provider'],
            ],
        ]);

        self::assertSame('app.customer_provider', $registry->resolveDataProvider('customer'));
        // Other grids keep the bundle-wide default.
        self::assertSame('app.default_provider', $registry->resolveDataProvider('order'));
    }

    public function testRenderGridviewSwapsInTheConfiguredDataProvider(): void
    {
        $defaultProvider = $this->createMock(DataProviderInterface::class);
        $customProvider = $this->createMock(DataProviderInterface::class);

        $service = new GridviewService($this->createMock(Environment::class));
        $service->setSearchForm(new SearchForm(Forms::createFormFactory(), new RequestStack()));
        $service->setDataProvider($defaultProvider);

        $builder = new GridviewBuilder(
            $service,
            new GridviewConfigRegistry(['gridviews' => ['customer' => ['dataProvider' => 'app.custom_provider']]]),
            new ColumnFactory(),
            new ThemeRegistry([]),
            new ServiceLocator(['app.custom_provider' => static fn () => $customProvider]),
        );

        $gridview = $builder
            ->setId('customer')
            ->setDataProvider(['model' => 'App\\Entity\\Customer'])
            ->renderGridview();

        self::assertSame($customProvider, $gridview->getDataProvider());
    }

    public function testRenderGridviewKeepsDefaultProviderWhenNotConfigured(): void
    {
        $defaultProvider = $this->createMock(DataProviderInterface::class);

        $service = new GridviewService($this->createMock(Environment::class));
        $service->setSearchForm(new SearchForm(Forms::createFormFactory(), new RequestStack()));
        $service->setDataProvider($defaultProvider);

        $builder = new GridviewBuilder(
            $service,
            new GridviewConfigRegistry([]),
            new ColumnFactory(),
            new ThemeRegistry([]),
            new ServiceLocator([]),
        );

        $gridview = $builder
            ->setId('customer')
            ->setDataProvider(['model' => 'App\\Entity\\Customer'])
            ->renderGridview();

        self::assertSame($defaultProvider, $gridview->getDataProvider());
    }

    public function testRenderGridviewThrowsOnUnknownDataProviderServiceId(): void
    {
        $service = new GridviewService($this->createMock(Environment::class));
        $service->setSearchForm(new SearchForm(Forms::createFormFactory(), new RequestStack()));
        $service->setDataProvider($this->createMock(DataProviderInterface::class));

        $builder = new GridviewBuilder(
            $service,
            new GridviewConfigRegistry(['gridviews' => ['customer' => ['dataProvider' => 'app.missing_provider']]]),
            new ColumnFactory(),
            new ThemeRegistry([]),
            new ServiceLocator([]),
        );

        $builder->setId('customer')->setDataProvider(['model' => 'App\\Entity\\Customer']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/app\.missing_provider/');

        $builder->renderGridview();
    }
}
