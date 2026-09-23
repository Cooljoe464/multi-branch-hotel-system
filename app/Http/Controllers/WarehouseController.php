<?php

namespace App\Http\Controllers;

use App\Jobs\WarehouseExportJob;
use App\Models\WarehouseManifest;
use App\Services\WarehouseExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WarehouseController extends Controller
{
    public function index(): Response
    {
        $manifests = WarehouseManifest::latest('business_date')->limit(90)->get()->map(fn (WarehouseManifest $m) => [
            'id' => $m->id,
            'business_date' => $m->business_date->toDateString(),
            'files' => $m->files ?? [],
            'row_counts' => $m->row_counts ?? [],
            'status' => $m->status,
            'last_error' => $m->last_error,
            'schema_version' => WarehouseExporter::SCHEMA_VERSION,
        ])->all();

        return Inertia::render('analytics/Warehouse', ['manifests' => $manifests]);
    }

    public function export(Request $request): RedirectResponse
    {
        $request->validate(['business_date' => 'required|date_format:Y-m-d']);

        WarehouseExportJob::dispatch($request->string('business_date')->value());

        return redirect()->route('warehouse.index')
            ->with('toast', ['type' => 'success', 'message' => 'Warehouse export queued.']);
    }

    public function verify(WarehouseManifest $manifest): RedirectResponse
    {
        $ok = (new WarehouseExporter)->verify($manifest);

        return redirect()->route('warehouse.index')
            ->with('toast', ['type' => $ok ? 'success' : 'error', 'message' => $ok ? "Manifest #{$manifest->id} verified." : "Manifest #{$manifest->id} FAILED verification."]);
    }

    public function manifests(): JsonResponse
    {
        $manifests = WarehouseManifest::latest('business_date')->limit(90)->get()->map(fn (WarehouseManifest $m) => [
            'business_date' => $m->business_date->toDateString(),
            'files' => $m->files ?? [],
            'row_counts' => $m->row_counts ?? [],
            'checksums' => $m->checksums ?? [],
            'status' => $m->status,
            'schema_version' => WarehouseExporter::SCHEMA_VERSION,
        ])->all();

        return response()->json(['data' => $manifests]);
    }
}
