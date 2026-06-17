<?php

namespace Fedale\GridviewBundle\Column\Type;

use Fedale\GridviewBundle\Contract\ColumnInterface;

/**
 * Rating — a fixed scale rendered as filled/empty stars. Accepts an integer
 * (3) or a Twenty-style "RATING_3" token. Options: `max` (default 5).
 */
class RatingType extends SelectType
{
    public function getName(): string
    {
        return 'rating';
    }

    public function getParent(): ?string
    {
        return 'select';
    }

    public function getDefaultOptions(): array
    {
        return ['max' => 5];
    }

    public function format(mixed $value, array $options, ColumnInterface $column): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (\is_string($value) && \preg_match('/(\d+)/', $value, $m)) {
            return (int) $m[1];
        }

        return (int) $value;
    }

    public function render(mixed $value, array $options, ColumnInterface $column): mixed
    {
        if ($value === null || $value === '') {
            return '';
        }

        $max  = (int) ($options['max'] ?? 5);
        $n    = max(0, min($max, (int) $value));
        $stars = \str_repeat('★', $n) . \str_repeat('☆', $max - $n);

        return $this->markup(sprintf(
            '<span class="gv-rating" title="%d/%d">%s</span>',
            $n,
            $max,
            $stars
        ));
    }
}
