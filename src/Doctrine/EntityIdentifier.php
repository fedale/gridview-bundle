<?php

namespace Fedale\GridviewBundle\Doctrine;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Turns an entity's primary key into something a URL can carry, and back.
 *
 * A grid links to records (edit, clone, delete, inline edit) and the browser
 * sends them back (the selection checkboxes), so every key makes a round trip
 * through a URL. An auto-increment int survives that trip by accident; a UUID
 * needs a route requirement that is not `\d+`; a composite key needs a format of
 * its own. All three go through here, so nothing else in the bundle has to know
 * which shape it is dealing with.
 *
 * A composite token joins its parts with `~`, in the order Doctrine reports the
 * key fields, each part URL-encoded — a literal `~` included — so no value can
 * split the token by carrying the separator.
 */
class EntityIdentifier
{
    /**
     * Between the parts of a composite token. Unreserved in a URL (RFC 3986), so
     * it survives a round trip unescaped and stays readable in a browser's
     * address bar; parts that contain one carry it encoded instead.
     */
    private const SEPARATOR = '~';

    /**
     * Reserved row key carrying the token alongside the record's own fields.
     *
     * A row cannot always be read back for its key: the serializer normalizes an
     * association that was not fetch-joined to null, on purpose (it refuses to
     * lazy-load a grid into an N+1), and a composite key made of one is then
     * unreadable. So the provider stamps the token it computed from the entity,
     * and anything working from row data reads it from here first.
     */
    public const ROW_KEY = '_gv_id';

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * The key fields, in Doctrine's own order — the order every token follows.
     *
     * @param class-string $class
     *
     * @return list<string>
     */
    public function fields(string $class): array
    {
        return array_values($this->entityManager->getClassMetadata($class)->getIdentifierFieldNames());
    }

    /** @param class-string $class */
    public function isComposite(string $class): bool
    {
        return \count($this->fields($class)) > 1;
    }

    /** The token identifying a managed entity. */
    public function fromEntity(object $entity): string
    {
        $meta = $this->entityManager->getClassMetadata($entity::class);

        $parts = [];
        foreach ($meta->getIdentifierValues($entity) as $value) {
            $parts[] = $this->stringify($value);
        }

        return $this->encode($parts);
    }

    /**
     * The token identifying a normalized row — the array shape a column callback
     * sees, where a relation is a nested array. Null when the row carries no
     * complete key, which is the case for a provider that is not Doctrine-backed.
     *
     * @param class-string         $class
     * @param array<string, mixed> $row
     */
    public function fromRow(string $class, array $row): ?string
    {
        $stamped = $row[self::ROW_KEY] ?? null;
        if (\is_string($stamped) && $stamped !== '') {
            return $stamped;
        }

        $meta = $this->entityManager->getClassMetadata($class);

        $parts = [];
        foreach ($this->fields($class) as $field) {
            if (!\array_key_exists($field, $row)) {
                return null;
            }

            $value = $row[$field];
            // A key field that is an association normalizes to the related row;
            // its own key is what identifies it.
            if (\is_array($value)) {
                $target = $meta->getAssociationTargetClass($field);
                $value = $value[$this->fields($target)[0]] ?? null;
            }
            if ($value === null || \is_array($value)) {
                return null;
            }

            $parts[] = $this->stringify($value);
        }

        return $parts === [] ? null : $this->encode($parts);
    }

    /**
     * What `EntityRepository::find()` expects for the given token: the bare value
     * for a single-field key, a field => value map for a composite one. Null when
     * the token does not describe this entity's key at all — the caller turns
     * that into a 404 rather than a query.
     *
     * @param class-string $class
     *
     * @return string|array<string, string>|null
     */
    public function criteria(string $class, string $token): string|array|null
    {
        $map = $this->criteriaMap($class, $token);
        if ($map === null) {
            return null;
        }

        return \count($map) === 1 ? reset($map) : $map;
    }

    /**
     * The same resolution as {@see criteria()}, always as a field => value map —
     * what a query builder needs to name the fields it compares.
     *
     * @param class-string $class
     *
     * @return array<string, string>|null
     */
    public function criteriaMap(string $class, string $token): ?array
    {
        $fields = $this->fields($class);
        $parts = $this->decode($token);

        if ($fields === [] || \count($parts) !== \count($fields)) {
            return null;
        }

        return array_combine($fields, $parts);
    }

    /**
     * @param list<string> $parts
     */
    private function encode(array $parts): string
    {
        // Encoding is symmetric with decode() and a no-op for the keys that
        // matter in practice — an int stays an int, a UUID stays a UUID — while a
        // string key carrying a reserved character still survives the round trip.
        return implode(self::SEPARATOR, array_map(
            // rawurlencode leaves `~` alone (it is unreserved), so a value
            // carrying one would split the token: encode it by hand.
            static fn(string $part): string => str_replace(self::SEPARATOR, '%7E', rawurlencode($part)),
            $parts,
        ));
    }

    /**
     * @return list<string>
     */
    private function decode(string $token): array
    {
        return array_map(rawurldecode(...), explode(self::SEPARATOR, $token));
    }

    /**
     * @param bool $nested true while resolving the key of an association, where
     *                     a second indirection would be a key made of keys —
     *                     out of scope, and the way to loop forever
     */
    private function stringify(mixed $value, bool $nested = false): string
    {
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        if (\is_scalar($value)) {
            return (string) $value;
        }

        // An association standing in for a key field: its own key identifies it,
        // and a single-field one is the only shape a token can carry here. This
        // comes BEFORE the stringable branch on purpose — an entity with a
        // __toString() would otherwise be addressed by its label.
        if (!$nested && \is_object($value) && !$this->entityManager->getMetadataFactory()->isTransient($value::class)) {
            $values = $this->entityManager->getClassMetadata($value::class)->getIdentifierValues($value);
            if (\count($values) === 1) {
                return $this->stringify(reset($values), true);
            }
        }

        // A value object standing for the key itself: a Uuid, a Ulid, a custom id.
        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        throw new \InvalidArgumentException(sprintf(
            'A grid identifier must be a scalar, a backed enum, a stringable object (such as a Uuid) or a to-one association with a single-field key; %s given. Map a key field of another type to something that reads as a string.',
            get_debug_type($value),
        ));
    }
}
