<?php

namespace App\Services;

use App\Events\SlaBreached;
use App\Events\WorkOrderAssigned;
use App\Events\WorkOrderCreated;
use App\Events\WorkOrderResolved;
use App\Exceptions\AvailabilityException;
use App\Models\Asset;
use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\MaintenanceTicket;
use App\Models\User;
use App\Support\BranchTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Preventive + reactive maintenance: SLA clocks per category/priority,
 * single-assignee work orders, PM generation, breach escalation, and
 * parts-cost resolution posting.
 */
class MaintenanceService
{
    /**
     * Raise a work order with its SLA clock started.
     *
     * @param  array<string, mixed>  $attributes  title, description, category, priority, room_id, asset_id.
     */
    public function raise(Branch $branch, array $attributes, User $reportedBy): MaintenanceTicket
    {
        $title = $attributes['title'] ?? null;
        $title = is_string($title) && $title !== '' ? $title : 'Untitled work order';

        return DB::transaction(function () use ($branch, $attributes, $reportedBy, $title) {
            $category = $attributes['category'] ?? 'other';
            $category = is_string($category) ? $category : 'other';
            $priority = $attributes['priority'] ?? 'normal';
            $priority = is_string($priority) ? $priority : 'normal';

            $roomId = $attributes['room_id'] ?? null;
            $roomId = is_int($roomId) ? $roomId : null;
            $assetId = $attributes['asset_id'] ?? null;
            $assetId = is_int($assetId) ? $assetId : null;
            $description = $this->stringOrNull($attributes['description'] ?? null) ?? $title;

            $ticket = MaintenanceTicket::create([
                'branch_id' => $branch->id,
                'room_id' => $roomId,
                'reported_by' => $reportedBy->id,
                'category' => $category,
                'priority' => $priority,
                'status' => 'open',
                'title' => $title,
                'description' => $description,
                'asset_id' => $assetId,
                'sla_due_at' => now()->addHours($this->slaHours($branch, $category, $priority)),
            ]);

            event(new WorkOrderCreated($ticket->fresh() ?? $ticket));

            return $ticket->fresh() ?? $ticket;
        });
    }

    /**
     * Assign to exactly one technician. Concurrent assigns serialize
     * on the ticket row; a taken ticket refuses a second assignee.
     */
    public function assign(MaintenanceTicket $ticket, User $assignee, ?User $by = null): MaintenanceTicket
    {
        if ($assignee->branch_id !== $ticket->branch_id) {
            throw new AvailabilityException('WO_BRANCH', 'Technicians take tickets at their own property only.');
        }

        return DB::transaction(function () use ($ticket, $assignee, $by) {
            $locked = MaintenanceTicket::where('id', $ticket->id)->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, ['completed'], true)) {
                throw new AvailabilityException('WO_STATE', 'Completed tickets cannot be reassigned.');
            }

            if ($locked->status === 'draft') {
                throw new AvailabilityException('WO_DRAFT', 'Publish the draft before assigning it.');
            }

            if ($locked->assigned_to !== null && $locked->assigned_to !== $assignee->id) {
                throw new AvailabilityException('WO_TAKEN', 'This work order already has an assignee.');
            }

            $locked->update([
                'assigned_to' => $assignee->id,
                'responded_at' => $locked->responded_at ?? now(),
            ]);

            if ($locked->status === 'open') {
                $locked->start();
            }

            event(new WorkOrderAssigned($locked->fresh() ?? $locked, $by));

            return $locked->fresh() ?? $locked;
        });
    }

    /**
     * Publish a predictive draft into the live queue. The SLA clock
     * starts here — never while the draft sits unpublished.
     */
    public function publish(MaintenanceTicket $ticket): MaintenanceTicket
    {
        return DB::transaction(function () use ($ticket) {
            $locked = MaintenanceTicket::where('id', $ticket->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'draft') {
                return $locked;
            }

            $branch = Branch::findOrFail($locked->branch_id);

            $locked->update([
                'status' => 'open',
                'sla_due_at' => now()->addHours($this->slaHours($branch, $locked->category, $locked->priority)),
            ]);

            return $locked->fresh() ?? $locked;
        });
    }

    /**
     * SLA breach check. First breach escalates (event + metadata flag);
     * re-checks are no-ops.
     */
    public function checkSla(MaintenanceTicket $ticket): bool
    {
        return DB::transaction(function () use ($ticket) {
            $locked = MaintenanceTicket::where('id', $ticket->id)->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, ['completed', 'draft'], true)) {
                return false;
            }

            if ($locked->sla_due_at === null || ! now()->greaterThan($locked->sla_due_at)) {
                return false;
            }

            $metadata = is_array($locked->metadata) ? $locked->metadata : [];

            if (($metadata['sla_escalated'] ?? false) === true) {
                return false;
            }

            $metadata['sla_escalated'] = true;
            $metadata['sla_escalated_at'] = now()->toDateTimeString();
            $locked->update(['metadata' => $metadata]);

            event(new SlaBreached($locked->fresh() ?? $locked));

            return true;
        });
    }

    /**
     * Resolve with parts: consumes inventory (never negative), posts
     * parts cost, closes the ticket. Idempotent on completed tickets.
     *
     * @param  list<array<string, mixed>>  $parts  {inventory_item_id, qty}.
     */
    public function resolve(MaintenanceTicket $ticket, array $parts, ?string $notes, ?User $by = null): MaintenanceTicket
    {
        return DB::transaction(function () use ($ticket, $parts, $notes, $by) {
            $locked = MaintenanceTicket::where('id', $ticket->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'completed') {
                return $locked;
            }

            if ($locked->status === 'draft') {
                throw new AvailabilityException('WO_DRAFT', 'Publish the draft before resolving it.');
            }

            $branch = Branch::findOrFail($locked->branch_id);
            $businessDate = (new BusinessDateService)->current($branch)->business_date->toDateString();
            $partsCost = 0;

            foreach ($parts as $part) {
                $itemId = $part['inventory_item_id'] ?? null;
                $qty = $part['qty'] ?? null;

                if (! is_int($itemId) || (! is_int($qty) && ! is_float($qty)) || $qty <= 0) {
                    continue;
                }

                $item = InventoryItem::where('id', $itemId)->lockForUpdate()->firstOrFail();

                if ($item->branch_id !== $locked->branch_id) {
                    throw new AvailabilityException('WO_BRANCH', 'Parts must belong to the ticket property.');
                }

                if ($item->current_quantity < $qty) {
                    throw new AvailabilityException('STOCK_SHORTAGE', "Insufficient {$item->name} for this resolution.");
                }

                $item->update(['current_quantity' => $item->current_quantity - $qty]);

                InventoryTransaction::create([
                    'branch_id' => $item->branch_id,
                    'inventory_item_id' => $item->id,
                    'type' => 'deduction',
                    'quantity' => $qty,
                    'created_by' => $by?->id,
                    'notes' => "Parts for ticket {$locked->ticket_number}",
                ]);

                $partsCost += (int) round($qty * $item->cost_per_unit);
            }

            if ($partsCost > 0) {
                (new PostingService(new JournalService))->post(
                    branch: $branch,
                    businessDate: $businessDate,
                    event: 'maintenance.parts',
                    amountMinor: $partsCost,
                    source: $locked,
                    createdBy: $by,
                );
            }

            $locked->complete($notes, ($locked->actual_cost ?? 0) + $partsCost);
            $locked->update(['resolved_at' => now()]);

            event(new WorkOrderResolved($locked->fresh() ?? $locked, $partsCost));

            return $locked->fresh() ?? $locked;
        });
    }

    /**
     * Generate due PM work orders. Due = no last_pm_at or older than
     * every_days. Re-runs the same day create nothing.
     *
     * @return array{generated: int}
     */
    public function schedulePm(Branch $branch): array
    {
        $generated = 0;
        $today = BranchTime::today($branch);

        foreach (Asset::forBranch($branch->id)->orderBy('id')->get() as $asset) {
            $every = $asset->pmEveryDays();

            if ($every === null) {
                continue;
            }

            $last = $asset->last_pm_at?->toDateString();

            if ($last !== null && Carbon::parse($last)->diffInDays($today) < $every) {
                continue;
            }

            $exists = MaintenanceTicket::forBranch($branch->id)
                ->where('asset_id', $asset->id)
                ->whereDate('created_at', $today)
                ->where('title', 'like', 'PM:%')
                ->exists();

            if ($exists) {
                continue;
            }

            DB::transaction(function () use ($branch, $asset, $today, &$generated) {
                $system = User::where('branch_id', $branch->id)->orderBy('id')->firstOrFail();

                $ticket = $this->raise($branch, [
                    'title' => "PM: {$asset->name}",
                    'description' => $this->checklistText($asset),
                    'category' => $this->ticketCategory($asset->category),
                    'priority' => 'normal',
                    'room_id' => $asset->room_id,
                    'asset_id' => $asset->id,
                ], $system);

                $ticket->update(['metadata' => array_merge(
                    is_array($ticket->metadata) ? $ticket->metadata : [],
                    ['pm_key' => "pm.{$asset->id}.{$today}"],
                )]);

                $asset->update(['last_pm_at' => $today]);
                $generated++;
            });
        }

        return ['generated' => $generated];
    }

    /**
     * SLA hours for a category/priority from branch settings, with
     * sane defaults. Settings shape: sla: {plumbing: {urgent: 2}}.
     */
    public function slaHours(Branch $branch, string $category, string $priority): int
    {
        $settings = $branch->settings;
        $table = is_array($settings) ? ($settings['sla'] ?? null) : null;

        if (is_array($table)) {
            $row = $table[$category] ?? null;
            $hours = is_array($row) ? ($row[$priority] ?? null) : null;

            if (is_int($hours) && $hours > 0) {
                return $hours;
            }
        }

        return match ($priority) {
            'urgent' => 4,
            'high' => 24,
            'normal' => 72,
            default => 168,
        };
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function checklistText(Asset $asset): string
    {
        $schedule = $asset->pm_schedule;
        $items = is_array($schedule) ? ($schedule['checklist'] ?? []) : [];

        if (! is_array($items) || $items === []) {
            return 'Run the standard preventive checklist.';
        }

        $lines = [];
        foreach ($items as $item) {
            if (is_string($item) && $item !== '') {
                $lines[] = "- {$item}";
            }
        }

        return $lines === [] ? 'Run the standard preventive checklist.' : implode("\n", $lines);
    }

    private function ticketCategory(string $category): string
    {
        return in_array($category, ['plumbing', 'electrical', 'hvac', 'furniture', 'appliance', 'structural'], true)
            ? $category
            : 'other';
    }
}
