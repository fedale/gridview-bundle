<?php

namespace Fedale\GridviewBundle\Column\Type;

use Fedale\GridviewBundle\Contract\ColumnInterface;

/**
 * Trusted HTML / rich text — rendered raw. This is the explicit "raw" path,
 * an alternative to `twigFilter: 'raw'`. The value is assumed producer-trusted.
 */
class HtmlType extends TextType
{
    public function getName(): string
    {
        return 'html';
    }

    public function getParent(): ?string
    {
        return 'text';
    }

    public function render(mixed $value, array $options, ColumnInterface $column): mixed
    {
        return $value === null ? '' : $this->markup((string) $value);
    }

    public function inferFilterType(): ?string
    {
        return null;
    }
}
