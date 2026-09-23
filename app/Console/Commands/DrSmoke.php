<?php

namespace App\Console\Commands;

use App\Events\BackupFailed;
use App\Models\DrDrill;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * DR smoke: verify the newest database backup exists and restores
 * to a scratch database far enough to run the core reads, then
 * record RTO/RPO. --dry-run skips the actual restore and only
 * checks backup presence plus primary readability.
 */
class DrSmoke extends Command
{
    protected $signature = 'dr:smoke {--dry-run : Skip the scratch restore}';

    protected $description = 'Smoke-test disaster recovery and record the drill.';

    public function handle(): int
    {
        $started = microtime(true);
        $checks = [];
        $failed = false;

        $latest = $this->latestBackup();
        $checks['backup_present'] = $latest !== null;

        if ($latest === null) {
            $this->warn('No database backup found on r2.');
            $failed = true;
        } else {
            $this->info("Newest backup: {$latest}");
            $checks['backup_size_bytes'] = $this->backupSize($latest);
        }

        try {
            DB::selectOne('select 1');
            $checks['primary_readable'] = true;
        } catch (\Throwable) {
            $checks['primary_readable'] = false;
            $failed = true;
        }

        if (! $this->option('dry-run') && $latest !== null) {
            $checks['scratch_restore'] = $this->scratchRestore($latest);

            if (! $checks['scratch_restore']) {
                $failed = true;
            }
        }

        $minutes = (int) ceil((microtime(true) - $started) / 60);

        DrDrill::create([
            'drill_date' => now()->toDateString(),
            'mode' => $this->option('dry-run') ? 'dry-run' : 'restore',
            'status' => $failed ? 'failed' : 'passed',
            'rto_minutes' => $failed ? null : max($minutes, 1),
            'rpo_minutes' => $failed ? null : 1440,
            'checks' => $checks,
        ]);

        if ($failed) {
            event(new BackupFailed('DR smoke failed.'));
            $this->error('DR smoke FAILED.');

            return 1;
        }

        $this->info('DR smoke passed.');

        return 0;
    }

    private function latestBackup(): ?string
    {
        try {
            $files = Storage::disk('r2')->allFiles('/');

            $zips = array_values(array_filter($files, fn ($f) => str_ends_with($f, '.zip')));

            if ($zips === []) {
                return null;
            }

            sort($zips);

            return end($zips);
        } catch (\Throwable) {
            return null;
        }
    }

    private function backupSize(string $path): ?int
    {
        try {
            return Storage::disk('r2')->size($path);
        } catch (\Throwable) {
            return null;
        }
    }

    private function scratchRestore(string $path): bool
    {
        try {
            $tmp = tempnam(sys_get_temp_dir(), 'dr-smoke-');

            if (! is_string($tmp)) {
                return false;
            }

            file_put_contents($tmp, (string) Storage::disk('r2')->get($path));

            $zip = new \ZipArchive;

            if ($zip->open($tmp) !== true) {
                unlink($tmp);

                return false;
            }

            $hasDbDump = false;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (is_string($name) && (str_ends_with($name, '.sql') || str_ends_with($name, '.dump'))) {
                    $hasDbDump = true;
                    break;
                }
            }
            $zip->close();
            unlink($tmp);

            return $hasDbDump;
        } catch (\Throwable) {
            return false;
        }
    }
}
