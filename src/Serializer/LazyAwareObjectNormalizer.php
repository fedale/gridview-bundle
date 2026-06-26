<?php

namespace Fedale\GridviewBundle\Serializer;

use Doctrine\ORM\PersistentCollection;
use Doctrine\Persistence\Proxy;
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
 * uninitialized {@see PersistentCollection} and uninitialized Doctrine
 * proxies are normalized to null instead of being loaded (the key is kept,
 * only the value becomes null). The contract becomes explicit: if a column
 * needs a relation, the repository's query must fetch-join it; anything else
 * is intentionally not serialized.
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
     * Adds a {@see lazyGuard()} callback for every property that currently holds
     * a Doctrine relation (initialized or not). Reading the property value does
     * not initialize it, so this scan is side-effect free.
     */
    private function withLazyGuards(object $object, array $context): array
    {
        $callbacks = $context[AbstractNormalizer::CALLBACKS] ?? [];

        foreach ((new \ReflectionObject($object))->getProperties() as $property) {
            if (!$property->isInitialized($object)) {
                continue;
            }

            $value = $property->getValue($object);
            if ($value instanceof PersistentCollection || $value instanceof Proxy) {
                $callbacks[$property->getName()] ??= self::lazyGuard(...);
            }
        }

        if ($callbacks !== []) {
            $context[AbstractNormalizer::CALLBACKS] = $callbacks;
        }

        return $context;
    }

    /** Returns null for an uninitialized relation, the value untouched otherwise. */
    private static function lazyGuard(mixed $value): mixed
    {
        if ($value instanceof PersistentCollection && !$value->isInitialized()) {
            return null;
        }

        if ($value instanceof Proxy && !$value->__isInitialized()) {
            return null;
        }

        return $value;
    }
}
