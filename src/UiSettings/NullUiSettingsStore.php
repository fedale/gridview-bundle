<?php

namespace Fedale\GridviewBundle\UiSettings;

/**
 * Default store: nothing is stored, so grids render exactly as configured in
 * code and the settings modal is disabled (its route answers 404).
 */
final class NullUiSettingsStore implements UiSettingsStoreInterface
{
    public function load(string $scope): array
    {
        return [];
    }

    public function save(string $scope, array $values): void
    {
    }
}
