<?php

namespace Fedale\GridviewBundle\Serializer;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\PersistentCollection;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\PropertyInfo\PropertyTypeExtractorInterface;
use Symfony\Component\Serializer\Mapping\ClassDiscriminatorResolverInterface;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactoryInterface;
use Symfony\Component\Serializer\NameConverter\NameConverterInterface;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\SerializerAwareInterface;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * ObjectNormalizer that never triggers Doctrine lazy-loading.
 *
 * A grid normalizes whole entities to arrays for rendering. The default
 * ObjectNormalizer walks the entire object graph, so every association that
 * was not fetch-joined gets lazy-loaded — one extra query per row, per
 * relation (a classic N+1).
 *
 * This normalizer skips associations that are not already initialized:
 * uninitialized {@see PersistentCollection} and uninitialized to-one
 * associations are normalized to null instead of being loaded (the key is
 * kept, only the value becomes null). The contract becomes explicit: if a
 * column needs a relation, the query must fetch-join it (see the data
 * provider's `eager` option); anything else is intentionally not serialized.
 *
 * Doctrine ORM 3 hydrates uninitialized to-one associations as lazy ghost
 * objects: real instances of the entity class that are *not* instances of
 * {@see \Doctrine\Persistence\Proxy}. Sniffing the value type therefore misses
 * them, so initialization state is resolved through the EntityManager
 * ({@see EntityManagerInterface::isUninitializedObject()}), which also covers
 * classic proxies. Associations are discovered from the class metadata rather
 * than from the property value, so the guard applies whether or not the
 * relation was already loaded.
 *
 * Symfony 8 made {@see ObjectNormalizer} final, so this can no longer extend it
 * to override getAttributeValue(). It now decorates an inner ObjectNormalizer
 * and registers a self-checking per-attribute callback for every Doctrine
 * relation property. The callback re-inspects the value, so it stays correct
 * even when the Serializer propagates the callback context down the graph.
 */
class LazyAwareObjectNormalizer implements NormalizerInterface, SerializerAwareInterface
{
    private ObjectNormalizer $inner;

    public function __construct(
        private EntityManagerInterface $entityManager,
        ?ClassMetadataFactoryInterface $classMetadataFactory = null,
        ?NameConverterInterface $nameConverter = null,
        ?PropertyAccessorInterface $propertyAccessor = null,
        ?PropertyTypeExtractorInterface $propertyTypeExtractor = null,
        ?ClassDiscriminatorResolverInterface $classDiscriminatorResolver = null,
        ?callable $objectClassResolver = null,
        array $defaultContext = [],
    ) {
        $this->inner = new ObjectNormalizer(
            $classMetadataFactory,
            $nameConverter,
            $propertyAccessor,
            $propertyTypeExtractor,
            $classDiscriminatorResolver,
            $objectClassResolver,
            $defaultContext,
        );
    }

    public function setSerializer(SerializerInterface $serializer): void
    {
        $this->inner->setSerializer($serializer);
    }

    public function normalize(mixed $data, ?string $format = null, array $context = []): array|string|int|float|bool|\ArrayObject|null
    {
        if (\is_object($data) && !$data instanceof \Traversable) {
            $context = $this->withLazyGuards($data, $context);
        }

        return $this->inner->normalize($data, $format, $context);
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $this->inner->supportsNormalization($data, $format, $context);
    }

    public function getSupportedTypes(?string $format): array
    {
        return $this->inner->getSupportedTypes($format);
    }

    /**
     * Adds a {@see lazyGuard()} callback for every Doctrine association of the
     * object's class. Associations are read from the class metadata (not from
     * the property values), so the guard is registered whether or not the
     * relation is already loaded; the callback then decides per value. A
     * non-entity object (no metadata) is left untouched.
     */
    private function withLazyGuards(object $object, array $context): array
    {
        if ($this->entityManager->getMetadataFactory()->isTransient($object::class)) {
            return $context;
        }

        $callbacks = $context[AbstractNormalizer::CALLBACKS] ?? [];

        foreach ($this->entityManager->getClassMetadata($object::class)->getAssociationNames() as $name) {
            $callbacks[$name] ??= $this->lazyGuard(...);
        }

        if ($callbacks !== []) {
            $context[AbstractNormalizer::CALLBACKS] = $callbacks;
        }

        return $context;
    }

    /** Returns null for an uninitialized relation, the value untouched otherwise. */
    private function lazyGuard(mixed $value): mixed
    {
        if ($value instanceof PersistentCollection && !$value->isInitialized()) {
            return null;
        }

        // ORM 3 lazy ghosts are not Proxy instances, so ask the EntityManager;
        // it also reports classic (pre-lazy-ghost) proxies as uninitialized.
        if (\is_object($value) && $this->entityManager->isUninitializedObject($value)) {
            return null;
        }

        return $value;
    }
}
