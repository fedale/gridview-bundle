<?php

namespace Fedale\GridviewBundle\Filter;

class Filter
{
    const DEFAULT_VALUE = null;

    // Filters here: https://www.doctrine-project.org/projects/doctrine-collections/en/stable/expression-builder.html
    const DATA_CONJUNCTION      = 0;
    const DATA_DISJUNCTION      = 1;

    const OPERATOR_EQ           = 'eq'; // Default filter
    const OPERATOR_NEQ          = 'neq';
    const OPERATOR_LT           = 'lt';
    const OPERATOR_LTE          = 'lte';
    const OPERATOR_GT           = 'gt';
    const OPERATOR_GTE          = 'gte';
    const OPERATOR_BTW          = 'btw';
    const OPERATOR_BTWE         = 'btwe';
    const OPERATOR_LIKE         = 'like';
    const OPERATOR_NLIKE        = 'nlike';
    const OPERATOR_RLIKE        = 'rlike';
    const OPERATOR_LLIKE        = 'llike';
    const OPERATOR_SLIKE        = 'slike'; //simple/strict LIKE
    const OPERATOR_NSLIKE       = 'nslike';
    const OPERATOR_RSLIKE       = 'rslike';
    const OPERATOR_LSLIKE       = 'lslike';

    const OPERATOR_ISNULL       = 'isNull';
    const OPERATOR_ISNOTNULL    = 'isNotNull';
}
