<?php

namespace App\Http\Controllers;

use App\Models\ApiConsumer;
use App\Models\Branch;
use App\Models\WebhookDelivery;
use App\Services\WebhookDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DevelopersController extends Controller
{
    /**
     * @var list<string>
     */
    public const AVAILABLE_SCOPES = [
        'availability.view',
        'reservations.view',
        'reservations.create',
        'reservations.cancel',
        'folios.view',
        'rate_plans.view',
        'analytics.view',
        'analytics.export_warehouse',
        'webhooks.replay',
    ];

    public function index(): Response
    {
        $consumers = ApiConsumer::with('branch')->orderBy('id')->get()->map(fn (ApiConsumer $c) => [
            'id' => $c->id,
            'name' => $c->name,
            'branch_id' => $c->branch_id,
            'branch_name' => $c->branch?->name,
            'branch_ids' => $c->branch_ids ?? [],
            'scopes' => $c->scopes ?? [],
            'webhook_url' => $c->webhook_url,
            'has_webhook_secret' => $c->webhook_secret !== null,
            'grace_active' => $c->webhook_grace_until !== null && $c->webhook_grace_until->isFuture(),
            'is_active' => $c->is_active,
            'tokens_count' => $c->tokens()->count(),
        ])->all();

        $deliveries = WebhookDelivery::with('consumer')->latest('id')->limit(50)->get()->map(fn (WebhookDelivery $d) => [
            'id' => $d->id,
            'consumer_name' => $d->consumer->name,
            'event' => $d->event,
            'status' => $d->status,
            'attempts' => $d->attempts,
            'last_error' => $d->last_error,
            'created_at' => $d->created_at?->toIso8601String(),
        ])->all();

        return Inertia::render('developers/Consumers', [
            'consumers' => $consumers,
            'deliveries' => $deliveries,
            'scopes' => self::AVAILABLE_SCOPES,
            'branches' => Branch::where('is_active', true)->orderBy('id')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => 'required|string|max:128',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'branch_ids' => 'nullable|array',
            'branch_ids.*' => 'integer|exists:branches,id',
            'scopes' => 'required|array|min:1',
            'scopes.*' => 'string|in:'.implode(',', self::AVAILABLE_SCOPES),
            'webhook_url' => 'nullable|url|max:256',
        ]);

        $branchId = $request->integer('branch_id');
        $branchIds = array_values(array_filter($request->array('branch_ids'), is_int(...)));

        if ($branchId > 0 && $branchIds !== []) {
            return back()->withErrors(['branch_id' => 'Set a single property or a property list, not both.']);
        }

        $scopes = array_values(array_filter($request->array('scopes'), is_string(...)));
        $webhookUrl = $request->string('webhook_url')->value();

        $consumer = ApiConsumer::create([
            'name' => $request->string('name')->value(),
            'branch_id' => $branchId > 0 ? $branchId : null,
            'branch_ids' => $branchId > 0 ? null : $branchIds,
            'scopes' => $scopes,
            'webhook_url' => $webhookUrl !== '' ? $webhookUrl : null,
        ]);

        $token = $consumer->createToken('default', $consumer->tokenAbilities(), now()->addYear())->plainTextToken;

        return redirect()->route('developers.index')
            ->with('token', $token)
            ->with('toast', ['type' => 'success', 'message' => "Consumer {$consumer->name} created. Copy the token now — it is shown once."]);
    }

    public function issueToken(Request $request, ApiConsumer $consumer): RedirectResponse
    {
        $token = $consumer->createToken('default', $consumer->tokenAbilities(), now()->addYear())->plainTextToken;

        return redirect()->route('developers.index')
            ->with('token', $token)
            ->with('toast', ['type' => 'success', 'message' => "New token issued for {$consumer->name}. Copy it now — it is shown once."]);
    }

    public function rotate(Request $request, ApiConsumer $consumer, WebhookDispatcher $dispatcher): RedirectResponse
    {
        if ($consumer->webhook_secret === null && ! $request->filled('webhook_secret')) {
            return back()->withErrors(['webhook_secret' => 'Set an initial secret first.']);
        }

        if ($request->filled('webhook_secret')) {
            $request->validate(['webhook_secret' => 'string|min:32|max:128']);
            $consumer->update([
                'webhook_previous_secret' => $consumer->webhook_secret,
                'webhook_secret' => $request->string('webhook_secret')->value(),
                'webhook_grace_until' => now()->addHours(WebhookDispatcher::GRACE_HOURS),
            ]);
        } else {
            $dispatcher->rotateSecret($consumer);
        }

        return redirect()->route('developers.index')
            ->with('toast', ['type' => 'success', 'message' => "Webhook secret rotated for {$consumer->name}. The previous secret stays valid for 24h."]);
    }

    public function replay(Request $request, WebhookDelivery $delivery, WebhookDispatcher $dispatcher): RedirectResponse
    {
        $dispatcher->replay($delivery);

        return redirect()->route('developers.index')
            ->with('toast', ['type' => 'success', 'message' => "Delivery #{$delivery->id} requeued."]);
    }

    public function toggle(ApiConsumer $consumer): RedirectResponse
    {
        $consumer->update(['is_active' => ! $consumer->is_active]);

        return redirect()->route('developers.index')
            ->with('toast', ['type' => 'success', 'message' => "Consumer {$consumer->name} ".($consumer->is_active ? 'activated' : 'deactivated').'.']);
    }
}
