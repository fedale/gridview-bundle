<?php

namespace Fedale\GridviewBundle\Twig;

use Fedale\GridviewBundle\Grid\Gridview;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

class OptionsExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('options', [$this, 'renderOptions'], ['is_safe' => ['html']])
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('gridview_include', [$this, 'includeToken'], [
                'needs_environment' => true,
                'needs_context'     => true,
                'is_safe'           => ['html'],
            ]),
            new TwigFunction('gridview_children', [$this, 'renderChildren'], [
                'needs_environment' => true,
                'needs_context'     => true,
                'is_safe'           => ['html'],
            ]),
        ];
    }

    /**
     * Context key holding the set of regions currently being rendered up the
     * stack, used to break self/cyclic references (e.g. a `header` layout that
     * includes `{header}`). The recursion was previously impossible because
     * templates never self-included; the generic engine makes it expressible.
     */
    private const VISITED_KEY = '_gvVisited';

    /** Hard cap on region nesting depth, a backstop against runaway recursion. */
    private const MAX_DEPTH = 50;

    private const REGION_WRAPPER = '@FedaleGridview/gridview/sections/_region.html.twig';

    /**
     * The single dispatch for a layout token, in precedence order:
     *   1. slot   → inline content rendered as a template;
     *   2. region → a container template (dedicated or the generic wrapper),
     *               which pulls its children via {@see renderChildren()};
     *   3. block  → a leaf widget template (override or sections/{token});
     *   4. otherwise → '' (unknown token, as before).
     */
    public function includeToken(Environment $env, array $context, Gridview $gridview, string $token): string
    {
        if ($gridview->isSlot($token)) {
            return $env->createTemplate($gridview->slotContent($token))->render($context);
        }

        if ($gridview->isRegion($token)) {
            $visited = $context[self::VISITED_KEY] ?? [];
            if (isset($visited[$token]) || count($visited) >= self::MAX_DEPTH) {
                return '';
            }

            $childContext = array_merge($context, [
                'region'            => $token,
                self::VISITED_KEY   => $visited + [$token => true],
            ]);

            return $env->load($this->regionTemplate($env, $gridview, $token))->render($childContext);
        }

        try {
            return $env->load($gridview->layoutTemplate($token))->render($context);
        } catch (LoaderError $e) {
            return '';
        }
    }

    /**
     * Renders the children of a region: each child token via {@see includeToken()},
     * wrapped in a width slot `<div>` only when the layout gave an inline width
     * (e.g. "{globalSearch 40%}"). Empty children are skipped so a region with no
     * visible content collapses to '' (no stray wrapper). Shared by the generic
     * wrapper and any dedicated region template.
     */
    public function renderChildren(Environment $env, array $context, Gridview $gridview, string $region): string
    {
        $out = '';
        foreach ($gridview->layoutTokens($region) as $item) {
            $rendered = $this->includeToken($env, $context, $gridview, $item['token']);
            if ($rendered === '') {
                continue;
            }

            if ($item['width'] !== null) {
                $out .= sprintf(
                    '<div class="gv-region__slot" style="flex: 0 0 %1$s; max-width: %1$s;">%2$s</div>',
                    $item['width'],
                    $rendered
                );
            } else {
                $out .= $rendered;
            }
        }

        return $out;
    }

    /**
     * Picks a region's template: an explicit `templates[T]` override, else a
     * dedicated `sections/{T}.html.twig`, else the shared generic wrapper.
     */
    private function regionTemplate(Environment $env, Gridview $gridview, string $token): string
    {
        $override = $gridview->getOptions()['layout']['templates'][$token] ?? null;
        if ($override !== null) {
            return $override;
        }

        // The data region is renderer-agnostic: its template is the active
        // strategy (sections/dataview/{renderer}.html.twig). Only 'table' ships
        // today; an unknown/not-yet-built renderer falls back to it.
        if ($token === 'dataview') {
            $renderer = $gridview->getRenderer();
            $strategy = "@FedaleGridview/gridview/sections/dataview/{$renderer}.html.twig";

            return $env->getLoader()->exists($strategy)
                ? $strategy
                : '@FedaleGridview/gridview/sections/dataview/table.html.twig';
        }

        $dedicated = "@FedaleGridview/gridview/sections/{$token}.html.twig";

        return $env->getLoader()->exists($dedicated) ? $dedicated : self::REGION_WRAPPER;
    }

    /**
     */
    public function renderOptions(array $options = []): string
    {
        $str = '';
        if ( count($options) == 0) {
            return $str;
        }
        
        foreach ($options as $key => $value) {
            $str .= $key . '="' . $value . '" ';
        }

        return $str;
    }

}