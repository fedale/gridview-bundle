<?php

namespace Fedale\GridviewBundle\Column\Type;

use Fedale\GridviewBundle\Contract\ColumnInterface;

/**
 * Date — formats a DateTimeInterface (or parseable string) with `pattern`
 * (PHP date() format, default d/m/Y). Non-date values pass through unchanged so
 * an existing per-column `twigFilter: "date(...)"` keeps working.
 */
class DateType extends AbstractColumnType
{
    public function getName(): string
    {
        return 'date';
    }

    public function getDefaultOptions(): array
    {
        return ['pattern' => 'd/m/Y'];
    }

    public function format(mixed $value, array $options, ColumnInterface $column): mixed
    {
        if ($value === null || $value === '') {
            return '';
        }

        $date = $value instanceof \DateTimeInterface ? $value : $this->tryParse($value);
        if ($date === null) {
            return $value;
        }

        return $date->format((string) ($options['pattern'] ?? 'd/m/Y'));
    }

    private function tryParse(mixed $value): ?\DateTimeInterface
    {
        if (!\is_string($value)) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    public function inferFilterType(): ?string
    {
        return 'date';
    }

    public function inferControlType(): ?string
    {
        return 'date';
    }
}
