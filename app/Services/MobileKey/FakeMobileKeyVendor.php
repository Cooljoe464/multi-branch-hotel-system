<?php

namespace App\Services\MobileKey;

use App\Models\MobileKey;

/**
 * In-memory BLE vendor for tests and local development.
 */
class FakeMobileKeyVendor implements MobileKeyVendor
{
    /**
     * @var array<int, string>
     */
    public static array $provisioned = [];

    /**
     * @var list<int>
     */
    public static array $revoked = [];

    public static bool $down = false;

    public static function reset(): void
    {
        self::$provisioned = [];
        self::$revoked = [];
        self::$down = false;
    }

    public function provision(MobileKey $key): string
    {
        if (self::$down) {
            throw new \RuntimeException('Mobile key vendor unreachable.');
        }

        return self::$provisioned[$key->id] ??= 'fake-cred-'.$key->id;
    }

    public function revoke(MobileKey $key): void
    {
        if (self::$down) {
            throw new \RuntimeException('Mobile key vendor unreachable.');
        }

        self::$revoked[] = $key->id;
    }
}
