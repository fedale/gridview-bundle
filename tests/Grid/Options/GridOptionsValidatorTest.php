<?php

namespace Fedale\GridviewBundle\Tests\Grid\Options;

use Fedale\GridviewBundle\Exception\InvalidGridOptionsException;
use Fedale\GridviewBundle\Grid\GridviewConfigRegistry;
use Fedale\GridviewBundle\Grid\Options\GridOptionsValidator;
use Fedale\GridviewBundle\Grid\Options\OptionViolation;
use Fedale\GridviewBundle\Grid\TemplateCatalog;
use Fedale\GridviewBundle\Pagination\Strategy\InfiniteScrollStrategy;
use Fedale\GridviewBundle\Pagination\Strategy\NumericStrategy;
use Fedale\GridviewBundle\Pagination\Strategy\PaginatorStrategyRegistry;
use Fedale\GridviewBundle\Theme\ThemeRegistry;
use PHPUnit\Framework\TestCase;

class GridOptionsValidatorTest extends TestCase
{
    private function validator(): GridOptionsValidator
    {
        return new GridOptionsValidator(
            new ThemeRegistry(['brand' => ['extends' => 'bootstrap5', 'classes' => []]]),
            new PaginatorStrategyRegistry([new NumericStrategy(), new InfiniteScrollStrategy()]),
            new TemplateCatalog(),
        );
    }

    /**
     * @return array<string, string> path => message
     */
    private function violations(array $options): array
    {
        $out = [];
        foreach ($this->validator()->validate($options) as $violation) {
            $out[$violation->path] = $violation->message;
        }

        return $out;
    }

    public function testTheResolvedBundleDefaultsAreValid(): void
    {
        $options = (new GridviewConfigRegistry([]))->resolveOptions('customers');

        $this->assertSame([], $this->violations($options));
    }

    public function testATypicalCrudGridIsValid(): void
    {
        $options = [
            'display' => [
                'title'    => 'Customers',
                'theme'    => 'brand',
                'renderer' => ['default' => 'card', 'map' => ['table' => [], 'card' => ['min' => '16rem']]],
                'layout'   => [
                    'toolbar' => '{addButton} {globalSearch 40%} {spacer} {viewSwitcher} {export} {legend}',
                    'filters' => '{filterBar}',
                    'footer'  => '{resultsSummary} {pagination} {filters}',
                    'slots'   => ['legend' => '<span>Legend</span>'],
                ],
            ],
            'behavior' => [
                'globalSearch' => ['c.name', 'c.email'],
                'crudMode'     => 'page',
                'formTheme'    => ['bootstrap_5_layout.html.twig'],
                'pagination'   => ['mode' => 'infinite'],
                'restriction'  => 'You are only seeing your own customers.',
            ],
            'integration' => [
                'routeName' => 'customer_index',
                'crud'      => ['addUrl' => '/customers/new', 'bulkActions' => []],
                'export'    => ['url' => '/customers/export', 'formats' => []],
            ],
        ];

        $this->assertSame([], $this->violations($options));
    }

    public function testUnknownKeysAreReportedWithASuggestion(): void
    {
        $violations = $this->violations([
            'display'  => ['renderr' => 'card'],
            'behavior' => ['filterControls' => ['inHeadr' => false]],
            'theme'    => 'tailwind',
        ]);

        $this->assertStringContainsString('Unknown key "renderr"', $violations['display.renderr']);
        $this->assertStringContainsString('Did you mean "renderer"?', $violations['display.renderr']);
        $this->assertStringContainsString('Did you mean "inHeader"?', $violations['behavior.filterControls.inHeadr']);
        // A misplaced top-level key: the merge would drop it.
        $this->assertStringContainsString('Known keys: actionLayout, behavior, display, integration.', $violations['theme']);
    }

    public function testWrongTypesAreReported(): void
    {
        $violations = $this->violations([
            'display'  => ['showThead' => 'yes', 'title' => null],
            'behavior' => ['globalSearch' => 'c.name', 'maxQueryLength' => '4000', 'crudMode' => 'drawer'],
        ]);

        $this->assertSame('Expected bool, got string.', $violations['display.showThead']);
        $this->assertSame('Expected list of string, got string.', $violations['behavior.globalSearch']);
        $this->assertSame('Expected int, got string.', $violations['behavior.maxQueryLength']);
        $this->assertStringContainsString('Accepted values: custom, modal, page.', $violations['behavior.crudMode']);
        // Nullable keys accept null.
        $this->assertArrayNotHasKey('display.title', $violations);
    }

    public function testAUnionReportsTheVariantOfTheSameKind(): void
    {
        $violations = $this->violations(['display' => ['renderer' => ['default' => 'card', 'mapp' => []]]]);

        $this->assertStringContainsString('Did you mean "map"?', $violations['display.renderer.mapp']);
    }

    public function testRegisteredNamesAreChecked(): void
    {
        $violations = $this->violations([
            'display' => [
                'theme'    => 'bootstrap',
                'renderer' => ['default' => 'cards', 'map' => ['table' => [], 'kanban' => []]],
            ],
            'behavior' => ['pagination' => ['mode' => 'infinte']],
        ]);

        $this->assertStringContainsString('Did you mean "bootstrap5"?', $violations['display.theme']);
        $this->assertStringContainsString('Did you mean "card"?', $violations['display.renderer.default']);
        $this->assertStringContainsString('Unknown renderer "kanban"', $violations['display.renderer.map.kanban']);
        $this->assertStringContainsString('Did you mean "infinite"?', $violations['behavior.pagination.mode']);
    }

    public function testTheRendererShorthandIsChecked(): void
    {
        $violations = $this->violations(['display' => ['renderer' => 'grid']]);

        $this->assertStringContainsString('Unknown renderer "grid"', $violations['display.renderer']);
    }

    public function testUnknownLayoutTokensAreReported(): void
    {
        $violations = $this->violations(['display' => ['layout' => [
            'toolbar'  => '{addButton} {globalSerch} {spacer}',
            // Table tokens belong to the dataview region only.
            'footer'   => '{tbody}',
            'dataview' => '{thead} {tbody}',
        ]]]);

        $this->assertStringContainsString('Unknown layout token "{globalSerch}"', $violations['display.layout.toolbar']);
        $this->assertStringContainsString('Did you mean "globalSearch"?', $violations['display.layout.toolbar']);
        $this->assertStringContainsString('Unknown layout token "{tbody}"', $violations['display.layout.footer']);
        $this->assertArrayNotHasKey('display.layout.dataview', $violations);
    }

    public function testTokensDeclaredThroughTemplatesAreKnown(): void
    {
        $violations = $this->violations(['display' => ['layout' => [
            'toolbar'   => '{globalSearch} {kpis}',
            'templates' => ['kpis' => 'customer/_kpis.html.twig'],
        ]]]);

        $this->assertSame([], $violations);
    }

    public function testEveryViolationIsReportedAtOnce(): void
    {
        $validator = $this->validator();

        try {
            $validator->assertValid([
                'display'  => ['showThead' => 1, 'captoin' => 'x'],
                'behavior' => ['useTurbo' => 'no'],
            ], 'customers');
            $this->fail('An InvalidGridOptionsException was expected.');
        } catch (InvalidGridOptionsException $e) {
            $this->assertSame('customers', $e->getGridId());
            $this->assertSame(
                ['display.showThead', 'display.captoin', 'behavior.useTurbo'],
                array_map(static fn(OptionViolation $v) => $v->path, $e->getViolations()),
            );
            $this->assertStringStartsWith('Gridview "customers" has 3 invalid option(s):', $e->getMessage());
        }
    }

    public function testNameChecksAreSkippedWithoutRegistries(): void
    {
        $violations = (new GridOptionsValidator())->validate([
            'display'  => ['theme' => 'anything', 'renderer' => 'kanban', 'layout' => ['toolbar' => '{nope}']],
            'behavior' => ['pagination' => ['mode' => 'nope']],
        ]);

        $this->assertSame([], $violations);
    }
}
