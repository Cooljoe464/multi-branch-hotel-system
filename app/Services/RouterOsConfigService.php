<?php

namespace App\Services;

use App\Models\Branch;

/**
 * Generates a per-branch RouterOS export (.rsc) enforcing isolation:
 * staff PSK on VLAN 10, guest Hotspot on VLAN 30, WireGuard to cloud,
 * RADIUS over the tunnel, and guest->RFC1918 drops.
 *
 * Single router per branch (no CAPsMAN in V1).
 */
class RouterOsConfigService
{
    /**
     * @param  array<string, mixed>  $overrides  Merged over branch settings for previews.
     */
    public function export(Branch $branch, array $overrides = []): string
    {
        $settings = is_array($branch->settings) ? $branch->settings : [];
        $settings = array_merge($settings, $overrides);

        $staffVlan = $this->settingInt($settings, 'staff_vlan_id', 'hotspot.staff_vlan_id', 10);
        $guestVlan = $this->settingInt($settings, 'guest_vlan_id', 'hotspot.guest_vlan_id', 30);
        $staffSubnet = $this->settingString($settings, 'staff_subnet', 'hotspot.staff_subnet', '10.10.0.0/24');
        $guestSubnet = $this->settingString($settings, 'guest_subnet', 'hotspot.guest_subnet', '10.30.0.0/21');
        $staffSsid = $this->escape($this->settingString($settings, 'staff_ssid', 'hotspot.staff_ssid', 'STAFF'));
        $guestSsid = $this->escape($this->settingString($settings, 'guest_ssid', 'hotspot.guest_ssid', 'GUEST-WIFI'));
        $staffPsk = $this->escape($this->settingString($settings, 'staff_psk', 'hotspot.staff_psk', 'CHANGE-ME-STAFF-PSK'));
        $nasSecret = $this->escape($this->settingString($settings, 'nas_secret', 'hotspot.nas_secret', $branch->cdr_secret ?? 'CHANGE-ME-NAS-SECRET'));
        $radiusHost = $this->escape($this->settingString($settings, 'radius_host', 'radius.host', '100.64.0.1'));
        $portalDomain = $this->settingString($settings, 'portal_domain', 'hotspot.portal_domain', 'guest.hotels.example');
        $portalDefault = 'https://'.$portalDomain."/guest/wifi/{$branch->id}";
        $portalUrl = $settings['portal_url'] ?? $portalDefault;
        $portal = $this->escape(is_string($portalUrl) ? $portalUrl : $portalDefault);
        $wgPriv = $this->escape($this->settingString($settings, 'wireguard_private_key', 'hotspot.wireguard_private_key', 'GENERATE-WITH-wg-genkey'));
        $wgPeer = $this->escape($this->settingString($settings, 'wireguard_peer_public_key', 'hotspot.wireguard_peer_public_key', 'CLOUD-PEER-PUBKEY'));
        $wgEndpoint = $this->escape($this->settingString($settings, 'wireguard_endpoint', 'hotspot.wireguard_endpoint', 'vpn.example.com:51820'));
        $wgAddr = $this->escape($this->settingString($settings, 'tunnel_ip', 'hotspot.tunnel_ip', '100.64.0.2/30'));
        $portalDomainEscaped = $this->escape($portalDomain);
        $nasName = $this->escape("hms-{$branch->id}-".preg_replace('/[^a-z0-9-]/i', '', (string) $branch->code));

        $staffGw = $this->gatewayOf($staffSubnet);
        $guestGw = $this->gatewayOf($guestSubnet);
        $staffPool = "staff-pool-b{$branch->id}";
        $guestPool = "guest-pool-b{$branch->id}";

        return <<<RSC
        # HMS hotspot isolation export — branch {$branch->id} ({$this->escape($branch->name)})
        # Import with: /import hotspot-b{$branch->id}.rsc
        # Staff: PSK SSID "{$staffSsid}" on VLAN {$staffVlan} ({$staffSubnet})
        # Guest: open SSID "{$guestSsid}" + Hotspot on VLAN {$guestVlan} ({$guestSubnet})

        /interface vlan add name=vlan-staff vlan-id={$staffVlan} interface=ether2 comment="HMS staff"
        /interface vlan add name=vlan-guest vlan-id={$guestVlan} interface=ether2 comment="HMS guest hotspot"
        /interface bridge add name=bridge-staff comment="HMS staff"
        /interface bridge add name=bridge-guest comment="HMS guest hotspot"
        /interface bridge port add bridge=bridge-staff interface=vlan-staff
        /interface bridge port add bridge=bridge-guest interface=vlan-guest

        /ip address add address={$staffGw} interface=bridge-staff comment="HMS staff gw"
        /ip address add address={$guestGw} interface=bridge-guest comment="HMS guest gw"
        /ip pool add name={$staffPool} ranges={$this->poolRange($staffSubnet)} comment="HMS staff"
        /ip pool add name={$guestPool} ranges={$this->poolRange($guestSubnet)} comment="HMS guest hotspot"
        /ip dhcp-server add name=dhcp-staff interface=bridge-staff address-pool={$staffPool} disabled=no comment="HMS staff"
        /ip dhcp-server add name=dhcp-guest interface=bridge-guest address-pool={$guestPool} disabled=no comment="HMS guest"
        /ip dhcp-server network add address={$staffSubnet} gateway={$this->ipOf($staffGw)} dns-server=1.1.1.1,8.8.8.8 comment="HMS staff"
        /ip dhcp-server network add address={$guestSubnet} gateway={$this->ipOf($guestGw)} dns-server=1.1.1.1,8.8.8.8 comment="HMS guest"

        /interface wireless security-profiles add name=sec-staff mode=dynamic-keys authentication-types=wpa2-psk wpa2-pre-shared-key="{$staffPsk}" comment="HMS staff PSK"
        /interface wireless security-profiles add name=sec-guest-open mode=none comment="HMS guest open (hotspot auth)"
        # Bind these profiles to your wlan interfaces; guest WLAN must use default-forwarding=no
        # /interface wireless set wlan1 ssid="{$staffSsid}" security-profile=sec-staff default-forwarding=no
        # /interface wireless set wlan2 ssid="{$guestSsid}" security-profile=sec-guest-open default-forwarding=no

        /interface wireguard add name=wg-hms private-key="{$wgPriv}" comment="HMS cloud tunnel"
        /ip address add address={$wgAddr} interface=wg-hms comment="HMS tunnel"
        /interface wireguard peers add interface=wg-hms public-key="{$wgPeer}" endpoint="{$wgEndpoint}" allowed-address={$radiusHost}/32 persistent-keepalive=25 comment="HMS cloud"

        # Hotspot ONLY on the guest bridge — never on staff.
        /ip hotspot profile add name=hs-b{$branch->id} hotspot-address={$this->ipOf($guestGw)} login-by=http-pap,cookie ssl-certificate=none comment="HMS branch {$branch->id}"
        /ip hotspot add name=hotspot-b{$branch->id} interface=bridge-guest address-pool={$guestPool} profile=hs-b{$branch->id} disabled=no comment="HMS guest only"
        /ip hotspot walled-garden add dst-host={$portalDomainEscaped} comment="HMS portal"
        /ip hotspot walled-garden ip add dst-address={$radiusHost} comment="HMS RADIUS over tunnel"

        /radius add service=hotspot address={$radiusHost} secret="{$nasSecret}" timeout=3s comment="HMS cloud RADIUS"
        /radius incoming set accept=yes port=3799 comment="HMS CoA/disconnect"

        # --- Isolation: guests cannot reach company systems ---
        /ip firewall filter add chain=forward in-interface=bridge-guest dst-address=10.0.0.0/8 action=drop comment="HMS guest->RFC1918 drop"
        /ip firewall filter add chain=forward in-interface=bridge-guest dst-address=172.16.0.0/12 action=drop comment="HMS guest->RFC1918 drop"
        /ip firewall filter add chain=forward in-interface=bridge-guest dst-address=192.168.0.0/16 action=drop comment="HMS guest->RFC1918 drop"
        /ip firewall filter add chain=forward in-interface=bridge-guest out-interface=bridge-staff action=drop comment="HMS guest->staff drop"
        /ip firewall filter add chain=forward in-interface=bridge-staff out-interface=bridge-guest action=drop comment="HMS staff->guest drop"
        /ip firewall filter add chain=input in-interface=bridge-guest protocol=tcp dst-port=22,23,80,443,8291,8728,8729 action=drop comment="HMS guest->router admin drop"
        /ip firewall filter add chain=input in-interface=bridge-guest protocol=udp dst-port=22,53,8291,8728 action=drop comment="HMS guest->router admin drop"
        # Allow guest DNS + portal + RADIUS (tunnel) — established/related first in your base ruleset.
        /ip firewall nat add chain=srcnat out-interface=ether1 action=masquerade comment="HMS guest+staff WAN"

        # RADIUS NAS identity for accounting/session tracking
        /radius set 0 calling-station-id-format=XX-XX-XX-XX-XX-XX
        :log info "HMS hotspot export applied for NAS {$nasName}; portal {$portal}"
        RSC;
    }

    private function escape(string $value): string
    {
        return str_replace(['"', '$', '`'], ['\"', '', ''], $value);
    }

    /**
     * Branch setting with config fallback. Narrows instead of casting so
     * non-scalar misconfiguration falls back to the default.
     *
     * @param  array<string, mixed>  $settings
     */
    private function settingString(array $settings, string $key, string $configKey, string $default): string
    {
        $value = $settings[$key] ?? config($configKey, $default);

        return is_string($value) ? $value : $default;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function settingInt(array $settings, string $key, string $configKey, int $default): int
    {
        $value = $settings[$key] ?? config($configKey, $default);

        return is_int($value) ? $value : $default;
    }

    private function ipOf(string $cidr): string
    {
        $parts = explode('/', $cidr);

        return $parts[0];
    }

    private function gatewayOf(string $subnet): string
    {
        [$base] = explode('/', $subnet);
        $octets = explode('.', $base);
        $octets[3] = '1';

        $prefix = explode('/', $subnet)[1] ?? '24';

        return implode('.', $octets).'/'.$prefix;
    }

    private function poolRange(string $subnet): string
    {
        [$base] = explode('/', $subnet);
        $octets = explode('.', $base);
        $prefix = (int) (explode('/', $subnet)[1] ?? '24');

        if ($prefix <= 22) {
            return "{$octets[0]}.{$octets[1]}.{$octets[2]}.100-{$octets[0]}.{$octets[1]}.".((int) $octets[2] + 3).'.200';
        }

        return "{$octets[0]}.{$octets[1]}.{$octets[2]}.100-{$octets[0]}.{$octets[1]}.{$octets[2]}.200";
    }
}
