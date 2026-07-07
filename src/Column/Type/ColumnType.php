<?php

namespace Fedale\GridviewBundle\Column\Type;

/**
 * Canonical column type names as an enum, for IDE autocomplete, go-to-definition
 * and typo-safety in a column spec `type => ...`. Plain strings keep working —
 * {@see \Fedale\GridviewBundle\Column\ColumnFactory} unwraps a ColumnType to its
 * string value.
 *
 * The data-type cases mirror {@see ColumnTypeRegistry::withBuiltins()} (kept in
 * sync by the enum parity test); the last three are the structural columns
 * resolved directly by the factory (row actions, selection, row numbers).
 */
enum ColumnType: string
{
    case Text = 'text';
    case Uuid = 'uuid';
    case Html = 'html';
    case RichText = 'richText';
    case Json = 'json';
    case Link = 'link';
    case Url = 'url';
    case Email = 'email';
    case Media = 'media';
    case Number = 'number';
    case Currency = 'currency';
    case Percent = 'percent';
    case Boolean = 'boolean';
    case Date = 'date';
    case Datetime = 'datetime';
    case Select = 'select';
    case MultiSelect = 'multiSelect';
    case Rating = 'rating';
    case Badge = 'badge';
    case List = 'list';
    case Relation = 'relation';

    // Structural columns (not data types).
    case Action = 'action';
    case Checkbox = 'checkbox';
    case Serial = 'serial';
}
