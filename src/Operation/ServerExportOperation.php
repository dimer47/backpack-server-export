<?php

namespace Dimer47\BackpackServerExport\Operation;

use Backpack\CRUD\app\Library\CrudPanel\CrudPanelFacade as CRUD;
use Dimer47\BackpackServerExport\Contracts\ExportProgressTrackerInterface;
use Dimer47\BackpackServerExport\Jobs\ProcessServerExportJob;
use Dimer47\BackpackServerExport\Services\ServerExportService;
use Illuminate\Support\Facades\Route;

trait ServerExportOperation
{
    protected function setupServerExportRoutes(string $segment, string $routeName, string $controller): void
    {
        Route::post($segment.'/server-export', [
            'as' => $routeName.'.serverExport',
            'uses' => $controller.'@serverExport',
            'operation' => 'list',
        ]);

        Route::get($segment.'/server-export-download', [
            'as' => $routeName.'.serverExportDownload',
            'uses' => $controller.'@serverExportDownload',
            'operation' => 'list',
        ]);
    }

    protected function setupServerExportDefaults(): void
    {
        CRUD::allowAccess('serverExport');

        CRUD::operation(['list'], function () {
            CRUD::set('serverExport.formats', $this->getServerExportFormats());
            CRUD::set('serverExport.route', $this->crud->getRoute());
            CRUD::addButton('top', 'server_export', 'view', 'backpack-server-export::buttons.server_export', 'end');
        });

        // Also allow access during list operation (since our routes use operation 'list')
        CRUD::operation('list', function () {
            CRUD::allowAccess('serverExport');
        });
    }

    /**
     * POST endpoint: triggers the server-side export.
     */
    public function serverExport()
    {
        CRUD::hasAccessOrFail('serverExport');

        $format = request()->input('format', 'xlsx');
        $search = request()->input('search', '');
        $order = request()->input('order', []);
        $visibleColumns = request()->input('visible_columns', []);

        // Filters come from the querystring (same as DataTables AJAX URL)
        $filters = request()->query();

        $availableFormats = $this->getServerExportFormats();
        if (! in_array($format, $availableFormats)) {
            return response()->json([
                'success' => false,
                'message' => trans('backpack-server-export::server-export.unknown_format'),
            ], 400);
        }

        // Filters are already in the querystring (sent by JS as URL params),
        // so CrudFilters can read them via request()->has(). Just apply them.
        $this->crud->applyUnappliedFilters();

        // Apply search term
        if (! empty($search)) {
            $this->crud->applySearchTerm($search);
        }

        // Apply ordering
        if (! empty($order)) {
            request()->merge(['order' => $order]);
            $this->crud->applyDatatableOrder();
        }

        // Get the query WITHOUT pagination
        $query = $this->crud->query;

        // Count to determine sync/async
        $count = (clone $query)->count();
        $threshold = $this->getServerExportAsyncThreshold();

        // Resolve columns (filter by visible_columns from ColVis if provided)
        $service = app(ServerExportService::class);
        $customColumns = $this->getServerExportColumns();
        $columns = $customColumns ?? $service->resolveColumnsFromCrud($this->crud, $visibleColumns);

        $filename = $this->getServerExportFilename($format);

        if ($threshold !== null && $count <= $threshold) {
            // SYNCHRONOUS mode
            $downloadRoute = url($this->crud->getRoute().'/server-export-download');

            $result = $service->exportSync(
                query: clone $query,
                columns: $columns,
                format: $format,
                filename: $filename,
                downloadRoute: $downloadRoute,
            );

            return response()->json([
                'success' => true,
                'mode' => 'sync',
                'download_url' => $result['download_url'],
                'count' => $result['count'],
                'message' => trans('backpack-server-export::server-export.export_ready', ['count' => $result['count']]),
            ]);
        }

        // ASYNCHRONOUS mode
        // Respond immediately, job will reconstruct CRUD context and process by chunks.
        $tracker = app(ExportProgressTrackerInterface::class);
        $entityLabel = $this->crud->entity_name_plural ?? $this->crud->entity_name ?? '';
        $trackerId = $tracker->create(
            backpack_user()->id,
            $this->getServerExportOrigin(),
            ['format' => $format, 'estimated_count' => $count, 'entity' => $entityLabel]
        );

        ProcessServerExportJob::dispatch(
            controllerClass: get_class($this),
            userId: backpack_user()->id,
            filters: is_array($filters) ? $filters : [],
            search: $search ?? '',
            order: is_array($order) ? $order : [],
            visibleColumns: $visibleColumns,
            format: $format,
            filename: $filename,
            trackerId: $trackerId,
        );

        return response()->json([
            'success' => true,
            'mode' => 'async',
            'tracker_id' => $trackerId,
            'estimated_count' => $count,
            'message' => trans('backpack-server-export::server-export.export_started'),
        ]);
    }

    /**
     * GET endpoint: download the generated file (sync mode).
     */
    public function serverExportDownload()
    {
        CRUD::hasAccessOrFail('serverExport');

        $filename = request()->query('file');

        if (empty($filename)) {
            abort(404);
        }

        // Sanitize
        $filename = basename($filename);

        // Validate filename pattern
        if (! preg_match('/^export-.+\.(csv|xlsx|md)$/', $filename)) {
            abort(404);
        }

        $service = app(ServerExportService::class);

        return $service->download($filename);
    }

    // === Overridable methods ===

    protected function getServerExportFormats(): array
    {
        return config('backpack-server-export.default_formats', ['xlsx', 'csv']);
    }

    protected function getServerExportAsyncThreshold(): ?int
    {
        return config('backpack-server-export.async_threshold', 1000);
    }

    protected function getServerExportFilename(string $format): string
    {
        $entity = $this->crud->entity_name_plural ?? 'export';
        $entity = preg_replace('/[^a-zA-Z0-9_-]/', '_', $entity);
        $date = now()->format('Y-m-d_His');

        return "export-{$entity}-{$date}";
    }

    protected function getServerExportOrigin(): string
    {
        return 'server_export';
    }

    /**
     * Override to define custom export columns.
     * Return null to use the CRUD list columns automatically.
     *
     * @return \Dimer47\BackpackServerExport\Dto\ExportColumn[]|null
     */
    protected function getServerExportColumns(): ?array
    {
        return null;
    }
}
