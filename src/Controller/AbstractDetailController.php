<?php

namespace Fedale\GridviewBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Fedale\GridviewBundle\Doctrine\EntityIdentifier;
use Fedale\GridviewBundle\Grid\DetailView;
use Fedale\GridviewBundle\Grid\GridviewBuilderFactory;
use Fedale\GridviewBundle\Row\Row;
use Fedale\GridviewBundle\Serializer\RowSerializerFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Read-only "show" controller base: renders a single record as a key/value
 * {@see DetailView}, reusing the very same column definitions as the entity's
 * grid ({@see AbstractGridController::buildColumns()}).
 *
 * It is deliberately NOT a subclass of {@see AbstractGridController}: a detail
 * view shares only the columns, not the list machinery (pagination, sort,
 * filters, export, real-time), so a lean dedicated base reads cleaner than
 * overloading the grid controller. Concrete controllers typically expose both
 * `buildColumns()` from a shared trait so grid and detail never drift.
 */
abstract class AbstractDetailController extends AbstractController
{
    use ResolvesViewConfig;

    /** FQCN of the entity backing the view (e.g. Customer::class). */
    abstract protected function getDataClass(): string;

    /** @return array<int, mixed> Column definitions, as consumed by the ColumnFactory. */
    abstract protected function buildColumns(): array;

    /**
     * Per-controller overrides merged over {@see defaultConfig()}.
     *
     * @return array<string, mixed>
     */
    protected function viewConfig(): array
    {
        return [];
    }

    /**
     * `id` defaults to the entity short name lowercased — the SAME id as the
     * grid, looked up in the separate `detailviews.<id>` YAML section.
     *
     * @return array<string, mixed>
     */
    protected function defaultConfig(): array
    {
        $id = strtolower((new \ReflectionClass($this->getDataClass()))->getShortName());

        return [
            'id'         => $id,
            'template'   => ['show' => '@FedaleGridview/detailview/detailview.html.twig'],
            'attributes' => [],   // table-level HTML attrs; falls back to YAML/defaults
            'options'    => [],   // extra builder options (emptyText, onlyVisible, ...)
        ];
    }

    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(int|string $id): Response
    {
        $entity = $this->findModel($id);
        if ($entity === null) {
            throw $this->createNotFoundException();
        }

        return $this->buildDetailView($entity)->render($this->config('template.show'));
    }

    protected function buildDetailView(object $entity): DetailView
    {
        return $this->detailBuilderFactory()->createDetailViewBuilder()
            ->setId($this->config('id'))
            ->setModel($this->toRow($entity))
            ->setColumns($this->buildColumns())
            ->setOptions($this->config('options'))
            ->setAttributes($this->config('attributes'))
            ->renderDetailView();
    }

    /** Override to customise lookup (e.g. soft-delete scope). */
    protected function findModel(int|string $id): ?object
    {
        // Through the identifier resolver, so a UUID or a composite token in the
        // URL finds its record exactly like an auto-increment int does.
        $criteria = $this->identifiers()->criteria($this->getDataClass(), (string) $id);

        return $criteria === null ? null : $this->em()->getRepository($this->getDataClass())->find($criteria);
    }

    /**
     * Wraps the entity into the same {@see Row} shape grid columns expect
     * (`->data` = normalized array), so DataColumn::render() works unchanged.
     *
     * Through the SAME factory the grid's rows go through, rather than a
     * hand-rolled serializer: that one walked the whole object graph, lazy-loading
     * every association it met — a detail view of an entity with a to-many
     * relation would spend its time loading records nothing renders, and could
     * run until the request timed out. The factory's normalizer refuses to
     * initialize anything, and brings the Uid and backed-enum handling with it.
     */
    protected function toRow(object $entity): Row
    {
        $serializer = $this->container->get(RowSerializerFactory::class)->create();

        $row                  = new Row(0, 1);
        $row->data            = $serializer->normalize($entity);
        $row->identifierToken = $this->identifiers()->fromEntity($entity);
        $row->data[EntityIdentifier::ROW_KEY] = $row->identifierToken;

        return $row;
    }

    protected function detailBuilderFactory(): GridviewBuilderFactory
    {
        return $this->container->get(GridviewBuilderFactory::class);
    }

    protected function em(): EntityManagerInterface
    {
        return $this->container->get(EntityManagerInterface::class);
    }

    /** Reads and writes the URL tokens that stand for a record. */
    protected function identifiers(): EntityIdentifier
    {
        return $this->container->get(EntityIdentifier::class);
    }

    public static function getSubscribedServices(): array
    {
        return array_merge(parent::getSubscribedServices(), [
            GridviewBuilderFactory::class,
            EntityManagerInterface::class,
            EntityIdentifier::class,
            RowSerializerFactory::class,
        ]);
    }
}
