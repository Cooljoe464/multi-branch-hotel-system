<?php

namespace App\Http\Controllers;

use App\Events\RestrictionsChanged;
use App\Http\Controllers\Concerns\EnsuresBranchAccess;
use App\Models\Branch;
use App\Models\RatePlan;
use App\Models\RateRestriction;
use App\Models\RoomType;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RateRestrictionController extends Controller
{
    use EnsuresBranchAccess;

    public function index(Request $request, Branch $branch): Response
    {
        $this->ensureBranchAccess($branch);

        $request->validate([
            'rate_plan_id' => 'nullable|exists:rate_plans,id',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after:from',
        ]);

        $plans = RatePlan::forBranch($branch->id)->active()->get();
        $roomTypes = RoomType::forBranch($branch->id)->active()->get();

        $planId = $request->integer('rate_plan_id') ?: $plans->first()?->id;
        $from = $request->string('from')->value() !== '' ? $request->string('from')->value() : now()->toDateString();
        $to = $request->string('to')->value() !== '' ? $request->string('to')->value() : now()->addDays(13)->toDateString();

        $rows = RateRestriction::forBranch($branch->id)
            ->when($planId, fn ($q) => $q->where('rate_plan_id', $planId))
            ->whereBetween('stay_date', [$from, $to])
            ->orderBy('stay_date')
            ->get();

        return Inertia::render('rates/Restrictions', [
            'branch' => $branch->only(['id', 'name', 'code']),
            'plans' => $plans,
            'roomTypes' => $roomTypes,
            'filters' => ['rate_plan_id' => $planId, 'from' => $from, 'to' => $to],
            'rows' => $rows,
        ]);
    }

    /**
     * Bulk upsert a date range for one plan (optionally one room type).
     */
    public function storeRange(Request $request, Branch $branch): RedirectResponse
    {
        $this->ensureBranchAccess($branch);

        $request->validate([
            'rate_plan_id' => 'required|exists:rate_plans,id',
            'room_type_id' => 'nullable|exists:room_types,id',
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
            'min_los' => 'nullable|integer|min:1|max:60',
            'max_los' => 'nullable|integer|min:1|max:60',
            'cta' => 'nullable|boolean',
            'ctd' => 'nullable|boolean',
            'stop_sell' => 'nullable|boolean',
            'min_advance_hours' => 'nullable|integer|min:0|max:720',
            'clear' => 'nullable|boolean',
        ]);

        $plan = RatePlan::findOrFail($request->integer('rate_plan_id'));
        abort_unless($plan->branch_id === $branch->id, 403);

        $roomTypeId = $request->filled('room_type_id') ? $request->integer('room_type_id') : null;

        if ($roomTypeId !== null) {
            $roomType = RoomType::findOrFail($roomTypeId);
            abort_unless($roomType->branch_id === $branch->id, 403);
        }

        $day = Carbon::parse($request->string('from')->value())->startOfDay();
        $end = Carbon::parse($request->string('to')->value())->startOfDay();

        while (! $day->greaterThan($end)) {
            $date = $day->toDateString();

            if ($request->boolean('clear')) {
                RateRestriction::forBranch($branch->id)
                    ->where('rate_plan_id', $plan->id)
                    ->where('room_type_id', $roomTypeId)
                    ->where('stay_date', $date)
                    ->delete();
            } else {
                RateRestriction::updateOrCreate(
                    ['rate_plan_id' => $plan->id, 'room_type_id' => $roomTypeId, 'stay_date' => $date],
                    [
                        'branch_id' => $branch->id,
                        'min_los' => $request->input('min_los'),
                        'max_los' => $request->input('max_los'),
                        'cta' => $request->boolean('cta'),
                        'ctd' => $request->boolean('ctd'),
                        'stop_sell' => $request->boolean('stop_sell'),
                        'min_advance_hours' => $request->input('min_advance_hours'),
                    ],
                );
            }

            $day = $day->addDay();
        }

        event(new RestrictionsChanged($branch));

        return $this->flashSuccess('Restrictions saved.');
    }
}
