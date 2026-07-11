<?php

namespace Fedale\GridviewBundle\Column\Type;

use Fedale\GridviewBundle\Contract\ColumnInterface;

/**
 * Date — formats a DateTimeInterface (or parseable string). Locale-aware by
 * default: uses `options['locale']` (or `\Locale::getDefault()`, i.e. the
 * host's current request locale) via `\IntlDateFormatter` (SHORT, numeric,
 * field order per locale, year forced to 4 digits) when `ext-intl` is
 * available. Explicitly setting `pattern` (a PHP `date()`-format string)
 * bypasses locale entirely, unchanged; without `ext-intl`, formatting falls
 * back to that same pattern style (default d/m/Y). Non-date values pass
 * through unchanged so an existing per-column `twigFilter: "date(...)"` keeps
 * working.
 */
class DateType extends AbstractColumnType
{
    public function getName(): string
    {
        return 'date';
    }

    public function getDefaultOptions(): array
    {
        return [];
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

        if (\array_key_exists('pattern', $options)) {
            return $date->format((string) $options['pattern']);
        }

        if (!$this->intlAvailable()) {
            return $date->format($this->noIntlPattern());
        }

        $locale = (string) ($options['locale'] ?? \Locale::getDefault());
        [$dateStyle, $timeStyle] = $this->intlStyles();

        $fmt = new \IntlDateFormatter($locale, $dateStyle, $timeStyle, $date->getTimezone());
        // SHORT yields a 2-digit year for most locales (e.g. it_IT, de_DE,
        // en_US) but already 4 digits for others (e.g. fr_FR); normalize so no
        // locale silently regresses from the previous always-4-digit default.
        $pattern = \preg_replace('/(?<!y)yy(?!y)/', 'yyyy', $fmt->getPattern());
        $fmt->setPattern($pattern);

        return $fmt->format($date);
    }

    /** Overridable so tests can force the no-ext-intl fallback branch deterministically. */
    protected function intlAvailable(): bool
    {
        return \class_exists(\IntlDateFormatter::class);
    }

    /** @return array{0: int, 1: int} IntlDateFormatter date/time style pair */
    protected function intlStyles(): array
    {
        return [\IntlDateFormatter::SHORT, \IntlDateFormatter::NONE];
    }

    /** PHP date() format used when ext-intl is unavailable. */
    protected function noIntlPattern(): string
    {
        return 'd/m/Y';
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
