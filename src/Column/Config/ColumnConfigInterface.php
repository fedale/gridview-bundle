<?php

namespace Fedale\GridviewBundle\Column\Config;

/**
 * A fluent, typed builder for a single grid column. Implementations produce the
 * exact same associative column spec (`ColumnSpec`) that
 * {@see \Fedale\GridviewBundle\Column\ColumnFactory} already consumes, so a
 * `buildColumns()` may freely mix builders, arrays and string shorthands.
 */
interface ColumnConfigInterface
{
    /**
     * The assembled column spec, ready for the ColumnFactory.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
