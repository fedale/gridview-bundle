<?php

namespace Fedale\GridviewBundle\Tests\Controller;

use Fedale\GridviewBundle\Controller\AbstractCrudGridController;
use PHPUnit\Framework\TestCase;

/**
 * Covers the auto-wiring of a bare `['type' => 'action']` column to the CRUD
 * routes (AbstractCrudGridController::gridColumns / defaultActionButtons),
 * without booting a kernel: the route lookup and URL generation are stubbed.
 */
class CrudActionWiringTest extends TestCase
{
    private function controller(array $columns, array $existingRoutes = ['update', 'clone', 'delete']): object
    {
        return new class($columns, $existingRoutes) extends AbstractCrudGridController {
            public function __construct(private array $cols, private array $existingRoutes)
            {
            }

            protected function getDataClass(): string
            {
                return \stdClass::class;
            }

            protected function buildColumns(): array
            {
                return $this->cols;
            }

            protected function dataConfig(): array
            {
                return [];
            }

            protected function viewConfig(): array
            {
                // Avoid the YAML branch (container-free test).
                return ['options' => ['actionLayout' => '{view} {edit} {delete}']];
            }

            protected function routeName(string $action): string
            {
                return 'r_' . $action;
            }

            protected function routeExists(string $name): bool
            {
                return \in_array(substr($name, 2), $this->existingRoutes, true);
            }

            public function generateUrl(string $route, array $parameters = [], int $referenceType = 1): string
            {
                return '/' . $route . '/' . ($parameters['id'] ?? '');
            }

            public function exposeColumns(): array
            {
                return $this->gridColumns();
            }
        };
    }

    public function testBareActionColumnGetsButtonsForExistingRoutesOnly(): void
    {
        $columns = $this->controller([['type' => 'action']])->exposeColumns();
        $action  = $columns[0];

        // {view} has no `show` route here → not wired; edit/clone/delete are.
        $this->assertArrayHasKey('buttons', $action);
        $this->assertSame(['edit', 'clone', 'delete'], array_keys($action['buttons']));
        $this->assertArrayNotHasKey('view', $action['buttons']);

        // Default layout applied; closures generate the convention URLs + modal hook.
        $this->assertSame('{view} {edit} {delete}', $action['layout']);
        $edit = ($action['buttons']['edit'])(['id' => 11]);
        $this->assertStringContainsString('/r_update/11', $edit);
        $this->assertStringContainsString('data-action="gridview-crud#open"', $edit);
    }

    public function testExplicitButtonsAreLeftUntouched(): void
    {
        $custom  = [['type' => 'action', 'buttons' => ['edit' => '<a>x</a>']]];
        $columns = $this->controller($custom)->exposeColumns();

        $this->assertSame($custom[0], $columns[0]);
    }

    public function testSpecLayoutWinsOverActionLayout(): void
    {
        $columns = $this->controller([['type' => 'action', 'layout' => '{edit}']])->exposeColumns();

        $this->assertSame('{edit}', $columns[0]['layout']);
        $this->assertArrayHasKey('buttons', $columns[0]);
    }

    public function testNoCrudRoutesLeavesColumnBare(): void
    {
        // Read-only-style: no convention routes exist → no buttons injected.
        $columns = $this->controller([['type' => 'action']], existingRoutes: [])->exposeColumns();

        $this->assertArrayNotHasKey('buttons', $columns[0]);
        $this->assertArrayNotHasKey('layout', $columns[0]);
    }

    public function testNonActionColumnsAreUntouched(): void
    {
        $columns = $this->controller(['id', ['type' => 'action']])->exposeColumns();

        $this->assertSame('id', $columns[0]);
    }
}
