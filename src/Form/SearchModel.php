<?php

namespace Fedale\GridviewBundle\Form;

use Fedale\GridviewBundle\Contract\DataProviderInterface;
use Fedale\GridviewBundle\Contract\SearchModelInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

class SearchModel implements SearchModelInterface
{
    private ?RequestStack $requestStack = null;

    private DataProviderInterface $dataProvider;

    /**
     * ⚠ Keeps the stack, not the Request it currently holds: this is a shared
     * service, and a captured Request outlives its own request under a runtime
     * that keeps the container alive (FrankenPHP worker mode, RoadRunner,
     * Swoole). See {@see \Fedale\GridviewBundle\Sort\Sort::__construct()}.
     */
    public function setRequest(RequestStack $requestStack)
    {
        $this->requestStack = $requestStack;
    }

    /**
     * The request being served, read afresh on every access.
     *
     * Nothing consumes it yet — search() is a stub — but the stack is kept
     * rather than a Request so that whatever grows here cannot inherit the
     * frozen-request bug the rest of this bundle just shed.
     */
    public function request(): ?Request
    {
        return $this->requestStack?->getCurrentRequest();
    }

    public function setDataProvider(DataProviderInterface $dataProvider)
    {
        $this->dataProvider = $dataProvider;
    }

    public function search(){}
}
