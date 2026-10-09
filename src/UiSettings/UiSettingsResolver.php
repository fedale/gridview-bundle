<?php

namespace Fedale\GridviewBundle\UiSettings;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Applies the stored UI settings to a grid's options and reads/writes them for
 * the settings UI.
 *
 * Precedence, lowest to highest: bundle/YAML defaults < controller viewConfig()
 * < global UI setting < per-grid UI setting. The `?view=` query parameter still
 * overrides the renderer at request time (see Gridview::getRenderer()). A value
 * the grid cannot honour ({@see UiSettingInterface::isApplicable()}) is skipped.
 */
class UiSettingsResolver implements ResetInterface
{
    public const GLOBAL_SCOPE = '_global';

    /** @var array<string, UiSettingInterface> */
    private array $settings = [];

    /** @var array<string, array<string, mixed>> per-request cache, scope => values */
    private array $loaded = [];

    /**
     * @param iterable<UiSettingInterface> $settings
     */
    public function __construct(
        iterable $settings,
        private readonly UiSettingsStoreInterface $store,
    ) {
        foreach ($settings as $setting) {
            $this->settings[$setting->key()] = $setting;
        }
    }

    /** Whether a real store is wired: with the null store the feature is off. */
    public function isEnabled(): bool
    {
        return !$this->store instanceof NullUiSettingsStore;
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
            $this->settings,
            static fn (UiSettingInterface $s): bool => $grid === null ? $s->supportsGlobal() : $s->supportsGrid($grid),
        );
    }

    /** @return array<string, mixed> setting key => stored value for the scope */
    public function values(string $scope): array
    {
        return $this->loaded[$scope] ??= $this->store->load($scope);
    }

    /**
     * Replaces the stored values of a scope. Null values mean "inherit" and are
     * dropped; keys of unknown settings are ignored.
     *
     * @param array<string, mixed> $values
     */
    public function save(string $scope, array $values): void
    {
        $values = array_filter(
            array_intersect_key($values, $this->settings),
            static fn (mixed $v): bool => $v !== null && $v !== '',
        );
        $this->store->save($scope, $values);
        $this->loaded[$scope] = $values;
    }

    /**
     * Writes the effective UI settings of grid $gridId into $options, the grid's
     * options already merged from YAML and the controller.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function apply(string $gridId, array $options): array
    {
        if ($this->settings === [] || !$this->isEnabled()) {
            return $options;
        }

        $global = $this->values(self::GLOBAL_SCOPE);
        $own = $gridId === self::GLOBAL_SCOPE ? [] : $this->values($gridId);

        foreach ($this->settings as $key => $setting) {
            // Grid value first; a grid value the grid can't honour (e.g. its
            // renderer map changed since) falls back to the global one.
            foreach ([$own, $global] as $bag) {
                if (!\array_key_exists($key, $bag) || !$setting->isApplicable($bag[$key], $options)) {
                    continue;
                }
                $options = self::setPath($options, $setting->optionPath(), $bag[$key]);
                break;
            }
        }

        return $options;
    }

    public function reset(): void
    {
        $this->loaded = [];
    }

    /**
     * @param array<string, mixed> $array
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
