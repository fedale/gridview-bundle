<?php

namespace Fedale\GridviewBundle\Tests\Serializer;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\ORM\PersistentCollection;
use Doctrine\Persistence\Proxy;
use Fedale\GridviewBundle\Serializer\LazyAwareObjectNormalizer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Serializer;

class LazyAwareObjectNormalizerTest extends TestCase
{
    private function normalizer(): LazyAwareObjectNormalizer
    {
        // Treat NormalizableModel as a mapped entity whose 'collection' and
        // 'proxy' properties are Doctrine associations, so the guard registers
        // a callback for them (associations are read from the metadata).
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('getAssociationNames')->willReturn(['collection', 'proxy', 'ghost']);

        $metadataFactory = $this->createMock(ClassMetadataFactory::class);
        $metadataFactory->method('isTransient')->willReturn(false);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getMetadataFactory')->willReturn($metadataFactory);
        $em->method('getClassMetadata')->willReturn($metadata);
        // ORM 3 lazy ghosts are not Proxy instances; the guard resolves the
        // initialization state through the EntityManager, which also reports
        // classic proxies as uninitialized.
        $em->method('isUninitializedObject')->willReturnCallback(
            static fn (object $value): bool => $value instanceof LazyGhostStub
                || ($value instanceof Proxy && !$value->__isInitialized())
        );

        $normalizer = new LazyAwareObjectNormalizer($em);
        // ObjectNormalizer needs a serializer to recurse into nested objects/collections.
        new Serializer([$normalizer]);

        return $normalizer;
    }

    private function persistentCollection(bool $initialized): PersistentCollection
    {
        $collection = new PersistentCollection(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(ClassMetadata::class),
            new ArrayCollection()
        );
        $collection->setInitialized($initialized);

        return $collection;
    }

    public function testUninitializedCollectionIsSkipped(): void
    {
        $entity = new NormalizableModel();
        $entity->setCollection($this->persistentCollection(false));

        $data = $this->normalizer()->normalize($entity);

        $this->assertSame('foo', $data['name']);
        $this->assertNull($data['collection'], 'Uninitialized relation must not be loaded.');
    }

    public function testInitializedCollectionIsKept(): void
    {
        $entity = new NormalizableModel();
        $entity->setCollection($this->persistentCollection(true));

        $data = $this->normalizer()->normalize($entity);

        $this->assertSame([], $data['collection'], 'Already-loaded relation must be serialized.');
    }

    public function testIgnoredAttributeIsNotReadAtAll(): void
    {
        $entity = new NormalizableModel();

        $data = $this->normalizer()->normalize($entity, null, [
            \Symfony\Component\Serializer\Normalizer\AbstractNormalizer::IGNORED_ATTRIBUTES => ['eager'],
        ]);

        $this->assertArrayNotHasKey('eager', $data);
        $this->assertFalse($entity->eagerRead, 'Ignored attribute getter must never be called.');
        $this->assertSame('foo', $data['name']);
    }

    public function testUninitializedProxyIsSkipped(): void
    {
        $proxy = new class implements Proxy {
            public function __load(): void
            {
            }

            public function __isInitialized(): bool
            {
                return false;
            }
        };

        $entity = new NormalizableModel();
        $entity->setProxy($proxy);

        $data = $this->normalizer()->normalize($entity);

        $this->assertNull($data['proxy'], 'Uninitialized proxy must not be loaded.');
    }

    public function testUninitializedLazyGhostIsSkipped(): void
    {
        // ORM 3 hydrates an uninitialized to-one as a lazy ghost: a real
        // instance of the entity class that is NOT a Proxy. The guard must
        // still skip it, resolving its state through the EntityManager.
        $entity = new NormalizableModel();
        $entity->setGhost(new LazyGhostStub());

        $data = $this->normalizer()->normalize($entity);

        $this->assertNull($data['ghost'], 'Uninitialized lazy ghost must not be loaded.');
    }
}

/** A stand-in for an uninitialized ORM 3 lazy ghost: a plain, non-Proxy object. */
class LazyGhostStub
{
    public bool $loaded = false;

    public function getLoaded(): bool
    {
        // Reading any getter would initialize a real lazy ghost; if the guard
        // fails and the serializer walks into this, the flag flips.
        $this->loaded = true;

        return $this->loaded;
    }
}

class NormalizableModel
{
    private string $name = 'foo';

    private mixed $collection = null;

    private mixed $proxy = null;

    private mixed $ghost = null;

    public function getName(): string
    {
        return $this->name;
    }

    public function getCollection(): mixed
    {
        return $this->collection;
    }

    public function setCollection(mixed $collection): void
    {
        $this->collection = $collection;
    }

    public function getProxy(): mixed
    {
        return $this->proxy;
    }

    public bool $eagerRead = false;

    public function getEager(): string
    {
        // Stands in for a getter that triggers Doctrine lazy-loading
        // (e.g. UserInterface::getRoles() calling ->toArray()): reading it
        // has a side effect we want IGNORED_ATTRIBUTES to avoid entirely.
        $this->eagerRead = true;

        return 'eager';
    }

    public function setProxy(mixed $proxy): void
    {
        $this->proxy = $proxy;
    }

    public function getGhost(): mixed
    {
        return $this->ghost;
    }

    public function setGhost(mixed $ghost): void
    {
        $this->ghost = $ghost;
    }
}
