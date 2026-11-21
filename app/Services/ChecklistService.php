<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\OperationalChecklist;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use LogicException;

/**
 * Operational checklists: template creation, item check-off,
 * completion workflow, and supervisor verification.
 */
class ChecklistService
{
    /**
     * Create a checklist from a list of task descriptions.
     *
     * @param  array<int, string>  $tasks
     */
    public function createFromTemplate(Branch $branch, string $department, string $shiftType, string $title, array $tasks): OperationalChecklist
    {
        $items = collect($tasks)->map(fn (string $task, int $index) => [
            'id' => $index + 1,
            'task' => $task,
            'done' => false,
            'checked_at' => null,
            'note' => null,
        ])->values()->all();

        return OperationalChecklist::create([
            'branch_id' => $branch->id,
            'department' => $department,
            'shift_type' => $shiftType,
            'title' => $title,
            'items' => $items,
            'status' => OperationalChecklist::STATUS_PENDING,
        ]);
    }

    /**
     * Check off an item in the checklist.
     */
    public function checkItem(OperationalChecklist $checklist, int $itemId, ?string $note = null): OperationalChecklist
    {
        if ($checklist->status !== OperationalChecklist::STATUS_PENDING) {
            throw new LogicException('Cannot modify a completed or reviewed checklist.');
        }

        $items = $checklist->items;
        $found = false;

        foreach ($items as &$item) {
            if ($item['id'] === $itemId) {
                $item['done'] = true;
                $item['checked_at'] = now()->toIso8601String();
                if ($note !== null) {
                    $item['note'] = $note;
                }
                $found = true;
                break;
            }
        }
        unset($item);

        if (! $found) {
            throw new LogicException("Checklist item {$itemId} not found.");
        }

        $checklist->update(['items' => $items]);

        return $checklist->fresh() ?? $checklist;
    }

    /**
     * Complete a checklist. All items must be checked.
     */
    public function complete(OperationalChecklist $checklist, User $completedBy): OperationalChecklist
    {
        $unchecked = collect($checklist->items)->filter(fn (array $item) => ! $item['done']);

        if ($unchecked->isNotEmpty()) {
            throw new LogicException('All checklist items must be completed before marking as done.');
        }

        $checklist->update([
            'status' => OperationalChecklist::STATUS_COMPLETED,
            'completed_by' => $completedBy->id,
            'completed_at' => now(),
        ]);

        return $checklist->fresh() ?? $checklist;
    }

    /**
     * Verify (supervisor sign-off) a completed checklist.
     */
    public function verify(OperationalChecklist $checklist, User $verifiedBy): OperationalChecklist
    {
        if ($checklist->status !== OperationalChecklist::STATUS_COMPLETED) {
            throw new LogicException('Only completed checklists can be verified.');
        }

        $checklist->update([
            'status' => OperationalChecklist::STATUS_REVIEWED,
            'verified_by' => $verifiedBy->id,
        ]);

        return $checklist->fresh() ?? $checklist;
    }

    /**
     * Get pending checklists for a given department and shift today.
     *
     * @return Collection<int, OperationalChecklist>
     */
    public function pendingForShift(Branch $branch, string $department, string $shiftType): Collection
    {
        return OperationalChecklist::forBranch($branch->id)
            ->pending()
            ->where('department', $department)
            ->where('shift_type', $shiftType)
            ->whereDate('created_at', now()->toDateString())
            ->get();
    }
}
