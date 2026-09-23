<?php

use App\Jobs\PartitionManagerJob;
use App\Models\DrDrill;
use App\Services\PartitionManager;
use App\Services\ReadRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

uses(RefreshDatabase::class);

it('routes activity rows into monthly partitions and prunes queries', function () {
    DB::statement("CREATE TABLE IF NOT EXISTS activity_log_2024_01 PARTITION OF activity_log FOR VALUES FROM ('2024-01-01') TO ('2024-02-01')");

    DB::table('activity_log')->insert([
        'log_name' => 'test', 'description' => 'old row',
        'created_at' => '2024-01-15 10:00:00', 'updated_at' => '2024-01-15 10:00:00',
    ]);
    DB::table('activity_log')->insert([
        'log_name' => 'test', 'description' => 'new row',
        'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
    ]);

    $oldChild = DB::selectOne("SELECT tableoid::regclass AS child FROM activity_log WHERE description = 'old row'");
    $newChild = DB::selectOne("SELECT tableoid::regclass AS child FROM activity_log WHERE description = 'new row'");

    expect((string) $oldChild->child)->toContain('activity_log_2024_01')
        ->and((string) $newChild->child)->not->toBe((string) $oldChild->child);

    $plan = DB::select("EXPLAIN SELECT * FROM activity_log WHERE created_at >= '2024-01-01' AND created_at < '2024-02-01'");
    $text = implode("\n", array_map(fn ($r) => (string) array_values((array) $r)[0], $plan));

    expect($text)->toContain('activity_log_2024_01')
        ->and($text)->not->toContain('activity_log_'.strtolower(now()->format('Y_m')));
});

it('provisions future partitions idempotently', function () {
    $first = (new PartitionManagerJob)->handle();
    $second = (new PartitionManagerJob)->handle();

    expect($first)->toBe($second)
        ->and((new PartitionManager)->partitionCount('activity_log'))->toBeGreaterThanOrEqual(3);
});

it('reports transactions partitioning blockers instead of converting', function () {
    $report = (new PartitionManager)->readiness();

    expect($report['activity_log']['partitioned'])->toBeTrue()
        ->and($report['transactions']['partitioned'])->toBeFalse()
        ->and($report['transactions']['blockers'])->not->toBeEmpty();
});

it('reads reports from the replica connection when enabled', function () {
    expect(ReadRouter::connection())->toBe(config('database.default'));

    ReadRouter::enable();

    expect(ReadRouter::connection())->toBe('replica');

    $count = DB::connection(ReadRouter::connection())->table('branches')->count();

    expect($count)->toBeGreaterThanOrEqual(0);
});

it('records a DR smoke drill and stays fresh', function () {
    Storage::fake('r2');

    $tmp = tempnam(sys_get_temp_dir(), 'dr-test-');
    $zip = new ZipArchive;
    $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('db.sql', 'SELECT 1;');
    $zip->close();
    Storage::disk('r2')->put('hms-backup.zip', (string) file_get_contents((string) $tmp));

    Artisan::call('dr:smoke', ['--dry-run' => true]);

    expect(DrDrill::where('status', 'passed')->count())->toBe(1)
        ->and(DrDrill::isFresh())->toBeTrue();
});
