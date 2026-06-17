<?php

namespace Fedale\GridviewBundle\Column\Type;

use Fedale\GridviewBundle\Contract\ColumnInterface;

/**
 * Boolean — renders a check/cross glyph. Options `true`/`false` override the
 * displayed strings (i18n is layered on top in Workstream C).
 */
class BooleanType extends AbstractColumnType
{
    public function getDefaultOptions(): array
    {
        return ['true' => '✓', 'false' => '✗'];
    }

    public function getName(): string
    {
        return 'boolean';
    }

    public function format(mixed $value, array $options, ColumnInterface $column): mixed
    {
        return match (true) {
            $value === true,  $value === 1, $value === '1', $value === 'true'  => $options['true'] ?? '✓',
            $value === false, $value === 0, $value === '0', $value === 'false' => $options['false'] ?? '✗',
            default => '',
        };
    }

    public function inferFilterType(): ?string
    {
        return 'boolean';
    }

    public function inferControlType(): ?string
    {
        return 'boolean';
    }
}
