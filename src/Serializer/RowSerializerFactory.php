<?php

namespace Fedale\GridviewBundle\Serializer;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\UidNormalizer;
use Symfony\Component\Serializer\Serializer;

/**
 * Builds the serializer used to turn entities into grid row data. Shared by the
 * data provider (parent rows) and the child-row resolver (grouped rows) so both
 * normalize entities identically: same date format, backed-enum handling, lazy
 * proxy awareness and accessor-prefix name conversion.
 */
class RowSerializerFactory
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param string[] $ignoredAttributes entity attributes to skip when normalizing
     */
    public function create(array $ignoredAttributes = []): Serializer
    {
        $defaultContext = [
            AbstractNormalizer::CIRCULAR_REFERENCE_HANDLER => fn($object) => $object->getId(),
            AbstractNormalizer::IGNORED_ATTRIBUTES => $ignoredAttributes,
        ];

        $normalizers = [
            // Same class of bug as BackedEnumNormalizer below, one type over: a
            // Symfony\Component\Uid\Uuid would otherwise fall through to the
            // object normalizer, which walks its accessors and serializes a
            // UuidV7 to its inner timestamp — losing the identifier. Registered
            // first so any Uid becomes its canonical string.
            new UidNormalizer(),
            new DateTimeNormalizer([
                DateTimeNormalizer::FORMAT_KEY   => \DateTimeInterface::ATOM,
                DateTimeNormalizer::TIMEZONE_KEY => new \DateTimeZone(date_default_timezone_get()),
            ]),
            // Without this, a backed-enum property falls through to
            // LazyAwareObjectNormalizer's inner ObjectNormalizer, which treats
            // it as a generic object and normalizes it to {name, value} instead
            // of its scalar backing value — breaking any column that prints it.
            new BackedEnumNormalizer(),
            // The name converter keeps serialized row keys aligned with the
            // entity's field names, so a column configured with a Doctrine field
            // whose getter carries an `is`/`has`/`can` prefix still resolves.
            new LazyAwareObjectNormalizer($this->entityManager, null, new AccessorPrefixNameConverter(), null, null, null, null, $defaultContext),
        ];

        return new Serializer($normalizers);
    }
}
