<?php

namespace Fedale\GridviewBundle\UiSettings;

use Fedale\SettingBundle\Scoped\AbstractSettingDefinition;
use Fedale\SettingBundle\Scoped\Scope;
use Fedale\SettingBundle\Scoped\SettingType;

/**
 * Convenience base for {@see UiSettingInterface}: a choice offered in both
 * scopes, no help text, and a value is applicable when it is one of the global
 * choices. A concrete setting only declares its key, path, label and choices.
 */
abstract class AbstractUiSetting extends AbstractSettingDefinition implements UiSettingInterface
{
    public function namespace(): string
    {
        return UiSettingsResolver::NAMESPACE;
    }

    public function type(): SettingType
    {
        return SettingType::Enum;
    }

    public function supportsGlobal(): bool
    {
        return true;
    }

    public function supportsGrid(GridDescriptor $grid): bool
    {
        return true;
    }

    /**
     * The modal narrows per grid with supportsGrid(); here only the level is
     * checked, since a grid scope carries just the grid id.
     */
    public function supportsScope(Scope $scope): bool
    {
        if ($scope->isGlobal()) {
            return $this->supportsGlobal();
        }

        return $scope->level === UiSettingsResolver::GRID_LEVEL;
    }

    /**
     * The global choices are checked by setting-bundle on write and read. A grid
     * scope answers null (free input): its choices depend on the grid's options,
     * which the modal form enforces on write and isApplicable() at render time.
     */
    public function choices(?Scope $scope): ?array
    {
        if ($scope === null || $scope->isGlobal()) {
            return $this->choicesFor(null);
        }

        return null;
    }

    public function isApplicable(mixed $value, mixed $context): bool
    {
        return \in_array($value, $this->choicesFor(null), true);
    }
}
