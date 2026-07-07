<?php

namespace Fedale\GridviewBundle\Filter;

/**
 * Filter type names as an enum, for use in a column spec — either
 * `filter => FilterType::Text` or `filter => ['type' => FilterType::Text]`.
 * Plain strings keep working; {@see \Fedale\GridviewBundle\Column\ColumnFactory}
 * unwraps a FilterType to its value.
 *
 * Mirrors {@see \Fedale\GridviewBundle\Filter\Applier\FilterApplierRegistry}
 * (kept in sync by the enum parity test).
 */
enum FilterType: string
{
    case Text = 'text';
    case Boolean = 'boolean';
    case Date = 'date';
    case Number = 'number';
    case Choice = 'choice';
    case Relation = 'relation';
}
