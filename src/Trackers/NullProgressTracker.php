<?php

namespace Dimer47\BackpackServerExport\Trackers;

use Dimer47\BackpackServerExport\Contracts\ExportProgressTrackerInterface;

class NullProgressTracker implements ExportProgressTrackerInterface
{
    public function create(int $userId, string $origin, array $metadata = []): string|int
    {
        return uniqid('export_');
    }

    public function start(string|int $trackerId, int $total): void
    {
        // No-op
    }

    public function advance(string|int $trackerId, int $done): void
    {
        // No-op
    }

    public function complete(string|int $trackerId, string $filename): void
    {
        // No-op
    }

    public function fail(string|int $trackerId, string $error): void
    {
        // No-op
    }

    public function getDownloadUrl(string|int $trackerId): ?string
    {
        return null;
    }
}
