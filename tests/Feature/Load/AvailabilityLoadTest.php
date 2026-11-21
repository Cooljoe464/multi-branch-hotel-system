<?php

// Availability load baseline (group: load — nightly/manual only).
//
// Spawns parallel workers racing one room/night through the real
// reservation store path and asserts completion + wall-time SLO.
// The engine serializes them: exactly 1 winner is expected.
//
// The parent runs against a freshly migrated database WITHOUT holding an
// open transaction: RefreshDatabase wraps each test in a transaction that
// keeps seeded rows invisible (Postgres never exposes uncommitted data) to
// the worker processes. Per attempt we terminate stale load backends,
// reset the schema, migrate fresh and seed before the burst.
//
// Worker teardown must kill the full process tree: on Windows the PHPUnit
// runner is a grandchild of `artisan test` and can outlive the direct child.

use App\Models\Branch;
use App\Models\ChartAccount;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Database\Seeders\ChartSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

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
            $rows = DB::select('select pid, application_name, state, wait_event_type, wait_event, left(query, 100) as q from pg_stat_activity where datname = current_database()');
            foreach ($rows as $row) {
                $out .= "{$row->pid} ".($row->application_name ?: '-')." {$row->state} {$row->wait_event_type}/{$row->wait_event} {$row->q}\n";
            }
        } catch (Throwable $e) {
            $out .= 'pg unreachable: '.$e->getMessage()."\n";
        }

        return $out;
    }
}

if (! function_exists('hms_load_stop_worker')) {
    /**
     * Kill a worker process tree. On Linux `stop()` is enough; on Windows the
     * PHPUnit runner is a grandchild of `artisan test` and must be terminated
     * via taskkill /T or it survives the artisan parent with DB handles open.
     */
    function hms_load_stop_worker(Process $process): void
    {
        if (! $process->isRunning()) {
            return;
        }

        try {
            $process->stop(5);
        } catch (Throwable $e) {
            // process may have already exited between isRunning and stop
        }

        $pid = $process->getPid();

        if ($pid > 0 && PHP_OS_FAMILY === 'Windows') {
            try {
                Process::run(['taskkill', '/F', '/T', '/PID', (string) $pid], null, 10000);
            } catch (Throwable $e) {
                // taskkill unavailable/failed; the stop() above already applied
            }
        }

        for ($i = 0; $i < 50; $i++) {
            if (! $process->isRunning()) {
                break;
            }

            usleep(100000);
        }
    }
}

if (! function_exists('hms_load_prepare_database')) {
    /**
     * Reset the load database before a burst: bound lock waits, drop stale
     * load-test backends, fresh-migrate and seed reference data. No open
     * transaction is held, so worker processes see the committed seed.
     */
    function hms_load_prepare_database(TestCase $test): void
    {
        try {
            DB::statement("SET lock_timeout TO '15s'");
        } catch (Throwable $e) {
            // session-scoped setting; keep going
        }

        try {
            DB::select(
                'select pg_terminate_backend(pid) from pg_stat_activity
                 where datname = current_database()
                   and pid <> pg_backend_pid()
                   and (application_name like \'hms-load-%\' or state = \'idle in transaction\')'
            );
        } catch (Throwable $e) {
            // best effort stale-backend cleanup; proceed with fresh migrate
        }

        Artisan::call('migrate:fresh', ['--force' => true]);
        DB::purge();

        if (Schema::hasTable('chart_accounts') && ! ChartAccount::query()->exists()) {
            $test->seed(ChartSeeder::class);
        }
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
        }

        hms_load_prepare_database($this);
        $this->seed(RoleSeeder::class);
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
                // Per-worker isolation: own pg app name keeps forensics clean,
                // log channel avoids N processes contending on one laravel.log
                // flock; explicit DB env keeps workers on the same test database.
                [
                    'LOAD_WORKER' => (string) $i,
                    'LOAD_RESULT_DIR' => $dir,
                    'LOG_CHANNEL' => 'errorlog',
                    'PGAPPNAME' => "hms-load-worker-{$i}",
                    'DB_CONNECTION' => 'pgsql',
                    'DB_HOST' => '127.0.0.1',
                    'DB_PORT' => '5432',
                    'DB_DATABASE' => 'multi_branch_hotel_system',
                    'DB_USERNAME' => 'postgres',
                    'DB_PASSWORD' => 'password',
                ],
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
        $workerFailures = [];
        foreach ($processes as $i => $process) {
            try {
                // wait() pumps the pipes incrementally: polling isRunning()
                // without pumping deadlocks workers once pipe buffers fill.
                $process->wait(function ($type, $buffer) use ($dir, $i) {
                    file_put_contents("{$dir}/{$i}.out", $buffer, FILE_APPEND);
                });
            } catch (ProcessTimedOutException $e) {
                fwrite(STDERR, "\n[load] FORENSICS:\n".hms_load_forensic_snapshot());
                $stuckLog = is_file("{$dir}/{$i}.out") ? substr((string) file_get_contents("{$dir}/{$i}.out"), -3000) : '(no worker output yet)';
                $workerFailures[] = "worker {$i} timed out. worker log tail: {$stuckLog}";

                foreach ($processes as $worker) {
                    hms_load_stop_worker($worker);
                }
            }

            $completedAt[$i] = round(microtime(true) - $started, 1);
            $tail = is_file("{$dir}/{$i}.out") ? (string) file_get_contents("{$dir}/{$i}.out") : '';

            if (! $process->isSuccessful()) {
                $workerFailures[] = "worker {$i} crashed with exit ".$process->getExitCode().' ('.$process->getExitCodeText().'). out tail: '.substr($tail, -2000).' err tail: '.substr($process->getErrorOutput(), -2000);
            }
        }

        foreach ($processes as $process) {
            hms_load_stop_worker($process);
        }

        if ($workerFailures !== []) {
            $test->fail(implode("\n", $workerFailures));
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
