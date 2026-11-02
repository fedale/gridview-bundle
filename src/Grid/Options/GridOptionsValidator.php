<?php

namespace Fedale\GridviewBundle\Grid\Options;

use Fedale\GridviewBundle\Exception\InvalidGridOptionsException;
use Fedale\GridviewBundle\Grid\GridviewConfigRegistry;
use Fedale\GridviewBundle\Grid\TemplateCatalog;
use Fedale\GridviewBundle\Pagination\Strategy\PaginatorStrategyRegistry;
use Fedale\GridviewBundle\Theme\ThemeRegistry;

/**
 * Checks a grid's options array and reports every problem at once, each with
 * its path and, for a misspelt key or token, the closest valid name.
 *
 * It exists because the options merge (Gridview::setOptions()) is a plain
 * array_replace: an unknown key is merged and ignored, an unknown renderer
 * falls back to the table, an unknown layout token renders nothing. None of
 * that raises an error, so a mistake only shows up as a grid that quietly
 * looks wrong. Two passes:
 *
 *  1. shape — keys and value types, against {@see GridOptionsSchema};
 *  2. names — the values that must match something registered: the theme, the
 *     paginator mode, the renderers, the tokens used in the layout regions.
 *
 * The registries are optional: without one, that name check is skipped. The
 * bundle wires all of them; a host (an app builder, an MCP server) can call
 * {@see validate()} on an options array it is about to hand to a grid.
 */
class GridOptionsValidator
{
    private const RESERVED_LAYOUT_KEYS = ['templates', 'slots', 'attrs'];

    /** Regions whose tokens the table renderer reads itself (see table.html.twig). */
    private const TABLE_REGIONS = ['dataview', 'tfoot'];

    public function __construct(
        private readonly ?ThemeRegistry $themes = null,
        private readonly ?PaginatorStrategyRegistry $paginators = null,
        private readonly ?TemplateCatalog $templates = null,
    ) {
    }

    /**
     * @param array<mixed> $options the grid options: the `display` / `behavior` / `integration` groups
     *
     * @return list<OptionViolation>
     */
    public function validate(array $options): array
    {
        $violations = [];
        $this->checkNode($options, GridOptionsSchema::get(), '', $violations);
        $this->checkNames($options, $violations);

        return $violations;
    }

    /**
     * @param array<mixed> $options
     *
     * @throws InvalidGridOptionsException when there is at least one violation
     */
    public function assertValid(array $options, ?string $gridId = null): void
    {
        $violations = $this->validate($options);
        if ($violations !== []) {
            throw new InvalidGridOptionsException($violations, $gridId);
        }
    }

    /**
     * @param list<OptionViolation> $violations
     */
    private function checkNode(mixed $value, SchemaNode $node, string $path, array &$violations): void
    {
        if ($node->type === SchemaNode::ANY) {
            return;
        }

        if ($value === null) {
            if (!$node->isNullable()) {
                $violations[] = new OptionViolation($path, sprintf('Expected %s, got null.', $node->label()));
            }

            return;
        }

        switch ($node->type) {
            case SchemaNode::BOOL:
            case SchemaNode::INT:
            case SchemaNode::STRING:
                $this->checkScalar($value, $node, $path, $violations);

                return;

            case SchemaNode::LIST:
                if (!\is_array($value) || !array_is_list($value)) {
                    $violations[] = $this->typeViolation($value, $node, $path);

                    return;
                }
                foreach ($value as $i => $item) {
                    $this->checkNode($item, $node->items ?? SchemaNode::any(), $path . '[' . $i . ']', $violations);
                }

                return;

            case SchemaNode::STRUCT:
                $this->checkStruct($value, $node, $path, $violations);

                return;

            case SchemaNode::ANY_OF:
                $this->checkAnyOf($value, $node, $path, $violations);

                return;
        }
    }

    /**
     * @param list<OptionViolation> $violations
     */
    private function checkScalar(mixed $value, SchemaNode $node, string $path, array &$violations): void
    {
        $ok = match ($node->type) {
            SchemaNode::BOOL => \is_bool($value),
            SchemaNode::INT  => \is_int($value),
            default          => \is_string($value),
        };
        if (!$ok) {
            $violations[] = $this->typeViolation($value, $node, $path);

            return;
        }

        if ($node->enum !== null && !\in_array($value, $node->enum, true)) {
            $violations[] = new OptionViolation($path, $this->unknownName(
                sprintf('Invalid value "%s".', (string) $value),
                (string) $value,
                array_map('strval', $node->enum),
                'Accepted values',
            ));
        }
    }

    /**
     * @param list<OptionViolation> $violations
     */
    private function checkStruct(mixed $value, SchemaNode $node, string $path, array &$violations): void
    {
        if (!\is_array($value)) {
            $violations[] = $this->typeViolation($value, $node, $path);

            return;
        }

        foreach ($value as $key => $child) {
            $childPath = $path === '' ? (string) $key : $path . '.' . $key;

            if (isset($node->children[$key])) {
                $this->checkNode($child, $node->children[$key], $childPath, $violations);
                continue;
            }
            if ($node->additional !== null) {
                $this->checkNode($child, $node->additional, $childPath, $violations);
                continue;
            }

            $violations[] = new OptionViolation($childPath, $this->unknownName(
                sprintf('Unknown key "%s"; it would be ignored.', $key),
                (string) $key,
                array_keys($node->children),
                'Known keys',
            ));
        }
    }

    /**
     * A union passes when any variant does. When none does, the report comes
     * from the variant of the same kind as the value (an array checked against
     * the struct variant, say), so it points at the wrong key inside instead of
     * a vague "expected string or array".
     *
     * @param list<OptionViolation> $violations
     */
    private function checkAnyOf(mixed $value, SchemaNode $node, string $path, array &$violations): void
    {
        $sameKind = [];
        foreach ($node->variants as $variant) {
            $found = [];
            $this->checkNode($value, $variant, $path, $found);
            if ($found === []) {
                return;
            }
            if ($this->isSameKind($value, $variant)) {
                $sameKind[] = $found;
            }
        }

        if (\count($sameKind) === 1) {
            array_push($violations, ...$sameKind[0]);

            return;
        }

        $violations[] = $this->typeViolation($value, $node, $path);
    }

    private function isSameKind(mixed $value, SchemaNode $node): bool
    {
        return match ($node->type) {
            SchemaNode::BOOL   => \is_bool($value),
            SchemaNode::INT    => \is_int($value),
            SchemaNode::STRING => \is_string($value),
            SchemaNode::LIST   => \is_array($value) && array_is_list($value),
            SchemaNode::STRUCT => \is_array($value),
            default            => false,
        };
    }

    /**
     * @param array<mixed>          $options
     * @param list<OptionViolation> $violations
     */
    private function checkNames(array $options, array &$violations): void
    {
        $display = \is_array($options['display'] ?? null) ? $options['display'] : [];

        $theme = $display['theme'] ?? null;
        if ($this->themes !== null && \is_string($theme) && !\in_array($theme, $this->themes->knownThemes(), true)) {
            $violations[] = new OptionViolation('display.theme', $this->unknownName(
                sprintf('Unknown theme "%s".', $theme),
                $theme,
                $this->themes->knownThemes(),
                'Known themes',
            ));
        }

        $this->checkRenderers($display['renderer'] ?? null, $violations);

        $mode = $options['behavior']['pagination']['mode'] ?? null;
        if ($this->paginators !== null && \is_string($mode) && !$this->paginators->has($mode)) {
            $violations[] = new OptionViolation('behavior.pagination.mode', $this->unknownName(
                sprintf('Unknown paginator mode "%s".', $mode),
                $mode,
                $this->paginators->names(),
                'Registered modes',
            ));
        }

        if (\is_array($display['layout'] ?? null)) {
            $this->checkLayout($display['layout'], $violations);
        }
    }

    /**
     * @param list<OptionViolation> $violations
     */
    private function checkRenderers(mixed $renderer, array &$violations): void
    {
        if ($this->templates === null) {
            return;
        }

        // The scalar shorthand stands for renderer.default.
        $names = [];
        if (\is_string($renderer)) {
            $names['display.renderer'] = $renderer;
        } elseif (\is_array($renderer)) {
            if (\is_string($renderer['default'] ?? null)) {
                $names['display.renderer.default'] = $renderer['default'];
            }
            if (\is_array($renderer['map'] ?? null)) {
                foreach (array_keys($renderer['map']) as $name) {
                    $names['display.renderer.map.' . $name] = (string) $name;
                }
            }
        }

        foreach ($names as $path => $name) {
            if (!$this->templates->rendererExists($name)) {
                $violations[] = new OptionViolation($path, $this->unknownName(
                    sprintf('Unknown renderer "%s"; the grid would fall back to the table.', $name),
                    $name,
                    $this->templates->builtinRenderers(),
                    'Built-in renderers',
                ));
            }
        }
    }

    /**
     * Every `{token}` of every region must resolve to something, in the same
     * order the engine tries (OptionsExtension::includeToken()): a slot, a
     * region, a templates override, a block template. The regions are the
     * built-in ones plus the keys this layout declares, since the grid always
     * starts from GridviewConfigRegistry::LAYOUT_DEFAULTS.
     *
     * @param array<mixed>          $layout
     * @param list<OptionViolation> $violations
     */
    private function checkLayout(array $layout, array &$violations): void
    {
        if ($this->templates === null) {
            return;
        }

        $known = array_merge(
            array_keys(GridviewConfigRegistry::LAYOUT_DEFAULTS),
            array_keys($layout),
            \is_array($layout['slots'] ?? null) ? array_keys($layout['slots']) : [],
            \is_array($layout['templates'] ?? null) ? array_keys($layout['templates']) : [],
        );
        $known = array_values(array_diff(array_map('strval', $known), self::RESERVED_LAYOUT_KEYS));

        foreach ($layout as $region => $tokens) {
            if (\in_array($region, self::RESERVED_LAYOUT_KEYS, true) || !\is_string($tokens)) {
                continue;
            }

            $allowed = \in_array($region, self::TABLE_REGIONS, true)
                ? array_merge($known, $this->templates->tableTokens())
                : $known;

            preg_match_all('/\{(\w+)(?:\s+[^}]+)?\}/', $tokens, $matches);
            foreach (array_unique($matches[1]) as $token) {
                if (\in_array($token, $allowed, true) || $this->templates->blockExists($token)) {
                    continue;
                }

                $violations[] = new OptionViolation('display.layout.' . $region, $this->unknownName(
                    sprintf('Unknown layout token "{%s}"; it would render nothing.', $token),
                    $token,
                    array_merge($allowed, $this->templates->builtinBlocks()),
                    'Add it under layout.templates or layout.slots to declare a custom token. Built-in tokens',
                ));
            }
        }
    }

    private function typeViolation(mixed $value, SchemaNode $node, string $path): OptionViolation
    {
        $given = \is_array($value) && array_is_list($value) && $value !== [] ? 'list' : get_debug_type($value);

        return new OptionViolation($path, sprintf('Expected %s, got %s.', $node->label(), $given));
    }

    /**
     * @param list<string> $candidates
     */
    private function unknownName(string $message, string $given, array $candidates, string $listLabel): string
    {
        $candidates = array_values(array_unique($candidates));
        $suggestion = $this->closest($given, $candidates);
        if ($suggestion !== null) {
            $message .= sprintf(' Did you mean "%s"?', $suggestion);
        }
        if ($candidates !== []) {
            sort($candidates);
            $message .= sprintf(' %s: %s.', $listLabel, implode(', ', $candidates));
        }

        return $message;
    }

    /**
     * @param list<string> $candidates
     */
    private function closest(string $given, array $candidates): ?string
    {
        $best = null;
        $bestDistance = PHP_INT_MAX;
        foreach ($candidates as $candidate) {
            if (strcasecmp($given, $candidate) === 0) {
                return $candidate;
            }
            $distance = levenshtein(strtolower($given), strtolower($candidate));
            if ($distance < $bestDistance) {
                $best = $candidate;
                $bestDistance = $distance;
            }
        }

        // Only a near miss is worth suggesting: a third of the name, at least 2.
        return $best !== null && $bestDistance <= max(2, intdiv(\strlen($given), 3)) ? $best : null;
    }
}
