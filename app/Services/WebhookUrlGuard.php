<?php

namespace App\Services;

use App\Exceptions\UnsafeWebhookUrl;
use App\Support\Network\DnsResolver;

/**
 * Destination safety for outbound webhooks (spec 4.3): HTTPS only, and no
 * address that is private, loopback, link-local, reserved, multicast or
 * carrier-grade NAT. Every address a host resolves to must be public, and the
 * check is repeated at delivery time because DNS answers change.
 */
class WebhookUrlGuard
{
    /**
     * IPv4 ranges PHP's reserved-range filter does not cover.
     *
     * @var list<string>
     */
    private const BLOCKED_IPV4 = [
        '0.0.0.0/8',
        '100.64.0.0/10',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
    ];

    public function __construct(private DnsResolver $resolver) {}

    /**
     * @return array{host: string, port: int, addresses: list<string>}
     *
     * @throws UnsafeWebhookUrl
     */
    public function resolve(string $url): array
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new UnsafeWebhookUrl('The webhook URL is not valid.');
        }

        if (strtolower($parts['scheme']) !== 'https') {
            throw new UnsafeWebhookUrl('Webhook URLs must use HTTPS.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new UnsafeWebhookUrl('Webhook URLs must not contain credentials.');
        }

        $host = trim($parts['host'], '[]');
        $addresses = $this->resolver->resolve($host);

        if ($addresses === []) {
            throw new UnsafeWebhookUrl("The webhook host [{$host}] does not resolve.");
        }

        foreach ($addresses as $address) {
            if (! $this->isPublic($address)) {
                throw new UnsafeWebhookUrl("The webhook host [{$host}] resolves to a non-public address.");
            }
        }

        return ['host' => $host, 'port' => $parts['port'] ?? 443, 'addresses' => $addresses];
    }

    public function isPublic(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $embedded = $this->embeddedIpv4($address);

        if ($embedded !== null) {
            return $this->isPublic($embedded);
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            foreach (self::BLOCKED_IPV4 as $cidr) {
                if ($this->ipv4InCidr($address, $cidr)) {
                    return false;
                }
            }

            return true;
        }

        $packed = inet_pton($address);

        return $packed !== false && ord($packed[0]) !== 0xFF;
    }

    /**
     * The IPv4 address hidden inside an IPv4-mapped (::ffff:a.b.c.d) or
     * NAT64 (64:ff9b::a.b.c.d) IPv6 address, so those cannot smuggle a
     * private target past the IPv6 checks.
     */
    private function embeddedIpv4(string $address): ?string
    {
        $packed = inet_pton($address);

        if ($packed === false || strlen($packed) !== 16) {
            return null;
        }

        $mappedPrefix = str_repeat("\0", 10)."\xff\xff";
        $nat64Prefix = hex2bin('0064ff9b').str_repeat("\0", 8);

        if (str_starts_with($packed, $mappedPrefix) || str_starts_with($packed, $nat64Prefix)) {
            $ipv4 = inet_ntop(substr($packed, 12, 4));

            return $ipv4 === false ? null : $ipv4;
        }

        return null;
    }

    private function ipv4InCidr(string $address, string $cidr): bool
    {
        [$network, $bits] = explode('/', $cidr);
        $mask = -1 << (32 - (int) $bits);

        return (ip2long($address) & $mask) === (ip2long($network) & $mask);
    }
}
