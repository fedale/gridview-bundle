<?php

namespace Fedale\GridviewBundle\DataSource;

/**
 * The comparison a {@see FilterCriterion} asks for. Case sensitivity is not an
 * operator of its own: it is the criterion's caseInsensitive flag.
 */
enum FilterOperator: string
{
    case Eq = 'eq';
    case Neq = 'neq';
    case Lt = 'lt';
    case Lte = 'lte';
    case Gt = 'gt';
    case Gte = 'gte';
    /** The value occurs anywhere in the field. */
    case Contains = 'contains';
    case NotContains = 'not_contains';
    case StartsWith = 'starts_with';
    case EndsWith = 'ends_with';
    /** The value is a non-empty list; the field equals one of its items. */
    case In = 'in';
    /** The value is [low, high], inclusive on both ends, low <= high. */
    case Between = 'between';
}
