<?php

namespace Fedale\GridviewBundle\DataSource;

final class SortOrder
{
    public function __construct(
        public readonly string $attribute,
        public readonly SortDirection $direction,
    ) {
    }
}
