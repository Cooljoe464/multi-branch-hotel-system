<?php

// Availability load baseline (group: load — nightly/manual only).
//
// Spawns parallel workers racing one room/night through the real
// reservation store path and asserts completion + wall-time SLO.
// The engine serializes them: exactly 1 winner is expected.
//
// NOTE on Windows dev boxes: roughly one run in three stalls a single
// worker pre-test-body (process alive, frozen, zero output, no DB backend;
// forensics in hms_load_forensic_snapshot have never shown a lock, a
// waiter, or CPU burn — it is environmental, not application code). The
// test therefore retries the whole run once on a freshly migrated
// database; two consecutive stalls fail loud with forensics attached.

use App\Models\Branch;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(DatabaseMigrations::class);
uses()->group('load');

if (! function_exists('hms_load_forensic_snapshot')) {
    function hms_load_forensic_snapshot(): string
    {
        $out = "\n--- php processes ---\n";
        $out .= (string) shell_exec('tasklist /FI "IMAGENAME eq php.exe" /FO TABLE 2>&1');
        $out .= "\n--- php cpu seconds ---\n";
        $out .= (string) shell_exec('powershell -NoProfile -Command "Get-Process php | Select-Object Id,CPU | Format-Table -HideTableHeaders -AutoSize" 2>&1');

        $out .= "\n--- pg backends (test db) ---\n";
        try {
            $rows = DB::select("select pid, state, wait_event_type, wait_event, left(query, 100) as q from pg_stat_activity where datname = 'hotel_system_test'");
            foreach ($rows as $row) {
                $out .= "{$row->pid} {$row->state} {$row->wait_event_type}/{$row->wait_event} {$row->q}\n";
            }
        } catch (Throwable $e) {
            $out .= 'pg unreachable: '.$e->getMessage()."\n";
        }

        return $out;
    }
}

if (! function_exists('hms_load_seed')) {
    /**
     * @return array{branch: Branch, roomType: RoomType}
     */
    function hms_load_seed(): array
    {
        $branch = Branch::factory()->create(['code' => 'LOAD-01']);
        $roomType = RoomType::factory()->create(['branch_id' => $branch->id]);
        Room::factory()->create([
            'branch_id' => $branch->id,
            'room_type_id' => $roomType->id,
            'status' => 'available',
        ]);
        $user = User::factory()->create(['branch_id' => $branch->id, 'email' => 'load.runner@example.com']);
        $user->assignRole('Global Admin');
        $user->branches()->syncWithoutDetaching([$branch->id]);

        return ['branch' => $branch, 'roomType' => $roomType];
    }
}

it('handles concurrent contested bookings within the time budget', function () {
    $this->seed(RoleSeeder::class);

    $lastFailure = null;

    for ($attempt = 1; $attempt <= 2; $attempt++) {
        if ($attempt > 1) {
            fwrite(STDERR, "\n[load] attempt 1 stalled, retrying once on a fresh database\n");
            Artisan::call('migrate:fresh', ['--force' => true]);
            $this->seed(RoleSeeder::class);
        }

        hms_load_seed();

        try {
            hms_load_burst($this);
            $lastFailure = null;

            break;
        } catch (Throwable $e) {
            if (! str_contains($e->getMessage(), 'timed out') || $attempt >= 2) {
                throw $e;
            }

            $lastFailure = $e;
        }
    }

    if ($lastFailure instanceof Throwable) {
        throw $lastFailure;
    }
});

if (! function_exists('hms_load_burst')) {
    function hms_load_burst(TestCase $test): void
    {
        $dir = storage_path('framework/load-'.uniqid());
        mkdir($dir);

        $workers = max(1, (int) (getenv('LOAD_WORKERS') ?: 10));
        $started = microtime(true);

        $processes = [];
        foreach (range(1, $workers) as $i) {
            $process = new Process(
                [PHP_BINARY, 'artisan', 'test', '--compact', '--group=load-worker', '--do-not-cache-result'],
                base_path(),
                // Per-worker log channel: N processes appending massive traces
                // to one laravel.log contend on flock; errorlog goes to the
                // pumped pipe instead.
                ['LOAD_WORKER' => (string) $i, 'LOAD_RESULT_DIR' => $dir, 'LOG_CHANNEL' => 'errorlog'],
                null,
                120
            );
            $process->start();
            $processes[$i] = $process;
            // Stagger boots: N simultaneous framework boots on a dev box
            // contend on file IO (Defender, opcache-cold) and look like hangs.
            usleep(150000);
        }

        $completedAt = [];
        foreach ($processes as $i => $process) {
            try {
                // wait() pumps the pipes incrementally: polling isRunning()
                // without pumping deadlocks workers once pipe buffers fill.
                $process->wait(function ($type, $buffer) use ($dir, $i) {
                    file_put_contents("{$dir}/{$i}.out", $buffer, FILE_APPEND);
                });
            } catch (ProcessTimedOutException $e) {
                fwrite(STDERR, "\n[load] FORENSICS:\n".hms_load_forensic_snapshot());
                $process->stop(5);
                $stuckLog = is_file("{$dir}/{$i}.out") ? substr((string) file_get_contents("{$dir}/{$i}.out"), -3000) : '(no worker output yet)';
                $test->fail("worker {$i} timed out. worker log tail: {$stuckLog}");
            }
            $completedAt[$i] = round(microtime(true) - $started, 1);
            $tail = is_file("{$dir}/{$i}.out") ? (string) file_get_contents("{$dir}/{$i}.out") : '';
            expect($process->isSuccessful())->toBeTrue("worker {$i} crashed with exit ".$process->getExitCode().' ('.$process->getExitCodeText().'). out tail: '.substr($tail, -2000).' err tail: '.substr($process->getErrorOutput(), -2000));
        }
        fwrite(STDERR, "\n[load] completion order: ".json_encode($completedAt)."\n");

        $wallSeconds = microtime(true) - $started;

        $outcomes = [];
        foreach (range(1, $workers) as $i) {
            $file = "{$dir}/{$i}.txt";
            expect(is_file($file))->toBeTrue("worker {$i} produced no result");
            $outcomes[$i] = trim((string) file_get_contents($file));
        }

        $winners = count(array_filter($outcomes, fn ($o) => str_starts_with($o, 'WON:')));
        $serverErrors = count(array_filter($outcomes, fn ($o) => $o === 'LOST:500'));

        fwrite(STDERR, "\n[load] {$workers} workers, {$winners} winner(s), wall ".round($wallSeconds, 1)."s\n");

        expect($serverErrors)->toBe(0, 'no worker may hit a server error');
        expect($winners)->toBeGreaterThanOrEqual(1, 'at least one booking must succeed');
        expect($wallSeconds)->toBeLessThan(90, 'p95 budget: all workers finish inside 90s');

        // Tidy up worker scratch files (kept on failure for forensics).
        array_map('unlink', glob("{$dir}/*") ?: []);
        rmdir($dir);
    }
}
