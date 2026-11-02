<?php

namespace Fedale\GridviewBundle\Grid;

use Twig\Environment;

/**
 * Which layout tokens and data renderers exist, answered the way the render
 * engine answers it: a block token is a `sections/{token}.html.twig` template,
 * a renderer a `sections/dataview/{name}.html.twig` one (see
 * OptionsExtension::regionTemplate() / includeToken()).
 *
 * Two questions, two sources. The built-in lists are read from the bundle's own
 * template directory, so they follow the templates that ship and never need a
 * hand-kept list. The exists() checks go through the Twig loader when there is
 * one, so a host template added under templates/bundles/FedaleGridviewBundle/
 * counts as well, exactly as it does at render time.
 */
class TemplateCatalog
{
    private const SECTIONS = '@FedaleGridview/gridview/sections/';

    /** Tokens only the table renderer understands, inside the `dataview` region. */
    private const TABLE_DIR = 'dataview/table';

    private readonly string $sectionsDir;

    public function __construct(
        private readonly ?Environment $twig = null,
        ?string $sectionsDir = null,
    ) {
        $this->sectionsDir = $sectionsDir ?? \dirname(__DIR__, 2) . '/templates/gridview/sections';
    }

    /**
     * Block tokens shipped by the bundle (`{globalSearch}`, `{export}`, …).
     *
     * @return list<string>
     */
    public function builtinBlocks(): array
    {
        return $this->templateNames($this->sectionsDir);
    }

    /**
     * Renderers shipped by the bundle (`table`, `list`, `card`).
     *
     * @return list<string>
     */
    public function builtinRenderers(): array
    {
        return $this->templateNames($this->sectionsDir . '/dataview');
    }

    /**
     * Tokens the table renderer reads from the `dataview` region (`{thead}`,
     * `{filter}`, `{tbody}`, `{tfoot}`, `{empty}`).
     *
     * @return list<string>
     */
    public function tableTokens(): array
    {
        return $this->templateNames($this->sectionsDir . '/' . self::TABLE_DIR);
    }

    public function blockExists(string $token): bool
    {
        if ($this->twig !== null) {
            return $this->twig->getLoader()->exists(self::SECTIONS . $token . '.html.twig');
        }

        return \in_array($token, $this->builtinBlocks(), true);
    }

    public function rendererExists(string $name): bool
    {
        if ($this->twig !== null) {
            return $this->twig->getLoader()->exists(self::SECTIONS . 'dataview/' . $name . '.html.twig');
        }

        return \in_array($name, $this->builtinRenderers(), true);
    }

    /**
     * Template base names in a directory, minus the `_partials`: those are
     * includes, never reachable as a token.
     *
     * @return list<string>
     */
    private function templateNames(string $dir): array
    {
        $names = [];
        foreach (glob($dir . '/*.html.twig') ?: [] as $file) {
            $name = basename($file, '.html.twig');
            if (!str_starts_with($name, '_')) {
                $names[] = $name;
            }
        }
        sort($names);

        return $names;
    }
}
