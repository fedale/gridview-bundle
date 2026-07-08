<?php 
namespace Fedale\GridviewBundle\Column;

use Fedale\GridviewBundle\Column\Type\ColumnTypeInterface;
use Fedale\GridviewBundle\Filter\FilterClearNormalizer;
use Fedale\GridviewBundle\Grid\Gridview;
use Twig\Markup;
use \Closure;

class DataColumn extends AbstractColumn
{
    /** Resolved data type driving the render pipeline (set by the ColumnFactory). */
    private ?ColumnTypeInterface $columnType = null;

    /** Stage-1 override: raw value extractor `($data, $index, $column)`. */
    private ?Closure $valueGetter = null;

    /** Stage-2 override: display formatter `($rawValue, $data, $column)`. */
    private ?Closure $formatter = null;

    /** Stage-3 override: HTML renderer `($displayValue, $data, $column)`. */
    private ?Closure $renderer = null;

    /** Per-column options passed to the data type's pipeline stages. */
    private array $format = [];

     /**
     * @var string|Closure|null an anonymous function or a string that is used to determine the value to display in the current column.
     *
     * If this is an anonymous function, it will be called for each row and the return value will be used as the value to
     * display for every data model. The signature of this function should be: `function ($data, $index, $column)`.
     * Where `$data` and `$index` refer to the model, key and index of the row currently being rendered
     * and `$column` is a reference to the [[DataColumn]] object.
     *
     * You may also set this property to a string representing the attribute name to be displayed in this column.
     * This can be used when the attribute to be displayed is different from the [[attribute]] that is used for
     * sorting and filtering.
     *
     * If this is not set, `$data[$attribute]` will be used to obtain the value, where `$attribute` is the value of [[attribute]].
     */
    public $value;

    public $filter;

    /**
     * Semantic data type of the column (text, boolean, date, number, ...).
     * Drives value rendering (e.g. boolean → ✓/✗) and the default filter type.
     */
    public ?string $dataType = null;

    /**
     * Tri-state filter-bar membership:
     *   - true  → always in the filter bar (explicit opt-in);
     *   - false → never in the filter bar (explicit exclusion, wins over autoBar);
     *   - null  → unset: in the bar only when `autoBar` is active (default on the
     *             list/card renderers) and the column is filterable.
     * {@see Gridview::getFilterBarColumns()} resolves the effective membership.
     */
    public ?bool $filterBar = null;

    /**
     * When the filter is shown in the filterBar, also render a "mirror" input in
     * the column header (text/number filters only). Opt-in: by default a filterBar
     * filter lives ONLY in the filterBar.
     */
    public bool $headerMirror = false;


    public function __construct (
        private Gridview $gridview,
        private string $attribute,
        protected ?string $twigFilter = null,
        protected ?string $label = null,
        protected ?array $options = [],
    ) { 
        if (null === $this->label) {
            $this->setLabel($attribute);
        }
    }

    public function getAttribute(): string
    {
        return $this->attribute;
    }

    public function render(mixed $model, int $index): mixed
    {
        $data = $model->data;

        // Legacy `value` short-circuit: full-cell override, behaviour unchanged.
        if ($this->value !== null) {
            return \is_string($this->value)
                ? $this->value
                : ($this->value)($data, $index, $this);
        }

        $type    = $this->columnType;
        $options = $type !== null
            ? \array_merge($type->getDefaultOptions(), $this->format)
            : $this->format;

        // Stage 1 — raw value
        $raw = $this->valueGetter !== null
            ? ($this->valueGetter)($data, $index, $this)
            : ($type !== null ? $type->getRawValue($data, $this) : $this->defaultRawValue($data));

        // Back-compat: a `twigFilter` is the historical formatter and operated on
        // the raw value (render() used to return it as-is). When present, the
        // type's format/render stages step aside so the twigFilter still receives
        // the raw value — unless an explicit formatter/renderer overrides them.
        if ($this->twigFilter !== null && $this->formatter === null && $this->renderer === null) {
            return $raw;
        }

        // Stage 2 — display value
        $display = $this->formatter !== null
            ? ($this->formatter)($raw, $data, $this)
            : ($type !== null ? $type->format($raw, $options, $this) : $raw);

        // Stage 3 — cell output (string escaped by Twig, or Twig\Markup passed through)
        return $this->renderer !== null
            ? ($this->renderer)($display, $data, $this)
            : ($type !== null ? $type->render($display, $options, $this) : $display);
    }

    private function defaultRawValue(array $data): mixed
    {
        return \str_contains($this->attribute, '.')
            ? $this->resolve($data, $this->attribute)
            : ($data[$this->attribute] ?? null);
    }

    public function setColumnType(ColumnTypeInterface $columnType): void
    {
        $this->columnType = $columnType;
    }

    public function getColumnType(): ?ColumnTypeInterface
    {
        return $this->columnType;
    }

    public function setValueGetter(Closure $valueGetter): void
    {
        $this->valueGetter = $valueGetter;
    }

    public function setFormatter(Closure $formatter): void
    {
        $this->formatter = $formatter;
    }

    public function setRenderer(Closure $renderer): void
    {
        $this->renderer = $renderer;
    }

    public function setFormat(array $format): void
    {
        $this->format = $format;
    }

    public function getFormat(): array
    {
        return $this->format;
    }

    public function renderHeader($label): string
    {
        if ($this->sortable) {
            $sort = $this->gridview->getDataProvider()->getSort();

            $sortAttribute = $sort->hasAttribute($this->label) ? $label : ($sort->hasAttribute($this->attribute)
                ? $this->attribute : null);

            if ($sortAttribute) {
                return $sort->createLink($sortAttribute, $this->gridview, ['label' => $label]);
            }
        }

        return $label;
    }

    public function sortState(): ?string
    {
        $attribute = $this->resolveSortAttribute();

        if ($attribute === null) {
            return null;
        }

        return $this->gridview->getDataProvider()->getSort()->getAttributeOrder($attribute) ?? 'none';
    }

    /** Toggle-sort URL for this column, or null when not managed by Sort. */
    public function sortUrl(): ?string
    {
        $attribute = $this->resolveSortAttribute();

        if ($attribute === null) {
            return null;
        }

        return $this->gridview->getDataProvider()->getSort()->createUrl($attribute, $this->gridview);
    }

    /** The Sort attribute key driving this column, or null if not sortable. */
    private function resolveSortAttribute(): ?string
    {
        if (!$this->sortable) {
            return null;
        }

        $sort = $this->gridview->getDataProvider()->getSort();

        return $sort->hasAttribute($this->label) ? $this->label
            : ($sort->hasAttribute($this->attribute) ? $this->attribute : null);
    }

    // https://stackoverflow.com/questions/14704984/access-a-multidimensional-array-with-dot-notation
    private function resolve(array $a, $path, $default = null)
    {
      $current = $a;
      $p = strtok($path, '.');
    
      while ($p !== false) {
        if (!isset($current[$p])) {
          return $default;
        }
        $current = $current[$p];
        $p = strtok('.');
      }
    
      return $current;
    }

    /**
     * Whether the column has explicitly opted into the filter bar. Note this is
     * the explicit opt-in only (used by the table header to decide bar-vs-header
     * placement); the effective bar membership under `autoBar` is computed in
     * {@see Gridview::getFilterBarColumns()}.
     */
    public function isInFilterBar(): bool
    {
        return $this->filterBar === true;
    }

    public function setFilterBar(?bool $filterBar): void
    {
        $this->filterBar = $filterBar;
    }

    public function hasHeaderMirror(): bool
    {
        return $this->headerMirror;
    }

    public function setHeaderMirror(bool $headerMirror): void
    {
        $this->headerMirror = $headerMirror;
    }

    public function getDataType(): ?string
    {
        return $this->dataType;
    }

    public function setDataType(?string $dataType): void
    {
        $this->dataType = $dataType;
    }

    public function setFilter($filter): void
    {
        $this->filter = $filter;
    }

    public function getFilter(): mixed
    {
        return $this->filter;
    }

    /**
     * Canonical per-column filter-clear config, resolving the `filter.clear`
     * spec against the grid-level defaults (filterControls.clear or .inlineClear).
     * Used by the header/filter/chip templates to decide which clear affordances
     * to render.
     *
     * Resolution order:
     *   1. filter.clear (explicit per-column spec) if present — always wins
     *   2. filterControls.clear (grid-level default) if set
     *   3. Fallback: ['header'] + 'input' iff filterControls.inlineClear is true
     *
     * @return array{mode: string[], icon: ?string, chipIcon: ?string}
     */
    public function getFilterClear(): array
    {
        $clear = \is_array($this->filter) ? ($this->filter['clear'] ?? null) : null;

        // Explicit per-column spec always wins.
        if ($clear !== null) {
            return FilterClearNormalizer::normalize($clear);
        }

        // Grid-level filterControls.clear is the next source.
        $gridClear = $this->gridview->getOptions()['behavior']['filterControls']['clear'] ?? null;
        if ($gridClear !== null) {
            return FilterClearNormalizer::normalize($gridClear);
        }

        // Fallback to retro-compatible logic: ['header'] + 'input' iff inlineClear.
        $inlineClearDefault = (bool) ($this->gridview->getOptions()['behavior']['filterControls']['inlineClear'] ?? false);
        return FilterClearNormalizer::normalize(null, $inlineClearDefault);
    }

    public function getOptions(): array
    {
        return $this->options ?? [];
    }

    /**
     * Configure the footer summary. Accepts, in addition to the base literal
     * forms (a label string or a closure):
     *   - an aggregate name ('sum', 'avg', 'min', 'max', 'count', 'countDistinct')
     *     or a {@see FooterAggregate};
     *   - an array with `agg` (aggregate) or `expr` (raw DQL, e.g.
     *     'SUM(e.qty * e.price)'), plus `scope` ('dataset' default, or 'page'),
     *     `format` (overrides merged over the column format), and `label`.
     * A bare string that is not a known aggregate name is treated as a literal.
     *
     * @param string|array<string, mixed>|\Closure|FooterAggregate $footer
     */
    public function setFooter($footer): void
    {
        if ($footer instanceof Closure) {
            $this->footer = ['callable' => $footer];

            return;
        }

        if ($footer instanceof FooterAggregate) {
            $this->footer = ['agg' => $footer, 'scope' => 'dataset'];

            return;
        }

        if (\is_string($footer)) {
            $agg = FooterAggregate::tryFrom($footer);
            $this->footer = $agg !== null
                ? ['agg' => $agg, 'scope' => 'dataset']
                : ['text' => $footer];

            return;
        }

        $spec = $footer;

        if (isset($spec['callable']) && $spec['callable'] instanceof Closure) {
            $this->footer = ['callable' => $spec['callable']];

            return;
        }

        if (\array_key_exists('agg', $spec)) {
            $spec['agg'] = $spec['agg'] instanceof FooterAggregate
                ? $spec['agg']
                : FooterAggregate::from($spec['agg']);
        }

        // Aggregates and raw expressions default to the dataset scope; a plain
        // label array is scope-agnostic.
        $spec['scope'] ??= (isset($spec['agg']) || isset($spec['expr'])) ? 'dataset' : 'page';

        $this->footer = $spec;
    }

    /**
     * The DQL aggregate expression to batch into the provider's dataset query, or
     * null when this footer can't be computed that way (literal/custom footer,
     * page scope, or an aggregate over a non-scalar/relation attribute — those
     * fall back to page-scope aggregation). A raw `expr` is passed through as-is.
     */
    public function footerDatasetExpression(string $rootAlias): ?string
    {
        $spec = $this->footer;
        if ($spec === null || isset($spec['callable']) || isset($spec['text'])) {
            return null;
        }

        if (($spec['scope'] ?? 'dataset') !== 'dataset') {
            return null;
        }

        if (isset($spec['expr'])) {
            return $spec['expr'];
        }

        if (!isset($spec['agg'])) {
            return null;
        }

        // Only a plain scalar attribute on the query root can be qualified into a
        // valid aggregate here; dotted relation paths need a join we don't build.
        $attribute = $this->attribute;
        if ($attribute === '' || \str_contains($attribute, '.')) {
            return null;
        }

        return $spec['agg']->toDql($rootAlias . '.' . $attribute);
    }

    /**
     * Render the footer summary cell. $datasetValue is the pre-computed dataset
     * aggregate (from the provider's batched query) when available, else null —
     * in which case the value is reduced from the current page's rows.
     *
     * @param iterable<\Fedale\GridviewBundle\Row\Row> $rows
     */
    public function renderFooter(mixed $datasetValue, iterable $rows): mixed
    {
        $spec = $this->footer;
        if ($spec === null) {
            return '';
        }

        if (isset($spec['callable']) && \is_callable($spec['callable'])) {
            return ($spec['callable'])($datasetValue, $rows, $this);
        }

        if (isset($spec['text'])) {
            return $spec['text'];
        }

        $agg = $spec['agg'] ?? null;

        $raw = $datasetValue;
        if ($raw === null) {
            // Page scope, or a dataset aggregate that couldn't be batched (relation
            // path / non-aggregatable provider): reduce over the current page.
            $raw = $agg?->reduce($this->footerPageValues($rows));
        }

        if ($raw === null) {
            return '';
        }

        // A count is a plain integer, not a domain value — never money/percent.
        if ($agg !== null && $agg->isCount()) {
            return new Markup('<span class="gv-num">' . (int) $raw . '</span>', 'UTF-8');
        }

        return $this->formatFooterValue($raw, $spec);
    }

    /**
     * Numeric raw values of this column across the given page rows, for page-scope
     * aggregation. Mirrors the render pipeline's stage-1 extraction and keeps only
     * numeric values.
     *
     * @param iterable<\Fedale\GridviewBundle\Row\Row> $rows
     *
     * @return array<int, int|float>
     */
    private function footerPageValues(iterable $rows): array
    {
        $values = [];
        foreach ($rows as $row) {
            $data = $row->data;

            $value = $this->valueGetter !== null
                ? ($this->valueGetter)($data, 0, $this)
                : ($this->columnType !== null
                    ? $this->columnType->getRawValue($data, $this)
                    : $this->defaultRawValue($data));

            if (\is_numeric($value)) {
                $values[] = $value + 0;
            }
        }

        return $values;
    }

    /**
     * Format an aggregated numeric value through the column type (so a currency
     * total looks like the body cells), honoring an optional `format` override in
     * the footer spec.
     *
     * @param array<string, mixed> $spec
     */
    private function formatFooterValue(int|float $raw, array $spec): mixed
    {
        $type = $this->columnType;
        if ($type === null) {
            return (string) $raw;
        }

        $options = \array_merge($type->getDefaultOptions(), $this->format, $spec['format'] ?? []);
        $display = $type->format($raw, $options, $this);

        return $type->render($display, $options, $this);
    }
}