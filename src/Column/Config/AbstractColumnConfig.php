<?php

namespace Fedale\GridviewBundle\Column\Config;

use Fedale\GridviewBundle\Column\Type\ColumnType;
use Fedale\GridviewBundle\Filter\FilterType;
use Fedale\GridviewBundle\Form\Control\ControlType;

/**
 * Base for the fluent column builders. Holds the pending spec and every
 * cross-cutting fluent method (all `return static`), so subclasses only add the
 * type-specific sugar. The builder never touches a runtime column object — it
 * just assembles the associative spec that
 * {@see \Fedale\GridviewBundle\Column\ColumnFactory} turns into one.
 *
 * The constructor is non-public on purpose: columns are always created through
 * the per-type `::new()` factory (mirroring EasyAdmin's `Field::new()`).
 *
 * @phpstan-consistent-constructor
 */
abstract class AbstractColumnConfig implements ColumnConfigInterface
{
    /** @var array<string, mixed> */
    protected array $spec = [];

    protected function __construct()
    {
    }

    /** The column type this builder maps to (data type or structural). */
    abstract protected static function columnType(): ColumnType;

    public static function new(?string $attribute = null): static
    {
        $config = new static();
        $config->spec['type'] = static::columnType()->value;
        if (null !== $attribute) {
            $config->spec['attribute'] = $attribute;
        }

        return $config;
    }

    public function toArray(): array
    {
        return $this->spec;
    }

    public function label(string|bool $label): static
    {
        $this->spec['label'] = $label;

        return $this;
    }

    public function sortable(bool $sortable = true): static
    {
        $this->spec['sortable'] = $sortable;

        return $this;
    }

    public function notSortable(): static
    {
        return $this->sortable(false);
    }

    public function visible(bool|\Closure $visible): static
    {
        $this->spec['visible'] = $visible;

        return $this;
    }

    /**
     * @param bool|array<string, bool>|\Closure $active
     */
    public function active(bool|array|\Closure $active): static
    {
        $this->spec['active'] = $active;

        return $this;
    }

    /**
     * Show the column only in the grid table (not the detail view or CRUD form).
     */
    public function onlyOnIndex(): static
    {
        return $this->active(['inIndex' => true, 'inShow' => false, 'inCreate' => false, 'inUpdate' => false]);
    }

    /**
     * Show the column only in the detail (show) view.
     */
    public function onlyOnShow(): static
    {
        return $this->active(['inIndex' => false, 'inShow' => true, 'inCreate' => false, 'inUpdate' => false]);
    }

    /**
     * Show the column only in the CRUD forms — both create and update (edit).
     */
    public function onlyOnForm(): static
    {
        return $this->active(['inIndex' => false, 'inShow' => false, 'inCreate' => true, 'inUpdate' => true]);
    }

    /**
     * Show the column only in the create form.
     */
    public function onlyOnCreate(): static
    {
        return $this->active(['inIndex' => false, 'inShow' => false, 'inCreate' => true, 'inUpdate' => false]);
    }

    /**
     * Show the column only in the update (edit) form.
     */
    public function onlyOnUpdate(): static
    {
        return $this->active(['inIndex' => false, 'inShow' => false, 'inCreate' => false, 'inUpdate' => true]);
    }

    /**
     * Hide the column from the grid table, keeping it everywhere else.
     */
    public function hideOnIndex(): static
    {
        return $this->active(['inIndex' => false]);
    }

    /**
     * Hide the column from the detail (show) view, keeping it everywhere else.
     */
    public function hideOnShow(): static
    {
        return $this->active(['inShow' => false]);
    }

    /**
     * Hide the column from both CRUD forms (create and update).
     */
    public function hideOnForm(): static
    {
        return $this->active(['inCreate' => false, 'inUpdate' => false]);
    }

    /**
     * Hide the column from the create form only.
     */
    public function hideOnCreate(): static
    {
        return $this->active(['inCreate' => false]);
    }

    /**
     * Hide the column from the update (edit) form only.
     */
    public function hideOnUpdate(): static
    {
        return $this->active(['inUpdate' => false]);
    }

    public function priority(int $priority): static
    {
        $this->spec['priority'] = $priority;

        return $this;
    }

    public function exportable(bool $exportable = true): static
    {
        $this->spec['exportable'] = $exportable;

        return $this;
    }

    public function batchUpdate(bool $batchUpdate = true): static
    {
        $this->spec['batchUpdate'] = $batchUpdate;

        return $this;
    }

    /**
     * @param bool|array<string, mixed> $showInDeleteConfirm
     */
    public function showInDeleteConfirm(bool|array $showInDeleteConfirm = true): static
    {
        $this->spec['showInDeleteConfirm'] = $showInDeleteConfirm;

        return $this;
    }

    /**
     * @param bool|array{trigger: string} $editable
     */
    public function editable(bool|array $editable = true): static
    {
        $this->spec['editable'] = $editable;

        return $this;
    }

    /**
     * @param string|array<string, mixed>|\Closure $footer
     */
    public function footer(string|array|\Closure $footer): static
    {
        $this->spec['footer'] = $footer;

        return $this;
    }

    /**
     * Legacy full-cell override: a closure `fn(array $data): mixed` (or an
     * attribute name) that short-circuits the type pipeline.
     */
    public function value(callable|string $value): static
    {
        $this->spec['value'] = $value;

        return $this;
    }

    public function valueGetter(callable $valueGetter): static
    {
        $this->spec['valueGetter'] = $valueGetter;

        return $this;
    }

    public function formatter(callable $formatter): static
    {
        $this->spec['formatter'] = $formatter;

        return $this;
    }

    public function renderer(callable $renderer): static
    {
        $this->spec['renderer'] = $renderer;

        return $this;
    }

    /**
     * Merge options passed to the column type's format pipeline.
     *
     * @param array<string, mixed> $format
     */
    public function format(array $format): static
    {
        $this->spec['format'] = [...($this->spec['format'] ?? []), ...$format];

        return $this;
    }

    /**
     * @param bool|string|array<string, mixed>|FilterType $filter
     */
    public function filter(bool|string|array|FilterType $filter): static
    {
        $this->spec['filter'] = $filter instanceof FilterType ? $filter->value : $filter;

        return $this;
    }

    public function filterText(bool $trim = true): static
    {
        if (false === $trim) {
            return $this->filter(['type' => FilterType::Text->value, 'options' => ['trim' => false]]);
        }

        return $this->filter(FilterType::Text);
    }

    public function filterNumber(): static
    {
        return $this->filter(FilterType::Number);
    }

    public function filterBoolean(): static
    {
        return $this->filter(FilterType::Boolean);
    }

    public function filterDate(): static
    {
        return $this->filter(FilterType::Date);
    }

    public function filterChoice(): static
    {
        return $this->filter(FilterType::Choice);
    }

    public function filterRelation(): static
    {
        return $this->filter(FilterType::Relation);
    }

    /**
     * @param bool|string|array<string, mixed>|ControlType $control
     */
    public function control(bool|string|array|ControlType $control): static
    {
        $this->spec['control'] = $control instanceof ControlType ? $control->value : $control;

        return $this;
    }

    /**
     * Mark the write-side control required. Creates the control (inheriting the
     * column's data type) when none was declared yet.
     */
    public function required(bool $required = true): static
    {
        $control = $this->controlArray();
        $control['required'] = $required;
        $this->spec['control'] = $control;

        return $this;
    }

    /**
     * Set one option on the write-side control, creating the control (inheriting
     * the column's data type) when none was declared yet.
     */
    protected function controlOption(string $key, mixed $value): static
    {
        $control = $this->controlArray();
        $control['options'] = [...($control['options'] ?? []), $key => $value];
        $this->spec['control'] = $control;

        return $this;
    }

    /** Force the control to a given type, preserving already-set control keys. */
    protected function controlType(ControlType|string $type): static
    {
        $control = $this->controlArray();
        $control['type'] = $type instanceof ControlType ? $type->value : $type;
        $this->spec['control'] = $control;

        return $this;
    }

    /**
     * Normalize whatever is in `control` into an editable array so sugar methods
     * can merge into it. A bare `control => true`/string is widened accordingly.
     *
     * @return array<string, mixed>
     */
    private function controlArray(): array
    {
        $control = $this->spec['control'] ?? null;

        if (\is_array($control)) {
            return $control;
        }
        if (\is_string($control)) {
            return ['type' => $control];
        }
        if ($control instanceof ControlType) {
            return ['type' => $control->value];
        }

        return [];
    }
}
