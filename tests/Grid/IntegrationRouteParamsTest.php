<?php

namespace Fedale\GridviewBundle\Tests\Grid;

use Fedale\GridviewBundle\Grid\State\GridviewUrlState;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Extension\RoutingExtension;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * The routes a grid links to by name (`integration.routeName`,
 * `integration.addRoute`) may carry placeholders — a grid served at
 * /o/{object}, say. Their URLs must be built with the current route's
 * parameters, or path() throws for the missing ones.
 */
class IntegrationRouteParamsTest extends TestCase
{
    private function urlState(): GridviewUrlState
    {
        $request = Request::create('/o/pet?sort=name');
        $request->attributes->set('_route_params', ['object' => 'pet']);

        return GridviewUrlState::fromRequest($request);
    }

    public function testUrlStateExposesTheRouteParametersAlone(): void
    {
        $this->assertSame(['object' => 'pet'], $this->urlState()->getRouteParams());
    }

    public function testAddButtonFillsTheRoutePlaceholders(): void
    {
        $routes = new RouteCollection();
        $routes->add('record_new', new Route('/o/{object}/new'));

        $loader = new FilesystemLoader();
        $loader->addPath(__DIR__ . '/../../templates', 'FedaleGridview');
        $twig = new Environment($loader, ['strict_variables' => true]);
        $twig->addExtension(new RoutingExtension(new UrlGenerator($routes, new RequestContext())));
        $twig->addExtension(new TranslationExtension());
        $twig->addFunction(new TwigFunction('gv_client_label', static fn() => false));
        $twig->addFunction(new TwigFunction('gv_client_text', static fn(string $key) => $key));
        $twig->addFunction(new TwigFunction('gv_i18n', static fn() => '', ['is_safe' => ['html']]));

        $gridview = new class($this->urlState()) {
            public array $options = [
                'integration' => ['addRoute' => 'record_new'],
                'display' => ['addLabel' => 'Add'],
                'behavior' => [],
            ];

            public function __construct(public GridviewUrlState $urlState)
            {
            }

            public function cls(string $key): string
            {
                return 'gv-' . str_replace('.', '-', $key);
            }
        };

        $html = $twig->render('@FedaleGridview/gridview/sections/addButton.html.twig', ['gridview' => $gridview]);

        $this->assertStringContainsString('href="/o/pet/new"', $html);
    }

    /**
     * _grid.html.twig needs most of the bundle to render, so this guards its
     * source instead: every path() to a route named in `integration` passes
     * the route parameters.
     */
    public function testGridTemplatePassesTheRouteParametersToTheListRoute(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../templates/gridview/_grid.html.twig');

        preg_match_all('/path\(gridview\.options\.integration\.\w+[^)]*\)/', $source, $calls);

        $this->assertNotEmpty($calls[0]);
        foreach ($calls[0] as $call) {
            $this->assertStringContainsString('gridview.urlState.routeParams', $call);
        }
    }
}
