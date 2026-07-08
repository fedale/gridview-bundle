<?php

namespace Fedale\GridviewBundle\Tests\DataProvider;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr;
use Doctrine\ORM\QueryBuilder;
use Fedale\GridviewBundle\Contract\AggregatableInterface;
use Fedale\GridviewBundle\DataProvider\EntityDataProvider;
use Fedale\GridviewBundle\Serializer\RowSerializerFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

class EntityDataProviderAggregateTest extends TestCase
{
    private function createProvider(): EntityDataProvider
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getExpressionBuilder')->willReturn(new Expr());

        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/customers'));

        $provider = new EntityDataProvider(
            $this->createMock(EventDispatcherInterface::class),
            $em,
            $requestStack,
            new RowSerializerFactory()
        );
        $provider->setQueryBuilder(
            (new QueryBuilder($em))->select('e')->from('App\Entity\Order', 'e')
        );

        return $provider;
    }

    public function testProviderIsAggregatable(): void
    {
        $this->assertInstanceOf(AggregatableInterface::class, $this->createProvider());
    }

    public function testRootAliasComesFromTheQuery(): void
    {
        $this->assertSame('e', $this->createProvider()->getRootAlias());
    }

    public function testEmptyExpressionsSkipTheQuery(): void
    {
        $this->assertSame([], $this->createProvider()->aggregate([]));
    }

    public function testAggregateDoesNotMutateTheListQuery(): void
    {
        $provider = $this->createProvider();
        $provider->setQueryBuilder(
            $qb = (new QueryBuilder($this->createMock(EntityManagerInterface::class)))
                ->select('e')
                ->from('App\Entity\Order', 'e')
                ->setMaxResults(20)
                ->orderBy('e.id', 'ASC')
        );

        // Empty batch is a no-op, but must never touch the shared list query.
        $provider->aggregate([]);

        $this->assertSame(20, $qb->getMaxResults());
        $this->assertSame('e', $qb->getRootAliases()[0]);
    }
}
