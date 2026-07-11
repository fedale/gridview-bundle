<?php

namespace Fedale\GridviewBundle\Column\Type;

/** Telephone number — a tel: link. Mirrors EasyAdmin's `TelephoneField`. */
class TelType extends LinkType
{
    public function getName(): string
    {
        return 'tel';
    }

    public function getParent(): ?string
    {
        return 'link';
    }

    protected function hrefFor(string $value): string
    {
        return 'tel:' . \preg_replace('/\s+/', '', $value);
    }

    public function inferControlType(): ?string
    {
        return 'tel';
    }
}
