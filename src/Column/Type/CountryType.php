<?php

namespace Fedale\GridviewBundle\Column\Type;

use Fedale\GridviewBundle\Contract\ColumnInterface;

/**
 * ISO 3166-1 alpha-2 country code (IT, US, FR, …) — renders the localized
 * country name (via the optional `symfony/intl` component) prefixed with a flag
 * emoji built from the code itself (no dependency: each letter maps to a
 * Unicode regional-indicator symbol). Falls back to the bare code, still with
 * its flag, when `symfony/intl` is not installed. Mirrors EasyAdmin's
 * `CountryField` / Symfony's `CountryType` control.
 */
class CountryType extends AbstractColumnType
{
    public function getName(): string
    {
        return 'country';
    }

    public function getDefaultOptions(): array
    {
        return ['showFlag' => true, 'showName' => true];
    }

    public function format(mixed $value, array $options, ColumnInterface $column): mixed
    {
        if ($value === null || $value === '') {
            return '';
        }

        $code = \strtoupper((string) $value);
        $name = $code;

        if ($options['showName'] ?? true) {
            if ($this->intlAvailable()) {
                try {
                    $locale = (string) ($options['locale'] ?? \Locale::getDefault());
                    $name   = \Symfony\Component\Intl\Countries::getName($code, $locale);
                } catch (\Exception) {
                    // Unknown code: keep the bare code as the name.
                }
            }
        }

        $flag = ($options['showFlag'] ?? true) ? $this->flagEmoji($code) : null;

        return $flag !== null ? $flag . ' ' . $name : $name;
    }

    /** Overridable so tests can force the no-symfony/intl fallback branch deterministically. */
    protected function intlAvailable(): bool
    {
        return \class_exists(\Symfony\Component\Intl\Countries::class);
    }

    /** Builds a flag emoji from a 2-letter ISO code via Unicode regional-indicator symbols. */
    private function flagEmoji(string $code): ?string
    {
        if (!\preg_match('/^[A-Z]{2}$/', $code)) {
            return null;
        }

        $flag = '';
        for ($i = 0; $i < 2; $i++) {
            $flag .= \mb_chr(0x1F1E6 + (\ord($code[$i]) - 65), 'UTF-8');
        }

        return $flag;
    }

    public function inferFilterType(): ?string
    {
        return 'text';
    }

    public function inferControlType(): ?string
    {
        return 'country';
    }
}
