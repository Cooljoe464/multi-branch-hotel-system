<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\FxRate;
use App\Services\FxService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class FxRateController extends Controller
{
    public function store(Request $request, FxService $fx): RedirectResponse
    {
        $request->validate([
            'base_code' => 'required|string|size:3',
            'quote_code' => 'required|string|size:3|different:base_code',
            'rate_date' => 'required|date_format:Y-m-d',
            'rate' => 'required|numeric|min:0.000001',
            'source' => 'nullable|string|max:32',
        ]);

        $base = strtoupper($request->string('base_code')->value());
        $quote = strtoupper($request->string('quote_code')->value());
        $rateInput = $request->input('rate');
        $rate = is_numeric($rateInput) ? (float) $rateInput : 0.0;

        $fx->setRate(
            $base,
            $quote,
            $request->string('rate_date')->value(),
            (int) round($rate * FxService::MICROS),
            $request->string('source')->value() !== '' ? $request->string('source')->value() : 'manual',
        );

        return $this->flashSuccess("Rate {$base}/{$quote} saved.");
    }

    public function destroy(FxRate $fxRate): RedirectResponse
    {
        $fxRate->delete();

        return $this->flashSuccess('Rate deleted.');
    }
}
