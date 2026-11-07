<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BillingInvoice;
use App\Models\Branch;
use App\Models\Subscription;
use App\Services\EntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SaasController extends Controller
{
    public function subscription(Request $request): JsonResponse
    {
        $subscription = Subscription::query()
            ->with('plan:id,key,name,currency')
            ->where('tenant_id', $this->tenantId($request))
            ->first();

        if ($subscription === null) {
            return response()->json(['data' => null]);
        }

        return response()->json(['data' => [
            'status' => $subscription->status,
            'plan' => $subscription->plan->only(['key', 'name', 'currency']),
            'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
            'current_period_ends_at' => $subscription->current_period_ends_at?->toIso8601String(),
            'past_due_at' => $subscription->past_due_at?->toIso8601String(),
            'grace_ends_at' => $subscription->grace_ends_at?->toIso8601String(),
            'restricted_at' => $subscription->restricted_at?->toIso8601String(),
        ]]);
    }

    public function invoices(Request $request): JsonResponse
    {
        $invoices = BillingInvoice::query()
            ->where('tenant_id', $this->tenantId($request))
            ->orderByDesc('period_start')
            ->orderByDesc('id')
            ->limit(24)
            ->get();

        return response()->json(['data' => $invoices->map(fn (BillingInvoice $invoice): array => [
            'number' => $invoice->number,
            'period_start' => $invoice->period_start->toDateString(),
            'period_end' => $invoice->period_end->toDateString(),
            'currency' => $invoice->currency,
            'subtotal_minor' => $invoice->subtotal_minor,
            'tax_minor' => $invoice->tax_minor,
            'total_minor' => $invoice->total_minor,
            'status' => $invoice->status,
            'due_at' => $invoice->due_at->toIso8601String(),
            'paid_at' => $invoice->paid_at?->toIso8601String(),
        ])->values()]);
    }

    public function entitlements(Request $request, EntitlementService $entitlements): JsonResponse
    {
        $resolved = $entitlements->resolve($this->tenantId($request));

        return response()->json(['data' => [
            'metered' => $resolved !== null,
            'entitlements' => $resolved ?? (object) [],
        ]]);
    }

    private function tenantId(Request $request): int
    {
        /** @var Branch $branch */
        $branch = $request->attributes->get('branch');

        return $branch->tenant_id;
    }
}
