<?php

namespace Fedale\GridviewBundle\Tests\Filter;

use Fedale\GridviewBundle\Filter\FilterDefaultNormalizer;
use PHPUnit\Framework\TestCase;

class FilterDefaultNormalizerTest extends TestCase
{
    public function testText(): void
    {
        $this->assertSame('abc', FilterDefaultNormalizer::normalize('text', 'abc'));
        $this->assertSame('5', FilterDefaultNormalizer::normalize('text', 5));
    }

    public function testTextRejectsNonScalar(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FilterDefaultNormalizer::normalize('text', ['a']);
    }

    /**
     * @dataProvider booleanValues
     */
    public function testBoolean(mixed $input, string $expected): void
    {
        $this->assertSame($expected, FilterDefaultNormalizer::normalize('boolean', $input));
    }

    public function booleanValues(): array
    {
        return [
            'string 1' => ['1', '1'],
            'true' => [true, '1'],
            'int 1' => [1, '1'],
            'string 0' => ['0', '0'],
            'false' => [false, '0'],
            'int 0' => [0, '0'],
        ];
    }

    public function testBooleanRejectsArbitraryValues(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FilterDefaultNormalizer::normalize('boolean', 'yes');
    }

    public function testDateStringShorthandBecomesFrom(): void
    {
        $this->assertSame(
            ['from' => '2026-01-01', 'to' => null],
            FilterDefaultNormalizer::normalize('date', '2026-01-01')
        );
    }

    public function testDateRange(): void
    {
        $this->assertSame(
            ['from' => null, 'to' => '2026-02-01'],
            FilterDefaultNormalizer::normalize('date', ['from' => '', 'to' => '2026-02-01'])
        );
    }

    public function testDateRejectsNonIsoBound(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FilterDefaultNormalizer::normalize('date', ['from' => '01/02/2026']);
    }

    public function testDateRejectsUnknownKeys(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('only accepts "from"/"to"');
        FilterDefaultNormalizer::normalize('date', ['form' => '2026-01-01']);
    }

    public function testDateRejectsAllNullRange(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at least "from" or "to"');
        FilterDefaultNormalizer::normalize('date', ['from' => null, 'to' => '']);
    }

    public function testNumberRange(): void
    {
        $this->assertSame(
            ['from' => '10', 'to' => '20.5'],
            FilterDefaultNormalizer::normalize('number', ['from' => 10, 'to' => '20.5'])
        );
    }

    public function testNumberScalarShorthand(): void
    {
        $this->assertSame(
            ['from' => '100', 'to' => null],
            FilterDefaultNormalizer::normalize('number', 100)
        );
    }

    public function testNumberRejectsNonNumericBound(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FilterDefaultNormalizer::normalize('number', ['from' => 'abc']);
    }

    public function testChoiceScalar(): void
    {
        $this->assertSame('5', FilterDefaultNormalizer::normalize('choice', 5));
    }

    public function testChoiceScalarWithMultipleIsWrappedInArray(): void
    {
        $this->assertSame(['5'], FilterDefaultNormalizer::normalize('relation', 5, ['multiple' => true]));
    }

    public function testChoiceArrayRequiresMultipleOption(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("requires the 'multiple' => true option");
        FilterDefaultNormalizer::normalize('relation', ['1', '2']);
    }

    public function testChoiceArrayWithMultiple(): void
    {
        $this->assertSame(
            ['1', '2'],
            FilterDefaultNormalizer::normalize('relation', [1, 2], ['multiple' => true])
        );
    }

    public function testChoiceArrayOfEmptiesRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FilterDefaultNormalizer::normalize('choice', ['', ''], ['multiple' => true]);
    }

    public function testUnsupportedTypeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not support a default value');
        FilterDefaultNormalizer::normalize('action', 'x');
    }
}
