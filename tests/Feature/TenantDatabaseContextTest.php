<?php

use App\Models\Branch;
use App\Models\RegistrationCard;
use App\Models\Reservation;
use App\Models\Tenant;
use App\Support\TenantDatabaseContext;
use App\Support\TenantQueueContext;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

class TenantQueueContextProbeJob implements ShouldQueue
{
    use Queueable;

    /** @var list<int> */
    public static array $seenTenantIds = [];

    public function handle(): void
    {
        self::$seenTenantIds[] = (int) DB::scalar("select current_setting('app.tenant_id', true)");
    }
}

beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Tenant database context uses PostgreSQL transaction-local settings.');
    }
});

test('nested tenant contexts restore the previous context on a pooled connection', function () {
    $context = app(TenantDatabaseContext::class);

    $context->run(10, 100, function () use ($context): void {
        expect(DB::scalar("select current_setting('app.user_id', true)"))->toBe('10')
            ->and(DB::scalar("select current_setting('app.tenant_id', true)"))->toBe('100');
        expect($context->captureQueueContext())->toBe(['tenant_id' => 100, 'branch_id' => null]);

        $context->run(20, 200, function (): void {
            expect(DB::scalar("select current_setting('app.user_id', true)"))->toBe('20')
                ->and(DB::scalar("select current_setting('app.tenant_id', true)"))->toBe('200');
        });

        expect(DB::scalar("select current_setting('app.user_id', true)"))->toBe('10')
            ->and(DB::scalar("select current_setting('app.tenant_id', true)"))->toBe('100');
    });

    expect(DB::scalar("select current_setting('app.user_id', true)"))->toBe('')
        ->and(DB::scalar("select current_setting('app.tenant_id', true)"))->toBe('');
});

test('tenant context is restored after an exception', function () {
    $context = app(TenantDatabaseContext::class);

    expect(fn () => $context->run(10, 100, function (): never {
        throw new RuntimeException('Request failed.');
    }))->toThrow(RuntimeException::class, 'Request failed.');

    expect(DB::scalar("select current_setting('app.user_id', true)"))->toBe('')
        ->and(DB::scalar("select current_setting('app.tenant_id', true)"))->toBe('');
});

test('queue workers apply each dispatched tenant context and clear it before the next job', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Tenant queue context uses PostgreSQL transaction-local settings.');
    }

    $context = app(TenantDatabaseContext::class);
    TenantQueueContextProbeJob::$seenTenantIds = [];

    $context->run(10, 101, function (): void {
        TenantQueueContextProbeJob::dispatch();
    });

    $context->run(20, 202, function (): void {
        TenantQueueContextProbeJob::dispatch();
    });

    expect(TenantQueueContextProbeJob::$seenTenantIds)->toBe([101, 202])
        ->and(DB::scalar("select current_setting('app.tenant_id', true)"))->toBe('');
});

test('forced row security isolates tenant rows and rejects cross-tenant writes', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Tenant row-level security is PostgreSQL-specific.');
    }

    $canCreateRoles = DB::selectOne(
        'select rolsuper as allowed from pg_roles where rolname = current_user',
    );

    if (! in_array($canCreateRoles->allowed ?? false, [true, 1, '1', 't', 'true'], true)) {
        $this->markTestSkipped('The test database role cannot create an isolated RLS probe role.');
    }

    $firstTenant = Tenant::factory()->create();
    $secondTenant = Tenant::factory()->create();
    $property = Branch::factory()->for($firstTenant)->create();
    $reservation = Reservation::factory()->forBranch($property->id)->create();
    $registrationCard = RegistrationCard::factory()->for($reservation)->create();
    $otherProperty = Branch::factory()->for($secondTenant)->create();
    $otherReservation = Reservation::factory()->forBranch($otherProperty->id)->create();
    $firstActivityId = DB::table('activity_log')->insertGetId([
        'description' => 'Tenant one activity',
        'subject_type' => Reservation::class,
        'subject_id' => $reservation->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $secondActivityId = DB::table('activity_log')->insertGetId([
        'description' => 'Tenant two activity',
        'subject_type' => Reservation::class,
        'subject_id' => $otherReservation->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => app(TenantDatabaseContext::class)->run(null, $firstTenant->id, function () use ($otherReservation): void {
        DB::table('activity_log')->insert([
            'description' => 'Cross-tenant activity',
            'subject_type' => Reservation::class,
            'subject_id' => $otherReservation->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }))->toThrow(QueryException::class);

    $roleName = 'hms_rls_'.bin2hex(random_bytes(6));

    DB::statement("CREATE ROLE {$roleName} NOLOGIN NOBYPASSRLS");
    DB::statement("GRANT SELECT, UPDATE ON tenants TO {$roleName}");
    DB::statement("GRANT SELECT ON registration_cards TO {$roleName}");
    DB::statement("GRANT SELECT ON activity_log TO {$roleName}");
    DB::statement("GRANT {$roleName} TO CURRENT_USER");
    DB::statement("SET LOCAL ROLE {$roleName}");
    DB::select('select set_config(?, ?, true)', ['app.tenant_id', (string) $firstTenant->id]);

    expect(DB::table('tenants')->orderBy('id')->pluck('id')->all())
        ->toBe([$firstTenant->id]);
    expect(DB::table('registration_cards')->pluck('id')->all())
        ->toBe([$registrationCard->id]);
    expect(DB::table('activity_log')->whereIn('id', [$firstActivityId, $secondActivityId])->pluck('id')->all())
        ->toBe([$firstActivityId]);

    expect(fn () => DB::transaction(fn () => DB::table('tenants')
        ->where('id', $firstTenant->id)
        ->update(['id' => $secondTenant->id])))
        ->toThrow(QueryException::class);

    DB::select('select set_config(?, ?, true)', ['app.tenant_id', '']);
    DB::select('select set_config(?, ?, true)', ['app.tenant_id', (string) $secondTenant->id]);

    expect(DB::table('registration_cards')->pluck('id')->all())->toBe([]);
    expect(DB::table('activity_log')->whereIn('id', [$firstActivityId, $secondActivityId])->pluck('id')->all())
        ->toBe([$secondActivityId]);

    DB::select('select set_config(?, ?, true)', ['app.tenant_id', '']);

    $queueContext = app(TenantQueueContext::class);
    $firstJob = Mockery::mock(Job::class);
    $firstJob->shouldReceive('payload')->andReturn([
        'tenant_context' => ['tenant_id' => $firstTenant->id, 'branch_id' => null],
    ]);
    $firstJob->shouldReceive('getJobId')->andReturn('tenant-a-job');

    $queueContext->begin($firstJob);

    expect(DB::table('tenants')->pluck('id')->all())->toBe([$firstTenant->id]);

    $queueContext->commit($firstJob);

    $secondJob = Mockery::mock(Job::class);
    $secondJob->shouldReceive('payload')->andReturn([
        'tenant_context' => ['tenant_id' => $secondTenant->id, 'branch_id' => null],
    ]);
    $secondJob->shouldReceive('getJobId')->andReturn('tenant-b-job');

    $queueContext->begin($secondJob);

    expect(DB::table('tenants')->pluck('id')->all())->toBe([$secondTenant->id]);

    $queueContext->rollback($secondJob);

    expect(DB::scalar("select current_setting('app.tenant_id', true)"))->toBe('');
});
