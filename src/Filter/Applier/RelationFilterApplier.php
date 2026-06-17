<?php

namespace Fedale\GridviewBundle\Filter\Applier;

/**
 * Same semantics as ChoiceFilterApplier today (scalar → eq, array → IN).
 * Separate class to keep the filter type → applier mapping 1:1 and leave
 * room for relation-specific behavior to diverge later.
 */
class RelationFilterApplier extends ChoiceFilterApplier
{
}
