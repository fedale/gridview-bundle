<?php

namespace Fedale\GridviewBundle\Tests\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Fedale\GridviewBundle\Doctrine\EntityIdentifier;
use PHPUnit\Framework\TestCase;

/**
 * The round trip a record's key makes through a URL: an auto-increment int, a
 * stringable key such as a UUID, and a composite key all have to come back as
 * something `find()` accepts.
 */
class EntityIdentifierTest extends TestCase
{
    /**
     * Stand-ins for the entity classes: the metadata map is keyed by class name
     * and `fromEntity()` looks its argument up by `::class`, so these have to be
     * classes that exist — what they are is beside the point.
     */
    private const RECORD = \stdClass::class;
    private const POST = \ArrayObject::class;

    /**
     * @param array<class-string|string, array{fields: list<string>, values?: array<string, mixed>, associations?: array<string, string>}> $classes
     */
    private function identifier(array $classes): EntityIdentifier
    {
        $em = $this->createMock(EntityManagerInterface::class);

        // Everything the map knows is an entity; anything else is transient, the
        // way a value object (a Uuid) is.
        $factory = $this->createMock(ClassMetadataFactory::class);
        $factory->method('isTransient')->willReturnCallback(
            static fn(string $class): bool => !isset($classes[$class])
        );
        $em->method('getMetadataFactory')->willReturn($factory);

        $em->method('getClassMetadata')->willReturnCallback(
            function (string $class) use ($classes): ClassMetadata {
                $spec = $classes[$class] ?? ['fields' => []];

                $meta = $this->createMock(ClassMetadata::class);
                $meta->method('getIdentifierFieldNames')->willReturn($spec['fields']);
                $meta->method('getIdentifierValues')->willReturn($spec['values'] ?? []);
                $meta->method('getAssociationTargetClass')->willReturnCallback(
                    static fn(string $field): string => ($spec['associations'] ?? [])[$field] ?? 'App\\Entity\\Unknown'
                );

                return $meta;
            }
        );

        return new EntityIdentifier($em);
    }

    public function testASingleKeyTravelsAsItself(): void
    {
        $identifier = $this->identifier([self::RECORD => ['fields' => ['id'], 'values' => ['id' => 42]]]);

        $this->assertSame('42', $identifier->fromEntity(new \stdClass()));
        $this->assertSame('42', $identifier->criteria(self::RECORD, '42'));
        $this->assertFalse($identifier->isComposite(self::RECORD));
    }

    public function testAStringableKeySuchAsAUuidTravelsAsItsString(): void
    {
        $uuid = new class implements \Stringable {
            public function __toString(): string
            {
                return '0192c1a0-4c3a-7b3e-9f1a-2b3c4d5e6f70';
            }
        };
        $identifier = $this->identifier([self::RECORD => ['fields' => ['id'], 'values' => ['id' => $uuid]]]);

        $this->assertSame((string) $uuid, $identifier->fromEntity(new \stdClass()));
        $this->assertSame((string) $uuid, $identifier->criteria(self::RECORD, (string) $uuid));
    }

    public function testACompositeKeyTravelsAsOneTokenAndComesBackAsAMap(): void
    {
        $identifier = $this->identifier([
            self::RECORD => ['fields' => ['post', 'locale'], 'values' => ['post' => 7, 'locale' => 'it']],
        ]);

        $this->assertSame('7~it', $identifier->fromEntity(new \stdClass()));
        $this->assertTrue($identifier->isComposite(self::RECORD));
        $this->assertSame(['post' => '7', 'locale' => 'it'], $identifier->criteria(self::RECORD, '7~it'));
    }

    public function testASeparatorInsideAValueCannotSplitTheToken(): void
    {
        $identifier = $this->identifier([
            self::RECORD => ['fields' => ['code', 'locale'], 'values' => ['code' => 'a~b', 'locale' => 'it']],
        ]);

        $token = $identifier->fromEntity(new \stdClass());

        $this->assertSame('a%7Eb~it', $token);
        $this->assertSame(['code' => 'a~b', 'locale' => 'it'], $identifier->criteria(self::RECORD, $token));
    }

    public function testATokenThatDoesNotMatchTheKeyIsRejected(): void
    {
        $identifier = $this->identifier([self::RECORD => ['fields' => ['post', 'locale']]]);

        // Too few parts for a two-field key, then too many: a 404, not a query.
        $this->assertNull($identifier->criteria(self::RECORD, '7'));
        $this->assertNull($identifier->criteriaMap(self::RECORD, '7~it~extra'));
    }

    public function testCriteriaMapAlwaysNamesItsFields(): void
    {
        $identifier = $this->identifier([self::RECORD => ['fields' => ['id']]]);

        $this->assertSame(['id' => '42'], $identifier->criteriaMap(self::RECORD, '42'));
    }

    public function testAStampedTokenWinsOverReadingTheRow(): void
    {
        // The provider stamps the token it computed from the entity: an
        // association that was not fetch-joined normalizes to null, and a key
        // made of one would be unreadable from the row alone.
        $identifier = $this->identifier([self::RECORD => ['fields' => ['post', 'locale']]]);

        $this->assertSame(
            '7~it',
            $identifier->fromRow(self::RECORD, [EntityIdentifier::ROW_KEY => '7~it', 'post' => null, 'locale' => 'it'])
        );
    }

    public function testARowIsReadByItsKeyFields(): void
    {
        $identifier = $this->identifier([self::RECORD => ['fields' => ['post', 'locale']]]);

        $this->assertSame(
            '7~it',
            $identifier->fromRow(self::RECORD, ['post' => 7, 'locale' => 'it', 'title' => 'Hello'])
        );
    }

    public function testARowWhoseKeyFieldIsARelationUsesTheRelatedKey(): void
    {
        // The related record is nested in the row, as the serializer leaves it.
        $identifier = $this->identifier([
            self::RECORD => ['fields' => ['post', 'locale'], 'associations' => ['post' => self::POST]],
            self::POST => ['fields' => ['id']],
        ]);

        $this->assertSame(
            '7~it',
            $identifier->fromRow(self::RECORD, ['post' => ['id' => 7, 'title' => 'Hello'], 'locale' => 'it'])
        );
    }

    public function testAnEntityWhoseKeyFieldIsARelationUsesTheRelatedKey(): void
    {
        $identifier = $this->identifier([
            self::RECORD => ['fields' => ['post', 'locale'], 'values' => ['post' => new \ArrayObject(), 'locale' => 'it']],
            self::POST => ['fields' => ['id'], 'values' => ['id' => 7]],
        ]);

        $this->assertSame('7~it', $identifier->fromEntity(new \stdClass()));
    }

    public function testAnEntityKeyFieldIsReadByItsKeyNotItsLabel(): void
    {
        // A mapped entity with a __toString() — a Post whose label is its title —
        // must still be addressed by its key: the label is neither unique nor
        // something find() could resolve.
        $post = new class implements \Stringable {
            public function __toString(): string
            {
                return 'How to Build Scalable Web Applications';
            }
        };

        $identifier = $this->identifier([
            self::RECORD => ['fields' => ['post', 'locale'], 'values' => ['post' => $post, 'locale' => 'it']],
            $post::class => ['fields' => ['id'], 'values' => ['id' => 7]],
        ]);

        $this->assertSame('7~it', $identifier->fromEntity(new \stdClass()));
    }

    public function testARowWithoutTheKeyYieldsNothing(): void
    {
        $identifier = $this->identifier([self::RECORD => ['fields' => ['id']]]);

        $this->assertNull($identifier->fromRow(self::RECORD, ['title' => 'Hello']));
    }

    public function testAnUnusableKeyValueIsReported(): void
    {
        $identifier = $this->identifier([
            self::RECORD => ['fields' => ['day'], 'values' => ['day' => new \DateTimeImmutable('2026-01-01')]],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a scalar');

        $identifier->fromEntity(new \stdClass());
    }
}
