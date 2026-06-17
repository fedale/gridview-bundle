<?php

namespace Fedale\GridviewBundle\Column\Type;

/** Email address — a mailto: link. */
class EmailType extends LinkType
{
    public function getName(): string
    {
        return 'email';
    }

    public function getParent(): ?string
    {
        return 'link';
    }

    protected function hrefFor(string $value): string
    {
        return 'mailto:' . $value;
    }
}
