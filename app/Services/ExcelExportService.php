<?php

namespace App\Services;

use DateTimeInterface;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use XMLWriter;
use ZipArchive;

class ExcelExportService
{
    /**
     * @param  array<int, array{key:string,label:string,type?:string,width?:int}>  $columns
     * @param  iterable<int, array<string, mixed>>  $rows
     */
    public function download(int $companyId, string $slug, string $sheetName, array $columns, iterable $rows): BinaryFileResponse
    {
        $directory = storage_path("app/private/companies/{$companyId}/exports");
        File::ensureDirectoryExists($directory);
        $path = $directory.'/'.Str::uuid().'.xlsx';

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Unable to create the Excel export.');
        }

        try {
            $zip->addFromString('[Content_Types].xml', $this->contentTypes());
            $zip->addFromString('_rels/.rels', $this->rootRelationships());
            $zip->addFromString('docProps/app.xml', $this->appProperties());
            $zip->addFromString('docProps/core.xml', $this->coreProperties());
            $zip->addFromString('xl/workbook.xml', $this->workbook($sheetName));
            $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelationships());
            $zip->addFromString('xl/styles.xml', $this->styles());
            $zip->addFromString('xl/worksheets/sheet1.xml', $this->worksheet($columns, $rows));
        } finally {
            $zip->close();
        }

        $filename = Str::slug($slug).'-'.now()->format('Y-m-d-His').'.xlsx';
        return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ])->deleteFileAfterSend(true);
    }

    private function worksheet(array $columns, iterable $rows): string
    {
        $xml = new XMLWriter();
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8', 'yes');
        $xml->startElement('worksheet');
        $xml->writeAttribute('xmlns', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $xml->startElement('sheetViews');
        $xml->startElement('sheetView');
        $xml->writeAttribute('workbookViewId', '0');
        $xml->startElement('pane');
        $xml->writeAttribute('ySplit', '1');
        $xml->writeAttribute('topLeftCell', 'A2');
        $xml->writeAttribute('activePane', 'bottomLeft');
        $xml->writeAttribute('state', 'frozen');
        $xml->endElement();
        $xml->endElement();
        $xml->endElement();

        $xml->startElement('cols');
        foreach ($columns as $index => $column) {
            $xml->startElement('col');
            $xml->writeAttribute('min', (string) ($index + 1));
            $xml->writeAttribute('max', (string) ($index + 1));
            $xml->writeAttribute('width', (string) ($column['width'] ?? 16));
            $xml->writeAttribute('customWidth', '1');
            $xml->endElement();
        }
        $xml->endElement();

        $xml->startElement('sheetData');
        $xml->startElement('row');
        $xml->writeAttribute('r', '1');
        foreach ($columns as $index => $column) {
            $this->writeStringCell($xml, $this->cellReference($index + 1, 1), $column['label'], 1);
        }
        $xml->endElement();

        $rowNumber = 2;
        foreach ($rows as $row) {
            $xml->startElement('row');
            $xml->writeAttribute('r', (string) $rowNumber);
            foreach ($columns as $index => $column) {
                $this->writeCell(
                    $xml,
                    $this->cellReference($index + 1, $rowNumber),
                    $row[$column['key']] ?? null,
                    $column['type'] ?? 'string'
                );
            }
            $xml->endElement();
            $rowNumber++;
        }
        $xml->endElement();

        $lastColumn = $this->columnName(count($columns));
        $xml->startElement('autoFilter');
        $xml->writeAttribute('ref', "A1:{$lastColumn}".max(1, $rowNumber - 1));
        $xml->endElement();
        $xml->endElement();
        $xml->endDocument();
        return $xml->outputMemory();
    }

    private function writeCell(XMLWriter $xml, string $reference, mixed $value, string $type): void
    {
        if ($value === null || $value === '') {
            $this->writeStringCell($xml, $reference, '');
            return;
        }

        if ($type === 'date' || $type === 'datetime') {
            if (! $value instanceof DateTimeInterface) {
                $value = new \DateTimeImmutable((string) $value);
            }
            $serial = ((float) $value->format('U') / 86400) + 25569;
            $this->writeNumberCell($xml, $reference, (string) $serial, $type === 'date' ? 2 : 3);
            return;
        }

        if (in_array($type, ['currency', 'integer', 'decimal', 'percent'], true)) {
            $styles = ['currency' => 4, 'integer' => 5, 'decimal' => 6, 'percent' => 7];
            $this->writeNumberCell($xml, $reference, (string) $value, $styles[$type]);
            return;
        }

        $this->writeStringCell($xml, $reference, (string) $value);
    }

    private function writeStringCell(XMLWriter $xml, string $reference, string $value, int $style = 0): void
    {
        $xml->startElement('c');
        $xml->writeAttribute('r', $reference);
        $xml->writeAttribute('t', 'inlineStr');
        if ($style) $xml->writeAttribute('s', (string) $style);
        $xml->startElement('is');
        $xml->startElement('t');
        if (preg_match('/^\s|\s$/u', $value)) $xml->writeAttribute('xml:space', 'preserve');
        $xml->text($this->validXmlText($value));
        $xml->endElement();
        $xml->endElement();
        $xml->endElement();
    }

    private function writeNumberCell(XMLWriter $xml, string $reference, string $value, int $style): void
    {
        $xml->startElement('c');
        $xml->writeAttribute('r', $reference);
        $xml->writeAttribute('s', (string) $style);
        $xml->writeElement('v', $value);
        $xml->endElement();
    }

    private function validXmlText(string $value): string
    {
        return preg_replace('/[^\x{0009}\x{000A}\x{000D}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $value) ?? '';
    }

    private function cellReference(int $column, int $row): string
    {
        return $this->columnName($column).$row;
    }

    private function columnName(int $column): string
    {
        $name = '';
        while ($column > 0) {
            $column--;
            $name = chr(65 + ($column % 26)).$name;
            $column = intdiv($column, 26);
        }
        return $name;
    }

    private function contentTypes(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/><Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/></Types>
XML;
    }

    private function rootRelationships(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>
XML;
    }

    private function workbook(string $sheetName): string
    {
        $name = htmlspecialchars(mb_substr($sheetName, 0, 31), ENT_XML1 | ENT_QUOTES, 'UTF-8');
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="'.$name.'" sheetId="1" r:id="rId1"/></sheets></workbook>';
    }

    private function workbookRelationships(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>
XML;
    }

    private function styles(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="4"><numFmt numFmtId="164" formatCode="yyyy-mm-dd"/><numFmt numFmtId="165" formatCode="yyyy-mm-dd hh:mm"/><numFmt numFmtId="166" formatCode="#,##0.00 &quot;EGP&quot;"/><numFmt numFmtId="167" formatCode="0.00%"/></numFmts><fonts count="2"><font><sz val="11"/><name val="Aptos"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Aptos"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF102A2E"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="8"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment vertical="center"/></xf><xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="166" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="1" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="2" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="167" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>
XML;
    }

    private function appProperties(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes"><Application>Exousia Realty</Application></Properties>';
    }

    private function coreProperties(): string
    {
        $created = now()->utc()->format('Y-m-d\TH:i:s\Z');
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:creator>Exousia Realty</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">'.$created.'</dcterms:created></cp:coreProperties>';
    }
}
