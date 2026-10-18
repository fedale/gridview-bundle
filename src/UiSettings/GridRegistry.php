<?php

namespace Fedale\GridviewBundle\UiSettings;

use Fedale\GridviewBundle\Contract\GridDescriptorProviderInterface;

/**
 * Every grid of the app, for the per-grid scopes of the settings UI: the
 * descriptors of every GridDescriptorProviderInterface (the grid controllers,
 * plus whatever a host declares for its runtime grids), merged. Built on first
 * use, so no provider is asked anything while a grid merely renders.
 */
class GridRegistry
{
    /** @var array<string, GridDescriptor>|null grid id => descriptor, sorted by id */
    private ?array $grids = null;

    /**
     * @param iterable<GridDescriptorProviderInterface> $providers
     */
    public function __construct(private readonly iterable $providers)
    {
    }

    /** @return array<string, GridDescriptor> grid id => descriptor */
    public function all(): array
    {
        if ($this->grids === null) {
            $this->grids = [];
            foreach ($this->providers as $provider) {
                foreach ($provider->getGridDescriptors() as $descriptor) {
                    // Two grids may share an id (e.g. a second grid over the
                    // same entity): their settings scope is shared too, keep the first.
                    $this->grids[$descriptor->id] ??= $descriptor;
                }
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
