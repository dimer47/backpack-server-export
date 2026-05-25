<?php

namespace Dimer47\BackpackServerExport\Services;

use Backpack\CRUD\app\Library\CrudPanel\CrudPanel;
use Dimer47\BackpackServerExport\Contracts\ColumnFormatterInterface;
use Dimer47\BackpackServerExport\Contracts\ExportProgressTrackerInterface;
use Dimer47\BackpackServerExport\Dto\ExportColumn;
use Dimer47\BackpackServerExport\Formatters\CsvFormatter;
use Dimer47\BackpackServerExport\Formatters\MarkdownFormatter;
use Dimer47\BackpackServerExport\Formatters\XlsxFormatter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ServerExportService
{
    private array $formatters = [];

    public function __construct()
    {
        $this->registerFormatter(new CsvFormatter());
        $this->registerFormatter(new MarkdownFormatter());

        if (class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
            $this->registerFormatter(new XlsxFormatter());
        }
    }

    public function registerFormatter(ColumnFormatterInterface $formatter): void
    {
        $this->formatters[$formatter->getIdentifier()] = $formatter;
    }

    public function getFormatter(string $identifier): ?ColumnFormatterInterface
    {
        return $this->formatters[$identifier] ?? null;
    }

    /**
     * Resolve columns from CRUD panel definition.
     *
     * @param  array  $visibleColumns  Column names visible in ColVis (empty = all)
     * @return ExportColumn[]
     */
    public function resolveColumnsFromCrud(CrudPanel $crud, array $visibleColumns = []): array
    {
        $crudColumns = $crud->columns();
        $exportColumns = [];

        foreach ($crudColumns as $name => $column) {
            // Respect visibleInExport / forceExport attributes
            if (isset($column['visibleInExport']) && $column['visibleInExport'] === false) {
                continue;
            }

            // Skip bulk actions column
            if (($column['type'] ?? '') === 'checkbox' && ($name === 'bulk_actions' || str_starts_with($name, 'bulk_'))) {
                continue;
            }

            // Skip details_row toggle
            if ($name === 'details_row_toggle') {
                continue;
            }

            // Skip complex visual types that don't make sense in exports
            $type = $column['type'] ?? 'text';
            if (in_array($type, ['ace_editor', 'timesheet_daily_entries_readonly', 'contract_documents_table'])) {
                continue;
            }

            $forceExport = $column['forceExport'] ?? false;
            $visibleInExport = $column['visibleInExport'] ?? null;

            // If ColVis sent visible_columns, respect it (unless forceExport)
            if (! empty($visibleColumns)) {
                if (! in_array($name, $visibleColumns) && ! $forceExport) {
                    continue;
                }
            } else {
                // Fallback: use Backpack defaults
                $visible = $column['visibleInTable'] ?? true;
                if (! $visible && ! $forceExport && $visibleInExport !== true) {
                    continue;
                }
            }

            $label = $column['label'] ?? ucfirst(str_replace('_', ' ', $name));
            $type = $column['type'] ?? 'text';
            $entity = $column['entity'] ?? null;
            $attribute = $column['attribute'] ?? null;

            // Build a value resolver for complex column types
            $resolver = $this->buildResolverForColumn($column);

            $exportColumns[] = new ExportColumn(
                name: $name,
                label: $label,
                type: $type,
                entity: $entity,
                attribute: $attribute,
                valueResolver: $resolver,
            );
        }

        return $exportColumns;
    }

    /**
     * Build a closure resolver for complex column types.
     */
    private function buildResolverForColumn(array $column): ?\Closure
    {
        $type = $column['type'] ?? 'text';
        $name = $column['name'] ?? '';

        // Closure columns: execute the function and strip HTML
        if ($type === 'closure' && isset($column['function']) && is_callable($column['function'])) {
            $fn = $column['function'];

            return function (Model $entry) use ($fn) {
                $result = $fn($entry);

                return $this->stripHtml($result);
            };
        }

        // model_function type
        if ($type === 'model_function' && isset($column['function_name'])) {
            $methodName = $column['function_name'];

            return function (Model $entry) use ($methodName) {
                if (method_exists($entry, $methodName)) {
                    return $this->stripHtml($entry->{$methodName}());
                }

                return '';
            };
        }

        // Enum type (with options mapping)
        if ($type === 'enum') {
            $options = $column['options'] ?? [];

            return function (Model $entry) use ($name, $options) {
                $value = $entry->{$name};

                // Resolve PHP backed enum to its scalar value
                if (is_object($value) && method_exists($value, 'value')) {
                    $value = $value->value;
                }

                $value = (string) ($value ?? '');

                // If options are defined, use the label (strip HTML from it)
                if (! empty($options) && isset($options[$value])) {
                    return $this->stripHtml($options[$value]);
                }

                return $value;
            };
        }

        // select_from_array type (same as enum with options)
        if ($type === 'select_from_array') {
            $options = $column['options'] ?? [];

            return function (Model $entry) use ($name, $options) {
                $value = $entry->{$name};
                $value = (string) ($value ?? '');

                if (! empty($options) && isset($options[$value])) {
                    return $this->stripHtml($options[$value]);
                }

                return $value;
            };
        }

        // custom_html type: has a ->value() that can be a string or Closure
        if ($type === 'custom_html') {
            $valueFn = $column['value'] ?? null;

            if (is_callable($valueFn)) {
                return function (Model $entry) use ($valueFn) {
                    return $this->stripHtml($valueFn($entry));
                };
            }

            if (is_string($valueFn)) {
                return function () use ($valueFn) {
                    return $this->stripHtml($valueFn);
                };
            }

            // No value defined, skip
            return function () {
                return '';
            };
        }

        // Select / relationship columns
        if (in_array($type, ['select', 'select_multiple', 'relationship'])) {
            $entity = $column['entity'] ?? null;
            $attribute = $column['attribute'] ?? 'name';

            if ($entity) {
                return function (Model $entry) use ($entity, $attribute, $type) {
                    $related = $entry->{$entity};
                    if (! $related) {
                        return '';
                    }

                    if ($type === 'select_multiple' || $related instanceof \Illuminate\Support\Collection) {
                        return $related->pluck($attribute)->implode(', ');
                    }

                    return $related->{$attribute} ?? '';
                };
            }
        }

        // Date / datetime columns
        if (in_array($type, ['date', 'datetime'])) {
            $format = $column['format'] ?? ($type === 'date' ? 'd/m/Y' : 'd/m/Y H:i');

            return function (Model $entry) use ($name, $format) {
                $value = $entry->{$name};
                if (! $value) {
                    return '';
                }
                if ($value instanceof \DateTimeInterface) {
                    return $value->format($format);
                }

                try {
                    return \Carbon\Carbon::parse($value)->format($format);
                } catch (\Exception) {
                    return (string) $value;
                }
            };
        }

        // Boolean type
        if ($type === 'boolean' || $type === 'check') {
            return function (Model $entry) use ($name) {
                return $entry->{$name} ? trans('backpack::crud.yes') : trans('backpack::crud.no');
            };
        }

        // Number type with formatting (decimals, separators, prefix/suffix)
        if ($type === 'number') {
            $decimals = $column['decimals'] ?? null;
            $decPoint = $column['dec_point'] ?? ',';
            $thousandsSep = $column['thousands_sep'] ?? '';
            $prefix = $column['prefix'] ?? '';
            $suffix = $column['suffix'] ?? '';

            if ($decimals !== null || $prefix || $suffix) {
                return function (Model $entry) use ($name, $decimals, $decPoint, $thousandsSep, $prefix, $suffix) {
                    $value = $entry->{$name};
                    if ($value === null || $value === '') {
                        return '';
                    }

                    if ($decimals !== null) {
                        $value = number_format((float) $value, $decimals, $decPoint, $thousandsSep);
                    }

                    return $prefix.$value.$suffix;
                };
            }
        }

        // image type: return the URL or path
        if ($type === 'image') {
            return function (Model $entry) use ($name) {
                $value = $entry->{$name};

                return $value ? (string) $value : '';
            };
        }

        // json type: serialize to readable string
        if ($type === 'json') {
            return function (Model $entry) use ($name) {
                $value = $entry->{$name};
                if ($value === null) {
                    return '';
                }
                if (is_array($value)) {
                    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                }

                return (string) $value;
            };
        }

        // Types that don't make sense in an export (complex visual widgets)
        if (in_array($type, ['ace_editor', 'timesheet_daily_entries_readonly', 'contract_documents_table'])) {
            return function () {
                return '';
            };
        }

        // Columns with prefix / suffix (generic fallback for text-like types)
        $prefix = $column['prefix'] ?? '';
        $suffix = $column['suffix'] ?? '';

        if ($prefix || $suffix) {
            return function (Model $entry) use ($name, $prefix, $suffix) {
                $value = $entry->{$name};

                return $value !== null && $value !== '' ? $prefix.$value.$suffix : '';
            };
        }

        // For simple types (text, textarea, email, url, phone, etc.), no special resolver needed
        return null;
    }

    /**
     * Resolve a single row of data from a model entry.
     *
     * @param  ExportColumn[]  $columns
     */
    public function resolveRow(Model $entry, array $columns): array
    {
        $row = [];

        foreach ($columns as $col) {
            if ($col->valueResolver) {
                $row[$col->name] = ($col->valueResolver)($entry);
            } else {
                $value = $entry->{$col->name};
                $row[$col->name] = is_scalar($value) || $value === null ? $value : (string) $value;
            }
        }

        return $row;
    }

    /**
     * Execute a synchronous export: query all data and generate the file.
     *
     * @param  ExportColumn[]  $columns
     * @return array{download_url: string, count: int, filename: string}
     */
    public function exportSync(Builder $query, array $columns, string $format, string $filename, string $downloadRoute): array
    {
        $formatter = $this->getFormatter($format);
        if (! $formatter) {
            throw new \InvalidArgumentException("Unknown export format: {$format}");
        }

        $storagePath = config('backpack-server-export.storage_path', storage_path('app/tmp'));
        if (! is_dir($storagePath)) {
            mkdir($storagePath, 0755, true);
        }

        $fullFilename = $filename.'.'.$formatter->getFileExtension();
        $filePath = $storagePath.'/'.$fullFilename;

        // Eager-load relations detected in columns to avoid N+1
        $relations = $this->detectRelations($columns);
        if (! empty($relations)) {
            $query->with($relations);
        }

        // Collect all rows
        $entries = $query->get();
        $rows = [];
        foreach ($entries as $entry) {
            $rows[] = $this->resolveRow($entry, $columns);
        }

        $formatter->generate($rows, $columns, $filePath);

        return [
            'download_url' => $downloadRoute.'?file='.urlencode($fullFilename),
            'count' => count($rows),
            'filename' => $fullFilename,
        ];
    }

    /**
     * Execute an asynchronous export: iterate by chunks, track progress.
     *
     * @param  ExportColumn[]  $columns
     */
    public function exportAsync(
        Builder $query,
        array $columns,
        string $format,
        string $filename,
        string|int $trackerId,
        ExportProgressTrackerInterface $tracker,
    ): void {
        $formatter = $this->getFormatter($format);
        if (! $formatter) {
            $tracker->fail($trackerId, "Unknown export format: {$format}");

            return;
        }

        $storagePath = config('backpack-server-export.storage_path', storage_path('app/tmp'));
        if (! is_dir($storagePath)) {
            mkdir($storagePath, 0755, true);
        }

        $fullFilename = $filename.'.'.$formatter->getFileExtension();
        $filePath = $storagePath.'/'.$fullFilename;
        $chunkSize = config('backpack-server-export.chunk_size', 500);

        // Eager-load relations detected in columns to avoid N+1
        $relations = $this->detectRelations($columns);
        if (! empty($relations)) {
            $query->with($relations);
        }

        // Count total
        $total = $query->count();
        $tracker->start($trackerId, $total);

        // Collect rows by chunks
        $rows = [];
        $done = 0;

        $query->chunk($chunkSize, function ($entries) use ($columns, &$rows, &$done, $tracker, $trackerId) {
            foreach ($entries as $entry) {
                $rows[] = $this->resolveRow($entry, $columns);
                $done++;
            }
            $tracker->advance($trackerId, $done);
        });

        // Generate file
        $formatter->generate($rows, $columns, $filePath);

        $tracker->complete($trackerId, $fullFilename);
    }

    /**
     * Download a generated export file.
     *
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
     */
    public function download(string $filename): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $filename = basename($filename);
        $storagePath = config('backpack-server-export.storage_path', storage_path('app/tmp'));
        $filePath = $storagePath.'/'.$filename;

        if (! file_exists($filePath)) {
            abort(404);
        }

        // Determine content type from extension
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $contentType = match ($extension) {
            'csv' => 'text/csv',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'md' => 'text/markdown',
            default => 'application/octet-stream',
        };

        return response()->download($filePath, $filename, [
            'Content-Type' => $contentType,
        ])->deleteFileAfterSend(true);
    }

    /**
     * Detect relation names from export columns for eager-loading.
     *
     * @param  ExportColumn[]  $columns
     * @return string[]
     */
    private function detectRelations(array $columns): array
    {
        $relations = [];

        foreach ($columns as $col) {
            if ($col->entity && ! in_array($col->entity, $relations)) {
                $relations[] = $col->entity;
            }
        }

        return $relations;
    }

    private function stripHtml(?string $html): string
    {
        if ($html === null || $html === '') {
            return '';
        }

        // Remove script/style tags and their content
        $html = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $html);
        // Strip remaining HTML tags
        $html = strip_tags($html);
        // Decode HTML entities
        $html = html_entity_decode($html, ENT_QUOTES, 'UTF-8');
        // Normalize whitespace
        $html = preg_replace('/\s+/', ' ', $html);

        return trim($html);
    }
}
