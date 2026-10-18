<?php

namespace Fedale\GridviewBundle\Tests\Support;

use Doctrine\ORM\QueryBuilder;

/**
 * A comparable picture of the WHERE clause a filter applier wrote: the DQL
 * with its parameter names replaced by :p1, :p2, ... in order of binding (the
 * appliers name them with uniqid(), so raw names never compare), followed by
 * each parameter's value and type.
 */
trait SnapshotsQueryBuilderTrait
{
    private function snapshot(QueryBuilder $qb): string
    {
        $dql = (string) $qb->getDQLPart('where');
        $lines = [];
        $index = 0;

        foreach ($qb->getParameters() as $parameter) {
            ++$index;
            $dql = str_replace(':' . $parameter->getName(), ':p' . $index, $dql);
            $lines[] = sprintf('p%d = %s (%s)', $index, $this->describeValue($parameter->getValue()), $this->describeType($parameter->getType()));
        }

        return implode(' | ', [$dql, ...$lines]);
    }

    private function describeValue(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return get_debug_type($value) . ' ' . $value->format('Y-m-d H:i:s');
        }
        if (is_array($value)) {
            return '[' . implode(', ', array_map($this->describeValue(...), $value)) . ']';
        }

        return get_debug_type($value) . ' ' . var_export($value, true);
    }

    private function describeType(mixed $type): string
    {
        if ($type instanceof \UnitEnum) {
            return $type::class . '::' . $type->name;
        }

        return var_export($type, true);
    }
}
