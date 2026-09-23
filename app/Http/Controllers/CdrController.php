<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Services\PbxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CdrController extends Controller
{
    public function store(Request $request, Branch $branch, PbxService $pbx): JsonResponse
    {
        $request->validate([
            'cdr_id' => 'required|string|max:64',
            'extension' => 'required|string|max:16',
            'destination' => 'required|string|max:32',
            'duration_secs' => 'required|integer|min:0',
            'reservation_id' => 'nullable|integer',
        ]);

        $signature = $request->header('X-Cdr-Signature');

        if (! is_string($signature) || $signature === '') {
            abort(401, 'Missing CDR signature.');
        }

        $record = $pbx->ingest($branch, [
            'cdr_id' => $request->string('cdr_id')->value(),
            'extension' => $request->string('extension')->value(),
            'destination' => $request->string('destination')->value(),
            'duration_secs' => $request->integer('duration_secs'),
            'reservation_id' => $request->integer('reservation_id') > 0 ? $request->integer('reservation_id') : null,
        ], $signature, (string) $request->getContent());

        return response()->json([
            'data' => [
                'id' => $record->id,
                'cdr_id' => $record->cdr_id,
                'charge_minor' => $record->charge_minor,
            ],
        ], 201);
    }
}
