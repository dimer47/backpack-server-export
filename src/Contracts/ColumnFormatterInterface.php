<?php

namespace Dimer47\BackpackServerExport\Contracts;

use Dimer47\BackpackServerExport\Dto\ExportColumn;

interface ColumnFormatterInterface
{
    /**
     * Unique identifier for this formatter (e.g. 'csv', 'xlsx').
     */
    public function getIdentifier(): string;

    /**
     * Generate the export file from rows data.
     *
     * @param  iterable  $rows  Array of associative arrays (column_name => value)
     * @param  ExportColumn[]  $columns  Column definitions
     * @param  string  $filePath  Full path where the file should be written
     */
    public function generate(iterable $rows, array $columns, string $filePath): void;

    /**
     * Return the MIME type of the generated file.
     */
    public function getMimeType(): string;

    /**
     * Return the file extension (without dot).
     */
    public function getFileExtension(): string;
}
