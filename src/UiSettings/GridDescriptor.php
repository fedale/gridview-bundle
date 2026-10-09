<?php

namespace Fedale\GridviewBundle\UiSettings;

/**
 * What the settings UI needs to know about one grid, without rendering it: its
 * id, its heading and the options its controller declares in viewConfig()
 * (e.g. the renderer map). Built by AbstractGridController::describeGrid().
 */
final class GridDescriptor
{
    /**
     * @param array<string, mixed> $options the controller's `options` config (display/behavior/integration)
     */
    public function __construct(
        public readonly string $id,
        public readonly ?string $label,
        public readonly array $options,
    ) {
    }
}
