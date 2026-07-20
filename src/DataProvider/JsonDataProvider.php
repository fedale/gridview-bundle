<?php

namespace Fedale\GridviewBundle\DataProvider;

use Doctrine\Common\Collections\ArrayCollection;
use Fedale\GridviewBundle\Event\RowEvent;
use Fedale\GridviewBundle\Row\Row;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Read-only DataProviderInterface backed by any JSON-over-HTTP endpoint instead
 * of Doctrine, configured entirely through the grid's dataConfig()["model"] —
 * base URI, resource path, response shape, query-param names and request headers
 * (so an Authorization token is just a header). Sorting/searching/pagination are
 * pushed down to the API's own query params when it supports server-side paging
 * (a "totalPath" is given); otherwise the full list is fetched once and paged in
 * memory.
 *
 * This complements — it does not contradict — the "swap the data source from the
 * host app without touching the bundle" story demonstrated by a hand-written
 * provider like the demo's DummyJsonUserDataProvider. That story proves the seam
 * exists; this class is a ready-made, reusable provider that rides the same seam,
 * so a host app that only needs a plain token-authenticated JSON endpoint can
 * wire it from config alone and never write a provider class.
 *
 * @see \Fedale\GridviewBundle\Contract\DataProviderInterface
 */
final class JsonDataProvider extends AbstractDataProvider
{
    /** Page size used only when the pagination component reports none yet. */
    private const DEFAULT_PAGE_SIZE = 10;

    private string $baseUri = '';

    private string $resource = '';

    private ?string $searchResource = null;

    /** Dot-path to the row list inside the decoded body; null = body is the list. */
    private ?string $listPath = null;

    /** Dot-path to the total count; null = no server paging, page in memory. */
    private ?string $totalPath = null;

    /** @var array<string, string> Static request headers (e.g. Authorization). */
    private array $headers = [];

    /** @var array<string, scalar> Static query params sent on every request. */
    private array $staticQuery = [];

    /**
     * Logical -> actual query-param names. Set any value to null to omit that
     * param entirely (e.g. an API with no full-text search endpoint).
     *
     * @var array{limit: ?string, offset: ?string, search: ?string, sort: ?string, order: ?string}
     */
    private array $paramMap = [
        'limit' => 'limit',
        'offset' => 'skip',
        'search' => 'q',
        'sort' => 'sortBy',
        'order' => 'order',
    ];

    private ?string $searchTerm = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function prepareModels(string|array $models): void
    {
        // A bare string is accepted as a shorthand for the resource path, but
        // baseUri is always required and can only come from the array form.
        $config = is_array($models) ? $models : ['resource' => $models];

        $baseUri = $config['baseUri'] ?? null;
        if (!is_string($baseUri) || '' === $baseUri) {
            throw new \InvalidArgumentException(sprintf(
                'JsonDataProvider requires a non-empty "baseUri" in the grid dataConfig()["model"], none given (resource: "%s").',
                is_string($config['resource'] ?? null) ? $config['resource'] : get_debug_type($config['resource'] ?? null),
            ));
        }

        $resource = $config['resource'] ?? null;
        if (!is_string($resource) || '' === $resource) {
            throw new \InvalidArgumentException('JsonDataProvider requires a non-empty "resource" in the grid dataConfig()["model"].');
        }

        $this->baseUri = $baseUri;
        $this->resource = $resource;
        $this->searchResource = $config['searchResource'] ?? null;
        $this->listPath = $config['listPath'] ?? null;
        $this->totalPath = $config['totalPath'] ?? null;
        $this->headers = $config['headers'] ?? [];
        $this->staticQuery = $config['query'] ?? [];
        $this->paramMap = array_replace($this->paramMap, $config['params'] ?? []);
    }

    public function setFormName(string $formName): void
    {
        // No filter form on an HTTP-backed grid; nothing to key request params
        // under. Global search is handled by applyGlobalSearch() instead.
    }

    public function applyGlobalSearch(array $fields, string $term): void
    {
        $this->searchTerm = $term;
    }

    public function getData()
    {
        if (null !== $this->totalPath) {
            // Server-side pagination. totalCount must be set *before* getOffset()
            // is read: Pagination::getCurrentPage() clamps the requested page
            // against the total the first time it's called and caches the result,
            // so reading the offset against a stale total of 0 would produce a
            // negative offset on any page beyond the first. Same ordering
            // EntityDataProvider::getData() relies on.
            $this->pagination->setTotalCount($this->probeTotal());

            $limit = $this->pagination->getPageSize() ?? self::DEFAULT_PAGE_SIZE;
            $offset = $this->pagination->getOffset();

            $payload = $this->fetch($limit, $offset);

            return $this->models = $this->buildRows($this->extractList($payload), $limit, $offset);
        }

        // No total path: the endpoint returns a plain list with no server-side
        // paging, so fetch it once and slice the current page in memory.
        $all = $this->extractList($this->fetch(null, 0));
        $this->pagination->setTotalCount(count($all));

        $limit = $this->pagination->getPageSize() ?? $this->pagination->getDefaultPageSize();
        $offset = $this->pagination->getOffset();
        $page = array_slice($all, $offset, $limit > 0 ? $limit : null);

        return $this->models = $this->buildRows($page, $limit, $offset);
    }

    public function getAllData()
    {
        // Export path: every matching row, unpaginated. When the API pages
        // server-side, probe the total and request exactly that many in one call.
        if (null !== $this->totalPath) {
            $total = $this->probeTotal();
            $payload = $this->fetch($total > 0 ? $total : null, 0);

            return $this->buildRows($this->extractList($payload), 0, 0);
        }

        return $this->buildRows($this->extractList($this->fetch(null, 0)), 0, 0);
    }

    private function probeTotal(): int
    {
        // A cheap limit=1 request carrying the same search term, read for its
        // total count only.
        return (int) $this->extractByPath($this->fetch(1, 0), $this->totalPath);
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function buildRows(array $items, int $pageSize, int $offset): ArrayCollection
    {
        $rows = new ArrayCollection();

        foreach ($items as $key => $item) {
            $row = new Row($key, $pageSize, $offset);
            $row->data = $item;

            $event = new RowEvent();
            $event->row = $row;
            $this->eventDispatcher->dispatch($event, RowEvent::BEFORE_ROW);
            $rows->add($row);
            $this->eventDispatcher->dispatch($event, RowEvent::AFTER_ROW);
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetch(?int $limit, int $offset): array
    {
        $path = $this->resource;
        $query = $this->staticQuery;

        if (null !== $this->searchTerm && '' !== $this->searchTerm) {
            $path = $this->searchResource ?? $this->resource;
            if (null !== $this->paramMap['search']) {
                $query[$this->paramMap['search']] = $this->searchTerm;
            }
        }

        if (null !== $limit && null !== $this->paramMap['limit']) {
            $query[$this->paramMap['limit']] = $limit;
        }
        if (null !== $this->paramMap['offset']) {
            $query[$this->paramMap['offset']] = $offset;
        }

        [$field, $direction] = $this->firstSortOrder();
        if (null !== $field && null !== $this->paramMap['sort']) {
            $query[$this->paramMap['sort']] = $field;
            if (null !== $this->paramMap['order']) {
                $query[$this->paramMap['order']] = $direction;
            }
        }

        $url = rtrim($this->baseUri, '/') . '/' . ltrim($path, '/');

        return $this->httpClient
            ->request('GET', $url, ['query' => $query, 'headers' => $this->headers])
            ->toArray();
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<array<string, mixed>>
     */
    private function extractList(array $payload): array
    {
        $list = $this->extractByPath($payload, $this->listPath);

        return is_array($list) ? array_values($list) : [];
    }

    /**
     * Read a dot-notation path from a decoded body. A null path returns the body
     * unchanged; a missing segment returns null.
     *
     * @param array<string, mixed> $data
     */
    private function extractByPath(array $data, ?string $path): mixed
    {
        if (null === $path) {
            return $data;
        }

        $value = $data;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * @return array{0: ?string, 1: string}
     */
    private function firstSortOrder(): array
    {
        foreach ($this->getSort()->fetchOrders() as $field => $direction) {
            return [$field, $direction];
        }

        return [null, 'asc'];
    }
}
