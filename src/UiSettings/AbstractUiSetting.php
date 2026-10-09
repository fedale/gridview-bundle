<?php

namespace Fedale\GridviewBundle\UiSettings;

/**
 * Convenience base for {@see UiSettingInterface}: offered in both scopes, no
 * help text, and a value is applicable when it is one of the grid's choices.
 * A concrete setting only declares its key, path, label and choices.
 */
abstract class AbstractUiSetting implements UiSettingInterface
{
    public function help(): ?string
    {
        return null;
    }

    public function supportsGlobal(): bool
    {
        return true;
    }

    public function supportsGrid(GridDescriptor $grid): bool
    {
        return true;
    }

    public function isApplicable(mixed $value, array $options): bool
    {
        return \in_array($value, $this->choices(null), true);
    }
}
