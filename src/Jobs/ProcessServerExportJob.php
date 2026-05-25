<?php

namespace Dimer47\BackpackServerExport\Jobs;

use Backpack\CRUD\app\Library\CrudPanel\CrudPanelFacade as CRUD;
use Dimer47\BackpackServerExport\Contracts\ExportProgressTrackerInterface;
use Dimer47\BackpackServerExport\Services\ServerExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Request;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ProcessServerExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(
        private string $controllerClass,
        private int $userId,
        private array $filters,
        private string $search,
        private array $order,
        private array $visibleColumns,
        private string $format,
        private string $filename,
        private string|int $trackerId,
    ) {
        $this->queue = config('backpack-server-export.queue', 'default');
    }

    public function handle(ServerExportService $service, ExportProgressTrackerInterface $tracker): void
    {
        try {
            // Load the user who requested the export
            $userModel = config('backpack.base.user_model', 'App\\Models\\User\\User');
            $user = $userModel::findOrFail($this->userId);

            // Create a fake request with filter params and authenticated user
            // MUST be done BEFORE Auth::login() because the Login event listener
            // (ActivityLogger) calls Request::user()->id
            $requestParams = $this->filters;
            $fakeRequest = Request::create(
                uri: '/'.config('backpack.base.route_prefix', 'admin').'/server-export-job',
                method: 'POST',
                parameters: $requestParams,
            );
            $fakeRequest->setUserResolver(fn () => $user);

            // Swap the application request BEFORE login and controller instantiation
            app()->instance('request', $fakeRequest);
            \Illuminate\Support\Facades\Request::swap($fakeRequest);

            // Now login (events will have access to Request::user() via our fake request)
            Auth::login($user);

            // Instantiate the controller
            $controller = app()->make($this->controllerClass);

            // Set operation to 'list' BEFORE initializeCrudPanel so that
            // setupListOperation() is called and columns are defined
            $controller->crud->setCurrentOperation('list');
            $controller->initializeCrudPanel($fakeRequest);

            $crud = $controller->crud;

            // Apply filters
            $crud->applyUnappliedFilters();

            // Apply search
            if (! empty($this->search)) {
                $crud->applySearchTerm($this->search);
            }

            // Apply ordering
            if (! empty($this->order)) {
                $fakeRequest->merge(['order' => $this->order]);
                $crud->applyDatatableOrder();
            }

            // Get query without pagination
            $query = $crud->query;

            // Resolve columns from CRUD (now we have full context with closures)
            $columns = $service->resolveColumnsFromCrud($crud, $this->visibleColumns);

            if (empty($columns)) {
                $tracker->fail($this->trackerId, 'No columns resolved from CRUD.');

                return;
            }

            // Get formatter
            $formatter = $service->getFormatter($this->format);
            if (! $formatter) {
                $tracker->fail($this->trackerId, "Unknown export format: {$this->format}");

                return;
            }

            // Eager-load relations
            $relations = [];
            foreach ($columns as $col) {
                if ($col->entity) {
                    $relations[] = $col->entity;
                }
            }
            if (! empty($relations)) {
                $query->with($relations);
            }

            // Count total
            $total = (clone $query)->count();
            $tracker->start($this->trackerId, $total);

            // Process by chunks
            $storagePath = config('backpack-server-export.storage_path', storage_path('app/tmp'));
            if (! is_dir($storagePath)) {
                mkdir($storagePath, 0755, true);
            }

            $fullFilename = $this->filename.'.'.$formatter->getFileExtension();
            $filePath = $storagePath.'/'.$fullFilename;
            $chunkSize = config('backpack-server-export.chunk_size', 500);

            $rows = [];
            $done = 0;

            $query->chunk($chunkSize, function ($entries) use ($columns, $service, &$rows, &$done, $tracker) {
                foreach ($entries as $entry) {
                    $rows[] = $service->resolveRow($entry, $columns);
                    $done++;
                }
                $tracker->advance($this->trackerId, $done);
            });

            // Generate file
            $formatter->generate($rows, $columns, $filePath);

            $tracker->complete($this->trackerId, $fullFilename);

            Auth::logout();
        } catch (\Throwable $e) {
            Log::error('ServerExport job failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'tracker_id' => $this->trackerId,
                'controller' => $this->controllerClass,
            ]);

            $tracker->fail($this->trackerId, $e->getMessage());

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        try {
            $tracker = app(ExportProgressTrackerInterface::class);
            $tracker->fail($this->trackerId, 'Job failed: '.$exception->getMessage());
        } catch (\Throwable) {
            // Silently fail if tracker itself is broken
        }
    }
}
