<?php

namespace Fedale\GridviewBundle\UiSettings;

use Fedale\GridviewBundle\Controller\AbstractGridController;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * Every grid of the app, for the per-grid scopes of the settings UI. The grid
 * controllers (any AbstractGridController subclass) are autoconfigured with the
 * `fedale_gridview.grid` tag and reach this registry as a lazy locator, so a
 * controller is only instantiated when the list is actually needed.
 */
class GridRegistry
{
    /** @var array<string, GridDescriptor>|null grid id => descriptor, sorted by id */
    private ?array $grids = null;

    /**
     * @param ServiceLocator<AbstractGridController> $controllers
     */
    public function __construct(private readonly ServiceLocator $controllers)
    {
    }

    /** @return array<string, GridDescriptor> grid id => descriptor */
    public function all(): array
    {
        if ($this->grids === null) {
            $this->grids = [];
            foreach (array_keys($this->controllers->getProvidedServices()) as $serviceId) {
                $descriptor = $this->controllers->get($serviceId)->describeGrid();
                // Two controllers may share an id (e.g. a second grid over the
                // same entity): their settings scope is shared too, keep the first.
                $this->grids[$descriptor->id] ??= $descriptor;
            }
            ksort($this->grids);
        }

        return $this->grids;
    }

    public function get(string $id): ?GridDescriptor
    {
        return $this->all()[$id] ?? null;
    }
}
