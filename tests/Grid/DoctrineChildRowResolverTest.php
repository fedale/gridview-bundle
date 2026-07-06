<?php

namespace Fedale\GridviewBundle\Tests\Grid;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ObjectRepository;
use Fedale\GridviewBundle\Grid\DoctrineChildRowResolver;
use Fedale\GridviewBundle\Grid\GroupingConfig;
use Fedale\GridviewBundle\Serializer\RowSerializerFactory;
use PHPUnit\Framework\TestCase;

class DoctrineChildRowResolverTest extends TestCase
{
    private function config(): GroupingConfig
    {
        return GroupingConfig::fromArray(
            ['enabled' => true, 'mode' => 'lazy', 'relation' => 'posts', 'columns' => []],
            'App\Entity\User',
        );
    }

    private function resolver(?object $parent): DoctrineChildRowResolver
    {
        $repository = $this->createMock(ObjectRepository::class);
        $repository->method('findOneBy')->willReturn($parent);

        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('getAssociationNames')->willReturn([]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);
        $em->method('getClassMetadata')->willReturn($metadata);

        return new DoctrineChildRowResolver($em, new RowSerializerFactory());
    }

    public function testResolveForParentReturnsOneRowPerRelatedRecord(): void
    {
        $posts = [
            new class {
                public function getId(): int { return 1; }
                public function getTitle(): string { return 'First'; }
            },
            new class {
                public function getId(): int { return 2; }
                public function getTitle(): string { return 'Second'; }
            },
        ];
        $parent = new class($posts) {
            public function __construct(private array $posts) {}
            public function getPosts(): array { return $this->posts; }
        };

        $rows = $this->resolver($parent)->resolveForParent(42, $this->config());

        $this->assertCount(2, $rows);
        $this->assertSame('First', $rows[0]->data['title']);
        $this->assertSame('Second', $rows[1]->data['title']);
        // Child ids are scoped by parent so they don't collide with parent row ids.
        $this->assertSame('child_42_0', $rows[0]->attr['id']);
        $this->assertSame('child_42_1', $rows[1]->attr['id']);
    }

    public function testResolveForParentReturnsEmptyWhenTheRelationHasNoRecords(): void
    {
        $parent = new class {
            public function getPosts(): array { return []; }
        };

        $rows = $this->resolver($parent)->resolveForParent(42, $this->config());

        $this->assertSame([], $rows);
    }

    public function testResolveForParentReturnsEmptyWhenTheParentIsNotFound(): void
    {
        $rows = $this->resolver(null)->resolveForParent(999, $this->config());

        $this->assertSame([], $rows);
    }

    public function testResolveForParentTruncatesToTheConfiguredLimit(): void
    {
        $posts = array_map(
            fn(int $i) => new class($i) {
                public function __construct(private int $id) {}
                public function getId(): int { return $this->id; }
                public function getTitle(): string { return "Post {$this->id}"; }
            },
            range(1, 5),
        );
        $parent = new class($posts) {
            public function __construct(private array $posts) {}
            public function getPosts(): array { return $this->posts; }
        };

        $config = GroupingConfig::fromArray(
            ['enabled' => true, 'mode' => 'lazy', 'relation' => 'posts', 'columns' => [], 'limit' => 2],
            'App\Entity\User',
        );

        $rows = $this->resolver($parent)->resolveForParent(42, $config);

        $this->assertCount(2, $rows);
    }
}
