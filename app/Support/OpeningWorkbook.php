<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;
use SimpleXMLElement;
use ZipArchive;

class OpeningWorkbook
{
    public const HEADERS = ['product_name', 'category', 'color', 'size', 'opening_quantity', 'unit_cost', 'selling_price', 'product_code', 'zero_cost_confirmed'];

    /** @return list<array<string, string>> */
    public function read(string $path): array
    {
        if (! class_exists(ZipArchive::class) || ! function_exists('simplexml_load_string')) {
            $this->fail('استيراد Excel يحتاج ZIP وSimpleXML لدى مسؤول الخادم.');
        }
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            $this->fail('ارفع ملف XLSX صالحًا من القالب؛ ملفات SQL وCSV والماكرو غير مقبولة.');
        }
        try {
            $bytes = 0;
            if ($zip->numFiles > 100) {
                $this->fail('الملف يحتوي أجزاء أكثر من الحد الآمن.');
            }
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                $bytes += $stat['size'];
                if ($bytes > 24 * 1024 * 1024 || str_contains(strtolower($stat['name']), 'vbaproject')) {
                    $this->fail('ملف كبير بعد فك الضغط أو يحتوي ماكرو؛ استخدم القالب دون ماكرو.');
                }
                if (str_ends_with($stat['name'], '.rels')) {
                    $rels = $this->xml($zip->getFromIndex($index));
                    foreach ($rels->children('http://schemas.openxmlformats.org/package/2006/relationships') as $relationship) {
                        if (strtolower((string) $relationship->attributes()['TargetMode']) === 'external') {
                            $this->fail('لا تُقبل روابط ملفات أو مصادر خارجية في ملف الاستيراد.');
                        }
                    }
                }
            }
            $book = $this->xml($zip->getFromName('xl/workbook.xml'));
            $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
            if (count($book->children($ns)->sheets->sheet) !== 1) {
                $this->fail('استخدم ورقة بيانات واحدة فقط، دون جداول إضافية تُهمل أثناء الاستيراد.');
            }
            $sheetInfo = $book->children($ns)->sheets->sheet;
            if (in_array((string) $sheetInfo->attributes()['state'], ['hidden', 'veryHidden'], true)) {
                $this->fail('ورقة البيانات مخفية؛ أظهرها وراجعها قبل الاستيراد.');
            }
            $relationshipId = (string) $sheetInfo->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            $relationships = $this->xml($zip->getFromName('xl/_rels/workbook.xml.rels'));
            $sheetPath = null;
            foreach ($relationships->children('http://schemas.openxmlformats.org/package/2006/relationships') as $relationship) {
                $attributes = $relationship->attributes();
                if ((string) $attributes['Id'] === $relationshipId && str_ends_with((string) $attributes['Type'], '/worksheet')) {
                    $target = ltrim((string) $attributes['Target'], '/');
                    $sheetPath = str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
                }
            }
            if ($sheetPath === null || ! preg_match('/\Axl\/worksheets\/[A-Za-z0-9_-]+\.xml\z/', $sheetPath)) {
                $this->fail('ارتباط ورقة Excel غير صالح؛ أعد حفظ الملف دون روابط خارجية.');
            }
            $shared = [];
            if (($strings = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
                $xml = $this->xml($strings);
                $xml->registerXPathNamespace('s', $ns);
                foreach ($xml->xpath('//s:si') as $item) {
                    $item->registerXPathNamespace('s', $ns);
                    $shared[] = implode('', array_map('strval', $item->xpath('.//s:t')));
                }
            }
            $sheet = $this->xml($zip->getFromName($sheetPath));
            $sheet->registerXPathNamespace('s', $ns);
            $rows = $sheet->xpath('//s:sheetData/s:row');
            if (count($rows) > 5001) {
                $this->fail('الحد 5000 سطر بيانات في الملف الواحد؛ قسّم الملفات الأكبر.');
            }
            $headers = null;
            $result = [];
            foreach ($rows as $row) {
                $cells = array_fill(0, count(self::HEADERS), '');
                foreach ($row->children($ns)->c as $cell) {
                    $ref = (string) $cell->attributes()['r'];
                    if (! preg_match('/\A([A-Z]{1,3})[1-9]\d{0,6}\z/', $ref, $match)) {
                        $this->fail('مرجع خلية غير صالح؛ أعد حفظ الملف من القالب.');
                    }
                    $column = 0;
                    foreach (str_split($match[1]) as $letter) {
                        $column = $column * 26 + ord($letter) - 64;
                    }
                    $content = $cell->children($ns);
                    if (isset($content->f)) {
                        $this->fail('لا تُقبل المعادلات؛ الصق القيم فقط قبل الرفع.');
                    }
                    $type = (string) $cell->attributes()['t'];
                    if ($type === 's' && ! preg_match('/\A\d+\z/', (string) $content->v)) {
                        $this->fail('مرجع النص في Excel غير صالح.');
                    }
                    $cell->registerXPathNamespace('s', $ns);
                    $value = match ($type) {
                        'inlineStr' => implode('', array_map('strval', $cell->xpath('.//s:t'))),
                        's' => $shared[(int) $content->v] ?? $this->fail('مرجع نص غير صالح.'),
                        '', 'n', 'str', 'b' => (string) $content->v,
                        default => $this->fail('نوع خلية غير مدعوم؛ استخدم نصوصًا وأرقامًا فقط.'),
                    };
                    if ($column > count(self::HEADERS)) {
                        if (trim($value) !== '') {
                            $this->fail('توجد أعمدة غير مدعومة؛ استخدم القالب الموحد.');
                        }

                        continue;
                    }
                    $cells[$column - 1] = trim($value);
                }
                if (array_filter($cells, fn ($cell) => $cell !== '') === []) {
                    continue;
                }
                if ($headers === null) {
                    if (array_slice($cells, 0, 7) !== array_slice(self::HEADERS, 0, 7)
                        || ! in_array($cells[7], ['', 'product_code'], true) || ! in_array($cells[8], ['', 'zero_cost_confirmed'], true)) {
                        $this->fail('أسماء الأعمدة أو ترتيبها لا تطابق القالب. لا يُنفذ SQL؛ انقل بيانات المصدر إلى أعمدة القالب أولًا.');
                    }
                    $headers = self::HEADERS;

                    continue;
                }
                $result[] = array_combine($headers, $cells);
            }
            if ($result === []) {
                $this->fail('لا توجد أصناف في الملف.');
            }

            return $result;
        } finally {
            $zip->close();
        }
    }

    private function xml(string|false $text): SimpleXMLElement
    {
        if ($text === false || preg_match('/<!DOCTYPE|<!ENTITY/i', $text)) {
            $this->fail('جزء Excel مفقود أو يحتوي تعريفات XML غير آمنة.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($text, SimpleXMLElement::class, LIBXML_NONET);
            if ($xml === false) {
                $this->fail('تعذر قراءة بنية Excel.');
            }

            return $xml;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
