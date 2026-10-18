<?php

namespace Fedale\GridviewBundle\Tests\Filter\Applier;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr;
use Doctrine\ORM\QueryBuilder;
use Fedale\GridviewBundle\Filter\Applier\FilterApplierRegistry;
use Fedale\GridviewBundle\Tests\Support\SnapshotsQueryBuilderTrait;
use PHPUnit\Framework\TestCase;

/**
 * Characterization of every built-in filter applier: the WHERE clause, the
 * parameter values and the parameter types each raw request value produces.
 *
 * The expectations were recorded from the appliers as they were before their
 * parsing moved into the criteria builders, and they must not change: entity
 * grids filter exactly as before whatever the appliers are built on. An empty
 * snapshot means the value adds no constraint.
 */
class FilterApplierParityTest extends TestCase
{
    use SnapshotsQueryBuilderTrait;

    /**
     * @dataProvider cases
     */
    public function testApplierWritesTheRecordedClause(string $type, mixed $rawValue, array $options, string $expected): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getExpressionBuilder')->willReturn(new Expr());
        $qb = new QueryBuilder($em);

        (new FilterApplierRegistry())->get($type)->apply($qb, 'e.field', $rawValue, $options);

        $this->assertSame($expected, $this->snapshot($qb));
    }

    public function cases(): array
    {
        return [
            'text plain' => ['text', 'Foo', [], 'LOWER(e.field) LIKE :p1 | p1 = string \'%foo%\' (Doctrine\\DBAL\\ParameterType::STRING)'],
            'text blank' => ['text', '   ', [], ''],
            'text non scalar' => ['text', ['a'], [], ''],
            'text eq' => ['text', 'eq Foo', [], 'e.field = :p1 | p1 = string \'Foo\' (Doctrine\\DBAL\\ParameterType::STRING)'],
            'text ==' => ['text', '== Foo', [], 'e.field = :p1 | p1 = string \'Foo\' (Doctrine\\DBAL\\ParameterType::STRING)'],
            'text ieq' => ['text', 'ieq Foo', [], 'LOWER(e.field) = :p1 | p1 = string \'foo\' (Doctrine\\DBAL\\ParameterType::STRING)'],
            'text =' => ['text', '= Foo', [], 'LOWER(e.field) = :p1 | p1 = string \'foo\' (Doctrine\\DBAL\\ParameterType::STRING)'],
            'text neq' => ['text', 'neq Foo', [], 'e.field <> :p1 | p1 = string \'Foo\' (Doctrine\\DBAL\\ParameterType::STRING)'],
            'text not' => ['text', 'not Foo', [], 'e.field <> :p1 | p1 = string \'Foo\' (Doctrine\\DBAL\\ParameterType::STRING)'],
            'text <>' => ['text', '<> Foo', [], 'e.field <> :p1 | p1 = string \'Foo\' (Doctrine\\DBAL\\ParameterType::STRING)'],
            'text ineq' => ['text', 'ineq Foo', [], 'LOWER(e.field) <> :p1 | p1 = string \'foo\' (Doctrine\\DBAL\\ParameterType::STRING)'],
            'text !=' => ['text', '!= Foo', [], 'LOWER(e.field) <> :p1 | p1 = string \'foo\' (Doctrine\\DBAL\\ParameterType::STRING)'],
            'text gt' => ['text', 'gt 5', [], 'e.field > :p1 | p1 = string \'5\' (Doctrine\\DBAL\\ParameterType::STRING)'],
            'text >' => ['text', '> 5', [], 'e.field > :p1 | p1 = string \'5\' (Doctrine\\DBAL\\ParameterType::STRING)'],
            'text gte' => ['text', '>= 5', [], 'e.field >= :p1 | p1 = string \'5\' (Doctrine\\DBAL\\ParameterType::STRING)'],
            'text lt' => ['text', '< 5', [], 'e.field < :p1 | p1 = string \'5\' (Doctrine\\DBAL\\ParameterType::STRING)'],
            'text lte' => ['text', 'lte 5', [], 'e.field <= :p1 | p1 = string \'5\' (Doctrine\\DBAL\\ParameterType::STRING)'],
            'text like' => ['text', 'like Foo', [], 'e.field LIKE :p1 | p1 = string \'%Foo%\' (Doctrine\\DBAL\\ParameterType::STRING)'],
            'text %' => ['text', '% Foo', [], 'e.field LIKE :p1 | p1 = string \'%Foo%\' (Doctrine\\DBAL\\ParameterType::STRING)'],
            'text ilike' => ['text', 'ilike Foo', [], 'LOWER(e.field) LIKE :p1 | p1 = string \'%foo%\' (Doctrine\\DBAL\\ParameterType::STRING)'],
            'text nlike' => ['text', 'notlike Foo', [], 'e.field NOT LIKE :p1 | p1 = string \'%Foo%\' (Doctrine\\DBAL\\ParameterType::STRING)'],
            'text !%' => ['text', '!% Foo', [], 'e.field NOT LIKE :p1 | p1 = string \'%Foo%\' (Doctrine\\DBAL\\ParameterType::STRING)'],
            'text nilike' => ['text', 'nilike Foo', [], 'LOWER(e.field) NOT LIKE :p1 | p1 = string \'%foo%\' (Doctrine\\DBAL\\ParameterType::STRING)'],
            'text startwith' => ['text', 'startwith Foo', [], 'e.field LIKE :p1 | p1 = string \'Foo%\' (Doctrine\\DBAL\\ParameterType::STRING)'],
            'text -%' => ['text', '-% Foo', [], 'e.field LIKE :p1 | p1 = string \'Foo%\' (Doctrine\\DBAL\\ParameterType::STRING)'],
            'text istartwith' => ['text', 'istartwith Foo', [], 'LOWER(e.field) LIKE :p1 | p1 = string \'foo%\' (Doctrine\\DBAL\\ParameterType::STRING)'],
            'text endwith' => ['text', 'endwith Foo', [], 'e.field LIKE :p1 | p1 = string \'%Foo\' (Doctrine\\DBAL\\ParameterType::STRING)'],
            'text %-' => ['text', '%- Foo', [], 'e.field LIKE :p1 | p1 = string \'%Foo\' (Doctrine\\DBAL\\ParameterType::STRING)'],
            'text in' => ['text', 'in a, b,,c', [], 'e.field IN(:p1) | p1 = [string \'a\', string \'b\', string \'c\'] (Doctrine\\DBAL\\ArrayParameterType::STRING)'],
            'text in empty' => ['text', 'in , ,', [], ''],
            'text between' => ['text', 'btw 10 and 20', [], 'e.field BETWEEN :p1 AND :p2 | p1 = string \'10\' (Doctrine\\DBAL\\ParameterType::STRING) | p2 = string \'20\' (Doctrine\\DBAL\\ParameterType::STRING)'],
            'text between malformed' => ['text', 'between 10', [], 'LOWER(e.field) LIKE :p1 | p1 = string \'%10%\' (Doctrine\\DBAL\\ParameterType::STRING)'],
            'text operator only' => ['text', 'eq ', [], 'LOWER(e.field) LIKE :p1 | p1 = string \'%eq%\' (Doctrine\\DBAL\\ParameterType::STRING)'],
            'text wildcard contains' => ['text', '%Foo%', [], 'LOWER(e.field) LIKE :p1 | p1 = string \'%foo%\' (Doctrine\\DBAL\\ParameterType::STRING)'],
            'text wildcard starts' => ['text', 'Foo%', [], 'LOWER(e.field) LIKE :p1 | p1 = string \'foo%\' (Doctrine\\DBAL\\ParameterType::STRING)'],
            'text wildcard ends' => ['text', '%Foo', [], 'LOWER(e.field) LIKE :p1 | p1 = string \'%foo\' (Doctrine\\DBAL\\ParameterType::STRING)'],
            'text wildcard only' => ['text', '%%', [], ''],
            'text wildcard single' => ['text', '%', [], ''],
            'text wildcard custom' => ['text', '*Foo', ['wildcard' => '*'], 'LOWER(e.field) LIKE :p1 | p1 = string \'%foo\' (Doctrine\\DBAL\\ParameterType::STRING)'],
            'text wildcard disabled' => ['text', '%Foo%', ['wildcard' => ''], 'LOWER(e.field) LIKE :p1 | p1 = string \'%%foo%%\' (Doctrine\\DBAL\\ParameterType::STRING)'],
            'text explicit beats wildcard' => ['text', 'eq %Foo%', [], 'e.field = :p1 | p1 = string \'%Foo%\' (Doctrine\\DBAL\\ParameterType::STRING)'],
            'text default eq' => ['text', 'Foo', ['default_operator' => 'eq'], 'e.field = :p1 | p1 = string \'Foo\' (Doctrine\\DBAL\\ParameterType::STRING)'],
            'text default unknown' => ['text', 'Foo', ['default_operator' => 'zzz'], 'LOWER(e.field) LIKE :p1 | p1 = string \'%foo%\' (Doctrine\\DBAL\\ParameterType::STRING)'],
            'text default ==' => ['text', 'Foo', ['default_operator' => '=='], 'e.field = :p1 | p1 = string \'Foo\' (Doctrine\\DBAL\\ParameterType::STRING)'],
            'text default in' => ['text', 'a,b', ['default_operator' => 'in'], 'e.field IN(:p1) | p1 = [string \'a\', string \'b\'] (Doctrine\\DBAL\\ArrayParameterType::STRING)'],
            'text no trim' => ['text', '  Foo  ', ['trim' => false], 'LOWER(e.field) LIKE :p1 | p1 = string \'%  foo  %\' (Doctrine\\DBAL\\ParameterType::STRING)'],
            'text int' => ['text', 42, [], 'LOWER(e.field) LIKE :p1 | p1 = string \'%42%\' (Doctrine\\DBAL\\ParameterType::STRING)'],
            'text uppercase op' => ['text', 'EQ Foo', [], 'e.field = :p1 | p1 = string \'Foo\' (Doctrine\\DBAL\\ParameterType::STRING)'],
            'number plain' => ['number', '34', [], 'e.field = :p1 | p1 = int 34 (\'integer\')'],
            'number decimal comma' => ['number', '3,5', [], ''],
            'number int' => ['number', 34, [], 'e.field = :p1 | p1 = int 34 (\'integer\')'],
            'number gt' => ['number', '>5', [], 'e.field > :p1 | p1 = int 5 (\'integer\')'],
            'number gte space' => ['number', '>= 10', [], 'e.field >= :p1 | p1 = int 10 (\'integer\')'],
            'number lt' => ['number', '<3', [], 'e.field < :p1 | p1 = int 3 (\'integer\')'],
            'number lte' => ['number', '<=3', [], 'e.field <= :p1 | p1 = int 3 (\'integer\')'],
            'number eq' => ['number', '=10', [], 'e.field = :p1 | p1 = int 10 (\'integer\')'],
            'number neq' => ['number', '!=10', [], 'e.field <> :p1 | p1 = int 10 (\'integer\')'],
            'number ltgt' => ['number', '<>10', [], 'e.field <> :p1 | p1 = int 10 (\'integer\')'],
            'number negative' => ['number', '>-5', [], 'e.field > :p1 | p1 = int -5 (\'integer\')'],
            'number btw' => ['number', 'btw 10 and 20', [], 'e.field BETWEEN :p1 AND :p2 | p1 = int 10 (\'integer\') | p2 = int 20 (\'integer\')'],
            'number btw reversed' => ['number', 'BTW 20 AND 10', [], 'e.field BETWEEN :p1 AND :p2 | p1 = int 10 (\'integer\') | p2 = int 20 (\'integer\')'],
            'number arrow' => ['number', '-5 -> 5', [], 'e.field BETWEEN :p1 AND :p2 | p1 = int -5 (\'integer\') | p2 = int 5 (\'integer\')'],
            'number dots' => ['number', '1.5..2,5', [], 'e.field BETWEEN :p1 AND :p2 | p1 = float 1.5 (Doctrine\\DBAL\\ParameterType::STRING) | p2 = float 2.5 (Doctrine\\DBAL\\ParameterType::STRING)'],
            'number legacy' => ['number', '10-20', [], 'e.field BETWEEN :p1 AND :p2 | p1 = int 10 (\'integer\') | p2 = int 20 (\'integer\')'],
            'number legacy custom sep' => ['number', '10/20', ['range_separator' => '/'], 'e.field BETWEEN :p1 AND :p2 | p1 = int 10 (\'integer\') | p2 = int 20 (\'integer\')'],
            'number legacy no sep' => ['number', '10-20', ['range_separator' => ''], ''],
            'number junk' => ['number', 'abc', [], ''],
            'number blank' => ['number', '  ', [], ''],
            'number null' => ['number', null, [], ''],
            'number range' => ['number', ['from' => '5', 'to' => '10'], [], 'e.field >= :p1 AND e.field <= :p2 | p1 = int 5 (\'integer\') | p2 = int 10 (\'integer\')'],
            'number range from only' => ['number', ['from' => '5', 'to' => ''], [], 'e.field >= :p1 | p1 = int 5 (\'integer\')'],
            'number range expr' => ['number', ['from' => '>5', 'to' => '20'], [], 'e.field > :p1 AND e.field <= :p2 | p1 = int 5 (\'integer\') | p2 = int 20 (\'integer\')'],
            'number range between bound' => ['number', ['from' => '1..3'], [], 'e.field BETWEEN :p1 AND :p2 | p1 = int 1 (\'integer\') | p2 = int 3 (\'integer\')'],
            'number range blank' => ['number', ['from' => '', 'to' => ''], [], ''],
            'number range junk' => ['number', ['from' => 'x', 'to' => '7'], [], 'e.field <= :p1 | p1 = int 7 (\'integer\')'],
            'date both' => ['date', ['from' => '2026-01-01', 'to' => '2026-02-01'], [], 'e.field >= :p1 AND e.field <= :p2 | p1 = DateTime 2026-01-01 00:00:00 (\'datetime\') | p2 = DateTime 2026-02-01 23:59:59 (\'datetime\')'],
            'date no end of day' => ['date', ['to' => '2026-02-01'], ['end_of_day' => false], 'e.field <= :p1 | p1 = DateTime 2026-02-01 00:00:00 (\'datetime\')'],
            'date from only' => ['date', ['from' => '2026-01-01', 'to' => ''], [], 'e.field >= :p1 | p1 = DateTime 2026-01-01 00:00:00 (\'datetime\')'],
            'date non iso' => ['date', ['from' => '01/02/2026', 'to' => '2026-13-99x'], [], ''],
            'date scalar' => ['date', '2026-01-01', [], ''],
            'date blank' => ['date', ['from' => '', 'to' => ''], [], ''],
            'date null' => ['date', null, [], ''],
            'boolean 1' => ['boolean', '1', [], 'e.field = :p1 | p1 = bool true (\'boolean\')'],
            'boolean 0' => ['boolean', '0', [], 'e.field = :p1 | p1 = bool false (\'boolean\')'],
            'boolean int 1' => ['boolean', 1, [], 'e.field = :p1 | p1 = bool true (\'boolean\')'],
            'boolean int 0' => ['boolean', 0, [], 'e.field = :p1 | p1 = bool false (\'boolean\')'],
            'boolean true' => ['boolean', true, [], 'e.field = :p1 | p1 = bool true (\'boolean\')'],
            'boolean false' => ['boolean', false, [], 'e.field = :p1 | p1 = bool false (\'boolean\')'],
            'boolean empty' => ['boolean', '', [], ''],
            'boolean other' => ['boolean', 'yes', [], ''],
            'boolean null' => ['boolean', null, [], ''],
            'choice scalar' => ['choice', 'red', [], 'e.field = :p1 | p1 = string \'red\' (Doctrine\\DBAL\\ParameterType::STRING)'],
            'choice int' => ['choice', 3, [], 'e.field = :p1 | p1 = int 3 (\'integer\')'],
            'choice zero' => ['choice', '0', [], 'e.field = :p1 | p1 = string \'0\' (Doctrine\\DBAL\\ParameterType::STRING)'],
            'choice list' => ['choice', ['red', '', 'blue', null], [], 'e.field IN(:p1) | p1 = [string \'red\', string \'blue\'] (Doctrine\\DBAL\\ArrayParameterType::STRING)'],
            'choice list numeric' => ['choice', ['1', '2'], [], 'e.field IN(:p1) | p1 = [string \'1\', string \'2\'] (Doctrine\\DBAL\\ArrayParameterType::STRING)'],
            'choice list blank' => ['choice', ['', null], [], ''],
            'choice blank' => ['choice', '', [], ''],
            'relation scalar' => ['relation', '7', [], 'e.field = :p1 | p1 = string \'7\' (Doctrine\\DBAL\\ParameterType::STRING)'],
            'relation list' => ['relation', ['7', '9'], [], 'e.field IN(:p1) | p1 = [string \'7\', string \'9\'] (Doctrine\\DBAL\\ArrayParameterType::STRING)'],
            'relation blank' => ['relation', [], [], ''],
        ];
    }
}
