<?php

namespace App\Jobs;

use App\Models\Guest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * One-shot backfill: re-save legacy plaintext ID numbers through the
 * encrypted cast, verifying a decrypt round-trip per row. Safe to
 * re-run; already-encrypted values are detected by prefix.
 */
class EncryptIdsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue('maintenance');
    }

    /**
     * @return array{converted: int, skipped: int}
     */
    public function handle(): array
    {
        $converted = 0;
        $skipped = 0;

        Guest::whereNotNull('id_number')->orderBy('id')->chunkById(200, function ($guests) use (&$converted, &$skipped) {
            foreach ($guests as $guest) {
                $raw = $guest->getAttributes()['id_number'] ?? null;

                if (! is_string($raw) || $raw === '' || str_starts_with($raw, 'eyJ')) {
                    $skipped++;

                    continue;
                }

                $guest->update(['id_number' => $raw]);

                $roundTrip = Guest::find($guest->id)?->id_number;

                if ($roundTrip === $raw) {
                    $converted++;
                } else {
                    Log::error('ID encryption round-trip failed.', ['guest_id' => $guest->id]);
                    $skipped++;
                }
            }
        });

        Log::info('ID encryption backfill completed.', ['converted' => $converted, 'skipped' => $skipped]);

        return ['converted' => $converted, 'skipped' => $skipped];
    }
}
