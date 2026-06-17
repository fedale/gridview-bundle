<?php

namespace Fedale\GridviewBundle\Filter;

/**
 * Validates and normalizes the 'default' key of a column filter config.
 * The canonical output always matches what a GET form submit would produce
 * (strings / arrays of strings), so the same value can feed both the form
 * 'data' option (display) and the data provider params (query).
 */
final class FilterDefaultNormalizer
{
    private const ISO_DATE = '/^\d{4}-\d{2}-\d{2}$/';

    public static function normalize(string $type, mixed $default, array $options = []): mixed
    {
        return match ($type) {
            'text' => self::normalizeText($default),
            'boolean' => self::normalizeBoolean($default),
            'date' => self::normalizeRange($type, $default),
            'number' => self::normalizeRange($type, $default),
            'choice', 'relation' => self::normalizeChoice($type, $default, $options),
            default => throw new \InvalidArgumentException(sprintf(
                'Filter type "%s" does not support a default value.',
                $type
            )),
        };
    }

    private static function normalizeText(mixed $default): string
    {
        if (!is_scalar($default)) {
            throw new \InvalidArgumentException('Default for a "text" filter must be a scalar.');
        }

        return (string) $default;
    }

    private static function normalizeBoolean(mixed $default): string
    {
        if (in_array($default, ['1', 1, true], true)) {
            return '1';
        }
        if (in_array($default, ['0', 0, false], true)) {
            return '0';
        }

        throw new \InvalidArgumentException(
            'Default for a "boolean" filter must be one of \'1\', \'0\', 1, 0, true, false.'
        );
    }

    /**
     * @return array{from: string|null, to: string|null}
     */
    private static function normalizeRange(string $type, mixed $default): array
    {
        // Scalar shorthand: default => '2026-01-01' or default => 100 means "from"
        if (is_scalar($default)) {
            $default = ['from' => $default, 'to' => null];
        }

        if (!is_array($default)) {
            throw new \InvalidArgumentException(sprintf(
                'Default for a "%s" filter must be a scalar or an array with "from"/"to" keys.',
                $type
            ));
        }

        $unknown = array_diff(array_keys($default), ['from', 'to']);
        if ($unknown !== []) {
            throw new \InvalidArgumentException(sprintf(
                'Default for a "%s" filter only accepts "from"/"to" keys, got: %s.',
                $type,
                implode(', ', $unknown)
            ));
        }

        $normalized = [
            'from' => self::normalizeBound($type, $default['from'] ?? null),
            'to'   => self::normalizeBound($type, $default['to'] ?? null),
        ];

        if ($normalized['from'] === null && $normalized['to'] === null) {
            throw new \InvalidArgumentException(sprintf(
                'Default for a "%s" filter must define at least "from" or "to".',
                $type
            ));
        }

        return $normalized;
    }

    private static function normalizeBound(string $type, mixed $bound): ?string
    {
        if ($bound === null || $bound === '') {
            return null;
        }

        if ($type === 'date') {
            if (!is_string($bound) || !preg_match(self::ISO_DATE, $bound)) {
                throw new \InvalidArgumentException(
                    'Date filter default bounds must be ISO strings (YYYY-MM-DD).'
                );
            }

            return $bound;
        }

        if (!is_numeric($bound)) {
            throw new \InvalidArgumentException('Number filter default bounds must be numeric.');
        }

        return (string) $bound;
    }

    /**
     * @return string|array<string>
     */
    private static function normalizeChoice(string $type, mixed $default, array $options): string|array
    {
        $multiple = $options['multiple'] ?? false;

        if (is_array($default)) {
            if (!$multiple) {
                throw new \InvalidArgumentException(sprintf(
                    'Array default for a "%s" filter requires the \'multiple\' => true option.',
                    $type
                ));
            }

            $values = [];
            foreach ($default as $value) {
                if (!is_scalar($value)) {
                    throw new \InvalidArgumentException(sprintf(
                        'Default values for a "%s" filter must be scalars.',
                        $type
                    ));
                }
                if ((string) $value !== '') {
                    $values[] = (string) $value;
                }
            }

            if ($values === []) {
                throw new \InvalidArgumentException(sprintf(
                    'Array default for a "%s" filter must contain at least one non-empty value.',
                    $type
                ));
            }

            return $values;
        }

        if (!is_scalar($default) || (string) $default === '') {
            throw new \InvalidArgumentException(sprintf(
                'Default for a "%s" filter must be a non-empty scalar or an array of scalars.',
                $type
            ));
        }

        // Multiple selects expect array data even for a single default value
        return $multiple ? [(string) $default] : (string) $default;
    }
}
