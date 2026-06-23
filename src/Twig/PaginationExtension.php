<?php

namespace Fedale\GridviewBundle\Twig;

use Fedale\GridviewBundle\Pagination\Strategy\PaginatorStrategyRegistry;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig glue for paginator strategies: resolves the active `mode` to the strategy's
 * template (for the `{pagination}` dispatcher) and its Stimulus controllers (merged
 * into the grid container in `_grid.html.twig`).
 */
class PaginationExtension extends AbstractExtension
{
    public function __construct(private PaginatorStrategyRegistry $registry)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('gridview_paginator_template', [$this, 'template']),
            new TwigFunction('gridview_paginator_controllers', [$this, 'controllers']),
        ];
    }

    public function template(string $mode): string
    {
        return $this->registry->get($mode)->getTemplate();
    }

    /**
     * @return list<string>
     */
    public function controllers(string $mode): array
    {
        return $this->registry->get($mode)->getControllers();
    }
}
