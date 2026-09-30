<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Branding;
use App\Models\FxRate;
use App\Services\FxService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CurrencyController extends Controller
{
    public function edit(): Response
    {
        $branding = Branding::instance();

        $branches = Branch::query()
            ->orderBy('name')
            ->get()
            ->map(fn (Branch $branch) => [
                'id' => $branch->id,
                'name' => $branch->name,
                'currency_code' => $branch->currency_code,
                'currency_symbol' => $branch->currency_symbol,
            ]);

        return Inertia::render('settings/Currency', [
            'globalCurrency' => [
                'currency_code' => $branding->currency_code,
                'currency_symbol' => $branding->currency_symbol,
            ],
            'branches' => $branches,
            'rates' => FxRate::orderByDesc('rate_date')->limit(50)->get([
                'id', 'base_code', 'quote_code', 'rate_date', 'rate_micro', 'source',
            ])->map(fn (FxRate $r) => [
                'id' => $r->id,
                'base_code' => $r->base_code,
                'quote_code' => $r->quote_code,
                'rate_date' => $r->rate_date->toDateString(),
                'rate' => $r->rate_micro / FxService::MICROS,
                'source' => $r->source,
            ])->all(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'currency_code' => 'required|string|size:3',
            'currency_symbol' => 'required|string|max:5',
            'branch_currencies' => 'nullable|array',
            'branch_currencies.*.id' => 'required|exists:branches,id',
            'branch_currencies.*.currency_code' => 'required|string|size:3',
            'branch_currencies.*.currency_symbol' => 'required|string|max:5',
        ]);

        $branding = Branding::instance();
        $branding->update([
            'currency_code' => $request->string('currency_code')->value(),
            'currency_symbol' => $request->string('currency_symbol')->value(),
        ]);

        if ($request->filled('branch_currencies')) {
            foreach ($request->array('branch_currencies') as $branchCurrency) {
                if (! is_array($branchCurrency)) {
                    continue;
                }

                $id = $branchCurrency['id'] ?? null;
                $code = $branchCurrency['currency_code'] ?? null;
                $symbol = $branchCurrency['currency_symbol'] ?? null;

                if (! is_int($id) || ! is_string($code) || ! is_string($symbol)) {
                    continue;
                }

                Branch::where('id', $id)->update([
                    'currency_code' => $code,
                    'currency_symbol' => $symbol,
                ]);
            }
        }

        return $this->flashSuccess('Currency settings updated successfully.');
    }
}
