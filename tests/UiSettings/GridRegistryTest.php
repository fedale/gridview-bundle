<?php

namespace Fedale\GridviewBundle\Tests\UiSettings;

use Fedale\GridviewBundle\Contract\GridDescriptorProviderInterface;
use Fedale\GridviewBundle\Controller\AbstractGridController;
use Fedale\GridviewBundle\UiSettings\ControllerGridDescriptorProvider;
use Fedale\GridviewBundle\UiSettings\GridDescriptor;
use Fedale\GridviewBundle\UiSettings\GridRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

class GridRegistryTest extends TestCase
{
    private function provider(GridDescriptor ...$descriptors): GridDescriptorProviderInterface
    {
        return new class($descriptors) implements GridDescriptorProviderInterface {
            public int $calls = 0;

            /** @param list<GridDescriptor> $descriptors */
            public function __construct(private readonly array $descriptors)
            {
            }

            public function getGridDescriptors(): iterable
            {
                ++$this->calls;

                return $this->descriptors;
            }
        };
    }

    public function testDescriptorsOfEveryProviderAreMergedAndSortedById(): void
    {
        $registry = new GridRegistry([
            $this->provider(new GridDescriptor('post', 'Posts', [])),
            $this->provider(new GridDescriptor('record_pet', 'Pets', []), new GridDescriptor('category', null, [])),
        ]);

        $this->assertSame(['category', 'post', 'record_pet'], array_keys($registry->all()));
    }

    public function testARuntimeGridIsAScope(): void
    {
        $options = ['display' => ['renderer' => ['default' => 'table', 'map' => ['table' => [], 'card' => []]]]];
        $registry = new GridRegistry([$this->provider(new GridDescriptor('record_pet', 'Pets', $options))]);

        $this->assertSame($options, $registry->get('record_pet')?->options);
        $this->assertNull($registry->get('record_owner'));
    }

    public function testTheFirstDescriptorOfAnIdWins(): void
    {
        $registry = new GridRegistry([
            $this->provider(new GridDescriptor('post', 'From the controller', [])),
            $this->provider(new GridDescriptor('post', 'From the host', [])),
        ]);

        $this->assertSame('From the controller', $registry->get('post')?->label);
    }

    public function testProvidersAreAskedOnceAndOnlyWhenNeeded(): void
    {
        $provider = $this->provider(new GridDescriptor('post', null, []));
        $registry = new GridRegistry([$provider]);

        $this->assertSame(0, $provider->calls);
        $registry->all();
        $registry->get('post');
        $this->assertSame(1, $provider->calls);
    }

    public function testTheControllerProviderDescribesEachController(): void
    {
        $controller = $this->createMock(AbstractGridController::class);
        $controller->method('describeGrid')->willReturn(new GridDescriptor('post', 'Posts', []));

        $provider = new ControllerGridDescriptorProvider(new ServiceLocator(['app.post_controller' => static fn() => $controller]));

        $this->assertEquals([new GridDescriptor('post', 'Posts', [])], iterator_to_array($provider->getGridDescriptors(), false));
    }
}
