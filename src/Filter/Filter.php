<?php

namespace Fedale\GridviewBundle\Filter;

class Filter
{
    public const DEFAULT_VALUE = null;

    // Filters here: https://www.doctrine-project.org/projects/doctrine-collections/en/stable/expression-builder.html
    public const DATA_CONJUNCTION      = 0;
    public const DATA_DISJUNCTION      = 1;

    public const OPERATOR_EQ           = 'eq'; // Default filter
    public const OPERATOR_NEQ          = 'neq';
    public const OPERATOR_LT           = 'lt';
    public const OPERATOR_LTE          = 'lte';
    public const OPERATOR_GT           = 'gt';
    public const OPERATOR_GTE          = 'gte';
    public const OPERATOR_BTW          = 'btw';
    public const OPERATOR_BTWE         = 'btwe';
    public const OPERATOR_LIKE         = 'like';
    public const OPERATOR_NLIKE        = 'nlike';
    public const OPERATOR_RLIKE        = 'rlike';
    public const OPERATOR_LLIKE        = 'llike';
    public const OPERATOR_SLIKE        = 'slike'; // simple/strict LIKE
    public const OPERATOR_NSLIKE       = 'nslike';
    public const OPERATOR_RSLIKE       = 'rslike';
    public const OPERATOR_LSLIKE       = 'lslike';

    public const OPERATOR_ISNULL       = 'isNull';
    public const OPERATOR_ISNOTNULL    = 'isNotNull';
}
