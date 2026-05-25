<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Async Threshold
    |--------------------------------------------------------------------------
    |
    | Number of rows above which the export switches to async (job in queue).
    | Set to 0 for always async, null for always sync.
    |
    */
    'async_threshold' => 1000,

    /*
    |--------------------------------------------------------------------------
    | Default Formats
    |--------------------------------------------------------------------------
    |
    | Available export formats. Supported: 'xlsx', 'csv'
    |
    */
    'default_formats' => ['xlsx', 'csv', 'md'],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | The queue to use for async export jobs.
    |
    */
    'queue' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Storage Path
    |--------------------------------------------------------------------------
    |
    | Directory where temporary export files are stored.
    |
    */
    'storage_path' => storage_path('app/tmp'),

    /*
    |--------------------------------------------------------------------------
    | Chunk Size
    |--------------------------------------------------------------------------
    |
    | Number of rows to process per chunk during async export.
    |
    */
    'chunk_size' => 500,

    /*
    |--------------------------------------------------------------------------
    | File Retention
    |--------------------------------------------------------------------------
    |
    | How long to keep generated export files (in minutes).
    |
    */
    'file_retention_minutes' => 60,
];
