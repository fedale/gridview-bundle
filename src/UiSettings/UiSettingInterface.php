<?php

namespace Fedale\GridviewBundle\UiSettings;

use Fedale\SettingBundle\Scoped\SettingDefinitionInterface;

/**
 * One grid option the end user may set at runtime from the UI settings modal,
 * either globally (every grid) or for a single grid (which wins over global).
 *
 * This is the extension point of the configurator: a new configurable option is
 * one class implementing this interface (or extending {@see AbstractUiSetting}).
 * It is a fedale/setting-bundle scoped setting of the `gridview.ui` namespace,
 * at the scope levels `global` and `grid`: setting-bundle autoconfigures it,
 * stores it and resolves it (tenant first, then grid before global). The modal
 * form is built from the definitions, so no template or controller change is
 * needed.
 *
 * Values are applied at render time over the controller's own viewConfig(), and
 * only when isApplicable() accepts them for that grid: its context is the grid's
 * resolved options array. A stored value a grid cannot honour is skipped and the
 * lookup moves on to the next scope, down to the grid's own configuration.
 */
interface UiSettingInterface extends SettingDefinitionInterface
{
    /**
     * Dotted path of the grid option the value is written to, relative to the
     * resolved options array (e.g. 'display.renderer.default'). Only that leaf
     * is replaced: sibling keys are preserved.
     */
    public function optionPath(): string;

    /** Whether the setting is offered in the global ("all grids") scope. */
    public function supportsGlobal(): bool;

    /** Whether the setting is offered for this grid's own scope. */
    public function supportsGrid(GridDescriptor $grid): bool;

    /**
     * The values the user can pick in the modal, as `translation key => value`.
     * $grid is null for the global scope, else the grid being configured, so the
     * list can be narrowed to what that grid supports.
     *
     * @return array<string, scalar>
     */
    public function choicesFor(?GridDescriptor $grid): array;
}
