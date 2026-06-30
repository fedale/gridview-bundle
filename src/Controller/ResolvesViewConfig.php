<?php

namespace Fedale\GridviewBundle\Controller;

/**
 * Resolves a controller's view configuration: deep-merges the per-controller
 * {@see viewConfig()} over {@see defaultConfig()} group by group, applies the
 * naming conventions (id-derived labels/filenames) and exposes the result
 * through a dotted-path accessor (e.g. {@see config()} with `'form.theme'`).
 *
 * Shared by the grid, CRUD and detail controller bases so the merge/derivation
 * logic lives in one place.
 */
trait ResolvesViewConfig
{
    private ?array $resolvedConfig = null;

    /** @return array<string, mixed> Per-controller overrides; a concrete controller lists only what it changes. */
    abstract protected function viewConfig(): array;

    /** @return array<string, mixed> Baseline config carrying every key the controller understands. */
    abstract protected function defaultConfig(): array;

    /**
     * Resolved config accessor. Pass null for the whole array, a plain key
     * (`'id'`) or a dotted path (`'form.theme'`, `'labels.heading'`) for a
     * nested value. Resolved once and cached.
     */
    protected function config(?string $key = null, mixed $default = null): mixed
    {
        if ($this->resolvedConfig === null) {
            $this->resolvedConfig = $this->applyConventions(
                $this->mergeConfig($this->defaultConfig(), $this->viewConfig())
            );
        }

        if ($key === null) {
            return $this->resolvedConfig;
        }

        if (!str_contains($key, '.')) {
            return $this->resolvedConfig[$key] ?? $default;
        }

        $value = $this->resolvedConfig;
        foreach (explode('.', $key) as $segment) {
            if (!\is_array($value) || !\array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * Deep-merges $overrides over $defaults. A key whose default and override
     * are both associative arrays is merged recursively (so an override may set
     * just some sub-keys); scalars and list arrays (e.g. `form.actions.buttons`)
     * are replaced wholesale.
     *
     * @param array<string, mixed> $defaults
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected function mergeConfig(array $defaults, array $overrides): array
    {
        $merged = $defaults;
        foreach ($overrides as $key => $value) {
            $base = $defaults[$key] ?? null;
            if (\is_array($base) && $this->isAssoc($base) && \is_array($value) && $this->isAssoc($value)) {
                $merged[$key] = $this->mergeConfig($base, $value);
            } else {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }

    /** True for a non-empty associative (string-keyed) array; false for a list or empty array. */
    private function isAssoc(array $value): bool
    {
        return $value !== [] && !array_is_list($value);
    }

    /**
     * Fills the convention-derived defaults still null after the merge. The base
     * implementation derives the export filename from the grid id; a subclass
     * overrides to add its own (e.g. id-derived labels) and calls parent.
     *
     * @param array<string, mixed> $resolved
     * @return array<string, mixed>
     */
    protected function applyConventions(array $resolved): array
    {
        if (\is_array($resolved['export'] ?? null) && ($resolved['export']['filename'] ?? null) === null) {
            $resolved['export']['filename'] = $resolved['id'] ?? null;
        }

        return $resolved;
    }
}
