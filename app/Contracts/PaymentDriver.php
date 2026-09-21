<?php

namespace App\Contracts;

/**
 * Payment gateway driver. All money is integer minor units. Implementations
 * perform transport only; state machines live in PaymentService.
 */
interface PaymentDriver
{
    public function name(): string;

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{authorization_url: string, access_code: string, reference: string}
     */
    public function initialize(int $amountMinor, string $currency, string $email, array $metadata, ?string $callbackUrl = null): array;

    /**
     * @return array<string, mixed>
     */
    public function verify(string $reference): array;

    /**
     * Hold funds without capturing (card pre-auth for incidentals).
     *
     * @param  array<string, mixed>  $metadata
     * @return array{reference: string, authorization_code: string}
     */
    public function preauthorize(int $amountMinor, string $currency, string $token, array $metadata): array;

    /**
     * @return array{reference: string, captured_minor: int}
     */
    public function capture(string $authorizationCode, int $amountMinor): array;

    /**
     * @return array{reference: string, refunded_minor: int}
     */
    public function refund(string $gatewayReference, int $amountMinor): array;

    public function void(string $gatewayReference): bool;
}
