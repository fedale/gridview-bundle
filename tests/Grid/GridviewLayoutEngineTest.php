<?php

namespace Fedale\GridviewBundle\Tests\Grid;

use Fedale\GridviewBundle\Column\ColumnFactory;
use Fedale\GridviewBundle\Contract\DataProviderInterface;
use Fedale\GridviewBundle\Form\SearchForm;
use Fedale\GridviewBundle\Grid\Gridview;
use Fedale\GridviewBundle\Grid\GridviewBuilder;
use Fedale\GridviewBundle\Grid\GridviewConfigRegistry;
use Fedale\GridviewBundle\Service\GridviewService;
use Fedale\GridviewBundle\Theme\ThemeRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

/**
 * Covers the recursive layout engine's PHP surface (the Twig dispatch is
 * exercised end-to-end by the demo): region detection, the single-source
 * defaults, the renamed dataview strategy default and the generalized
 * per-region attributes.
 */
class GridviewLayoutEngineTest extends TestCase
{
    private function gridview(array $config = []): Gridview
    {
        $service = new GridviewService($this->createMock(Environment::class));
        $service->setSearchForm(new SearchForm(Forms::createFormFactory(), new RequestStack()));
        $service->setDataProvider($this->createMock(DataProviderInterface::class));

        $builder = new GridviewBuilder(
            $service,
            new GridviewConfigRegistry($config),
            new ColumnFactory(),
            new ThemeRegistry([]),
        );

        return $builder->renderGridview();
    }

    public function testDefaultLayoutUsesTheNewVocabulary(): void
    {
        $layout = $this->gridview()->getOptions()['layout'];

        $this->assertSame('{restrictionNotice} {header} {dataview} {footer}', $layout['shell']);
        $this->assertSame('{heading} {toolbar}', $layout['header']);
        $this->assertSame('{globalSearch} {filterSubmit}', $layout['toolbar']);
        $this->assertArrayNotHasKey('title', $layout);
        $this->assertNull($layout['dataview']);
        $this->assertSame('{pagination} {pageSize}', $layout['footer']);
        $this->assertArrayNotHasKey('gridview', $layout);
        $this->assertArrayNotHasKey('table', $layout);
    }

    public function testDefaultsHaveSingleSourceAcrossInClassAndRegistry(): void
    {
        // The in-class Gridview defaults must equal the registry's canonical
        // constant, so the two never drift apart again.
        $this->assertSame(
            GridviewConfigRegistry::LAYOUT_DEFAULTS,
            $this->gridview()->getOptions()['layout'],
        );
    }

    public function testIsRegionDistinguishesRegionsBlocksAndReservedMaps(): void
    {
        $gridview = $this->gridview();

        foreach (['shell', 'header', 'toolbar', 'dataview', 'footer', 'tfoot'] as $region) {
            $this->assertTrue($gridview->isRegion($region), "$region should be a region");
        }

        // Reserved sub-maps are configuration, not regions.
        foreach (['templates', 'slots', 'attrs'] as $reserved) {
            $this->assertFalse($gridview->isRegion($reserved), "$reserved must not be a region");
        }

        // Leaf widgets and unknown tokens are not regions.
        $this->assertFalse($gridview->isRegion('globalSearch'));
        $this->assertFalse($gridview->isRegion('nope'));
    }

    public function testDataviewNullComputesTableStrategyTokens(): void
    {
        $gridview = $this->gridview();

        $this->assertSame(['thead', 'filter', 'tbody', 'tfoot'], $gridview->parseLayout('dataview'));
    }

    public function testDataviewStrategyHonoursShowTheadShowTfoot(): void
    {
        $config = ['gridviews' => ['g' => ['options' => [
            'showThead' => false,
            'showTfoot' => false,
        ]]]];

        $gridview = $this->gridview($config);
        $gridview->setId('g');
        // Re-resolve options for the id (builder resolved before setId in helper).
        $gridview->setOptions((new GridviewConfigRegistry($config))->resolveOptions('g'));

        $this->assertSame(['filter', 'tbody'], $gridview->parseLayout('dataview'));
    }

    public function testRegionAttrMapsLegacyBagsToCanonicalRegions(): void
    {
        $gridview = $this->gridview();
        $gridview->setAttributes([
            'container' => ['id' => 'wrap'],
            'header'    => ['class' => 'hdr'],
            'filter'    => ['class' => 'flt'],
            'row'       => ['class' => 'rw'],
            'class'     => 'table',
        ]);

        $this->assertSame(['id' => 'wrap'], $gridview->regionAttr('shell'));
        $this->assertSame(['class' => 'hdr'], $gridview->regionAttr('header'));
        $this->assertSame(['class' => 'flt'], $gridview->regionAttr('filter'));
        $this->assertSame(['class' => 'rw'], $gridview->regionAttr('row'));
        // The leftover bag (incl. class) lands on the table-level dataview attrs.
        $this->assertSame('table', $gridview->regionAttr('dataview')['class']);
        // thead has no legacy bag: empty unless layout.attrs.thead is set.
        $this->assertSame([], $gridview->regionAttr('thead'));
    }

    public function testLayoutAttrsOverrideAndExtendRegionAttr(): void
    {
        $config = ['gridviews' => ['g' => ['options' => ['layout' => ['attrs' => [
            'thead' => ['class' => 'sticky'],
            'shell' => ['data-x' => '1'],
        ]]]]]];

        $gridview = $this->gridview($config);
        $gridview->setOptions((new GridviewConfigRegistry($config))->resolveOptions('g'));
        $gridview->setAttributes(['container' => ['id' => 'wrap']]);

        // New region attrs from layout.attrs.
        $this->assertSame(['class' => 'sticky'], $gridview->regionAttr('thead'));
        // Merged with (and overriding) the legacy bag.
        $this->assertSame(['id' => 'wrap', 'data-x' => '1'], $gridview->regionAttr('shell'));
    }

    public function testRendererDefaultsToTable(): void
    {
        $this->assertSame('table', $this->gridview()->getRenderer());
    }

    public function testRendererIsConfigurablePerGridview(): void
    {
        $config = ['gridviews' => ['g' => ['options' => ['renderer' => 'card']]]];

        $gridview = $this->gridview($config);
        $gridview->setOptions((new GridviewConfigRegistry($config))->resolveOptions('g'));

        $this->assertSame('card', $gridview->getRenderer());
    }

    public function testLayoutTokensExtractInlineWidths(): void
    {
        $config = ['gridviews' => ['g' => ['options' => ['layout' => [
            'toolbar' => '{globalSearch 40%} {spacer} {export 120px}',
        ]]]]];

        $gridview = $this->gridview($config);
        $gridview->setOptions((new GridviewConfigRegistry($config))->resolveOptions('g'));

        $this->assertSame([
            ['token' => 'globalSearch', 'width' => '40%'],
            ['token' => 'spacer', 'width' => null],
            ['token' => 'export', 'width' => '120px'],
        ], $gridview->layoutTokens('toolbar'));
    }
}
