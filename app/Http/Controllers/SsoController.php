<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\SsoService;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SsoController extends Controller
{
    public function __construct(
        private readonly SsoService $sso,
        private readonly StatefulGuard $auth,
    ) {}

    public function authorize(string $provider, Request $request): RedirectResponse
    {
        $providers = $this->sso->providers();

        if (! array_key_exists($provider, $providers)) {
            abort(404, 'Unknown identity provider.');
        }

        if ($providers[$provider]['client_id'] === '') {
            abort(404, 'The identity provider is not configured.');
        }

        $state = bin2hex(random_bytes(16));
        $nonce = bin2hex(random_bytes(16));

        $request->session()->put('sso_state', $state);
        $request->session()->put('sso_nonce', $nonce);

        return redirect()->away($this->sso->authorizationUrl($provider, $this->redirectUri($request), $state, $nonce));
    }

    public function callback(string $provider, Request $request): RedirectResponse
    {
        $providers = $this->sso->providers();

        if (! array_key_exists($provider, $providers)) {
            abort(404, 'Unknown identity provider.');
        }

        $state = (string) $request->session()->get('sso_state');
        $storedNonce = (string) $request->session()->get('sso_nonce');

        if ($state === '' || ! hash_equals($state, (string) $request->input('state'))) {
            abort(403, 'The SSO state check failed.');
        }

        if ($storedNonce === '' || ! hash_equals($storedNonce, (string) $request->input('nonce'))) {
            abort(403, 'The SSO nonce check failed.');
        }

        $request->session()->forget(['sso_state', 'sso_nonce']);

        $code = (string) $request->input('code');

        if ($code === '') {
            abort(400, 'The SSO callback is missing an authorization code.');
        }

        $token = $this->sso->exchangeCode($provider, $this->redirectUri($request), $code);
        $profile = $this->sso->userInfo($provider, $token);

        $subject = (string) ($profile['sub'] ?? '');
        $email = strtolower((string) ($profile['email'] ?? ''));

        if ($subject === '' || $email === '') {
            abort(400, 'The identity provider did not return a subject or email.');
        }

        $issuer = $providers[$provider]['issuer'];

        $user = User::query()
            ->where('sso_issuer', $issuer)
            ->where('sso_subject', $subject)
            ->first();

        if ($user === null) {
            $user = User::create([
                'name' => (string) ($profile['name'] ?? $email),
                'email' => $email,
                'password' => str()->random(32),
                'email_verified_at' => now(),
                'sso_issuer' => $issuer,
                'sso_subject' => $subject,
            ]);
        }

        $this->auth->login($user);

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        return redirect()->to($this->redirectAfterLogin());
    }

    private function redirectUri(Request $request): string
    {
        $host = $request->getHost();
        $scheme = $request->isSecure() ? 'https' : 'http';

        return $scheme.'://'.$host.'/sso/'.$request->route('provider').'/callback';
    }

    private function redirectAfterLogin(): string
    {
        return (string) config('fortify.home');
    }
}
