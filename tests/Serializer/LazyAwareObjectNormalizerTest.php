<?php

namespace Fedale\GridviewBundle\Tests\Serializer;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\PersistentCollection;
use Doctrine\Persistence\Proxy;
use Fedale\GridviewBundle\Serializer\LazyAwareObjectNormalizer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Serializer;

class LazyAwareObjectNormalizerTest extends TestCase
{
    private function normalizer(): LazyAwareObjectNormalizer
    {
        $normalizer = new LazyAwareObjectNormalizer();
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
}

class NormalizableModel
{
    private string $name = 'foo';

    private mixed $collection = null;

    private mixed $proxy = null;

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
}
