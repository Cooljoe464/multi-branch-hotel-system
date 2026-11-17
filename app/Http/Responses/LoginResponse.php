<?php

namespace App\Http\Responses;

use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

class LoginResponse implements LoginResponseContract
{
    /**
     * @param  Request  $request
     * @return Response
     */
    public function toResponse($request)
    {
        if ($request->wantsJson()) {
            return response()->json(['two_factor' => false]);
        }

        return redirect()->intended($this->redirectPath($request));
    }

    /**
     * Platform owners are cross-tenant accounts with no branch of their own,
     * so they land on the commercial control plane instead of the branch
     * dashboard, which requires a current branch.
     *
     * @param  Request  $request
     */
    private function redirectPath($request): string
    {
        $user = $request->user();

        if ($user instanceof User && $user->is_global_admin && $user->branch_id === null) {
            return route('platform.subscriptions.index', absolute: false);
        }

        return Fortify::redirects('login');
    }
}
