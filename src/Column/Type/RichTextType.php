<?php

namespace Fedale\GridviewBundle\Column\Type;

/** Rich text — alias of HtmlType (raw render), kept as its own name for clarity. */
class RichTextType extends HtmlType
{
    public function getName(): string
    {
        return 'richText';
    }

    public function getParent(): ?string
    {
        return 'html';
    }
}
