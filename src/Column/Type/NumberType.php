<?php

namespace Fedale\GridviewBundle\Column\Type;

use Fedale\GridviewBundle\Contract\ColumnInterface;

/**
 * Numeric value — formatted with thousands/decimal separators and right-aligned
 * (`gv-num`). Locale-aware by default: uses `options['locale']` (or
 * `\Locale::getDefault()`, i.e. the host's current request locale) via
 * `\NumberFormatter` when `ext-intl` is available. Explicitly setting
 * `decimalSep` and/or `thousandsSep` bypasses locale entirely (manual mode);
 * without `ext-intl`, formatting degrades to a small `it`/`en` separator
 * table. Options: `decimals`, `decimalSep`, `thousandsSep`, `locale`.
 */
class NumberType extends AbstractColumnType
{
    /** @var array<string, array{0: string, 1: string}> decimalSep/thousandsSep by primary language subtag */
    private const NO_INTL_SEPARATORS = [
        'it' => [',', '.'],
        'en' => ['.', ','],
    ];

    public function getName(): string
    {
        return 'number';
    }

    public function getDefaultOptions(): array
    {
        return ['decimals' => 0];
    }

    public function format(mixed $value, array $options, ColumnInterface $column): mixed
    {
        if ($value === null || $value === '') {
            return '';
        }

        $decimals = (int) ($options['decimals'] ?? 0);

        // Explicit separator override: manual mode, locale plays no part.
        if (\array_key_exists('decimalSep', $options) || \array_key_exists('thousandsSep', $options)) {
            return \number_format(
                (float) $value,
                $decimals,
                (string) ($options['decimalSep'] ?? '.'),
                (string) ($options['thousandsSep'] ?? ',')
            );
        }

        $locale = (string) ($options['locale'] ?? \Locale::getDefault());

        if ($this->intlAvailable()) {
            $fmt = new \NumberFormatter($locale, \NumberFormatter::DECIMAL);
            $fmt->setAttribute(\NumberFormatter::FRACTION_DIGITS, $decimals);

            return $fmt->format((float) $value);
        }

        [$decimalSep, $thousandsSep] = $this->noIntlSeparators($locale);

        return \number_format((float) $value, $decimals, $decimalSep, $thousandsSep);
    }

    /** Overridable so tests can force the no-ext-intl fallback branch deterministically. */
    protected function intlAvailable(): bool
    {
        return \class_exists(\NumberFormatter::class);
    }

    /** @return array{0: string, 1: string} */
    private function noIntlSeparators(string $locale): array
    {
        $lang = \strtolower(\substr($locale, 0, 2));

        return self::NO_INTL_SEPARATORS[$lang] ?? self::NO_INTL_SEPARATORS['en'];
    }

    public function render(mixed $value, array $options, ColumnInterface $column): mixed
    {
        if ($value === null || $value === '') {
            return '';
        }

        return $this->markup('<span class="gv-num">' . $this->esc($value) . '</span>');
    }

    public function inferFilterType(): ?string
    {
        return 'number';
    }

    public function inferControlType(): ?string
    {
        return 'number';
    }
}
