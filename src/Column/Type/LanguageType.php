<?php

namespace Fedale\GridviewBundle\Column\Type;

use Fedale\GridviewBundle\Contract\ColumnInterface;

/**
 * ISO 639-1 language code (en, it, fr, …) — renders the localized language name
 * via the optional `symfony/intl` component, falling back to the bare code when
 * it is not installed. Mirrors EasyAdmin's `LanguageField` / Symfony's
 * `LanguageType` control.
 */
class LanguageType extends AbstractColumnType
{
    public function getName(): string
    {
        return 'language';
    }

    public function getDefaultOptions(): array
    {
        return ['showName' => true];
    }

    public function format(mixed $value, array $options, ColumnInterface $column): mixed
    {
        if ($value === null || $value === '') {
            return '';
        }

        $code = (string) $value;

        if (($options['showName'] ?? true) && $this->intlAvailable()) {
            $locale = (string) ($options['locale'] ?? \Locale::getDefault());

            try {
                return \Symfony\Component\Intl\Languages::getName($code, $locale);
            } catch (\Exception) {
                // Unknown code: fall through to the bare code below.
            }
        }

        return $code;
    }

    /** Overridable so tests can force the no-symfony/intl fallback branch deterministically. */
    protected function intlAvailable(): bool
    {
        return \class_exists(\Symfony\Component\Intl\Languages::class);
    }

    public function inferFilterType(): ?string
    {
        return 'text';
    }

    public function inferControlType(): ?string
    {
        return 'language';
    }
}
