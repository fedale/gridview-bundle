<?php

namespace Fedale\GridviewBundle\DataSource;

/**
 * One normalized filter condition, independent of any query language. A grid
 * filter yields zero or more of them, and they are AND-ed.
 *
 * Values are already parsed and typed by the criteria builder of the filter
 * type, so a source never re-parses request input:
 *
 * - text: strings, as typed by the user (Contains/StartsWith/EndsWith carry
 *   the bare term, no wildcard characters);
 * - number: int or float;
 * - date: \DateTimeImmutable. A Lte bound already sits at 23:59:59 when the
 *   filter asks for end_of_day, so sources never re-implement it. Known limit:
 *   that bound misses timestamps with fractional seconds after 23:59:59;
 * - boolean: bool;
 * - choice and relation: the raw request values (strings, blanks removed),
 *   exactly what the Doctrine applier binds. Casting them to the field's type
 *   (int, UUID, ...) is the source's job.
 *
 * In takes a non-empty list, Between a [low, high] pair.
 */
final class FilterCriterion
{
    public function __construct(
        public readonly string $attribute,
        public readonly FilterOperator $operator,
        public readonly mixed $value,
        public readonly bool $caseInsensitive = false,
    ) {
    }
}
