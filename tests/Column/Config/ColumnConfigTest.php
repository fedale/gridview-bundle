<?php

namespace Fedale\GridviewBundle\Tests\Column\Config;

use Fedale\GridviewBundle\Column\ColumnFactory;
use Fedale\GridviewBundle\Column\Config\ActionColumn;
use Fedale\GridviewBundle\Column\Config\BooleanColumn;
use Fedale\GridviewBundle\Column\Config\CheckboxColumn;
use Fedale\GridviewBundle\Column\Config\MoneyColumn;
use Fedale\GridviewBundle\Column\Config\NumberColumn;
use Fedale\GridviewBundle\Column\Config\RelationColumn;
use Fedale\GridviewBundle\Column\Config\SelectColumn;
use Fedale\GridviewBundle\Column\Config\TextColumn;
use Fedale\GridviewBundle\Grid\Gridview;
use PHPUnit\Framework\TestCase;

class ColumnConfigTest extends TestCase
{
    private function row(array $data): object
    {
        return new class($data) {
            public function __construct(public array $data)
            {
            }
        };
    }

    public function testNewSeedsTypeAndAttribute(): void
    {
        $this->assertSame(['type' => 'text', 'attribute' => 'title'], TextColumn::new('title')->toArray());
    }

    public function testStructuralColumnsOmitAttribute(): void
    {
        $this->assertSame(['type' => 'checkbox'], CheckboxColumn::new()->toArray());
        $this->assertSame(['type' => 'action', 'label' => false], ActionColumn::new()->label(false)->toArray());
    }

    public function testMoneySugarWritesFormat(): void
    {
        $spec = MoneyColumn::new('price')->currency('EUR')->decimals(2)->sortable()->toArray();

        $this->assertSame([
            'type' => 'money',
            'attribute' => 'price',
            'format' => ['currency' => 'EUR', 'decimals' => 2],
            'sortable' => true,
        ], $spec);
    }

    public function testFilterAndRequiredSugar(): void
    {
        $spec = TextColumn::new('title')->sortable()->filterText(trim: false)->required()->toArray();

        $this->assertSame([
            'type' => 'text',
            'attribute' => 'title',
            'sortable' => true,
            'filter' => ['type' => 'text', 'options' => ['trim' => false]],
            'control' => ['required' => true],
        ], $spec);
    }

    public function testEnumSugarFoldsControlAndFilter(): void
    {
        $spec = SelectColumn::new('status')->enum(ConfigTestStatus::class, required: true)->toArray();

        $this->assertSame([
            'type' => 'select',
            'attribute' => 'status',
            'control' => ['type' => 'enum', 'options' => ['class' => ConfigTestStatus::class], 'required' => true],
            'filter' => 'choice',
        ], $spec);
    }

    public function testRelationSugarDerivesControlFilterAndValue(): void
    {
        $spec = RelationColumn::new('author')->relation(\stdClass::class, choiceLabel: 'name')->toArray();

        $this->assertSame('relation', $spec['type']);
        $this->assertSame(['type' => 'relation', 'required' => false, 'options' => ['class' => \stdClass::class, 'choice_label' => 'name']], $spec['control']);
        $this->assertSame(['type' => 'relation'], $spec['filter']);

        $value = $spec['value'];
        $this->assertIsCallable($value);
        $this->assertSame('Ada', $value(['author' => ['name' => 'Ada', 'id' => 7]]));
        $this->assertSame(7, $value(['author' => ['id' => 7]]));
    }

    public function testExplicitValueIsNotOverriddenByRelationSync(): void
    {
        $custom = static fn (array $data): mixed => 'X';
        $spec = RelationColumn::new('author')->value($custom)->relation(\stdClass::class)->toArray();

        $this->assertSame($custom, $spec['value']);
    }

    public function testFactoryAcceptsBuilderEquivalentToArray(): void
    {
        $factory = new ColumnFactory();
        $gridview = $this->createStub(Gridview::class);

        $fromBuilder = $factory->create(NumberColumn::new('id')->decimals(0), $gridview, 0);
        $fromArray = $factory->create(['type' => 'number', 'attribute' => 'id', 'format' => ['decimals' => 0]], $gridview, 0);

        $this->assertSame($fromArray::class, $fromBuilder::class);
        $row = $this->row(['id' => 1234]);
        $this->assertSame((string) $fromArray->render($row, 0), (string) $fromBuilder->render($row, 0));
    }

    public function testBooleanLabelsSugar(): void
    {
        $spec = BooleanColumn::new('active')->labels('yes', 'no')->toArray();

        $this->assertSame(['type' => 'boolean', 'attribute' => 'active', 'format' => ['true' => 'yes', 'false' => 'no']], $spec);
    }

    /**
     * @return iterable<string, array{\Closure(TextColumn): TextColumn, array<string, bool>}>
     */
    public static function contextSugarProvider(): iterable
    {
        yield 'onlyOnIndex' => [static fn (TextColumn $c) => $c->onlyOnIndex(), ['inIndex' => true, 'inShow' => false, 'inCreate' => false, 'inUpdate' => false]];
        yield 'onlyOnShow' => [static fn (TextColumn $c) => $c->onlyOnShow(), ['inIndex' => false, 'inShow' => true, 'inCreate' => false, 'inUpdate' => false]];
        yield 'onlyOnForm' => [static fn (TextColumn $c) => $c->onlyOnForm(), ['inIndex' => false, 'inShow' => false, 'inCreate' => true, 'inUpdate' => true]];
        yield 'onlyOnCreate' => [static fn (TextColumn $c) => $c->onlyOnCreate(), ['inIndex' => false, 'inShow' => false, 'inCreate' => true, 'inUpdate' => false]];
        yield 'onlyOnUpdate' => [static fn (TextColumn $c) => $c->onlyOnUpdate(), ['inIndex' => false, 'inShow' => false, 'inCreate' => false, 'inUpdate' => true]];
        yield 'hideOnIndex' => [static fn (TextColumn $c) => $c->hideOnIndex(), ['inIndex' => false]];
        yield 'hideOnShow' => [static fn (TextColumn $c) => $c->hideOnShow(), ['inShow' => false]];
        yield 'hideOnForm' => [static fn (TextColumn $c) => $c->hideOnForm(), ['inCreate' => false, 'inUpdate' => false]];
        yield 'hideOnCreate' => [static fn (TextColumn $c) => $c->hideOnCreate(), ['inCreate' => false]];
        yield 'hideOnUpdate' => [static fn (TextColumn $c) => $c->hideOnUpdate(), ['inUpdate' => false]];
    }

    /**
     * @param \Closure(TextColumn): TextColumn $apply
     * @param array<string, bool>              $expected
     *
     * @dataProvider contextSugarProvider
     */
    public function testContextSugarWritesActiveSpec(\Closure $apply, array $expected): void
    {
        $spec = $apply(TextColumn::new('content'))->toArray();

        $this->assertSame(['type' => 'text', 'attribute' => 'content', 'active' => $expected], $spec);
    }

    public function testOnlyOnUpdateGatesTheRuntimeContexts(): void
    {
        $factory = new ColumnFactory();
        $gridview = $this->createStub(Gridview::class);

        $column = $factory->create(TextColumn::new('content')->onlyOnUpdate(), $gridview, 0);

        $this->assertFalse($column->isActiveIn('index'));
        $this->assertFalse($column->isActiveIn('show'));
        $this->assertFalse($column->isActiveIn('create'));
        $this->assertTrue($column->isActiveIn('update'));
    }
}

enum ConfigTestStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
