<?php

namespace Fedale\GridviewBundle\Tests\Form;

use Fedale\GridviewBundle\Form\SearchForm;
use Fedale\GridviewBundle\Tests\Support\CreatesQueryBuilderTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\RequestStack;

class SearchFormApplyFiltersTest extends TestCase
{
    use CreatesQueryBuilderTrait;

    private SearchForm $searchForm;

    protected function setUp(): void
    {
        $this->searchForm = new SearchForm(Forms::createFormFactory(), new RequestStack());
    }

    public function testDispatchesEachMapEntryToItsApplier(): void
    {
        $qb = $this->createTestQueryBuilder();

        $this->searchForm->applyFilters($qb, ['code' => 'abc', 'active' => '1'], [
            'code'   => ['text', 'c.code'],
            'active' => ['boolean', 'c.active'],
        ]);

        $params = $qb->getParameters();
        $this->assertCount(2, $params);
        $this->assertNotSame($params[0]->getName(), $params[1]->getName());
        $this->assertSame(
            sprintf('LOWER(c.code) LIKE :%s AND c.active = :%s', $params[0]->getName(), $params[1]->getName()),
            $this->whereDql($qb)
        );
    }

    public function testMissingParamKeyProducesNoCondition(): void
    {
        $qb = $this->createTestQueryBuilder();

        $this->searchForm->applyFilters($qb, [], [
            'code' => ['text', 'c.code'],
        ]);

        $this->assertNull($qb->getDQLPart('where'));
    }

    public function testApplierOptionsArePassedThrough(): void
    {
        $qb = $this->createTestQueryBuilder();

        $this->searchForm->applyFilters($qb, ['createdAt' => ['to' => '2026-02-01']], [
            'createdAt' => ['date', 'c.createdAt', ['end_of_day' => false]],
        ]);

        $this->assertEquals(new \DateTime('2026-02-01 00:00:00'), $qb->getParameters()->first()->getValue());
    }

    public function testUnknownTypeThrows(): void
    {
        $qb = $this->createTestQueryBuilder();

        $this->expectException(\InvalidArgumentException::class);
        $this->searchForm->applyFilters($qb, ['x' => '1'], ['x' => ['nope', 'c.x']]);
    }
}
