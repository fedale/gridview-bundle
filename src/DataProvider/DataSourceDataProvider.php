<?php

namespace Fedale\GridviewBundle\DataProvider;

use Doctrine\Common\Collections\ArrayCollection;
use Fedale\GridviewBundle\Contract\ColumnAwareDataProviderInterface;
use Fedale\GridviewBundle\Contract\FilterCriteriaBuilderInterface;
use Fedale\GridviewBundle\Contract\GridDataSourceInterface;
use Fedale\GridviewBundle\DataSource\FilterCriterion;
use Fedale\GridviewBundle\DataSource\GridQuery;
use Fedale\GridviewBundle\DataSource\SortDirection;
use Fedale\GridviewBundle\DataSource\SortOrder;
use Fedale\GridviewBundle\Doctrine\EntityIdentifier;
use Fedale\GridviewBundle\Event\RowEvent;
use Fedale\GridviewBundle\Filter\Criteria\FilterCriteriaBuilderRegistry;
use Fedale\GridviewBundle\Row\Row;
use Fedale\GridviewBundle\Sort\Sort;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Lets a {@see GridDataSourceInterface} power a grid through the regular
 * pipeline: it reads filters, global search, sort and page from the request,
 * hands the source one normalized {@see GridQuery}, and wraps the arrays it
 * returns in rows. Gridview, the exporters and the paginator strategies see an
 * ordinary {@see \Fedale\GridviewBundle\Contract\DataProviderInterface}.
 *
 * One instance per grid, built by {@see DataSourceDataProviderFactory}: it is
 * not a container service, so all of its state dies with the grid and there is
 * nothing to reset between requests.
 */
class DataSourceDataProvider extends AbstractDataProvider implements ColumnAwareDataProviderInterface
{
    use ReadsFilterParamsTrait;

    private mixed $source = null;

    /** @var array<string, array{0: string, 1: string, 2?: array<string, mixed>}> */
    private array $filterSpecs = [];

    /** @var list<string> */
    private array $globalSearchFields = [];

    private ?string $searchTerm = null;

    /** The last query sent to the source, for the profiler. */
    private ?GridQuery $lastQuery = null;

    /**
     * @param array<string, FilterCriteriaBuilderInterface> $criteriaBuilders per-grid builders (`search.builders`), by filter type,
     *                                                                        consulted before the shared registry
     * @param string|list<string>                           $identifier       the row key(s) the record is addressed by
     */
    public function __construct(
        private readonly GridDataSourceInterface $dataSource,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly RequestStack $requestStack,
        private readonly FilterCriteriaBuilderRegistry $criteriaBuilderRegistry,
        private readonly ?string $gridId = null,
        private readonly array $criteriaBuilders = [],
        private readonly string|array $identifier = 'id',
    ) {
        $this->models = new ArrayCollection();
        $this->populateParams();
    }

    protected function currentRequest(): ?Request
    {
        return $this->requestStack->getCurrentRequest();
    }

    public function getDataSource(): GridDataSourceInterface
    {
        return $this->dataSource;
    }

    /**
     * @param string|array<array-key, mixed> $models the grid's `model`, passed through as GridQuery::$source
     */
    public function prepareModels(string|array $models): void
    {
        $this->source = $models;
    }

    /**
     * Also resolves each filter's criteria builder, so a type nothing can
     * build fails the grid here — while it is being set up — rather than inside
     * getData(), where Gridview::renderGrid() would turn it into an empty grid
     * with a logged error.
     */
    public function setFilterSpecs(array $specs): void
    {
        $this->filterSpecs = $specs;

        foreach ($this->specs() as $paramKey => $spec) {
            $this->criteriaBuilder($paramKey, $spec[0]);
        }
    }

    /**
     * @param array<array-key, string> $fields
     */
    public function applyGlobalSearch(array $fields, string $term): void
    {
        $this->globalSearchFields = array_values($fields);
        $this->searchTerm = $term;
    }

    /**
     * @return ArrayCollection<int, Row>
     */
    public function getData(): ArrayCollection
    {
        $query = $this->buildQuery();

        // The total must be known before the offset is read:
        // Pagination::getCurrentPage() clamps the requested page against it the
        // first time it is called and caches the result.
        $this->pagination->setTotalCount($this->dataSource->count($query));

        $pageSize = $this->pagination->getPageSize();
        $offset = $this->pagination->getOffset();
        $this->lastQuery = $query->withWindow($offset, $pageSize !== null && $pageSize > 0 ? $pageSize : null);

        return $this->models = $this->buildRows($this->dataSource->fetch($this->lastQuery), (int) $pageSize, $offset);
    }

    /**
     * @return ArrayCollection<int, Row>
     */
    public function getAllData(): ArrayCollection
    {
        $this->lastQuery = $this->buildQuery()->withWindow(0, null);

        return $this->buildRows($this->dataSource->fetch($this->lastQuery), 0, 0);
    }

    /** How the current filters were applied (for the profiler). */
    public function getFilterPath(): string
    {
        return 'dataSource';
    }

    /**
     * The query sent to the source, in the profiler's query shape: the
     * readable GridQuery stands where an entity grid shows its DQL.
     *
     * @return array{dql: ?string, sql: ?string, params: list<array{name: string, value: string}>, rootAlias: ?string}
     */
    public function getDebugQuery(): array
    {
        $query = $this->lastQuery;
        if ($query === null) {
            return ['dql' => null, 'sql' => null, 'params' => [], 'rootAlias' => null];
        }

        $lines = [sprintf('source: %s', json_encode($query->source))];
        foreach ($query->criteria as $criterion) {
            $lines[] = sprintf(
                'where %s %s%s %s',
                $criterion->attribute,
                $criterion->operator->value,
                $criterion->caseInsensitive ? ' (ci)' : '',
                $this->describe($criterion->value),
            );
        }
        if ($query->searchTerm !== null) {
            $lines[] = sprintf('search %s in %s', json_encode($query->searchTerm), implode(', ', $query->searchFields));
        }
        foreach ($query->sort as $order) {
            $lines[] = sprintf('sort %s %s', $order->attribute, $order->direction->value);
        }
        $lines[] = sprintf('window offset %d, limit %s', $query->offset, $query->limit ?? 'none');

        return ['dql' => implode("\n", $lines), 'sql' => null, 'params' => [], 'rootAlias' => null];
    }

    private function buildQuery(): GridQuery
    {
        return new GridQuery(
            gridId: $this->gridId,
            source: $this->source,
            criteria: $this->buildCriteria(),
            searchTerm: $this->searchTerm,
            searchFields: $this->searchTerm !== null ? $this->globalSearchFields : [],
            sort: $this->buildSort(),
        );
    }

    /**
     * @return list<FilterCriterion>
     */
    private function buildCriteria(): array
    {
        $criteria = [];
        foreach ($this->specs() as $paramKey => $spec) {
            [$type, $attribute] = $spec;
            foreach ($this->criteriaBuilder($paramKey, $type)->build($attribute, $this->params[$paramKey] ?? null, $spec[2] ?? []) as $criterion) {
                $criteria[] = $criterion;
            }
        }

        return $criteria;
    }

    /**
     * The filters to apply: the grid's own, with an explicit `search.map` entry
     * (attribute in place of the DQL field) winning over what the grid derived
     * from the same column — which is how a column filter gets applier options
     * such as default_operator.
     *
     * @return array<string, array{0: string, 1: string, 2?: array<string, mixed>}>
     */
    private function specs(): array
    {
        return array_replace($this->filterSpecs, $this->searchFields);
    }

    /** Per-grid builder first (`search.builders`), then the shared registry. */
    private function criteriaBuilder(string $paramKey, string $type): FilterCriteriaBuilderInterface
    {
        if (isset($this->criteriaBuilders[$type])) {
            return $this->criteriaBuilders[$type];
        }

        if (!$this->criteriaBuilderRegistry->has($type)) {
            throw new \InvalidArgumentException(sprintf(
                'Gridview "%s": filter "%s" has type "%s", for which no criteria builder exists. Declare one in "search.builders".',
                $this->gridId ?? '?',
                $paramKey,
                $type,
            ));
        }

        return $this->criteriaBuilderRegistry->get($type);
    }

    /**
     * The active sort, expanded through the sort map. For a data-source grid
     * the map's fields are source attributes: the identity map the grid sets
     * for its sortable columns, or an explicit `sort.map`.
     *
     * @return list<SortOrder>
     */
    private function buildSort(): array
    {
        $orders = [];
        foreach ($this->getSort()->fetchOrders() as $attribute => $direction) {
            if (!is_string($attribute)) {
                continue;
            }
            $orders[] = new SortOrder($attribute, $direction === Sort::DESC ? SortDirection::Desc : SortDirection::Asc);
        }

        return $orders;
    }

    /**
     * @param iterable<array<string, mixed>> $items
     *
     * @return ArrayCollection<int, Row>
     */
    private function buildRows(iterable $items, int $pageSize, int $offset): ArrayCollection
    {
        $rows = new ArrayCollection();
        $event = new RowEvent();
        $key = 0;

        foreach ($items as $item) {
            $row = new Row($key++, $pageSize, $offset);
            $row->data = $item;
            // What every link and checkbox addresses the record by, in the same
            // token format an entity grid uses.
            $row->identifierToken = $this->identifierToken($item);
            if ($row->identifierToken !== null) {
                $row->data[EntityIdentifier::ROW_KEY] = $row->identifierToken;
            }

            $event->row = $row;
            $this->eventDispatcher->dispatch($event, RowEvent::BEFORE_ROW);
            $rows->add($row);
            $this->eventDispatcher->dispatch($event, RowEvent::AFTER_ROW);
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function identifierToken(array $item): ?string
    {
        $parts = [];
        foreach ((array) $this->identifier as $field) {
            $value = $item[$field] ?? null;
            if ($value instanceof \BackedEnum) {
                $value = $value->value;
            }
            if (!is_scalar($value) && !$value instanceof \Stringable) {
                return null;
            }
            $parts[] = (string) $value;
        }

        return $parts === [] ? null : EntityIdentifier::encodeParts($parts);
    }

    private function describe(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return (string) json_encode($value);
    }
}
