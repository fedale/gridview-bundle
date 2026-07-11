<?php

namespace Fedale\GridviewBundle\Form\Control;

/**
 * Control (write-side field) type names as an enum, for use in a column spec —
 * either `control => ControlType::Money` or `control => ['type' => ControlType::Money]`.
 * Plain strings keep working; {@see \Fedale\GridviewBundle\Column\ColumnFactory}
 * unwraps a ControlType to its value.
 *
 * Mirrors {@see ControlTypeRegistry} (kept in sync by the enum parity test).
 */
enum ControlType: string
{
    case Text = 'text';
    case Html = 'html';
    case Email = 'email';
    case Url = 'url';
    case Tel = 'tel';
    case Password = 'password';
    case Color = 'color';
    case Number = 'number';
    case Integer = 'integer';
    case Money = 'money';
    case Percent = 'percent';
    case Range = 'range';
    case Date = 'date';
    case Datetime = 'datetime';
    case Time = 'time';
    case Boolean = 'boolean';
    case Choice = 'choice';
    case Enum = 'enum';
    case Relation = 'relation';
    case Country = 'country';
    case Language = 'language';
    case Locale = 'locale';
    case Timezone = 'timezone';
    case Currency = 'currency';
    case Hidden = 'hidden';
    case Media = 'media';
}
