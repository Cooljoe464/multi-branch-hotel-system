<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mandatory MFA gate for sensitive operations (execution plan §1.8).
 *
 * Authenticated users holding a sensitive permission must have a
 * confirmed two-factor factor (TOTP) or at least one registered
 * WebAuthn passkey before the protected operation may proceed.
 */
class EnforceMfa
{
    /**
     * Permissions that require a confirmed MFA factor.
     *
     * @var array<int, string>
     */
    protected const SENSITIVE_PERMISSIONS = [
        'availability.manage_overbook',
        'cashier.approve_void',
        'security.audit',
        'tenant.billing',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        if (! $user->hasAnyPermission(...self::SENSITIVE_PERMISSIONS) || $this->mfaConfirmed($user)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'MFA_REQUIRED'], Response::HTTP_FORBIDDEN);
        }

        return redirect()->route('security.edit');
    }

    private function mfaConfirmed(User $user): bool
    {
        return $user->hasEnabledTwoFactorAuthentication() || $user->hasPasskeysEnabled();
    }
}
