<?php
namespace Fedale\GridviewBundle\Service;

use Fedale\GridviewBundle\Contract\DataProviderInterface;
use Fedale\GridviewBundle\Form\SearchForm;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

class GridviewService
{
    public array $attr = [];

    private SearchForm $searchForm;

    private Request $request;

    private DataProviderInterface $dataProvider;

    public function __construct(private Environment $twig)
    {}

    public function setSearchForm(SearchForm $searchForm)
    {
        $this->searchForm = $searchForm;
    }

    public function setRequest(RequestStack $requestStack)
    {
        $this->request = $requestStack->getCurrentRequest();
    }

    public function getRequest(): Request
    {
        return $this->request;
    }

    public function getSearchForm()
    {
        return $this->searchForm;
    }

    public function getEnvironment()
    {
        return $this->twig;
    }

    public function setDataProvider(DataProviderInterface $dataProvider)
    {
        $this->dataProvider = $dataProvider;
    }

    public function getDataProvider(): DataProviderInterface
    {
        return $this->dataProvider;
    }

    public function setAttr(string $key, string $value, $replace = false)
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