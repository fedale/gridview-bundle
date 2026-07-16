<?php

namespace Fedale\GridviewBundle\Tests\DataProvider;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Fedale\GridviewBundle\DataProvider\EntityDataProvider;
use Fedale\GridviewBundle\Serializer\RowSerializerFactory;
use Fedale\GridviewBundle\Tests\Support\PlainRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

class EntityDataProviderEagerTest extends TestCase
{
    private function createProvider(EntityManagerInterface $em): EntityDataProvider
    {
        $queryBuilder = (new QueryBuilder($em))->select('e')->from('App\Entity\Post', 'e');
        $em->method('getRepository')->willReturn(new PlainRepository($queryBuilder));

        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/posts'));

        return new EntityDataProvider(
            $this->createMock(EventDispatcherInterface::class),
            $em,
            $requestStack,
            new RowSerializerFactory($em)
        );
    }

    public function testEagerRelationsAreFetchJoined(): void
    {
        $provider = $this->createProvider($this->createMock(EntityManagerInterface::class));
        $provider->setEagerRelations(['author', 'category']);
        $provider->prepareModels('App\Entity\Post');

        $dql = $provider->getDebugQuery()['dql'];

        $this->assertStringContainsString('LEFT JOIN e.author gv_author', $dql);
        $this->assertStringContainsString('LEFT JOIN e.category gv_category', $dql);
        // addSelect() keeps the joined relations in the hydration, avoiding a
        // lazy load per row when a column reads them.
        $this->assertStringContainsString('gv_author', explode('FROM', $dql)[0]);
    }

    public function testNoEagerRelationsLeavesQueryUnjoined(): void
    {
        $provider = $this->createProvider($this->createMock(EntityManagerInterface::class));
        $provider->prepareModels('App\Entity\Post');

        $this->assertStringNotContainsString('LEFT JOIN', $provider->getDebugQuery()['dql']);
    }
}
