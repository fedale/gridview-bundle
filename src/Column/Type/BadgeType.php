<?php

namespace Fedale\GridviewBundle\Column\Type;

use Fedale\GridviewBundle\Contract\ColumnInterface;

/**
 * Status badge — a select rendered as a coloured chip. Options: `choices`
 * (label map), `colors` as a `value => cssColor` map applied as an inline
 * background. A modifier class `gv-badge--<value>` is also emitted for CSS theming.
 *
 * Unlike the parent, label mapping happens in render() so the raw value is still
 * available for the colour/modifier lookup.
 */
class BadgeType extends SelectType
{
    public function getName(): string
    {
        return 'badge';
    }

    public function getParent(): ?string
    {
        return 'select';
    }

    public function format(mixed $value, array $options, ColumnInterface $column): mixed
    {
        // Keep the raw value; render() does the label lookup.
        return $value;
    }

    public function render(mixed $value, array $options, ColumnInterface $column): mixed
    {
        if ($value === null || $value === '') {
            return '';
        }

        $label    = $this->labelFor($value, $options['choices'] ?? []);
        $modifier = $this->slug((string) $value);

        $style = '';
        $color = $options['colors'][$value] ?? null;
        if ($color !== null) {
            $style = sprintf(' style="background-color:%s"', $this->esc($color));
        }

        return $this->markup(sprintf(
            '<span class="gv-badge gv-badge--%s"%s>%s</span>',
            $this->esc($modifier),
            $style,
            $this->esc($label)
        ));
    }

    private function slug(string $value): string
    {
        $slug = \strtolower(\preg_replace('/[^A-Za-z0-9]+/', '-', $value) ?? '');

        return \trim($slug, '-');
    }
}
