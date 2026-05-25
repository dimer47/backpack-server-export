<?php

namespace Dimer47\BackpackServerExport\Formatters;

use Dimer47\BackpackServerExport\Contracts\ColumnFormatterInterface;

class CsvFormatter implements ColumnFormatterInterface
{
    public function getIdentifier(): string
    {
        return 'csv';
    }

    public function generate(iterable $rows, array $columns, string $filePath): void
    {
        $handle = fopen($filePath, 'w');

        // BOM for Excel UTF-8 compatibility
        fwrite($handle, "\xEF\xBB\xBF");

        // Header row
        $headers = array_map(fn ($col) => $col->label, $columns);
        fputcsv($handle, $headers, ';');

        // Data rows
        foreach ($rows as $row) {
            $values = [];
            foreach ($columns as $col) {
                $value = $row[$col->name] ?? '';
                $values[] = is_array($value) ? implode(', ', $value) : (string) $value;
            }
            fputcsv($handle, $values, ';');
        }

        fclose($handle);
    }

    public function getMimeType(): string
    {
        return 'text/csv';
    }

    public function getFileExtension(): string
    {
        return 'csv';
    }
}
