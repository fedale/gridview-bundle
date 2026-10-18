<?php

namespace Fedale\GridviewBundle\Tests\DataProvider;

use Fedale\GridviewBundle\Contract\FilterCriteriaBuilderInterface;
use Fedale\GridviewBundle\Contract\SearchModelInterface;
use Fedale\GridviewBundle\DataProvider\DataSourceDataProvider;
use Fedale\GridviewBundle\DataProvider\DataSourceDataProviderFactory;
use Fedale\GridviewBundle\DataSource\FilterCriterion;
use Fedale\GridviewBundle\DataSource\FilterOperator;
use Fedale\GridviewBundle\DataSource\SortDirection;
use Fedale\GridviewBundle\DataSource\SortOrder;
use Fedale\GridviewBundle\Doctrine\EntityIdentifier;
use Fedale\GridviewBundle\Filter\Criteria\FilterCriteriaBuilderRegistry;
use Fedale\GridviewBundle\Pagination\Pagination;
use Fedale\GridviewBundle\Sort\Sort;
use Fedale\GridviewBundle\Tests\Fixtures\InMemoryGridDataSource;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;

class DataSourceDataProviderTest extends TestCase
{
    private InMemoryGridDataSource $source;

    private Pagination $pagination;

    private Sort $sort;

    private function provider(string $uri, array $builders = [], string|array $identifier = 'id'): DataSourceDataProvider
    {
        $stack = new RequestStack();
        $stack->push(Request::create($uri));

        $this->source = new InMemoryGridDataSource([
            ['id' => 'a1', 'name' => 'Rex', 'age' => 3],
            ['id' => 'b2', 'name' => 'Fido', 'age' => 7],
            ['id' => 'c3', 'name' => 'Rocky', 'age' => 5],
            ['id' => 'd4', 'name' => 'Luna', 'age' => 1],
            ['id' => 'e5', 'name' => 'Ruby', 'age' => 9],
        ]);
        $this->pagination = new Pagination($stack);
        $this->pagination->setAttributes(['defaultPageSize' => 2]);
        $this->sort = new Sort($stack, $this->createStub(RouterInterface::class));
        $this->sort->setAttributes(['name', 'age']);

        $factory = new DataSourceDataProviderFactory(
            new EventDispatcher(),
            $stack,
            new FilterCriteriaBuilderRegistry(),
            $this->sort,
            $this->pagination,
            $this->createStub(SearchModelInterface::class),
        );
        $provider = $factory->create($this->source, 'record_pet', $builders, $identifier);
        $provider->setFormName('fedaleForm');
        $provider->prepareModels(['object' => 'pet']);

        return $provider;
    }

    public function testSourceReceivesTheNormalizedQueryOfTheRequest(): void
    {
        $provider = $this->provider('/pets?fedaleForm[name]=r&fedaleForm[age]=>2&sort=-age&page=1');
        $provider->setFilterSpecs(['name' => ['text', 'name'], 'age' => ['number', 'age']]);
        $provider->applyGlobalSearch(['name'], 'R');

        $rows = $provider->getData();

        $query = $this->source->lastQuery('fetch');
        $this->assertSame('record_pet', $query->gridId);
        $this->assertSame(['object' => 'pet'], $query->source);
        $this->assertEquals([
            new FilterCriterion('name', FilterOperator::Contains, 'r', true),
            new FilterCriterion('age', FilterOperator::Gt, 2),
        ], $query->criteria);
        $this->assertSame('R', $query->searchTerm);
        $this->assertSame(['name'], $query->searchFields);
        $this->assertEquals([new SortOrder('age', SortDirection::Desc)], $query->sort);
        $this->assertSame([0, 2], [$query->offset, $query->limit]);
        $this->assertSame(['Ruby', 'Rocky'], array_map(fn($row) => $row->data['name'], $rows->toArray()));
    }

    public function testFactoryWiresTheSharedSortAndPagination(): void
    {
        $provider = $this->provider('/pets');

        $this->assertSame($this->sort, $provider->getSort());
        $this->assertSame($this->pagination, $provider->getPagination());
    }

    public function testCountRunsBeforeTheWindowIsRead(): void
    {
        $provider = $this->provider('/pets?page=2');

        $provider->getData();

        $this->assertSame(['count', 'fetch'], array_column($this->source->calls, 0));
        $this->assertNull($this->source->calls[0][1]->limit, 'count() gets no window');
        $this->assertSame([2, 2], [$this->source->calls[1][1]->offset, $this->source->calls[1][1]->limit]);
        $this->assertSame(5, $this->pagination->getTotalCount());
    }

    public function testAPagePastTheEndClampsToTheLastPage(): void
    {
        $provider = $this->provider('/pets?page=99');

        $rows = $provider->getData();

        $this->assertSame(4, $this->source->lastQuery('fetch')->offset);
        $this->assertCount(1, $rows);
    }

    public function testDefaultsApplyOnlyWhenTheFormIsAbsent(): void
    {
        $provider = $this->provider('/pets');
        $provider->setDefaultParams(['name' => 'eq Luna']);
        $provider->setFilterSpecs(['name' => ['text', 'name']]);
        $provider->getData();
        $this->assertEquals([new FilterCriterion('name', FilterOperator::Eq, 'Luna')], $this->source->lastQuery('fetch')->criteria);

        // A submitted form with the filter cleared: the defaults stay out.
        $provider = $this->provider('/pets?fedaleForm[name]=');
        $provider->setDefaultParams(['name' => 'eq Luna']);
        $provider->setFilterSpecs(['name' => ['text', 'name']]);
        $provider->getData();
        $this->assertSame([], $this->source->lastQuery('fetch')->criteria);
    }

    public function testExportFetchesEveryRowWithTheSameQuery(): void
    {
        $provider = $this->provider('/pets?fedaleForm[age]=>2&sort=name&page=2');
        $provider->setFilterSpecs(['age' => ['number', 'age']]);

        $rows = $provider->getAllData();

        $query = $this->source->lastQuery('fetch');
        $this->assertSame([0, null], [$query->offset, $query->limit]);
        $this->assertEquals([new FilterCriterion('age', FilterOperator::Gt, 2)], $query->criteria);
        $this->assertSame(['Fido', 'Rex', 'Rocky', 'Ruby'], array_map(fn($row) => $row->data['name'], $rows->toArray()));
    }

    public function testRowsCarryTheIdentifierToken(): void
    {
        $rows = $this->provider('/pets')->getData();

        $this->assertSame('a1', $rows[0]->identifierToken);
        $this->assertSame('a1', $rows[0]->data[EntityIdentifier::ROW_KEY]);
    }

    public function testCompositeIdentifierUsesTheEntityTokenFormat(): void
    {
        $rows = $this->provider('/pets', [], ['id', 'name'])->getData();

        $this->assertSame('a1~Rex', $rows[0]->identifierToken);
    }

    public function testRowWithoutItsKeyHasNoToken(): void
    {
        $rows = $this->provider('/pets', [], 'uuid')->getData();

        $this->assertNull($rows[0]->identifierToken);
        $this->assertArrayNotHasKey(EntityIdentifier::ROW_KEY, $rows[0]->data);
    }

    public function testExplicitSearchMapEntryGivesAColumnFilterItsOptions(): void
    {
        $provider = $this->provider('/pets?fedaleForm[name]=Rex');
        $provider->setSearchFields(['name' => ['text', 'name', ['default_operator' => 'eq']]]);
        $provider->setFilterSpecs(['name' => ['text', 'name']]);

        $provider->getData();

        $this->assertEquals([new FilterCriterion('name', FilterOperator::Eq, 'Rex')], $this->source->lastQuery('fetch')->criteria);
    }

    public function testPerGridBuilderIsUsedBeforeTheRegistry(): void
    {
        $builder = new class implements FilterCriteriaBuilderInterface {
            public function build(string $attribute, mixed $rawValue, array $options = []): array
            {
                return $rawValue === null ? [] : [new FilterCriterion($attribute, FilterOperator::Eq, 'custom:' . $rawValue)];
            }
        };
        $provider = $this->provider('/pets?fedaleForm[name]=x', ['text' => $builder]);
        $provider->setFilterSpecs(['name' => ['text', 'name']]);

        $provider->getData();

        $this->assertEquals([new FilterCriterion('name', FilterOperator::Eq, 'custom:x')], $this->source->lastQuery('fetch')->criteria);
    }

    public function testAFilterTypeWithNoBuilderFailsWhenTheFiltersAreSet(): void
    {
        $provider = $this->provider('/pets');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Gridview "record_pet": filter "colour" has type "palette", for which no criteria builder exists.');

        $provider->setFilterSpecs(['colour' => ['palette', 'colour']]);
    }

    public function testDebugQueryDescribesTheLastQuery(): void
    {
        $provider = $this->provider('/pets?fedaleForm[name]=Rex&sort=name');
        $provider->setFilterSpecs(['name' => ['text', 'name']]);
        $provider->getData();

        $debug = $provider->getDebugQuery()['dql'];

        $this->assertStringContainsString('where name contains (ci) "Rex"', $debug);
        $this->assertStringContainsString('sort name asc', $debug);
        $this->assertStringContainsString('window offset 0, limit 2', $debug);
        $this->assertSame('dataSource', $provider->getFilterPath());
    }
}
