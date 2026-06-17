<?php

namespace Fedale\GridviewBundle\Column\Type;

use Fedale\GridviewBundle\Contract\ColumnInterface;

/**
 * Image — renders an <img> from a URL/path value. Options: `width`, `height`,
 * `alt`, `fallback` (shown when the value is empty).
 */
class ImageType extends UrlType
{
    public function getName(): string
    {
        return 'image';
    }

    public function getParent(): ?string
    {
        return 'url';
    }

    public function render(mixed $value, array $options, ColumnInterface $column): mixed
    {
        $src = ($value === null || $value === '') ? ($options['fallback'] ?? null) : $value;
        if ($src === null || $src === '') {
            return '';
        }

        $attrs = '';
        foreach (['width', 'height'] as $dim) {
            if (isset($options[$dim])) {
                $attrs .= sprintf(' %s="%s"', $dim, $this->esc($options[$dim]));
            }
        }

        return $this->markup(sprintf(
            '<img src="%s" class="gv-img" loading="lazy" alt="%s"%s>',
            $this->esc($src),
            $this->esc($options['alt'] ?? ''),
            $attrs
        ));
    }

    public function inferFilterType(): ?string
    {
        return null;
    }
}
