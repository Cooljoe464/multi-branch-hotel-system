<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\OfflineCommand;
use App\Models\OfflineProvisionalStay;
use App\Models\Room;
use App\Support\BranchTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OfflineSyncService
{
    public function __construct(
        private readonly OfflineOverrideService $overrides,
        private readonly EntitlementService $entitlements,
    ) {}

    /**
     * @param  array<array-key, mixed>  $commands
     * @return list<array<string, mixed>>
     */
    public function push(Branch $branch, string $deviceId, array $commands): array
    {
        return DB::transaction(function () use ($branch, $deviceId, $commands): array {
            $results = [];

            foreach ($commands as $input) {
                if (! is_array($input)) {
                    continue;
                }
                $uuid = is_string($input['command_uuid'] ?? null) ? $input['command_uuid'] : '';
                $type = is_string($input['command_type'] ?? null) ? $input['command_type'] : '';
                $payload = is_array($input['payload'] ?? null) ? $input['payload'] : [];

                if (! Str::isUuid($uuid) || $type === '') {
                    $results[] = ['command_uuid' => $uuid, 'status' => 'rejected', 'reason' => 'Invalid command envelope.'];

                    continue;
                }

                $existing = OfflineCommand::query()
                    ->where('tenant_id', $branch->tenant_id)
                    ->where('command_uuid', $uuid)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    if ($existing->branch_id !== $branch->id
                        || $existing->device_id !== $deviceId
                        || $existing->command_type !== $type
                        || ! $this->sameCommandPayload($existing->payload, $payload)) {
                        $results[] = [
                            'command_uuid' => $uuid,
                            'status' => 'rejected',
                            'reason' => 'Command UUID was already used with a different envelope.',
                        ];

                        continue;
                    }

                    $overrideToken = is_string($payload['override_token'] ?? null) ? $payload['override_token'] : null;

                    if ($existing->status === 'conflict' && $overrideToken !== null) {
                        $existing->update(['status' => 'pending', 'reason' => null, 'payload' => $payload]);
                        if ($this->rejectIfNotEntitled($existing, $branch)) {
                            $results[] = $this->result($existing->fresh() ?? $existing);

                            continue;
                        }
                        $this->applyProvisionalCheckIn($existing, $branch, $payload);
                        $results[] = $this->result($existing->fresh() ?? $existing);

                        continue;
                    }

                    $results[] = $this->result($existing);

                    continue;
                }

                $command = OfflineCommand::create([
                    'tenant_id' => $branch->tenant_id,
                    'branch_id' => $branch->id,
                    'device_id' => $deviceId,
                    'command_uuid' => $uuid,
                    'command_type' => $type,
                    'payload' => $payload,
                    'status' => 'pending',
                ]);

                if ($type !== 'check_in.provisional') {
                    $command->update(['status' => 'rejected', 'reason' => 'Unsupported offline command.']);
                    $results[] = $this->result($command->fresh() ?? $command);

                    continue;
                }

                if ($this->rejectIfNotEntitled($command, $branch)) {
                    $results[] = $this->result($command->fresh() ?? $command);

                    continue;
                }

                $this->applyProvisionalCheckIn($command, $branch, $payload);
                $results[] = $this->result($command->fresh() ?? $command);
            }

            return $results;
        }, 3);
    }

    /** @return array{commands: array<int, OfflineCommand>, stays: array<int, OfflineProvisionalStay>} */
    public function pull(Branch $branch, string $deviceId, ?string $since = null): array
    {
        $query = OfflineCommand::query()
            ->where('branch_id', $branch->id)
            ->where('device_id', $deviceId)
            ->latest('id');

        if (is_string($since) && $since !== '') {
            $query->where('updated_at', '>', $since);
        }

        return [
            'commands' => $query->limit(100)->get()->values()->all(),
            'stays' => OfflineProvisionalStay::query()->where('branch_id', $branch->id)->where('device_id', $deviceId)->latest('id')->limit(100)->get()->values()->all(),
        ];
    }

    /** @param array<array-key, mixed> $payload */
    private function applyProvisionalCheckIn(OfflineCommand $command, Branch $branch, array $payload): void
    {
        $roomId = filter_var($payload['room_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $roomTypeId = filter_var($payload['room_type_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $checkIn = is_string($payload['check_in_date'] ?? null) ? $payload['check_in_date'] : '';
        $checkOut = is_string($payload['check_out_date'] ?? null) ? $payload['check_out_date'] : '';
        $guestName = is_string($payload['guest_name'] ?? null) ? trim($payload['guest_name']) : '';

        if (! is_int($roomId) || ! is_int($roomTypeId) || $guestName === '' || $checkIn === '' || $checkOut === '') {
            $command->update(['status' => 'rejected', 'reason' => 'A provisional stay requires room, dates, and guest name.']);

            return;
        }

        try {
            $checkInDate = Carbon::createFromFormat('!Y-m-d', $checkIn, BranchTime::timezone($branch));
            $checkOutDate = Carbon::createFromFormat('!Y-m-d', $checkOut, BranchTime::timezone($branch));
        } catch (\Throwable) {
            $checkInDate = false;
            $checkOutDate = false;
        }

        if (! $checkInDate || ! $checkOutDate || $checkInDate->toDateString() !== $checkIn || $checkOutDate->toDateString() !== $checkOut || $checkIn !== BranchTime::today($branch) || $checkOut <= $checkIn) {
            $command->update(['status' => 'rejected', 'reason' => 'Offline provisional check-in is limited to valid stays arriving today.']);

            return;
        }

        $room = Room::query()
            ->whereKey($roomId)
            ->where('branch_id', $branch->id)
            ->where('is_active', true)
            ->lockForUpdate()
            ->first();
        if (! $room || $room->room_type_id !== $roomTypeId) {
            $command->update(['status' => 'rejected', 'reason' => 'The room is outside the selected branch or room type.']);

            return;
        }

        $overlap = DB::table('reservations')
            ->where('branch_id', $branch->id)
            ->where('room_id', $room->id)
            ->whereIn('status', ['reserved', 'confirmed', 'checked_in'])
            ->where('check_in_date', '<', $checkOut)
            ->where('check_out_date', '>', $checkIn)
            ->exists();

        $provisionalOverlap = OfflineProvisionalStay::query()
            ->where('branch_id', $branch->id)
            ->where('room_id', $room->id)
            ->where('status', 'provisional')
            ->where('check_in_date', '<', $checkOut)
            ->where('check_out_date', '>', $checkIn)
            ->exists();

        $override = $overlap || $provisionalOverlap
            ? $this->overrides->consume($command, is_string($payload['override_token'] ?? null) ? $payload['override_token'] : null)
            : false;

        if (($overlap || $provisionalOverlap) && ! $override
            || $room->status !== 'available'
            || $room->condition !== 'clean') {
            $command->update(['status' => 'conflict', 'reason' => 'Room assignment conflicts with current inventory.']);

            return;
        }

        OfflineProvisionalStay::create([
            'tenant_id' => $branch->tenant_id,
            'branch_id' => $branch->id,
            'offline_command_id' => $command->id,
            'device_id' => $command->device_id,
            'room_id' => $room->id,
            'room_type_id' => $roomTypeId,
            'guest_name' => $guestName,
            'guest_email' => is_string($payload['guest_email'] ?? null) ? $payload['guest_email'] : null,
            'check_in_date' => $checkIn,
            'check_out_date' => $checkOut,
            'status' => 'provisional',
            'metadata' => ['not_tax_invoice' => true, 'payment_status' => 'unpaid', 'identity_verified' => false],
        ]);

        $command->update(['status' => 'applied', 'applied_at' => now()]);
    }

    private function rejectIfNotEntitled(OfflineCommand $command, Branch $branch): bool
    {
        if ($this->entitlements->allows($branch->tenant_id, 'pms.reservations')) {
            return false;
        }

        $command->update([
            'status' => 'rejected',
            'reason' => 'The pms.reservations entitlement is not active for this property.',
        ]);

        return true;
    }

    /**
     * Manager override tokens may be added after a conflict; all other
     * command content must remain byte-for-byte equivalent in structure.
     *
     * @param  array<mixed>  $stored
     * @param  array<mixed>  $incoming
     */
    private function sameCommandPayload(array $stored, array $incoming): bool
    {
        unset($stored['override_token'], $incoming['override_token']);

        return serialize($this->canonicalize($stored)) === serialize($this->canonicalize($incoming));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private function result(OfflineCommand $command): array
    {
        return [
            'command_uuid' => $command->command_uuid,
            'status' => $command->status,
            'reason' => $command->reason,
            'applied_at' => $command->applied_at?->toIso8601String(),
        ];
    }
}
