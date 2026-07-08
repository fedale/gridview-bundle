<?php

namespace Fedale\GridviewBundle\DataProvider;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Fedale\GridviewBundle\Contract\AggregatableInterface;
use Fedale\GridviewBundle\Contract\ChildRowResolverInterface;
use Fedale\GridviewBundle\Contract\GroupingCapableInterface;
use Fedale\GridviewBundle\Contract\ScopeVerifiableInterface;
use Fedale\GridviewBundle\Contract\SearchFormInterface;
use Fedale\GridviewBundle\Row\Row;
use Fedale\GridviewBundle\Serializer\RowSerializerFactory;
use Fedale\GridviewBundle\Event\RowEvent;

class EntityDataProvider extends AbstractDataProvider implements AggregatableInterface, GroupingCapableInterface, ScopeVerifiableInterface
{
    protected QueryBuilder $queryBuilder;

    protected $ormMetadata;

    private $paginator;

    private int $totalRows;

    private array $params;

    /** The filter-form param name (grid `formName` option); drives which query params are read. */
    private string $formName = 'fedaleForm';

    /** How the current filters were applied: repository search(), searchFields, or none. */
    private string $filterPath = 'none';

    private ?SearchFormInterface $searchForm = null;

    private ?ChildRowResolverInterface $childResolver = null;

    public function __construct(
        private EventDispatcherInterface $eventDispatcher,
        private EntityManagerInterface $entityManager,
        private RequestStack $requestStack,
        private RowSerializerFactory $serializerFactory,
    ) {
        $this->models = new ArrayCollection();
        $this->populateParams();
    }

    public function setChildResolver(ChildRowResolverInterface $childResolver): void
    {
        $this->childResolver = $childResolver;
    }

    public function getChildResolver(): ?ChildRowResolverInterface
    {
        return $this->childResolver;
    }

    /** Used to apply the declarative `searchFields` map when the repository has no search(). */
    public function setSearchForm(SearchFormInterface $searchForm): void
    {
        $this->searchForm = $searchForm;
    }

    private function populateParams(): void
    {
        $this->params = $this->requestStack->getCurrentRequest()?->query->all($this->formName) ?? [];
    }

    /**
     * Set the filter-form param name (the grid `formName` option) and re-read the
     * request params under it. The constructor primes params with the default
     * 'fedaleForm'; the grid forwards the configured name before prepareModels().
     */
    public function setFormName(string $formName): void
    {
        $this->formName = $formName;
        $this->populateParams();
    }

    public function setDefaultParams(array $defaults): void
    {
        parent::setDefaultParams($defaults);

        if ($defaults === []) {
            return;
        }

        // A submitted GET form always sends every field (even empty), so a
        // present-but-empty form param means the user cleared the filters:
        // defaults apply only when the form is absent from the query string.
        $request = $this->requestStack->getCurrentRequest();
        if ($request !== null && $request->query->has($this->formName)) {
            return;
        }

        $this->params = $defaults;
    }

    public function setQueryBuilder(QueryBuilder $queryBuilder): void
    {
        $this->queryBuilder = $queryBuilder;
    }

    public function prepareModels(string|array $models): void
    {
        $repository = $this->entityManager->getRepository($models);

        // Repositories may implement the grid's search() contract, applying the
        // filters themselves. Entities from third-party bundles often don't:
        // fall back to a plain QueryBuilder and apply the optional declarative
        // `searchFields` map here, so sorting, pagination and filtering keep
        // working either way.
        if (method_exists($repository, 'search')) {
            $this->filterPath = 'repository search()';
            $this->queryBuilder = $repository->search($this->params);

            return;
        }

        $qb = $repository->createQueryBuilder($this->alias);

        if ($this->searchFields !== [] && $this->searchForm !== null) {
            $this->filterPath = 'searchFields';
            $this->searchForm->applyFilters($qb, $this->params, $this->searchFields);
        }

        $this->queryBuilder = $qb;
    }

    public function applyGlobalSearch(array $fields, string $term): void
    {
        // Plain field names ('name') must be qualified with the query's root
        // alias to be valid DQL; an already-qualified field ('t.name') or any
        // expression containing a dot is left untouched.
        $rootAlias = $this->queryBuilder->getRootAliases()[0] ?? $this->alias;
        $qualify = static fn(string $f): string => str_contains($f, '.') ? $f : $rootAlias . '.' . $f;

        $exprs = array_map(
            fn($f) => $this->queryBuilder->expr()->like(
                $this->queryBuilder->expr()->lower($qualify($f)),
                $this->queryBuilder->expr()->literal('%' . strtolower($term) . '%')
            ),
            $fields
        );
        $this->queryBuilder->andWhere($this->queryBuilder->expr()->orX(...$exprs));
    }

    /**
     * Whether a row with the given key would appear in the current, filtered
     * query — i.e. it passes any row-level restriction the repository's
     * search() applies, not just "this id exists". Cloned off the already
     * prepared queryBuilder (before pagination limits) so it inherits the
     * exact same WHERE/JOIN as the list itself.
     */
    public function isKeyInScope(int|string $key, string $keyField = 'id'): bool
    {
        $rootAlias = $this->queryBuilder->getRootAliases()[0] ?? $this->alias;

        $qb = (clone $this->queryBuilder)
            ->select('1')
            ->andWhere(sprintf('%s.%s = :gvScopeKey', $rootAlias, $keyField))
            ->setParameter('gvScopeKey', $key)
            ->setMaxResults(1);

        return $qb->getQuery()->getOneOrNullResult() !== null;
    }

    public function getRootAlias(): string
    {
        return isset($this->queryBuilder)
            ? ($this->queryBuilder->getRootAliases()[0] ?? $this->alias)
            : $this->alias;
    }

    /**
     * Aggregate over the whole filtered dataset in a single query: clones the
     * prepared query builder (so it inherits the exact WHERE/JOIN of the list),
     * drops pagination and ordering, and selects every requested expression at
     * once. Returns the scalar row keyed by the caller's aliases.
     *
     * @param array<string, string> $expressions
     *
     * @return array<string, int|float|string|null>
     */
    public function aggregate(array $expressions): array
    {
        if ($expressions === [] || !isset($this->queryBuilder)) {
            return [];
        }

        $qb = (clone $this->queryBuilder)
            ->setFirstResult(null)
            ->setMaxResults(null)
            ->resetDQLPart('orderBy');

        $select = [];
        foreach ($expressions as $alias => $expr) {
            $select[] = sprintf('%s AS %s', $expr, $alias);
        }
        $qb->select(implode(', ', $select));

        /** @var array<string, int|float|string|null> $row */
        $row = $qb->getQuery()->getSingleResult();

        return $row;
    }

    public function getData()
    {
        $this->pagination->setTotalCount($this->getTotalCount());

        $this->queryBuilder
            ->setMaxResults($this->pagination->getPageSize())
            ->setFirstResult($this->pagination->getOffset())
        ;

        foreach ($this->getSort()->fetchOrders() as $fieldName => $sortType) {
            $this->queryBuilder->addOrderBy($fieldName, $sortType);
        }

        $this->prepareData();

        return $this->models;
    }

    public function getAllData()
    {
        // Same as getData() but without pagination limits — applies the current
        // sort and returns every row matching the filters (used by exports).
        foreach ($this->getSort()->fetchOrders() as $fieldName => $sortType) {
            $this->queryBuilder->addOrderBy($fieldName, $sortType);
        }

        $this->prepareData();

        return $this->models;
    }

    private function prepareData(): void
    {
        if (!$this->queryBuilder instanceof QueryBuilder) {
            throw new \Exception('The "queryBuilder" property must be an instance of Doctrine\ORM\QueryBuilder.');
        }

        $serializer = $this->serializerFactory->create($this->ignoredAttributes);

        $this->paginator = new Paginator($this->queryBuilder, true);

        $event = new RowEvent();
        foreach ($this->paginator as $key => $model) {
            $row = new Row($key, $this->pagination->getPageSize(), $this->pagination->getOffset());

            // A scalar addSelect (e.g. "COUNT(...) AS postCount") makes Doctrine
            // hydrate the row as [0 => entity, 'alias' => value, ...]. Unwrap it so
            // the entity is normalized as usual and the computed scalars are merged
            // onto the row data, letting a plain DataColumn read them by attribute.
            $extra = [];
            if (is_array($model)) {
                $entity = $model[0] ?? null;
                unset($model[0]);
                $extra  = $model;
                $model  = $entity;
            }

            $data       = $model !== null ? $serializer->normalize($model) : [];
            $row->data  = $extra === [] ? $data : array_merge($data, $extra);
            $event->row = $row;
            $this->eventDispatcher->dispatch($event, RowEvent::BEFORE_ROW);
            $this->models->add($row);
            $this->eventDispatcher->dispatch($event, RowEvent::AFTER_ROW);
        }
    }

    public function getTotalCount($criteria = []): int
    {
        $this->paginator = new Paginator($this->queryBuilder, true);
        $this->totalRows = count($this->paginator);

        return $this->totalRows;
    }

    /** How the current filters were applied (for the profiler). */
    public function getFilterPath(): string
    {
        return $this->filterPath;
    }

    /** The raw filter-form params read from the request (for the profiler). */
    public function getParams(): array
    {
        return $this->params;
    }

    /**
     * A best-effort, serializable view of the built query for the profiler: DQL,
     * SQL and bound parameters. Read after getData(), so it reflects the page
     * query (max results, offset and order by included).
     *
     * @return array{dql: ?string, sql: ?string, params: list<array{name: string, value: string}>, rootAlias: ?string}
     */
    public function getDebugQuery(): array
    {
        if (!isset($this->queryBuilder)) {
            return ['dql' => null, 'sql' => null, 'params' => [], 'rootAlias' => null];
        }

        $qb = $this->queryBuilder;

        $dql = null;
        $sql = null;
        try {
            $dql = $qb->getDQL();
        } catch (\Throwable) {
        }
        try {
            $sql = $qb->getQuery()->getSQL();
        } catch (\Throwable) {
        }

        $params = [];
        foreach ($qb->getParameters() as $parameter) {
            $params[] = [
                'name' => (string) $parameter->getName(),
                'value' => $this->stringifyParam($parameter->getValue()),
            ];
        }

        return [
            'dql' => $dql,
            'sql' => is_array($sql) ? implode('; ', $sql) : $sql,
            'params' => $params,
            'rootAlias' => $qb->getRootAliases()[0] ?? null,
        ];
    }

    private function stringifyParam(mixed $value): string
    {
        if (is_scalar($value) || $value === null) {
            return (string) $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }
        if (is_array($value)) {
            return '[' . implode(', ', array_map($this->stringifyParam(...), $value)) . ']';
        }
        if (is_object($value)) {
            if (method_exists($value, 'getId')) {
                return sprintf('%s#%s', get_debug_type($value), (string) $value->getId());
            }
            if (method_exists($value, '__toString')) {
                return (string) $value;
            }

            return get_debug_type($value);
        }

        return get_debug_type($value);
    }
}
