<?php

namespace Fedale\GridviewBundle\Maker\Util;

/**
 * Renders a PHP array (possibly nested, possibly holding {@see RawPhp} values)
 * as formatted, indented PHP source — used to generate the `buildColumns()`
 * and `dataConfig()` array literals for the CRUD maker skeleton.
 */
final class PhpArrayPrinter
{
    /** Arrays shorter than this, once flattened, are kept on a single line. */
    private const INLINE_LENGTH_LIMIT = 100;

    public static function export(array $array, int $indentLevel = 0): string
    {
        if ($array === []) {
            return '[]';
        }

        $inline = self::tryInline($array);
        if ($inline !== null) {
            return $inline;
        }

        $pad = str_repeat('    ', $indentLevel);
        $inner = str_repeat('    ', $indentLevel + 1);
        $isList = array_is_list($array);

        $lines = [];
        foreach ($array as $key => $value) {
            $keyPart = $isList ? '' : self::exportScalar($key) . ' => ';
            $lines[] = $inner . $keyPart . self::exportValue($value, $indentLevel + 1) . ',';
        }

        return "[\n" . implode("\n", $lines) . "\n{$pad}]";
    }

    /** A short, purely scalar/array/RawPhp-leaf array is rendered on one line; returns null when it doesn't fit. */
    private static function tryInline(array $array): ?string
    {
        $isList = array_is_list($array);
        $parts = [];

        foreach ($array as $key => $value) {
            if (\is_array($value)) {
                $rendered = self::tryInline($value);
                if ($rendered === null) {
                    return null;
                }
            } else {
                $rendered = self::exportValue($value, 0);
            }
            $parts[] = ($isList ? '' : self::exportScalar($key) . ' => ') . $rendered;
        }

        $inline = '[' . implode(', ', $parts) . ']';

        return \strlen($inline) <= self::INLINE_LENGTH_LIMIT ? $inline : null;
    }

    private static function exportValue(mixed $value, int $indentLevel): string
    {
        return match (true) {
            $value instanceof RawPhp => $value->code,
            \is_array($value) => self::export($value, $indentLevel),
            default => self::exportScalar($value),
        };
    }

    private static function exportScalar(mixed $value): string
    {
        return match (true) {
            \is_string($value) => "'" . addcslashes($value, "'\\") . "'",
            \is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            default => (string) $value,
        };
    }
}
