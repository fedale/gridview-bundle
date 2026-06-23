<?php

namespace Fedale\GridviewBundle\Export;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Built-in PDF exporter (native PHP, no dependency). Renders the export columns
 * into a paginated table using a hand-written, minimal PDF document with the
 * standard Helvetica core fonts (WinAnsi). Good enough for tabular dumps; for
 * pixel-perfect reports plug in a host-app exporter (dompdf, wkhtmltopdf, …).
 */
class PdfExporter implements ExporterInterface
{
    /** A4 landscape, in PDF points. */
    private const PAGE_W = 842.0;
    private const PAGE_H = 595.0;
    private const MARGIN = 40.0;
    private const ROW_H = 18.0;
    private const FONT_SIZE = 9.0;

    public function getKey(): string
    {
        return 'pdf';
    }

    public function getLabel(): string
    {
        return 'PDF';
    }

    public function export(iterable $rows, iterable $columns, array $context = []): Response
    {
        $columns = \is_array($columns) ? $columns : iterator_to_array($columns);
        $colCount = max(1, \count($columns));

        $header = array_map(static fn ($c) => (string) ($c->getLabel() ?? $c->getAttribute()), $columns);

        $usableW = self::PAGE_W - 2 * self::MARGIN;
        $colW = $usableW / $colCount;
        $maxChars = max(1, (int) floor(($colW - 6) / (self::FONT_SIZE * 0.5)));

        $rowsPerPage = max(1, (int) floor((self::PAGE_H - 2 * self::MARGIN - self::ROW_H) / self::ROW_H));

        // Pre-render rows to flat text matrix.
        $matrix = [];
        $index = 0;
        foreach ($rows as $row) {
            $line = [];
            foreach ($columns as $column) {
                $line[] = $this->flatten($column->render($row, $index));
            }
            $matrix[] = $line;
            ++$index;
        }

        $pages = array_chunk($matrix, $rowsPerPage) ?: [[]];

        $streams = [];
        foreach ($pages as $pageRows) {
            $streams[] = $this->pageStream($header, $pageRows, $colW, $maxChars);
        }

        $content = $this->document($streams);

        $filename = ($context['filename'] ?? 'export') . '.pdf';
        $response = new Response($content);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set(
            'Content-Disposition',
            $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename)
        );

        return $response;
    }

    /**
     * @param string[]   $header
     * @param string[][] $rows
     */
    private function pageStream(array $header, array $rows, float $colW, int $maxChars): string
    {
        $left = self::MARGIN;
        $top = self::PAGE_H - self::MARGIN;
        $ops = [];

        // Header row: bold, with a rule underneath.
        $y = $top - self::FONT_SIZE - 4;
        $this->textRow($ops, $header, $left, $y, $colW, $maxChars, true);
        $ruleY = $top - self::ROW_H;
        $ops[] = sprintf('0.5 w %.2F %.2F m %.2F %.2F l S', $left, $ruleY, $left + $colW * \count($header), $ruleY);

        // Data rows.
        $rowY = $top - self::ROW_H;
        foreach ($rows as $line) {
            $rowY -= self::ROW_H;
            $this->textRow($ops, $line, $left, $rowY + 5, $colW, $maxChars, false);
        }

        return implode("\n", $ops);
    }

    /**
     * @param string[] $ops
     * @param string[] $cells
     */
    private function textRow(array &$ops, array $cells, float $left, float $y, float $colW, int $maxChars, bool $bold): void
    {
        $font = $bold ? '/F2' : '/F1';
        $x = $left + 3;
        $col = 0;
        foreach ($cells as $cell) {
            $text = $this->fit($cell, $maxChars);
            $ops[] = sprintf(
                "BT %s %.1F Tf %.2F %.2F Td (%s) Tj ET",
                $font,
                self::FONT_SIZE,
                $x + $col * $colW,
                $y,
                $this->escape($text)
            );
            ++$col;
        }
    }

    /** @param string[] $streams Page content streams. */
    private function document(array $streams): string
    {
        $objects = [];

        // 1: catalog, 2: pages, 3/4: fonts. Pages then come in (content, page) pairs.
        $pageCount = \count($streams);
        $kids = [];
        for ($i = 0; $i < $pageCount; ++$i) {
            $kids[] = (6 + 2 * $i) . ' 0 R';
        }

        $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        // %.2F (capital) is locale-independent: PDF coordinates always use a dot.
        $objects[2] = sprintf(
            "<< /Type /Pages /Kids [%s] /Count %d /MediaBox [0 0 %.2F %.2F] "
            . "/Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> >>",
            implode(' ', $kids),
            $pageCount,
            self::PAGE_W,
            self::PAGE_H
        );
        $objects[3] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
        $objects[4] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";

        foreach ($streams as $i => $stream) {
            $contentId = 5 + 2 * $i;
            $pageId = 6 + 2 * $i;
            $objects[$contentId] = sprintf("<< /Length %d >>\nstream\n%s\nendstream", \strlen($stream), $stream);
            $objects[$pageId] = sprintf("<< /Type /Page /Parent 2 0 R /Contents %d 0 R >>", $contentId);
        }

        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = \strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $body . "\nendobj\n";
        }

        $count = \count($objects);
        $xrefPos = \strlen($pdf);
        $pdf .= "xref\n0 " . ($count + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($id = 1; $id <= $count; ++$id) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }
        $pdf .= sprintf(
            "trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF",
            $count + 1,
            $xrefPos
        );

        return $pdf;
    }

    private function fit(string $text, int $maxChars): string
    {
        $text = preg_replace('/\s+/', ' ', trim($text)) ?? '';
        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }

        return mb_substr($text, 0, max(1, $maxChars - 1)) . '...';
    }

    /** Escape for a PDF literal string and down-convert to WinAnsi (CP-1252). */
    private function escape(string $text): string
    {
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $text);
            if ($converted !== false) {
                $text = $converted;
            }
        }

        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], $text);
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
