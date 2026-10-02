<?php

namespace Fedale\GridviewBundle\Tests\Grid;

use Fedale\GridviewBundle\Column\ColumnFactory;
use Fedale\GridviewBundle\Column\Config\TextColumn;
use Fedale\GridviewBundle\Column\Config\VirtualColumn;
use Fedale\GridviewBundle\Contract\DataProviderInterface;
use Fedale\GridviewBundle\Contract\SearchModelInterface;
use Fedale\GridviewBundle\Form\SearchForm;
use Fedale\GridviewBundle\Grid\Gridview;
use Fedale\GridviewBundle\Service\GridviewService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

/**
 * One column to read, several to write: covers what a virtual column expands
 * into, where each half shows up, and how the joined value is rendered.
 */
class GridviewVirtualColumnTest extends TestCase
{
    private function gridview(array $columns): Gridview
    {
        $service = new GridviewService($this->createMock(Environment::class));
        $service->setSearchForm(new SearchForm(Forms::createFormFactory(), new RequestStack()));
        $service->setDataProvider($this->createMock(DataProviderInterface::class));

        $gridview = new Gridview($service, new ColumnFactory());
        $gridview->setSearchModel($this->createMock(SearchModelInterface::class));
        $gridview->setColumns($columns);

        return $gridview;
    }

    /** @return string[] */
    private function attributes(iterable $columns): array
    {
        $names = [];
        foreach ($columns as $column) {
            $names[] = (string) $column->getAttribute();
        }

        return $names;
    }

    private function row(array $data): object
    {
        return new class($data) {
            public function __construct(public array $data)
            {
            }
        };
    }

    public function testTheSourcesBecomeColumnsOfTheirOwn(): void
    {
        $gridview = $this->gridview([
            VirtualColumn::new('fullName')->label('Name')->from(['firstName', 'lastName']),
        ]);

        $this->assertSame(['fullName', 'firstName', 'lastName'], $this->attributes($gridview->getColumns()));
    }

    public function testTheGridShowsTheVirtualColumnAndTheFormTheSources(): void
    {
        $gridview = $this->gridview([
            VirtualColumn::new('fullName')->label('Name')->from(['firstName', 'lastName']),
        ]);

        $byName = [];
        foreach ($gridview->getColumns() as $column) {
            $byName[(string) $column->getAttribute()] = $column;
        }

        $this->assertSame(['fullName'], $this->attributes($gridview->getIndexColumns()));
        $this->assertTrue($byName['fullName']->isActiveIn('show'));
        $this->assertFalse($byName['fullName']->isActiveIn('create'));
        $this->assertFalse($byName['fullName']->isActiveIn('update'));

        foreach (['firstName', 'lastName'] as $source) {
            $this->assertFalse($byName[$source]->isActiveIn('index'), $source);
            $this->assertFalse($byName[$source]->isActiveIn('show'), $source);
            $this->assertTrue($byName[$source]->isActiveIn('create'), $source);
            $this->assertTrue($byName[$source]->isActiveIn('update'), $source);
            $this->assertNotNull($byName[$source]->getControl(), $source . ' has no control');
        }
    }

    public function testTheJoinedValueIsRendered(): void
    {
        $gridview = $this->gridview([
            VirtualColumn::new('fullName')->from(['firstName', 'lastName']),
        ]);

        $column = $gridview->getIndexColumns()->first();

        $this->assertSame('Ada Lovelace', $column->render($this->row(['firstName' => 'Ada', 'lastName' => 'Lovelace']), 0));
    }

    public function testEmptyPartsLeaveNoSeparatorBehind(): void
    {
        $gridview = $this->gridview([
            VirtualColumn::new('fullName')->from(['firstName', 'middleName', 'lastName']),
        ]);

        $column = $gridview->getIndexColumns()->first();

        $this->assertSame('Ada Lovelace', $column->render(
            $this->row(['firstName' => 'Ada', 'middleName' => null, 'lastName' => 'Lovelace']),
            0
        ));
    }

    public function testASeparatorAndDotNotationAreHonoured(): void
    {
        $gridview = $this->gridview([
            VirtualColumn::new('place')->from(['city', 'country.name'], separator: ', '),
        ]);

        $column = $gridview->getIndexColumns()->first();

        $this->assertSame('Turin, Italy', $column->render(
            $this->row(['city' => 'Turin', 'country' => ['name' => 'Italy']]),
            0
        ));
    }

    public function testASourceBuilderKeepsItsOwnConfiguration(): void
    {
        $gridview = $this->gridview([
            VirtualColumn::new('fullName')->from([
                TextColumn::new('firstName')->label('First name')->required(),
                TextColumn::new('lastName')->label('Last name'),
            ]),
        ]);

        $byName = [];
        foreach ($gridview->getColumns() as $column) {
            $byName[(string) $column->getAttribute()] = $column;
        }

        $this->assertSame('First name', $byName['firstName']->getLabel());
        $this->assertTrue($byName['firstName']->getControl()['required']);
        $this->assertFalse($byName['lastName']->getControl()['required']);
    }

    public function testASourceMayDeclareItselfBackOnScreen(): void
    {
        $gridview = $this->gridview([
            VirtualColumn::new('fullName')->from([
                'firstName',
                ['attribute' => 'lastName', 'active' => ['inIndex' => true]],
            ]),
        ]);

        $this->assertSame(['fullName', 'lastName'], $this->attributes($gridview->getIndexColumns()));
    }

    public function testAnExplicitValueGetterWins(): void
    {
        $gridview = $this->gridview([
            VirtualColumn::new('fullName')
                ->valueGetter(static fn(array $data): string => strtoupper($data['lastName']))
                ->from(['firstName', 'lastName']),
        ]);

        $column = $gridview->getIndexColumns()->first();

        $this->assertSame('LOVELACE', $column->render($this->row(['firstName' => 'Ada', 'lastName' => 'Lovelace']), 0));
    }

    public function testSourcesAreRejectedWhenUnusable(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('needs at least one source field');

        VirtualColumn::new('fullName')->from([]);
    }

    public function testASourceWithoutAnAttributeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('needs an `attribute`');

        VirtualColumn::new('fullName')->from([['label' => 'Nameless']]);
    }
}
