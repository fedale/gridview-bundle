<?php

namespace Fedale\GridviewBundle\Serializer;

use Symfony\Component\Serializer\NameConverter\NameConverterInterface;

/**
 * Restores property names that ObjectNormalizer's accessor-prefix stripping
 * would otherwise mangle.
 *
 * ObjectNormalizer derives an attribute name from each getter, dropping the
 * `is`/`has`/`can` prefix: a boolean property `isVerified` read through its
 * `isVerified()` getter is serialized under the key `verified`. A grid column
 * is configured with the Doctrine field name (`isVerified`, the same string
 * used in DQL), so the mismatched key leaves the rendered cell empty.
 *
 * This converter maps such derived names back to the real property name, so
 * serialized row keys always match the entity's field names. It acts only when
 * the derived name has no property of its own but a single prefixed property
 * would produce it; every unambiguous name is left untouched.
 */
class AccessorPrefixNameConverter implements NameConverterInterface
{
    private const PREFIXES = ['is', 'has', 'can'];

    /** @var array<class-string, array<string, string>> */
    private array $mapCache = [];

    public function normalize(string $propertyName, ?string $class = null, ?string $format = null, array $context = []): string
    {
        if (null === $class) {
            return $propertyName;
        }

        return $this->map($class)[$propertyName] ?? $propertyName;
    }

    public function denormalize(string $propertyName, ?string $class = null, ?string $format = null, array $context = []): string
    {
        // The grid only ever normalizes; denormalizing the real name back to the
        // derived one keeps a round-trip through the serializer consistent.
        if (null === $class) {
            return $propertyName;
        }

        $realToDerived = array_flip($this->map($class));

        return $realToDerived[$propertyName] ?? $propertyName;
    }

    /**
     * Builds, per class, the map from each accessor-derived name to the real
     * property it came from, dropping ambiguous or already-matching names.
     *
     * @return array<string, string>
     */
    private function map(string $class): array
    {
        if (isset($this->mapCache[$class])) {
            return $this->mapCache[$class];
        }

        $properties = [];
        foreach ((new \ReflectionClass($class))->getProperties() as $property) {
            $properties[$property->getName()] = true;
        }

        $map = [];
        $ambiguous = [];
        foreach (array_keys($properties) as $name) {
            $derived = $this->derive($name);
            if (null === $derived || isset($properties[$derived])) {
                continue;
            }

            if (isset($map[$derived])) {
                $ambiguous[$derived] = true;

                continue;
            }

            $map[$derived] = $name;
        }

        foreach (array_keys($ambiguous) as $derived) {
            unset($map[$derived]);
        }

        return $this->mapCache[$class] = $map;
    }

    /** The name ObjectNormalizer would derive from a prefixed property, or null. */
    private function derive(string $name): ?string
    {
        foreach (self::PREFIXES as $prefix) {
            $length = \strlen($prefix);
            if (str_starts_with($name, $prefix) && \strlen($name) > $length && ctype_upper($name[$length])) {
                return lcfirst(substr($name, $length));
            }
        }

        return null;
    }
}
