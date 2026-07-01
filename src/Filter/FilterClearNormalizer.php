<?php

namespace Fedale\GridviewBundle\Filter;

/**
 * Validates and normalizes the per-column `filter.clear` spec into a canonical
 * shape describing how a single column's filter can be cleared.
 *
 * Accepted input forms:
 *   'clear' => 'chip'                    // single mode (shorthand)
 *   'clear' => ['header', 'chip']        // several affordances at once
 *   'clear' => [                          // extended form with custom icons
 *       'mode'     => ['header', 'chip'],
 *       'icon'     => '<svg …>',          // glyph for the header/input clear button
 *       'chipIcon' => '<svg …>',          // glyph for the chip close button
 *   ]
 *
 * Canonical output: ['mode' => string[], 'icon' => ?string, 'chipIcon' => ?string].
 */
final class FilterClearNormalizer
{
    /** Supported clear affordances. 'none' is a sentinel that empties the list. */
    private const MODES = ['header', 'input', 'chip', 'none'];

    /**
     * @param mixed $clear             The raw `filter.clear` spec (or null when omitted).
     * @param bool  $inlineClearDefault Grid-level filterControls.inlineClear — only
     *                                  applied when no explicit clear spec is given.
     *
     * @return array{mode: string[], icon: ?string, chipIcon: ?string}
     */
    public static function normalize(mixed $clear, bool $inlineClearDefault = false): array
    {
        // No explicit spec: keep the historical default (funnel icon in the header),
        // plus the inline "X" when the grid opted into it.
        if ($clear === null) {
            $mode = ['header'];
            if ($inlineClearDefault) {
                $mode[] = 'input';
            }

            return ['mode' => $mode, 'icon' => null, 'chipIcon' => null];
        }

        $icon     = null;
        $chipIcon = null;

        if (\is_array($clear) && \array_key_exists('mode', $clear)) {
            $rawMode  = $clear['mode'];
            $icon     = $clear['icon'] ?? null;
            $chipIcon = $clear['chipIcon'] ?? null;
        } else {
            $rawMode = $clear;
        }

        $modes = self::normalizeModes($rawMode);

        return ['mode' => $modes, 'icon' => $icon, 'chipIcon' => $chipIcon];
    }

    /**
     * @return string[]
     */
    private static function normalizeModes(mixed $rawMode): array
    {
        if (\is_string($rawMode)) {
            $rawMode = [$rawMode];
        }

        if (!\is_array($rawMode)) {
            throw new \InvalidArgumentException(
                'Filter "clear" mode must be a string or an array of strings.'
            );
        }

        $modes = [];
        foreach ($rawMode as $mode) {
            if (!\is_string($mode) || !\in_array($mode, self::MODES, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'Invalid filter "clear" mode "%s". Allowed: %s.',
                    \is_string($mode) ? $mode : \get_debug_type($mode),
                    implode(', ', self::MODES)
                ));
            }

            // 'none' disables every affordance regardless of the rest.
            if ($mode === 'none') {
                return [];
            }

            if (!\in_array($mode, $modes, true)) {
                $modes[] = $mode;
            }
        }

        return $modes;
    }
}
