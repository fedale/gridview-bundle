<?php

namespace Fedale\GridviewBundle\Column\Type;

/** Plain text — the root scalar type (escaped passthrough). */
class TextType extends AbstractColumnType
{
    public function getName(): string
    {
        return 'text';
    }
}
