<?php

namespace Fedale\GridviewBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Fedale\GridviewBundle\Grid\DetailView;
use Fedale\GridviewBundle\Grid\GridviewBuilderFactory;
use Fedale\GridviewBundle\Row\Row;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

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
    private ?array $resolvedConfig = null;

    /** FQCN of the entity backing the view (e.g. Customer::class). */
    abstract protected function getDataClass(): string;

    /** @return array<int, mixed> Column definitions, as consumed by the ColumnFactory. */
    abstract protected function buildColumns(): array;

    /**
     * Per-controller overrides merged over {@see defaultConfig()}.
     *
     * @return array<string, mixed>
     */
    protected function configure(): array
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
            'id'           => $id,
            'showTemplate' => '@FedaleGridview/detailview/detailview.html.twig',
            'attributes'   => [],   // table-level HTML attrs; falls back to YAML/defaults
            'options'      => [],   // extra builder options (emptyText, onlyVisible, ...)
        ];
    }

    protected function config(?string $key = null, mixed $default = null): mixed
    {
        if ($this->resolvedConfig === null) {
            $this->resolvedConfig = array_replace($this->defaultConfig(), $this->configure());
        }

        return $key === null ? $this->resolvedConfig : ($this->resolvedConfig[$key] ?? $default);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(int|string $id): Response
    {
        $entity = $this->findModel($id);
        if ($entity === null) {
            throw $this->createNotFoundException();
        }

        return $this->buildDetailView($entity)->render($this->config('showTemplate'));
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
        return $this->em()->getRepository($this->getDataClass())->find($id);
    }

    /**
     * Wraps the entity into the same {@see Row} shape grid columns expect
     * (`->data` = normalized array), so DataColumn::render() works unchanged.
     * Mirrors the normalizer setup of EntityDataProvider.
     */
    protected function toRow(object $entity): Row
    {
        $serializer = new Serializer([
            new DateTimeNormalizer([
                DateTimeNormalizer::FORMAT_KEY   => \DateTimeInterface::ATOM,
                DateTimeNormalizer::TIMEZONE_KEY => new \DateTimeZone(date_default_timezone_get()),
            ]),
            new ObjectNormalizer(null, null, null, null, null, null, [
                AbstractNormalizer::CIRCULAR_REFERENCE_HANDLER => static fn ($object) => $object->getId(),
            ]),
        ]);

        $row       = new Row(0, 1);
        $row->data = $serializer->normalize($entity);

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

    public static function getSubscribedServices(): array
    {
        return array_merge(parent::getSubscribedServices(), [
            GridviewBuilderFactory::class,
            EntityManagerInterface::class,
        ]);
    }
}
