<?php 
namespace Fedale\GridviewBundle\Column;

use Fedale\GridviewBundle\Column\Type\ColumnTypeInterface;
use Fedale\GridviewBundle\Filter\FilterClearNormalizer;
use Fedale\GridviewBundle\Grid\Gridview;
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
        $gridClear = $this->gridview->getOptions()['filterControls']['clear'] ?? null;
        if ($gridClear !== null) {
            return FilterClearNormalizer::normalize($gridClear);
        }

        // Fallback to retro-compatible logic: ['header'] + 'input' iff inlineClear.
        $inlineClearDefault = (bool) ($this->gridview->getOptions()['filterControls']['inlineClear'] ?? false);
        return FilterClearNormalizer::normalize(null, $inlineClearDefault);
    }

    public function getOptions(): array
    {
        return $this->options ?? [];
    }
}