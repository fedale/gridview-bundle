<?php

namespace Fedale\GridviewBundle\Column\Type;

/** External URL — a link defaulting to opening in a new tab. */
class UrlType extends LinkType
{
    public function getName(): string
    {
        return 'url';
    }

    public function getParent(): ?string
    {
        return 'link';
    }

    public function getDefaultOptions(): array
    {
        return ['target' => '_blank', 'rel' => 'noopener noreferrer'];
    }
}
