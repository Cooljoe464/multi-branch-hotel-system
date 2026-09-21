<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnsuresBranchAccess;
use App\Models\Branch;
use App\Models\IdempotencyKey;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class IdempotencyController extends Controller
{
    use EnsuresBranchAccess;

    public function index(Request $request, Branch $branch): Response
    {
        $this->ensureBranchAccess($branch);

        $keys = IdempotencyKey::forBranch($branch->id)
            ->orderByDesc('updated_at')
            ->limit(50)
            ->get(['id', 'scope', 'key', 'status', 'updated_at']);

        return Inertia::render('admin/IdempotencyKeys', [
            'branch' => $branch->only(['id', 'name', 'code']),
            'keys' => $keys,
        ]);
    }
}
