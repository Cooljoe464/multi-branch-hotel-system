<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBranchRequest;
use App\Models\Branch;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Tenant;
use App\Services\EntitlementService;
use App\Services\SubscriptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class BranchWizardController extends Controller
{
    public function index(): Response
    {
        $user = Auth::user();
        abort_unless($user !== null, 401);

        return Inertia::render('admin/branches/Create', [
            'branch' => $user->currentBranch,
        ]);
    }

    public function store(StoreBranchRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $user = Auth::user();
        abort_unless($user !== null, 401);

        $existingTenantId = $user->currentBranch?->tenant_id;

        if ($existingTenantId !== null) {
            $entitlements = app(EntitlementService::class);
            $roomsToAdd = is_array($data['rooms'] ?? null) ? count($data['rooms']) : 0;
            $branchIds = Branch::query()->where('tenant_id', $existingTenantId)->pluck('id');

            if ($entitlements->limitExceeded($existingTenantId, 'limits.properties.max', $branchIds->count())) {
                throw ValidationException::withMessages([
                    'name' => 'The plan property limit has been reached. Upgrade the plan to add another property.',
                ]);
            }

            if ($entitlements->limitExceeded($existingTenantId, 'limits.rooms.max', Room::query()->whereIn('branch_id', $branchIds)->count(), $roomsToAdd)) {
                throw ValidationException::withMessages([
                    'rooms' => 'Adding these rooms would exceed the plan room limit.',
                ]);
            }
        }

        $tenantWasNew = $user->currentBranch?->tenant === null;

        $branch = DB::transaction(function () use ($data, $user) {
            $tenant = $user->currentBranch?->tenant;

            if ($tenant === null) {
                $tenant = Tenant::create([
                    'name' => $data['name'],
                    'slug' => Str::slug(is_string($data['code'] ?? null) ? $data['code'] : ''),
                    'billing_email' => $data['email'] ?? null,
                    'country' => $data['country'],
                ]);
            }

            $branch = Branch::create([
                'tenant_id' => $tenant->id,
                'name' => $data['name'],
                'code' => $data['code'],
                'slug' => Str::slug(is_string($data['code'] ?? null) ? $data['code'] : ''),
                'address' => $data['address'] ?? null,
                'city' => $data['city'],
                'state' => $data['state'] ?? null,
                'country' => $data['country'],
                'postal_code' => $data['postal_code'] ?? null,
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
                'timezone' => $data['timezone'],
                'locale' => $data['locale'] ?? 'en',
                'currency_code' => $data['currency_code'],
                'currency_symbol' => $data['currency_symbol'],
                'tax_rate' => $data['tax_rate'],
                'tax_label' => $data['tax_label'],
                'is_active' => true,
                'is_primary' => $data['is_primary'] ?? false,
            ]);

            // Create room types
            /** @var list<array{name: string, code: string, description: string|null, base_rate: int, max_occupancy: int, bed_count: int, bed_type: string}> $roomTypesData */
            $roomTypesData = $data['room_types'];
            $roomTypeMap = [];
            foreach ($roomTypesData as $rt) {
                $roomType = RoomType::create([
                    'branch_id' => $branch->id,
                    'currency_code' => $branch->currency_code,
                    'name' => $rt['name'],
                    'code' => $rt['code'],
                    'description' => $rt['description'] ?? null,
                    'base_rate' => $rt['base_rate'],
                    'max_occupancy' => $rt['max_occupancy'],
                    'bed_count' => $rt['bed_count'],
                    'bed_type' => $rt['bed_type'],
                    'is_active' => true,
                ]);
                $roomTypeMap[$rt['code']] = $roomType;
            }

            /** @var list<array{room_type_code: string, number: string, floor: string|null, wing: string|null}> $roomsData */
            $roomsData = $data['rooms'];
            foreach ($roomsData as $roomData) {
                $roomType = $roomTypeMap[$roomData['room_type_code']] ?? null;
                if (! $roomType) {
                    continue;
                }

                Room::create([
                    'branch_id' => $branch->id,
                    'room_type_id' => $roomType->id,
                    'number' => $roomData['number'],
                    'floor' => $roomData['floor'],
                    'wing' => $roomData['wing'] ?? null,
                    'status' => 'available',
                    'is_active' => true,
                ]);
            }

            return $branch;
        });

        if ($tenantWasNew) {
            app(SubscriptionService::class)->startTrial($branch->tenant, 'starter', (int) config('billing.trial_days'));
        }

        return redirect()->route('rooms.index')->with([
            'flash' => [
                'type' => 'success',
                'message' => "Branch \"{$branch->name}\" created with {$branch->roomTypes->count()} room types and {$branch->rooms->count()} rooms.",
            ],
        ]);
    }
}
