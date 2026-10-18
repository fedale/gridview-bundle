<?php

namespace Fedale\GridviewBundle\UiSettings;

use Fedale\GridviewBundle\Contract\GridDescriptorProviderInterface;
use Fedale\GridviewBundle\Controller\AbstractGridController;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * The grid controllers (any AbstractGridController subclass, autoconfigured
 * with the `fedale_gridview.grid` tag), each describing its own grid. They
 * arrive as a lazy locator, so a controller is only instantiated when the
 * list is actually needed.
 */
final class ControllerGridDescriptorProvider implements GridDescriptorProviderInterface
{
    /**
     * @param ServiceLocator<AbstractGridController> $controllers
     */
    public function __construct(private readonly ServiceLocator $controllers)
    {
    }

    public function getGridDescriptors(): iterable
    {
        foreach (array_keys($this->controllers->getProvidedServices()) as $serviceId) {
            yield $this->controllers->get($serviceId)->describeGrid();
        }
    }
}
