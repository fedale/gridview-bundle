<?php

namespace Fedale\GridviewBundle\Maker\Util;

/**
 * Marks a value to be printed verbatim by {@see PhpArrayPrinter} instead of
 * being serialized as a scalar. Used for value closures (`fn(array $data) => ...`)
 * and `::class` references, neither of which can be expressed as a plain
 * array-literal value.
 */
final class RawPhp
{
    public function __construct(public readonly string $code)
    {
    }
}
