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
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

class GridviewThemeTest extends TestCase
{
    private function builder(array $config, array $customThemes = []): GridviewBuilder
    {
        $service = new GridviewService($this->createMock(Environment::class));
        $service->setSearchForm(new SearchForm(Forms::createFormFactory(), new RequestStack()));
        $service->setDataProvider($this->createMock(DataProviderInterface::class));

        return new GridviewBuilder(
            $service,
            new GridviewConfigRegistry($config),
            new ColumnFactory(),
            new ThemeRegistry($customThemes),
        );
    }

    public function testDefaultThemeEmitsNoFrameworkAttributeAndGvClasses(): void
    {
        $gridview = $this->builder([])->renderGridview();

        $this->assertSame('default', $gridview->theme);
        $this->assertArrayNotHasKey('data-gv-framework', $gridview->containerAttr);
        $this->assertSame('gv-btn gv-btn-primary', $gridview->cls('btn.primary'));
    }

    public function testGlobalThemeIsResolvedAndExposedOnContainer(): void
    {
        $gridview = $this->builder(['theme' => 'bootstrap5'])->renderGridview();

        $this->assertSame('bootstrap5', $gridview->theme);
        $this->assertSame('bootstrap5', $gridview->containerAttr['data-gv-framework']);
        $this->assertSame('btn btn-sm btn-primary', $gridview->cls('btn.primary'));
    }

    public function testPerGridviewThemeOverridesGlobal(): void
    {
        $config = [
            'theme'     => 'bootstrap5',
            'gridviews' => ['users' => ['options' => ['theme' => 'tailwind']]],
        ];

        $gridview = $this->builder($config)->setId('users')->renderGridview();

        $this->assertSame('tailwind', $gridview->theme);
        $this->assertSame('tailwind', $gridview->containerAttr['data-gv-framework']);
        $this->assertStringContainsString('bg-indigo-600', $gridview->cls('btn.primary'));
    }

    public function testCustomThemeFromConfigIsApplied(): void
    {
        $config       = ['theme' => 'mycss'];
        $customThemes = ['mycss' => ['classes' => ['btn.primary' => 'c-button c-button--primary']]];

        $gridview = $this->builder($config, $customThemes)->renderGridview();

        $this->assertSame('c-button c-button--primary', $gridview->cls('btn.primary'));
        // Omitted key falls back to the default gv-* class.
        $this->assertSame('gv-btn-icon', $gridview->cls('btn.icon'));
    }

    public function testClsAppendsExtraHookClasses(): void
    {
        $gridview = $this->builder([])->renderGridview();

        $this->assertSame('gv-btn gv-locale-switcher', $gridview->cls('btn', 'gv-locale-switcher'));
    }
}
