<?php

namespace Dimer47\BackpackServerExport\Contracts;

interface ExportProgressTrackerInterface
{
    /**
     * Create a new export tracking entry and return its identifier.
     */
    public function create(int $userId, string $origin, array $metadata = []): string|int;

    /**
     * Mark the export as started with the total number of rows.
     */
    public function start(string|int $trackerId, int $total): void;

    /**
     * Update the progress (number of rows processed so far).
     */
    public function advance(string|int $trackerId, int $done): void;

    /**
     * Mark the export as completed with the generated filename.
     */
    public function complete(string|int $trackerId, string $filename): void;

    /**
     * Mark the export as failed with an error message.
     */
    public function fail(string|int $trackerId, string $error): void;

    /**
     * Return the download URL for the completed export, or null if the host app handles downloads.
     */
    public function getDownloadUrl(string|int $trackerId): ?string;
}
