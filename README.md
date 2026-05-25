# 📦 Backpack Server Export

![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?style=flat-square&logo=php&logoColor=white) ![Laravel](https://img.shields.io/badge/Laravel-10%20%7C%2011%20%7C%2012%2B-FF2D20?style=flat-square&logo=laravel&logoColor=white) ![Backpack](https://img.shields.io/badge/Backpack-6%20%7C%207-blue?style=flat-square) ![License](https://img.shields.io/badge/License-MIT-green?style=flat-square)

> Server-side full export for [Backpack for Laravel](https://backpackforlaravel.com/) CRUD panels with active filters, search, sort, and async queue support.

Unlike the built-in DataTables client-side export (which only exports the **current page**), this package exports **all data** server-side, respecting the active filters, search term, column sort, and column visibility.

## 🎉 Features

- 📊 **Full server-side export** — all rows, not just the current page
- 🔍 **Filter-aware** — respects active Backpack filters, search term, and sort order
- 👁️ **ColVis-aware** — only exports columns visible to the user (respects the column visibility picker)
- 🔐 **Permission-aware** — respects per-user column visibility and query scoping
- ⚡ **Sync + Async** — direct download for small datasets, queue job for large ones
- 📝 **3 formats** — XLSX (Excel), CSV, Markdown (GFM tables)
- 🔌 **Pluggable progress tracker** — bring your own background task system or use the built-in no-op tracker
- 🧩 **One-line integration** — just `use ServerExportOperation;` in your CRUD controller
- 🌐 **Translated** — English and French included, easily extendable
- 🎨 **Native UI** — button integrates seamlessly into the DataTables export bar

## 📍 Installation

```bash
composer require dimer47/backpack-server-export
```

For XLSX support, also install PhpSpreadsheet:

```bash
composer require phpoffice/phpspreadsheet
```

> Without PhpSpreadsheet, only CSV and Markdown formats are available.

## 🚀 Quick Start

Add the trait to any Backpack CRUD controller:

```php
use Dimer47\BackpackServerExport\Operation\ServerExportOperation;

class ArticleCrudController extends CrudController
{
    use ListOperation;
    use ServerExportOperation; // That's it!
    // ...
}
```

A **"Full Export"** dropdown button will appear in the DataTables toolbar with XLSX, CSV, and Markdown options:

```
[ Export current view ▾ ]  [ Full Export ▾ ]  [ Column visibility ▾ ]
                            ├─ Excel (XLSX)
                            ├─ CSV
                            └─ Markdown
```

## ⚙️ Configuration

Publish the config file:

```bash
php artisan vendor:publish --tag=backpack-server-export-config
```

```php
// config/backpack-server-export.php
return [
    // Rows threshold above which export switches to async (queue job)
    // 0 = always async, null = always sync
    'async_threshold' => 1000,

    // Available export formats
    'default_formats' => ['xlsx', 'csv', 'md'],

    // Queue name for async exports
    'queue' => 'default',

    // Temporary file storage path
    'storage_path' => storage_path('app/tmp'),

    // Chunk size for async processing
    'chunk_size' => 500,

    // How long to keep generated files (minutes)
    'file_retention_minutes' => 60,
];
```

## 🔧 Customization

Override these methods in your CRUD controller to customize the behavior:

```php
class ArticleCrudController extends CrudController
{
    use ServerExportOperation;

    // Custom export columns (default: auto-resolved from CRUD list columns)
    protected function getServerExportColumns(): ?array
    {
        return [
            ExportColumn::make('title')->withLabel('Title'),
            ExportColumn::make('author')->withLabel('Author')->withRelation('author', 'name'),
            ExportColumn::make('status')->withLabel('Status'),
        ];
    }

    // Available formats (default: from config)
    protected function getServerExportFormats(): array
    {
        return ['xlsx', 'csv'];
    }

    // Async threshold (default: from config)
    protected function getServerExportAsyncThreshold(): ?int
    {
        return 500; // force async above 500 rows
    }

    // Custom filename
    protected function getServerExportFilename(string $format): string
    {
        return 'my-articles-' . now()->format('Y-m-d');
    }
}
```

## 📊 Supported Column Types

The package automatically resolves values for all standard Backpack column types:

| Type | Resolution |
|------|-----------|
| `text`, `textarea`, `email`, `url`, `phone` | Direct attribute value |
| `number` | Formatted with `decimals`, `dec_point`, `thousands_sep`, `prefix`, `suffix` |
| `date`, `datetime` | Formatted with configurable format string |
| `relationship`, `select`, `select_multiple` | Resolved via Eloquent relation + attribute |
| `enum` | Mapped via `options` array, HTML stripped |
| `select_from_array` | Mapped via `options` array |
| `closure` | Closure executed, HTML stripped |
| `custom_html` | `value()` executed, HTML stripped |
| `boolean`, `check` | Translated "Yes" / "No" |
| `model_function` | Method executed, HTML stripped |
| `image` | URL/path returned as string |
| `json` | Pretty-printed JSON string |

Complex visual types (`ace_editor`, etc.) are automatically excluded from exports.

## 🔄 Async Export with Progress Tracking

For large datasets, exports are processed as queue jobs. The package provides an `ExportProgressTrackerInterface` that you can implement to integrate with your own background task system.

### Interface

```php
use Dimer47\BackpackServerExport\Contracts\ExportProgressTrackerInterface;

interface ExportProgressTrackerInterface
{
    public function create(int $userId, string $origin, array $metadata = []): string|int;
    public function start(string|int $trackerId, int $total): void;
    public function advance(string|int $trackerId, int $done): void;
    public function complete(string|int $trackerId, string $filename): void;
    public function fail(string|int $trackerId, string $error): void;
    public function getDownloadUrl(string|int $trackerId): ?string;
}
```

### Example: Custom Tracker

```php
// app/Adapters/MyExportTracker.php
use Dimer47\BackpackServerExport\Contracts\ExportProgressTrackerInterface;

class MyExportTracker implements ExportProgressTrackerInterface
{
    public function create(int $userId, string $origin, array $metadata = []): int
    {
        return BackgroundTask::create([
            'user_id' => $userId,
            'type' => 'server_export',
            'origin' => $origin,
            'status' => 'pending',
            'metadata' => $metadata,
        ])->id;
    }

    public function start($id, int $total): void
    {
        BackgroundTask::where('id', $id)->update(['status' => 'processing', 'total' => $total]);
    }

    public function advance($id, int $done): void
    {
        BackgroundTask::where('id', $id)->update(['done' => $done]);
    }

    public function complete($id, string $filename): void
    {
        BackgroundTask::where('id', $id)->update(['status' => 'completed', 'filename' => $filename]);
    }

    public function fail($id, string $error): void
    {
        BackgroundTask::where('id', $id)->update(['status' => 'failed', 'error' => $error]);
    }

    public function getDownloadUrl($id): ?string
    {
        return null; // Let your UI handle downloads
    }
}

// app/Providers/AppServiceProvider.php
$this->app->bind(ExportProgressTrackerInterface::class, MyExportTracker::class);
```

> If no tracker is bound, the package uses a `NullProgressTracker` (no-op) — async exports still work, you just don't get progress feedback.

## 🎨 Custom Formatters

Register your own export format:

```php
use Dimer47\BackpackServerExport\Contracts\ColumnFormatterInterface;
use Dimer47\BackpackServerExport\Services\ServerExportService;

class JsonFormatter implements ColumnFormatterInterface
{
    public function getIdentifier(): string { return 'json'; }

    public function generate(iterable $rows, array $columns, string $filePath): void
    {
        file_put_contents($filePath, json_encode(iterator_to_array($rows), JSON_PRETTY_PRINT));
    }

    public function getMimeType(): string { return 'application/json'; }
    public function getFileExtension(): string { return 'json'; }
}

// In a service provider:
app(ServerExportService::class)->registerFormatter(new JsonFormatter());
```

## 🌐 Translations

Publish and customize translations:

```bash
php artisan vendor:publish --tag=backpack-server-export-lang
```

Available keys:

| Key | EN | FR |
|-----|----|----|
| `button_label` | Full Export | Export complet |
| `generating` | Generating... | Generation... |
| `export_ready` | Export ready (:count rows) | Export pret (:count lignes) |
| `export_started` | Export is being generated. Check background tasks. | Export en cours de generation. Consultez les taches en arriere-plan. |
| `format_xlsx` | Excel (XLSX) | Excel (XLSX) |
| `format_csv` | CSV | CSV |
| `format_md` | Markdown | Markdown |

## 🏗️ How It Works

### Sync Mode (rows <= threshold)

1. User clicks "Full Export" > chooses format
2. JS collects active filters, search term, sort order, visible columns from DataTable state
3. `POST /admin/resource/server-export?filters...` — filters in querystring, format in body
4. Backend applies filters/search/sort on the CRUD query (no pagination)
5. Resolves column values, generates file, returns download URL
6. Browser downloads the file immediately

### Async Mode (rows > threshold)

1-3. Same as sync
4. Backend counts rows, creates a tracker entry, dispatches a queue job
5. Returns immediately with `{ mode: 'async', tracker_id }`
6. Queue job: authenticates as requesting user, bootstraps the CRUD controller, applies filters, processes rows by chunks, generates file, marks tracker as complete
7. User sees progress in their notification center and downloads when ready

## 📋 Requirements

| Dependency | Version |
|-----------|---------|
| PHP | ^8.2 |
| Laravel | ^10.0 \| ^11.0 \| ^12.0 \| ^13.0 |
| Backpack CRUD | ^6.0 \| ^7.0 |
| PhpSpreadsheet | ^1.29 \| ^2.0 *(optional, for XLSX)* |

## 📄 License

MIT — see [LICENSE](LICENSE) for details.
