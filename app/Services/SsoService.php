<?php

namespace App\Services;

use DomainException;
use Illuminate\Support\Facades\Http;

/**
 * Generic OpenID Connect client for the enterprise identity providers
 * (Google Workspace, Microsoft Entra ID, Okta). Talks the standard
 * authorization / token / userinfo endpoints published in the provider's
 * discovery document; no third-party SDK is required.
 */
class SsoService
{
    /**
     * @return array<string, array{issuer: string, client_id: string, client_secret: string, tenant?: string}>
     */
    public function providers(): array
    {
        /** @var array<string, array<string, mixed>> $configured */
        $configured = config('sso.providers', []);

        $providers = [];

        foreach ($configured as $key => $provider) {
            if (! is_array($provider)) {
                continue;
            }

            $providers[(string) $key] = [
                'issuer' => (string) ($provider['issuer'] ?? ''),
                'client_id' => (string) ($provider['client_id'] ?? ''),
                'client_secret' => (string) ($provider['client_secret'] ?? ''),
                'tenant' => isset($provider['tenant']) && is_string($provider['tenant']) && $provider['tenant'] !== ''
                    ? $provider['tenant']
                    : null,
            ];
        }

        return $providers;
    }

    public function issuer(string $provider): string
    {
        $providerConfig = $this->providers()[$provider] ?? null;

        if ($providerConfig === null) {
            throw new DomainException('Unknown identity provider.');
        }

        if ($providerConfig['issuer'] === '') {
            throw new DomainException('The identity provider issuer is not configured.');
        }

        return $providerConfig['issuer'];
    }

    public function clientId(string $provider): string
    {
        $providerConfig = $this->providers()[$provider] ?? null;

        if ($providerConfig === null) {
            throw new DomainException('Unknown identity provider.');
        }

        return $providerConfig['client_id'];
    }

    public function clientSecret(string $provider): string
    {
        $providerConfig = $this->providers()[$provider] ?? null;

        if ($providerConfig === null) {
            throw new DomainException('Unknown identity provider.');
        }

        return $providerConfig['client_secret'];
    }

    public function tenant(string $provider): ?string
    {
        return $this->providers()[$provider]['tenant'] ?? null;
    }

    /**
     * Fetch and validate the OpenID Connect discovery document.
     *
     * @return array<string, mixed>
     */
    public function issuerDocument(string $issuer): array
    {
        $response = Http::timeout(10)->acceptJson()->get($issuer.'/.well-known/openid-configuration');

        if (! $response->successful()) {
            throw new DomainException('Unable to resolve the identity provider discovery document.');
        }

        $document = $response->json();

        if (! is_array($document) || ! isset($document['authorization_endpoint'], $document['token_endpoint'], $document['userinfo_endpoint'])) {
            throw new DomainException('The identity provider discovery document is incomplete.');
        }

        return $document;
    }

    public function authorizationUrl(string $provider, string $redirectUri, string $state, string $nonce): string
    {
        $configuration = $this->issuerDocument($this->issuer($provider));
        $endpoint = (string) $configuration['authorization_endpoint'];

        $query = [
            'response_type' => 'code',
            'client_id' => $this->clientId($provider),
            'redirect_uri' => $redirectUri,
            'scope' => 'openid email profile',
            'state' => $state,
            'nonce' => $nonce,
        ];

        $tenant = $this->tenant($provider);

        if ($tenant !== null) {
            $query['tenant'] = $tenant;
        }

        return $endpoint.'?'.http_build_query($query);
    }

    /**
     * @return array<string, mixed>
     */
    public function exchangeCode(string $provider, string $redirectUri, string $code): array
    {
        $configuration = $this->issuerDocument($this->issuer($provider));
        $tokenEndpoint = (string) $configuration['token_endpoint'];

        $response = Http::timeout(10)
            ->acceptJson()
            ->asForm()
            ->post($tokenEndpoint, [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $redirectUri,
                'client_id' => $this->clientId($provider),
                'client_secret' => $this->clientSecret($provider),
            ]);

        if (! $response->successful()) {
            throw new DomainException('The identity provider token exchange failed.');
        }

        $token = $response->json();

        if (! is_array($token) || empty($token['access_token'])) {
            throw new DomainException('The identity provider returned no access token.');
        }

        return $token;
    }

    /**
     * @param  array<string, mixed>  $token
     * @return array<string, mixed>
     */
    public function userInfo(string $provider, array $token): array
    {
        $configuration = $this->issuerDocument($this->issuer($provider));
        $endpoint = (string) $configuration['userinfo_endpoint'];

        $response = Http::withToken((string) $token['access_token'])->acceptJson()->get($endpoint);

        if (! $response->successful()) {
            throw new DomainException('The identity provider userinfo call failed.');
        }

        $user = $response->json();

        if (! is_array($user)) {
            throw new DomainException('The identity provider returned no user profile.');
        }

        return $user;
    }
}
