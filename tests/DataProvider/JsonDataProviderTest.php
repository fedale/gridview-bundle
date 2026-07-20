<?php

namespace Fedale\GridviewBundle\Tests\DataProvider;

use Fedale\GridviewBundle\Contract\PaginationInterface;
use Fedale\GridviewBundle\Contract\SortInterface;
use Fedale\GridviewBundle\DataProvider\JsonDataProvider;
use Fedale\GridviewBundle\Row\Row;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class JsonDataProviderTest extends TestCase
{
    /** @var list<array{method: string, url: string, options: array}> */
    private array $requests = [];

    private function client(array ...$responses): HttpClientInterface
    {
        $bodies = array_map(static fn (array $body): string => json_encode($body), $responses);
        $i = 0;

        return new MockHttpClient(function (string $method, string $url, array $options) use (&$i, $bodies): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];
            $body = $bodies[min($i, count($bodies) - 1)];
            ++$i;

            return new MockResponse($body);
        });
    }

    private function provider(HttpClientInterface $client, array $orders = [], int $pageSize = 10, int $offset = 0): JsonDataProvider
    {
        $sort = $this->createMock(SortInterface::class);
        $sort->method('fetchOrders')->willReturn($orders);

        $pagination = $this->createMock(PaginationInterface::class);
        $pagination->method('getPageSize')->willReturn($pageSize);
        $pagination->method('getOffset')->willReturn($offset);
        $pagination->method('setTotalCount')->willReturnSelf();

        $provider = new JsonDataProvider($client, $this->createMock(EventDispatcherInterface::class));
        $provider->setSort($sort);
        $provider->setPagination($pagination);

        return $provider;
    }

    public function testServerSidePaginationSendsLimitOffsetAndReadsListAndTotal(): void
    {
        $client = $this->client(
            ['products' => [], 'total' => 42],
            ['products' => [['id' => 1, 'name' => 'Widget']], 'total' => 42],
        );

        $pagination = $this->createMock(PaginationInterface::class);
        $pagination->method('getPageSize')->willReturn(10);
        $pagination->method('getOffset')->willReturn(20);
        $pagination->expects($this->once())->method('setTotalCount')->with(42)->willReturnSelf();

        $sort = $this->createMock(SortInterface::class);
        $sort->method('fetchOrders')->willReturn([]);

        $provider = new JsonDataProvider($client, $this->createMock(EventDispatcherInterface::class));
        $provider->setSort($sort);
        $provider->setPagination($pagination);
        $provider->prepareModels([
            'baseUri' => 'https://api.example.test',
            'resource' => 'products',
            'listPath' => 'products',
            'totalPath' => 'total',
        ]);

        $rows = $provider->getData();

        // Probe request first (limit=1), then the page request (limit=10&skip=20).
        $this->assertStringContainsString('limit=1', $this->requests[0]['url']);
        $this->assertStringContainsString('https://api.example.test/products', $this->requests[1]['url']);
        $this->assertStringContainsString('limit=10', $this->requests[1]['url']);
        $this->assertStringContainsString('skip=20', $this->requests[1]['url']);

        $this->assertCount(1, $rows);
        $this->assertSame(['id' => 1, 'name' => 'Widget'], $rows->first()->data);
    }

    public function testAuthorizationHeaderIsSentOnEveryRequest(): void
    {
        $client = $this->client(['products' => [], 'total' => 0], ['products' => [], 'total' => 0]);

        $provider = $this->provider($client);
        $provider->prepareModels([
            'baseUri' => 'https://api.example.test',
            'resource' => 'products',
            'listPath' => 'products',
            'totalPath' => 'total',
            'headers' => ['Authorization' => 'Bearer test-token'],
        ]);

        $provider->getData();

        foreach ($this->requests as $request) {
            $this->assertContains('Authorization: Bearer test-token', $request['options']['headers']);
        }
    }

    public function testGlobalSearchSwitchesResourceAndAddsSearchParam(): void
    {
        $client = $this->client(['products' => [], 'total' => 0], ['products' => [], 'total' => 0]);

        $provider = $this->provider($client);
        $provider->prepareModels([
            'baseUri' => 'https://api.example.test',
            'resource' => 'products',
            'searchResource' => 'products/search',
            'listPath' => 'products',
            'totalPath' => 'total',
        ]);
        $provider->applyGlobalSearch(['name'], 'widget');

        $provider->getData();

        $this->assertStringContainsString('https://api.example.test/products/search', $this->requests[1]['url']);
        $this->assertStringContainsString('q=widget', $this->requests[1]['url']);
    }

    public function testSortIsPushedToSortByAndOrderParams(): void
    {
        $client = $this->client(['products' => [], 'total' => 0], ['products' => [], 'total' => 0]);

        $provider = $this->provider($client, ['name' => 'desc']);
        $provider->prepareModels([
            'baseUri' => 'https://api.example.test',
            'resource' => 'products',
            'listPath' => 'products',
            'totalPath' => 'total',
        ]);

        $provider->getData();

        $this->assertStringContainsString('sortBy=name', $this->requests[1]['url']);
        $this->assertStringContainsString('order=desc', $this->requests[1]['url']);
    }

    public function testClientSidePaginationSlicesPlainArrayInMemory(): void
    {
        // No totalPath and body is a bare JSON array: one fetch, page in memory.
        $all = [['id' => 1], ['id' => 2], ['id' => 3], ['id' => 4], ['id' => 5]];
        $client = $this->client($all);

        $pagination = $this->createMock(PaginationInterface::class);
        $pagination->method('getPageSize')->willReturn(2);
        $pagination->method('getOffset')->willReturn(2);
        $pagination->expects($this->once())->method('setTotalCount')->with(5)->willReturnSelf();

        $sort = $this->createMock(SortInterface::class);
        $sort->method('fetchOrders')->willReturn([]);

        $provider = new JsonDataProvider($client, $this->createMock(EventDispatcherInterface::class));
        $provider->setSort($sort);
        $provider->setPagination($pagination);
        $provider->prepareModels(['baseUri' => 'https://api.example.test', 'resource' => 'items']);

        $rows = $provider->getData();

        $this->assertCount(1, $this->requests);
        $this->assertCount(2, $rows);
        $this->assertSame([['id' => 3], ['id' => 4]], array_map(static fn (Row $row): array => $row->data, $rows->toArray()));
    }

    public function testClientSidePaginationSortsPlainArrayInMemory(): void
    {
        // No totalPath and the endpoint does not sort server-side: the provider
        // orders the fetched list itself, numerically, with empty values last.
        $all = [
            ['id' => 1, 'weight' => 30],
            ['id' => 2, 'weight' => null],
            ['id' => 3, 'weight' => 200],
            ['id' => 4, 'weight' => 5],
        ];
        $client = $this->client($all);

        $provider = $this->provider($client, ['weight' => 'asc'], pageSize: 10, offset: 0);
        $provider->prepareModels(['baseUri' => 'https://api.example.test', 'resource' => 'items']);

        $rows = $provider->getData();

        $this->assertCount(1, $this->requests);
        $this->assertSame(
            [4, 1, 3, 2],
            array_map(static fn (Row $row): int => $row->data['id'], $rows->toArray()),
        );
    }

    public function testInMemorySortIsDescendingWithEmptyValuesStillLast(): void
    {
        $all = [
            ['id' => 1, 'weight' => 30],
            ['id' => 2, 'weight' => null],
            ['id' => 3, 'weight' => 200],
            ['id' => 4, 'weight' => 5],
        ];
        $client = $this->client($all);

        $provider = $this->provider($client, ['weight' => 'desc'], pageSize: 10, offset: 0);
        $provider->prepareModels(['baseUri' => 'https://api.example.test', 'resource' => 'items']);

        $rows = $provider->getData();

        // Descending by weight (200, 30, 5), and the empty weight stays last.
        $this->assertSame(
            [3, 1, 4, 2],
            array_map(static fn (Row $row): int => $row->data['id'], $rows->toArray()),
        );
    }

    public function testDotPathReadsNestedListAndTotal(): void
    {
        $client = $this->client(
            ['data' => ['items' => []], 'meta' => ['count' => 7]],
            ['data' => ['items' => [['id' => 9]]], 'meta' => ['count' => 7]],
        );

        $pagination = $this->createMock(PaginationInterface::class);
        $pagination->method('getPageSize')->willReturn(10);
        $pagination->method('getOffset')->willReturn(0);
        $pagination->expects($this->once())->method('setTotalCount')->with(7)->willReturnSelf();

        $sort = $this->createMock(SortInterface::class);
        $sort->method('fetchOrders')->willReturn([]);

        $provider = new JsonDataProvider($client, $this->createMock(EventDispatcherInterface::class));
        $provider->setSort($sort);
        $provider->setPagination($pagination);
        $provider->prepareModels([
            'baseUri' => 'https://api.example.test',
            'resource' => 'items',
            'listPath' => 'data.items',
            'totalPath' => 'meta.count',
        ]);

        $rows = $provider->getData();

        $this->assertCount(1, $rows);
        $this->assertSame(['id' => 9], $rows->first()->data);
    }

    public function testMissingBaseUriThrows(): void
    {
        $provider = $this->provider($this->client());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('baseUri');

        $provider->prepareModels(['resource' => 'products']);
    }

    public function testCustomParamNamesAndDisabledOffset(): void
    {
        $client = $this->client(['rows' => [], 'count' => 0], ['rows' => [], 'count' => 0]);

        $provider = $this->provider($client);
        $provider->prepareModels([
            'baseUri' => 'https://api.example.test',
            'resource' => 'products',
            'listPath' => 'rows',
            'totalPath' => 'count',
            'params' => ['limit' => 'per_page', 'offset' => null],
        ]);

        $provider->getData();

        $this->assertStringContainsString('per_page=10', $this->requests[1]['url']);
        $this->assertStringNotContainsString('skip=', $this->requests[1]['url']);
    }
}
