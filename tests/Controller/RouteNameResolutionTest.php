<?php

namespace Fedale\GridviewBundle\Tests\Controller;

use Fedale\GridviewBundle\Controller\AbstractGridController;
use Fedale\GridviewBundle\Grid\Gridview;
use Fedale\GridviewBundle\Routing\GridAction;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Covers route-name resolution: the convention default, the per-action override
 * map ({@see AbstractGridController::routeNames()}, both the method and the
 * declarative `options.integration.routeNames` form), and the early failure when
 * the resolved list route does not exist — without booting a kernel (container
 * touch-points are stubbed).
 */
class RouteNameResolutionTest extends TestCase
{
    /**
     * @param array<string, string>|null $methodMap    non-null → returned by routeNames() (method override)
     * @param array<string, mixed>       $viewConfig   per-controller viewConfig() overrides
     * @param list<string>               $existingRoutes route names routeExists() should report as present
     */
    private function controller(
        ?array $methodMap = null,
        array $viewConfig = [],
        array $existingRoutes = [],
    ): object {
        return new #[Route('/members', name: 'backoffice_member_')] class($methodMap, $viewConfig, $existingRoutes) extends AbstractGridController {
            public function __construct(
                private ?array $methodMap,
                private array $cfg,
                private array $existingRoutes,
            ) {
            }

            protected function getDataClass(): string
            {
                return \stdClass::class;
            }

            protected function buildColumns(): array
            {
                return [];
            }

            protected function dataConfig(): array
            {
                return [];
            }

            protected function viewConfig(): array
            {
                return $this->cfg;
            }

            protected function routeNames(): array
            {
                // null → exercise the real default (reads from config); otherwise
                // simulate a subclass overriding the method.
                return $this->methodMap ?? parent::routeNames();
            }

            // ---- container-free stubs -------------------------------------

            protected function realtime(): array
            {
                return ['active' => false, 'topic' => '', 'hubUrl' => null];
            }

            protected function exportFormats(): array
            {
                return [];
            }

            public function generateUrl(string $route, array $parameters = [], int $referenceType = 1): string
            {
                return $route;
            }

            protected function routeExists(string $name): bool
            {
                return \in_array($name, $this->existingRoutes, true);
            }

            // ---- accessors ------------------------------------------------

            public function resolve(GridAction|string $action): string
            {
                return $this->routeName($action);
            }

            public function build(): Gridview
            {
                return $this->buildGridview();
            }
        };
    }

    public function testDefaultFollowsTheEnumValueConvention(): void
    {
        $c = $this->controller();

        // Guards against a refactor silently changing the convention.
        $this->assertSame('backoffice_member_index', $c->resolve(GridAction::Index));
        $this->assertSame('backoffice_member_export', $c->resolve(GridAction::Export));
        $this->assertSame('backoffice_member_show', $c->resolve(GridAction::Show));
        $this->assertSame('backoffice_member_delete', $c->resolve(GridAction::Delete));
        // Raw-string call sites (link route tokens) follow the same path.
        $this->assertSame('backoffice_member_archive', $c->resolve('archive'));
    }

    public function testMethodOverrideRemapsOneActionOnly(): void
    {
        $c = $this->controller(methodMap: [GridAction::Index->value => 'list']);

        $this->assertSame('backoffice_member_list', $c->resolve(GridAction::Index));
        // Everything else stays conventional.
        $this->assertSame('backoffice_member_export', $c->resolve(GridAction::Export));
        $this->assertSame('backoffice_member_show', $c->resolve(GridAction::Show));
    }

    public function testDeclarativeConfigMapHasTheSameEffect(): void
    {
        $c = $this->controller(viewConfig: [
            'options' => ['integration' => ['routeNames' => [GridAction::Index->value => 'list']]],
        ]);

        $this->assertSame('backoffice_member_list', $c->resolve(GridAction::Index));
        $this->assertSame('backoffice_member_export', $c->resolve(GridAction::Export));
    }

    public function testMethodOverrideReplacesConfigWholesale(): void
    {
        // Both sources present: the method override wins (replaces, never merges).
        $c = $this->controller(
            methodMap: [GridAction::Index->value => 'list'],
            viewConfig: ['options' => ['integration' => ['routeNames' => [GridAction::Index->value => 'roster']]]],
        );

        $this->assertSame('backoffice_member_list', $c->resolve(GridAction::Index));
    }

    public function testMissingListRouteFailsEarlyNamingTheController(): void
    {
        // No route mapped as existing → the derived index route is absent.
        $c = $this->controller();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('backoffice_member_index');
        $this->expectExceptionMessage('GridAction::Index');
        $c->build();
    }

    public function testFailEarlyValidatesTheResolvedRouteNameNotTheDerivedDefault(): void
    {
        // routeNames maps index → 'list' (so the derived default would be the
        // existing backoffice_member_list), but an explicit integration.routeName
        // sets a full route name that does NOT exist. The explicit value must win
        // and be the one validated — proving both override precedence and that the
        // check reads the resolved value, not just the default.
        $c = $this->controller(
            methodMap: [GridAction::Index->value => 'list'],
            viewConfig: ['options' => ['integration' => [
                'routeName' => 'some_other_prefix_list',
            ]]],
            existingRoutes: ['backoffice_member_list', 'backoffice_member_export'],
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('some_other_prefix_list');
        $c->build();
    }
}
