<?php

namespace Fedale\GridviewBundle\DataProvider;

use Fedale\GridviewBundle\Contract\DataProviderInterface;
use Fedale\GridviewBundle\Contract\PaginationInterface;
use Fedale\GridviewBundle\Contract\SearchModelInterface;
use Fedale\GridviewBundle\Contract\SortInterface;

abstract class AbstractDataProvider implements DataProviderInterface
{
    private SearchModelInterface $searchModel;

    /**
     * Array of arrays that carry data on
     */
    protected $models;

    /**
     * @var PaginationInterface
     */
    protected PaginationInterface $pagination;

    /**
     * @var SortInterface
     */
    protected SortInterface $sort;

    /**
     * Filter defaults declared in the column config, applied by concrete
     * providers when the request carries no filter params at all.
     */
    protected array $defaultParams = [];

    /**
     * Entity attribute names to skip when normalizing rows. Use it to keep
     * heavy or eager-loading getters (e.g. a security {@see \Symfony\Component\Security\Core\User\UserInterface::getRoles()}
     * that materializes a relation) out of grid serialization.
     *
     * @var string[]
     */
    protected array $ignoredAttributes = [];

    public function setDefaultParams(array $defaults): void
    {
        $this->defaultParams = $defaults;
    }

    /**
     * @param string[] $attributes
     */
    public function setIgnoredAttributes(array $attributes): void
    {
        $this->ignoredAttributes = $attributes;
    }

    /**
     * @param PaginationInterface $pagination
     *
     * @return $this
     */
    public function setPagination(PaginationInterface $pagination): static
    {
        $this->pagination = $pagination;

        return $this;
    }

    /**
     * @param SortInterface $sort
     *
     * @return AbstractDataProvider
     */
    public function setSort(SortInterface $sort): static
    {
        $this->sort = $sort;
        
        return $this;
    }

    /**
     * @return PaginationInterface
     */
    public function getPagination(): PaginationInterface
    {
        return $this->pagination;
    }

    /**
     * @return SortInterface
     */
    public function getSort(): SortInterface
    {
        return $this->sort;
    }

    public function setSearchModel(SearchModelInterface $searchModel): static
    {
        $this->searchModel = $searchModel;

        return $this;
    }

    public function getSearchModel(): SearchModelInterface
    {
        return $this->searchModel;
    }

    public function getData()
    {
        // $this->prepare();
        return $this->models;
    }
}