<?php

namespace App\Http\Controllers\Scim;

use App\Http\Controllers\Controller;
use App\Models\ApiConsumer;
use App\Models\Branch;
use App\Models\User;
use App\Services\Scim\ScimService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class ScimUserController extends Controller
{
    public function __construct(private readonly ScimService $scim) {}

    public function index(Request $request): JsonResponse
    {
        $consumer = $this->consumer($request);

        $branchIds = $consumer->reachableBranchIds();
        $branch = Branch::query()->find($branchIds[0] ?? 0);

        $users = $this->scim->listForTenant($branch?->tenant_id ?? 0);

        return response()->json([
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:error'],
            'totalResults' => $users->count(),
            'itemsPerPage' => $users->count(),
            'startIndex' => 1,
            'resources' => $users->map(fn (User $user): array => $this->scim->toResource($user))->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $attributes = $request->validate([
            'schemas' => ['nullable', 'array'],
            'id' => ['required', 'string'],
            'userName' => ['required', 'string', 'email'],
            'name' => ['nullable', 'array'],
            'name.givenName' => ['nullable', 'string', 'max:128'],
            'name.familyName' => ['nullable', 'string', 'max:128'],
            'active' => ['nullable', 'boolean'],
        ]);

        $user = $this->scim->create(
            (string) $attributes['id'],
            (string) $attributes['userName'],
            $attributes['name'] ?? [],
            (bool) ($attributes['active'] ?? true),
        );

        return response()->json($this->scim->toResource($user), 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $attributes = $request->validate([
            'schemas' => ['nullable', 'array'],
            'userName' => ['required', 'string', 'email'],
            'name' => ['nullable', 'array'],
            'name.givenName' => ['nullable', 'string', 'max:128'],
            'name.familyName' => ['nullable', 'string', 'max:128'],
            'active' => ['nullable', 'boolean'],
        ]);

        $updated = $this->scim->update(
            $user,
            (string) $user->sso_subject,
            (string) $attributes['userName'],
            $attributes['name'] ?? [],
            (bool) ($attributes['active'] ?? true),
        );

        return response()->json($this->scim->toResource($updated));
    }

    public function show(Request $request, User $user): JsonResponse
    {
        return response()->json($this->scim->toResource($user));
    }

    public function patch(Request $request, User $user): JsonResponse
    {
        $attributes = $request->validate([
            'schemas' => ['nullable', 'array'],
            'userName' => ['sometimes', 'required', 'string', 'email'],
            'name' => ['nullable', 'array'],
            'name.givenName' => ['nullable', 'string', 'max:128'],
            'name.familyName' => ['nullable', 'string', 'max:128'],
            'active' => ['nullable', 'boolean'],
        ]);

        $updated = $this->scim->update(
            $user,
            (string) $user->sso_subject,
            (string) ($attributes['userName'] ?? $user->email),
            $attributes['name'] ?? [],
            (bool) ($attributes['active'] ?? true),
        );

        return response()->json($this->scim->toResource($updated));
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->scim->revoke($user);

        return response()->json(['meta' => ['resourceType' => 'User']]);
    }

    private function consumer(Request $request): ApiConsumer
    {
        $accessToken = PersonalAccessToken::findToken($request->bearerToken());

        $consumer = $accessToken?->tokenable;

        if (! $consumer instanceof ApiConsumer) {
            abort(401, 'Invalid API token.');
        }

        return $consumer;
    }
}
