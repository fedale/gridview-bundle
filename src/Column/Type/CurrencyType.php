<?php

namespace Fedale\GridviewBundle\Column\Type;

use Fedale\GridviewBundle\Contract\ColumnInterface;

/**
 * Monetary amount — a number with a currency symbol/code. Scalar field in this
 * app (no composite amount+code). Options: `currency` (ISO code, default EUR),
 * `decimals` (default 2), `locale`. Uses ext-intl when available, else a symbol map.
 */
class CurrencyType extends NumberType
{
    private const SYMBOLS = ['EUR' => '€', 'USD' => '$', 'GBP' => '£', 'CHF' => 'CHF', 'JPY' => '¥'];

    public function getName(): string
    {
        return 'currency';
    }

    public function getParent(): ?string
    {
        return 'number';
    }

    public function getDefaultOptions(): array
    {
        return ['currency' => 'EUR', 'decimals' => 2, 'decimalSep' => ',', 'thousandsSep' => '.'];
    }

    public function format(mixed $value, array $options, ColumnInterface $column): mixed
    {
        if ($value === null || $value === '') {
            return '';
        }

        $currency = (string) ($options['currency'] ?? 'EUR');

        if (\class_exists(\NumberFormatter::class)) {
            $locale = (string) ($options['locale'] ?? \Locale::getDefault());
            $fmt = new \NumberFormatter($locale, \NumberFormatter::CURRENCY);
            if (isset($options['decimals'])) {
                $fmt->setAttribute(\NumberFormatter::FRACTION_DIGITS, (int) $options['decimals']);
            }

            return $fmt->formatCurrency((float) $value, $currency);
        }

        // Fallback: numeric formatting + trailing symbol (it-IT style "1.234,56 €").
        $number = parent::format($value, $options, $column);
        $symbol = self::SYMBOLS[$currency] ?? $currency;

        return $number . ' ' . $symbol;
    }
}
