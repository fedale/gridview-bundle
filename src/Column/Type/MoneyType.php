<?php

namespace Fedale\GridviewBundle\Column\Type;

use Fedale\GridviewBundle\Contract\ColumnInterface;

/**
 * Monetary amount — a number with a currency symbol/code. Scalar field in this
 * app (no composite amount+code). Options: `currency` (ISO code, default EUR),
 * `decimals` (default 2), `locale`. Uses ext-intl (`\NumberFormatter::CURRENCY`,
 * fully locale-aware) when available; otherwise falls back to
 * {@see NumberType}'s locale-aware number formatting plus a trailing symbol from
 * a small static map — a simplification (fixed symbol position/spacing) since
 * true currency formatting conventions require ext-intl.
 *
 * Named to mirror Symfony's own `MoneyType`/`CurrencyType` split (also used by
 * EasyAdmin's `MoneyField`/`CurrencyField`): this type edits an **amount**, its
 * write-side twin is the `money` control. The ISO currency **code** itself is a
 * separate {@see CurrencyType}.
 */
class MoneyType extends NumberType
{
    private const SYMBOLS = ['EUR' => '€', 'USD' => '$', 'GBP' => '£', 'CHF' => 'CHF', 'JPY' => '¥'];

    public function getName(): string
    {
        return 'money';
    }

    public function getParent(): ?string
    {
        return 'number';
    }

    public function getDefaultOptions(): array
    {
        return ['currency' => 'EUR', 'decimals' => 2];
    }

    public function format(mixed $value, array $options, ColumnInterface $column): mixed
    {
        if ($value === null || $value === '') {
            return '';
        }

        $currency = (string) ($options['currency'] ?? 'EUR');

        if ($this->intlAvailable()) {
            $locale = (string) ($options['locale'] ?? \Locale::getDefault());
            $fmt = new \NumberFormatter($locale, \NumberFormatter::CURRENCY);
            if (isset($options['decimals'])) {
                $fmt->setAttribute(\NumberFormatter::FRACTION_DIGITS, (int) $options['decimals']);
            }

            return $fmt->formatCurrency((float) $value, $currency);
        }

        // Fallback: locale-aware numeric formatting + trailing symbol
        // (fixed placement/spacing — full currency conventions need ext-intl).
        $number = parent::format($value, $options, $column);
        $symbol = self::SYMBOLS[$currency] ?? $currency;

        return $number . ' ' . $symbol;
    }

    public function inferControlType(): ?string
    {
        return 'money';
    }
}
