<?php

namespace Fedale\GridviewBundle\Tests\Grid;

use Fedale\GridviewBundle\Grid\TemplateCatalog;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

class TemplateCatalogTest extends TestCase
{
    public function testBuiltinListsFollowTheShippedTemplates(): void
    {
        $catalog = new TemplateCatalog();

        $this->assertSame(['card', 'list', 'table'], $catalog->builtinRenderers());
        $this->assertSame(['empty', 'filter', 'tbody', 'tfoot', 'thead'], $catalog->tableTokens());

        $blocks = $catalog->builtinBlocks();
        foreach (['globalSearch', 'pagination', 'export', 'spacer', 'viewSwitcher'] as $token) {
            $this->assertContains($token, $blocks);
        }
        // Partials are includes, not tokens.
        $this->assertNotContains('_region', $blocks);
        $this->assertNotContains('_stream', $blocks);
    }

    public function testWithoutTwigExistsChecksUseTheBuiltinLists(): void
    {
        $catalog = new TemplateCatalog();

        $this->assertTrue($catalog->blockExists('globalSearch'));
        $this->assertFalse($catalog->blockExists('kanbanBoard'));
        $this->assertTrue($catalog->rendererExists('card'));
        $this->assertFalse($catalog->rendererExists('kanban'));
    }

    public function testWithTwigAHostTemplateCounts(): void
    {
        // A host override (templates/bundles/FedaleGridviewBundle/…) lands in
        // the same namespace; an ArrayLoader keyed by the full name stands in.
        $twig = new Environment(new ArrayLoader([
            '@FedaleGridview/gridview/sections/kanbanBoard.html.twig'  => '',
            '@FedaleGridview/gridview/sections/dataview/kanban.html.twig' => '',
        ]));
        $catalog = new TemplateCatalog($twig);

        $this->assertTrue($catalog->blockExists('kanbanBoard'));
        $this->assertTrue($catalog->rendererExists('kanban'));
        $this->assertFalse($catalog->rendererExists('timeline'));
    }
}
