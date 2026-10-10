<?php

namespace Fedale\GridviewBundle\UiSettings;

use Fedale\SettingBundle\Scoped\Scope;
use Fedale\SettingBundle\Scoped\ScopeChain;
use Fedale\SettingBundle\Scoped\ScopedSettingsResolver;
use Fedale\SettingBundle\Scoped\ScopedSettingsWriter;
use Fedale\SettingBundle\Scoped\SettingDefinitionRegistry;

/**
 * Applies the stored UI settings to a grid's options and reads/writes them for
 * the settings UI, on top of fedale/setting-bundle's scoped settings.
 *
 * Precedence, lowest to highest: bundle/YAML defaults < controller viewConfig()
 * < global UI setting < per-grid UI setting, the current tenant's values before
 * the platform's (tenant 0) ones. The `?view=` query parameter still overrides
 * the renderer at request time (see Gridview::getRenderer()). A value the grid
 * cannot honour ({@see UiSettingInterface::isApplicable()}) is skipped and the
 * next scope is tried.
 *
 * Only defined when setting-bundle is installed and enabled; the feature is off
 * until its `fedale_setting.scoped.store` is configured.
 */
class UiSettingsResolver
{
    /** setting-bundle namespace of the UI settings. */
    public const NAMESPACE = 'gridview.ui';

    /** Scope level of a single grid; the scope id is the grid id. */
    public const GRID_LEVEL = 'grid';

    /** Value of the modal's `scope` parameter for the global scope. */
    public const GLOBAL_SCOPE = '_global';

    public function __construct(
        private readonly ScopedSettingsResolver $resolver,
        private readonly ScopedSettingsWriter $writer,
        private readonly SettingDefinitionRegistry $definitions,
    ) {
    }

    /** Whether setting-bundle has a real store: with its null store the feature is off. */
    public function isEnabled(): bool
    {
        return $this->resolver->isEnabled();
    }

    /**
     * The settings offered in a scope: the global ones when $grid is null, else
     * those that apply to that grid.
     *
     * @return array<string, UiSettingInterface>
     */
    public function settingsFor(?GridDescriptor $grid): array
    {
        return array_filter(
            $this->settings(),
            static fn(UiSettingInterface $s): bool => $grid === null ? $s->supportsGlobal() : $s->supportsGrid($grid),
        );
    }

    /**
     * @param string $scope {@see GLOBAL_SCOPE} or a grid id
     *
     * @return array<string, mixed> setting key => value stored for the scope (no inheritance)
     */
    public function values(string $scope): array
    {
        return $this->resolver->values(self::NAMESPACE, self::scope($scope));
    }

    /**
     * What each setting of a scope falls back to when its own field is left on
     * "inherit": the next value along the precedence (the global scope, the
     * platform's values), as the grid would apply it.
     *
     * @param string $scope {@see GLOBAL_SCOPE} or a grid id
     *
     * @return array<string, mixed> setting key => inherited value
     */
    public function inherited(string $scope, ?GridDescriptor $grid = null): array
    {
        $own = self::scope($scope);
        $chain = new ScopeChain($own->isGlobal() ? [$own] : [$own, Scope::global()]);

        $values = [];
        foreach ($this->resolver->inherited(self::NAMESPACE, $chain, $grid?->options) as $key => $resolved) {
            $values[$key] = $resolved->value;
        }

        return $values;
    }

    /**
     * Writes the values of a scope. Null and '' mean "inherit" and clear the
     * stored value; keys of settings not offered in the scope are ignored.
     *
     * @param string               $scope  {@see GLOBAL_SCOPE} or a grid id
     * @param array<string, mixed> $values
     */
    public function save(string $scope, array $values, ?GridDescriptor $grid = null): void
    {
        $this->writer->save(
            self::NAMESPACE,
            self::scope($scope),
            array_intersect_key($values, $this->settingsFor($grid)),
        );
    }

    /**
     * Writes the effective UI settings of grid $gridId into $options, the grid's
     * options already merged from YAML and the controller.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function apply(string $gridId, array $options): array
    {
        $settings = $this->settings();
        if ($settings === [] || !$this->isEnabled()) {
            return $options;
        }

        $chain = new ScopeChain([self::scope($gridId), Scope::global()]);
        // The grid's options are the context of isApplicable(): a value the grid
        // can't honour (e.g. its renderer map changed since) falls through to
        // the next scope.
        foreach ($this->resolver->resolve(self::NAMESPACE, $chain, $options) as $key => $resolved) {
            if (isset($settings[$key])) {
                $options = self::setPath($options, $settings[$key]->optionPath(), $resolved->value);
            }
        }

        return $options;
    }

    /**
     * The UI settings among the `gridview.ui` definitions: another definition of
     * the namespace has no option path to write to.
     *
     * @return array<string, UiSettingInterface>
     */
    private function settings(): array
    {
        return array_filter(
            $this->definitions->all(self::NAMESPACE),
            static fn(object $d): bool => $d instanceof UiSettingInterface,
        );
    }

    private static function scope(string $scope): Scope
    {
        return $scope === self::GLOBAL_SCOPE ? Scope::global() : new Scope(self::GRID_LEVEL, $scope);
    }

    /**
     * @param array<string, mixed> $array
     *
     * @return array<string, mixed>
     */
    private static function setPath(array $array, string $path, mixed $value): array
    {
        $ref = &$array;
        foreach (explode('.', $path) as $segment) {
            if (!isset($ref[$segment]) || !\is_array($ref[$segment])) {
                $ref[$segment] = [];
            }
            $ref = &$ref[$segment];
        }
        $ref = $value;

        return $array;
    }
}
