<?php

namespace App\Http\Controllers;

use App\Models\BillingInvoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantEntitlementOverride;
use App\Services\BillingService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Platform-owner screen for the commercial control plane: who is on which
 * plan, overrides, and manual payment reconciliation. Restricted to platform
 * owners (the is_global_admin flag), never a tenant's own admins.
 */
class PlatformSubscriptionController extends Controller
{
    public function index(Request $request): Response
    {
        $this->ensurePlatformOwner($request);

        return Inertia::render('platform/Subscriptions', [
            'tenants' => Tenant::query()
                ->with('subscription.plan:id,key,name')
                ->orderBy('name')
                ->orderBy('id')
                ->get()
                ->map(fn (Tenant $tenant): array => [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'billing_email' => $tenant->billing_email,
                    'subscription' => $tenant->subscription === null ? null : [
                        'id' => $tenant->subscription->id,
                        'status' => $tenant->subscription->status,
                        'plan_key' => $tenant->subscription->plan->key,
                        'plan_name' => $tenant->subscription->plan->name,
                    ],
                ]),
            'plans' => Plan::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'key', 'name']),
            'openInvoices' => BillingInvoice::query()
                ->where('status', BillingInvoice::STATUS_ISSUED)
                ->orderBy('due_at')
                ->orderBy('id')
                ->get(['id', 'tenant_id', 'number', 'currency', 'total_minor', 'due_at']),
        ]);
    }

    public function assignPlan(Request $request, Tenant $tenant): RedirectResponse
    {
        $this->ensurePlatformOwner($request);

        $request->validate([
            'plan_id' => ['required', 'integer', Rule::exists('plans', 'id')->where('is_active', true)],
        ]);
        $planId = $request->integer('plan_id');

        $subscription = Subscription::query()->where('tenant_id', $tenant->id)->first();

        if ($subscription === null) {
            Subscription::create([
                'tenant_id' => $tenant->id,
                'plan_id' => $planId,
                'status' => Subscription::STATUS_TRIALING,
                'trial_ends_at' => now()->addDays(config()->integer('billing.trial_days')),
            ]);
        } else {
            $subscription->update(['plan_id' => $planId]);
        }

        return back()->with('success', 'Plan updated.');
    }

    public function storeOverride(Request $request, Tenant $tenant): RedirectResponse
    {
        $this->ensurePlatformOwner($request);

        $request->validate([
            'entitlement_key' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_.]+$/'],
            'value_type' => ['required', Rule::in(['boolean', 'integer_limit'])],
            'value' => ['required', 'string', 'max:20'],
            'reason' => ['required', 'string', 'max:255'],
            'expires_at' => ['required', 'date', 'after:now'],
        ]);

        TenantEntitlementOverride::create([
            'tenant_id' => $tenant->id,
            'entitlement_key' => $request->string('entitlement_key')->value(),
            'value_type' => $request->string('value_type')->value(),
            'value' => $request->string('value')->value(),
            'reason' => $request->string('reason')->value(),
            'expires_at' => $request->date('expires_at'),
            'created_by' => $request->user()?->id,
        ]);

        return back()->with('success', 'Override added.');
    }

    public function recordPayment(Request $request, BillingInvoice $invoice, BillingService $billing): RedirectResponse
    {
        $this->ensurePlatformOwner($request);

        $request->validate([
            'payment_reference' => ['required', 'string', 'max:100'],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'currency' => ['required', 'string', 'size:3'],
        ]);

        try {
            $billing->reconcilePayment(
                $invoice,
                $request->string('payment_reference')->value(),
                $request->integer('amount_minor'),
                $request->string('currency')->value(),
            );
        } catch (DomainException $exception) {
            return back()->withErrors(['payment' => $exception->getMessage()]);
        }

        return back()->with('success', 'Payment recorded.');
    }

    private function ensurePlatformOwner(Request $request): void
    {
        abort_unless($request->user()?->is_global_admin === true, 403);
    }
}
