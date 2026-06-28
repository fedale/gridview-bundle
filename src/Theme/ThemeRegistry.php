<?php

namespace Fedale\GridviewBundle\Theme;

/**
 * Resolves logical "class keys" (e.g. `btn.primary`) into concrete CSS class
 * strings for the active theme.
 *
 * The `default` theme reproduces the bundle's own framework-agnostic `gv-*`
 * look. Framework themes (`bootstrap5`, `tailwind`) emit the framework's real
 * component classes so the host application's CSS styles the element directly.
 * Host apps can declare custom themes entirely from YAML
 * (`fedale_gridview.themes`), optionally extending a built-in one.
 *
 * Theming is presentation-only: it maps the leaf presentational classes and
 * never touches the structural / JS `gv-*` hooks (handled via `data-*`).
 */
class ThemeRegistry
{
    /**
     * Built-in class maps. `default` is the canonical, complete key set — the
     * closed list of themable leaves; every other theme overrides a subset and
     * inherits `default` for any key it omits.
     *
     * @var array<string, array<string, string>>
     */
    private const BUILTIN = [
        'default' => [
            'btn'                 => 'gv-btn',
            'btn.primary'         => 'gv-btn gv-btn-primary',
            'btn.danger'          => 'gv-btn gv-btn-danger',
            'btn.icon'            => 'gv-btn-icon',
            'pagination'          => 'gv-pagination',
            'pagination.item'     => 'gv-page-item',
            'pagination.link'     => 'gv-page-link',
            'pagination.active'   => 'gv-active',
            'pagination.disabled' => 'gv-disabled',
        ],
        'bootstrap5' => [
            'btn'                 => 'btn btn-sm btn-secondary',
            'btn.primary'         => 'btn btn-sm btn-primary',
            'btn.danger'          => 'btn btn-sm btn-danger',
            // No Bootstrap equivalent: keep the bundle's tiny icon helper.
            'btn.icon'            => 'gv-btn-icon',
            'pagination'          => 'pagination',
            'pagination.item'     => 'page-item',
            'pagination.link'     => 'page-link',
            'pagination.active'   => 'active',
            'pagination.disabled' => 'disabled',
        ],
        // Tailwind has no pagination component: those keys are omitted and fall
        // back to the structural gv-* pagination, tinted by the tailwind preset.
        'tailwind' => [
            'btn'                 => 'inline-flex items-center gap-1 rounded border border-gray-300 bg-white px-2.5 py-1 text-sm hover:bg-gray-50',
            'btn.primary'         => 'inline-flex items-center gap-1 rounded bg-indigo-600 px-2.5 py-1 text-sm text-white hover:bg-indigo-700',
            'btn.danger'          => 'inline-flex items-center gap-1 rounded bg-red-600 px-2.5 py-1 text-sm text-white hover:bg-red-700',
            'btn.icon'            => 'gv-btn-icon',
        ],
    ];

    /**
     * @param array<string, array{extends?: string, classes?: array<string, string>}> $customThemes
     *        Host-declared themes from `fedale_gridview.themes`.
     */
    public function __construct(private array $customThemes = [])
    {
    }

    /**
     * The complete class map for a theme: the `default` map overlaid with the
     * theme's overrides (built-in or custom, following any `extends` chain).
     * Any key the theme omits falls back to the `default` (`gv-*`) class, so a
     * partial custom theme is valid.
     *
     * @return array<string, string>
     */
    public function classMap(string $theme): array
    {
        return array_replace(self::BUILTIN['default'], $this->overrides($theme, []));
    }

    /**
     * A single class key resolved for a theme. Convenience around
     * {@see classMap()} (which the renderer pre-resolves once per grid).
     */
    public function resolve(string $theme, string $key): string
    {
        return $this->classMap($theme)[$key] ?? '';
    }

    /**
     * Names of all known themes (built-in + custom), for validation/docs.
     *
     * @return string[]
     */
    public function knownThemes(): array
    {
        return array_values(array_unique([
            ...array_keys(self::BUILTIN),
            ...array_keys($this->customThemes),
        ]));
    }

    /**
     * Theme overrides only (without the `default` base), resolving `extends`
     * chains and guarding against cycles via $seen.
     *
     * @param string[] $seen
     * @return array<string, string>
     */
    private function overrides(string $theme, array $seen): array
    {
        if ($theme === 'default' || in_array($theme, $seen, true)) {
            return [];
        }
        $seen[] = $theme;

        // A custom theme of the same name as a built-in wins over it.
        if (isset($this->customThemes[$theme])) {
            $def = $this->customThemes[$theme];

            return array_replace(
                $this->overrides($def['extends'] ?? 'default', $seen),
                $def['classes'] ?? []
            );
        }

        return self::BUILTIN[$theme] ?? [];
    }
}
