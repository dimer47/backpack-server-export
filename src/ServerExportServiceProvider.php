<?php

namespace Dimer47\BackpackServerExport;

use Dimer47\BackpackServerExport\Contracts\ExportProgressTrackerInterface;
use Dimer47\BackpackServerExport\Formatters\CsvFormatter;
use Dimer47\BackpackServerExport\Formatters\MarkdownFormatter;
use Dimer47\BackpackServerExport\Formatters\XlsxFormatter;
use Dimer47\BackpackServerExport\Trackers\NullProgressTracker;
use Illuminate\Support\ServiceProvider;

class ServerExportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/backpack-server-export.php', 'backpack-server-export');

        // Bind the default tracker (NullProgressTracker) only if not already bound by the host app
        $this->app->bindIf(ExportProgressTrackerInterface::class, NullProgressTracker::class);

        // Register available formatters
        $formatters = [CsvFormatter::class, MarkdownFormatter::class];
        if (class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
            $formatters[] = XlsxFormatter::class;
        }
        $this->app->tag($formatters, 'server-export.formatters');
    }

    public function boot(): void
    {
        // Publish config
        $this->publishes([
            __DIR__.'/../config/backpack-server-export.php' => config_path('backpack-server-export.php'),
        ], 'backpack-server-export-config');

        // Load views
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'backpack-server-export');

        // Load translations
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'backpack-server-export');

        // Publish views
        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/backpack-server-export'),
        ], 'backpack-server-export-views');

        // Publish translations
        $this->publishes([
            __DIR__.'/../resources/lang' => lang_path('vendor/backpack-server-export'),
        ], 'backpack-server-export-lang');
    }
}
