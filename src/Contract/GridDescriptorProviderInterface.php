<?php

namespace Fedale\GridviewBundle\Contract;

use Fedale\GridviewBundle\UiSettings\GridDescriptor;

/**
 * Lists grids for the per-grid scopes of the UI settings modal. The grid
 * controllers are described by a built-in provider; implement this for grids a
 * host builds at runtime with GridviewBuilderFactory (one per record type,
 * say), which have no controller of their own to describe them.
 *
 * Autoconfigured with the `fedale_gridview.grid_descriptor_provider` tag. Only
 * asked when the modal needs the list, never while a grid renders.
 */
interface GridDescriptorProviderInterface
{
    /**
     * Each descriptor's options are a copy of the options the host passes to
     * the grid's setOptions(): the modal reads its renderer map from them. Build
     * both from the same code so they cannot drift apart.
     *
     * @return iterable<GridDescriptor>
     */
    public function getGridDescriptors(): iterable;
}
