<?php

namespace Fedale\GridviewBundle\Pagination\Strategy;

/**
 * Default paginator: classic numbered page links (with the "jump to page" select
 * for long lists). Pure server-side rendering, no Stimulus controller required.
 */
class NumericStrategy extends AbstractPaginatorStrategy
{
    public function getName(): string
    {
        return 'numeric';
    }

    public function getTemplate(): string
    {
        return '@FedaleGridview/gridview/sections/paginators/numeric.html.twig';
    }
}
