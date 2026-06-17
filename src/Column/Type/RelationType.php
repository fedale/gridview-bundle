<?php

namespace Fedale\GridviewBundle\Column\Type;

/**
 * Relation to another entity. Display is typically provided by a per-column
 * `value` closure (the bundle has no routing knowledge of related entities);
 * this type mainly drives the relation filter and control inference.
 */
class RelationType extends TextType
{
    public function getName(): string
    {
        return 'relation';
    }

    public function getParent(): ?string
    {
        return 'text';
    }

    public function inferFilterType(): ?string
    {
        return 'relation';
    }

    public function inferControlType(): ?string
    {
        return 'relation';
    }
}
