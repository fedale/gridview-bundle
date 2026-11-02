<?php

namespace Fedale\GridviewBundle\Tests\Capabilities;

use Fedale\GridviewBundle\Capabilities\GridCapabilities;
use Fedale\GridviewBundle\Column\Type\ColumnTypeRegistry;
use Fedale\GridviewBundle\Command\CapabilitiesCommand;
use Fedale\GridviewBundle\Export\CsvExporter;
use Fedale\GridviewBundle\Export\GridExporterRegistry;
use Fedale\GridviewBundle\Export\JsonExporter;
use Fedale\GridviewBundle\Form\Control\ControlTypeRegistry;
use Fedale\GridviewBundle\Grid\TemplateCatalog;
use Fedale\GridviewBundle\Pagination\Strategy\InfiniteScrollStrategy;
use Fedale\GridviewBundle\Pagination\Strategy\NumericStrategy;
use Fedale\GridviewBundle\Pagination\Strategy\PaginatorStrategyRegistry;
use Fedale\GridviewBundle\Theme\ThemeRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class GridCapabilitiesTest extends TestCase
{
    private function capabilities(): GridCapabilities
    {
        return new GridCapabilities(
            ColumnTypeRegistry::create(),
            new ControlTypeRegistry(),
            new GridExporterRegistry([new CsvExporter(), new JsonExporter()]),
            new ThemeRegistry(['brand' => ['extends' => 'tailwind', 'classes' => []]]),
            new PaginatorStrategyRegistry([new NumericStrategy(), new InfiniteScrollStrategy()]),
            new TemplateCatalog(),
        );
    }

    public function testDescribesTheRegisteredNames(): void
    {
        $data = $this->capabilities()->describe();

        $this->assertContains('money', $data['columnTypes']);
        $this->assertSame(['text', 'boolean', 'date', 'number', 'choice', 'relation'], $data['filterTypes']);
        $this->assertContains('between', $data['filterOperators']);
        $this->assertContains('relation', $data['controlTypes']);
        $this->assertSame(['csv', 'json'], array_column($data['exportFormats'], 'key'));
        $this->assertSame(['bootstrap5', 'brand', 'default', 'tailwind'], $data['themes']);
        $this->assertSame(['infinite', 'numeric'], $data['paginationModes']);
        $this->assertSame(['card', 'list', 'table'], $data['renderers']);
        $this->assertSame('{heading} {toolbar}', $data['layout']['regions']['header']);
        $this->assertArrayNotHasKey('templates', $data['layout']['regions']);
        $this->assertContains('globalSearch', $data['layout']['blocks']);
        $this->assertSame('https://json-schema.org/draft/2020-12/schema', $data['optionsSchema']['$schema']);
    }

    public function testCommandPrintsOneSectionAsJson(): void
    {
        $tester = new CommandTester(new CapabilitiesCommand($this->capabilities()));

        $this->assertSame(Command::SUCCESS, $tester->execute(['section' => 'renderers']));
        $this->assertSame(['card', 'list', 'table'], json_decode($tester->getDisplay(), true));
    }

    public function testCommandRejectsAnUnknownSection(): void
    {
        $tester = new CommandTester(new CapabilitiesCommand($this->capabilities()));

        $this->assertSame(Command::INVALID, $tester->execute(['section' => 'widgets']));
        $this->assertStringContainsString('Available sections: columnTypes', $tester->getDisplay());
    }

    public function testTheWholeDescriptionIsValidJson(): void
    {
        $tester = new CommandTester(new CapabilitiesCommand($this->capabilities()));
        $tester->execute([]);

        $this->assertIsArray(json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR));
    }
}
