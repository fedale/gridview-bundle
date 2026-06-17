<?php

namespace Fedale\GridviewBundle\Serializer;

use Doctrine\ORM\PersistentCollection;
use Doctrine\Persistence\Proxy;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;

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
 * proxies are returned as null instead of being loaded. The contract becomes
 * explicit: if a column needs a relation, the repository's query must
 * fetch-join it; anything else is intentionally not serialized.
 */
class LazyAwareObjectNormalizer extends ObjectNormalizer
{
    protected function getAttributeValue(object $object, string $attribute, ?string $format = null, array $context = []): mixed
    {
        $value = parent::getAttributeValue($object, $attribute, $format, $context);

        if ($value instanceof PersistentCollection && !$value->isInitialized()) {
            return null;
        }

        if ($value instanceof Proxy && !$value->__isInitialized()) {
            return null;
        }

        return $value;
    }
}
