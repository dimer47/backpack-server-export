<?php

namespace Dimer47\BackpackServerExport\Formatters;

use Dimer47\BackpackServerExport\Contracts\ColumnFormatterInterface;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class XlsxFormatter implements ColumnFormatterInterface
{
    public function getIdentifier(): string
    {
        return 'xlsx';
    }

    public function generate(iterable $rows, array $columns, string $filePath): void
    {
        if (! class_exists(Spreadsheet::class)) {
            throw new \RuntimeException('XLSX export requires phpoffice/phpspreadsheet. Install it with: composer require phpoffice/phpspreadsheet');
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Export');

        // Header row
        $colIndex = 1;
        foreach ($columns as $col) {
            $cell = $sheet->getCellByColumnAndRow($colIndex, 1);
            $cell->setValue($col->label);
            $colIndex++;
        }

        // Style header
        $lastCol = $sheet->getHighestColumn();
        $headerRange = "A1:{$lastCol}1";
        $sheet->getStyle($headerRange)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '4472C4'],
            ],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'borders' => [
                'bottom' => ['borderStyle' => Border::BORDER_THIN],
            ],
        ]);

        // Data rows
        $rowIndex = 2;
        foreach ($rows as $row) {
            $colIndex = 1;
            foreach ($columns as $col) {
                $value = $row[$col->name] ?? '';
                if (is_array($value)) {
                    $value = implode(', ', $value);
                }
                $sheet->getCellByColumnAndRow($colIndex, $rowIndex)->setValue($value);
                $colIndex++;
            }
            $rowIndex++;
        }

        // Auto-size columns
        foreach (range('A', $lastCol) as $columnID) {
            $sheet->getColumnDimension($columnID)->setAutoSize(true);
        }

        // Write file
        $writer = new Xlsx($spreadsheet);
        $writer->save($filePath);

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
    }

    public function getMimeType(): string
    {
        return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    }

    public function getFileExtension(): string
    {
        return 'xlsx';
    }
}
