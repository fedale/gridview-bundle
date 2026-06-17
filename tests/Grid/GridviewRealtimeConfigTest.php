<?php

namespace Fedale\GridviewBundle\Tests\Grid;

use Fedale\GridviewBundle\Grid\GridviewConfigRegistry;
use PHPUnit\Framework\TestCase;

class GridviewRealtimeConfigTest extends TestCase
{
    public function testRealtimeDisabledByDefault(): void
    {
        $registry = new GridviewConfigRegistry([]);

        $realtime = $registry->resolveOptions(null)['realtime'];

        $this->assertFalse($realtime['enabled']);
        $this->assertSame('gridview/', $realtime['topicPrefix']);
    }

    public function testRealtimeEnabledViaDefaults(): void
    {
        $registry = new GridviewConfigRegistry([
            'defaults' => ['options' => ['realtime' => ['enabled' => true, 'topicPrefix' => 'gridview/']]],
        ]);

        $this->assertTrue($registry->resolveOptions(null)['realtime']['enabled']);
    }

    public function testRealtimeEnabledPerGridOnly(): void
    {
        $registry = new GridviewConfigRegistry([
            'gridviews' => [
                'customer' => ['options' => ['realtime' => ['enabled' => true, 'topicPrefix' => 'gridview/']]],
            ],
        ]);

        // The opted-in grid is enabled...
        $this->assertTrue($registry->resolveOptions('customer')['realtime']['enabled']);
        // ...while others fall back to the disabled default.
        $this->assertFalse($registry->resolveOptions('user')['realtime']['enabled']);
    }
}
