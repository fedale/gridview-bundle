<?php

namespace Fedale\GridviewBundle\Tests\Grid;

use Fedale\GridviewBundle\Pagination\Pagination;
use Fedale\GridviewBundle\Sort\Sort;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;

/**
 * The services that decide *which rows* the grid shows, driven through two
 * requests the way a worker runtime drives them.
 *
 * Sort and Pagination both used to capture the Request in their constructor.
 * That is correct under PHP-FPM, where the container dies with the request,
 * and wrong under FrankenPHP worker mode, RoadRunner or Swoole, where the
 * container outlives it: every later request was then sorted and paged by the
 * query string of whichever request happened to build the container.
 *
 * The failure reads as a data bug rather than a caching one — the result count
 * is recomputed per request and stays correct while the rows do not — so these
 * tests assert the values actually read from the second request.
 */
class WorkerStateResetTest extends TestCase
{
    private function router(): RouterInterface
    {
        return $this->createStub(RouterInterface::class);
    }

    /**
     * A stack holding one request, as RequestStack looks mid-request.
     */
    private function stackFor(string $uri): RequestStack
    {
        $stack = new RequestStack();
        $stack->push(Request::create($uri));

        return $stack;
    }

    public function testSortFollowsTheRequestBeingServed(): void
    {
        $stack = new RequestStack();
        $sort = new Sort($stack, $this->router());
        $sort->setAttributes([
            'name' => ['asc' => ['name' => 'ASC'], 'desc' => ['name' => 'DESC']],
            'year' => ['asc' => ['year' => 'ASC'], 'desc' => ['year' => 'DESC']],
        ]);

        $stack->push(Request::create('/grid?sort=name'));
        $this->assertSame(['name' => 'ASC'], $sort->fetchOrders());

        // What the worker does between two requests.
        $stack->pop();
        $sort->reset();
        $stack->push(Request::create('/grid?sort=-year'));

        // Re-declared per render, as Gridview does.
        $sort->setAttributes([
            'name' => ['asc' => ['name' => 'ASC'], 'desc' => ['name' => 'DESC']],
            'year' => ['asc' => ['year' => 'ASC'], 'desc' => ['year' => 'DESC']],
        ]);

        $this->assertSame(
            ['year' => 'DESC'],
            $sort->fetchOrders(),
            'the grid kept sorting by the previous request\'s column',
        );
    }

    /**
     * The page number is the clearest case: getCurrentPage() memoises it behind
     * an isset() guard, so without reset() the second request is served the
     * first one's page however its query string reads.
     */
    public function testPaginationFollowsTheRequestBeingServed(): void
    {
        $stack = new RequestStack();
        $pagination = new Pagination($stack);

        $stack->push(Request::create('/grid?page=3'));
        $pagination->setTotalCount(500);
        $this->assertSame(2, $pagination->getCurrentPage(), 'page is zero-based internally');

        $stack->pop();
        $pagination->reset();
        $stack->push(Request::create('/grid?page=1'));
        $pagination->setTotalCount(500);

        $this->assertSame(
            0,
            $pagination->getCurrentPage(),
            'the grid stayed on the page requested by the previous request',
        );
    }

    /**
     * Without the reset the stale page survives, which is what makes the test
     * above meaningful rather than a tautology about a fresh object.
     */
    public function testPaginationWithoutResetKeepsTheStalePage(): void
    {
        $stack = new RequestStack();
        $pagination = new Pagination($stack);

        $stack->push(Request::create('/grid?page=3'));
        $pagination->setTotalCount(500);
        $pagination->getCurrentPage();

        $stack->pop();
        $stack->push(Request::create('/grid?page=1'));

        $this->assertSame(
            2,
            $pagination->getCurrentPage(),
            'this pins the bug: the memoised page is only dropped by reset()',
        );
    }

    /**
     * reset() puts the paging options back to their declared defaults, so a
     * second grid in the same worker does not inherit the first grid's page
     * size.
     */
    public function testResetRestoresTheDeclaredPagingDefaults(): void
    {
        $stack = $this->stackFor('/grid');
        $pagination = new Pagination($stack);

        $pagination->setAttributes(['defaultPageSize' => 100, 'maxPageSize' => 200]);
        $this->assertSame(100, $pagination->getPageSize());

        $pagination->reset();

        $this->assertSame(20, $pagination->getPageSize(), 'the bundle default');
    }

    /**
     * Sort::reset() drops the attribute map too: it is declared per grid from
     * `sort.map`, so keeping it would let one grid sort by another's columns.
     */
    public function testResetDropsTheSortAttributes(): void
    {
        $sort = new Sort($this->stackFor('/grid?sort=name'), $this->router());
        $sort->setAttributes(['name' => ['asc' => ['name' => 'ASC'], 'desc' => ['name' => 'DESC']]]);

        $this->assertTrue($sort->hasAttribute('name'));

        $sort->reset();

        $this->assertFalse($sort->hasAttribute('name'));
        $this->assertSame([], $sort->fetchOrders());
    }

    /**
     * Neither service may touch the stack at construction time: that is the
     * moment the container is built, which under a worker is one arbitrary
     * request for the life of the process.
     */
    public function testNeitherServiceReadsTheRequestAtConstructionTime(): void
    {
        $empty = new RequestStack();

        $sort = new Sort($empty, $this->router());
        $pagination = new Pagination($empty);

        // Built with nothing on the stack, they still serve a request pushed later.
        $empty->push(Request::create('/grid?page=2'));

        $pagination->setTotalCount(100);
        $this->assertSame(1, $pagination->getCurrentPage());

        $sort->setAttributes(['name' => ['asc' => ['name' => 'ASC'], 'desc' => ['name' => 'DESC']]]);
        $this->assertSame([], $sort->fetchOrders(), 'no sort param on this request');
    }
}
