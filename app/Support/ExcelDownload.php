<?php

namespace App\Support;

use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use XMLWriter;
use ZipArchive;

class ExcelDownload
{
    /** @param array<string, iterable<array<int, mixed>>> $sheets First row contains headings. */
    public function download(string $filename, array $sheets): BinaryFileResponse
    {
        abort_unless(class_exists(ZipArchive::class) && class_exists(XMLWriter::class), 503, 'تصدير Excel يحتاج تفعيل ZIP وXMLWriter لدى مسؤول الخادم.');
        $path = $this->temporary();
        $archive = new ZipArchive;
        $temporary = [];
        $success = false;
        $opened = false;
        try {
            if ($archive->open($path, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Cannot open workbook.');
            }
            $opened = true;
            $types = '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
            $book = '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><bookViews><workbookView/></bookViews><sheets>';
            $rels = '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
            $index = 0;
            foreach ($sheets as $name => $rows) {
                $index++;
                $temporary[] = $sheet = $this->temporary();
                $this->worksheet($sheet, $rows);
                if (! $archive->addFile($sheet, 'xl/worksheets/sheet'.$index.'.xml')) {
                    throw new RuntimeException('Cannot store worksheet.');
                }
                $types .= '<Override PartName="/xl/worksheets/sheet'.$index.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
                $book .= '<sheet name="'.htmlspecialchars($name, ENT_XML1 | ENT_QUOTES, 'UTF-8').'" sheetId="'.$index.'" r:id="rId'.$index.'"/>';
                $rels .= '<Relationship Id="rId'.$index.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$index.'.xml"/>';
            }
            foreach (['[Content_Types].xml' => $types.'</Types>', 'xl/workbook.xml' => $book.'</sheets></workbook>',
                'xl/_rels/workbook.xml.rels' => $rels.'</Relationships>',
                '_rels/.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>'] as $name => $xml) {
                if (! $archive->addFromString($name, '<?xml version="1.0" encoding="UTF-8"?>'.$xml)) {
                    throw new RuntimeException('Cannot store workbook metadata.');
                }
            }
            $success = $archive->close();
            $opened = false;
            if (! $success) {
                throw new RuntimeException('Cannot finish workbook.');
            }

            return response()->download($path, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff'])->deleteFileAfterSend(true);
        } finally {
            if (! $success) {
                if ($opened) {
                    $archive->close();
                }
                unlink($path);
            }
            foreach ($temporary as $sheet) {
                unlink($sheet);
            }
        }
    }

    private function temporary(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'appstore-xlsx-');
        if ($path === false) {
            throw new RuntimeException('Cannot allocate workbook.');
        }
        chmod($path, 0600);

        return $path;
    }

    /** Strings are always inline text, never formulas or numeric codes. Null remains a blank cell. */
    private function worksheet(string $path, iterable $rows): void
    {
        $xml = new XMLWriter;
        $xml->openUri($path);
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElementNS(null, 'worksheet', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $xml->startElement('sheetViews');
        $xml->startElement('sheetView');
        $xml->writeAttribute('workbookViewId', '0');
        $xml->writeAttribute('rightToLeft', '1');
        $xml->endElement();
        $xml->endElement();
        $xml->startElement('sheetData');
        $rowNumber = 0;
        foreach ($rows as $row) {
            if (++$rowNumber > 1048576) {
                throw new RuntimeException('Worksheet exceeds the Excel row limit; narrow report filters.');
            }
            $xml->startElement('row');
            $xml->writeAttribute('r', (string) $rowNumber);
            foreach (array_values($row) as $offset => $value) {
                $column = '';
                for ($number = $offset + 1; $number > 0; $number = intdiv($number - 1, 26)) {
                    $column = chr(65 + ($number - 1) % 26).$column;
                }
                $xml->startElement('c');
                $xml->writeAttribute('r', $column.$rowNumber);
                if (is_int($value) || is_array($value)) {
                    $number = is_array($value) ? $value['number'] : (string) $value;
                    if (! is_string($number) || ! preg_match('/\A-?\d+(?:\.\d+)?\z/', $number)) {
                        throw new RuntimeException('Invalid numeric report cell.');
                    }
                    $xml->writeElement('v', $number);
                } elseif ($value !== null) {
                    $xml->writeAttribute('t', 'inlineStr');
                    $xml->startElement('is');
                    $xml->startElement('t');
                    $xml->writeAttribute('xml:space', 'preserve');
                    $xml->text(preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', mb_substr((string) $value, 0, 32767)) ?? '');
                    $xml->endElement();
                    $xml->endElement();
                }
                $xml->endElement();
            }
            $xml->endElement();
            $xml->flush();
        }
        $xml->endElement();
        $xml->endElement();
        $xml->endDocument();
        $xml->flush();
    }
}
