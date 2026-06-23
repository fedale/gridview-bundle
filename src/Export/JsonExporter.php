<?php

namespace Fedale\GridviewBundle\Export;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Built-in JSON exporter (native PHP, no dependency). Emits an array of objects,
 * one per row, keyed by each export column's attribute (falling back to label).
 */
class JsonExporter implements ExporterInterface
{
    public function getKey(): string
    {
        return 'json';
    }

    public function getLabel(): string
    {
        return 'JSON';
    }

    public function export(iterable $rows, iterable $columns, array $context = []): Response
    {
        $columns = \is_array($columns) ? $columns : iterator_to_array($columns);

        $data = [];
        $index = 0;
        foreach ($rows as $row) {
            $record = [];
            foreach ($columns as $column) {
                $key = (string) ($column->getAttribute() ?? $column->getLabel() ?? 'col');
                $record[$key] = $this->flatten($column->render($row, $index));
            }
            $data[] = $record;
            ++$index;
        }

        $content = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $filename = ($context['filename'] ?? 'export') . '.json';
        $response = new Response($content === false ? '[]' : $content);
        $response->headers->set('Content-Type', 'application/json; charset=UTF-8');
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
