<?php
namespace Fedale\GridviewBundle\DataProvider;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Serializer;
use Fedale\GridviewBundle\Serializer\LazyAwareObjectNormalizer;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Fedale\GridviewBundle\Row\Row;
use Fedale\GridviewBundle\Event\RowEvent;

class EntityDataProvider extends AbstractDataProvider
{
    protected QueryBuilder $queryBuilder;

    protected $ormMetadata;

    private $paginator;

    private int $totalRows;

    private array $params;

    public function __construct(
        private EventDispatcherInterface $eventDispatcher,
        private EntityManagerInterface $entityManager,
        private RequestStack $requestStack,
    ) {
        $this->models = new ArrayCollection();
        $this->populateParams();
    }

    private function populateParams(): void
    {
        $this->params = $this->requestStack->getCurrentRequest()?->get('myform') ?? [];
    }

    public function setDefaultParams(array $defaults): void
    {
        parent::setDefaultParams($defaults);

        if ($defaults === []) {
            return;
        }

        // A submitted GET form always sends every field (even empty), so a
        // present-but-empty 'myform' means the user cleared the filters:
        // defaults apply only when 'myform' is absent from the query string.
        $request = $this->requestStack->getCurrentRequest();
        if ($request !== null && $request->query->has('myform')) {
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
        $this->queryBuilder = $this->entityManager->getRepository($models)->search($this->params);
    }

    public function applyGlobalSearch(array $fields, string $term): void
    {
        $exprs = array_map(
            fn($f) => $this->queryBuilder->expr()->like(
                $this->queryBuilder->expr()->lower($f),
                $this->queryBuilder->expr()->literal('%' . strtolower($term) . '%')
            ),
            $fields
        );
        $this->queryBuilder->andWhere($this->queryBuilder->expr()->orX(...$exprs));
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

        $defaultContext = [
            AbstractNormalizer::CIRCULAR_REFERENCE_HANDLER => fn($object) => $object->getId(),
            AbstractNormalizer::IGNORED_ATTRIBUTES => $this->ignoredAttributes,
        ];
        $normalizers = [
            new DateTimeNormalizer([
                DateTimeNormalizer::FORMAT_KEY   => \DateTimeInterface::ATOM,
                DateTimeNormalizer::TIMEZONE_KEY => new \DateTimeZone(date_default_timezone_get()),
            ]),
            new LazyAwareObjectNormalizer(null, null, null, null, null, null, $defaultContext),
        ];
        $serializer  = new Serializer($normalizers);

        $this->paginator = new Paginator($this->queryBuilder, true);

        $event = new RowEvent();
        foreach ($this->paginator as $key => $model) {
            $row       = new Row($key, $this->pagination->getPageSize());
            $row->data = $serializer->normalize($model);
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
}
