<?php

namespace Fedale\GridviewBundle\Service;

use Fedale\GridviewBundle\Contract\DataProviderInterface;
use Fedale\GridviewBundle\Form\SearchForm;
use Fedale\GridviewBundle\Pagination\Strategy\PaginatorStrategyRegistry;
use Fedale\GridviewBundle\Profiler\GridviewProfileRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

class GridviewService
{
    public array $attr = [];

    private SearchForm $searchForm;

    /**
     * ⚠ The stack, never the Request it currently holds.
     *
     * {@see Gridview::renderGrid()} reads the request from here to build
     * {@see \Fedale\GridviewBundle\Grid\State\GridviewUrlState}, which carries
     * the renderer (`view`), the page, the page size, the applied filters and
     * the Turbo parameters (`_rows`, `_children`). A Request captured in the
     * setter below is the one being served when the container was built, so
     * under a worker runtime every later request rendered the first request's
     * view, page and filter chips — while the filters themselves worked,
     * because the query is built from the data provider's own params.
     */
    private ?RequestStack $requestStack = null;

    private DataProviderInterface $dataProvider;

    private PaginatorStrategyRegistry $paginatorStrategyRegistry;

    private ?GridviewProfileRegistry $profileRegistry = null;

    private ?LoggerInterface $logger = null;

    public function __construct(private Environment $twig)
    {
    }

    public function setLogger(?LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function getLogger(): ?LoggerInterface
    {
        return $this->logger;
    }

    public function setSearchForm(SearchForm $searchForm): void
    {
        $this->searchForm = $searchForm;
    }

    public function setRequest(RequestStack $requestStack): void
    {
        $this->requestStack = $requestStack;
    }

    /**
     * The request being served, read afresh on every access.
     *
     * Null outside a request — a console command rendering a grid, say — which
     * the caller has to handle rather than assume away.
     */
    public function getRequest(): ?Request
    {
        return $this->requestStack?->getCurrentRequest();
    }

    /**
     * Discards the attributes accumulated while rendering the last grid.
     *
     * ⚠ {@see setAttr()} appends with `.=` when a key is already present, so
     * without this the container's CSS classes grow on every request for the
     * life of the worker.
     */
    public function reset(): void
    {
        $this->attr = [];
    }

    public function getSearchForm()
    {
        return $this->searchForm;
    }

    public function getEnvironment()
    {
        return $this->twig;
    }

    public function setDataProvider(DataProviderInterface $dataProvider): void
    {
        $this->dataProvider = $dataProvider;
    }

    public function getDataProvider(): DataProviderInterface
    {
        return $this->dataProvider;
    }

    public function setPaginatorStrategyRegistry(PaginatorStrategyRegistry $registry): void
    {
        $this->paginatorStrategyRegistry = $registry;
    }

    public function getPaginatorStrategyRegistry(): PaginatorStrategyRegistry
    {
        return $this->paginatorStrategyRegistry;
    }

    public function setProfileRegistry(?GridviewProfileRegistry $profileRegistry): void
    {
        $this->profileRegistry = $profileRegistry;
    }

    public function getProfileRegistry(): ?GridviewProfileRegistry
    {
        return $this->profileRegistry;
    }

    public function setAttr(string $key, string $value, $replace = false): void
    {
        if (!isset($this->attr[$key])) {
            $this->attr[$key] = $value;
        } else {
            if ($replace) {
                $this->attr[$key] = $value;
            } else {
                $this->attr[$key] .= ' ' . $value;
            }
        }
    }
}
