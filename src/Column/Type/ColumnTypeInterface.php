<?php

namespace Fedale\GridviewBundle\Column\Type;

use Fedale\GridviewBundle\Contract\ColumnInterface;

/**
 * A column data type. Types form an inheritance tree (a type "extends" another
 * and overrides only what differs, e.g. CurrencyType extends NumberType) and
 * implement the three-stage render pipeline:
 *
 *     getRawValue(row)  →  format(value, opts)  →  render(value, opts)
 *
 * Per-column closures (valueGetter/formatter/renderer on the column) win over the
 * type's defaults; a column's legacy `value` short-circuits the whole pipeline.
 *
 * Any service implementing this interface is auto-collected into the
 * {@see ColumnTypeRegistry}, so a host app adds its own types with zero config.
 */
interface ColumnTypeInterface
{
    /** Public name used in a column spec `type => ...`. */
    public function getName(): string;

    /** Name of the parent type (for introspection/docs), or null for a root type. */
    public function getParent(): ?string;

    /** Stage 1 — extract the raw value from the row data. */
    public function getRawValue(array $data, ColumnInterface $column): mixed;

    /** Stage 2 — transform the raw value into a display value. */
    public function format(mixed $value, array $options, ColumnInterface $column): mixed;

    /**
     * Stage 3 — produce the final cell output. Return a plain string/scalar to let
     * Twig auto-escape it, or a {@see \Twig\Markup} for already-safe HTML.
     */
    public function render(mixed $value, array $options, ColumnInterface $column): mixed;

    /** Default filter type to inherit (one of the FilterApplier types), or null when not filterable. */
    public function inferFilterType(): ?string;

    /** Default write-side control type to inherit, or null when none. */
    public function inferControlType(): ?string;

    /** Default option values merged under the column's `format` options. */
    public function getDefaultOptions(): array;
}
