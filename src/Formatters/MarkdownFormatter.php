<?php

namespace Dimer47\BackpackServerExport\Formatters;

use Dimer47\BackpackServerExport\Contracts\ColumnFormatterInterface;

class MarkdownFormatter implements ColumnFormatterInterface
{
    public function getIdentifier(): string
    {
        return 'md';
    }

    public function generate(iterable $rows, array $columns, string $filePath): void
    {
        $handle = fopen($filePath, 'w');

        // Header row
        $headers = array_map(fn ($col) => $col->label, $columns);
        fwrite($handle, '| '.implode(' | ', $headers).' |'."\n");

        // Separator row
        $separators = array_map(fn () => '---', $columns);
        fwrite($handle, '| '.implode(' | ', $separators).' |'."\n");

        // Data rows
        foreach ($rows as $row) {
            $values = [];
            foreach ($columns as $col) {
                $value = $row[$col->name] ?? '';
                if (is_array($value)) {
                    $value = implode(', ', $value);
                }
                // Escape pipes in values to avoid breaking the table
                $value = str_replace('|', '\\|', (string) $value);
                // Replace newlines with spaces
                $value = str_replace(["\r\n", "\r", "\n"], ' ', $value);
                $values[] = $value;
            }
            fwrite($handle, '| '.implode(' | ', $values).' |'."\n");
        }

        fclose($handle);
    }

    public function getMimeType(): string
    {
        return 'text/markdown';
    }

    public function getFileExtension(): string
    {
        return 'md';
    }
}
