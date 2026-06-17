<?php

namespace Fedale\GridviewBundle\Export;

use Fedale\GridviewBundle\Contract\ColumnInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * A grid export format. Implement this (and the service will be auto-registered
 * thanks to registerForAutoconfiguration) to add a custom export to any grid:
 * it appears in the export menu and is selectable via ?format=<key>.
 */
interface ExporterInterface
{
    /** Stable key used in the URL (?format=<key>), e.g. "csv". */
    public function getKey(): string;

    /** Human label shown in the export menu, e.g. "CSV". */
    public function getLabel(): string;

    /**
     * Builds the downloadable response from the (unpaginated, filtered) rows and
     * the export columns. Each column renders a cell via $column->render($row, $i).
     *
     * @param iterable<\Fedale\GridviewBundle\Row\Row> $rows
     * @param iterable<ColumnInterface>                $columns
     */
    public function export(iterable $rows, iterable $columns, array $context = []): Response;
}
