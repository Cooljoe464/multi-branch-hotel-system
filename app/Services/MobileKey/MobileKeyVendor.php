<?php

namespace App\Services\MobileKey;

use App\Models\MobileKey;

interface MobileKeyVendor
{
    /**
     * Push the credential to the lock cloud. Returns the
     * vendor-side credential id. Must not throw for
     * already-provisioned devices (idempotent).
     */
    public function provision(MobileKey $key): string;

    public function revoke(MobileKey $key): void;
}
