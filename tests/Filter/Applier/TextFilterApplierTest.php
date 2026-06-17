<?php

namespace Fedale\GridviewBundle\Tests\Filter\Applier;

use Fedale\GridviewBundle\Filter\Applier\TextFilterApplier;
use Fedale\GridviewBundle\Tests\Support\CreatesQueryBuilderTrait;
use PHPUnit\Framework\TestCase;

class TextFilterApplierTest extends TestCase
{
    use CreatesQueryBuilderTrait;

    private TextFilterApplier $applier;

    protected function setUp(): void
    {
        $this->applier = new TextFilterApplier();
    }

    public function testDefaultOperatorIsCaseInsensitiveLike(): void
    {
        $qb = $this->createTestQueryBuilder();
        $this->applier->apply($qb, 'c.name', 'Foo');

        $param = $qb->getParameters()->first();
        $this->assertSame('%foo%', $param->getValue());
        $this->assertSame(sprintf('LOWER(c.name) LIKE :%s', $param->getName()), $this->whereDql($qb));
    }

    public function testTermContainingOperatorSubstringIsNotMangled(): void
    {
        $qb = $this->createTestQueryBuilder();
        $this->applier->apply($qb, 'c.name', 'sequence');

        $this->assertSame('%sequence%', $qb->getParameters()->first()->getValue());
    }

    public function testOperatorPrefixSplitsOnFirstWhitespaceOnly(): void
    {
        $qb = $this->createTestQueryBuilder();
        $this->applier->apply($qb, 'c.name', 'eq sequence');

        $param = $qb->getParameters()->first();
        $this->assertSame('sequence', $param->getValue());
        $this->assertSame(sprintf('c.name = :%s', $param->getName()), $this->whereDql($qb));
    }

    public function testIeqPrefixLowercasesBothSides(): void
    {
        $qb = $this->createTestQueryBuilder();
        $this->applier->apply($qb, 'c.name', '= John');

        $param = $qb->getParameters()->first();
        $this->assertSame('john', $param->getValue());
        $this->assertSame(sprintf('LOWER(c.name) = :%s', $param->getName()), $this->whereDql($qb));
    }

    public function testComparisonPrefix(): void
    {
        $qb = $this->createTestQueryBuilder();
        $this->applier->apply($qb, 'c.amount', '>= 10');

        $param = $qb->getParameters()->first();
        $this->assertSame('10', $param->getValue());
        $this->assertSame(sprintf('c.amount >= :%s', $param->getName()), $this->whereDql($qb));
    }

    public function testInPrefixSplitsCommaSeparatedValues(): void
    {
        $qb = $this->createTestQueryBuilder();
        $this->applier->apply($qb, 'c.code', 'in a, b,c');

        $param = $qb->getParameters()->first();
        $this->assertSame(['a', 'b', 'c'], $param->getValue());
        $this->assertSame(sprintf('c.code IN(:%s)', $param->getName()), $this->whereDql($qb));
    }

    public function testBetweenPrefixBindsBothBounds(): void
    {
        $qb = $this->createTestQueryBuilder();
        $this->applier->apply($qb, 'c.amount', 'btw 10 AND 20');

        $params = $qb->getParameters();
        $this->assertCount(2, $params);
        $this->assertSame('10', $params[0]->getValue());
        $this->assertSame('20', $params[1]->getValue());
        $this->assertSame(
            sprintf('c.amount BETWEEN :%s AND :%s', $params[0]->getName(), $params[1]->getName()),
            $this->whereDql($qb)
        );
    }

    public function testMalformedBetweenFallsBackToIlike(): void
    {
        $qb = $this->createTestQueryBuilder();
        $this->applier->apply($qb, 'c.amount', 'btw 10');

        $param = $qb->getParameters()->first();
        $this->assertSame('%10%', $param->getValue());
    }

    public function testUnknownTokenIsTreatedAsPlainTerm(): void
    {
        $qb = $this->createTestQueryBuilder();
        $this->applier->apply($qb, 'c.name', 'mario rossi');

        $this->assertSame('%mario rossi%', $qb->getParameters()->first()->getValue());
    }

    public function testDefaultOperatorOption(): void
    {
        $qb = $this->createTestQueryBuilder();
        $this->applier->apply($qb, 'c.code', 'ABC', ['default_operator' => 'eq']);

        $param = $qb->getParameters()->first();
        $this->assertSame('ABC', $param->getValue());
        $this->assertSame(sprintf('c.code = :%s', $param->getName()), $this->whereDql($qb));
    }

    public function testTrimCanBeDisabled(): void
    {
        $qb = $this->createTestQueryBuilder();
        $this->applier->apply($qb, 'c.name', '  Foo  ', ['trim' => false]);

        $this->assertSame('%  foo  %', $qb->getParameters()->first()->getValue());
    }

    /**
     * Client-typed wildcard ('%' by default): position drives the match.
     *
     * @dataProvider wildcardPositions
     */
    public function testClientWildcardPosition(string $input, string $expectedPattern): void
    {
        $qb = $this->createTestQueryBuilder();
        $this->applier->apply($qb, 'c.name', $input);

        $param = $qb->getParameters()->first();
        $this->assertSame($expectedPattern, $param->getValue());
        $this->assertSame(sprintf('LOWER(c.name) LIKE :%s', $param->getName()), $this->whereDql($qb));
    }

    public function wildcardPositions(): array
    {
        return [
            'contains'    => ['%foo%', '%foo%'],
            'starts with' => ['foo%', 'foo%'],
            'ends with'   => ['%foo', '%foo'],
        ];
    }

    public function testClientWildcardCharIsConfigurable(): void
    {
        $qb = $this->createTestQueryBuilder();
        // Custom wildcard '*' typed by the user → translated to SQL '%'.
        $this->applier->apply($qb, 'c.name', 'foo*', ['wildcard' => '*']);

        $this->assertSame('foo%', $qb->getParameters()->first()->getValue());
    }

    public function testWildcardOnlyTermAddsNoConstraint(): void
    {
        $qb = $this->createTestQueryBuilder();
        $this->applier->apply($qb, 'c.name', '%%');

        $this->assertNull($qb->getDQLPart('where'));
        $this->assertCount(0, $qb->getParameters());
    }

    public function testExplicitOperatorBeatsClientWildcard(): void
    {
        $qb = $this->createTestQueryBuilder();
        // "eq" prefix is explicit → wildcard chars are kept verbatim, no LIKE.
        $this->applier->apply($qb, 'c.name', 'eq %foo%');

        $param = $qb->getParameters()->first();
        $this->assertSame('%foo%', $param->getValue());
        $this->assertSame(sprintf('c.name = :%s', $param->getName()), $this->whereDql($qb));
    }

    /**
     * @dataProvider blankValues
     */
    public function testBlankValuesAreSkipped(mixed $value): void
    {
        $qb = $this->createTestQueryBuilder();
        $this->applier->apply($qb, 'c.name', $value);

        $this->assertNull($qb->getDQLPart('where'));
        $this->assertCount(0, $qb->getParameters());
    }

    public function blankValues(): array
    {
        return [
            'null' => [null],
            'empty string' => [''],
            'whitespace only' => ['   '],
            'array' => [['from' => '']],
        ];
    }
}
