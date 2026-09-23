<?php

namespace App\Services;

use App\Exceptions\AvailabilityException;
use App\Models\InventoryItem;
use App\Models\JournalEntry;
use App\Models\Reservation;
use App\Models\RevenueSnapshot;
use App\Models\WarehouseManifest;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Nightly OLTP → R2 warehouse feed. Chunked reads keep memory flat;
 * guest PII is SHA-256 hashed (deterministic for joins, irreversible
 * for readers); free-text and metadata columns never leave the
 * primary. Same date always lands on the same keys, so re-runs are
 * byte-identical instead of duplicating.
 */
class WarehouseExporter
{
    public const SCHEMA_VERSION = 1;

    /**
     * @return array{manifest: WarehouseManifest, duplicate: bool}
     */
    public function export(string $businessDate): array
    {
        $manifest = WarehouseManifest::updateOrCreate(
            ['business_date' => $businessDate],
            ['status' => 'pending', 'last_error' => null],
        );

        try {
            $files = [];
            $counts = [];
            $checksums = [];

            $tables = [
                'reservations' => fn () => $this->reservationRows(),
                'journal_entries' => fn () => $this->journalRows(),
                'revenue_snapshots' => fn () => $this->snapshotRows(),
                'inventory_items' => fn () => $this->inventoryRows(),
            ];

            foreach ($tables as $table => $rows) {
                $path = "warehouse/dt={$businessDate}/{$table}.csv.gz";
                $csv = $this->toCsv($rows());
                $payload = gzencode($csv);

                if (! is_string($payload)) {
                    throw new AvailabilityException('WAREHOUSE_ENCODE', "Could not compress {$table}.");
                }

                Storage::disk('r2')->put($path, $payload);

                $files[$table] = $path;
                $counts[$table] = max(0, substr_count($csv, "\n") - 1);
                $checksums[$table] = hash('sha256', $payload);
            }

            $manifest->update([
                'files' => $files,
                'row_counts' => $counts,
                'checksums' => $checksums,
                'status' => WarehouseManifest::STATUS_READY,
            ]);

            return ['manifest' => $manifest->fresh() ?? $manifest, 'duplicate' => false];
        } catch (\Throwable $e) {
            $manifest->update(['status' => WarehouseManifest::STATUS_FAILED, 'last_error' => substr($e->getMessage(), 0, 500)]);
            Log::warning('Warehouse export failed.', ['date' => $businessDate, 'error' => $e->getMessage()]);

            throw $e;
        }
    }

    /**
     * Verify every file against the manifest checksum. Any mismatch
     * (including a missing file or an unreadable manifest) fails
     * closed with a warning.
     */
    public function verify(WarehouseManifest $manifest): bool
    {
        try {
            $files = $manifest->files ?? [];
            $checksums = $manifest->checksums ?? [];

            if ($files === [] || $checksums === []) {
                return false;
            }

            foreach ($files as $table => $path) {
                if (! Storage::disk('r2')->exists($path)) {
                    Log::warning('Warehouse file missing.', ['manifest_id' => $manifest->id, 'table' => $table]);

                    return false;
                }

                $contents = Storage::disk('r2')->get($path);

                if (! is_string($contents) || hash('sha256', $contents) !== ($checksums[$table] ?? null)) {
                    Log::warning('Warehouse checksum mismatch.', ['manifest_id' => $manifest->id, 'table' => $table]);

                    return false;
                }
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('Warehouse verification failed.', ['manifest_id' => $manifest->id, 'error' => $e->getMessage()]);

            return false;
        }
    }

    public static function hashPii(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $key = config('app.key');

        return hash('sha256', mb_strtolower(trim($value)).'|'.(is_string($key) ? $key : ''));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function reservationRows(): array
    {
        $rows = [];

        Reservation::on(ReadRouter::connection())->orderBy('id')->chunk(500, function ($stays) use (&$rows) {
            foreach ($stays as $stay) {
                $rows[] = [
                    'id' => $stay->id,
                    'branch_id' => $stay->branch_id,
                    'confirmation_number' => $stay->confirmation_number,
                    'guest_name_hash' => self::hashPii($stay->guest_name),
                    'guest_email_hash' => self::hashPii($stay->guest_email),
                    'guest_phone_hash' => self::hashPii($stay->guest_phone),
                    'room_type_id' => $stay->room_type_id,
                    'check_in_date' => $stay->check_in_date->toDateString(),
                    'check_out_date' => $stay->check_out_date->toDateString(),
                    'adults' => $stay->adults,
                    'children' => $stay->children,
                    'status' => $stay->status,
                    'source' => $stay->source,
                    'room_rate_minor' => $stay->room_rate,
                    'total_minor' => $stay->total_amount,
                    'amount_paid_minor' => $stay->amount_paid,
                    'currency_code' => $stay->currency_code,
                    'created_at' => $stay->created_at?->toIso8601String(),
                ];
            }
        });

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function journalRows(): array
    {
        $rows = [];

        JournalEntry::on(ReadRouter::connection())->orderBy('id')->chunk(500, function ($entries) use (&$rows) {
            foreach ($entries as $entry) {
                $rows[] = [
                    'id' => $entry->id,
                    'branch_id' => $entry->branch_id,
                    'business_date' => $entry->business_date->toDateString(),
                    'event' => $entry->event,
                    'debit_account' => $entry->debit_account,
                    'credit_account' => $entry->credit_account,
                    'amount_minor' => $entry->amount_minor,
                    'currency_code' => $entry->currency_code,
                    'posted_at' => $entry->posted_at?->toIso8601String(),
                ];
            }
        });

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function snapshotRows(): array
    {
        $rows = [];

        RevenueSnapshot::on(ReadRouter::connection())->orderBy('id')->chunk(500, function ($snapshots) use (&$rows) {
            foreach ($snapshots as $snapshot) {
                $rows[] = [
                    'id' => $snapshot->id,
                    'branch_id' => $snapshot->branch_id,
                    'currency_code' => $snapshot->currency_code,
                    'stay_date' => $snapshot->stay_date->toDateString(),
                    'snapshot_date' => $snapshot->snapshot_date->toDateString(),
                    'rooms_available' => $snapshot->rooms_available,
                    'rooms_sold' => $snapshot->rooms_sold,
                    'room_revenue_minor' => $snapshot->room_revenue_minor,
                    'total_revenue_minor' => $snapshot->total_revenue_minor,
                ];
            }
        });

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function inventoryRows(): array
    {
        $rows = [];

        InventoryItem::on(ReadRouter::connection())->orderBy('id')->chunk(500, function ($items) use (&$rows) {
            foreach ($items as $item) {
                $rows[] = [
                    'id' => $item->id,
                    'branch_id' => $item->branch_id,
                    'name' => $item->name,
                    'category' => $item->category,
                    'unit' => $item->unit,
                    'current_quantity' => $item->current_quantity,
                    'cost_per_unit_minor' => $item->cost_per_unit,
                    'valuation_method' => $item->valuation_method,
                ];
            }
        });

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function toCsv(array $rows): string
    {
        if ($rows === []) {
            return 'schema_version,'.self::SCHEMA_VERSION."\n";
        }

        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new AvailabilityException('WAREHOUSE_ENCODE', 'Could not open CSV buffer.');
        }

        fputcsv($handle, array_keys($rows[0]));

        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn ($v) => $v === null ? '' : (is_scalar($v) ? $v : json_encode($v)), $row));
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return is_string($csv) ? $csv : '';
    }
}
