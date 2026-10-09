<?php

namespace Fedale\GridviewBundle\UiSettings;

/**
 * Persistence of the UI settings, one value bag per scope. The host app
 * provides the implementation (database, session, settings bundle, ...) and
 * selects it with `fedale_gridview.ui_settings.store`; the bundle default is
 * {@see NullUiSettingsStore}, which keeps the feature off.
 *
 * A scope is either {@see UiSettingsResolver::GLOBAL_SCOPE} or a grid id. A
 * setting key missing from a bag means "inherit".
 */
interface UiSettingsStoreInterface
{
    /** @return array<string, mixed> setting key => value */
    public function load(string $scope): array;

    /** @param array<string, mixed> $values setting key => value; replaces the whole bag */
    public function save(string $scope, array $values): void;
}
