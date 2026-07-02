<?php

namespace Fedale\GridviewBundle\Tests\Pagination;

use Fedale\GridviewBundle\Pagination\Pagination;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

class PaginationTest extends TestCase
{
    private function createPagination(Request $request): Pagination
    {
        $requestStack = new RequestStack();
        $requestStack->push($request);

        return new Pagination($requestStack);
    }

    public function testDefaultPageSizeIsUsedWhenNoQueryParam(): void
    {
        $pagination = $this->createPagination(Request::create('/customers'));
        $pagination->setAttributes(['defaultPageSize' => 25]);

        $this->assertSame(25, $pagination->getPageSize());
    }

    public function testRequestedPageSizeInOptionsIsHonoured(): void
    {
        $pagination = $this->createPagination(Request::create('/customers?per-page=50'));
        $pagination->setAttributes([
            'defaultPageSize' => 25,
            'pageSizeOptions' => [25, 50, 100],
        ]);

        $this->assertSame(50, $pagination->getPageSize());
    }

    public function testRequestedPageSizeOutsideOptionsFallsBackToDefault(): void
    {
        // 37 is not one of the offered sizes: ignore it, use the default.
        $pagination = $this->createPagination(Request::create('/customers?per-page=37'));
        $pagination->setAttributes([
            'defaultPageSize' => 25,
            'pageSizeOptions' => [25, 50, 100],
        ]);

        $this->assertSame(25, $pagination->getPageSize());
    }

    public function testWithoutOptionsAnyRequestedSizeIsClampedToMax(): void
    {
        // No pageSizeOptions: the legacy behaviour (clamp to maxPageSize) applies.
        $pagination = $this->createPagination(Request::create('/customers?per-page=999'));
        $pagination->setAttributes([
            'defaultPageSize' => 25,
            'maxPageSize'     => 100,
        ]);

        $this->assertSame(100, $pagination->getPageSize());
    }

    public function testGetPageSizeOptionsReturnsConfiguredList(): void
    {
        $pagination = $this->createPagination(Request::create('/customers'));
        $pagination->setAttributes(['pageSizeOptions' => [25, 50, 100]]);

        $this->assertSame([25, 50, 100], $pagination->getPageSizeOptions());
        $this->assertSame([], (new Pagination(new RequestStack()))->getPageSizeOptions());
    }
}
