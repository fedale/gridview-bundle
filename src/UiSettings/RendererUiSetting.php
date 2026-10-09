<?php

namespace Fedale\GridviewBundle\UiSettings;

/**
 * The default data renderer (table / card / list), i.e.
 * `options.display.renderer.default`.
 *
 * A grid can only show the renderers declared in its own `renderer.map`, so the
 * per-grid choices are that map's keys, and the setting is offered only for
 * grids with more than one renderer. A global value naming a renderer a grid
 * does not map is skipped for that grid. The `?view=` query parameter (the
 * view switcher) still wins over the stored default.
 */
final class RendererUiSetting extends AbstractUiSetting
{
    private const BUILT_IN = ['table', 'card', 'list'];

    public function key(): string
    {
        return 'renderer';
    }

    public function optionPath(): string
    {
        return 'display.renderer.default';
    }

    public function label(): string
    {
        return 'ui_settings.renderer.label';
    }

    public function help(): string
    {
        return 'ui_settings.renderer.help';
    }

    public function supportsGrid(GridDescriptor $grid): bool
    {
        return \count($this->renderers($grid->options)) > 1;
    }

    public function choices(?GridDescriptor $grid): array
    {
        $names = $grid === null ? self::BUILT_IN : $this->renderers($grid->options);

        $choices = [];
        foreach ($names as $name) {
            $choices['ui_settings.renderer.' . $name] = $name;
        }

        return $choices;
    }

    public function isApplicable(mixed $value, array $options): bool
    {
        return \is_string($value) && \in_array($value, $this->renderers($options), true);
    }

    /**
     * @param array<string, mixed> $options
     * @return list<string>
     */
    private function renderers(array $options): array
    {
        $map = $options['display']['renderer']['map'] ?? [];

        return \is_array($map) ? array_map('strval', array_keys($map)) : [];
    }
}
