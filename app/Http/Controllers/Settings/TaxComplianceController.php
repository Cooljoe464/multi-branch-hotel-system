<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\Fiscal\AfriInvoiceProvider;
use App\Services\Fiscal\FirsEmitter;
use App\Services\Security\SecretManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TaxComplianceController extends Controller
{
    public function __construct(
        private SecretManager $secrets,
        private AfriInvoiceProvider $afriInvoice,
        private FirsEmitter $firs,
    ) {}

    public function edit(Request $request): Response
    {
        /** @var Branch $branch */
        $branch = $request->user()->branch ?? Branch::query()->firstOrFail();

        $afriCaps = $this->afriInvoice->capabilities($branch);
        $firsCaps = $this->firs->capabilities($branch);

        $hasAfriKey = $this->afriInvoice->apiKey($branch) !== null;
        $afriServiceId = $this->afriInvoice->serviceId($branch);
        $afriEnv = $this->afriInvoice->environment($branch);

        $hasFirsKey = $this->secrets->get($branch, FirsEmitter::SECRET_API_KEY) !== null
            || config('services.firs.api_key') !== null;

        $preferredProvider = $branch->settings['fiscal_provider'] ?? ($hasAfriKey ? 'afri_invoice' : 'firs');

        return Inertia::render('settings/TaxCompliance', [
            'preferred_provider' => $preferredProvider,
            'afri_invoice' => [
                'has_api_key' => $hasAfriKey,
                'service_id' => $afriServiceId ?? '',
                'environment' => $afriEnv,
                'is_ready' => $afriCaps['submit'],
            ],
            'firs' => [
                'has_api_key' => $hasFirsKey,
                'is_ready' => $firsCaps['submit'],
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        /** @var Branch $branch */
        $branch = $request->user()->branch ?? Branch::query()->firstOrFail();

        $validated = $request->validate([
            'preferred_provider' => 'required|string|in:afri_invoice,firs',
            'afri_invoice_api_key' => 'nullable|string|max:255',
            'afri_invoice_service_id' => 'nullable|string|max:128',
            'afri_invoice_environment' => 'nullable|string|in:sandbox,live',
        ]);

        if (! empty($validated['afri_invoice_api_key'])) {
            $this->secrets->set($branch, AfriInvoiceProvider::SECRET_API_KEY, $validated['afri_invoice_api_key']);
        }

        if (isset($validated['afri_invoice_service_id'])) {
            $this->secrets->set($branch, AfriInvoiceProvider::SECRET_SERVICE_ID, $validated['afri_invoice_service_id']);
        }

        if (isset($validated['afri_invoice_environment'])) {
            $this->secrets->set($branch, AfriInvoiceProvider::SECRET_ENVIRONMENT, $validated['afri_invoice_environment']);
        }

        $settings = $branch->settings ?? [];
        $settings['fiscal_provider'] = $validated['preferred_provider'];
        $branch->settings = $settings;
        $branch->save();

        return back()->with('status', 'Tax & NRS compliance settings updated successfully.');
    }

    public function test(Request $request): JsonResponse
    {
        /** @var Branch $branch */
        $branch = $request->user()->branch ?? Branch::query()->firstOrFail();

        $apiKey = $request->input('api_key') ?: $this->afriInvoice->apiKey($branch);
        $serviceId = $request->input('service_id') ?: $this->afriInvoice->serviceId($branch);
        $env = $request->input('environment') ?: $this->afriInvoice->environment($branch);

        $result = $this->afriInvoice->testConnection($branch, $apiKey, $serviceId, $env);

        return response()->json($result);
    }
}
