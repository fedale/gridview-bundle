<?php

namespace Fedale\GridviewBundle\Column\Type;

use Fedale\GridviewBundle\Contract\ColumnInterface;
use Twig\Markup;

/**
 * Base column type. Defaults to a transparent passthrough so the historical
 * behaviour of plain `text`/`data` columns is preserved: the raw value flows
 * through unchanged and Twig auto-escapes it on output (or a per-column
 * `twigFilter` post-processes it). Subtypes override only the stage they change.
 */
abstract class AbstractColumnType implements ColumnTypeInterface
{
    public function getParent(): ?string
    {
        return null;
    }

    public function getRawValue(array $data, ColumnInterface $column): mixed
    {
        $attribute = $column->getAttribute();
        if ($attribute === null) {
            return null;
        }

        return \str_contains($attribute, '.')
            ? $this->resolvePath($data, $attribute)
            : ($data[$attribute] ?? null);
    }

    public function format(mixed $value, array $options, ColumnInterface $column): mixed
    {
        return $value;
    }

    public function render(mixed $value, array $options, ColumnInterface $column): mixed
    {
        // Returned as-is: plain strings are escaped by Twig downstream, and a
        // per-column twigFilter (e.g. date(), join()) can still consume non-string
        // values (DateTime, array). HTML-emitting subtypes return markup() instead.
        return $value;
    }

    public function inferFilterType(): ?string
    {
        return 'text';
    }

    public function inferControlType(): ?string
    {
        return 'text';
    }

    public function getDefaultOptions(): array
    {
        return [];
    }

    /** Wrap already-safe HTML so Twig does not escape it again. */
    protected function markup(string $html): Markup
    {
        return new Markup($html, 'UTF-8');
    }

    /** Escape a dynamic value for safe interpolation into HTML built by a renderer. */
    protected function esc(mixed $value): string
    {
        return htmlspecialchars((string) $value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Dot-path lookup into a nested array (`a.b.c`). */
    protected function resolvePath(array $data, string $path): mixed
    {
        $current = $data;
        $segment = \strtok($path, '.');

        while ($segment !== false) {
            if (!\is_array($current) || !isset($current[$segment])) {
                return null;
            }
            $current = $current[$segment];
            $segment = \strtok('.');
        }

        return $current;
    }
}
