<?php

namespace App\Services;

use App\Events\DnrListed;
use App\Events\GuestMerged;
use App\Exceptions\AvailabilityException;
use App\Models\Branch;
use App\Models\DoNotRent;
use App\Models\Guest;
use App\Models\GuestMergeLink;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Guest dedup, merge and do-not-rent enforcement. The dedup hash
 * (soundex names + phone digits + birth date) follows every profile
 * write; merges repoint related rows in one transaction and leave a
 * redirect link behind.
 */
class GuestDedupService
{
    /**
     * Tables repointed on merge: [table => guest FK]. Later phases add
     * rows here (consents, loyalty, surveys, DSAR) and merges pick
     * them up automatically when the tables exist.
     *
     * @return array<string, string>
     */
    public static function mergeable(): array
    {
        return [
            'reservations' => 'guest_id',
            'guest_preferences' => 'guest_id',
            'guest_identity_documents' => 'guest_id',
            'consents' => 'guest_id',
            'loyalty_accounts' => 'guest_id',
            'dsar_requests' => 'guest_id',
        ];
    }

    public static function hashFor(string $first, string $last, mixed $phone, mixed $dob): string
    {
        $digits = preg_replace('/\D+/', '', is_string($phone) ? $phone : '') ?? '';

        if ($dob instanceof \DateTimeInterface) {
            $birth = $dob->format('Y-m-d');
        } elseif (is_string($dob) && $dob !== '') {
            $birth = substr($dob, 0, 10);
        } else {
            $birth = '';
        }

        return md5(implode('|', [
            soundex(trim($first)) ?: '-',
            soundex(trim($last)) ?: '-',
            $digits,
            $birth,
        ]));
    }

    /**
     * @return Collection<int, Guest>
     */
    public function candidates(int $limit = 100): Collection
    {
        $hashes = Guest::whereNull('master_guest_id')
            ->whereNotNull('dedup_hash')
            ->selectRaw('dedup_hash, COUNT(*) as total')
            ->groupBy('dedup_hash')
            ->havingRaw('COUNT(*) > 1')
            ->orderByDesc('total')
            ->limit($limit)
            ->pluck('dedup_hash');

        return Guest::whereNull('master_guest_id')
            ->whereIn('dedup_hash', $hashes)
            ->orderBy('dedup_hash')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, Guest>
     */
    public function suggest(Guest $guest): Collection
    {
        if ($guest->dedup_hash === null) {
            return new Collection;
        }

        return Guest::whereNull('master_guest_id')
            ->where('id', '!=', $guest->id)
            ->where('dedup_hash', $guest->dedup_hash)
            ->orderBy('id')
            ->get();
    }

    /**
     * Merge retired into survivor: repoint related rows, freeze
     * survivor fields per choices, link + log. Re-runs return the
     * original link. Concurrent merges of the same pair collapse.
     *
     * @param  array<string, string>  $fieldChoices  field => 'survivor'|'retired'.
     */
    public function merge(Guest $survivor, Guest $retired, array $fieldChoices, User $by): GuestMergeLink
    {
        if ($survivor->id === $retired->id) {
            throw new AvailabilityException('MERGE_SAME', 'A profile cannot merge into itself.');
        }

        return DB::transaction(function () use ($survivor, $retired, $fieldChoices, $by) {
            $freshSurvivor = Guest::where('id', $survivor->id)->lockForUpdate()->firstOrFail();
            $freshRetired = Guest::where('id', $retired->id)->lockForUpdate()->firstOrFail();

            if ($freshRetired->master_guest_id !== null) {
                return GuestMergeLink::where('surviving_guest_id', $freshSurvivor->id)
                    ->where('retired_guest_id', $freshRetired->id)
                    ->firstOrFail();
            }

            if ($freshSurvivor->master_guest_id !== null) {
                throw new AvailabilityException('MERGE_RETIRED', 'The surviving profile is itself retired.');
            }

            $activeStay = $freshRetired->reservations()
                ->where('status', 'checked_in')
                ->exists();

            if ($activeStay) {
                throw new AvailabilityException('MERGE_ACTIVE_STAY', 'Retire after the guest checks out: an active stay is on the profile.');
            }

            foreach (self::mergeable() as $table => $column) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                    continue;
                }

                DB::table($table)->where($column, $freshRetired->id)->update([$column => $freshSurvivor->id]);
            }

            $updates = [];
            foreach ($fieldChoices as $field => $choice) {
                if ($choice === 'retired' && in_array($field, $freshRetired->getFillable(), true)) {
                    $updates[$field] = $freshRetired->getAttribute($field);
                }
            }

            if ($updates !== []) {
                $freshSurvivor->update($updates);
            }

            // Survivor accumulates lifetime stats; totals stay exact.
            $freshSurvivor->update([
                'total_stays' => $freshSurvivor->total_stays + $freshRetired->total_stays,
                'total_nights' => $freshSurvivor->total_nights + $freshRetired->total_nights,
                'total_spent' => $freshSurvivor->total_spent + $freshRetired->total_spent,
            ]);
            $freshSurvivor->evaluateVipStatus();

            $freshRetired->update(['master_guest_id' => $freshSurvivor->id]);

            try {
                $link = GuestMergeLink::create([
                    'surviving_guest_id' => $freshSurvivor->id,
                    'retired_guest_id' => $freshRetired->id,
                    'merged_by' => $by->id,
                    'field_choices' => $fieldChoices,
                ]);
            } catch (QueryException $e) {
                $code = $e->getPrevious()?->getCode();

                if ($code === '23000' || $code === '23505') {
                    return GuestMergeLink::where('surviving_guest_id', $freshSurvivor->id)
                        ->where('retired_guest_id', $freshRetired->id)
                        ->firstOrFail();
                }

                throw $e;
            }

            activity('guests')
                ->performedOn($freshSurvivor)
                ->causedBy($by)
                ->withProperties(['retired_id' => $freshRetired->id])
                ->log("Merged duplicate profile {$freshRetired->id} into {$freshSurvivor->id}.");

            event(new GuestMerged($freshSurvivor->fresh() ?? $freshSurvivor, $link));

            return $link;
        });
    }

    /**
     * Block DNR bookings. Override needs the guests.manage_dnr
     * permission plus a reason; the override is logged, never silent.
     */
    public function assertRentable(Branch $branch, ?int $guestId, ?string $email, ?User $overrider = null, ?string $overrideReason = null): void
    {
        $match = DoNotRent::forBranch($branch->id)
            ->where(function ($query) use ($guestId, $email) {
                if ($guestId !== null) {
                    $query->where('guest_id', $guestId);
                }

                if (is_string($email) && $email !== '') {
                    $query->orWhere('email', strtolower(trim($email)));
                }
            })
            ->first();

        if (! $match) {
            return;
        }

        if ($overrider !== null && trim((string) $overrideReason) !== ''
            && ($overrider->can('guests.manage_dnr') || (bool) ($overrider->is_global_admin ?? false))) {
            activity('guests')
                ->causedBy($overrider)
                ->withProperties(['dnr_id' => $match->id, 'reason' => $overrideReason])
                ->log("DNR override for booking (list {$match->id}).");

            return;
        }

        if ($overrider !== null || ($overrideReason !== null && trim($overrideReason) !== '')) {
            throw new AvailabilityException('DNR_OVERRIDE_FORBIDDEN', 'Overriding do-not-rent requires the guests.manage_dnr permission.');
        }

        throw new AvailabilityException('DO_NOT_RENT', 'This booking cannot be completed.');
    }

    public function listDnr(?Branch $branch, ?int $guestId, ?string $email, string $reason, User $by): DoNotRent
    {
        $email = is_string($email) && trim($email) !== '' ? strtolower(trim($email)) : null;

        $entry = DoNotRent::create([
            'branch_id' => $branch?->id,
            'guest_id' => $guestId,
            'email' => $email,
            'reason' => $reason,
            'listed_by' => $by->id,
        ]);

        event(new DnrListed($entry));

        return $entry;
    }
}
