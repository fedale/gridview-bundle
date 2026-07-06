<?php

namespace Fedale\GridviewBundle\Grid;

use Doctrine\ORM\EntityManagerInterface;
use Fedale\GridviewBundle\Contract\ChildCountResolverInterface;
use Fedale\GridviewBundle\Contract\ChildRowResolverInterface;
use Fedale\GridviewBundle\Row\Row;
use Fedale\GridviewBundle\Serializer\RowSerializerFactory;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

/**
 * Resolves grouped child rows by navigating a Doctrine association (1-N or N-M)
 * on the parent entity. It re-fetches the page's parents through the entity
 * manager — served from the identity map when they are already loaded — and
 * reads the relation via the property accessor, so it works for any association
 * type without knowing the owning side.
 *
 * Child entities are normalized with their associations ignored by default:
 * child columns reference scalar/enum fields, and skipping associations avoids
 * both N+1 lazy loads and the circular back-reference to the parent.
 */
class DoctrineChildRowResolver implements ChildRowResolverInterface, ChildCountResolverInterface
{
    private PropertyAccessorInterface $propertyAccessor;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private RowSerializerFactory $serializerFactory,
    ) {
        $this->propertyAccessor = PropertyAccess::createPropertyAccessor();
    }

    public function resolveForParents(iterable $parentRows, GroupingConfig $config): array
    {
        $keys = $this->extractKeys($parentRows, $config);

        if ($keys === [] || $config->getParentClass() === null) {
            return [];
        }

        $parents = $this->entityManager
            ->getRepository($config->getParentClass())
            ->findBy([$config->getParentKey() => $keys]);

        $out = [];
        foreach ($parents as $parent) {
            $key = $this->propertyAccessor->getValue($parent, $config->getParentKey());
            $out[$key] = $this->buildChildRows($parent, (string) $key, $config);
        }

        return $out;
    }

    public function resolveForParent(int|string $parentKey, GroupingConfig $config): array
    {
        if ($config->getParentClass() === null) {
            return [];
        }

        $parent = $this->entityManager
            ->getRepository($config->getParentClass())
            ->findOneBy([$config->getParentKey() => $parentKey]);

        if ($parent === null) {
            return [];
        }

        return $this->buildChildRows($parent, (string) $parentKey, $config);
    }

    /**
     * Child counts for a page of parents in one query, used in lazy mode to gate
     * the expand toggle without fetching any child. A plain `COUNT(child)` on the
     * joined relation works for both to-one and to-many associations and for
     * either ownership side, since it counts the joined entity, not a column.
     *
     * @param iterable<Row> $parentRows
     *
     * @return array<int|string, int>
     */
    public function countForParents(iterable $parentRows, GroupingConfig $config): array
    {
        $keys = $this->extractKeys($parentRows, $config);

        if ($keys === [] || $config->getParentClass() === null) {
            return [];
        }

        $parentKey = $config->getParentKey();

        $result = $this->entityManager->createQueryBuilder()
            ->select(sprintf('parent.%s AS k, COUNT(child) AS n', $parentKey))
            ->from($config->getParentClass(), 'parent')
            ->leftJoin('parent.' . $config->getRelation(), 'child')
            ->where(sprintf('parent.%s IN (:keys)', $parentKey))
            ->groupBy('parent.' . $parentKey)
            ->setParameter('keys', $keys)
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($result as $row) {
            $counts[$row['k']] = (int) $row['n'];
        }

        return $counts;
    }

    /**
     * @param iterable<Row> $parentRows
     *
     * @return list<int|string>
     */
    private function extractKeys(iterable $parentRows, GroupingConfig $config): array
    {
        $keys = [];
        foreach ($parentRows as $row) {
            $key = $row->data[$config->getParentKey()] ?? null;
            if ($key !== null) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * @return list<Row>
     */
    private function buildChildRows(object $parent, string $parentKey, GroupingConfig $config): array
    {
        $children = $this->propertyAccessor->getValue($parent, $config->getRelation());

        // A to-one relation is a single object; a to-many one is iterable.
        if ($children === null) {
            return [];
        }
        if (!is_iterable($children)) {
            $children = [$children];
        }

        $children = iterator_to_array(
            is_array($children) ? new \ArrayIterator($children) : $children,
            false,
        );

        $limit = $config->getLimit();
        if ($limit !== null && \count($children) > $limit) {
            $children = \array_slice($children, 0, $limit);
        }

        $serializer = $this->serializerFactory->create($this->childIgnoredAttributes($children[0] ?? null));
        $total = \count($children) - 1;

        $rows = [];
        foreach ($children as $i => $child) {
            $row = new Row($i, $total);
            $row->data = $serializer->normalize($child);
            // Child ids share the 'row_N' space with parent rows, which would
            // duplicate DOM ids; scope them by parent so each stays unique.
            $row->setAttr('id', sprintf('child_%s_%d', $parentKey, $i), true);
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Child associations to skip during normalization: navigating them would
     * trigger extra lazy loads per child and re-enter the parent back-reference.
     *
     * @return string[]
     */
    private function childIgnoredAttributes(?object $child): array
    {
        if ($child === null) {
            return [];
        }

        return $this->entityManager
            ->getClassMetadata($child::class)
            ->getAssociationNames();
    }
}
