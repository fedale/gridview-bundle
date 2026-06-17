<?php

namespace Fedale\GridviewBundle\Column\Type;

/** UUID / identifier — text with no filter by default. */
class UuidType extends TextType
{
    public function getName(): string
    {
        return 'uuid';
    }

    public function getParent(): ?string
    {
        return 'text';
    }

    public function inferFilterType(): ?string
    {
        return null;
    }
}
