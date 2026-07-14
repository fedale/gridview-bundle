<?php

namespace Fedale\GridviewBundle\Maker\Util;

/**
 * Renders column {@see \Fedale\GridviewBundle\Maker\DoctrineTypeMapper column
 * plans} as fluent builder chains — the `--fluent` counterpart of
 * {@see \Fedale\GridviewBundle\Maker\DoctrineTypeMapper::columnsArrayFor()} +
 * {@see PhpArrayPrinter}. Returns both the PHP array-of-chains source and the
 * set of builder FQCNs the generated controller must import.
 *
 * @phpstan-type ColumnPlan array{
 *     attribute: string,
 *     label: string,
 *     type: string|null,
 *     sortable: bool,
 *     filter: array<string, mixed>|null,
 *     control: array<string, mixed>|null,
 *     value: RawPhp|null,
 * }
 */
final class FluentColumnPrinter
{
    private const CONFIG_NAMESPACE = 'Fedale\\GridviewBundle\\Column\\Config';

    /** Gridview column type => builder short class name. */
    private const BUILDER_BY_TYPE = [
        'text' => 'TextColumn',
        'uuid' => 'UuidColumn',
        'html' => 'HtmlColumn',
        'richText' => 'RichTextColumn',
        'json' => 'JsonColumn',
        'link' => 'LinkColumn',
        'url' => 'UrlColumn',
        'email' => 'EmailColumn',
        'media' => 'MediaColumn',
        'number' => 'NumberColumn',
        'money' => 'MoneyColumn',
        'currency' => 'CurrencyColumn',
        'percent' => 'PercentColumn',
        'boolean' => 'BooleanColumn',
        'date' => 'DateColumn',
        'datetime' => 'DatetimeColumn',
        'time' => 'TimeColumn',
        'select' => 'SelectColumn',
        'multiSelect' => 'MultiSelectColumn',
        'rating' => 'RatingColumn',
        'badge' => 'BadgeColumn',
        'list' => 'ListColumn',
        'relation' => 'RelationColumn',
        'color' => 'ColorColumn',
        'country' => 'CountryColumn',
        'language' => 'LanguageColumn',
        'locale' => 'LocaleColumn',
        'timezone' => 'TimezoneColumn',
        'tel' => 'TelColumn',
        'hidden' => 'HiddenColumn',
    ];

    /** Simple filter type => shared sugar method (only for `['type' => X]` with no extra keys). */
    private const FILTER_SUGAR = [
        'text' => 'filterText',
        'number' => 'filterNumber',
        'boolean' => 'filterBoolean',
        'date' => 'filterDate',
        'choice' => 'filterChoice',
    ];

    /** Column type => the control type it inherits by default (so a matching control needs no explicit type). */
    private const DEFAULT_CONTROL_BY_TYPE = [
        'text' => 'text',
        'uuid' => 'text',
        'number' => 'number',
        'boolean' => 'boolean',
        'date' => 'date',
        'datetime' => 'datetime',
    ];

    /**
     * @param list<ColumnPlan> $plans
     * @param int              $indentLevel indent level of the enclosing `return [...]` bracket
     *
     * @return array{code: string, imports: list<string>}
     */
    public function print(array $plans, bool $withCheckbox, int $indentLevel = 2): array
    {
        $used = [];
        $lines = [];

        if ($withCheckbox) {
            $used['CheckboxColumn'] = true;
            $lines[] = 'CheckboxColumn::new(),';
        }

        foreach ($plans as $plan) {
            $lines[] = $this->chainFor($plan, $used) . ',';
        }

        $used['ActionColumn'] = true;
        $lines[] = 'ActionColumn::new()->label(false),';

        $pad = str_repeat('    ', $indentLevel);
        $inner = str_repeat('    ', $indentLevel + 1);
        $code = "[\n" . implode("\n", array_map(static fn (string $l): string => $inner . $l, $lines)) . "\n{$pad}]";

        $imports = array_map(static fn (string $short): string => self::CONFIG_NAMESPACE . '\\' . $short, array_keys($used));
        sort($imports);

        return ['code' => $code, 'imports' => $imports];
    }

    /**
     * @param ColumnPlan          $plan
     * @param array<string, true> $used builder short names collected for the import list
     */
    private function chainFor(array $plan, array &$used): string
    {
        $control = $plan['control'];
        $isRelation = \is_array($control) && ($control['type'] ?? null) === 'relation';

        if ($isRelation) {
            $used['RelationColumn'] = true;

            return $this->relationChain($plan, $control);
        }

        $type = $plan['type'];
        $short = $type !== null ? (self::BUILDER_BY_TYPE[$type] ?? 'TextColumn') : 'TextColumn';
        $used[$short] = true;

        $chain = \sprintf('%s::new(%s)->label(%s)', $short, $this->phpString($plan['attribute']), $this->phpString($plan['label']));

        if ($plan['sortable']) {
            $chain .= '->sortable()';
        }

        // Enum select: enum() folds the choice filter and the enum control together.
        if ($type === 'select' && \is_array($control) && ($control['type'] ?? null) === 'enum') {
            $chain .= $this->enumCall($control);

            return $chain;
        }

        $chain .= $this->filterCall($plan['filter']);
        $chain .= $this->controlCall($type, $control);
        $chain .= $this->valueCall($plan['value']);

        return $chain;
    }

    /**
     * @param array{attribute: string, label: string, sortable: bool}                       $plan
     * @param array{options?: array{class?: mixed, choice_label?: string}, required?: bool} $control
     */
    private function relationChain(array $plan, array $control): string
    {
        $chain = \sprintf('RelationColumn::new(%s)->label(%s)', $this->phpString($plan['attribute']), $this->phpString($plan['label']));
        if ($plan['sortable']) {
            $chain .= '->sortable()';
        }

        $class = $control['options']['class'] ?? null;
        $classCode = $class instanceof RawPhp ? $class->code : $this->classConst((string) $class);

        $args = [$classCode];
        if (isset($control['options']['choice_label'])) {
            $args[] = 'choiceLabel: ' . $this->phpString($control['options']['choice_label']);
        }
        if (($control['required'] ?? false) === true) {
            $args[] = 'required: true';
        }

        return $chain . '->relation(' . implode(', ', $args) . ')';
    }

    /** @param array{options?: array{class?: mixed}, required?: bool} $control */
    private function enumCall(array $control): string
    {
        $class = $control['options']['class'] ?? null;
        $classCode = $class instanceof RawPhp ? $class->code : $this->classConst((string) $class);

        $args = [$classCode];
        if (($control['required'] ?? false) === true) {
            $args[] = 'required: true';
        }

        return '->enum(' . implode(', ', $args) . ')';
    }

    /** @param array<string, mixed>|null $filter */
    private function filterCall(?array $filter): string
    {
        if ($filter === null) {
            return '';
        }

        // Simple `['type' => X]` maps to the matching sugar method.
        if (\count($filter) === 1 && isset($filter['type']) && isset(self::FILTER_SUGAR[$filter['type']])) {
            return '->' . self::FILTER_SUGAR[$filter['type']] . '()';
        }

        return '->filter(' . PhpArrayPrinter::export($filter) . ')';
    }

    /** @param array<string, mixed>|null $control */
    private function controlCall(?string $columnType, ?array $control): string
    {
        if ($control === null) {
            return '';
        }

        $type = $control['type'] ?? null;
        $required = ($control['required'] ?? false) === true;
        $extraKeys = array_diff(array_keys($control), ['type', 'required']);

        // A control whose type matches the column's default and carries no extra
        // options collapses to required()/control(true).
        if ($extraKeys === [] && $type !== null && ($columnType !== null) && ($type === (self::DEFAULT_CONTROL_BY_TYPE[$columnType] ?? null))) {
            return $required ? '->required()' : '->control(true)';
        }

        return '->control(' . PhpArrayPrinter::export($control) . ')';
    }

    private function valueCall(?RawPhp $value): string
    {
        if ($value === null) {
            return '';
        }

        return '->value(' . $value->code . ')';
    }

    /** Turn a plain FQCN string into a `\Fully\Qualified::class` constant expression. */
    private function classConst(string $fqcn): string
    {
        return '\\' . ltrim($fqcn, '\\') . '::class';
    }

    private function phpString(string $value): string
    {
        return "'" . addcslashes($value, "'\\") . "'";
    }
}
