<?php

namespace Fedale\GridviewBundle\Column\Type;

use Fedale\GridviewBundle\Contract\ColumnInterface;

/**
 * Hyperlink — renders an <a href>. Options: `label` (link text, defaults to the
 * value), `target`, `rel`. Subtypes override hrefFor() for mailto:/tel: schemes.
 */
class LinkType extends TextType
{
    public function getName(): string
    {
        return 'link';
    }

    public function getParent(): ?string
    {
        return 'text';
    }

    public function render(mixed $value, array $options, ColumnInterface $column): mixed
    {
        if ($value === null || $value === '') {
            return '';
        }

        $label  = $options['label'] ?? $value;
        $target = isset($options['target']) ? sprintf(' target="%s"', $this->esc($options['target'])) : '';
        $rel    = isset($options['rel']) ? sprintf(' rel="%s"', $this->esc($options['rel'])) : '';

        return $this->markup(sprintf(
            '<a href="%s"%s%s>%s</a>',
            $this->esc($this->hrefFor((string) $value)),
            $target,
            $rel,
            $this->esc($label)
        ));
    }

    protected function hrefFor(string $value): string
    {
        return $value;
    }

    public function inferFilterType(): ?string
    {
        return 'text';
    }
}
