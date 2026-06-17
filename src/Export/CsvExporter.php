<?php

namespace Fedale\GridviewBundle\Export;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Built-in CSV exporter (native PHP, no dependency). Renders each export column
 * via its render() and flattens to plain text.
 */
class CsvExporter implements ExporterInterface
{
    public function getKey(): string
    {
        return 'csv';
    }

    public function getLabel(): string
    {
        return 'CSV';
    }

    public function export(iterable $rows, iterable $columns, array $context = []): Response
    {
        $columns = \is_array($columns) ? $columns : iterator_to_array($columns);

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, array_map(static fn ($c) => (string) ($c->getLabel() ?? $c->getAttribute()), $columns));

        $index = 0;
        foreach ($rows as $row) {
            $line = [];
            foreach ($columns as $column) {
                $line[] = $this->flatten($column->render($row, $index));
            }
            fputcsv($handle, $line);
            ++$index;
        }

        rewind($handle);
        // BOM so Excel opens UTF-8 correctly.
        $content = "\xEF\xBB\xBF" . stream_get_contents($handle);
        fclose($handle);

        $filename = ($context['filename'] ?? 'export') . '.csv';
        $response = new Response($content);
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set(
            'Content-Disposition',
            $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename)
        );

        return $response;
    }

    private function flatten(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (\is_array($value)) {
            return implode(', ', array_map(fn ($v) => $this->flatten($v), $value));
        }
        if (\is_scalar($value)) {
            return trim(strip_tags((string) $value));
        }

        return '';
    }
}
