<?php

namespace Fedale\GridviewBundle\Tests\Filter\Criteria;

use Fedale\GridviewBundle\DataSource\FilterCriterion;
use Fedale\GridviewBundle\DataSource\FilterOperator;
use Fedale\GridviewBundle\Filter\Criteria\FilterCriteriaBuilderRegistry;
use Fedale\GridviewBundle\Filter\FilterType;
use PHPUnit\Framework\TestCase;

class FilterCriteriaBuildersTest extends TestCase
{
    /**
     * @dataProvider cases
     *
     * @param list<array{0: FilterOperator, 1: mixed, 2?: bool}> $expected [operator, value, caseInsensitive]
     */
    public function testBuildsTheNormalizedCriteria(string $type, mixed $rawValue, array $options, array $expected): void
    {
        $criteria = (new FilterCriteriaBuilderRegistry())->get($type)->build('name', $rawValue, $options);

        $this->assertEquals(
            array_map(fn(array $e) => new FilterCriterion('name', $e[0], $e[1], $e[2] ?? false), $expected),
            $criteria
        );
    }

    public function cases(): array
    {
        $op = fn(string $name) => constant(FilterOperator::class . '::' . $name);

        return [
            // Text: one case per row of the operator table.
            'text eq' => ['text', 'eq Rex', [], [[$op('Eq'), 'Rex']]],
            'text ieq' => ['text', '= Rex', [], [[$op('Eq'), 'Rex', true]]],
            'text neq' => ['text', '<> Rex', [], [[$op('Neq'), 'Rex']]],
            'text ineq' => ['text', '!= Rex', [], [[$op('Neq'), 'Rex', true]]],
            'text gt' => ['text', '> 5', [], [[$op('Gt'), '5']]],
            'text gte' => ['text', 'gte 5', [], [[$op('Gte'), '5']]],
            'text lt' => ['text', '< 5', [], [[$op('Lt'), '5']]],
            'text lte' => ['text', '<= 5', [], [[$op('Lte'), '5']]],
            'text like' => ['text', 'like Rex', [], [[$op('Contains'), 'Rex']]],
            'text ilike (default)' => ['text', 'Rex', [], [[$op('Contains'), 'Rex', true]]],
            'text nlike' => ['text', 'notlike Rex', [], [[$op('NotContains'), 'Rex']]],
            'text nilike' => ['text', 'nilike Rex', [], [[$op('NotContains'), 'Rex', true]]],
            'text startwith' => ['text', 'startwith Rex', [], [[$op('StartsWith'), 'Rex']]],
            'text istartwith' => ['text', 'istartwith Rex', [], [[$op('StartsWith'), 'Rex', true]]],
            'text endwith' => ['text', 'endwith Rex', [], [[$op('EndsWith'), 'Rex']]],
            'text in' => ['text', 'in a, b,,c', [], [[$op('In'), ['a', 'b', 'c']]]],
            'text between' => ['text', 'btw 10 and 20', [], [[$op('Between'), ['10', '20']]]],
            'text malformed between' => ['text', 'btw 10', [], [[$op('Contains'), '10', true]]],
            'text wildcard contains' => ['text', '%Rex%', [], [[$op('Contains'), 'Rex', true]]],
            'text wildcard starts' => ['text', 'Rex%', [], [[$op('StartsWith'), 'Rex', true]]],
            'text wildcard ends' => ['text', '%Rex', [], [[$op('EndsWith'), 'Rex', true]]],
            'text wildcard only' => ['text', '%%', [], []],
            'text explicit beats wildcard' => ['text', 'eq %Rex%', [], [[$op('Eq'), '%Rex%']]],
            'text default operator' => ['text', 'Rex', ['default_operator' => 'eq'], [[$op('Eq'), 'Rex']]],
            'text blank' => ['text', '  ', [], []],
            // Number
            'number plain' => ['number', '34', [], [[$op('Eq'), 34]]],
            'number decimal comma' => ['number', '>3,5', [], [[$op('Gt'), 3.5]]],
            'number operator' => ['number', '>=10', [], [[$op('Gte'), 10]]],
            'number neq' => ['number', '<>10', [], [[$op('Neq'), 10]]],
            'number range swapped' => ['number', '20..10', [], [[$op('Between'), [10, 20]]]],
            'number legacy range' => ['number', '10-20', [], [[$op('Between'), [10, 20]]]],
            'number widget' => ['number', ['from' => '5', 'to' => '10'], [], [[$op('Gte'), 5], [$op('Lte'), 10]]],
            'number widget expression' => ['number', ['from' => '>5', 'to' => ''], [], [[$op('Gt'), 5]]],
            'number junk' => ['number', 'abc', [], []],
            // Date
            'date range' => ['date', ['from' => '2026-01-01', 'to' => '2026-02-01'], [], [
                [$op('Gte'), new \DateTimeImmutable('2026-01-01 00:00:00')],
                [$op('Lte'), new \DateTimeImmutable('2026-02-01 23:59:59')],
            ]],
            'date without end of day' => ['date', ['to' => '2026-02-01'], ['end_of_day' => false], [
                [$op('Lte'), new \DateTimeImmutable('2026-02-01 00:00:00')],
            ]],
            'date non iso' => ['date', ['from' => '01/02/2026'], [], []],
            // Boolean
            'boolean true' => ['boolean', '1', [], [[$op('Eq'), true]]],
            'boolean false' => ['boolean', '0', [], [[$op('Eq'), false]]],
            'boolean any' => ['boolean', '', [], []],
            // Choice and relation keep the raw values.
            'choice one' => ['choice', '7', [], [[$op('Eq'), '7']]],
            'choice many' => ['choice', ['7', '', '9'], [], [[$op('In'), ['7', '9']]]],
            'choice blank' => ['choice', ['', null], [], []],
            'relation many' => ['relation', ['7', '9'], [], [[$op('In'), ['7', '9']]]],
        ];
    }

    public function testEveryFilterTypeHasABuilder(): void
    {
        $registry = new FilterCriteriaBuilderRegistry();

        foreach (FilterType::cases() as $type) {
            $this->assertTrue($registry->has($type->value), $type->value);
        }
    }

    public function testUnknownTypeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown filter criteria builder type "colour".');

        (new FilterCriteriaBuilderRegistry())->get('colour');
    }
}
