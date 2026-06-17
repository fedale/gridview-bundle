<?php

namespace Fedale\GridviewBundle\Pagination;

use Fedale\GridviewBundle\Contract\PaginationInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

class Pagination implements PaginationInterface
{
    /**
     * @var int|null Current page number.
     */
    protected ?int $currentPage;

    /**
     * @var string Name of query parameter that contains page number.
     */
    protected string $pageParam = 'page';

    /**
     * @var string Name of query parameter that contains number of items on page.
     */
    protected string $pageSizeParam = 'per-page';

    /**
     * @var string Name of route. If route was not specified then current route
     * will be used.
     */
    protected string $route;

    /**
     * @var int Total number of items.
     */
    protected int $totalCount = 0;

    /**
     * @var int Default number of items per page. Will be used if [[$pageSize]]
     * not specified.
     */
    protected int $defaultPageSize = 20;

    /**
     * @var int Default limit of items per page.
     */
    protected int $maxPageSize = 50;

    /**
     * @var int|null Default number of items per page.
     */
    protected ?int $pageSize;

    /**
     * array to overwrite Pagination attributes
     * 'attributes' => [
     *      'pageParam' => 'page',
     *      'pageSizeParam' => 'per-page',
     *      'defaultPageSize' => 20,
     *      'maxPageSize' => 50
     * ]
     */
    protected array $attributes = [];

    /**
     * @var Request
     */
    protected Request|null $request;

    /**
     * Pagination constructor.
     *
     * @param RequestStack $requestStack
     */
    public function __construct(RequestStack $requestStack)
    {
        $this->request = $requestStack->getCurrentRequest();
    }

    public function setAttributes(array $attributes): static
    {
        $this->attributes = $attributes;
        $this->prepareAttributes();

        return $this;
    }

    public function prepareAttributes(): void
    {
        $attributes = $this->attributes;

        $this->pageParam  = $attributes['pageParam'] ?? $this->pageParam;
        $this->pageSizeParam  = $attributes['pageSizeParam'] ?? $this->pageSizeParam;
        $this->defaultPageSize  = $attributes['defaultPageSize'] ?? $this->defaultPageSize;
        $this->maxPageSize  = $attributes['maxPageSize'] ?? $this->maxPageSize;
    }

    /**
     * Calculate total number of pages.
     *
     * @return int number of pages
     */
    public function getPageCount(): int
    {
        $pageSize = $this->getPageSize();

        if ($pageSize < 1) {
            return $this->totalCount > 0 ? 1 : 0;
        }

        $totalCount = $this->totalCount < 0 ? 0 : $this->totalCount;

        return (int)(($totalCount + $pageSize - 1) / $pageSize);
    }

    /**
     * Get current page number. Pages numeration starts from zero.
     *
     * @return int the zero-based current page number.
     */
    public function getCurrentPage(): int
    {
        if (!isset($this->currentPage)) {
            $currentPage = (int)$this->request->get($this->pageParam, 1) - 1;

            $this->setCurrentPage($currentPage);
        }

        return $this->currentPage;
    }

    /**
     * Set current page number.
     *
     * @param int $pageNumber
     *
     * @return $this
     */
    protected function setCurrentPage($pageNumber)
    {
        if (!is_numeric($pageNumber) || (int)$pageNumber <= 0) {

            $this->currentPage = 0;

            return $this;
        }

        $pageNumber = (int)$pageNumber;

        $totalPageCount = $this->getPageCount();

        if ($pageNumber >= $totalPageCount) {
            $pageNumber = $totalPageCount - 1;
        }

        $this->currentPage = $pageNumber;

        return $this;
    }

    /**
     * Get current number of items per page. If it's not specified yet the value
     * will be taken from query parameters. In other case default value will
     * be used.
     *
     * @return int
     */
    public function getPageSize(): ?int
    {
        if (isset($this->pageSize)) {
            return $this->pageSize;
        }

        $pageSize = (int)$this->request->get(
            $this->pageSizeParam,
            $this->defaultPageSize
        );

        $this->setPageSize($pageSize, true);

        return $this->pageSize;
    }

    /**
     * Set number of items to show per page.
     * By default, limit will be used.
     *
     * @param int $pageSize
     * @param bool $useLimit
     *
     * @return $this
     */
    public function setPageSize(int $pageSize, bool $useLimit = true): static
    {
        $pageSize = $useLimit && $pageSize > $this->maxPageSize ? $this->maxPageSize : $pageSize;
        $pageSize = $pageSize < 0 ? 0 : $pageSize;

        $this->pageSize = $pageSize;

        return $this;
    }

    /**
     * Fetch current route name.
     *
     * @return string
     * @throws \Exception
     */
    public function getRoute(): string
    {
        if (!isset($this->route)) {
            $this->setRoute($this->request->attributes->all()['_route']);
        }

        return $this->route;
    }

    /**
     * @return int Get offset value that can be used in data source query.
     * @throws \Exception
     */
    public function getOffset(): int
    {
        $pageSize = $this->getPageSize();
        $offset = $pageSize < 1 ? 0 : $this->getCurrentPage() * $pageSize;

        return $offset;
    }

    /**
     * @return int Get limit value that can be used in data source query.
     * @throws \Exception
     */
    public function getLimit(): int
    {
        $pageSize = $this->getPageSize();

        return $pageSize < 1 ? -1 : $pageSize;
    }

    /**
     * Get name of query parameter that stores current page index.
     *
     * @return string
     */
    public function getPageParamName(): string
    {
        return $this->pageParam;
    }

    /**
     * Get default number of items per page.
     *
     * @return int
     */
    public function getDefaultPageSize(): int
    {
        return $this->defaultPageSize;
    }

    /**
     * Get name of query parameter that stores number of items per page.
     *
     * @return string
     */
    public function getPageSizeParam()
    {
        return $this->pageSizeParam;
    }

    /**
     * Set total number of items.
     *
     * @param int|float $totalCount
     *
     * @return $this
     * @throws \Exception
     */
    public function setTotalCount(int|float $totalCount): static
    {
        if (!is_numeric($totalCount)) {
            throw new \Exception(
                'The expected type of the '.Pagination::class
                .' items total count is a numeric.'.gettype($totalCount)
                .' given.'
            );
        }

        $this->totalCount = (int)$totalCount;

        return $this;
    }

    /**
     * Set route name.
     *
     * @param string $route Route name.
     *
     * @return $this
     * @throws \Exception
     */
    public function setRoute($route)
    {
        if (!is_string($route)) {
            throw new \Exception(
                'The expected type of the '.Pagination::class
                .' route is a string. '.gettype($route).' given.'
            );
        }

        $this->route = $route;

        return $this;
    }

    /**
     * @param int $defaultPageSize
     *
     * @return $this
     * @throws \Exception
     */
    public function setDefaultPageSize($defaultPageSize)
    {
        if (!is_numeric($defaultPageSize)) {
            throw new \Exception(
                'The expected type of the '.Pagination::class
                .' default page size is a numeric.'.gettype($defaultPageSize)
                .' given.'
            );
        }

        $this->defaultPageSize = (int)$defaultPageSize;

        return $this;
    }

    /**
     * @param string $pageSizeParam
     *
     * @return $this
     * @throws \Exception
     */
    public function setPageSizeParam($pageSizeParam)
    {
        if (!is_string($pageSizeParam)) {
            throw new \Exception(
                'The expected type of the '.Pagination::class
                .' page size param name is a string. '.gettype($pageSizeParam)
                .' given.'
            );
        }

        $this->pageSizeParam = $pageSizeParam;

        return $this;
    }

    /**
     * @param string $pageParam
     *
     * @return $this
     * @throws \Exception
     */
    public function setPageParam($pageParam)
    {
        if (!is_string($pageParam)) {
            throw new \Exception(
                'The expected type of the '.Pagination::class
                .' page param name is a string. '.gettype($pageParam).' given.'
            );
        }

        $this->pageParam = $pageParam;

        return $this;
    }
}
