<?php

namespace App\Jobs;

use App\Imports\GuestImport;
use App\Imports\ReservationImport;
use App\Imports\RoomImport;
use App\Imports\RoomingListImport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class ProcessExcelImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public string $filePath,
        public string $importType,
        public int $branchId,
        public int $userId,
    ) {
        $this->onQueue('imports');
    }

    /**
     * @return array{created: int, skipped: int}
     */
    public function handle(): array
    {
        try {
            $import = $this->resolveImport();

            Excel::import($import, $this->filePath);

            $created = $import->getCreatedCount();
            $skipped = $import->getSkippedCount();

            Log::info("Import completed: {$this->importType}", [
                'user_id' => $this->userId,
                'branch_id' => $this->branchId,
                'created' => $created,
                'skipped' => $skipped,
            ]);

            return compact('created', 'skipped');
        } finally {
            Storage::disk('local')->delete($this->filePath);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("Import failed: {$this->importType}", [
            'user_id' => $this->userId,
            'branch_id' => $this->branchId,
            'error' => $exception->getMessage(),
        ]);

        Storage::disk('local')->delete($this->filePath);
    }

    private function resolveImport(): GuestImport|ReservationImport|RoomImport|RoomingListImport
    {
        // Rooming lists carry their block: type rooming:{blockId}.
        if (str_starts_with($this->importType, 'rooming:')) {
            $blockId = (int) substr($this->importType, strlen('rooming:'));

            return new RoomingListImport($this->branchId, $blockId);
        }

        return match ($this->importType) {
            'rooms' => new RoomImport($this->branchId),
            'guests' => new GuestImport,
            'reservations' => new ReservationImport($this->branchId),
            default => throw new \InvalidArgumentException("Unknown import type: {$this->importType}"),
        };
    }
}
