<?php

namespace Fedale\GridviewBundle\Tests\Grid;

use Fedale\GridviewBundle\Grid\GridviewConfigRegistry;
use PHPUnit\Framework\TestCase;

class DetailViewConfigTest extends TestCase
{
    public function testDetailDefaultsWhenEmpty(): void
    {
        $registry = new GridviewConfigRegistry([]);

        $options    = $registry->resolveDetailOptions(null);
        $attributes = $registry->resolveDetailAttributes(null);

        $this->assertSame('No data', $options['emptyText']);
        $this->assertFalse($options['onlyVisible']);
        $this->assertSame('table table-bordered', $attributes['class']);
    }

    public function testGridOnlyKeysNeverLeakIntoDetail(): void
    {
        $registry = new GridviewConfigRegistry([]);

        $options = $registry->resolveDetailOptions(null);

        // The detail must not inherit list-only knobs from the grid defaults.
        foreach (['pagination', 'realtime', 'globalSearch', 'layout'] as $gridOnly) {
            $this->assertArrayNotHasKey($gridOnly, $options);
        }
    }

    public function testPerIdOverridesDefaults(): void
    {
        $registry = new GridviewConfigRegistry([
            'defaults' => [
                'detailview' => [
                    'options'    => ['emptyText' => 'Empty', 'onlyVisible' => false],
                    'attributes' => ['class' => 'table'],
                ],
            ],
            'detailviews' => [
                'customer' => [
                    'options'    => ['onlyVisible' => true],
                    'attributes' => ['class' => 'table table-sm', 'id' => 'customer-detail'],
                ],
            ],
        ]);

        $options    = $registry->resolveDetailOptions('customer');
        $attributes = $registry->resolveDetailAttributes('customer');

        // per-id wins, untouched default keys survive
        $this->assertTrue($options['onlyVisible']);
        $this->assertSame('Empty', $options['emptyText']);
        $this->assertSame('table table-sm', $attributes['class']);
        $this->assertSame('customer-detail', $attributes['id']);

        // a different id falls back to the defaults layer
        $this->assertFalse($registry->resolveDetailOptions('user')['onlyVisible']);
        $this->assertSame('table', $registry->resolveDetailAttributes('user')['class']);
    }

    public function testDetailSectionIsDisjointFromGridviewsSection(): void
    {
        // Same id used by BOTH a grid and a detail: they must not collide.
        $registry = new GridviewConfigRegistry([
            'gridviews'   => ['customer' => ['options' => ['emptyText' => 'No records']]],
            'detailviews' => ['customer' => ['options' => ['emptyText' => 'No record']]],
        ]);

        $this->assertSame('No records', $registry->resolveOptions('customer')['emptyText']);
        $this->assertSame('No record', $registry->resolveDetailOptions('customer')['emptyText']);
    }
}
