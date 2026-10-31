<?php

namespace Fedale\GridviewBundle\Export;

use Twig\Markup;

/**
 * The plain text of a rendered cell, as every exporter writes it.
 *
 * A column renders a scalar, a list, or a Twig\Markup: column types (number, money,
 * percent...) and value closures wrap their HTML in one. Markup is HTML, so beyond its tags
 * its entities are decoded too: "Rossi &amp; Co" is exported as "Rossi & Co". A plain string
 * is not HTML, so only its tags are stripped, as before.
 */
final class CellText
{
    public static function of(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (\is_array($value)) {
            return implode(', ', array_map(self::of(...), $value));
        }
        if ($value instanceof Markup) {
            return trim(html_entity_decode(strip_tags((string) $value), \ENT_QUOTES | \ENT_HTML5, 'UTF-8'));
        }
        if (\is_scalar($value) || $value instanceof \Stringable) {
            return trim(strip_tags((string) $value));
        }

        return '';
    }
}
