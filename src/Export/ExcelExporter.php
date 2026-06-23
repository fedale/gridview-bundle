<?php

namespace Fedale\GridviewBundle\Export;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Built-in Excel exporter (native PHP, no dependency). Builds a real .xlsx
 * (Office Open XML) on the fly with {@see \ZipArchive}, using inline strings so
 * no sharedStrings table is needed. The header row is bold; cells that look
 * numeric are written as numbers, everything else as text.
 */
class ExcelExporter implements ExporterInterface
{
    public function getKey(): string
    {
        return 'xlsx';
    }

    public function getLabel(): string
    {
        return 'Excel';
    }

    public function export(iterable $rows, iterable $columns, array $context = []): Response
    {
        if (!class_exists(\ZipArchive::class)) {
            // No zip extension: fall back to CSV so the export still works.
            return (new CsvExporter())->export($rows, $columns, $context);
        }

        $columns = \is_array($columns) ? $columns : iterator_to_array($columns);

        $sheet = $this->openSheet();

        $header = array_map(static fn ($c) => (string) ($c->getLabel() ?? $c->getAttribute()), $columns);
        $sheet .= $this->row(1, $header, true);

        $rowNum = 1;
        foreach ($rows as $row) {
            ++$rowNum;
            $line = [];
            foreach ($columns as $column) {
                $line[] = $this->flatten($column->render($row, $rowNum - 2));
            }
            $sheet .= $this->row($rowNum, $line, false);
        }

        $sheet .= '</sheetData></worksheet>';

        $content = $this->zip($sheet);

        $filename = ($context['filename'] ?? 'export') . '.xlsx';
        $response = new Response($content);
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set(
            'Content-Disposition',
            $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename)
        );

        return $response;
    }

    private function openSheet(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetData>';
    }

    /** @param string[] $values */
    private function row(int $rowNum, array $values, bool $bold): string
    {
        $xml = '<row r="' . $rowNum . '">';
        $col = 0;
        foreach ($values as $value) {
            $ref = $this->colLetter($col) . $rowNum;
            $style = $bold ? ' s="1"' : '';
            if (!$bold && $value !== '' && is_numeric($value)) {
                $xml .= '<c r="' . $ref . '"' . $style . '><v>' . $value . '</v></c>';
            } else {
                $xml .= '<c r="' . $ref . '"' . $style . ' t="inlineStr"><is><t xml:space="preserve">'
                    . $this->esc($value) . '</t></is></c>';
            }
            ++$col;
        }

        return $xml . '</row>';
    }

    private function zip(string $sheet): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'gvxlsx');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);

        $zip->addFromString('[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>');

        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');

        $zip->addFromString('xl/workbook.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Export" sheetId="1" r:id="rId1"/></sheets></workbook>');

        $zip->addFromString('xl/_rels/workbook.xml.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>');

        // One cell style (s="1") = bold, for the header row.
        $zip->addFromString('xl/styles.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
            . '<borders count="1"><border/></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
            . '</styleSheet>');

        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();

        $content = file_get_contents($tmp);
        @unlink($tmp);

        return $content === false ? '' : $content;
    }

    private function colLetter(int $index): string
    {
        $letter = '';
        for ($n = $index; $n >= 0; $n = intdiv($n, 26) - 1) {
            $letter = chr(65 + ($n % 26)) . $letter;
        }

        return $letter;
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
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
