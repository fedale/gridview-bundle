<?php

namespace Fedale\GridviewBundle\UiSettings;

/**
 * One grid option the end user may set at runtime from the UI settings modal,
 * either globally (every grid) or for a single grid (which wins over global).
 *
 * This is the extension point of the configurator: a new configurable option is
 * one class implementing this interface (or extending {@see AbstractUiSetting}).
 * Services implementing it are autoconfigured with the `fedale_gridview.ui_setting`
 * tag and picked up by {@see UiSettingsResolver}; the modal form is built from
 * the definitions, so no template or controller change is needed.
 *
 * Values are applied at render time over the controller's own viewConfig(), and
 * only when {@see isApplicable()} accepts them for that grid — a stored value a
 * grid cannot honour is skipped, the grid keeps its own configuration.
 */
interface UiSettingInterface
{
    /** Stable storage key, unique across settings (e.g. 'renderer'). */
    public function key(): string;

    /**
     * Dotted path of the grid option the value is written to, relative to the
     * resolved options array (e.g. 'display.renderer.default'). Only that leaf
     * is replaced: sibling keys are preserved.
     */
    public function optionPath(): string;

    /** Field label, a key of the `GridviewBundle` translation domain. */
    public function label(): string;

    /** Optional help text, a key of the `GridviewBundle` translation domain. */
    public function help(): ?string;

    /** Whether the setting is offered in the global ("all grids") scope. */
    public function supportsGlobal(): bool;

    /** Whether the setting is offered for this grid's own scope. */
    public function supportsGrid(GridDescriptor $grid): bool;

    /**
     * The values the user can pick, as `translation key => value`. $grid is
     * null for the global scope, else the grid being configured, so the list can
     * be narrowed to what that grid supports.
     *
     * @return array<string, scalar>
     */
    public function choices(?GridDescriptor $grid): array;

    /**
     * Final guard at render time: true when $value can be applied to a grid
     * whose resolved options are $options. A global value is checked against
     * every grid, so it must reject what a given grid cannot honour.
     *
     * @param array<string, mixed> $options
     */
    public function isApplicable(mixed $value, array $options): bool;
}
