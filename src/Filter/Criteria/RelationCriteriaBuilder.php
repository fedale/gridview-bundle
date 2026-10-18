<?php

namespace Fedale\GridviewBundle\Filter\Criteria;

/**
 * Same semantics as ChoiceCriteriaBuilder today (scalar → Eq on the raw
 * identifier, list → In). Separate class to keep the filter type → builder
 * mapping 1:1, like the appliers.
 */
class RelationCriteriaBuilder extends ChoiceCriteriaBuilder
{
}
