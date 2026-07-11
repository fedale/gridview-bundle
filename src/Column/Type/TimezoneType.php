<?php

namespace Fedale\GridviewBundle\Column\Type;

use Fedale\GridviewBundle\Contract\ColumnInterface;

/**
 * IANA timezone identifier (Europe/Rome, America/New_York, …) — already
 * human-readable, so it renders as-is with its current UTC offset appended
 * (`options.showOffset`, default true) using the core `\DateTimeZone` (no
 * optional dependency needed, unlike Country/Language/Locale). Mirrors
 * EasyAdmin's `TimezoneField` / Symfony's `TimezoneType` control.
 */
class TimezoneType extends AbstractColumnType
{
    public function getDefaultOptions(): array
    {
        return ['showOffset' => true];
    }

    public function getName(): string
    {
        return 'timezone';
    }

    public function format(mixed $value, array $options, ColumnInterface $column): mixed
    {
        if ($value === null || $value === '') {
            return '';
        }

        $id = (string) $value;

        if (!($options['showOffset'] ?? true)) {
            return $id;
        }

        try {
            $tz     = new \DateTimeZone($id);
            $offset = $tz->getOffset(new \DateTimeImmutable('now', $tz));

            return sprintf('%s (UTC%s%02d:%02d)', $id, $offset < 0 ? '-' : '+', \intdiv(abs($offset), 3600), \intdiv(abs($offset) % 3600, 60));
        } catch (\Exception) {
            return $id;
        }
    }

    public function inferFilterType(): ?string
    {
        return 'text';
    }

    public function inferControlType(): ?string
    {
        return 'timezone';
    }
}
