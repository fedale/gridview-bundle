<?php

namespace Fedale\GridviewBundle\Tests\Grid;

use Fedale\GridviewBundle\Column\ColumnFactory;
use Fedale\GridviewBundle\Contract\ChildRowResolverInterface;
use Fedale\GridviewBundle\Contract\DataProviderInterface;
use Fedale\GridviewBundle\Contract\GroupingCapableInterface;
use Fedale\GridviewBundle\Contract\ScopeVerifiableInterface;
use Fedale\GridviewBundle\Form\SearchForm;
use Fedale\GridviewBundle\Grid\Gridview;
use Fedale\GridviewBundle\Service\GridviewService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Twig\Environment;

/**
 * The `?_children=<key>` lazy grouping endpoint receives a raw, client-supplied
 * key. Without a scope check, it would resolve any parent's children regardless
 * of whatever row-level restriction the grid's own list applies (IDOR). See
 * ScopeVerifiableInterface.
 */
class GridviewGroupingScopeTest extends TestCase
{
    private function createGridview(DataProviderInterface $dataProvider, string|int $childrenKey): Gridview
    {
        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturn('<div class="gv-group-empty">No related records</div>');

        $service = new GridviewService($twig);
        $service->setSearchForm(new SearchForm(Forms::createFormFactory(), new RequestStack()));
        $service->setDataProvider($dataProvider);

        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/users?_children=' . $childrenKey));
        $service->setRequest($requestStack);

        $gridview = new Gridview($service, new ColumnFactory());
        $gridview->setDataProvider($dataProvider);
        $gridview->setOptions(['behavior' => ['grouping' => [
            'enabled' => true,
            'mode' => 'lazy',
            'relation' => 'posts',
            'columns' => [],
        ]]]);

        return $gridview;
    }

    private function provider(ChildRowResolverInterface $resolver, bool $inScope): DataProviderInterface
    {
        return new class($resolver, $inScope) implements DataProviderInterface, GroupingCapableInterface, ScopeVerifiableInterface {
            public function __construct(
                private ChildRowResolverInterface $resolver,
                private bool $inScope,
            ) {
            }

            public function isKeyInScope(int|string $key, string $keyField = 'id'): bool
            {
                return $this->inScope;
            }

            public function getChildResolver(): ?ChildRowResolverInterface
            {
                return $this->resolver;
            }

            public function prepareModels(string|array $models)
            {
            }

            public function setDefaultParams(array $defaults): void
            {
            }

            public function setFormName(string $formName): void
            {
            }

            public function setAlias(string $alias): void
            {
            }

            public function setSearchFields(array $searchFields): void
            {
            }

            public function setIgnoredAttributes(array $attributes): void
            {
            }

            public function getData()
            {
                return [];
            }

            public function getAllData()
            {
                return [];
            }

            public function getSort()
            {
                return null;
            }

            public function getPagination()
            {
                return null;
            }

            public function applyGlobalSearch(array $fields, string $term): void
            {
            }
        };
    }

    public function testChildrenEndpointRejectsAKeyOutsideTheProvidersScope(): void
    {
        $resolver = $this->createMock(ChildRowResolverInterface::class);
        $resolver->expects($this->never())->method('resolveForParent');

        $gridview = $this->createGridview($this->provider($resolver, inScope: false), 999);

        $this->expectException(NotFoundHttpException::class);
        $gridview->renderGrid('irrelevant.html.twig');
    }

    public function testChildrenEndpointServesTheFragmentWhenTheKeyIsInScope(): void
    {
        $resolver = $this->createMock(ChildRowResolverInterface::class);
        $resolver->expects($this->once())->method('resolveForParent')->willReturn([]);

        $gridview = $this->createGridview($this->provider($resolver, inScope: true), 10);

        $response = $gridview->renderGrid('irrelevant.html.twig');

        $this->assertSame(200, $response->getStatusCode());
    }
}
