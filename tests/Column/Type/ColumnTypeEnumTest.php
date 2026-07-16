<?php

namespace Fedale\GridviewBundle\Tests\Column\Type;

use Fedale\GridviewBundle\Column\ColumnFactory;
use Fedale\GridviewBundle\Column\DataColumn;
use Fedale\GridviewBundle\Column\Type\ColumnType;
use Fedale\GridviewBundle\Column\Type\ColumnTypeRegistry;
use Fedale\GridviewBundle\Contract\DataProviderInterface;
use Fedale\GridviewBundle\Filter\FilterType;
use Fedale\GridviewBundle\Form\SearchForm;
use Fedale\GridviewBundle\Grid\GridviewBuilder;
use Fedale\GridviewBundle\Grid\GridviewConfigRegistry;
use Fedale\GridviewBundle\Service\GridviewService;
use Fedale\GridviewBundle\Theme\ThemeRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

class ColumnTypeEnumTest extends TestCase
{
    /** Every canonical data type in the registry must have a matching enum case. */
    public function testEnumCoversEveryRegistryDataType(): void
    {
        $enumValues = array_map(fn (ColumnType $c): string => $c->value, ColumnType::cases());

        foreach ($this->registryDataTypeNames() as $name) {
            $this->assertContains(
                $name,
                $enumValues,
                sprintf('ColumnType is missing a case for the registered data type "%s".', $name)
            );
        }
    }

    /** Every enum case must be either a known data type or one of the structural columns. */
    public function testEveryEnumCaseIsKnown(): void
    {
        $registry   = ColumnTypeRegistry::withBuiltins();
        $structural = ['action', 'checkbox', 'serial'];

        foreach (ColumnType::cases() as $case) {
            $this->assertTrue(
                $registry->has($case->value) || \in_array($case->value, $structural, true),
                sprintf('ColumnType::%s ("%s") maps to no data type nor structural column.', $case->name, $case->value)
            );
        }
    }

    /** A ColumnType passed as `type` resolves exactly like its string value. */
    public function testFactoryUnwrapsEnumType(): void
    {
        $fromEnum   = $this->firstColumn(['attribute' => 'x', 'type' => ColumnType::Badge]);
        $fromString = $this->firstColumn(['attribute' => 'x', 'type' => 'badge']);

        $this->assertInstanceOf(DataColumn::class, $fromEnum);
        $this->assertSame('badge', $fromEnum->getDataType());
        $this->assertSame($fromString->getDataType(), $fromEnum->getDataType());
    }

    /** A FilterType passed as `filter` (scalar or in `filter.type`) is unwrapped. */
    public function testFactoryUnwrapsFilterEnum(): void
    {
        $scalar = $this->firstColumn(['attribute' => 'x', 'type' => 'text', 'filter' => FilterType::Number]);
        $nested = $this->firstColumn(['attribute' => 'x', 'type' => 'text', 'filter' => ['type' => FilterType::Choice]]);

        $this->assertSame('number', $scalar->getFilter()['type']);
        $this->assertSame('choice', $nested->getFilter()['type']);
    }

    /**
     * End-to-end guard through the real ColumnFactory pipeline (not just the
     * isolated ControlResolver unit): `control: true` on these display types
     * must resolve to a control of the *same* name, not silently fall back to a
     * parent type's control (e.g. `percent` used to resolve to a plain `number`
     * control, and `datetime` to a plain `date` control, because their
     * inferControlType() wasn't overridden).
     *
     * @dataProvider selfNamedControlTypes
     */
    public function testDisplayTypeInheritsSameNameControlThroughFactory(string $type): void
    {
        $column = $this->firstColumn(['attribute' => 'x', 'type' => $type, 'control' => true]);

        $this->assertSame($type, $column->getControl()['type']);
    }

    public static function selfNamedControlTypes(): array
    {
        return [
            ['email'], ['url'], ['tel'], ['percent'], ['datetime'], ['time'],
            ['money'], ['currency'], ['color'], ['country'], ['language'],
            ['locale'], ['timezone'], ['hidden'],
        ];
    }

    /** @return string[] canonical (non-alias) data type names from the registry */
    private function registryDataTypeNames(): array
    {
        $property = new \ReflectionProperty(ColumnTypeRegistry::class, 'types');

        return array_keys($property->getValue(ColumnTypeRegistry::withBuiltins()));
    }

    /** @param array<string, mixed> $spec */
    private function firstColumn(array $spec): DataColumn
    {
        $service = new GridviewService($this->createMock(Environment::class));
        $service->setSearchForm(new SearchForm(Forms::createFormFactory(), new RequestStack()));
        $service->setDataProvider($this->createMock(DataProviderInterface::class));

        $gridview = (new GridviewBuilder(
            $service,
            new GridviewConfigRegistry([]),
            new ColumnFactory(),
            new ThemeRegistry([]),
            new \Symfony\Component\DependencyInjection\ServiceLocator([]),
        ))
            ->setColumns([$spec])
            ->renderGridview();

        return $gridview->getColumns()->first();
    }
}
