<?php

namespace Fedale\GridviewBundle\Tests\DataProvider;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr;
use Doctrine\ORM\QueryBuilder;
use Fedale\GridviewBundle\DataProvider\EntityDataProvider;
use Fedale\GridviewBundle\Tests\Support\RecordingRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

class EntityDataProviderDefaultsTest extends TestCase
{
    private RecordingRepository $repository;

    private function createProvider(Request $request): EntityDataProvider
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getExpressionBuilder')->willReturn(new Expr());

        $this->repository = new RecordingRepository(new QueryBuilder($em));
        $em->method('getRepository')->willReturn($this->repository);

        $requestStack = new RequestStack();
        $requestStack->push($request);

        return new EntityDataProvider(
            $this->createMock(EventDispatcherInterface::class),
            $em,
            $requestStack
        );
    }

    public function testDefaultsApplyWhenFormIsAbsentFromQueryString(): void
    {
        $provider = $this->createProvider(Request::create('/customers'));

        $provider->setDefaultParams(['active' => '1']);
        $provider->prepareModels('App\Entity\Customer');

        $this->assertSame(['active' => '1'], $this->repository->receivedParams);
    }

    public function testDefaultsDoNotApplyWhenFormWasSubmittedEmpty(): void
    {
        // A cleared filter submits an empty value: 'myform' is present
        $provider = $this->createProvider(Request::create('/customers?myform[active]='));

        $provider->setDefaultParams(['active' => '1']);
        $provider->prepareModels('App\Entity\Customer');

        $this->assertSame(['active' => ''], $this->repository->receivedParams);
    }

    public function testDefaultsDoNotOverrideSubmittedValues(): void
    {
        $provider = $this->createProvider(Request::create('/customers?myform[active]=0'));

        $provider->setDefaultParams(['active' => '1']);
        $provider->prepareModels('App\Entity\Customer');

        $this->assertSame(['active' => '0'], $this->repository->receivedParams);
    }

    public function testSortAndPageParamsAloneDoNotDisableDefaults(): void
    {
        // First-visit pagination/sort links carry no 'myform' key
        $provider = $this->createProvider(Request::create('/customers?sort=-id&page=2'));

        $provider->setDefaultParams(['active' => '1']);
        $provider->prepareModels('App\Entity\Customer');

        $this->assertSame(['active' => '1'], $this->repository->receivedParams);
    }

    public function testEmptyDefaultsAreANoOp(): void
    {
        $provider = $this->createProvider(Request::create('/customers'));

        $provider->setDefaultParams([]);
        $provider->prepareModels('App\Entity\Customer');

        $this->assertSame([], $this->repository->receivedParams);
    }
}
