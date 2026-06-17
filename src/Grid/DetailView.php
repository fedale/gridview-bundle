<?php

namespace Fedale\GridviewBundle\Grid;

use Doctrine\Common\Collections\ArrayCollection;
use Fedale\GridviewBundle\Column\ColumnFactory;
use Fedale\GridviewBundle\Contract\ColumnInterface;
use Fedale\GridviewBundle\Service\GridviewService;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/**
 * The single-record sibling of {@see Gridview}: it shares the very same column
 * DNA but renders ONE model as a vertical key/value table (the "show" view of a
 * CRUD) instead of a list.
 *
 * It deliberately has no filters, pagination, sort, global search, Turbo-Frame
 * switching or token layout — none of those make sense for a single record.
 * Columns are reused unchanged; only their data side (label + rendered cell) is
 * used here.
 */
class DetailView
{
    /** @var ArrayCollection<int, ColumnInterface> */
    private ArrayCollection $columns;

    private object $model;

    private Environment $twig;

    protected ?string $id = null;

    /** Flat HTML attribute bag for the <table> element (class + arbitrary). */
    public array $attr = [];

    protected array $options = [
        'emptyText'   => 'No data',
        // Render the row only when the column is visible in the grid. Default
        // false: a "show" page usually wants *every* field, including the ones
        // hidden from the list. Flip it (per-call or via YAML) to honour grid
        // visibility instead.
        'onlyVisible' => false,
        'template'    => '@FedaleGridview/detailview/detailview.html.twig',
    ];

    public function __construct(
        private GridviewService $gridviewService,
        private ColumnFactory $columnFactory,
    ) {
        $this->columns = new ArrayCollection();
        $this->twig    = $this->gridviewService->getEnvironment();
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function setId(string $id): void
    {
        $this->id = $id;
    }

    public function getModel(): object
    {
        return $this->model;
    }

    public function setModel(object $model): void
    {
        $this->model = $model;
    }

    public function addColumn(ColumnInterface $column): void
    {
        $this->columns->add($column);
    }

    /** @return ArrayCollection<int, ColumnInterface> */
    public function getColumns(): ArrayCollection
    {
        return $this->columns;
    }

    /**
     * The columns actually shown as key/value rows: data columns only
     * (getAttribute() !== null, i.e. action/structural columns excluded),
     * optionally narrowed to visible ones via the `onlyVisible` option.
     *
     * @return ColumnInterface[]
     */
    public function getDataColumns(): array
    {
        $onlyVisible = (bool) ($this->options['onlyVisible'] ?? false);

        return array_values(array_filter(
            $this->columns->toArray(),
            static fn (ColumnInterface $c) => $c->getAttribute() !== null
                && (!$onlyVisible || $c->isVisible())
        ));
    }

    /**
     * Pre-rendered rows for non-Twig consumers (and tests): a list of
     * `['label' => ..., 'value' => ...]`. Note this bypasses the per-column
     * `twigFilter`, which is applied by the template; use {@see getDataColumns()}
     * when you need the full templated cell output.
     *
     * @return array<int, array{label: ?string, value: mixed}>
     */
    public function rows(): array
    {
        $rows = [];
        foreach ($this->getDataColumns() as $column) {
            $rows[] = [
                'label' => $column->getLabel(),
                'value' => $column->render($this->model, 0),
            ];
        }

        return $rows;
    }

    public function setOptions(array $options): void
    {
        $this->options = array_replace($this->options, $options);
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    public function setAttributes(array $attributes): void
    {
        if (array_key_exists('class', $attributes) && $attributes['class'] === null) {
            unset($attributes['class']);
        }
        $this->attr = $attributes;
    }

    public function getAttributes(): array
    {
        return $this->attr;
    }

    public function render(?string $view = null): Response
    {
        $template = $view ?? $this->options['template'];

        return new Response($this->twig->render($template, [
            'detailview' => $this,
            'model'      => $this->model,
            'columns'    => $this->getDataColumns(),
        ]));
    }
}
