<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Services\RouterOsConfigService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('hotspot:export-rsc {branch : Branch ID or code} {--output= : Write to file instead of stdout}')]
#[Description('Export per-branch RouterOS isolation script (staff PSK VLAN + guest hotspot VLAN + WireGuard + RADIUS)')]
class HotspotExportRsc extends Command
{
    public function handle(RouterOsConfigService $exporter): int
    {
        $ref = (string) $this->argument('branch');
        $branch = is_numeric($ref)
            ? Branch::find((int) $ref)
            : Branch::where('code', $ref)->first();

        if (! $branch) {
            $this->error("Branch [{$ref}] not found.");

            return self::FAILURE;
        }

        $rsc = $exporter->export($branch);
        $output = $this->option('output');

        if (is_string($output) && $output !== '') {
            file_put_contents($output, $rsc);
            $this->info("Wrote hotspot export for {$branch->name} to {$output}");
        } else {
            $this->line($rsc);
        }

        return self::SUCCESS;
    }
}
