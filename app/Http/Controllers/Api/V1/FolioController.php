<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\FolioResource;
use App\Models\Branch;
use App\Models\Folio;
use Illuminate\Http\Request;

class FolioController extends Controller
{
    public function show(Request $request, int $id): FolioResource
    {
        /** @var Branch $branch */
        $branch = $request->attributes->get('branch');

        $folio = Folio::with('transactions')->findOrFail($id);
        abort_unless($folio->branch_id === $branch->id, 404);

        return new FolioResource($folio);
    }
}
