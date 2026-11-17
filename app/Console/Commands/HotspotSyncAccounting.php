<?php

namespace App\Console\Commands;

use App\Services\RadiusService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('hotspot:sync-accounting')]
#[Description('Project FreeRADIUS accounting totals into guest Wi-Fi sessions.')]
class HotspotSyncAccounting extends Command
{
    public function handle(RadiusService $radius): int
    {
        $updated = $radius->syncAccounting();
        $this->info("Updated {$updated} Wi-Fi accounting session(s).");

        return self::SUCCESS;
    }
}
