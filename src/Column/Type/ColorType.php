<?php

namespace Fedale\GridviewBundle\Column\Type;

use Fedale\GridviewBundle\Contract\ColumnInterface;

/**
 * Colour — renders a small swatch next to the raw hex/name value. Mirrors
 * EasyAdmin's `ColorField` / Symfony's `ColorType` control. The swatch is only
 * drawn for a value that looks like a `#rgb`/`#rrggbb` hex code (untrusted
 * values fall back to plain text, since the value is interpolated into a CSS
 * `style` attribute).
 */
class ColorType extends AbstractColumnType
{
    private const HEX_PATTERN = '/^#([0-9a-f]{3}|[0-9a-f]{6})$/i';

    public function getName(): string
    {
        return 'color';
    }

    public function render(mixed $value, array $options, ColumnInterface $column): mixed
    {
        if ($value === null || $value === '') {
            return '';
        }

        $value = (string) $value;
        if (!\preg_match(self::HEX_PATTERN, $value)) {
            return $value;
        }

        return $this->markup(sprintf(
            '<span class="gv-color-swatch" style="background-color:%s"></span>%s',
            $this->esc($value),
            $this->esc($value)
        ));
    }

    public function inferFilterType(): ?string
    {
        return 'text';
    }

    public function inferControlType(): ?string
    {
        return 'color';
    }
}
